<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NicknameTest extends TestCase
{
    private string $base = '/api/v1/projects/streetcat';

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => str_repeat('nickname-test-secret-', 6),
            'platform.sms_driver' => 'preview', 'hashing.bcrypt.rounds' => 4,
            'platform.projects' => ['streetcat' => ['name' => '스트릿캣', 'connection' => 'test_nickname']],
            'database.connections.test_nickname' => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('project:migrate', ['code' => 'streetcat', '--force' => true]));
        app(ProjectDatabase::class)->run('streetcat', fn () => Project::create(['code' => 'streetcat', 'name' => '스트릿캣']));
    }

    private function login(string $phone = '01012345678'): array
    {
        $challenge = $this->postJson($this->base.'/auth/phone/request', ['phone' => $phone])->assertCreated()->json('data');
        return $this->postJson($this->base.'/auth/phone/verify', [
            'challenge_id' => $challenge['challenge_id'], 'code' => $challenge['development_code'],
        ])->assertOk()->json('data');
    }

    public function test_signup_persists_a_seeded_combination_and_primary_key_tag(): void
    {
        $data = $this->login();
        $name = $data['account']['display_name'];
        $this->assertMatchesRegularExpression('/^(.+한) (.+)$/u', $name);
        $words = $name;
        $tag = $data['account']['nickname_tag'];
        $this->assertSame($name.'#'.$tag, $data['account']['nickname']);
        $this->assertSame((string) $data['account']['id'], $tag);
        [$adjective, $noun] = explode(' ', $words);
        foreach (['adjective' => $adjective, 'noun' => $noun] as $type => $word) {
            $this->assertSame(1, DB::connection('test_nickname')->table('nickname_words')
                ->where('type', $type)->where('word', $word)->where('is_active', true)->count());
        }
        $this->assertSame($name, DB::connection('test_nickname')->table('project_accounts')->value('display_name'));
        $this->withToken($data['access_token'])->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.display_name', $name);
    }

    public function test_same_word_choices_have_unique_tags_and_ignore_disabled_words(): void
    {
        $words = DB::connection('test_nickname')->table('nickname_words');
        $words->update(['is_active' => false]);
        (clone $words)->whereIn('word', ['다정한', '밥지기'])->update(['is_active' => true]);
        $first = $this->login()['account'];
        $second = $this->login('01012345679')['account'];
        $this->assertSame('다정한 밥지기#'.$first['id'], $first['nickname']);
        $this->assertSame('다정한 밥지기#'.$second['id'], $second['nickname']);
        $this->assertSame($first['display_name'], $second['display_name']);
        $this->assertNotSame($first['nickname_tag'], $second['nickname_tag']);
    }

    public function test_relogin_preserves_generated_and_user_edited_names(): void
    {
        $first = $this->login();
        $this->travel(61)->seconds();
        $this->assertSame($first['account']['display_name'], $this->login()['account']['display_name']);
        $this->withToken($first['access_token'])->patchJson($this->base.'/me', ['display_name' => '직접 정한 이름'])->assertOk();
        $this->travel(61)->seconds();
        $this->assertSame('직접 정한 이름', $this->login()['account']['display_name']);
    }

    public function test_existing_accounts_without_name_or_tag_receive_them_on_login(): void
    {
        app(ProjectDatabase::class)->run('streetcat', function () {
            $account = new ProjectAccount;
            $account->forceFill(['project_id' => Project::first()->id, 'phone' => '01012345678',
                'status' => 'active', 'phone_verified_at' => now(), 'display_name' => null])->save();
        });
        $account = $this->login()['account'];
        $this->assertNotEmpty($account['display_name']);
        $this->assertSame((string) $account['id'], $account['nickname_tag']);
    }

    public function test_seeder_is_idempotent_and_never_writes_legacy_database(): void
    {
        $words = DB::connection('test_nickname')->table('nickname_words');
        $before = $words->count();
        $this->assertGreaterThan(300, $before);
        $this->assertSame(0, Artisan::call('project:seed-nicknames', ['code' => 'streetcat']));
        $this->assertSame(0, Artisan::call('project:seed-nicknames', ['code' => 'streetcat']));
        $this->assertSame($before, $words->count());
        $this->assertFalse(DB::connection('sqlite')->getSchemaBuilder()->hasTable('nickname_words'));
        $this->assertSame(0, DB::connection('test_nickname')->table('project_accounts')->count());
        $this->assertSame(0, (clone $words)->where('type', 'adjective')->where('word', 'not like', '%한')->count());
    }

    public function test_empty_word_types_use_a_safe_fallback_without_breaking_signup(): void
    {
        DB::connection('test_nickname')->table('nickname_words')->update(['is_active' => false]);
        $account = $this->login()['account'];
        $this->assertSame('다정한 밥지기#'.$account['id'], $account['nickname']);
    }
    public function test_duplicate_display_names_are_allowed_but_tags_are_immutable(): void
    {
        $first = $this->login();
        $second = $this->login('01012345679');
        foreach ([$first, $second] as $login) {
            $tag = $login['account']['nickname_tag'];
            $this->withToken($login['access_token'])->patchJson($this->base.'/me', ['display_name' => '같은 이름'])
                ->assertOk()->assertJsonPath('data.display_name', '같은 이름')
                ->assertJsonPath('data.nickname_tag', $tag)->assertJsonPath('data.nickname', '같은 이름#'.$tag);
            $this->withToken($login['access_token'])->patchJson($this->base.'/me', ['display_name' => '바꾼 이름', 'nickname_tag' => 999])
                ->assertUnprocessable();
            $this->withToken($login['access_token'])->patchJson($this->base.'/me', ['display_name' => '바꾼 이름#999'])
                ->assertUnprocessable();
            $this->withToken($login['access_token'])->getJson($this->base.'/me')->assertOk()
                ->assertJsonPath('data.display_name', '같은 이름')->assertJsonPath('data.nickname_tag', $tag);
            $this->withToken($login['access_token'])->patchJson($this->base.'/me', ['display_name' => '구버전 수정#'.$tag])
                ->assertOk()->assertJsonPath('data.display_name', '구버전 수정')->assertJsonPath('data.nickname_tag', $tag);
        }
    }

    public function test_migration_backfills_existing_accounts_without_duplicate_suffixes(): void
    {
        $first = $this->login()['account'];
        $second = $this->login('01012345679')['account'];
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('test_nickname');
        try {
            $migration = require database_path('project-migrations/2026_11_04_000014_add_fixed_nickname_tags.php');
            $migration->down();
            DB::table('project_accounts')->where('id', $first['id'])->update(['display_name' => '기존 이름#'.$first['id']]);
            DB::table('project_accounts')->where('id', $second['id'])->update(['display_name' => null]);
            $migration->up();
            $accounts = DB::table('project_accounts')->orderBy('id')->get();
            $this->assertCount(2, $accounts);
            $this->assertSame('기존 이름', $accounts[0]->display_name);
            $this->assertNotEmpty($accounts[1]->display_name);
            foreach ($accounts as $account) $this->assertSame($account->id, $account->nickname_tag);
            $this->expectException(\Illuminate\Database\QueryException::class);
            DB::table('project_accounts')->where('id', $second['id'])->update(['nickname_tag' => $first['id']]);
        } finally { DB::setDefaultConnection($previous); }
    }

}
