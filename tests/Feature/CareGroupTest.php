<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CareGroupTest extends TestCase
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
            for ($i = 1; $i <= 3; $i++) {
                $account = new ProjectAccount;
                $account->forceFill(['project_id' => $project->id, 'status' => 'active',
                    'phone' => '010'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'phone_verified_at' => now(), 'display_name' => '테스트 '.$i])->save();
                $this->tokens[$i] = Auth::guard('api')->login($account);
                Auth::forgetGuards();
            }
        });
    }

    private function group(bool $public = false, int $owner = 1): string
    {
        return $this->withToken($this->tokens[$owner])->postJson($this->base.'/groups', [
            'name' => '테스트 돌봄팀', 'region_code' => '0000000000', 'region_label' => '테스트 동네',
            'is_discoverable' => $public, 'accepting_requests' => $public,
        ])->assertCreated()->json('data.id');
    }

    public function test_nearby_search_enforces_radius_and_hides_coordinates(): void
    {
        $public = $this->group(true);
        $private = $this->group(false, 2);
        $closed = $this->group(true);
        $this->withToken($this->tokens[1])->patchJson($this->base."/groups/$closed", ['accepting_requests' => false])->assertOk();
        app(ProjectDatabase::class)->run('streetcat', function () use ($public, $private, $closed) {
            DB::connection('test_groups')->table('care_groups')->whereIn('id', [$public, $private, $closed])
                ->update(['discovery_latitude' => 37.56, 'discovery_longitude' => 126.92]);
        });
        $this->withToken($this->tokens[3])->postJson($this->base.'/groups/nearby', [
            'latitude' => 37.56, 'longitude' => 126.92, 'radius_m' => 1000,
        ])->assertOk()->assertJsonCount(2, 'data.groups')
            ->assertJsonMissingPath('data.groups.0.discovery_latitude')
            ->assertJsonMissingPath('data.groups.0.discovery_longitude')
            ->assertJsonFragment(['id' => $closed, 'accepting_requests' => false]);
        $this->withToken($this->tokens[3])->postJson($this->base.'/groups/nearby', [
            'latitude' => 37.56, 'longitude' => 126.92, 'radius_m' => 2001,
        ])->assertUnprocessable();
        $this->withToken($this->tokens[3])->postJson($this->base.'/groups/nearby', [
            'latitude' => 38, 'longitude' => 126.92, 'radius_m' => 2000,
        ])->assertOk()->assertJsonCount(0, 'data.groups');
    }

    private function invitation(string $group, array $data = []): array
    {
        return $this->withToken($this->tokens[1])->postJson($this->base."/groups/$group/invitations", $data)
            ->assertCreated()->json('data');
    }

    private function apply(string $group, int $actor = 2, array $extra = []): array
    {
        return $this->withToken($this->tokens[$actor])->postJson($this->base."/groups/$group/join-requests",
            ['message' => '함께 돌보고 싶습니다.', ...$extra])->assertOk()->json('data');
    }

    private function review(string $group, array $request, string $decision = 'approve')
    {
        $body = ['version' => $request['version'], 'decision' => $decision];
        if ($decision === 'approve') $body['role'] = 'caregiver';

        return $this->withToken($this->tokens[1])->postJson(
            $this->base."/groups/$group/join-requests/{$request['id']}/review", $body);
    }

    public function test_creation_defaults_to_private_and_creates_owner_atomically(): void
    {
        $id = $this->group();
        $this->withToken($this->tokens[1])->getJson($this->base."/groups/$id")
            ->assertOk()->assertJsonPath('data.is_discoverable', false)
            ->assertJsonPath('data.my_membership.role', 'owner')
            ->assertJsonPath('data.my_membership.can_view_locations', true);
        $this->assertSame(1, DB::connection('test_groups')->table('group_memberships')->count());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('care_groups'));
    }

    public function test_discovery_exposes_only_opted_in_recruiting_groups_in_same_region(): void
    {
        $private = $this->group();
        $public = $this->group(true);
        $closed = $this->group(true);
        $this->withToken($this->tokens[1])->patchJson($this->base."/groups/$closed", ['accepting_requests' => false])->assertOk();
        $response = $this->withToken($this->tokens[2])->getJson($this->base.'/groups/discover?region_code=0000000000')
            ->assertOk()->assertJsonCount(1, 'data.groups')->assertJsonPath('data.groups.0.id', $public);
        $this->assertStringNotContainsString($private, $response->getContent());
        $this->assertSame(['id', 'name', 'description', 'region_code', 'region_label', 'is_discoverable', 'accepting_requests', 'has_discovery_center'],
            array_keys($response->json('data.groups.0')));
        $this->withToken($this->tokens[2])->getJson($this->base.'/groups/discover?region_code=1111111111')
            ->assertOk()->assertJsonCount(0, 'data.groups');
    }

    public function test_outsider_cannot_open_or_apply_to_private_group_without_invitation(): void
    {
        $group = $this->group();
        $this->withToken($this->tokens[2])->getJson($this->base."/groups/$group")->assertNotFound();
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$group/join-requests", ['message' => '신청'])
            ->assertNotFound();
    }

    public function test_public_application_needs_owner_approval_and_grants_no_location_access(): void
    {
        $group = $this->group(true);
        $request = $this->apply($group);
        $this->withToken($this->tokens[2])->getJson($this->base.'/me/groups')->assertJsonCount(0, 'data');
        $this->withToken($this->tokens[2])->getJson($this->base."/groups/$group/join-requests")->assertForbidden();
        $this->review($group, $request)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->withToken($this->tokens[2])->getJson($this->base.'/me/groups')
            ->assertOk()->assertJsonPath('data.0.my_role', 'caregiver')
            ->assertJsonPath('data.0.can_view_locations', false);
    }

    public function test_invitation_is_hashed_single_use_and_duplicate_application_is_idempotent(): void
    {
        $group = $this->group();
        $invite = $this->invitation($group);
        $this->assertNotSame($invite['token'], DB::connection('test_groups')->table('group_invitations')->value('token_hash'));
        $this->withToken($this->tokens[2])->postJson($this->base.'/invitations/preview', ['token' => $invite['token']])
            ->assertOk()->assertJsonPath('data.requires_approval', true)->assertJsonPath('data.group.id', $group);
        $request = $this->apply($group, 2, ['invitation_token' => $invite['token']]);
        $again = $this->apply($group, 2, ['invitation_token' => $invite['token']]);
        $this->assertSame($request['id'], $again['id']);
        $this->assertSame(1, DB::connection('test_groups')->table('group_join_requests')->count());
        $this->assertSame(1, (int) DB::connection('test_groups')->table('group_invitations')->value('used_count'));
        $this->withToken($this->tokens[3])->postJson($this->base."/groups/$group/join-requests",
            ['message' => '다른 신청자', 'invitation_token' => $invite['token']])->assertUnprocessable();
    }

    public function test_revoked_expired_and_phone_bound_invitations_cannot_be_reused(): void
    {
        $group = $this->group();
        $invite = $this->invitation($group, ['phone' => '01000000002']);
        $this->withToken($this->tokens[3])->postJson($this->base.'/invitations/preview', ['token' => $invite['token']])
            ->assertUnprocessable();
        $this->withToken($this->tokens[2])->postJson($this->base.'/invitations/preview', ['token' => $invite['token']])->assertOk();
        $this->withToken($this->tokens[1])->deleteJson($this->base."/groups/$group/invitations/{$invite['id']}")->assertNoContent();
        $this->withToken($this->tokens[2])->postJson($this->base.'/invitations/preview', ['token' => $invite['token']])->assertUnprocessable();
        $other = $this->invitation($group);
        DB::connection('test_groups')->table('group_invitations')->where('id', $other['id'])->update(['expires_at' => now()->subSecond()]);
        $this->withToken($this->tokens[2])->postJson($this->base.'/invitations/preview', ['token' => $other['token']])->assertUnprocessable();
    }

    public function test_invitation_for_a_different_group_cannot_authorize_application(): void
    {
        $group = $this->group();
        $other = $this->group();
        $invite = $this->invitation($group);
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$other/join-requests",
            ['message' => '신청', 'invitation_token' => $invite['token']])->assertUnprocessable();
        $this->assertSame(0, (int) DB::connection('test_groups')->table('group_invitations')->value('used_count'));
    }

    public function test_applicant_cannot_approve_or_grant_owner_role(): void
    {
        $group = $this->group(true);
        $request = $this->apply($group);
        $url = $this->base."/groups/$group/join-requests/{$request['id']}/review";
        $body = ['version' => 1, 'decision' => 'approve', 'role' => 'caregiver'];
        $this->withToken($this->tokens[2])->postJson($url, $body)->assertForbidden();
        $this->withToken($this->tokens[1])->postJson($url, [...$body, 'role' => 'owner'])->assertUnprocessable();
        $this->assertSame(1, DB::connection('test_groups')->table('group_memberships')->count());
    }

    public function test_withdrawn_and_already_reviewed_requests_cannot_be_approved_again(): void
    {
        $group = $this->group(true);
        $request = $this->apply($group);
        $this->withToken($this->tokens[2])->postJson(
            $this->base."/groups/$group/join-requests/{$request['id']}/withdraw", ['version' => 1])->assertNoContent();
        $this->review($group, $request)->assertConflict();
        $newRequest = $this->apply($group);
        $this->assertSame($request['id'], $newRequest['id']);
        $this->assertSame(3, $newRequest['version']);
        $this->review($group, $newRequest)->assertOk();
        $this->review($group, $newRequest)->assertConflict();
    }

    public function test_rejection_returns_note_only_to_applicant_and_does_not_create_membership(): void
    {
        $group = $this->group(true);
        $request = $this->apply($group);
        $this->withToken($this->tokens[1])->postJson(
            $this->base."/groups/$group/join-requests/{$request['id']}/review",
            ['version' => 1, 'decision' => 'reject', 'note' => '운영자와 먼저 이야기해 주세요.'])->assertOk();
        $this->withToken($this->tokens[2])->getJson($this->base.'/me/group-requests')
            ->assertOk()->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.review_note', '운영자와 먼저 이야기해 주세요.');
        $this->withToken($this->tokens[3])->getJson($this->base.'/me/group-requests')->assertJsonCount(0, 'data');
        $this->assertSame(1, DB::connection('test_groups')->table('group_memberships')->count());
    }

    public function test_removal_revokes_membership_and_blocks_reapplication(): void
    {
        $group = $this->group(true);
        $this->review($group, $this->apply($group))->assertOk();
        $this->withToken($this->tokens[1])->deleteJson($this->base."/groups/$group/members/2")->assertNoContent();
        $this->withToken($this->tokens[2])->getJson($this->base.'/me/groups')->assertJsonCount(0, 'data');
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$group/join-requests", ['message' => '다시 신청'])
            ->assertForbidden();
    }

    public function test_owner_cannot_leave_and_caregiver_cannot_manage_group(): void
    {
        $group = $this->group(true);
        $this->review($group, $this->apply($group))->assertOk();
        $this->withToken($this->tokens[1])->postJson($this->base."/groups/$group/leave")->assertConflict();
        $this->withToken($this->tokens[2])->patchJson($this->base."/groups/$group", ['name' => '다른 이름'])->assertForbidden();
        $this->withToken($this->tokens[2])->getJson($this->base."/groups/$group/members")->assertForbidden();
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$group/invitations")->assertForbidden();
    }

    public function test_precise_location_and_untrusted_privilege_fields_are_rejected(): void
    {
        $this->withToken($this->tokens[1])->postJson($this->base.'/groups', [
            'name' => '테스트', 'region_code' => '0000000000', 'region_label' => '테스트 동네',
            'lat' => 37.5, 'lng' => 127.0,
        ])->assertUnprocessable();
        $group = $this->group(true);
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/$group/join-requests",
            ['message' => '신청', 'role' => 'owner', 'status' => 'active'])->assertUnprocessable();
    }

    public function test_request_from_another_group_cannot_be_reviewed_using_owner_group_id(): void
    {
        $group = $this->group(true);
        $other = $this->group(true, 3);
        $request = $this->apply($other);
        $this->review($group, $request)->assertNotFound();
    }

    public function test_join_group_writes_require_authentication(): void
    {
        $this->flushHeaders();
        $this->postJson($this->base.'/groups', [])->assertUnauthorized();
    }
}
