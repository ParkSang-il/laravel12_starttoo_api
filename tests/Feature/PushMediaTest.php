<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\Project;
use App\Models\ProjectAccount;
use App\Services\ProjectDatabase;
use App\Services\FcmClient;
use App\Jobs\SendGroupPush;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
class PushMediaTest extends TestCase {
    private array $tokens = [];
    private string $group;
    private string $base = '/api/v1/projects/streetcat';
    protected function setUp(): void {
        parent::setUp();
        config(['jwt.secret' => str_repeat('push-media-tests-', 8), 'services.fcm.enabled' => false,
            'platform.projects' => ['streetcat' => ['name' => '스트릿캣', 'connection' => 'test_push_media']],
            'database.connections.test_push_media' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        $this->assertSame(0, Artisan::call('project:migrate', ['code' => 'streetcat', '--force' => true]));
        Queue::fake(); Http::preventStrayRequests();
        Storage::fake('s3')->buildTemporaryUrlsUsing(fn ($path, $until, $options) => 'https://private.invalid/'.$path.'?expires='.$until->getTimestamp());
        app(ProjectDatabase::class)->run('streetcat', function () {
            $project = Project::create(['code' => 'streetcat', 'name' => '스트릿캣']);
            for ($i = 1; $i <= 4; $i++) {
                $account = new ProjectAccount;
                $account->forceFill(['project_id' => $project->id, 'phone' => '010'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                    'display_name' => '테스트 '.$i, 'status' => 'active', 'phone_verified_at' => now()])->save();
                $guard = Auth::guard('api'); $this->tokens[$i] = $guard->login($account);
                $this->db()->table('project_auth_sessions')->insert(['id' => hash('sha256', $guard->payload()->get('jti')),
                    'account_id' => $account->id, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                Auth::forgetGuards();
            }
        });
        $this->group = $this->withToken($this->tokens[1])->postJson($this->base.'/groups', [
            'name' => '사진 테스트', 'region_code' => '0000000000', 'region_label' => '동네', 'is_discoverable' => true, 'accepting_requests' => true])->assertCreated()->json('data.id');
        foreach ([2 => 'caregiver', 3 => 'viewer'] as $id => $role) $this->db()->table('group_memberships')->insert([
            'project_id' => 1, 'group_id' => $this->group, 'account_id' => $id, 'role' => $role, 'status' => 'active',
            'chat_visible_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()]);
    }
    private function db() { return DB::connection('test_push_media'); }
    private function path(string $path): string { return $this->base.'/groups/'.$this->group.$path; }
    private function photo(string $purpose = 'chat', ?string $place = null, int $actor = 1, ?string $upload = null): array {
        if (!extension_loaded('gd')) $this->markTestSkipped('GD required for image tests');
        return $this->withToken($this->tokens[$actor])->post($this->path('/media'), [
            'upload_id' => $upload ?? (string) Str::uuid(), 'purpose' => $purpose,
            ...($place ? ['place_id' => $place] : []), 'photo' => UploadedFile::fake()->image('test.jpg', 2400, 1200)],
            ['Accept' => 'application/json'])->assertCreated()->json('data');
    }
    private function place(): array {
        return $this->withToken($this->tokens[1])->postJson($this->path('/places'), [
            'name' => '돌봄 장소', 'note' => '', 'interval_hours' => 24])->assertCreated()->json('data');
    }
    private function grant(array $place, int $actor, string $access): void {
        $version = $this->withToken($this->tokens[1])->getJson($this->path('/places/'.$place['id']))->assertOk()->json('data.version');
        $this->withToken($this->tokens[1])->patchJson($this->path('/places/'.$place['id'].'/permissions/'.$actor), ['version' => $version, 'access' => $access])->assertOk();
    }
    private function device(int $actor, string $token): int {
        $this->withToken($this->tokens[$actor])->putJson($this->base.'/me/push-device', ['token' => $token, 'platform' => 'android'])->assertOk();
        return (int) $this->db()->table('device_push_tokens')->where('token_hash', hash('sha256', $token))->value('id');
    }
    public function test_private_upload_is_resized_and_does_not_return_keys_or_urls(): void {
        $upload = (string) Str::uuid(); $one = $this->photo(upload: $upload); $two = $this->photo(upload: $upload);
        $this->assertSame($one, $two); $this->assertSame(['id','width','height'], array_keys($one));
        $row = $this->db()->table('group_media')->first();
        $this->assertSame('private', Storage::disk('s3')->visibility($row->object_key));
        $this->assertLessThanOrEqual(1600, max($one['width'], $one['height']));
        $size = getimagesizefromstring(Storage::disk('s3')->get($row->thumbnail_key));
        $this->assertLessThanOrEqual(320, max($size[0], $size[1]));
        $this->assertSame(1, $this->db()->table('group_media')->count());
    }
    public function test_purpose_permissions_and_upload_content_conflicts(): void {
        $place = $this->place(); $this->grant($place, 2, 'view');
        foreach ([[3,'chat',null],[2,'animal',$place['id']],[2,'care_record',$place['id']]] as [$actor,$purpose,$id]) {
            $this->withToken($this->tokens[$actor])->post($this->path('/media'), ['upload_id' => (string) Str::uuid(),
                'purpose' => $purpose, 'place_id' => $id, 'photo' => UploadedFile::fake()->image('test.jpg')], ['Accept'=>'application/json'])->assertForbidden();
        }
        $this->grant($place, 2, 'care'); $this->photo('care_record', $place['id'], 2);
        $upload = (string) Str::uuid(); $this->photo(upload: $upload);
        $this->withToken($this->tokens[1])->post($this->path('/media'), ['upload_id' => $upload, 'purpose' => 'chat',
            'photo' => UploadedFile::fake()->image('different.jpg', 100, 100)], ['Accept'=>'application/json'])->assertConflict();
    }
    public function test_photo_message_is_idempotent_and_deletion_defers_purge_30_days(): void {
        $photo = $this->photo(); $data = ['request_id'=>(string) Str::uuid(), 'media_ids'=>[$photo['id']]];
        $one = $this->withToken($this->tokens[1])->postJson($this->path('/messages/photos'), $data)->assertCreated()->json('data');
        $this->withToken($this->tokens[1])->postJson($this->path('/messages/photos'), $data)->assertCreated()->assertJsonPath('data.id',$one['id']);
        $this->assertSame('photo',$one['kind']); $this->assertCount(1,$one['photos']);
        $expires = \Carbon\Carbon::parse($one['photos'][0]['expires_at']); $this->assertEqualsWithDelta(600, now()->diffInSeconds($expires), 2);
        $this->withToken($this->tokens[1])->patchJson($this->path('/messages/'.$one['id']), ['body'=>'caption'])->assertConflict();
        $row = $this->db()->table('group_media')->first();
        $this->withToken($this->tokens[1])->deleteJson($this->path('/messages/'.$one['id']))->assertOk()->assertJsonPath('data.photos',[]);
        $this->assertSame('deleted',$this->db()->table('group_media')->value('status'));
        Artisan::call('project:purge-group-media',['code'=>'streetcat']); Storage::disk('s3')->assertExists($row->object_key);
        $this->travel(31)->days(); Artisan::call('project:purge-group-media',['code'=>'streetcat']); Storage::disk('s3')->assertMissing($row->object_key);
        $this->assertNotNull($this->db()->table('group_media')->value('purged_at'));
    }
    public function test_another_authors_media_and_wrong_purpose_cannot_be_claimed(): void {
        $photo=$this->photo(actor:2);
        $this->withToken($this->tokens[1])->postJson($this->path('/messages/photos'),['request_id'=>(string) Str::uuid(),'media_ids'=>[$photo['id']]])->assertUnprocessable();
        $place=$this->place(); $animal=$this->withToken($this->tokens[1])->postJson($this->path('/places/'.$place['id'].'/animals'),['name'=>'나비'])->assertCreated()->json('data');
        $photo=$this->photo();
        $this->withToken($this->tokens[1])->putJson($this->path('/places/'.$place['id'].'/animals/'.$animal['id'].'/photo'),['media_id'=>$photo['id'],'version'=>$animal['version']])->assertUnprocessable();
    }
    public function test_friend_photo_replace_is_idempotent_and_old_photo_kept_for_30_days(): void {
        $place=$this->place(); $animal=$this->withToken($this->tokens[1])->postJson($this->path('/places/'.$place['id'].'/animals'),['name'=>'나비'])->assertCreated()->json('data');
        $url=$this->path('/places/'.$place['id'].'/animals/'.$animal['id'].'/photo');
        $first=$this->photo('animal',$place['id']);
        $a=$this->withToken($this->tokens[1])->putJson($url,['media_id'=>$first['id'],'version'=>$animal['version']])->assertOk()->json('data');
        $this->withToken($this->tokens[1])->putJson($url,['media_id'=>$first['id'],'version'=>$animal['version']])->assertOk()->assertJsonPath('data.version',$a['version']);
        $second=$this->photo('animal',$place['id']);
        $this->withToken($this->tokens[1])->putJson($url,['media_id'=>$second['id'],'version'=>$a['version']])->assertOk()->assertJsonPath('data.photo.id',$second['id']);
        $this->assertSame('deleted',$this->db()->table('group_media')->where('id',$first['id'])->value('status'));
    }
    public function test_care_photos_preserve_old_empty_hash_and_cannot_cross_places(): void {
        $one=$this->place(); $two=$this->place(); $photo=$this->photo('care_record',$one['id']);
        $data=['request_id'=>(string) Str::uuid(),'fed_at'=>now()->subMinute()->toISOString(),'tags'=>[],'cost'=>0,'sightings'=>[]];
        $url=$this->path('/places/'.$one['id'].'/care-records');
        $record=$this->withToken($this->tokens[1])->postJson($url,$data)->assertCreated()->json('data.id');
        $this->withToken($this->tokens[1])->postJson($url,[...$data,'photo_ids'=>[]])->assertCreated()->assertJsonPath('data.id',$record);
        $data['request_id']=(string) Str::uuid(); $data['photo_ids']=[$photo['id']];
        $this->withToken($this->tokens[1])->postJson($this->path('/places/'.$two['id'].'/care-records'),$data)->assertUnprocessable();
        $this->withToken($this->tokens[1])->postJson($url,$data)->assertCreated()->assertJsonPath('data.photos.0.id',$photo['id']);
    }
    public function test_device_transfer_off_and_logout_cascade(): void {
        $this->device(1,'same-device'); $id=$this->device(2,'same-device');
        $this->assertSame(1,$this->db()->table('device_push_tokens')->count());
        $this->assertSame(2,(int)$this->db()->table('device_push_tokens')->where('id',$id)->value('account_id'));
        $this->withToken($this->tokens[1])->deleteJson($this->base.'/me/push-device')->assertNoContent();
        $this->assertSame(1,$this->db()->table('device_push_tokens')->count());
        $this->withToken($this->tokens[2])->postJson($this->base.'/auth/logout')->assertNoContent();
        $this->assertSame(0,$this->db()->table('device_push_tokens')->count());
    }
    public function test_push_excludes_author_viewer_read_messages_and_expired_sessions(): void {
        config(['services.fcm.enabled'=>true]);
        $ids=[$this->device(1,'owner-device'),$this->device(2,'care-device'),$this->device(3,'viewer-device')];
        $message=$this->withToken($this->tokens[1])->postJson($this->path('/messages'),['request_id'=>(string)Str::uuid(),'body'=>'비밀 본문'])->assertCreated()->json('data.id');
        $client=\Mockery::mock(FcmClient::class); $client->shouldReceive('send')->once()->with('care-device',\Mockery::on(function($p){
            $this->assertSame('새 메시지가 있어요',$p['notification']['body']);
            $this->assertSame(['type','group_id','message_id'],array_keys($p['data']));
            $this->assertStringNotContainsString('비밀 본문',json_encode($p));return true;
        }))->andReturn(['state'=>'sent']);
        $job=new SendGroupPush('streetcat',$this->group,'message',$message,tokenIds:$ids);
        $job->handle(app(ProjectDatabase::class),$client);
        $this->db()->table('group_memberships')->where('account_id',2)->update(['chat_read_message_id'=>$message]);
        $job->handle(app(ProjectDatabase::class),$client);
        $this->db()->table('group_memberships')->where('account_id',2)->update(['chat_read_message_id'=>null]);
        $this->db()->table('project_auth_sessions')->where('account_id',2)->update(['last_seen_at'=>now()->subDays(91)]);
        $job->handle(app(ProjectDatabase::class),$client);
    }
    public function test_expired_pending_upload_is_purged_after_24_hours(): void {
        $photo=$this->photo(); $row=$this->db()->table('group_media')->where('id',$photo['id'])->first();
        $this->travel(25)->hours(); Artisan::call('project:purge-group-media',['code'=>'streetcat']);
        Storage::disk('s3')->assertMissing($row->object_key);
    }
    public function test_fcm_invalid_argument_only_removes_an_explicit_token_violation(): void {
        if (!function_exists('openssl_pkey_new')) $this->markTestSkipped('OpenSSL required');
        $key=openssl_pkey_new(['private_key_bits'=>2048]); openssl_pkey_export($key,$private);
        $path=tempnam(sys_get_temp_dir(),'test-fcm-');
        file_put_contents($path,json_encode(['project_id'=>'test-project','client_email'=>'test@test.invalid','private_key'=>$private]));
        config(['services.fcm.credentials'=>$path]);
        try {
            foreach ([false,true] as $badToken) {
                Http::fake(['oauth2.googleapis.com/*'=>Http::response(['access_token'=>'fake-access','expires_in'=>3600]),
                    'fcm.googleapis.com/*'=>Http::response(['error'=>['status'=>'INVALID_ARGUMENT','details'=>[
                        ['@type'=>'type.googleapis.com/google.rpc.BadRequest','fieldViolations'=>[['field'=>$badToken?'message.token':'message.data']]]]]],400)]);
                $result=(new FcmClient)->send('fake-device',['notification'=>['title'=>'그룹','body'=>'안내']]);
                $this->assertSame($badToken?'invalid':'rejected',$result['state']);
            }
        } finally { unlink($path); }
    }
}
