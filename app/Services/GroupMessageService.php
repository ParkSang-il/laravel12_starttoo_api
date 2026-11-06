<?php

namespace App\Services;

use App\Events\GroupMessageChanged;
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
        })->where('msg.project_id', $actor->project_id)->where('msg.group_id', $group)
            ->where('msg.created_at', '>=', $member->chat_visible_from)
            ->select('msg.*', 'a.id as account_id', 'a.status as account_status', 'a.display_name', 'a.nickname_tag',
                'a.avatar_url', 'a.avatar_thumbnail_url', 'a.avatar_disk', 'a.avatar_path', 'a.avatar_thumbnail_path',
                'author.status as author_status', 'author.role as author_role');
    }

    private function present(object $row, ProjectAccount $actor, object $member, bool $canSend): array
    {
        $deleted = $row->deleted_at !== null;
        $mine = (int) $row->author_id === $actor->id;
        $author = $row->author_status === 'active' && $row->account_status === 'active'
            ? [...$this->profiles->summary($row), 'role' => $row->author_role] : null;
        return ['id' => (int) $row->id, 'group_id' => $row->group_id, 'kind' => $row->kind,
            'body' => $deleted ? null : $row->body, 'created_at' => $this->iso($row->created_at),
            'edited_at' => $deleted ? null : $this->iso($row->edited_at), 'deleted' => $deleted, 'is_mine' => $mine,
            'can_edit' => !$deleted && $mine && $canSend,
            'can_delete' => !$deleted && $canSend && ($mine || $member->role === 'owner'), 'author' => $author];
    }

    public function index(ProjectAccount $actor, string $group, ?int $before, int $limit): array
    {
        $member = $this->reader($actor, $group);
        $canSend = $this->canSend($group, $member);
        // Read the watermark first: concurrent writes are replayed by changes().
        $cursor = (int) ($this->table('group_messages')->where('project_id', $actor->project_id)->where('group_id', $group)->max('change_seq') ?? 0);
        $rows = $this->records($actor, $group, $member)->when($before !== null, fn ($q) => $q->where('msg.id', '<', $before))
            ->orderByDesc('msg.id')->limit($limit + 1)->get();
        return ['messages' => $rows->take($limit)->reverse()->values()->map(fn ($r) => $this->present($r, $actor, $member, $canSend))->all(),
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
        return ['messages' => $page->map(fn ($r) => $this->present($r, $actor, $member, $canSend))->all(),
            'next_cursor' => $page->isEmpty() ? $since : (int) $page->last()->change_seq,
            'has_more' => $rows->count() > $limit, 'can_send' => $canSend, 'visible_from' => $this->iso($member->chat_visible_from)];
    }

    public function show(ProjectAccount $actor, string $group, int $id): array
    {
        $member = $this->reader($actor, $group);
        return $this->present($this->records($actor, $group, $member)->where('msg.id', $id)->firstOrFail(),
            $actor, $member, $this->canSend($group, $member));
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

    private function nextSeq(ProjectAccount $actor, string $group): int
    {
        return (int) ($this->table('group_messages')->where('project_id', $actor->project_id)->where('group_id', $group)->max('change_seq') ?? 0) + 1;
    }

    private function publish(string $group, array $result, string $change): array
    {
        [$message, $changed] = $result;
        if ($changed) event(new GroupMessageChanged($this->database->code(), $group, $message['id'], $change));
        return $message;
    }

    public function create(ProjectAccount $actor, string $group, array $data): array
    {
        $result = $this->locked($actor, $group, function ($member, $canSend) use ($actor, $group, $data) {
            abort_unless($canSend, 403, '그룹 메시지를 보낼 권한이 없습니다.');
            $hash = hash('sha256', $data['body']);
            $existing = $this->table('group_messages')->where('group_id', $group)->where('author_id', $actor->id)->where('request_id', $data['request_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, '같은 요청의 내용이 바뀌었습니다. 새 메시지로 보내 주세요.');
                return [$this->show($actor, $group, (int) $existing->id), false];
            }
            $time = $this->stamp();
            $id = $this->table('group_messages')->insertGetId(['project_id' => $actor->project_id, 'group_id' => $group,
                'author_id' => $actor->id, 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'body' => $data['body'],
                'kind' => 'text', 'change_seq' => $this->nextSeq($actor, $group), 'created_at' => $time, 'updated_at' => $time]);
            return [$this->show($actor, $group, (int) $id), true];
        });
        return $this->publish($group, $result, 'created');
    }

    private function revision(ProjectAccount $actor, string $group, object $message, string $action): void
    {
        // Only a future, separately authorized platform-admin feature may read this table.
        $this->table('group_message_revisions')->insert(['project_id' => $actor->project_id, 'group_id' => $group,
            'message_id' => $message->id, 'action' => $action, 'actor_id' => $actor->id, 'body' => $message->body, 'created_at' => $this->stamp()]);
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
            if ($body !== null) abort_if($message->deleted_at !== null, 409, '삭제된 메시지는 수정할 수 없습니다.');
            if (($body === null && $message->deleted_at !== null) || ($body !== null && $message->body === $body)) {
                return [$this->show($actor, $group, $id), false];
            }
            $this->revision($actor, $group, $message, $body === null ? 'deleted' : 'edited');
            $time = $this->stamp();
            $values = ['body' => $body, 'change_seq' => $this->nextSeq($actor, $group), 'updated_at' => $time];
            if ($body === null) $values += ['deleted_at' => $time, 'deleted_by' => $actor->id];
            else $values['edited_at'] = $time;
            $this->table('group_messages')->where('id', $id)->where('group_id', $group)->update($values);
            return [$this->show($actor, $group, $id), true];
        });
        return $this->publish($group, $result, $change);
    }

    public function update(ProjectAccount $actor, string $group, int $id, string $body): array { return $this->mutate($actor, $group, $id, $body); }
    public function delete(ProjectAccount $actor, string $group, int $id): array { return $this->mutate($actor, $group, $id, null); }
}
