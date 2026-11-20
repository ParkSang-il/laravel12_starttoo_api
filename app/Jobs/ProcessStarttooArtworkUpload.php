<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\ArtworkUploads;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 선업로드한 원본 사진을 백그라운드에서 변환(EXIF 제거·축소·재인코딩)한다. */
class ProcessStarttooArtworkUpload implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [5, 30];

    public function __construct(public string $uploadId)
    {
        // 기존 실시간 worker가 처리하는 큐를 함께 쓴다(별도 worker 설정 불필요).
        $this->onConnection('database')->onQueue('chat-push');
    }

    public function handle(ProjectDatabase $database): void
    {
        $database->run('starttoo', fn () => app(ArtworkUploads::class)->process($this->uploadId, $this->attempts() >= $this->tries));
    }

    public function failed(\Throwable $e): void
    {
        app(ProjectDatabase::class)->run('starttoo', fn () => app(ArtworkUploads::class)->markFailed($this->uploadId));
    }
}
