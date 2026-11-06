<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

class GroupChatReadChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public $connection = 'database';
    public $queue = 'chat-realtime';
    public function __construct(private string $projectCode, private string $groupId) {}
    public function broadcastOn(): array { return [new PrivateChannel("project.{$this->projectCode}.group.{$this->groupId}")]; }
    public function broadcastAs(): string { return 'group.chat.read'; }
    public function broadcastWith(): array { return ['group_id' => $this->groupId]; }
}
