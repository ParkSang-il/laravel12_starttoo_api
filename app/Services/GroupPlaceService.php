<?php

namespace App\Services;

use App\Models\ProjectAccount;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

class GroupPlaceService
{
    public function __construct(private ProjectDatabase $database) {}

    private function table(string $table): Builder
    {
        return $this->database->database()->table($table);
    }

    private function context(ProjectAccount $actor, string $groupId, bool $lock = false): array
    {
        $query = $this->table('care_groups')->where('id', $groupId)->where('project_id', $actor->project_id);
        if ($lock) $query->lockForUpdate();
        $group = $query->firstOrFail();
        $member = $this->table('group_memberships')->where('group_id', $groupId)
            ->where('account_id', $actor->id)->where('status', 'active')->first();
        abort_unless($member && in_array($member->role, ['owner', 'caregiver', 'viewer'], true)
            && $this->table('project_accounts')->where('id', $actor->id)
                ->where('project_id', $actor->project_id)->where('status', 'active')->exists(),
            403, '현재 이 그룹의 돌봄장소에 접근할 수 없습니다.');

        return ['group' => $group, 'member' => $member, 'owner' => $member->role === 'owner'];
    }

    private function locked(ProjectAccount $actor, string $groupId, Closure $callback): mixed
    {
        // Same first lock as group approval, leave and member removal.
        return $this->database->database()->transaction(fn () =>
            $callback($this->context($actor, $groupId, true)), 3);
    }

    private function owner(array $context): void
    {
        abort_unless($context['owner'], 403, '돌봄장소와 권한은 그룹 운영자만 변경할 수 있습니다.');
    }

    private function visiblePlaces(ProjectAccount $actor, array $context): Builder
    {
        $query = $this->table('group_places as p')->where('p.project_id', $actor->project_id)
            ->where('p.group_id', $context['group']->id)->whereNull('p.deleted_at');
        if (!$context['owner']) {
            $query->whereExists(function (Builder $q) use ($actor) {
                $q->selectRaw('1')->from('group_place_permissions as access')
                    ->whereColumn('access.place_id', 'p.id')->whereColumn('access.group_id', 'p.group_id')
                    ->where('access.account_id', $actor->id)->whereIn('access.access', ['view', 'care']);
            });
        }

        return $query;
    }

    private function summary(ProjectAccount $actor, array $context, object $place): array
    {
        $access = $context['owner'] ? 'manage' : $this->table('group_place_permissions')
            ->where('place_id', $place->id)->where('account_id', $actor->id)->value('access');
        abort_unless($context['owner'] || in_array($access, ['view', 'care'], true), 404);

        return ['id' => $place->id, 'group_id' => $place->group_id, 'name' => $place->name,
            'note' => $place->note, 'latitude' => $place->latitude === null ? null : (float) $place->latitude,
            'longitude' => $place->longitude === null ? null : (float) $place->longitude,
            'interval_hours' => (int) $place->interval_hours, 'version' => (int) $place->version,
            'access' => $context['member']->role === 'viewer' ? 'view' : $access,
            'can_manage' => $context['owner']];
    }

    private function place(ProjectAccount $actor, array $context, string $id): object
    {
        // A known UUID still needs a grant; disallowed and unknown IDs both return 404.
        return $this->visiblePlaces($actor, $context)->where('p.id', $id)->firstOrFail();
    }

    private function checkVersion(object $place, int $version): void
    {
        abort_unless((int) $place->version === $version, 409, '장소 또는 권한이 변경됐습니다. 새로 조회해 주세요.');
    }

    private function audit(ProjectAccount $actor, string $group, string $event, array $metadata): void
    {
        // Location values and private notes never go into audit metadata.
        $this->table('group_audit_events')->insert(['project_id' => $actor->project_id,
            'group_id' => $group, 'actor_id' => $actor->id, 'event' => $event,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public function index(ProjectAccount $actor, string $groupId, int $page): array
    {
        $context = $this->context($actor, $groupId);
        $rows = $this->visiblePlaces($actor, $context)->orderBy('p.id')->offset(($page - 1) * 20)->limit(21)->get();

        return ['places' => $rows->take(20)->map(fn ($row) => $this->summary($actor, $context, $row))->all(),
            'page' => $page, 'has_more' => $rows->count() > 20, 'can_manage' => $context['owner']];
    }

    public function show(ProjectAccount $actor, string $groupId, string $id): array
    {
        $context = $this->context($actor, $groupId);

        return $this->summary($actor, $context, $this->place($actor, $context, $id));
    }

    public function create(ProjectAccount $actor, string $groupId, array $data): array
    {
        return $this->locked($actor, $groupId, function ($context) use ($actor, $groupId, $data) {
            $this->owner($context);
            $id = (string) Str::uuid();
            $this->table('group_places')->insert([...$data, 'id' => $id, 'project_id' => $actor->project_id,
                'group_id' => $groupId, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now()]);
            $this->audit($actor, $groupId, 'place_created', ['place_id' => $id]);

            return $this->summary($actor, $context, $this->place($actor, $context, $id));
        });
    }

    public function update(ProjectAccount $actor, string $groupId, string $id, array $data): array
    {
        return $this->locked($actor, $groupId, function ($context) use ($actor, $groupId, $id, $data) {
            $this->owner($context);
            $place = $this->place($actor, $context, $id);
            $this->checkVersion($place, (int) $data['version']);
            unset($data['version']);
            $this->table('group_places')->where('id', $id)->update([...$data, 'version' => $place->version + 1,
                'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit($actor, $groupId, 'place_updated', ['place_id' => $id,
                'version' => $place->version + 1, 'fields' => array_keys($data)]);

            return $this->summary($actor, $context, $this->place($actor, $context, $id));
        });
    }

    public function archive(ProjectAccount $actor, string $groupId, string $id, int $version): void
    {
        $this->locked($actor, $groupId, function ($context) use ($actor, $groupId, $id, $version) {
            $this->owner($context);
            $place = $this->place($actor, $context, $id);
            $this->checkVersion($place, $version);
            $this->table('group_places')->where('id', $id)->update(['deleted_at' => now(),
                'version' => $place->version + 1, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->table('group_place_permissions')->where('place_id', $id)->delete();
            $this->audit($actor, $groupId, 'place_archived', ['place_id' => $id]);
        });
    }

    public function permissions(ProjectAccount $actor, string $groupId, string $id, int $page): array
    {
        $context = $this->context($actor, $groupId);
        $this->owner($context);
        $place = $this->place($actor, $context, $id);
        $rows = $this->table('group_memberships as m')->join('project_accounts as a', 'a.id', '=', 'm.account_id')
            ->leftJoin('group_place_permissions as access', function ($join) use ($id) {
                $join->on('access.group_id', '=', 'm.group_id')->on('access.account_id', '=', 'm.account_id')
                    ->where('access.place_id', '=', $id);
            })->where('m.group_id', $groupId)->where('m.status', 'active')->where('a.status', 'active')
            ->whereIn('m.role', ['owner', 'caregiver', 'viewer'])
            ->select('m.account_id', 'm.role', 'a.display_name', 'access.access')
            ->orderBy('m.account_id')->offset(($page - 1) * 20)->limit(21)->get();

        return ['place_version' => (int) $place->version, 'page' => $page, 'has_more' => $rows->count() > 20,
            'members' => $rows->take(20)->map(fn ($row) => ['account_id' => (int) $row->account_id,
                'display_name' => $row->display_name, 'role' => $row->role,
                'access' => $row->role === 'owner' ? 'manage' : ($row->access === null ? 'none' :
                    ($row->role === 'viewer' ? 'view' : $row->access))])->all()];
    }

    public function grant(ProjectAccount $actor, string $groupId, string $id, int $account, string $access, int $version): array
    {
        return $this->locked($actor, $groupId, function ($context) use ($actor, $groupId, $id, $account, $access, $version) {
            $this->owner($context);
            $place = $this->place($actor, $context, $id);
            $this->checkVersion($place, $version);
            $member = $this->table('group_memberships as m')->join('project_accounts as a', 'a.id', '=', 'm.account_id')
                ->where('m.group_id', $groupId)->where('m.account_id', $account)->where('m.status', 'active')
                ->where('a.status', 'active')->select('m.role')->firstOrFail();
            abort_if($member->role === 'owner', 409, '운영자의 관리 권한은 변경할 수 없습니다.');
            abort_unless(in_array($member->role, ['caregiver', 'viewer'], true), 403);
            abort_if($member->role === 'viewer' && $access === 'care', 422, '열람자에게 돌봄 권한을 줄 수 없습니다.');
            if ($access === 'none') {
                $this->table('group_place_permissions')->where('place_id', $id)->where('account_id', $account)->delete();
            } else {
                $this->table('group_place_permissions')->updateOrInsert(['place_id' => $id, 'account_id' => $account],
                    ['project_id' => $actor->project_id, 'group_id' => $groupId, 'access' => $access,
                        'granted_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->table('group_places')->where('id', $id)->update(['version' => $place->version + 1,
                'updated_at' => now(), 'updated_by' => $actor->id]);
            $this->audit($actor, $groupId, 'place_access_changed', ['place_id' => $id,
                'account_id' => $account, 'access' => $access, 'version' => $place->version + 1]);

            return ['place_version' => (int) $place->version + 1, 'account_id' => $account, 'access' => $access];
        });
    }
}
