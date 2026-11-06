<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportMessageTest extends TestCase
{
    private string $base = '/api/v1/projects/streetcat/support-messages';

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('support-message-tests-', 6),
            'platform.sms_driver' => 'preview', 'hashing.bcrypt.rounds' => 4,
            'platform.projects' => [
                'streetcat' => ['name' => '스트릿캣', 'connection' => 'test_support'],
                'second-app' => ['name' => '격리', 'connection' => 'test_support_second'],
            ]]);
        foreach (['streetcat' => 'test_support', 'second-app' => 'test_support_second'] as $code => $connection) {
            config(['database.connections.'.$connection => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
            $this->assertSame(0, Artisan::call('project:migrate', ['code' => $code, '--force' => true]));
            app(ProjectDatabase::class)->run($code, fn () => Project::create(['code' => $code, 'name' => $code]));
        }
    }

    private function login(string $project = 'streetcat'): array
    {
        $base = '/api/v1/projects/'.$project.'/auth/phone';
        $challenge = $this->postJson($base.'/request', ['phone' => '01012345678'])->assertCreated()->json('data');
        return $this->postJson($base.'/verify', ['challenge_id' => $challenge['challenge_id'],
            'code' => $challenge['development_code']])->assertOk()->json('data');
    }

    private function payload(bool $anonymous = false): array
    {
        return ['request_id' => (string) Str::uuid(), 'message' => '  항상 응원합니다  ', 'anonymous' => $anonymous];
    }

    public function test_authenticated_message_is_persisted_and_returned_with_current_nickname(): void
    {
        $login = $this->login();
        $message = $this->withToken($login['access_token'])->postJson($this->base, $this->payload())->assertCreated();
        $message->assertJsonPath('data.message', '항상 응원합니다')
            ->assertJsonPath('data.author_name', $login['account']['nickname'])
            ->assertJsonPath('data.anonymous', false)->assertJsonMissingPath('data.account_id');
        $this->assertSame(1, DB::connection('test_support')->table('support_messages')->count());
        $this->withToken($login['access_token'])->getJson($this->base)->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $message->json('data.id'));
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('support_messages'));
    }

    public function test_anonymous_responses_never_expose_the_writers_identity(): void
    {
        $login = $this->login();
        $row = $this->withToken($login['access_token'])->postJson($this->base, $this->payload(true))
            ->assertCreated()->json('data');
        $this->assertSame(['id', 'message', 'anonymous', 'author_name', 'created_at'], array_keys($row));
        $this->assertSame('익명의 응원', $row['author_name']);
        $this->withToken($login['access_token'])->getJson($this->base)->assertOk()
            ->assertJsonPath('data.items.0', $row)->assertJsonMissingPath('data.items.0.account_id')
            ->assertJsonMissingPath('data.items.0.nickname_tag')->assertJsonMissingPath('data.items.0.avatar_url');
    }

    public function test_identical_retry_is_idempotent_and_changed_payload_conflicts(): void
    {
        $login = $this->login();
        $data = $this->payload();
        $id = $this->withToken($login['access_token'])->postJson($this->base, $data)->assertCreated()->json('data.id');
        $this->withToken($login['access_token'])->postJson($this->base, $data)->assertOk()->assertJsonPath('data.id', $id);
        $data['anonymous'] = true;
        $this->withToken($login['access_token'])->postJson($this->base, $data)->assertConflict();
        $this->assertSame(1, DB::connection('test_support')->table('support_messages')->count());
    }

    public function test_empty_long_and_forged_identity_messages_are_rejected(): void
    {
        $login = $this->login();
        foreach ([['message' => '   '], ['message' => str_repeat('가', 501)],
            ['account_id' => 99], ['author_name' => '위조']] as $overrides) {
            $this->withToken($login['access_token'])->postJson($this->base, array_replace($this->payload(), $overrides))
                ->assertUnprocessable();
        }
        $this->assertSame(0, DB::connection('test_support')->table('support_messages')->count());
    }

    public function test_month_filter_and_cursor_return_each_message_once(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 6)->startOfDay());
        $login = $this->login();
        $table = DB::connection('test_support')->table('support_messages');
        $projectId = DB::connection('test_support')->table('projects')->value('id');
        for ($i = 0; $i < 23; $i++) {
            $at = $i === 0 ? now()->startOfMonth()->subSecond() : now();
            $table->insert(['project_id' => $projectId, 'account_id' => $login['account']['id'],
                'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64),
                'message' => '응원 '.$i, 'anonymous' => true, 'created_at' => $at, 'updated_at' => $at]);
        }
        $first = $this->withToken($login['access_token'])->getJson($this->base)->assertOk()
            ->assertJsonPath('data.month', '2026-11')->assertJsonPath('data.total', 22)->assertJsonCount(20, 'data.items')->json('data');
        $second = $this->withToken($login['access_token'])->getJson($this->base.'?before_id='.$first['next_before_id'])
            ->assertOk()->assertJsonCount(2, 'data.items')->assertJsonPath('data.next_before_id', null)->json('data');
        $ids = array_column([...$first['items'], ...$second['items']], 'id');
        $this->assertCount(22, array_unique($ids));
        $this->assertNotContains(1, $ids);
    }

    public function test_authentication_and_project_isolation_are_enforced(): void
    {
        $this->getJson($this->base)->assertUnauthorized();
        $this->postJson($this->base, $this->payload())->assertUnauthorized();
        $first = $this->login();
        $second = $this->login('second-app');
        $this->withToken($first['access_token'])->postJson($this->base, $this->payload())->assertCreated();
        $other = '/api/v1/projects/second-app/support-messages';
        $this->withToken($first['access_token'])->getJson($other)->assertForbidden();
        $this->withToken($second['access_token'])->getJson($other)->assertOk()->assertJsonPath('data.total', 0);
        DB::connection('test_support')->table('project_accounts')->update(['status' => 'suspended']);
        $this->withToken($first['access_token'])->postJson($this->base, $this->payload())->assertForbidden();
    }
}
