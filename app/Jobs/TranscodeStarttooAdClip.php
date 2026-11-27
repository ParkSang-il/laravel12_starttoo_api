<?php

namespace App\Jobs;

use App\Services\ProjectDatabase;
use App\Services\Starttoo\AdClips;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** 운영자 · 외부 광고 영상 변환(무빙과 같은 FFmpeg 변환). 미디어 worker 의 video-transcode 큐. */
class TranscodeStarttooAdClip implements ShouldQueue
{
    use Queueable;
    public int $tries = 2;
    public int $timeout = 600;
    public int $backoff = 30;

    public function __construct(public int $adId)
    {
        $this->onConnection('database')->onQueue('video-transcode');
    }

    public function handle(ProjectDatabase $database): void
    {
        $database->run('starttoo', fn () => app(AdClips::class)->process($this->adId, $this->attempts() >= $this->tries));
    }

    public function failed(\Throwable $e): void
    {
        app(ProjectDatabase::class)->run('starttoo', fn () => app(AdClips::class)->failed($this->adId, 'UNKNOWN'));
    }
}
