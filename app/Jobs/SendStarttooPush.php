<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendStarttooPush implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 25;
    public array $backoff = [60, 120];

    public function __construct(public int $deliveryId)
    {
        $this->onConnection('database')->onQueue('chat-push');
    }

    public function handle(ProjectDatabase $database): void
    {
        $delay = $database->run('starttoo', fn () => app(PushSender::class)->deliver($this->deliveryId));
        if ($delay !== null) self::dispatch($this->deliveryId)->delay(now()->addSeconds($delay));
    }
}
