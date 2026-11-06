<?php
namespace App\Jobs;
use App\Services\ProjectDatabase;
use App\Services\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
class SendGroupPush implements ShouldQueue {
    use Queueable;
    public int $tries = 1;
    public function __construct(public string $code, public string $group, public string $type,
        public int $resource, public ?int $version = null, public ?string $status = null,
        public ?array $tokenIds = null, public int $attempt = 0) {
        $this->onConnection('database')->onQueue('chat-push')->afterCommit();
    }
    public function handle(ProjectDatabase $database, FcmClient $fcm): void {
        if (!config('services.fcm.enabled', false)) return;
        $database->run($this->code, function () use ($database, $fcm) {
            $db = $database->database();
            $group = $db->table('care_groups')->where('id', $this->group)->first();
            if (!$group) return;
            $project = (int) $group->project_id; $message = null;
            if ($this->type === 'message') {
                $message = $db->table('group_messages')->where('project_id', $project)->where('group_id', $this->group)
                    ->where('id', $this->resource)->whereNull('deleted_at')->first();
                if (!$message) return;
                $accounts = $db->table('group_memberships')->where('project_id', $project)->where('group_id', $this->group)
                    ->where('status', 'active')->whereIn('role', ['owner', 'caregiver'])->where('account_id', '<>', $message->author_id)
                    ->where('chat_visible_from', '<=', $message->created_at)
                    ->where(fn ($q) => $q->whereNull('chat_read_message_id')->orWhere('chat_read_message_id', '<', $message->id))->pluck('account_id')->all();
                $body = $message->kind === 'care_record' ? '새 돌봄 기록이 있어요' : '새 메시지가 있어요';
            } else {
                $request = $db->table('group_join_requests')->where('project_id', $project)->where('group_id', $this->group)
                    ->where('id', $this->resource)->where('version', $this->version)->where('status', $this->status)->first();
                if (!$request) return;
                if ($this->type === 'join_request') {
                    $accounts = $db->table('group_memberships')->where('project_id', $project)->where('group_id', $this->group)
                        ->where('status', 'active')->where('role', 'owner')->where('account_id', '<>', $request->account_id)->pluck('account_id')->all();
                    $body = '새 가입 신청이 있어요';
                } else {
                    $accounts = [$request->account_id];
                    $body = $request->status === 'approved' ? '가입 신청이 승인됐어요' : '가입 신청이 거절됐어요';
                }
            }
            if (!$accounts) return;
            $tokens = $db->table('device_push_tokens as t')->join('project_accounts as a', function ($j) {
                $j->on('a.id', '=', 't.account_id')->on('a.project_id', '=', 't.project_id');
            })->join('project_auth_sessions as s', function ($j) { $j->on('s.id', '=', 't.session_id')->on('s.account_id', '=', 't.account_id'); })
                ->where('t.project_id', $project)->whereIn('t.account_id', $accounts)->where('a.status', 'active')
                ->where('s.last_seen_at', '>', now()->subDays(90))->select('t.*');
            // Fan out one device per delivery job to stay within the existing 30s worker timeout.
            if ($this->tokenIds === null) {
                foreach ($tokens->get(['t.id']) as $row) self::dispatch($this->code, $this->group, $this->type,
                    $this->resource, $this->version, $this->status, [(int) $row->id], 0);
                return;
            }
            $tag = $this->type.':'.$this->group;
            $data = ['type' => $this->type, 'group_id' => $this->group];
            if ($message) $data['message_id'] = (string) $message->id;
            $payload = ['notification' => ['title' => mb_substr($group->name, 0, 50), 'body' => $body], 'data' => $data,
                'android' => ['notification' => ['channel_id' => 'group_care', 'tag' => $tag]],
                'apns' => ['headers' => ['apns-collapse-id' => $tag], 'payload' => ['aps' => ['sound' => 'default']]]];
            foreach ($tokens->whereIn('t.id', $this->tokenIds)->get() as $row) {
                $result = $fcm->send($row->token, $payload);
                if ($result['state'] === 'invalid') $db->table('device_push_tokens')->where('id', $row->id)->where('token_hash', $row->token_hash)->delete();
                if ($result['state'] === 'retry' && $this->attempt < 3) self::dispatch($this->code, $this->group, $this->type,
                    $this->resource, $this->version, $this->status, [(int) $row->id], $this->attempt + 1)->delay(now()->addSeconds($result['delay']));
            }
        });
    }
}
