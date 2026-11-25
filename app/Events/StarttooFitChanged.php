<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * AI 작업(가상 피팅 · 도안 추출 · 필러 · 커버업)이 끝났음을 회원 개인 채널로 알린다.
 * 내용(이미지)은 싣지 않고 번호 · 상태만 보낸다. 앱은 받으면 결과를 HTTP로 조회한다.
 */
class StarttooFitChanged implements ShouldBroadcast
{
    public $connection = 'database';
    public $queue = 'chat-realtime';

    public function __construct(
        private int $userId,
        private string $resultId,
        private string $type,
        private string $status,
        private int $remaining,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("starttoo.user.{$this->userId}")];
    }

    public function broadcastAs(): string { return 'fit.result.changed'; }

    public function broadcastWith(): array
    {
        return ['job_id' => $this->resultId, 'type' => $this->type, 'status' => $this->status, 'remaining' => $this->remaining];
    }
}
