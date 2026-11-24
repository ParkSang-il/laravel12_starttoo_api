<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * 2-12·2-13 아티스트 전환 신청과 심사.
 * 상태: submitted 접수 → reviewing 심사 중 → approved 승인 / needs_changes 보완 요청 / rejected 반려.
 * 보완 요청·반려된 신청서는 회원이 고쳐 다시 제출한다(이력 보존).
 * 심사는 서버 명령(starttoo:artist-review)으로 처리한다. 관리자 웹은 이후 단계.
 */
class ArtistApplications
{
    public const STATUSES = ['submitted', 'reviewing', 'needs_changes', 'approved', 'rejected'];
    private const MIN_PHOTOS = 3;
    private const MAX_PHOTOS = 12;

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function show(int $userId): array
    {
        $row = $this->db()->table('starttoo_artist_applications')->where('user_id', $userId)->first();
        $user = $this->db()->table('starttoo_users')->where('id', $userId)->first(['nickname', 'artist_status']);
        return [
            'identity' => app(IdentityVerifications::class)->summary($userId),
            'license_required' => (bool) config('starttoo.artist_license_required'),
            'regions' => app(Profiles::class)->options()['regions'],
            'artist_status' => $user->artist_status ?? null,
            'nickname' => $user->nickname ?? '',
            'application' => $row ? $this->view($row) : null,
            'limits' => ['min_photos' => self::MIN_PHOTOS, 'max_photos' => self::MAX_PHOTOS],
        ];
    }

    /** 신청서 제출(처음 또는 보완·반려 후 다시 제출). */
    public function submit(int $userId, array $input): array
    {
        // 이전 앱이 보낸 별도 활동명은 사용하지 않는다. 사람의 표시 이름은 닉네임 하나다.
        $input['activity_name'] = $this->db()->table('starttoo_users')->where('id', $userId)->value('nickname');
        $regions = array_column(app(Profiles::class)->options()['regions'], 'id');
        foreach (['activity_name', 'bio', 'portfolio_link', 'license_number'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        }
        $licenseRequired = (bool) config('starttoo.artist_license_required');
        $validator = Validator::make($input, [
            'activity_name' => ['required', 'string', 'min:2', 'max:20'],
            'region' => ['required', 'string', Rule::in($regions)],
            'bio' => ['required', 'string', 'min:10', 'max:300'],
            'portfolio_link' => ['nullable', 'string', 'max:300'],
            // 새로 올린 사진(media_id)과 이전 제출 사진(keep_paths)을 순서대로 합쳐 3~12장
            'media_ids' => ['present', 'array', 'max:'.self::MAX_PHOTOS], 'media_ids.*' => ['uuid', 'distinct'],
            'keep_paths' => ['sometimes', 'array', 'max:'.self::MAX_PHOTOS], 'keep_paths.*' => ['string', 'distinct'],
            'license_number' => [$licenseRequired ? 'required' : 'nullable', 'string', 'max:40'],
            'license_media_id' => [$licenseRequired ? 'required' : 'nullable', 'uuid'],
        ]);
        if ($validator->fails()) {
            $this->fail(422, 'INVALID_ARTIST_APPLICATION', '신청서 내용을 확인해주세요.');
        }
        $input = $validator->validated();
        if (!app(IdentityVerifications::class)->summary($userId)['verified']) {
            $this->fail(409, 'IDENTITY_REQUIRED', '휴대폰 본인인증을 먼저 진행해주세요.');
        }

        return $this->db()->transaction(function () use ($userId, $input) {
            $user = $this->db()->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            $input['activity_name'] = $user->nickname;
            if ($user->artist_status === 'approved') $this->fail(409, 'ALREADY_ARTIST', '이미 아티스트로 활동 중이에요.');
            $row = $this->db()->table('starttoo_artist_applications')->where('user_id', $userId)->lockForUpdate()->first();
            if ($row && in_array($row->status, ['submitted', 'reviewing'], true)) {
                $this->fail(409, 'ARTIST_APPLICATION_IN_REVIEW', '심사 중인 신청서가 있어요. 결과를 기다려주세요.');
            }
            // 이전 제출 사진 중 남길 것만 유지한다.
            $previous = $row ? json_decode($row->portfolio_paths, true, 512, JSON_THROW_ON_ERROR) : [];
            $keep = array_values(array_intersect($input['keep_paths'] ?? [], $previous));
            $claimed = app(ArtworkUploads::class)->claim($userId, $input['media_ids']);
            $paths = [...$keep, ...array_map(fn ($id) => $claimed[$id], $input['media_ids'])];
            if (count($paths) < self::MIN_PHOTOS || count($paths) > self::MAX_PHOTOS) {
                $this->fail(422, 'INVALID_ARTIST_APPLICATION', '포트폴리오 사진은 '.self::MIN_PHOTOS.'~'.self::MAX_PHOTOS.'장 올려주세요.');
            }
            $licensePath = $row?->license_path;
            if (!empty($input['license_media_id'])) {
                $licensePath = app(ArtworkUploads::class)->claim($userId, [$input['license_media_id']])[$input['license_media_id']];
            }
            $values = [
                'status' => 'submitted', 'activity_name' => $input['activity_name'], 'region' => $input['region'],
                'bio' => $input['bio'], 'portfolio_link' => $input['portfolio_link'] ?? null,
                'portfolio_paths' => json_encode($paths, JSON_THROW_ON_ERROR),
                'license_number_enc' => !empty($input['license_number']) ? Crypt::encryptString($input['license_number']) : $row?->license_number_enc,
                'license_path' => $licensePath, 'submitted_at' => now(), 'updated_at' => now(),
            ];
            if ($row) {
                $version = (int) $row->version + 1;
                $this->db()->table('starttoo_artist_applications')->where('id', $row->id)->update([...$values, 'version' => $version]);
                $id = (int) $row->id;
                // 빠진 이전 사진은 지운다.
                app(ArtworkPhotos::class)->delete(array_values(array_diff($previous, $keep)));
            } else {
                $version = 1;
                $id = $this->db()->table('starttoo_artist_applications')->insertGetId([...$values, 'user_id' => $userId, 'version' => 1, 'created_at' => now()]);
            }
            $this->event($id, 'submitted', $version > 1 ? '수정해 다시 제출' : null, 'member', $version);
            app(Notifications::class)->notice($userId, 'artist_review', 'artist_app', (string) $id, 'submitted');
            return $this->view($this->db()->table('starttoo_artist_applications')->where('id', $id)->first());
        });
    }

    /** 운영자 심사 결정. reviewing·needs_changes·rejected는 사유를 남긴다. */
    public function review(int $id, string $status, string $operator, ?string $note): array
    {
        if (!in_array($status, ['reviewing', 'needs_changes', 'approved', 'rejected'], true)) {
            throw new \InvalidArgumentException('상태는 reviewing, needs_changes, approved, rejected 중 하나예요.');
        }
        if (in_array($status, ['needs_changes', 'rejected'], true) && trim((string) $note) === '') {
            throw new \InvalidArgumentException('보완 요청·반려는 --note로 사유를 적어주세요.');
        }
        if (trim($operator) === '') throw new \InvalidArgumentException('--operator로 처리자 이름을 적어주세요.');
        return $this->db()->transaction(function () use ($id, $status, $operator, $note) {
            $row = $this->db()->table('starttoo_artist_applications')->where('id', $id)->lockForUpdate()->first();
            if (!$row) throw new \InvalidArgumentException('신청서를 찾을 수 없어요.');
            if (!in_array($row->status, ['submitted', 'reviewing'], true)) {
                throw new \InvalidArgumentException("이미 결정된 신청서예요({$row->status}). 회원이 다시 제출하면 심사할 수 있어요.");
            }
            $this->db()->table('starttoo_artist_applications')->where('id', $id)->update([
                'status' => $status, 'review_note' => $note ? trim($note) : null,
                'reviewed_at' => $status === 'reviewing' ? $row->reviewed_at : now(), 'updated_at' => now(),
            ]);
            if ($status === 'approved') {
                $this->db()->table('starttoo_users')->where('id', $row->user_id)
                    ->update(['artist_status' => 'approved', 'artist_approved_at' => now(), 'updated_at' => now()]);
                // 승인과 함께 신청서 내용으로 상점을 만들어 둔다.
                app(Studios::class)->open((int) $row->user_id, $row);
            }
            $this->event($id, $status, $note ? trim($note) : null, 'operator:'.trim($operator), (int) $row->version);
            // 심사 단계마다 신청자에게 알림·푸시를 보낸다.
            app(Notifications::class)->notice((int) $row->user_id, 'artist_review', 'artist_app', (string) $id, $status);
            return ['id' => $id, 'user_id' => (int) $row->user_id, 'status' => $status];
        });
    }

    /** 서버 명령용 목록·상세(실명 포함). */
    public function operatorList(?string $status, int $limit): array
    {
        return $this->db()->table('starttoo_artist_applications as a')->join('starttoo_users as u', 'u.id', '=', 'a.user_id')
            ->when($status, fn ($q) => $q->where('a.status', $status))
            ->orderBy('a.submitted_at')->limit($limit)
            ->get(['a.id', 'a.user_id', 'u.nickname', 'a.activity_name', 'a.region', 'a.status', 'a.version', 'a.submitted_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function operatorDetail(int $id): ?array
    {
        $row = $this->db()->table('starttoo_artist_applications')->where('id', $id)->first();
        if (!$row) return null;
        return [...$this->view($row), 'user_id' => (int) $row->user_id,
            'real_name' => app(IdentityVerifications::class)->realName((int) $row->user_id),
            'license_number' => $row->license_number_enc ? Crypt::decryptString($row->license_number_enc) : null,
            'license_url' => $row->license_path ? app(MediaUrls::class)->url($row->license_path, 1080) : null];
    }

    private function view(object $row): array
    {
        $paths = json_decode($row->portfolio_paths, true, 512, JSON_THROW_ON_ERROR);
        return [
            'id' => (int) $row->id, 'status' => $row->status, 'version' => (int) $row->version,
            'activity_name' => $this->db()->table('starttoo_users')->where('id', $row->user_id)->value('nickname') ?? $row->activity_name,
            'region' => $row->region, 'bio' => $row->bio,
            'portfolio_link' => $row->portfolio_link,
            'photos' => array_map(fn ($p) => ['path' => $p, 'url' => app(MediaUrls::class)->url($p, 500)], $paths),
            'has_license' => $row->license_number_enc !== null,
            'review_note' => $row->review_note,
            'submitted_at' => $row->submitted_at, 'reviewed_at' => $row->reviewed_at,
            'events' => $this->db()->table('starttoo_artist_application_events')->where('application_id', $row->id)
                ->orderByDesc('id')->limit(30)->get(['status', 'note', 'version', 'created_at'])
                ->map(fn ($e) => ['status' => $e->status, 'note' => $e->note, 'version' => (int) $e->version, 'created_at' => $e->created_at])->all(),
        ];
    }

    private function event(int $id, string $status, ?string $note, string $actor, int $version): void
    {
        $this->db()->table('starttoo_artist_application_events')->insert([
            'application_id' => $id, 'status' => $status, 'note' => $note, 'actor' => $actor, 'version' => $version, 'created_at' => now(),
        ]);
    }

    private function fail(int $status, string $code, string $message): never
    {
        throw new HttpResponseException(response()->json(compact('code', 'message'), $status));
    }
}
