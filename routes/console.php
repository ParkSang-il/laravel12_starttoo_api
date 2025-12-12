<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 포트폴리오 통계 업데이트 스케줄 (매일 새벽 2시에 실행)
Schedule::command('portfolio:update-stats')
    ->dailyAt('02:00')
    ->timezone('Asia/Seoul')
    ->description('포트폴리오 최근 통계 업데이트 (최근 7일)');
