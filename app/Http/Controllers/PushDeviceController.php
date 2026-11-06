<?php
namespace App\Http\Controllers;
use App\Services\ProjectDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
class PushDeviceController extends Controller {
    public function put(Request $r, ProjectDatabase $database) {
        $data = $r->validate(['token' => ['required', 'string', 'max:4096'], 'platform' => ['required', 'in:android,ios']]);
        abort_unless($database->code() === 'streetcat', 409);
        $actor = $r->user(); $session = hash('sha256', Auth::guard('api')->payload()->get('jti'));
        $database->database()->transaction(function () use ($database, $actor, $data, $session) {
            // Serialize transfers so a token cannot belong to two accounts in this project.
            $database->database()->table('projects')->where('id', $actor->project_id)->lockForUpdate()->firstOrFail();
            $live = $database->database()->table('project_auth_sessions')->where('id', $session)->where('account_id', $actor->id)
                ->where('last_seen_at', '>', now()->subDays(90))->lockForUpdate()->first();
            abort_unless($live, 409, '로그인 정보를 다시 확인해 주세요.');
            $table = $database->database()->table('device_push_tokens'); $hash = hash('sha256', $data['token']);
            (clone $table)->where('token_hash', $hash)->where('session_id', '<>', $session)->delete();
            $old = (clone $table)->where('session_id', $session)->first();
            $values = ['project_id' => $actor->project_id, 'account_id' => $actor->id, 'session_id' => $session,
                'platform' => $data['platform'], 'token' => $data['token'], 'token_hash' => $hash,
                'last_registered_at' => now(), 'updated_at' => now()];
            if ($old) (clone $table)->where('id', $old->id)->update($values);
            else $table->insert([...$values, 'created_at' => now()]);
        }, 3);
        return response()->json(['data' => ['registered' => true, 'delivery_ready' => (bool) config('services.fcm.enabled', false)]]);
    }
    public function delete(Request $r, ProjectDatabase $database) {
        $database->database()->table('device_push_tokens')->where('account_id', $r->user()->id)
            ->where('session_id', hash('sha256', Auth::guard('api')->payload()->get('jti')))->delete();
        return response()->noContent();
    }
}
