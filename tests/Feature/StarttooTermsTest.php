<?php

namespace Tests\Feature;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\AuthSessions;
use App\Services\Starttoo\TermsConsents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StarttooTermsTest extends TestCase
{
    private string $base = '/api/v1/projects/starttoo/auth';
    private string $token;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'starttoo.draft_terms_enabled' => true,
            'platform.projects' => ['starttoo' => ['connection' => 'starttoo_terms_test']],
            'database.connections.starttoo_terms_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'starttoo_terms_test', '--path' => 'database/starttoo-migrations', '--force' => true]));
        $login = app(ProjectDatabase::class)->run('starttoo', fn () => app(AuthSessions::class)->login('google', 'terms-test-subject'));
        $this->token = $login['access_token'];
        $this->userId = $login['account']['id'];
    }

    private function db() { return DB::connection('starttoo_terms_test'); }

    private function catalog(): array
    {
        return $this->withToken($this->token)->getJson($this->base.'/terms')->assertOk()->json('data');
    }

    private function payload(?array $catalog = null): array
    {
        $catalog ??= $this->catalog();
        return ['version' => $catalog['version'], 'revision' => $catalog['revision'], 'request_key' => (string) Str::uuid(),
            'consents' => collect($catalog['documents'])->mapWithKeys(fn ($doc) => [$doc['id'] => $doc['required']])->all()];
    }

    public function test_documents_require_login_and_drafts_require_explicit_enablement(): void
    {
        $this->getJson($this->base.'/terms')->assertUnauthorized();
        config(['starttoo.draft_terms_enabled' => false]);
        $this->withToken($this->token)->getJson($this->base.'/terms')->assertStatus(503)->assertJsonPath('code', 'TERMS_NOT_AVAILABLE');
        config(['starttoo.draft_terms_enabled' => true]);
        $catalog = $this->catalog();
        $this->assertTrue($catalog['is_draft']);
        $this->assertCount(5, $catalog['documents']);
        $this->assertSame(64, strlen($catalog['revision']));
        foreach ($catalog['documents'] as $document) {
            $this->assertStringContainsString('개발용 초안', $document['content']);
        }
    }

    public function test_required_missing_invalid_boolean_and_unknown_fields_cannot_advance_signup(): void
    {
        $payload = $this->payload();
        $payload['consents']['service'] = false;
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertUnprocessable();
        $payload['consents']['service'] = 'true';
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertUnprocessable();
        $payload['consents']['service'] = true;
        $payload['consents']['unrecognized'] = true;
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertUnprocessable();
        $this->assertSame(0, $this->db()->table('starttoo_consent_records')->count());
        $this->assertNull($this->db()->table('starttoo_users')->where('id', $this->userId)->value('terms_version'));
    }

    public function test_optional_refusal_is_recorded_with_server_time_document_version_and_snapshot(): void
    {
        $payload = $this->payload();
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertOk()->assertJsonPath('data.next_action', 'profile');
        $submission = $this->db()->table('starttoo_consent_submissions')->first();
        $this->assertSame($payload['version'], $submission->version);
        $this->assertTrue((bool) $submission->is_draft);
        $snapshot = json_decode($submission->documents_snapshot, true);
        $records = $this->db()->table('starttoo_consent_records')->get()->keyBy('document_id');
        $this->assertCount(5, $records);
        $this->assertFalse((bool) $records['location']->accepted);
        $this->assertFalse((bool) $records['marketing']->accepted);
        foreach ($snapshot as $document) {
            $this->assertSame($document['version'], $records[$document['id']]->document_version);
            $this->assertSame(hash('sha256', $document['content']), $records[$document['id']]->content_sha256);
            $this->assertSame($submission->recorded_at, $records[$document['id']]->recorded_at);
        }
        $this->withToken($this->token)->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.next_action', 'profile');
        $this->withToken($this->token)->getJson($this->base.'/terms')->assertOk()->assertJsonPath('data.account.next_action', 'profile');
    }

    public function test_retries_create_one_submission_and_cannot_rewrite_past_choices(): void
    {
        $payload = $this->payload();
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertOk();
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertOk();
        $this->withToken($this->token)->postJson($this->base.'/terms', [...$payload, 'request_key' => (string) Str::uuid()])->assertOk();
        $payload['consents']['marketing'] = true;
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertConflict()->assertJsonPath('code', 'CONSENT_CONFLICT');
        $this->assertSame(1, $this->db()->table('starttoo_consent_submissions')->count());
        $this->assertSame(5, $this->db()->table('starttoo_consent_records')->count());
    }

    public function test_stale_version_and_content_revision_are_rejected(): void
    {
        $payload = $this->payload();
        $this->withToken($this->token)->postJson($this->base.'/terms', [...$payload, 'version' => 'older-version'])->assertConflict()->assertJsonPath('code', 'TERMS_VERSION_CHANGED');
        $this->withToken($this->token)->postJson($this->base.'/terms', [...$payload, 'revision' => str_repeat('0', 64)])->assertConflict();
        $this->assertSame(0, $this->db()->table('starttoo_consent_submissions')->count());
    }

    public function test_new_version_requires_reconsent_without_deleting_previous_history(): void
    {
        $old = $this->payload();
        $this->withToken($this->token)->postJson($this->base.'/terms', $old)->assertOk();
        $catalog = $this->catalog();
        $catalog['version'] = 'next-draft-version';
        $catalog['revision'] = str_repeat('b', 64);
        config(['starttoo.terms_version' => $catalog['version']]);
        $terms = \Mockery::mock(TermsConsents::class)->makePartial();
        $terms->shouldReceive('catalog')->andReturn($catalog);
        $this->app->instance(TermsConsents::class, $terms);
        $this->withToken($this->token)->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.next_action', 'terms');
        $this->withToken($this->token)->postJson($this->base.'/terms', $this->payload($catalog))->assertOk()->assertJsonPath('data.next_action', 'profile');
        $this->assertSame(2, $this->db()->table('starttoo_consent_submissions')->count());
        $this->assertSame(10, $this->db()->table('starttoo_consent_records')->count());
    }

    public function test_failed_record_insert_rolls_back_signup_progress(): void
    {
        $payload = $this->payload();
        $this->db()->getSchemaBuilder()->drop('starttoo_consent_records');
        $this->withToken($this->token)->postJson($this->base.'/terms', $payload)->assertStatus(500);
        $this->assertSame(0, $this->db()->table('starttoo_consent_submissions')->count());
        $this->assertNull($this->db()->table('starttoo_users')->where('id', $this->userId)->value('terms_version'));
    }
}
