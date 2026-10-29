<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('test-secret-', 8),
            'platform.sms_driver' => 'preview', 'hashing.bcrypt.rounds' => 4,
            // Only test configuration includes a second app. Production has streetcat only.
            'platform.projects' => [
                'streetcat' => ['name' => '스트릿캣', 'connection' => 'test_streetcat'],
                'second-app' => ['name' => '격리 테스트', 'connection' => 'test_second'],
            ],
        ]);
        foreach (['streetcat' => 'test_streetcat', 'second-app' => 'test_second'] as $code => $connection) {
            config(['database.connections.'.$connection => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ]]);
            $this->assertSame(0, Artisan::call('project:migrate', ['code' => $code, '--force' => true]));
            $this->within($code, fn () => Project::create(['code' => $code, 'name' => $code]));
        }
    }

    private function within(string $code, \Closure $callback): mixed
    {
        return app(ProjectDatabase::class)->run($code, $callback);
    }

    private function requestCode(string $project = 'streetcat'): array
    {
        return $this->postJson("/api/v1/projects/$project/auth/phone/request",
            ['phone' => '01012345678'])->assertCreated()->json('data');
    }

    private function verify(array $challenge, string $project = 'streetcat')
    {
        return $this->postJson("/api/v1/projects/$project/auth/phone/verify", [
            'challenge_id' => $challenge['challenge_id'], 'code' => $challenge['development_code'],
        ]);
    }

    private function tokenFor(string $project = 'streetcat'): string
    {
        return $this->verify($this->requestCode($project), $project)
            ->assertOk()->json('data.access_token');
    }

    public function test_same_phone_is_stored_in_two_separate_databases(): void
    {
        $this->tokenFor();
        $this->tokenFor('second-app');
        foreach (['streetcat', 'second-app'] as $code) {
            $this->within($code, function () use ($code) {
                $this->assertSame(1, ProjectAccount::count());
                $this->assertSame(1, (int) ProjectAccount::first()->id);
                $this->assertSame($code, ProjectAccount::first()->project_code);
                $this->assertSame([$code], Project::pluck('code')->all());
            });
        }
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('project_accounts'));
    }

    public function test_matching_numeric_ids_do_not_allow_cross_project_tokens(): void
    {
        $token = $this->tokenFor();
        $this->tokenFor('second-app');
        $this->withToken($token)->getJson('/api/v1/projects/second-app/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/projects/streetcat/me')
            ->assertOk()->assertJsonPath('data.project_code', 'streetcat');
    }

    public function test_challenge_cannot_cross_databases_or_be_reused(): void
    {
        $challenge = $this->requestCode();
        $this->verify($challenge, 'second-app')->assertUnprocessable();
        $this->verify($challenge)->assertOk();
        $this->verify($challenge)->assertUnprocessable();
    }

    public function test_failed_attempts_are_persisted_and_lock_the_challenge(): void
    {
        $challenge = $this->requestCode();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/projects/streetcat/auth/phone/verify', [
                'challenge_id' => $challenge['challenge_id'], 'code' => '000000',
            ])->assertUnprocessable();
        }
        $this->assertSame(5, (int) DB::connection('test_streetcat')
            ->table('project_phone_challenges')->value('attempts'));
        $this->verify($challenge)->assertUnprocessable();
    }

    public function test_expired_challenge_is_rejected(): void
    {
        $challenge = $this->requestCode();
        DB::connection('test_streetcat')->table('project_phone_challenges')
            ->update(['expires_at' => now()->subSecond()]);
        $this->verify($challenge)->assertUnprocessable();
    }

    public function test_profile_changes_only_affect_one_database(): void
    {
        $token = $this->tokenFor();
        $otherToken = $this->tokenFor('second-app');
        $this->withToken($token)->patchJson('/api/v1/projects/streetcat/me',
            ['display_name' => '돌봄 담당자'])->assertOk();
        $this->withToken($otherToken)->getJson('/api/v1/projects/second-app/me')
            ->assertOk()->assertJsonPath('data.display_name', null);
    }

    public function test_suspended_account_is_denied_on_existing_token(): void
    {
        $token = $this->tokenFor();
        $this->within('streetcat', fn () => ProjectAccount::query()->update(['status' => 'suspended']));
        $this->withToken($token)->getJson('/api/v1/projects/streetcat/me')->assertForbidden();
    }

    public function test_streetcat_session_stays_signed_in_with_activity_and_expires_after_90_idle_days(): void
    {
        $token = $this->tokenFor();
        $this->assertSame(1, DB::connection('test_streetcat')->table('project_auth_sessions')->count());
        $this->travel(89)->days();
        $this->withToken($token)->getJson('/api/v1/projects/streetcat/me')->assertOk();
        $this->travel(89)->days();
        $this->withToken($token)->getJson('/api/v1/projects/streetcat/me')->assertOk();
        $this->travel(90)->days();
        $this->withToken($token)->getJson('/api/v1/projects/streetcat/me')->assertUnauthorized();
    }

    public function test_streetcat_logout_revokes_persistent_session(): void
    {
        $token = $this->tokenFor();
        $this->withToken($token)->postJson('/api/v1/projects/streetcat/auth/logout')->assertNoContent();
        $this->assertSame(0, DB::connection('test_streetcat')->table('project_auth_sessions')->count());
        $this->withToken($token)->getJson('/api/v1/projects/streetcat/me')->assertUnauthorized();
    }

    public function test_database_and_project_parameters_cannot_override_route(): void
    {
        foreach (['project_id', 'project_code', 'database', 'connection'] as $field) {
            $this->postJson('/api/v1/projects/streetcat/auth/phone/request', [
                'phone' => '01012345678', $field => 'anything',
            ])->assertUnprocessable();
        }
    }

    public function test_inactive_and_unknown_projects_are_unavailable(): void
    {
        $this->within('streetcat', fn () => Project::query()->update(['is_active' => false]));
        $this->getJson('/api/v1/projects/streetcat/me')->assertNotFound();
        $this->getJson('/api/v1/projects/unknown/me')->assertNotFound();
    }

    public function test_production_never_exposes_preview_codes(): void
    {
        $this->app->instance('env', 'production');
        $this->postJson('/api/v1/projects/streetcat/auth/phone/request',
            ['phone' => '01012345678'])->assertStatus(503)->assertJsonMissingPath('data.development_code');
    }

    public function test_unauthenticated_access_and_legacy_endpoints_are_closed(): void
    {
        $this->getJson('/api/v1/projects/streetcat/me')->assertUnauthorized();
        $this->postJson('/api/auth/register', [])->assertNotFound();
        $this->getJson('/admin')->assertNotFound();
        $this->getJson('/api/portfolios')->assertNotFound();
    }

    public function test_context_is_cleared_after_an_exception(): void
    {
        try {
            $this->within('streetcat', fn () => throw new \RuntimeException('test'));
        } catch (\RuntimeException $e) {
            $this->assertSame('test', $e->getMessage());
        }
        $this->expectException(\LogicException::class);
        app(ProjectDatabase::class)->connectionName();
    }


    public function test_database_selection_runs_before_authentication(): void
    {
        $router = app('router');
        $route = $router->getRoutes()->match(
            \Illuminate\Http\Request::create('/api/v1/projects/streetcat/me', 'GET'));
        $stack = $router->gatherRouteMiddleware($route);
        $resolve = array_search(\App\Http\Middleware\ResolveProject::class, $stack, true);
        $auth = array_search(\Illuminate\Auth\Middleware\Authenticate::class.':api', $stack, true);
        $check = array_search(\App\Http\Middleware\EnsureProjectAccount::class, $stack, true);
        $this->assertNotFalse($resolve);
        $this->assertNotFalse($auth);
        $this->assertNotFalse($check);
        $this->assertLessThan($auth, $resolve);
        $this->assertLessThan($check, $auth);
    }

    public function test_shared_database_configuration_is_rejected(): void
    {
        config(['platform.projects.second-app.connection' => 'test_streetcat']);
        $this->expectException(\LogicException::class);
        app(ProjectDatabase::class)->configuredConnection('streetcat');
    }
}
