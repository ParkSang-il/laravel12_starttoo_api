<?php

namespace App\Services;

use App\Events\GroupMessageChanged;
use App\Events\GroupChatReadChanged;
use Illuminate\Support\Str;
use Illuminate\Support\Collection;
use App\Models\ProjectAccount;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;

class GroupMessageService
{
    public function __construct(private ProjectDatabase $database, private GroupProfile $profiles) {}

    private function table(string $name): Builder { return $this->database->database()->table($name); }
    private function stamp(): string { return now()->format('Y-m-d H:i:s.u'); }
    private function iso(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, config('app.timezone', 'UTC'))->toISOString();
    }

    private function reader(ProjectAccount $actor, string $group): object
    {
        $member = $this->table('group_memberships as m')
            ->join('care_groups as g', function ($j) { $j->on('g.id', '=', 'm.group_id')->on('g.project_id', '=', 'm.project_id'); })
            ->join('project_accounts as a', function ($j) { $j->on('a.id', '=', 'm.account_id')->on('a.project_id', '=', 'm.project_id'); })
            ->where('m.project_id', $actor->project_id)->where('m.group_id', $group)
            ->where('m.account_id', $actor->id)->where('m.status', 'active')->where('a.status', 'active')
            ->select('m.*')->first();
        abort_unless($member, 404);
        abort_unless(in_array($member->role, ['owner', 'caregiver'], true), 403, '그룹 대화를 볼 권한이 없습니다.');
        abort_unless($member->chat_visible_from !== null, 404);
        return $member;
    }

    private function canSend(string $group, object $member): bool
    {
        if ($member->role === 'owner') return true;
        if ($member->role !== 'caregiver') return false;
        $override = $this->table('group_chat_member_overrides')->where('project_id', $member->project_id)
            ->where('group_id', $group)->where('account_id', $member->account_id)->first();
        if ($override) return $override->mode === 'allow';
        $policy = $this->table('group_chat_role_policies')->where('project_id', $member->project_id)
            ->where('group_id', $group)->where('role', 'caregiver')->first();
        return $policy ? (bool) $policy->can_send : true;
    }

    private function records(ProjectAccount $actor, string $group, object $member): Builder
    {
        return $this->table('group_messages as msg')->leftJoin('project_accounts as a', function ($j) {
            $j->on('a.id', '=', 'msg.author_id')->on('a.project_id', '=', 'msg.project_id');
        })->leftJoin('group_memberships as author', function ($j) {
            $j->on('author.group_id', '=', 'msg.group_id')->on('author.project_id', '=', 'msg.project_id')->on('author.account_id', '=', 'msg.author_id');
        })->leftJoin('group_care_records as r', function ($j) {
            $j->on('r.id', '=', 'msg.care_record_id')->on('r.group_id', '=', 'msg.group_id')->on('r.project_id', '=', 'msg.project_id');
        })->leftJoin('group_places as p', function ($j) {
            $j->on('p.id', '=', 'r.place_id')->on('p.group_id', '=', 'r.group_id')->on('p.project_id', '=', 'r.project_id');
        })->leftJoin('group_place_permissions as perm', function ($j) use ($actor) {
            $j->on('perm.place_id', '=', 'r.place_id')->on('perm.group_id', '=', 'msg.group_id')->where('perm.account_id', '=', $actor->id);
        })->where('msg.project_id', $actor->project_id)->where('msg.group_id', $group)
            ->where('msg.created_at', '>=', $member->chat_visible_from)
            ->select('msg.*', 'a.id as account_id', 'a.status as account_status', 'a.display_name', 'a.nickname_tag',
                'a.avatar_url', 'a.avatar_thumbnail_url', 'a.avatar_disk', 'a.avatar_path', 'a.avatar_thumbnail_path',
                'author.status as author_status', 'author.role as author_role',
                'r.id as record_id', 'r.place_id as record_place_id', 'r.tags as record_tags', 'r.sightings as record_sightings',
                'p.name as record_place_name', 'p.deleted_at as place_deleted_at', 'perm.access as place_access');
    }

    private function present(object $row, ProjectAccount $actor, object $member, bool $canSend, array $animals = []): array
    {
        $deleted = $row->deleted_at !== null;
        $mine = (int) $row->author_id === $actor->id;
        $author = $row->author_status === 'active' && $row->account_status === 'active'
            ? [...$this->profiles->summary($row), 'role' => $row->author_role] : null;
        $care = null;
        if (!$deleted && $this->canSeeCare($row, $member)) {
            $seen = [];
            foreach ((json_decode($row->record_sightings, true) ?? []) as $id => $status) {
                if ($status === 'seen' && isset($animals[$id])) $seen[] = $animals[$id];
            }
            $care = ['id' => $row->record_id, 'place_id' => $row->record_place_id, 'place_name' => $row->record_place_name,
                'tags' => json_decode($row->record_tags, true) ?? [], 'seen_animals' => $seen];
        }
        return ['id' => (int) $row->id, 'group_id' => $row->group_id, 'kind' => $row->kind,
            'body' => $deleted ? null : $row->body, 'created_at' => $this->iso($row->created_at),
            'edited_at' => $deleted ? null : $this->iso($row->edited_at), 'deleted' => $deleted, 'is_mine' => $mine,
            'request_id' => $mine && $row->kind === 'text' ? $row->request_id : null,
            'care_record' => $care, 'can_edit' => !$deleted && $mine && $canSend && $row->kind === 'text',
            'can_delete' => !$deleted && $canSend && ($mine || $member->role === 'owner'), 'author' => $author];
    }

    private function canSeeCare(object $row, object $member): bool
    {
        return $row->kind === 'care_record' && $row->record_id !== null && $row->record_place_name !== null
            && $row->place_deleted_at === null && ($member->role === 'owner' || in_array($row->place_access, ['view', 'care'], true));
    }

    private function presentRows(Collection $rows, ProjectAccount $actor, object $member, bool $canSend): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->deleted_at !== null || !$this->canSeeCare($row, $member)) continue;
            foreach ((json_decode($row->record_sightings, true) ?? []) as $id => $status) if ($status === 'seen') $ids[] = $id;
        }
        $animals = $ids ? $this->table('group_animals')->where('project_id', $actor->project_id)
            ->where('group_id', $member->group_id)->whereIn('id', array_unique($ids))->pluck('name', 'id')->all() : [];
        return $rows->map(fn ($r) => $this->present($r, $actor, $member, $canSend, $animals))->values()->all();
    }

    public function index(ProjectAccount $actor, string $group, ?int $before, int $limit): array
    {
        $member = $this->reader($actor, $group);
        $canSend = $this->canSend($group, $member);
        // Read the watermark first: concurrent writes are replayed by changes().
        $cursor = (int) ($this->table('group_messages')->where('project_id', $actor->project_id)->where('group_id', $group)->max('change_seq') ?? 0);
        $rows = $this->records($actor, $group, $member)->when($before !== null, fn ($q) => $q->where('msg.id', '<', $before))
            ->orderByDesc('msg.id')->limit($limit + 1)->get();
        return ['messages' => $this->presentRows($rows->take($limit)->reverse()->values(), $actor, $member, $canSend),
            'has_more' => $rows->count() > $limit, 'can_send' => $canSend,
            'visible_from' => $this->iso($member->chat_visible_from), 'change_cursor' => $cursor];
    }

    public function changes(ProjectAccount $actor, string $group, int $since, int $limit): array
    {
        $member = $this->reader($actor, $group);
        $canSend = $this->canSend($group, $member);
        $rows = $this->records($actor, $group, $member)->where('msg.change_seq', '>', $since)
            ->orderBy('msg.change_seq')->limit($limit + 1)->get();
        $page = $rows->take($limit);
        return ['messages' => $this->presentRows($page, $actor, $member, $canSend),
            'next_cursor' => $page->isEmpty() ? $since : (int) $page->last()->change_seq,
            'has_more' => $rows->count() > $limit, 'can_send' => $canSend, 'visible_from' => $this->iso($member->chat_visible_from)];
    }

    public function show(ProjectAccount $actor, string $group, int $id): array
    {
        $member = $this->reader($actor, $group);
        return $this->presentRows(collect([$this->records($actor, $group, $member)->where('msg.id', $id)->firstOrFail()]),
            $actor, $member, $this->canSend($group, $member))[0];
    }

    private function locked(ProjectAccount $actor, string $group, Closure $action): array
    {
        return $this->database->database()->transaction(function () use ($actor, $group, $action) {
            // Keep this locking read first: the subsequent snapshot must include the previous writer.
            $this->table('care_groups')->where('project_id', $actor->project_id)->where('id', $group)->lockForUpdate()->firstOrFail();
            $member = $this->reader($actor, $group);
            return $action($member, $this->canSend($group, $member));
        }, 3);
    }

    private function nextSeq(int $projectId, string $group): int
    {
        return (int) ($this->table('group_messages')->where('project_id', $projectId)->where('group_id', $group)->max('change_seq') ?? 0) + 1;
    }

    private function publish(string $group, array $result, string $change): array
    {
        [$message, $changed] = $result;
        if ($changed) {
            try {
                event(new GroupMessageChanged($this->database->code(), $group, $message['id'], $change));
            } catch (\Throwable $e) {
                // The write has committed; report notification failures without rejecting it.
                report($e);
            }
        }
        return $message;
    }

    public function create(ProjectAccount $actor, string $group, array $data): array
    {
        $result = $this->locked($actor, $group, function ($member, $canSend) use ($actor, $group, $data) {
            abort_unless($canSend, 403, '그룹 메시지를 보낼 권한이 없습니다.');
            $hash = hash('sha256', $data['body']);
            $existing = $this->table('group_messages')->where('group_id', $group)->where('author_id', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->kind === 'text', 409, '다른 종류의 메시지 요청입니다.');
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, '같은 요청의 내용이 바뀌었습니다. 새 메시지로 보내 주세요.');
                return [$this->show($actor, $group, (int) $existing->id), false];
            }
            $time = $this->stamp();
            $id = $this->table('group_messages')->insertGetId(['project_id' => $actor->project_id, 'group_id' => $group,
                'author_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'body' => $data['body'],
                'kind' => 'text', 'change_seq' => $this->nextSeq((int) $actor->project_id, $group), 'created_at' => $time, 'updated_at' => $time]);
            return [$this->show($actor, $group, (int) $id), true];
        });
        return $this->publish($group, $result, 'created');
    }

    private function revision(ProjectAccount $actor, string $group, object $message, string $action): void
    {
        // Only a future, separately authorized platform-admin feature may read this table.
        $this->table('group_message_revisions')->insert(['project_id' => $actor->project_id, 'group_id' => $group,
            'message_id' => $message->id, 'action' => $action, 'actor_id' => $actor->id, 'body' => $message->body ?? '', 'created_at' => $this->stamp()]);
    }

    private function mutate(ProjectAccount $actor, string $group, int $id, ?string $body): array
    {
        $change = $body === null ? 'deleted' : 'updated';
        $result = $this->locked($actor, $group, function ($member, $canSend) use ($actor, $group, $id, $body) {
            $message = $this->table('group_messages')->where('project_id', $actor->project_id)->where('group_id', $group)
                ->where('id', $id)->where('created_at', '>=', $member->chat_visible_from)->lockForUpdate()->firstOrFail();
            abort_unless($canSend, 403, '메시지 발송이 제한돼 수정하거나 삭제할 수 없습니다.');
            $mine = (int) $message->author_id === $actor->id;
            abort_unless($mine || ($body === null && $member->role === 'owner'), 403, '이 메시지를 변경할 권한이 없습니다.');
            abort_if($body !== null && $message->kind !== 'text', 409, '자동 메시지는 수정할 수 없습니다.');
            if ($body !== null) abort_if($message->deleted_at !== null, 409, '삭제된 메시지는 수정할 수 없습니다.');
            if (($body === null && $message->deleted_at !== null) || ($body !== null && $message->body === $body)) {
                return [$this->show($actor, $group, $id), false];
            }
            $this->revision($actor, $group, $message, $body === null ? 'deleted' : 'edited');
            $time = $this->stamp();
            $values = ['body' => $body, 'change_seq' => $this->nextSeq((int) $actor->project_id, $group), 'updated_at' => $time];
            if ($body === null) $values += ['deleted_at' => $time, 'deleted_by' => $actor->id];
            else $values['edited_at'] = $time;
            $this->table('group_messages')->where('id', $id)->where('group_id', $group)->update($values);
            return [$this->show($actor, $group, $id), true];
        });
        return $this->publish($group, $result, $change);
    }

    /** Called only while GroupCareService holds the group row lock. */
    public function insertCareRecord(int $projectId, string $group, int $authorId, string $recordId): int
    {
        $requestId = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = (string) Str::uuid();
            if (!$this->table('group_messages')->where('group_id', $group)->where('author_id', $authorId)->where('request_id', $candidate)->first()) {
                $requestId = $candidate; break;
            }
        }
        if ($requestId === null) throw new \RuntimeException('자동 메시지 식별자를 생성하지 못했습니다.');
        $time = $this->stamp();
        return (int) $this->table('group_messages')->insertGetId(['project_id' => $projectId, 'group_id' => $group,
            'author_id' => $authorId, 'request_id' => $requestId, 'payload_hash' => hash('sha256', 'care_record:'.$recordId),
            'kind' => 'care_record', 'care_record_id' => $recordId, 'body' => null,
            'change_seq' => $this->nextSeq($projectId, $group), 'created_at' => $time, 'updated_at' => $time]);
    }

    public function announce(string $group, int $id): void
    {
        $this->publish($group, [['id' => $id], true], 'created');
    }

    public function markRead(ProjectAccount $actor, string $group, int $requested): array
    {
        $member = $this->reader($actor, $group);
        $max = (int) ($this->table('group_messages')->where('project_id', $actor->project_id)->where('group_id', $group)
            ->where('created_at', '>=', $member->chat_visible_from)->max('id') ?? 0);
        $target = min($requested, $max);
        $scope = fn () => $this->table('group_memberships')->where('id', $member->id)->where('project_id', $actor->project_id)
            ->where('status', 'active')->whereIn('role', ['owner', 'caregiver'])->where('chat_visible_from', $member->chat_visible_from);
        $changed = $target > 0 ? $scope()->where(fn ($q) => $q->whereNull('chat_read_message_id')->orWhere('chat_read_message_id', '<', $target))
            ->update(['chat_read_message_id' => $target]) : 0;
        if ($changed) {
            try { event(new GroupChatReadChanged($this->database->code(), $group)); }
            catch (\Throwable $e) { report($e); }
        }
        return ['read_through' => (int) ($scope()->value('chat_read_message_id') ?? 0)];
    }

    public function unreadCounts(ProjectAccount $actor, string $group, int $from): array
    {
        $member = $this->reader($actor, $group);
        $floor = $this->table('group_messages')->where('project_id', $actor->project_id)->where('group_id', $group)
            ->where('created_at', '>=', $member->chat_visible_from)->orderByDesc('id')->offset(299)->limit(1)->value('id');
        $effective = max($from, (int) ($floor ?? 0));
        $counts = $this->table('group_messages as msg')->join('group_memberships as m', function ($j) {
            $j->on('m.group_id', '=', 'msg.group_id')->on('m.project_id', '=', 'msg.project_id');
        })->join('project_accounts as a', function ($j) {
            $j->on('a.id', '=', 'm.account_id')->on('a.project_id', '=', 'm.project_id');
        })->where('msg.project_id', $actor->project_id)->where('msg.group_id', $group)->where('msg.id', '>=', $effective)
            ->where('msg.created_at', '>=', $member->chat_visible_from)->whereNull('msg.deleted_at')
            ->where('m.status', 'active')->whereIn('m.role', ['owner', 'caregiver'])->where('a.status', 'active')
            ->whereColumn('m.chat_visible_from', '<=', 'msg.created_at')->whereColumn('m.account_id', '<>', 'msg.author_id')
            ->where(fn ($q) => $q->whereNull('m.chat_read_message_id')->orWhereColumn('m.chat_read_message_id', '<', 'msg.id'))
            ->select('msg.id')->selectRaw('COUNT(*) as unread')->groupBy('msg.id')->get();
        return ['from' => $effective, 'visible_from' => $this->iso($member->chat_visible_from),
            'counts' => $counts->map(fn ($r) => ['id' => (int) $r->id, 'unread' => (int) $r->unread])->all()];
    }

    public function unreadByGroup(ProjectAccount $actor, ?string $group = null): array
    {
        return $this->table('group_messages as msg')->join('group_memberships as m', function ($j) use ($actor) {
            $j->on('m.group_id', '=', 'msg.group_id')->on('m.project_id', '=', 'msg.project_id')->where('m.account_id', '=', $actor->id);
        })->where('msg.project_id', $actor->project_id)->when($group !== null, fn ($q) => $q->where('msg.group_id', $group))
            ->where('m.status', 'active')->whereIn('m.role', ['owner', 'caregiver'])->whereNull('msg.deleted_at')
            ->where('msg.author_id', '<>', $actor->id)->whereColumn('msg.created_at', '>=', 'm.chat_visible_from')
            ->whereRaw('msg.id > COALESCE(m.chat_read_message_id, 0)')
            ->select('msg.group_id')->selectRaw('COUNT(*) as unread')->groupBy('msg.group_id')->pluck('unread', 'msg.group_id')->all();
    }

    public function update(ProjectAccount $actor, string $group, int $id, string $body): array { return $this->mutate($actor, $group, $id, $body); }
    public function delete(ProjectAccount $actor, string $group, int $id): array { return $this->mutate($actor, $group, $id, null); }
}
