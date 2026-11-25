<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\Fittings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 가상 피팅 AI 합성(나노바나나). 미디어 큐 worker(timeout 75초)에서 처리한다. */
class ProcessStarttooFitResult implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 75;

    public function __construct(public string $resultId)
    {
        $this->onConnection('database')->onQueue('chat-media');
    }

    public function handle(ProjectDatabase $database): void
    {
        $database->run('starttoo', fn () => app(Fittings::class)->process($this->resultId));
    }
}
