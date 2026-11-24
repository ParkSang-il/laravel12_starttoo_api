<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * 상담방 변경 알림(새 메시지 · 읽음). 내용은 싣지 않고 번호만 보낸다.
 * 앱은 받으면 after 커서로 메시지를 조회하고 목록·배지를 갱신한다.
 * 커밋 후 호출부(Consultations)가 직접 보낸다. 큐 입력 실패가 메시지 저장을 실패시키지 않게 한다.
 */
class StarttooConsultationChanged implements ShouldBroadcast
{
    public $connection = 'database';
    public $queue = 'chat-realtime';

    /** @param int[] $userIds 받을 회원(두 참여자) */
    public function __construct(
        private array $userIds,
        private int $consultationId,
        private string $type,
        private int $messageId = 0,
        private ?int $readerId = null,
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn ($id) => new PrivateChannel("starttoo.user.{$id}"), array_values(array_unique($this->userIds)));
    }

    public function broadcastAs(): string { return 'consultation.changed'; }

    public function broadcastWith(): array
    {
        return ['consultation_id' => $this->consultationId, 'type' => $this->type,
            'message_id' => $this->messageId, 'reader_id' => $this->readerId];
    }
}
