<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\FollowerNotifications;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FanoutStarttooPost implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 25;
    public array $backoff = [60, 120];
    public function __construct(public int $fanoutId) { $this->onConnection('database')->onQueue('chat-push'); }
    public function handle(ProjectDatabase $database): void
    {
        $more = $database->run('starttoo', fn () => app(FollowerNotifications::class)->batch($this->fanoutId));
        if ($more) self::dispatch($this->fanoutId);
    }
}
