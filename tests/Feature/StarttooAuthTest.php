<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StarttooAuthTest extends TestCase
{
    private string $base = '/api/v1/projects/starttoo/auth';

    private $privateKey;

    private string $publicKey;

    private bool $googleAvailable = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'starttoo.google_client_id' => 'starttoo-test-client',
            'platform.projects' => ['starttoo' => ['connection' => 'starttoo_test'], 'other' => ['connection' => 'other_test']],
            'database.connections.starttoo_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'database.connections.other_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'starttoo_test', '--path' => 'database/starttoo-migrations', '--force' => true]));
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->publicKey = openssl_pkey_get_details($this->privateKey)['key'];
        Http::preventStrayRequests();
        Http::fake(['https://www.googleapis.com/oauth2/v1/certs' => fn () => $this->googleAvailable ? Http::response(['test-key' => $this->publicKey]) : Http::response([], 503)]);
    }

    private function proof(array $overrides = []): string
    {
        $encode = fn ($value) => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
        $payload = $encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-key']).'.'.$encode([
            'iss' => 'https://accounts.google.com', 'aud' => 'starttoo-test-client', 'sub' => 'google-subject-1',
            'iat' => time(), 'exp' => time() + 3600, ...$overrides,
        ]);
        openssl_sign($payload, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return $payload.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }

    private function login(): array
    {
        return $this->postJson($this->base.'/google', ['id_token' => $this->proof()])->assertOk()
            ->assertJsonPath('data.account.next_action', 'terms')->json('data');
    }

    public function test_verified_identity_is_reused_and_tokens_are_stored_only_as_hashes(): void
    {
        $first = $this->login();
        $second = $this->login();
        $this->assertSame($first['account']['id'], $second['account']['id']);
        $this->assertSame(1, DB::connection('starttoo_test')->table('starttoo_users')->count());
        $session = DB::connection('starttoo_test')->table('starttoo_auth_sessions')->first();
        $this->assertSame(hash('sha256', $first['access_token']), $session->access_hash);
        $this->assertSame(hash('sha256', $first['refresh_token']), $session->refresh_hash);
        $this->assertFalse(DB::connection('other_test')->getSchemaBuilder()->hasTable('starttoo_users'));
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.next_action', 'terms');
    }

    public function test_wrong_audience_issuer_expired_and_tampered_proofs_are_rejected(): void
    {
        foreach ([['aud' => 'other-app'], ['iss' => 'https://attacker.invalid'], ['exp' => time() - 1]] as $overrides) {
            $this->postJson($this->base.'/google', ['id_token' => $this->proof($overrides)])->assertUnauthorized();
        }
        $parts = explode('.', $this->proof());
        $parts[2] = str_repeat('A', strlen($parts[2]));
        $this->postJson($this->base.'/google', ['id_token' => implode('.', $parts)])->assertUnauthorized();
        $this->assertSame(0, DB::connection('starttoo_test')->table('starttoo_users')->count());
    }

    public function test_provider_outage_does_not_create_a_session(): void
    {
        $this->googleAvailable = false;
        $this->postJson($this->base.'/google', ['id_token' => $this->proof()])->assertStatus(503)->assertJsonPath('code', 'GOOGLE_UNAVAILABLE');
        $this->assertSame(0, DB::connection('starttoo_test')->table('starttoo_auth_sessions')->count());
    }

    public function test_refresh_retries_same_key_and_reuse_with_another_key_revokes_only_one_device(): void
    {
        $first = $this->login();
        $other = $this->login();
        $request = ['refresh_token' => $first['refresh_token'], 'request_key' => (string) Str::uuid()];
        $rotated = $this->postJson($this->base.'/refresh', $request)->assertOk()->json('data');
        $this->postJson($this->base.'/refresh', $request)->assertOk()->assertJsonPath('data.refresh_token', $rotated['refresh_token']);
        $this->postJson($this->base.'/refresh', [...$request, 'request_key' => (string) Str::uuid()])->assertUnauthorized();
        $this->withToken($rotated['access_token'])->getJson($this->base.'/me')->assertUnauthorized();
        $this->withToken($other['access_token'])->getJson($this->base.'/me')->assertOk();
    }

    public function test_old_token_reuse_after_multiple_rotations_revokes_the_session(): void
    {
        $first = $this->login();
        $second = $this->postJson($this->base.'/refresh', ['refresh_token' => $first['refresh_token'], 'request_key' => (string) Str::uuid()])->assertOk()->json('data');
        $third = $this->postJson($this->base.'/refresh', ['refresh_token' => $second['refresh_token'], 'request_key' => (string) Str::uuid()])->assertOk()->json('data');
        $this->postJson($this->base.'/refresh', ['refresh_token' => $first['refresh_token'], 'request_key' => (string) Str::uuid()])->assertUnauthorized();
        $this->withToken($third['access_token'])->getJson($this->base.'/me')->assertUnauthorized();
    }

    public function test_access_expires_at_fifteen_minutes_and_background_refresh_does_not_extend_idle_limit(): void
    {
        $first = $this->login();
        $this->travel(15)->minutes();
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertUnauthorized()->assertJsonPath('code', 'ACCESS_EXPIRED');
        $this->travelBack();
        $this->travel(30)->days();
        $this->postJson($this->base.'/refresh', ['refresh_token' => $first['refresh_token'], 'request_key' => (string) Str::uuid()])->assertUnauthorized();
    }

    public function test_manual_activity_extends_idle_limit_but_never_absolute_limit(): void
    {
        $first = $this->login();
        $db = DB::connection('starttoo_test');
        $db->table('starttoo_auth_sessions')->update(['absolute_expires_at' => now()->addSeconds(10), 'idle_expires_at' => now()->addSeconds(5)]);
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertOk();
        $session = $db->table('starttoo_auth_sessions')->first();
        $this->assertSame($session->absolute_expires_at, $session->idle_expires_at);
        $this->travel(10)->seconds();
        $this->postJson($this->base.'/refresh', ['refresh_token' => $first['refresh_token'], 'request_key' => (string) Str::uuid()])->assertUnauthorized();
    }

    public function test_suspension_terms_and_profile_state_control_entry_and_logout_is_immediate(): void
    {
        $first = $this->login();
        $users = DB::connection('starttoo_test')->table('starttoo_users');
        $users->update(['terms_version' => config('starttoo.terms_version')]);
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.next_action', 'profile');
        $users->update(['profile_completed_at' => now()]);
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.next_action', 'home');
        $users->update(['status' => 'suspended']);
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_SUSPENDED');
        $users->update(['status' => 'active']);
        $this->withToken($first['access_token'])->postJson($this->base.'/logout')->assertNoContent();
        $this->withToken($first['access_token'])->getJson($this->base.'/me')->assertUnauthorized();
    }

    public function test_expired_retry_payload_is_purged_and_replay_window_ends_at_thirty_seconds(): void
    {
        $first = $this->login();
        $request = ['refresh_token' => $first['refresh_token'], 'request_key' => (string) Str::uuid()];
        $this->postJson($this->base.'/refresh', $request)->assertOk();
        $this->travel(30)->seconds();
        $this->assertSame(0, Artisan::call('starttoo:purge-auth'));
        $this->assertNull(DB::connection('starttoo_test')->table('starttoo_auth_sessions')->value('previous_result'));
        $this->postJson($this->base.'/refresh', $request)->assertUnauthorized();
    }
}
