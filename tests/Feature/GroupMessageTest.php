<?php

namespace Tests\Feature;

use App\Events\GroupMessageChanged;
use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroupMessageTest extends TestCase
{
    private array $tokens = [];
    private string $base = '/api/v1/projects/streetcat';

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('group-test-secret-', 6),
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'platform.projects' => ['streetcat' => ['name' => '스트릿캣', 'connection' => 'test_groups']],
            'database.connections.test_groups' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        $this->assertSame(0, Artisan::call('project:migrate', ['code' => 'streetcat', '--force' => true]));
        app(ProjectDatabase::class)->run('streetcat', function () {
            $project = Project::create(['code' => 'streetcat', 'name' => '스트릿캣']);
            for ($i = 1; $i <= 4; $i++) {
                $account = new ProjectAccount;
                $account->forceFill(['project_id' => $project->id, 'status' => 'active',
                    'phone' => '010'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'phone_verified_at' => now(), 'display_name' => '테스트 '.$i])->save();
                $this->tokens[$i] = Auth::guard('api')->login($account);
                app(ProjectDatabase::class)->database()->table('project_auth_sessions')->insert([
                    'id' => hash('sha256', Auth::guard('api')->payload()->get('jti')),
                    'account_id' => $account->id, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                Auth::forgetGuards();
            }
        });
    }


    private function group(): string
    {
        Event::fake([GroupMessageChanged::class]);
        return $this->withToken($this->tokens[1])->postJson($this->base.'/groups', [
            'name' => '대화 테스트팀', 'region_code' => '0000000000', 'region_label' => '테스트 동네',
            'is_discoverable' => true, 'accepting_requests' => true,
        ])->assertCreated()->json('data.id');
    }

    private function join(string $group, int $account, string $role = 'caregiver'): void
    {
        $request = $this->withToken($this->tokens[$account])->postJson($this->base."/groups/$group/join-requests",
            ['message' => '함께할게요'])->assertOk()->json('data');
        $this->withToken($this->tokens[1])->postJson($this->base."/groups/$group/join-requests/{$request['id']}/review",
            ['version' => $request['version'], 'decision' => 'approve', 'role' => $role])->assertOk();
    }

    private function path(string $group, string $suffix = ''): string { return $this->base."/groups/$group/messages".$suffix; }
    private function send(string $group, int $actor, string $body = '안녕하세요', ?string $request = null)
    {
        return $this->withToken($this->tokens[$actor])->postJson($this->path($group),
            ['request_id' => $request ?? (string) Str::uuid(), 'body' => $body]);
    }
    private function table(string $name) { return DB::connection('test_groups')->table($name); }
    private function override(string $group, int $account, string $mode): void
    {
        $this->table('group_chat_member_overrides')->updateOrInsert(['group_id' => $group, 'account_id' => $account],
            ['project_id' => 1, 'mode' => $mode, 'set_by' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_roles_and_nonmembers_are_enforced_on_every_entry(): void
    {
        $group = $this->group();
        $this->join($group, 2); $this->join($group, 3, 'viewer');
        $message = $this->send($group, 1)->assertCreated()->json('data.id');
        $this->send($group, 2)->assertCreated();
        $this->send($group, 3)->assertForbidden();
        foreach (['', '/changes?since=0', "/$message"] as $suffix) {
            $this->withToken($this->tokens[3])->getJson($this->path($group, $suffix))->assertForbidden();
            $this->withToken($this->tokens[4])->getJson($this->path($group, $suffix))->assertNotFound();
        }
        $this->withToken($this->tokens[2])->deleteJson($this->path($group, "/$message"))->assertForbidden();
        $this->withToken($this->tokens[2])->patchJson($this->path($group, "/$message"), ['body' => '다른 내용'])->assertForbidden();
    }

    public function test_request_id_is_idempotent_and_different_body_conflicts(): void
    {
        $group = $this->group(); $request = (string) Str::uuid();
        $first = $this->send($group, 1, '원본', $request)->assertCreated()->json('data');
        $this->send($group, 1, '원본', $request)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->send($group, 1, '다른 내용', $request)->assertConflict();
        $this->assertSame(1, $this->table('group_messages')->count());
        Event::assertDispatchedTimes(GroupMessageChanged::class, 1);
    }

    public function test_edits_and_deletes_keep_private_revisions_and_emit_only_on_changes(): void
    {
        $group = $this->group(); $this->join($group, 2);
        $id = $this->send($group, 2, '수정 전 비공개 본문')->assertCreated()->json('data.id');
        $this->withToken($this->tokens[2])->patchJson($this->path($group, "/$id"), ['body' => '수정된 본문'])
            ->assertOk()->assertJsonPath('data.body', '수정된 본문')->assertJsonPath('data.can_edit', true)
            ->assertJsonMissing(['body' => '수정 전 비공개 본문']);
        $this->assertSame('수정 전 비공개 본문', $this->table('group_message_revisions')->value('body'));
        $this->withToken($this->tokens[2])->patchJson($this->path($group, "/$id"), ['body' => '수정된 본문'])->assertOk();
        $this->assertSame(1, $this->table('group_message_revisions')->count());
        $this->withToken($this->tokens[1])->deleteJson($this->path($group, "/$id"))->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.body', null)->assertJsonPath('data.can_delete', false);
        $this->assertNull($this->table('group_messages')->where('id', $id)->value('body'));
        $this->assertSame('수정된 본문', $this->table('group_message_revisions')->orderByDesc('id')->value('body'));
        $this->withToken($this->tokens[1])->deleteJson($this->path($group, "/$id"))->assertOk();
        $this->assertSame(2, $this->table('group_message_revisions')->count());
        $this->withToken($this->tokens[2])->patchJson($this->path($group, "/$id"), ['body' => '복구'])->assertConflict();
        Event::assertDispatchedTimes(GroupMessageChanged::class, 3);
        $this->withToken($this->tokens[1])->getJson($this->path($group))->assertOk()->assertJsonPath('data.messages.0.body', null);
    }

    public function test_blocked_members_cannot_send_edit_or_delete_and_override_has_priority(): void
    {
        $group = $this->group(); $this->join($group, 2);
        $id = $this->send($group, 2)->assertCreated()->json('data.id');
        $this->table('group_chat_role_policies')->insert(['project_id' => 1, 'group_id' => $group,
            'role' => 'caregiver', 'can_send' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->send($group, 2)->assertForbidden();
        $this->withToken($this->tokens[2])->patchJson($this->path($group, "/$id"), ['body' => '수정'])->assertForbidden();
        $this->withToken($this->tokens[2])->deleteJson($this->path($group, "/$id"))->assertForbidden();
        $this->withToken($this->tokens[2])->getJson($this->path($group))->assertOk()
            ->assertJsonPath('data.can_send', false)->assertJsonPath('data.messages.0.can_edit', false)
            ->assertJsonPath('data.messages.0.can_delete', false);
        $this->override($group, 2, 'allow'); $this->send($group, 2)->assertCreated();
        $this->override($group, 2, 'deny'); $this->send($group, 2)->assertForbidden();
        $this->override($group, 1, 'deny'); $this->send($group, 1)->assertCreated();
        $this->join($group, 3, 'viewer'); $this->override($group, 3, 'allow');
        $this->send($group, 3)->assertForbidden();
    }

    public function test_changes_replay_offline_edits_and_deletes_and_pagination(): void
    {
        $group = $this->group();
        $a = $this->send($group, 1, '첫째')->assertCreated()->json('data.id');
        $b = $this->send($group, 1, '둘째')->assertCreated()->json('data.id');
        $this->withToken($this->tokens[1])->getJson($this->path($group, '?limit=1'))->assertOk()
            ->assertJsonPath('data.messages.0.id', $b)->assertJsonPath('data.has_more', true)->assertJsonPath('data.change_cursor', 2);
        $this->withToken($this->tokens[1])->getJson($this->path($group, "?before=$b&limit=1"))->assertOk()
            ->assertJsonPath('data.messages.0.id', $a)->assertJsonPath('data.has_more', false);
        $this->withToken($this->tokens[1])->patchJson($this->path($group, "/$a"), ['body' => '바뀐 첫째'])->assertOk();
        $this->withToken($this->tokens[1])->deleteJson($this->path($group, "/$b"))->assertOk();
        $this->withToken($this->tokens[1])->getJson($this->path($group, '/changes?since=2&limit=1'))->assertOk()
            ->assertJsonPath('data.messages.0.body', '바뀐 첫째')->assertJsonPath('data.next_cursor', 3)->assertJsonPath('data.has_more', true);
        $this->withToken($this->tokens[1])->getJson($this->path($group, '/changes?since=3'))->assertOk()
            ->assertJsonPath('data.messages.0.deleted', true)->assertJsonPath('data.next_cursor', 4);
        $this->withToken($this->tokens[1])->getJson($this->path($group, '/changes?since=4'))->assertOk()->assertJsonCount(0, 'data.messages');
    }

    public function test_latest_approval_cutoff_is_applied_to_all_reads_and_mutations(): void
    {
        $this->travelTo(now()->startOfSecond());
        $group = $this->group(); $before = $this->send($group, 1)->assertCreated()->json('data.id');
        $this->travel(1)->seconds(); $this->join($group, 2);
        $own = $this->send($group, 2)->assertCreated()->json('data.id');
        $this->withToken($this->tokens[2])->getJson($this->path($group, "/$before"))->assertNotFound();
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$group/leave")->assertOk();
        $this->travel(1)->seconds(); $during = $this->send($group, 1)->assertCreated()->json('data.id');
        $this->travel(1)->seconds(); $this->join($group, 2);
        foreach ([$before, $own, $during] as $hidden) {
            $this->withToken($this->tokens[2])->getJson($this->path($group, "/$hidden"))->assertNotFound();
            $this->withToken($this->tokens[2])->deleteJson($this->path($group, "/$hidden"))->assertNotFound();
        }
        $this->withToken($this->tokens[2])->getJson($this->path($group))->assertOk()->assertJsonCount(0, 'data.messages');
        $this->withToken($this->tokens[2])->getJson($this->path($group, '/changes?since=0'))->assertOk()->assertJsonCount(0, 'data.messages');
        $this->send($group, 2)->assertCreated();
    }

    public function test_departed_authors_remain_anonymous_and_events_have_no_body(): void
    {
        $group = $this->group(); $this->join($group, 2);
        $id = $this->send($group, 2)->assertCreated()->json('data.id');
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$group/leave")->assertOk();
        $this->withToken($this->tokens[1])->getJson($this->path($group, "/$id"))->assertOk()->assertJsonPath('data.author', null);
        $event = new GroupMessageChanged('streetcat', $group, $id, 'created');
        $this->assertSame('database', $event->connection); $this->assertSame('chat-realtime', $event->queue);
        $this->assertSame("private-project.streetcat.group.$group", $event->broadcastOn()[0]->name);
        $this->assertSame(['group_id' => $group, 'message_id' => $id, 'change' => 'created'], $event->broadcastWith());
    }

    public function test_invalid_payloads_and_query_keys_are_rejected(): void
    {
        $group = $this->group();
        foreach ([['body' => '본문'], ['body' => '   ', 'request_id' => (string) Str::uuid()],
            ['body' => str_repeat('a', 1001), 'request_id' => (string) Str::uuid()],
            ['body' => '본문', 'request_id' => (string) Str::uuid(), 'author_id' => 2]] as $data) {
            $this->withToken($this->tokens[1])->postJson($this->path($group), $data)->assertUnprocessable();
        }
        foreach (['?limit=51', '?after=1', '/changes?since=-1', '/changes?since=0&limit=101'] as $suffix) {
            $this->withToken($this->tokens[1])->getJson($this->path($group, $suffix))->assertUnprocessable();
        }
    }

    public function test_legacy_membership_cutoff_and_iso_values_use_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Seoul']);
        $group = $this->group();
        $legacy = $this->table('group_memberships')->where('group_id', $group)->value('created_at');
        $this->table('group_memberships')->where('group_id', $group)->update(['chat_visible_from' => $legacy]);
        $sent = $this->send($group, 1)->assertCreated()->json('data');
        $this->assertTrue(\Carbon\CarbonImmutable::parse($sent['created_at'])->isSameSecond(now()));
        $this->withToken($this->tokens[1])->getJson($this->path($group))->assertOk()->assertJsonCount(1, 'data.messages');
        $this->withToken($this->tokens[1])->getJson($this->path($group, '/changes?since=0'))->assertOk()->assertJsonCount(1, 'data.messages');
    }

    public function test_approval_cutoff_does_not_leak_earlier_messages_in_the_same_second(): void
    {
        $this->travelTo(now()->startOfSecond()->addMicroseconds(100));
        $group = $this->group();
        $before = $this->send($group, 1)->assertCreated()->json('data.id');
        $this->travelTo(now()->addMicroseconds(100));
        $this->join($group, 2);
        $this->travelTo(now()->addMicroseconds(100));
        $after = $this->send($group, 1)->assertCreated()->json('data.id');
        $this->withToken($this->tokens[2])->getJson($this->path($group, "/$before"))->assertNotFound();
        $this->withToken($this->tokens[2])->getJson($this->path($group))->assertOk()
            ->assertJsonCount(1, 'data.messages')->assertJsonPath('data.messages.0.id', $after);
    }

}
