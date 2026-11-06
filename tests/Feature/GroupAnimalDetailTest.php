<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GroupAnimalDetailTest extends TestCase
{
    private array $tokens = [];
    private string $base = '/api/v1/projects/streetcat';
    private string $group;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('animal-detail-test-', 6),
            'platform.projects' => ['streetcat' => ['name' => '스트릿캣', 'connection' => 'test_animal_detail']],
            'database.connections.test_animal_detail' => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('project:migrate', ['code' => 'streetcat', '--force' => true]));
        app(ProjectDatabase::class)->run('streetcat', function () {
            $project = Project::create(['code' => 'streetcat', 'name' => '스트릿캣']);
            for ($id = 1; $id <= 3; $id++) {
                $account = new ProjectAccount;
                $account->forceFill(['project_id' => $project->id, 'status' => 'active', 'phone_verified_at' => now(),
                    'phone' => '010'.str_pad((string) $id, 8, '0', STR_PAD_LEFT), 'display_name' => '회원 '.$id])->save();
                $guard = Auth::guard('api');
                $this->tokens[$id] = $guard->login($account);
                app(ProjectDatabase::class)->database()->table('project_auth_sessions')->insert([
                    'id' => hash('sha256', $guard->payload()->get('jti')), 'account_id' => $account->id,
                    'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                Auth::forgetGuards();
            }
        });
        $this->group = $this->withToken($this->tokens[1])->postJson($this->base.'/groups', [
            'name' => '대상 그룹', 'region_code' => '0000000000', 'region_label' => '테스트 동네',
            'is_discoverable' => true, 'accepting_requests' => true,
        ])->assertCreated()->json('data.id');
        $this->db()->table('group_memberships')->insert([
            'project_id' => 1, 'group_id' => $this->group, 'account_id' => 2,
            'role' => 'viewer', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function db() { return DB::connection('test_animal_detail'); }
    private function path(string $suffix): string { return $this->base.'/groups/'.$this->group.$suffix; }
    private function place(string $name): array
    {
        return $this->withToken($this->tokens[1])->postJson($this->path('/places'),
            ['name' => $name, 'note' => '위치 메모', 'latitude' => 37.1, 'longitude' => 127.1, 'interval_hours' => 24])
            ->assertCreated()->json('data');
    }
    private function animal(array $place, string $name = '나비'): array
    {
        return $this->withToken($this->tokens[1])->postJson($this->path('/places/'.$place['id'].'/animals'), ['name' => $name])
            ->assertCreated()->json('data');
    }
    private function grant(array $place, string $access): void
    {
        $version = $this->withToken($this->tokens[1])->getJson($this->path('/places/'.$place['id']))
            ->assertOk()->json('data.version');
        $this->withToken($this->tokens[1])->patchJson($this->path('/places/'.$place['id'].'/permissions/2'),
            ['access' => $access, 'version' => $version])->assertOk();
    }
    private function link(array $animal, array $place): void
    {
        $this->db()->table('group_animal_places')->insert([
            'group_id' => $this->group, 'animal_id' => $animal['id'], 'place_id' => $place['id'], 'created_at' => now()]);
    }
    private function record(array $place, array $animal, string $status = 'seen', ?string $at = null, ?string $requestId = null): array
    {
        return $this->withToken($this->tokens[1])->postJson($this->path('/places/'.$place['id'].'/care-records'), [
            'request_id' => $requestId ?? (string) Str::uuid(), 'fed_at' => $at ?? now()->subMinute()->toISOString(),
            'tags' => [], 'cost' => 0, 'sightings' => [$animal['id'] => $status],
        ])->assertCreated()->json('data');
    }
    private function detail(array $animal, int $actor = 2, ?string $cursor = null)
    {
        $url = $this->path('/animals/'.$animal['id'].'/detail');
        return $this->withToken($this->tokens[$actor])->getJson($url.($cursor === null ? '' : '?cursor='.rawurlencode($cursor)));
    }

    public function test_only_selected_animal_and_currently_accessible_linked_places_are_returned(): void
    {
        $visible = $this->place('허용 장소');
        $hidden = $this->place('숨겨진 장소');
        $unlinked = $this->place('연결되지 않은 장소');
        $animal = $this->animal($visible);
        $other = $this->animal($visible, '다른 친구');
        $this->link($animal, $hidden);
        $this->grant($visible, 'view');
        $this->grant($unlinked, 'view');
        $shown = $this->record($visible, $animal);
        $this->record($visible, $other);
        $this->record($hidden, $animal);
        $response = $this->detail($animal)->assertOk()->assertJsonPath('data.animal.id', $animal['id'])
            ->assertJsonPath('data.animal.can_manage', false)->assertJsonCount(1, 'data.places')
            ->assertJsonPath('data.places.0.id', $visible['id'])->assertJsonPath('data.places.0.access', 'view')
            ->assertJsonPath('data.history_total', 1)->assertJsonPath('data.history.0.id', $shown['id']);
        $this->assertStringNotContainsString($hidden['id'], $response->getContent());
        $this->assertStringNotContainsString('숨겨진 장소', $response->getContent());
        $this->detail($animal, 1)->assertOk()->assertJsonCount(2, 'data.places')->assertJsonPath('data.history_total', 2);
        $this->detail($animal, 3)->assertForbidden();
        $this->grant($visible, 'none');
        $this->detail($animal)->assertNotFound();
    }

    public function test_cursor_pages_tied_dates_once_and_last_seen_does_not_depend_on_first_page(): void
    {
        $place = $this->place('장소');
        $animal = $this->animal($place);
        $this->grant($place, 'view');
        $seenAt = now()->subDays(30)->startOfSecond()->toISOString();
        $this->record($place, $animal, 'seen', $seenAt);
        $at = now()->subHour()->startOfSecond()->toISOString();
        for ($i = 0; $i < 24; $i++) $this->record($place, $animal, 'notSeen', $at);
        $first = $this->detail($animal)->assertOk()->assertJsonCount(20, 'data.history')
            ->assertJsonPath('data.history_total', 25)->assertJsonPath('data.last_seen_at', $seenAt)->json('data');
        $this->assertNotContains('seen', array_column($first['history'], 'status'));
        $second = $this->detail($animal, 2, $first['next_cursor'])->assertOk()->assertJsonCount(5, 'data.history')
            ->assertJsonPath('data.last_seen_at', $seenAt)->assertJsonPath('data.next_cursor', null)->json('data');
        $ids = array_column([...$first['history'], ...$second['history']], 'id');
        $this->assertCount(25, array_unique($ids));
        $this->assertCount(25, $ids);
    }

    public function test_permission_changes_invalidate_the_cursor_and_do_not_leak_previous_places(): void
    {
        $one = $this->place('장소 하나'); $two = $this->place('장소 둘');
        $animal = $this->animal($one); $this->link($animal, $two);
        $this->grant($one, 'view'); $this->grant($two, 'view');
        $at = now()->subHour()->startOfSecond()->toISOString();
        for ($i = 0; $i < 21; $i++) $this->record($i % 2 ? $one : $two, $animal, 'seen', $at);
        $cursor = $this->detail($animal)->assertOk()->json('data.next_cursor');
        $this->grant($two, 'none');
        $this->detail($animal, 2, $cursor)->assertConflict()->assertJsonMissingPath('data.history');
        $this->detail($animal)->assertOk()->assertJsonCount(1, 'data.places')
            ->assertJsonPath('data.history_total', 10);
        $this->detail($animal, 1, $cursor)->assertConflict();
        $this->detail($animal, 2, 'invalid')->assertUnprocessable();
        $this->db()->table('group_places')->where('id', $one['id'])->update(['deleted_at' => now()]);
        $this->detail($animal)->assertNotFound();
    }

    public function test_history_index_is_written_atomically_once_and_backfills_existing_records(): void
    {
        $place = $this->place('장소'); $animal = $this->animal($place);
        $at = now()->subMinute()->startOfSecond()->toISOString(); $request = (string) Str::uuid();
        $first = $this->record($place, $animal, 'seen', $at, $request);
        $second = $this->record($place, $animal, 'seen', $at, $request);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $this->db()->table('group_animal_sightings')->count());
        app(ProjectDatabase::class)->run('streetcat', function () {
            $previous = DB::getDefaultConnection(); DB::setDefaultConnection('test_animal_detail');
            try {
                $migration = require database_path('project-migrations/2026_11_06_000021_create_group_animal_sightings.php');
                $migration->down(); $migration->up();
            } finally { DB::setDefaultConnection($previous); }
        });
        $this->assertSame(1, $this->db()->table('group_animal_sightings')->count());
        $this->detail($animal, 1)->assertOk()->assertJsonPath('data.history_total', 1);
    }

    public function test_detail_reads_two_bounded_history_queries_without_fetching_full_care_payloads(): void
    {
        $place = $this->place('장소'); $animal = $this->animal($place);
        $this->record($place, $animal);
        $db = $this->db(); $db->enableQueryLog();
        try {
            $this->detail($animal, 1)->assertOk();
            $queries = collect($db->getQueryLog())->pluck('query');
            $this->assertCount(2, $queries->filter(fn ($sql) => str_contains($sql, 'group_animal_sightings')));
            $this->assertCount(0, $queries->filter(fn ($sql) => str_contains($sql, 'group_care_records')));
            $this->assertCount(0, $queries->filter(fn ($sql) => str_contains(strtolower($sql), 'offset')));
        } finally { $db->disableQueryLog(); $db->flushQueryLog(); }
    }
}
