<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class GroupMessageChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public $connection = 'database';
    public $queue = 'chat-realtime';

    public function __construct(
        private string $projectCode,
        private string $groupId,
        private int $messageId,
        private string $change,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("project.{$this->projectCode}.group.{$this->groupId}")];
    }

    public function broadcastAs(): string { return 'group.message.changed'; }

    public function broadcastWith(): array
    {
        return ['group_id' => $this->groupId, 'message_id' => $this->messageId, 'change' => $this->change];
    }
}
