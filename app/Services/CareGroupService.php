<?php

namespace App\Services;

use App\Models\ProjectAccount;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class CareGroupService
{
    public function __construct(private ProjectDatabase $database) {}

    private function table(string $table): Builder
    {
        return $this->database->database()->table($table);
    }

    private function group(ProjectAccount $actor, string $id): object
    {
        return $this->table('care_groups')->where('project_id', $actor->project_id)
            ->where('id', $id)->firstOrFail();
    }

    private function membership(string $group, int $account): ?object
    {
        return $this->table('group_memberships')->where('group_id', $group)
            ->where('account_id', $account)->first();
    }

    private function owner(ProjectAccount $actor, object $group): void
    {
        $member = $this->membership($group->id, $actor->id);
        abort_unless($member && $member->status === 'active' && $member->role === 'owner',
            403, '그룹 운영자만 처리할 수 있습니다.');
    }

    private function locked(ProjectAccount $actor, string $id, Closure $callback): mixed
    {
        return $this->database->database()->transaction(function () use ($actor, $id, $callback) {
            // All group writes, approvals and revocations share the same first lock.
            $group = $this->table('care_groups')->where('project_id', $actor->project_id)
                ->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($this->table('project_accounts')->where('id', $actor->id)
                ->where('project_id', $actor->project_id)->where('status', 'active')->exists(), 403);

            return $callback($group);
        }, 3);
    }

    private function audit(ProjectAccount $actor, string $group, string $event, array $metadata = []): void
    {
        $this->table('group_audit_events')->insert([
            'project_id' => $actor->project_id, 'group_id' => $group, 'actor_id' => $actor->id,
            'event' => $event, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    public function summary(object $group): array
    {
        // Explicit allowlist: no member lists, coordinates, photo paths or schedules.
        return ['id' => $group->id, 'name' => $group->name, 'description' => $group->description,
            'region_code' => $group->region_code, 'region_label' => $group->region_label,
            'is_discoverable' => (bool) $group->is_discoverable,
            'accepting_requests' => (bool) $group->accepting_requests,
            'has_discovery_center' => $group->discovery_latitude !== null && $group->discovery_longitude !== null];
    }

    private function memberSummary(object $member): array
    {
        return ['account_id' => (int) $member->account_id, 'role' => $member->role,
            'status' => $member->status, 'can_view_locations' => $this->hasPlaceAccess(
                $member->group_id, (int) $member->account_id, $member->role)];
    }

    private function hasPlaceAccess(string $group, int $account, string $role): bool
    {
        if ($role === 'owner') return true;

        return $this->table('group_place_permissions as access')->join('group_places as p', 'p.id', '=', 'access.place_id')
            ->where('access.group_id', $group)->where('access.account_id', $account)
            ->whereIn('access.access', ['view', 'care'])->whereNull('p.deleted_at')->exists();
    }

    private function resetPlaceAccess(string $group, int $account, int $actor): void
    {
        $this->table('group_place_permissions')->where('group_id', $group)->where('account_id', $account)->delete();
        // A membership change also invalidates any open permission editor snapshot.
        $this->table('group_places')->where('group_id', $group)->whereNull('deleted_at')
            ->increment('version', 1, ['updated_at' => now(), 'updated_by' => $actor]);
    }

    private function requestSummary(object $request): array
    {
        return ['id' => (int) $request->id, 'group_id' => $request->group_id,
            'message' => $request->message, 'availability' => $request->availability,
            'experience' => $request->experience, 'status' => $request->status,
            'version' => (int) $request->version, 'submitted_at' => $this->timestamp($request->submitted_at),
            'review_note' => $request->review_note, 'reviewed_at' => $this->timestamp($request->reviewed_at)];
    }

    private function timestamp(?string $value): ?string
    {
        return $value === null ? null : Carbon::parse($value, config('app.timezone', 'UTC'))->toIso8601String();
    }

    public function create(ProjectAccount $actor, array $data): array
    {
        return $this->database->database()->transaction(function () use ($actor, $data) {
            $id = (string) Str::uuid();
            $this->table('care_groups')->insert([
                ...$data, 'id' => $id, 'project_id' => $actor->project_id,
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->table('group_memberships')->insert([
                'project_id' => $actor->project_id, 'group_id' => $id,
                'account_id' => $actor->id, 'role' => 'owner', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $id, 'group_created');

            return $this->detail($actor, $id);
        }, 3);
    }

    public function discover(ProjectAccount $actor, string $region, int $page): array
    {
        $groups = $this->table('care_groups')->where('project_id', $actor->project_id)
            ->where('region_code', $region)->where('is_discoverable', true)
            ->where('accepting_requests', true)->orderBy('id')->offset(($page - 1) * 20)->limit(21)->get();

        return ['groups' => $groups->take(20)->map(fn ($g) => $this->summary($g))->all(),
            'page' => $page, 'has_more' => $groups->count() > 20];
    }

    public function nearby(ProjectAccount $actor, float $latitude, float $longitude, int $radius, int $page): array
    {
        // Only coarse public reference points, never group_places or a member's GPS.
        $latitudeBand = $radius / 110000 + 0.00001;
        $rows = $this->table('care_groups')->where('project_id', $actor->project_id)
            ->where('is_discoverable', true)
            ->whereNotNull('discovery_latitude')->whereNotNull('discovery_longitude')
            ->whereBetween('discovery_latitude', [max(-90, $latitude - $latitudeBand), min(90, $latitude + $latitudeBand)])
            ->get()->map(function ($group) use ($latitude, $longitude) {
                $dLat = deg2rad((float) $group->discovery_latitude - $latitude);
                $dLon = deg2rad((float) $group->discovery_longitude - $longitude);
                $a = sin($dLat / 2) ** 2 + cos(deg2rad($latitude))
                    * cos(deg2rad((float) $group->discovery_latitude)) * sin($dLon / 2) ** 2;
                $distance = 6371000 * 2 * asin(sqrt(min(1, max(0, $a))));

                return ['group' => $group, 'distance' => $distance];
            })->filter(fn ($entry) => $entry['distance'] <= $radius)
            ->sort(fn ($a, $b) => ($a['distance'] <=> $b['distance']) ?: strcmp($a['group']->id, $b['group']->id))->values();

        return ['groups' => $rows->slice(($page - 1) * 20, 20)->map(fn ($entry) => [
            ...$this->summary($entry['group']),
            // Rounded approximate distance; exact reference coordinates are not returned.
            'distance_m' => max(250, (int) round($entry['distance'] / 250) * 250),
        ])->values()->all(), 'page' => $page, 'has_more' => $rows->count() > $page * 20,
            'radius_m' => $radius];
    }

    public function mine(ProjectAccount $actor): array
    {
        return $this->table('group_memberships as m')->join('care_groups as g', 'g.id', '=', 'm.group_id')
            ->where('m.project_id', $actor->project_id)->where('m.account_id', $actor->id)
            ->where('m.status', 'active')->select('g.*', 'm.role')->orderBy('g.created_at')
            ->get()->map(fn ($g) => [...$this->summary($g), 'my_role' => $g->role,
                'can_view_locations' => $this->hasPlaceAccess($g->id, $actor->id, $g->role)])->all();
    }

    public function detail(ProjectAccount $actor, string $id): array
    {
        $group = $this->group($actor, $id);
        $member = $this->membership($id, $actor->id);
        $request = $this->table('group_join_requests')->where('group_id', $id)
            ->where('account_id', $actor->id)->first();
        abort_unless($group->is_discoverable || ($member && $member->status === 'active')
            || ($request && $request->status === 'pending'), 404);

        return [...$this->summary($group),
            'my_membership' => $member && $member->status === 'active' ? $this->memberSummary($member) : null,
            'my_request' => $request ? $this->requestSummary($request) : null];
    }

    public function update(ProjectAccount $actor, string $id, array $data): array
    {
        return $this->locked($actor, $id, function ($group) use ($actor, $data) {
            $this->owner($actor, $group);
            $this->table('care_groups')->where('id', $group->id)->update([...$data, 'updated_at' => now()]);
            $this->audit($actor, $group->id, 'settings_updated', ['fields' => array_keys($data)]);

            return $this->detail($actor, $group->id);
        });
    }

    public function createInvitation(ProjectAccount $actor, string $id, array $data): array
    {
        return $this->locked($actor, $id, function ($group) use ($actor, $data) {
            $this->owner($actor, $group);
            $token = Str::random(64);
            $invitationId = (string) Str::uuid();
            $expires = now()->addHours($data['expires_in_hours'] ?? 24);
            $this->table('group_invitations')->insert([
                'id' => $invitationId, 'project_id' => $actor->project_id,
                'group_id' => $group->id, 'created_by' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'phone_hash' => isset($data['phone']) ? $this->phoneHash($data['phone']) : null,
                'max_uses' => $data['max_uses'] ?? 1, 'used_count' => 0, 'expires_at' => $expires,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $group->id, 'invitation_created', ['invitation_id' => $invitationId]);

            return ['id' => $invitationId, 'token' => $token, 'expires_at' => $expires->toIso8601String(),
                'max_uses' => $data['max_uses'] ?? 1];
        });
    }

    private function phoneHash(string $phone): string
    {
        $key = config('app.key');
        abort_unless(is_string($key) && strlen($key) >= 32, 503, '서버 인증 설정을 확인해 주세요.');

        return hash_hmac('sha256', $phone, $key);
    }

    private function usableInvitation(ProjectAccount $actor, string $token): object
    {
        $invite = $this->table('group_invitations')->where('project_id', $actor->project_id)
            ->where('token_hash', hash('sha256', $token))->first();
        abort_unless($invite && !$invite->revoked_at && now()->lt($invite->expires_at)
            && $invite->used_count < $invite->max_uses
            && (!$invite->phone_hash || hash_equals($invite->phone_hash, $this->phoneHash($actor->phone))),
            422, '사용할 수 없는 초대입니다.');
        $issuer = $this->membership($invite->group_id, $invite->created_by);
        abort_unless($issuer && $issuer->role === 'owner' && $issuer->status === 'active'
            && $this->table('project_accounts')->where('id', $invite->created_by)
                ->where('status', 'active')->exists(), 422, '사용할 수 없는 초대입니다.');

        return $invite;
    }

    public function preview(ProjectAccount $actor, string $token): array
    {
        $invite = $this->usableInvitation($actor, $token);

        return ['group' => $this->summary($this->group($actor, $invite->group_id)),
            'expires_at' => $this->timestamp($invite->expires_at), 'requires_approval' => true];
    }

    public function revokeInvitation(ProjectAccount $actor, string $id, string $invitation): void
    {
        $this->locked($actor, $id, function ($group) use ($actor, $invitation) {
            $this->owner($actor, $group);
            $row = $this->table('group_invitations')->where('group_id', $group->id)
                ->where('id', $invitation)->firstOrFail();
            if (!$row->revoked_at) {
                $this->table('group_invitations')->where('id', $row->id)
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
                $this->audit($actor, $group->id, 'invitation_revoked', ['invitation_id' => $row->id]);
            }
        });
    }

    public function apply(ProjectAccount $actor, string $id, array $data): array
    {
        return $this->locked($actor, $id, function ($group) use ($actor, $data) {
            $member = $this->membership($group->id, $actor->id);
            abort_if($member && $member->status === 'removed', 403, '운영자에게 가입 가능 여부를 문의해 주세요.');
            abort_if($member && $member->status === 'active', 409, '이미 참여 중인 그룹입니다.');
            $existing = $this->table('group_join_requests')->where('group_id', $group->id)
                ->where('account_id', $actor->id)->first();
            // Network retry cannot consume another invitation use or create duplicate requests.
            if ($existing && $existing->status === 'pending') return $this->requestSummary($existing);

            $invite = null;
            if (isset($data['invitation_token'])) {
                $invite = $this->usableInvitation($actor, $data['invitation_token']);
                abort_unless($invite->group_id === $group->id, 422, '해당 그룹의 초대가 아닙니다.');
            } else {
                abort_unless($group->is_discoverable && $group->accepting_requests, 404);
            }
            if ($invite) {
                // Group lock serializes use with revoke and other applicants.
                $this->table('group_invitations')->where('id', $invite->id)->increment('used_count');
            }
            $values = ['project_id' => $actor->project_id, 'group_id' => $group->id,
                'account_id' => $actor->id, 'invitation_id' => $invite?->id,
                'message' => $data['message'], 'availability' => $data['availability'] ?? '',
                'experience' => $data['experience'] ?? '', 'status' => 'pending',
                'version' => $existing ? $existing->version + 1 : 1,
                'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null,
                'review_note' => null, 'updated_at' => now()];
            if ($existing) {
                $requestId = $existing->id;
                $this->table('group_join_requests')->where('id', $requestId)->update($values);
            } else {
                $requestId = $this->table('group_join_requests')->insertGetId([...$values, 'created_at' => now()]);
            }
            $this->audit($actor, $group->id, 'join_requested', ['request_id' => $requestId,
                'version' => $values['version']]);

            return $this->requestSummary($this->table('group_join_requests')->where('id', $requestId)->firstOrFail());
        });
    }

    public function myRequests(ProjectAccount $actor): array
    {
        return $this->table('group_join_requests')->where('project_id', $actor->project_id)
            ->where('account_id', $actor->id)->orderByDesc('submitted_at')->limit(100)->get()
            ->map(fn ($row) => [...$this->requestSummary($row),
                'group' => $this->summary($this->group($actor, $row->group_id))])->all();
    }

    public function requests(ProjectAccount $actor, string $id): array
    {
        $group = $this->group($actor, $id);
        $this->owner($actor, $group);

        return $this->table('group_join_requests as r')->join('project_accounts as a', 'a.id', '=', 'r.account_id')
            ->where('r.group_id', $id)->where('r.status', 'pending')
            ->select('r.*', 'a.display_name')->orderBy('r.submitted_at')->limit(100)->get()
            ->map(fn ($row) => [...$this->requestSummary($row), 'account_id' => (int) $row->account_id,
                'display_name' => $row->display_name])->all();
    }

    public function review(ProjectAccount $actor, string $id, int $requestId, array $data): array
    {
        return $this->locked($actor, $id, function ($group) use ($actor, $requestId, $data) {
            $this->owner($actor, $group);
            $row = $this->table('group_join_requests')->where('group_id', $group->id)
                ->where('id', $requestId)->lockForUpdate()->firstOrFail();
            abort_if((int) $row->account_id === $actor->id, 403, '자신의 가입 신청을 승인할 수 없습니다.');
            abort_unless($row->status === 'pending' && (int) $row->version === $data['version'],
                409, '신청 상태가 변경됐습니다. 새로 조회해 주세요.');
            if ($data['decision'] === 'approve') {
                abort_unless($this->table('project_accounts')->where('id', $row->account_id)
                    ->where('status', 'active')->exists(), 409, '현재 가입할 수 없는 회원입니다.');
                $member = $this->membership($group->id, $row->account_id);
                abort_if($member && $member->status === 'removed', 409, '가입이 제한된 회원입니다.');
                abort_if($member && $member->status === 'active', 409, '이미 참여 중인 회원입니다.');
                $this->resetPlaceAccess($group->id, (int) $row->account_id, $actor->id);
                $this->table('group_memberships')->updateOrInsert(
                    ['group_id' => $group->id, 'account_id' => $row->account_id],
                    ['project_id' => $actor->project_id, 'role' => $data['role'], 'status' => 'active',
                        'created_at' => $member?->created_at ?? now(), 'updated_at' => now()]);
            }
            $status = $data['decision'] === 'approve' ? 'approved' : 'rejected';
            $this->table('group_join_requests')->where('id', $row->id)->update([
                'status' => $status, 'version' => $row->version + 1,
                'reviewed_by' => $actor->id, 'review_note' => $data['note'] ?? null,
                'reviewed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($actor, $group->id, 'join_'.$status,
                ['request_id' => $row->id, 'account_id' => $row->account_id, 'version' => $row->version + 1]);

            return $this->requestSummary($this->table('group_join_requests')->where('id', $row->id)->firstOrFail());
        });
    }

    public function withdraw(ProjectAccount $actor, string $id, int $requestId, int $version): void
    {
        $this->locked($actor, $id, function ($group) use ($actor, $requestId, $version) {
            $row = $this->table('group_join_requests')->where('group_id', $group->id)
                ->where('account_id', $actor->id)->where('id', $requestId)->firstOrFail();
            abort_unless($row->status === 'pending' && (int) $row->version === $version,
                409, '신청 상태가 변경됐습니다. 새로 조회해 주세요.');
            $this->table('group_join_requests')->where('id', $row->id)->update([
                'status' => 'withdrawn', 'version' => $row->version + 1, 'updated_at' => now(),
            ]);
            $this->audit($actor, $group->id, 'join_withdrawn', ['request_id' => $row->id]);
        });
    }

    public function members(ProjectAccount $actor, string $id): array
    {
        $this->owner($actor, $this->group($actor, $id));

        return $this->table('group_memberships as m')->join('project_accounts as a', 'a.id', '=', 'm.account_id')
            ->where('m.group_id', $id)->where('m.status', 'active')
            ->select('m.*', 'a.display_name')->orderBy('m.id')->limit(100)->get()
            ->map(fn ($row) => [...$this->memberSummary($row), 'display_name' => $row->display_name])->all();
    }

    public function leave(ProjectAccount $actor, string $id, ?int $target = null): void
    {
        $this->locked($actor, $id, function ($group) use ($actor, $target) {
            if ($target !== null) $this->owner($actor, $group);
            $member = $this->membership($group->id, $target ?? $actor->id);
            abort_unless($member && $member->status === 'active', 404);
            abort_if($member->role === 'owner', 409, '운영자는 그룹을 탈퇴하거나 내보낼 수 없습니다.');
            $this->resetPlaceAccess($group->id, (int) $member->account_id, $actor->id);
            $this->table('group_memberships')->where('id', $member->id)->update([
                'status' => $target === null ? 'left' : 'removed', 'updated_at' => now(),
            ]);
            $this->audit($actor, $group->id, $target === null ? 'member_left' : 'member_removed',
                ['account_id' => $member->account_id]);
        });
    }
}
