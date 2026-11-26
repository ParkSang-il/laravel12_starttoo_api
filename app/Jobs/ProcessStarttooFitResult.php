<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\Fittings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 가상 피팅 AI 합성(나노바나나). 미디어 큐 worker에서 처리한다.
 * 상위 모델(Pro)은 1~2분 걸려 제한 시간을 180초로 둔다.
 * ※ 큐 연결의 retry_after(DB_QUEUE_RETRY_AFTER)가 이보다 길어야 처리 중에 다시 집어 가지 않는다.
 */
class ProcessStarttooFitResult implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 180;

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
