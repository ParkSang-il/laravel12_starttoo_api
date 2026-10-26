<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupPlaceTest extends TestCase
{
    private array $tokens = [];
    private string $base = '/api/v1/projects/streetcat';
    private string $group;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('places-test-secret-', 6),
            'platform.projects' => ['streetcat' => ['name' => '스트릿캣', 'connection' => 'test_places']],
            'database.connections.test_places' => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('project:migrate', ['code' => 'streetcat', '--force' => true]));
        app(ProjectDatabase::class)->run('streetcat', function () {
            $project = Project::create(['code' => 'streetcat', 'name' => '스트릿캣']);
            for ($id = 1; $id <= 4; $id++) {
                $account = new ProjectAccount;
                $account->forceFill(['project_id' => $project->id, 'status' => 'active', 'phone_verified_at' => now(),
                    'phone' => '010'.str_pad((string) $id, 8, '0', STR_PAD_LEFT), 'display_name' => '회원 '.$id])->save();
                $this->tokens[$id] = Auth::guard('api')->login($account);
                Auth::forgetGuards();
            }
        });
        $this->group = $this->withToken($this->tokens[1])->postJson($this->base.'/groups', [
            'name' => '장소 테스트', 'region_code' => '0000000000', 'region_label' => '테스트 동네',
            'is_discoverable' => true, 'accepting_requests' => true,
        ])->assertCreated()->json('data.id');
        $this->join(2, 'caregiver');
        $this->join(3, 'viewer');
    }

    private function join(int $account, string $role): void
    {
        $request = $this->withToken($this->tokens[$account])->postJson($this->base."/groups/{$this->group}/join-requests",
            ['message' => '테스트 가입 신청'])->assertOk()->json('data');
        $this->withToken($this->tokens[1])->postJson($this->base."/groups/{$this->group}/join-requests/{$request['id']}/review",
            ['version' => $request['version'], 'decision' => 'approve', 'role' => $role])->assertOk();
    }

    private function path(string $suffix = ''): string
    {
        return $this->base."/groups/{$this->group}/places".$suffix;
    }

    private function createPlace(string $name = '공개되면 안 되는 장소'): array
    {
        return $this->withToken($this->tokens[1])->postJson($this->path(), [
            'name' => $name, 'note' => '비공개 장소 주의사항', 'latitude' => 37.1234567,
            'longitude' => 127.1234567, 'interval_hours' => 24,
        ])->assertCreated()->json('data');
    }

    private function grant(array $place, int $account, string $access, ?int $version = null)
    {
        $version ??= $this->withToken($this->tokens[1])->getJson($this->path('/'.$place['id']))
            ->assertOk()->json('data.version');

        return $this->withToken($this->tokens[1])->patchJson($this->path("/{$place['id']}/permissions/$account"),
            ['version' => $version, 'access' => $access]);
    }

    public function test_group_approval_does_not_grant_places_or_leak_coordinates(): void
    {
        $place = $this->createPlace();
        $this->withToken($this->tokens[2])->getJson($this->path())->assertOk()
            ->assertJsonCount(0, 'data.places')->assertJsonPath('data.can_manage', false);
        $this->withToken($this->tokens[2])->getJson($this->path('/'.$place['id']))->assertNotFound();
        $this->withToken($this->tokens[2])->getJson($this->base."/groups/{$this->group}")
            ->assertOk()->assertJsonPath('data.my_membership.can_view_locations', false);
    }

    public function test_only_explicitly_granted_places_are_returned(): void
    {
        $allowed = $this->createPlace('허용 장소');
        $hidden = $this->createPlace('다른 사람 장소');
        $this->grant($allowed, 2, 'care')->assertOk();
        $response = $this->withToken($this->tokens[2])->getJson($this->path())->assertOk()
            ->assertJsonCount(1, 'data.places')->assertJsonPath('data.places.0.id', $allowed['id'])
            ->assertJsonPath('data.places.0.access', 'care')->assertJsonPath('data.places.0.can_manage', false);
        $this->assertStringNotContainsString($hidden['id'], $response->getContent());
        $this->withToken($this->tokens[2])->getJson($this->path('/'.$hidden['id']))->assertNotFound();
        $this->withToken($this->tokens[2])->getJson($this->base."/groups/{$this->group}")
            ->assertJsonPath('data.my_membership.can_view_locations', true);
    }

    public function test_outsiders_and_pending_applicants_cannot_read_places(): void
    {
        $place = $this->createPlace();
        $this->withToken($this->tokens[4])->getJson($this->path())->assertForbidden();
        $this->withToken($this->tokens[4])->postJson($this->base."/groups/{$this->group}/join-requests", ['message' => '신청'])->assertOk();
        $this->withToken($this->tokens[4])->getJson($this->path('/'.$place['id']))->assertForbidden();
        $this->grant($place, 4, 'view')->assertNotFound();
    }

    public function test_non_owner_cannot_register_edit_archive_or_manage_permissions(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 2, 'care')->assertOk();
        $this->withToken($this->tokens[2])->postJson($this->path(), ['name' => '무단 등록'])->assertForbidden();
        $this->withToken($this->tokens[2])->patchJson($this->path('/'.$place['id']), ['name' => '무단 수정', 'version' => 2])->assertForbidden();
        $this->withToken($this->tokens[2])->deleteJson($this->path('/'.$place['id']), ['version' => 2])->assertForbidden();
        $this->withToken($this->tokens[2])->getJson($this->path("/{$place['id']}/permissions"))->assertForbidden();
        $this->withToken($this->tokens[2])->patchJson($this->path("/{$place['id']}/permissions/3"),
            ['version' => 2, 'access' => 'view'])->assertForbidden();
    }

    public function test_viewer_cannot_receive_care_and_owner_cannot_be_downgraded(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 3, 'care')->assertUnprocessable();
        $this->grant($place, 1, 'none')->assertConflict();
        $this->grant($place, 3, 'view')->assertOk();
        $this->withToken($this->tokens[3])->getJson($this->path('/'.$place['id']))
            ->assertOk()->assertJsonPath('data.access', 'view');
    }

    public function test_stale_place_or_permission_versions_never_overwrite_new_data(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 2, 'view', 1)->assertOk();
        $this->grant($place, 2, 'none', 1)->assertConflict();
        $this->withToken($this->tokens[1])->patchJson($this->path('/'.$place['id']), ['name' => '오래된 수정', 'version' => 1])->assertConflict();
        $this->withToken($this->tokens[1])->getJson($this->path('/'.$place['id']))
            ->assertJsonPath('data.name', $place['name'])->assertJsonPath('data.version', 2);
    }

    public function test_revocation_blocks_subsequent_list_and_detail_requests(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 2, 'view')->assertOk();
        $this->withToken($this->tokens[2])->getJson($this->path('/'.$place['id']))->assertOk();
        $this->grant($place, 2, 'none')->assertOk();
        $this->withToken($this->tokens[2])->getJson($this->path('/'.$place['id']))->assertNotFound();
        $this->withToken($this->tokens[2])->getJson($this->path())->assertJsonCount(0, 'data.places');
    }

    public function test_leaving_and_rejoining_does_not_restore_old_place_grants(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 2, 'care')->assertOk();
        $this->withToken($this->tokens[2])->postJson($this->base."/groups/{$this->group}/leave")->assertNoContent();
        $this->assertSame(0, DB::connection('test_places')->table('group_place_permissions')->count());
        $this->withToken($this->tokens[2])->getJson($this->path())->assertForbidden();
        $this->join(2, 'caregiver');
        $this->withToken($this->tokens[2])->getJson($this->path())->assertJsonCount(0, 'data.places');
    }

    public function test_member_removal_clears_all_place_access_and_invalidates_editor_version(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 2, 'view')->assertOk();
        $this->withToken($this->tokens[1])->deleteJson($this->base."/groups/{$this->group}/members/2")->assertNoContent();
        $this->assertSame(0, DB::connection('test_places')->table('group_place_permissions')->count());
        $this->withToken($this->tokens[2])->getJson($this->path())->assertForbidden();
        $this->grant($place, 3, 'view', 2)->assertConflict();
    }

    public function test_archive_preserves_data_but_hides_place_and_removes_grants(): void
    {
        $place = $this->createPlace();
        $this->grant($place, 2, 'care')->assertOk();
        $this->withToken($this->tokens[1])->deleteJson($this->path('/'.$place['id']), ['version' => 2])->assertNoContent();
        $row = DB::connection('test_places')->table('group_places')->first();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame('비공개 장소 주의사항', $row->note);
        $this->assertSame(0, DB::connection('test_places')->table('group_place_permissions')->count());
        $this->withToken($this->tokens[1])->getJson($this->path('/'.$place['id']))->assertNotFound();
        $this->withToken($this->tokens[1])->getJson($this->path())->assertJsonCount(0, 'data.places');
    }

    public function test_coordinate_pairs_ranges_and_server_owned_fields_are_validated(): void
    {
        foreach ([['latitude' => 37], ['latitude' => null, 'longitude' => 127],
            ['latitude' => 91, 'longitude' => 127], ['account_id' => 2]] as $invalid) {
            $this->withToken($this->tokens[1])->postJson($this->path(), ['name' => '장소', ...$invalid])->assertUnprocessable();
        }
        $place = $this->createPlace();
        $this->withToken($this->tokens[1])->patchJson($this->path('/'.$place['id']),
            ['version' => 1, 'latitude' => null, 'longitude' => null])->assertOk()
            ->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);
    }

    public function test_group_public_summary_never_exposes_private_place_data(): void
    {
        $place = $this->createPlace();
        $summary = $this->withToken($this->tokens[4])->getJson($this->base."/groups/{$this->group}")->assertOk()->getContent();
        $this->assertStringNotContainsString($place['name'], $summary);
        $this->assertStringNotContainsString('latitude', $summary);
        $this->assertStringNotContainsString('비공개 장소 주의사항', $summary);
    }

    public function test_place_id_from_another_group_cannot_be_read_using_owned_group(): void
    {
        $other = $this->withToken($this->tokens[4])->postJson($this->base.'/groups', [
            'name' => '다른 그룹', 'region_code' => '0000000000', 'region_label' => '테스트 동네',
        ])->assertCreated()->json('data.id');
        $place = $this->withToken($this->tokens[4])->postJson($this->base."/groups/$other/places", ['name' => '다른 비공개 장소'])
            ->assertCreated()->json('data');
        $this->withToken($this->tokens[1])->getJson($this->path('/'.$place['id']))->assertNotFound();
        $this->grant($place, 2, 'view', 1)->assertNotFound();
    }

    public function test_permission_list_hides_phone_and_audits_do_not_copy_private_values(): void
    {
        $place = $this->createPlace();
        $list = $this->withToken($this->tokens[1])->getJson($this->path("/{$place['id']}/permissions"))
            ->assertOk()->assertJsonCount(3, 'data.members');
        $this->assertStringNotContainsString('phone', $list->getContent());
        $this->grant($place, 2, 'view')->assertOk();
        $audit = DB::connection('test_places')->table('group_audit_events')->where('event', 'like', 'place_%')->pluck('metadata')->implode(' ');
        $this->assertStringNotContainsString('37.1234567', $audit);
        $this->assertStringNotContainsString('비공개 장소 주의사항', $audit);
    }
}
