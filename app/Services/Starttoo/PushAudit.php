<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;

/** 토큰·키·실제 댓글 원문은 복제하지 않는다. 기기/세션 삭제와 독립적으로 유지한다. */
class PushAudit
{
    public function begin(string $type, string $source, ?int $delivery, ?int $user, ?int $device, ?string $platform, int $attempt,
        ?string $kind = null, ?string $title = null, ?string $body = null): int
    {
        return app(ProjectDatabase::class)->database()->table('starttoo_push_logs')->insertGetId([
            'source_type' => $type, 'source_id' => $source, 'delivery_id' => $delivery, 'user_id' => $user,
            'device_id' => $device, 'platform' => $platform, 'attempt' => $attempt, 'result' => 'started', 'created_at' => now(),
            'kind' => $kind, 'title_snapshot' => $title, 'body_snapshot' => $body,
        ]);
    }
    public function finish(int $id, array $result): void
    {
        app(ProjectDatabase::class)->database()->table('starttoo_push_logs')->where('id', $id)->update([
            'result' => $result['state'], 'reason' => $result['code'] ?? null, 'http_status' => $result['http_status'] ?? null,
            'fcm_message_id' => $result['message_id'] ?? null, 'finished_at' => now(),
        ]);
    }
    public function opened(string $type, string $source, int $user): void
    {
        $id = $this->begin($type, $source, null, $user, null, null, 0);
        $this->finish($id, ['state' => 'opened']);
    }
}
