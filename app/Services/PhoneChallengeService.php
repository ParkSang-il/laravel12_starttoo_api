<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectAccount;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PhoneChallengeService
{
    public function __construct(private ProjectDatabase $projectDatabase) {}

    public function issue(Project $project, string $phone): array
    {
        // Development preview is explicit, never enabled in production, never logged.
        abort_unless(app()->environment(['local', 'testing'])
            && config('platform.sms_driver') === 'preview', 503,
            'SMS 발송 서비스가 설정되지 않았습니다.');

        $code = (string) random_int(100000, 999999);
        $id = (string) Str::uuid();
        $this->projectDatabase->database()->transaction(function () use ($project, $phone, $code, $id) {
            // Serialize issuance per project so per-phone limits cannot be raced.
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $recent = $this->projectDatabase->database()->table('project_phone_challenges')
                ->where('project_id', $project->id)->where('phone', $phone);
            abort_if((clone $recent)->where('created_at', '>', now()->subMinute())->exists(),
                429, '인증번호는 60초 후 다시 요청할 수 있습니다.');
            abort_if((clone $recent)->where('created_at', '>', now()->subHours(3))->count() >= 5,
                429, '인증번호 요청 횟수를 초과했습니다.');
            abort_if($this->projectDatabase->database()->table('project_phone_challenges')
                ->where('created_at', '>=', now()->startOfDay())->count()
                >= config('platform.daily_sms_limit'), 429, '일일 발송 한도입니다.');

            $recent->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->projectDatabase->database()->table('project_phone_challenges')->insert([
                'id' => $id, 'project_id' => $project->id, 'phone' => $phone,
                'code_hash' => Hash::make($code), 'attempts' => 0,
                'expires_at' => now()->addMinutes(config('platform.otp_minutes')),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ['challenge_id' => $id, 'expires_in' => config('platform.otp_minutes') * 60,
            'development_code' => $code];
    }

    public function consume(Project $project, string $id, string $code): ProjectAccount
    {
        // Return an error marker so failed-attempt increments commit before responding.
        $result = $this->projectDatabase->database()->transaction(function () use ($project, $id, $code) {
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $challenge = $this->projectDatabase->database()->table('project_phone_challenges')
                ->where('id', $id)->where('project_id', $project->id)->lockForUpdate()->first();
            if (!$challenge || $challenge->consumed_at
                || now()->greaterThanOrEqualTo($challenge->expires_at)
                || $challenge->attempts >= config('platform.otp_attempts')) {
                return null;
            }

            $this->projectDatabase->database()->table('project_phone_challenges')->where('id', $id)->update([
                'attempts' => $challenge->attempts + 1, 'updated_at' => now(),
            ]);
            if (!Hash::check($code, $challenge->code_hash)) {
                return null;
            }

            $account = ProjectAccount::where('project_id', $project->id)
                ->where('phone', $challenge->phone)->lockForUpdate()->first();
            if ($account && $account->status !== 'active') {
                return null;
            }
            if (!$account) {
                $account = new ProjectAccount;
                $account->project_id = $project->id;
                $account->phone = $challenge->phone;
                $account->status = 'active';
            }
            $account->phone_verified_at = now();
            $account->save();
            $this->projectDatabase->database()->table('project_phone_challenges')->where('id', $id)->update([
                'consumed_at' => now(), 'updated_at' => now(),
            ]);

            return $account->refresh();
        });

        abort_unless($result, 422, '유효하지 않거나 만료된 인증번호입니다.');

        return $result;
    }
}
