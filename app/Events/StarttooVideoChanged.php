<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * 무빙(동영상) 상태가 바뀌었음을 올린 회원의 개인 채널로 알린다(미니바 · 게시 대기함 갱신).
 * 번호 · 상태만 보낸다. 앱은 받으면 GET videos/{id} 로 다시 읽는다.
 */
class StarttooVideoChanged implements ShouldBroadcast
{
    public $connection = 'database';
    public $queue = 'chat-realtime';

    public function __construct(
        private int $userId,
        private string $videoId,
        private string $status,
        private ?string $publishedType,
        private ?string $publishedId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("starttoo.user.{$this->userId}")];
    }

    public function broadcastAs(): string { return 'video.status'; }

    public function broadcastWith(): array
    {
        return ['video_id' => $this->videoId, 'status' => $this->status,
            'published_type' => $this->publishedType, 'published_id' => $this->publishedId];
    }
}
