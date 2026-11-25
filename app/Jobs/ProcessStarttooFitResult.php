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

    /** 강제 종료(메모리 · 시간 초과)로 끝나면 처리 중으로 남지 않게 실패 처리 */
    public function failed(\Throwable $e): void
    {
        app(ProjectDatabase::class)->run('starttoo', fn () => app(Fittings::class)->markFailed($this->resultId));
    }
}
