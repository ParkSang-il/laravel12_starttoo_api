<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\VideoTranscoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 무빙(동영상) 변환: 확인(ffprobe) → HLS 변환(ffmpeg) → 포스터 → (자동 게시면) 게시.
 * 미디어 worker(starttoo_chat_queue_media)가 video-transcode 큐도 같이 처리한다.
 * 90초 영상 CPU 변환은 보통 1~3분. 한 번 실패하면 1회 다시 시도한다.
 * ※ 큐 연결의 retry_after(DB_QUEUE_RETRY_AFTER)가 제한 시간(600초)보다 길어야 처리 중에 다시 집어 가지 않는다.
 */
class TranscodeStarttooVideo implements ShouldQueue
{
    use Queueable;
    public int $tries = 2;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(public string $videoId)
    {
        $this->onConnection('database')->onQueue('video-transcode');
    }

    public function handle(ProjectDatabase $database): void
    {
        $database->run('starttoo', fn () => app(VideoTranscoder::class)->process($this->videoId, $this->attempts() >= $this->tries));
    }

    /** 강제 종료(메모리 · 시간 초과)로 끝나면 처리 중으로 남지 않게 실패 확정 */
    public function failed(\Throwable $e): void
    {
        app(ProjectDatabase::class)->run('starttoo', fn () => app(VideoTranscoder::class)->markFailed($this->videoId));
    }
}
