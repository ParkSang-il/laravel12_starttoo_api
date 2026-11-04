<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    private string $token;

    private string $base = '/api/v1/projects/streetcat';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        config(['jwt.secret' => str_repeat('avatar-test-secret-', 6),
            'platform.projects' => ['streetcat' => ['connection' => 'test_avatar']],
            'database.connections.test_avatar' => ['driver' => 'sqlite', 'database' => ':memory:',
                'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('project:migrate', ['code' => 'streetcat', '--force' => true]));
        app(ProjectDatabase::class)->run('streetcat', function () {
            $project = Project::create(['code' => 'streetcat', 'name' => '스트릿캣']);
            $account = new ProjectAccount;
            $account->forceFill(['project_id' => $project->id, 'status' => 'active', 'phone' => '01012345678',
                'phone_verified_at' => now(), 'display_name' => '회원'])->save();
            $this->token = Auth::guard('api')->login($account);
            app(ProjectDatabase::class)->database()->table('project_auth_sessions')->insert([
                'id' => hash('sha256', Auth::guard('api')->payload()->get('jti')),
                'account_id' => $account->id, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            Auth::forgetGuards();
        });
        Storage::fake('s3')->buildTemporaryUrlsUsing(fn ($path, $expiration) => 'https://objects.test/'.$path.'?expires='.$expiration->timestamp);
    }

    private function upload()
    {
        return $this->withToken($this->token)->post($this->base.'/me/avatar', [
            'photo' => UploadedFile::fake()->image('photo.png', 1200, 600),
        ], ['Accept' => 'application/json']);
    }

    public function test_upload_stores_private_dated_images_and_shared_views_return_signed_urls(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4));
        $result = $this->upload()->assertOk()->assertJsonMissingPath('data.avatar_path')
            ->assertJsonMissingPath('data.avatar_disk')->json('data');
        $this->assertStringContainsString('/profile/2026/11/04/streetcat/1/', $result['avatar_url']);
        $this->assertStringContainsString('-thumb.', $result['avatar_thumbnail_url']);
        $paths = Storage::disk('s3')->allFiles('profile');
        $this->assertCount(2, $paths);
        foreach ($paths as $path) {
            $this->assertSame('private', Storage::disk('s3')->getVisibility($path));
            $dimensions = getimagesizefromstring(Storage::disk('s3')->get($path));
            $this->assertSame(function_exists('imagejpeg') ? IMAGETYPE_JPEG : IMAGETYPE_PNG, $dimensions[2]);
            $this->assertLessThanOrEqual(str_contains($path, '-thumb') ? 192 : 1080, max($dimensions[0], $dimensions[1]));
        }
        $this->withToken($this->token)->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.avatar_url', $result['avatar_url']);
        $group = $this->withToken($this->token)->postJson($this->base.'/groups', ['name' => '프로필 테스트'])
            ->assertCreated()->json('data.id');
        $this->withToken($this->token)->getJson($this->base.'/me/groups')->assertOk()
            ->assertJsonPath('data.0.member_previews.0.avatar_thumbnail_url', $result['avatar_thumbnail_url']);
        $this->withToken($this->token)->getJson($this->base.'/groups/'.$group.'/members')->assertOk()
            ->assertJsonPath('data.0.avatar_url', $result['avatar_url'])->assertJsonMissingPath('data.0.avatar_path');
        $place = $this->withToken($this->token)->postJson($this->base.'/groups/'.$group.'/places', ['name' => '장소', 'interval_hours' => 24])
            ->assertCreated()->json('data.id');
        $path = $this->base.'/groups/'.$group.'/places/'.$place.'/care-records';
        $this->withToken($this->token)->postJson($path, ['request_id' => (string) Str::uuid(),
            'fed_at' => now()->subMinute()->toIso8601String(), 'cost' => 0, 'tags' => [], 'sightings' => []])
            ->assertCreated()->assertJsonPath('data.avatar_thumbnail_url', $result['avatar_thumbnail_url']);
        $this->withToken($this->token)->getJson($path)->assertOk()
            ->assertJsonPath('data.records.0.avatar_url', $result['avatar_url']);
    }

    public function test_replace_and_delete_remove_previous_objects_and_never_store_client_identity(): void
    {
        $this->upload()->assertOk();
        $old = Storage::disk('s3')->allFiles('profile');
        $this->withToken($this->token)->post($this->base.'/me/avatar', [
            'photo' => UploadedFile::fake()->image('new.png'), 'account_id' => 999, 'avatar_path' => 'someone-else.jpg',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.id', 1);
        foreach ($old as $path) {
            Storage::disk('s3')->assertMissing($path);
        }
        $this->assertCount(2, Storage::disk('s3')->allFiles('profile'));
        $this->withToken($this->token)->deleteJson($this->base.'/me/avatar')->assertOk()
            ->assertJsonPath('data.avatar_url', null)->assertJsonPath('data.avatar_thumbnail_url', null);
        $this->assertCount(0, Storage::disk('s3')->allFiles('profile'));
        $this->withToken($this->token)->deleteJson($this->base.'/me/avatar')->assertOk();
    }

    public function test_invalid_unauthenticated_and_oversized_uploads_do_not_create_objects(): void
    {
        $this->postJson($this->base.'/me/avatar')->assertUnauthorized();
        $this->withToken($this->token)->post($this->base.'/me/avatar', ['photo' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->withToken($this->token)->post($this->base.'/me/avatar', ['photo' => UploadedFile::fake()->image('big.png')->size(5121)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('s3')->allFiles('profile'));
    }

    public function test_failed_object_write_preserves_existing_profile_and_removes_partial_upload(): void
    {
        $before = $this->upload()->assertOk()->json('data.avatar_url');
        $old = Storage::disk('s3')->allFiles('profile');
        $real = Storage::disk('s3');
        $mock = \Mockery::mock(FilesystemAdapter::class);
        $writes = 0;
        $mock->shouldReceive('put')->twice()->andReturnUsing(function ($path, $bytes, $options) use ($real, &$writes) {
            if (++$writes === 2) {
                throw new \RuntimeException('simulated storage failure');
            }

            return $real->put($path, $bytes, $options);
        });
        $mock->shouldReceive('delete')->once()->andReturnUsing(fn ($paths) => $real->delete($paths));
        Storage::set('s3', $mock);
        $this->upload()->assertStatus(503);
        Storage::set('s3', $real);
        $this->assertSame($old, $real->allFiles('profile'));
        $this->withToken($this->token)->getJson($this->base.'/me')->assertOk()->assertJsonPath('data.avatar_url', $before);
    }
}
