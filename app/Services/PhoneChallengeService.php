<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAccount;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PhoneChallengeService
{
    public function __construct(private ProjectDatabase $projectDatabase, private OctomoClient $octomo) {}

    public function issue(Project $project, string $phone): array
    {
        $provider = config('platform.sms_driver');
        abort_unless(($provider === 'octomo' && $this->octomo->configured()) ||
            ($provider === 'preview' && app()->environment(['local', 'testing'])), 503,
            '휴대폰 인증 서비스가 설정되지 않았어요.');
        $code = (string) random_int(100000, 999999);
        $id = (string) Str::uuid();
        // Unique per attempt and project: an earlier received SMS cannot verify a new challenge.
        $text = $provider === 'octomo' ? '스트릿캣 인증 '.$id : null;
        $this->projectDatabase->database()->transaction(function () use ($project, $phone, $code, $id, $provider, $text) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $recent = $this->projectDatabase->database()->table('project_phone_challenges')
                ->where('project_id', $project->id)->where('phone', $phone);
            abort_if((clone $recent)->where('created_at', '>', now()->subMinute())->exists(),
                429, '인증 요청은 60초 후 다시 할 수 있어요.');
            abort_if((clone $recent)->where('created_at', '>', now()->subHours(3))->count() >= 5,
                429, '인증 요청 횟수를 초과했어요.');
            abort_if($this->projectDatabase->database()->table('project_phone_challenges')
                ->where('created_at', '>=', now()->startOfDay())->count() >= config('platform.daily_sms_limit'),
                429, '일일 인증 요청 한도입니다.');
            $recent->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->projectDatabase->database()->table('project_phone_challenges')->insert([
                'id' => $id, 'project_id' => $project->id, 'phone' => $phone,
                'provider' => $provider, 'message_text' => $text, 'code_hash' => Hash::make($code), 'attempts' => 0,
                'expires_at' => now()->addMinutes(config('platform.otp_minutes')), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $result = ['challenge_id' => $id, 'expires_in' => config('platform.otp_minutes') * 60];
        return $provider === 'octomo'
            ? [...$result, 'method' => 'sms_mo', 'recipient' => '16663538', 'message_text' => $text]
            : [...$result, 'development_code' => $code];
    }

    public function consume(Project $project, string $id, ?string $code): ProjectAccount
    {
        $challenge = $this->projectDatabase->database()->table('project_phone_challenges')
            ->where('id', $id)->where('project_id', $project->id)->first();
        if ($challenge && $challenge->provider === 'octomo') {
            return $this->consumeMessage($project, $id);
        }
        // Preview is explicit and cannot authenticate production, including old preview challenges.
        abort_unless(app()->environment(['local', 'testing']) && config('platform.sms_driver') === 'preview', 422,
            '유효하지 않거나 만료된 인증 요청입니다.');
        $result = $this->projectDatabase->database()->transaction(function () use ($project, $id, $code) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $row = $this->lockedChallenge($project, $id, (int) config('platform.otp_attempts'));
            if (!$row || $row->provider !== 'preview') return null;
            $this->challenges()->where('id', $id)->update(['attempts' => $row->attempts + 1, 'updated_at' => now()]);
            if (!is_string($code) || !Hash::check($code, $row->code_hash)) return null;
            return $this->complete($project, $row);
        });
        abort_unless($result, 422, '유효하지 않거나 만료된 인증번호입니다.');
        return $result;
    }

    private function challenges()
    {
        return $this->projectDatabase->database()->table('project_phone_challenges');
    }

    private function lockedChallenge(Project $project, string $id, int $attempts): ?object
    {
        $row = $this->challenges()->where('id', $id)->where('project_id', $project->id)->lockForUpdate()->first();
        return !$row || $row->consumed_at || now()->greaterThanOrEqualTo($row->expires_at) || $row->attempts >= $attempts
            ? null : $row;
    }

    private function consumeMessage(Project $project, string $id): ProjectAccount
    {
        abort_unless(config('platform.sms_driver') === 'octomo' && $this->octomo->configured(), 503,
            '휴대폰 인증 서비스가 설정되지 않았어요.');
        $row = $this->projectDatabase->database()->transaction(function () use ($project, $id) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $row = $this->lockedChallenge($project, $id, 30);
            if (!$row || $row->provider !== 'octomo' || !$row->message_text) return null;
            abort_if($row->last_checked_at && now()->diffInSeconds($row->last_checked_at, true) < 5, 429,
                '인증 확인은 5초 후 다시 해 주세요.', ['Retry-After' => '5']);
            $row->attempts++;
            $this->challenges()->where('id', $id)->update([
                'attempts' => $row->attempts, 'last_checked_at' => now(), 'updated_at' => now(),
            ]);
            return $row;
        });
        abort_unless($row, 422, '유효하지 않거나 만료된 인증 요청입니다.');
        // No DB locks are held during the external request. Only the DB-bound phone and text are used.
        abort_unless($this->octomo->messageExists($row->phone, $row->message_text), 422,
            '인증 문자가 아직 도착하지 않았어요. 전송 후 다시 확인해 주세요.');
        $result = $this->projectDatabase->database()->transaction(function () use ($project, $id, $row) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $current = $this->lockedChallenge($project, $id, 31);
            if (!$current || $current->provider !== 'octomo' || (int) $current->attempts !== (int) $row->attempts) return null;
            return $this->complete($project, $current);
        });
        abort_unless($result, 422, '유효하지 않거나 만료된 인증 요청입니다.');
        return $result;
    }

    /** Called only with both project and challenge rows locked. */
    private function complete(Project $project, object $challenge): ?ProjectAccount
    {
        $account = ProjectAccount::where('project_id', $project->id)->where('phone', $challenge->phone)->lockForUpdate()->first();
        if ($account && $account->status !== 'active') return null;
        $isNew = $account === null;
        if ($isNew) {
            $account = new ProjectAccount;
            $account->project_id = $project->id;
            $account->phone = $challenge->phone;
            $account->status = 'active';
        }
        $account->phone_verified_at = now();
        $account->save();
        if ($project->code === 'streetcat') {
            $account->nickname_tag ??= (string) $account->id;
            if ($isNew || trim($account->display_name ?? '') === '') $account->display_name = app(NicknameGenerator::class)->generate();
            $account->save();
        }
        $this->challenges()->where('id', $challenge->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
        return $account->refresh();
    }
}
