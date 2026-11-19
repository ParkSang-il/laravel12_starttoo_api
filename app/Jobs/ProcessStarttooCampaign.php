<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\PushCampaigns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessStarttooCampaign implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 25;
    public array $backoff = [60, 120];
    public function __construct(public string $campaignId) { $this->onConnection('database')->onQueue('chat-marketing'); }
    public function handle(ProjectDatabase $database): void
    {
        $delay = $database->run('starttoo', fn () => app(PushCampaigns::class)->tick($this->campaignId));
        if ($delay !== null) self::dispatch($this->campaignId)->delay(now()->addSeconds($delay));
    }
}
