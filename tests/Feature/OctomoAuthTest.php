<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\ProjectDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OctomoAuthTest extends TestCase
{
    private string $base = '/api/v1/projects/streetcat/auth/phone';
    private string $endpoint = 'https://api.octoverse.kr/octomo/v1/public/message/exists';

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('octomo-tests-', 8), 'platform.sms_driver' => 'octomo',
            'services.octomo.api_key' => 'test-only-key', 'hashing.bcrypt.rounds' => 4,
            'platform.projects' => [
                'streetcat' => ['name' => '스트릿캣', 'connection' => 'test_mo'],
                'second-app' => ['name' => '격리', 'connection' => 'test_mo_second'],
            ]]);
        foreach (['streetcat' => 'test_mo', 'second-app' => 'test_mo_second'] as $code => $connection) {
            config(['database.connections.'.$connection => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
            $this->assertSame(0, Artisan::call('project:migrate', ['code' => $code, '--force' => true]));
            app(ProjectDatabase::class)->run($code, fn () => Project::create(['code' => $code, 'name' => $code]));
        }
        Http::preventStrayRequests();
    }

    private function issue(): array
    {
        return $this->postJson($this->base.'/request', ['phone' => '01012345678'])->assertCreated()->json('data');
    }
    private function verify(array $challenge)
    {
        return $this->postJson($this->base.'/verify', ['challenge_id' => $challenge['challenge_id']]);
    }

    public function test_issue_returns_composer_instructions_without_key_or_development_code(): void
    {
        $response = $this->postJson($this->base.'/request', ['phone' => '01012345678'])->assertCreated()
            ->assertJsonPath('data.method', 'sms_mo')->assertJsonPath('data.recipient', '16663538')
            ->assertJsonMissingPath('data.development_code');
        $this->assertStringNotContainsString('test-only-key', $response->getContent());
        $this->assertStringContainsString($response->json('data.challenge_id'), $response->json('data.message_text'));
        Http::assertNothingSent();
    }

    public function test_provider_receipt_is_required_and_uses_db_bound_phone_and_text_without_locks(): void
    {
        $challenge = $this->issue();
        $arrived = false;
        Http::fake([$this->endpoint => function ($request) use (&$arrived, $challenge) {
            $this->assertSame(0, DB::connection('test_mo')->transactionLevel());
            $this->assertSame('Octomo test-only-key', $request->header('Authorization')[0]);
            $this->assertSame('01012345678', $request['mobileNum']);
            $this->assertSame($challenge['message_text'], $request['text']);
            $this->assertSame(5, $request['withinMinutes']);
            return Http::response(['exists' => $arrived], 201);
        }]);
        $this->verify($challenge)->assertUnprocessable()->assertJsonMissingPath('data.access_token');
        $this->assertSame(0, DB::connection('test_mo')->table('project_accounts')->count());
        $this->verify($challenge)->assertStatus(429)->assertHeader('Retry-After', '5');
        Http::assertSentCount(1);
        $this->travel(6)->seconds();
        $arrived = true;
        $this->verify($challenge)->assertOk()->assertJsonPath('data.account.project_code', 'streetcat')
            ->assertJsonMissingPath('data.account.phone');
        $this->assertSame('01012345678', DB::connection('test_mo')->table('project_accounts')->value('phone'));
        $this->assertNotNull(DB::connection('test_mo')->table('project_phone_challenges')->value('consumed_at'));
        $this->verify($challenge)->assertUnprocessable();
        Http::assertSentCount(2);
    }

    public function test_wrong_scope_expired_and_client_supplied_phone_do_not_call_octomo(): void
    {
        $challenge = $this->issue();
        $this->postJson('/api/v1/projects/second-app/auth/phone/verify', ['challenge_id' => $challenge['challenge_id']])->assertUnprocessable();
        $this->postJson($this->base.'/verify', ['challenge_id' => $challenge['challenge_id'], 'phone' => '01099999999', 'message_text' => 'forged'])->assertUnprocessable();
        DB::connection('test_mo')->table('project_phone_challenges')->update(['expires_at' => now()->subSecond()]);
        $this->verify($challenge)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_new_request_invalidates_old_proof_and_uses_different_text(): void
    {
        $first = $this->issue();
        $this->travel(61)->seconds();
        $next = $this->issue();
        $this->assertNotSame($first['message_text'], $next['message_text']);
        $this->verify($first)->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_provider_error_and_malformed_success_never_authenticate_or_expose_payload(): void
    {
        Http::fake([$this->endpoint => Http::sequence()->push(['exists' => 'true'], 201)
            ->push(['message' => 'sensitive provider payload'], 401)->push([], 429)]);
        for ($i = 0; $i < 3; $i++) {
            $challenge = $this->issue();
            $response = $this->verify($challenge)->assertStatus(503)->assertJsonMissingPath('data.access_token');
            $this->assertStringNotContainsString('sensitive provider payload', $response->getContent());
            $this->assertStringNotContainsString('test-only-key', $response->getContent());
            $this->travel(61)->seconds();
        }
        $this->assertSame(0, DB::connection('test_mo')->table('project_accounts')->count());
    }

    public function test_network_failure_is_closed_without_exposing_request_details(): void
    {
        $challenge = $this->issue();
        Http::fake([$this->endpoint => fn () => throw new ConnectionException('sensitive request details')]);
        $response = $this->verify($challenge)->assertStatus(503);
        $this->assertStringNotContainsString('sensitive request details', $response->getContent());
        $this->assertNull(DB::connection('test_mo')->table('project_phone_challenges')->value('consumed_at'));
    }

    public function test_challenge_revoked_during_provider_request_cannot_create_account(): void
    {
        $challenge = $this->issue();
        Http::fake([$this->endpoint => function () {
            DB::connection('test_mo')->table('project_phone_challenges')->update(['consumed_at' => now()]);
            return Http::response(['exists' => true], 201);
        }]);
        $this->verify($challenge)->assertUnprocessable();
        $this->assertSame(0, DB::connection('test_mo')->table('project_accounts')->count());
    }

    public function test_missing_key_and_production_preview_are_not_usable(): void
    {
        config(['services.octomo.api_key' => '']);
        $this->postJson($this->base.'/request', ['phone' => '01012345678'])->assertStatus(503);
        config(['platform.sms_driver' => 'preview']);
        $this->app->instance('env', 'production');
        $this->postJson($this->base.'/request', ['phone' => '01012345678'])->assertStatus(503);
        Http::assertNothingSent();
    }
}
