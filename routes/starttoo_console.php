<?php

use App\Services\ProjectDatabase;
use App\Services\Starttoo\Reports;
use Illuminate\Support\Facades\Artisan;

// 서버 쉘 권한이 있는 운영자만 실행한다. 회원 토큰용 관리자 API는 제공하지 않는다.
Artisan::command('starttoo:reports {--status=pending} {--limit=50} {--id=}', function () {
    return app(ProjectDatabase::class)->run('starttoo', function () {
        $db = app(ProjectDatabase::class)->database();
        if ($this->option('id')) {
            $id = filter_var($this->option('id'), FILTER_VALIDATE_INT);
            if (!$id || $id < 1) { $this->error('신고 번호를 확인해주세요.'); return 1; }
            $row = $db->table('starttoo_reports')->where('id', $id)->first();
            if (!$row) { $this->error('신고를 찾을 수 없습니다.'); return 1; }
            $this->line(json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return 0;
        }
        $status = $this->option('status');
        if (!in_array($status, ['pending', 'resolved', 'dismissed'], true)) { $this->error('상태를 확인해주세요.'); return 1; }
        $rows = $db->table('starttoo_reports')->where('status', $status)->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))
            ->get(['id', 'target_type', 'target_id', 'reason', 'reporter_id', 'created_at'])->map(fn ($row) => (array) $row)->all();
        $this->table(['번호', '대상 종류', '대상 ID', '사유', '신고자', '접수 시각'], $rows);
        return 0;
    });
})->purpose('List STARTTOO reports for server operators');

Artisan::command('starttoo:moderate {report} {action} {--operator=} {--reason=}', function () {
    $id = filter_var($this->argument('report'), FILTER_VALIDATE_INT);
    if (!$id || $id < 1) { $this->error('신고 번호를 확인해주세요.'); return 1; }
    try {
        $result = app(ProjectDatabase::class)->run('starttoo', fn () => app(Reports::class)->moderate($id, $this->argument('action'),
            (string) $this->option('operator'), (string) $this->option('reason')));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return 0;
    } catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
})->purpose('Hide/release reported content or dismiss a report, recording an operator audit');

// 기존 게시물이 스튜디오/상점에서 누락될 때 사용한다. 데이터 변경 없이 개수와 조회 결과만 확인한다.
Artisan::command('starttoo:studio-info {--user=}', function () {
    $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) { $this->error('--user=회원번호를 지정해주세요.'); return 1; }
    return app(ProjectDatabase::class)->run('starttoo', function () use ($userId) {
        $db = app(ProjectDatabase::class)->database();
        $schema = $db->getSchemaBuilder();
        foreach (['starttoo_users' => ['artist_status', 'artist_approved_at'],
            'starttoo_artworks' => ['visibility', 'show_in_shop', 'moderation_hidden', 'design_status'],
            'starttoo_artwork_images' => ['artwork_id', 'path'],
            'starttoo_shops' => ['business_hours', 'guide']] as $table => $columns) {
            if (!$schema->hasTable($table) || !$schema->hasColumns($table, $columns)) {
                $this->error('스키마 누락: '.$table.' ('.implode(', ', $columns).')');
                return 1;
            }
        }
        $user = $db->table('starttoo_users')->where('id', $userId)->first(['artist_status', 'artist_approved_at']);
        if (!$user) { $this->error('회원을 찾을 수 없어요.'); return 1; }
        $base = $db->table('starttoo_artworks as a')->where('a.user_id', $userId);
        $this->table(['항목', '값'], [
            ['회원 번호', $userId], ['아티스트 상태', $user->artist_status ?? '일반 회원'],
            ['상점 존재', $db->table('starttoo_shops')->where('user_id', $userId)->exists() ? '예' : '아니오'],
            ['전체 작품·도안', (clone $base)->count()],
            ['승인 전 작품·도안', $user->artist_approved_at ? (clone $base)->where('a.created_at', '<=', $user->artist_approved_at)->count() : '미승인'],
            ['사진 없는 작품·도안', (clone $base)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('starttoo_artwork_images as i')->whereColumn('i.artwork_id', 'a.id'))->count()],
        ]);
        $groups = (clone $base)->select('a.kind', 'a.visibility', 'a.show_in_shop', 'a.moderation_hidden')
            ->selectRaw('COUNT(*) as total')->groupBy('a.kind', 'a.visibility', 'a.show_in_shop', 'a.moderation_hidden')->get();
        $this->table(['유형', '공개 상태', '상점 노출', '운영자 숨김', '개수'],
            $groups->map(fn ($r) => [$r->kind, $r->visibility, (int) $r->show_in_shop, (int) $r->moderation_hidden, $r->total])->all());
        $failed = false;
        foreach (['studio:work', 'studio:design', 'shop:work', 'shop:design'] as $scope) {
            [$screen, $kind] = explode(':', $scope);
            try {
                $result = $screen === 'studio'
                    ? app(\App\Services\Starttoo\Studios::class)->artworks($userId, ['kind' => $kind])
                    : app(\App\Services\Starttoo\Shops::class)->portfolio($userId, $userId, ['kind' => $kind]);
                $this->line($scope.': '.count($result['items']).'개 (첫 페이지)');
            } catch (\Throwable $error) {
                $failed = true;
                $trace = (string) \Illuminate\Support\Str::uuid();
                \Illuminate\Support\Facades\Log::warning('STARTTOO studio-info 조회 실패', ['trace' => $trace, 'scope' => $scope, 'user_id' => $userId, 'exception' => $error]);
                // 출력에는 개인정보·서명 URL·SQL 바인딩을 포함하지 않는다.
                $this->error($scope.': 조회 실패 ('.get_class($error).', 로그 추적 번호 '.$trace.')');
            }
        }
        return $failed ? 1 : 0;
    });
})->purpose('Inspect STARTTOO studio/shop artwork counts without changing any data');

// 2-13 아티스트 신청 심사 (관리자 웹 전까지 서버 명령으로 처리)
Artisan::command('starttoo:artist-applications {--status=} {--limit=50} {--id=}', function () {
    return app(ProjectDatabase::class)->run('starttoo', function () {
        $service = app(\App\Services\Starttoo\ArtistApplications::class);
        if ($this->option('id')) {
            $detail = $service->operatorDetail((int) $this->option('id'));
            if (!$detail) { $this->error('신청서를 찾을 수 없어요.'); return 1; }
            $this->line(json_encode($detail, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            return 0;
        }
        $status = $this->option('status') ?: null;
        if ($status && !in_array($status, \App\Services\Starttoo\ArtistApplications::STATUSES, true)) { $this->error('상태를 확인해주세요.'); return 1; }
        $rows = $service->operatorList($status, max(1, min(100, (int) $this->option('limit'))));
        $this->table(['번호', '회원', '닉네임', '활동명', '지역', '상태', '제출 회차', '제출 시각'], $rows);
        return 0;
    });
})->purpose('List STARTTOO artist applications (use --id for detail including real name)');

Artisan::command('starttoo:artist-review {application} {decision} {--operator=} {--note=}', function () {
    $id = filter_var($this->argument('application'), FILTER_VALIDATE_INT);
    if (!$id || $id < 1) { $this->error('신청서 번호를 확인해주세요.'); return 1; }
    try {
        $result = app(ProjectDatabase::class)->run('starttoo', fn () => app(\App\Services\Starttoo\ArtistApplications::class)
            ->review($id, (string) $this->argument('decision'), (string) $this->option('operator'), $this->option('note')));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return 0;
    } catch (\InvalidArgumentException $e) { $this->error($e->getMessage()); return 1; }
})->purpose('Review an artist application: reviewing | needs_changes | approved | rejected');

// 공개 지도 좌표 흐림 기준이 바뀌었을 때 저장된 상점의 공개 좌표를 다시 계산한다(외부 API 호출 없음).
Artisan::command('starttoo:shops-reblur', function () {
    return app(ProjectDatabase::class)->run('starttoo', function () {
        $db = app(ProjectDatabase::class)->database();
        $studios = app(\App\Services\Starttoo\Studios::class);
        $count = 0;
        foreach ($db->table('starttoo_shops')->whereNotNull('lat')->whereNotNull('lng')->get(['user_id', 'lat', 'lng']) as $shop) {
            [$lat, $lng] = $studios->blur((int) $shop->user_id, (float) $shop->lat, (float) $shop->lng);
            $db->table('starttoo_shops')->where('user_id', $shop->user_id)->update(['map_lat' => $lat, 'map_lng' => $lng]);
            $count++;
        }
        $this->info("공개 좌표를 다시 계산했습니다: {$count}개 상점");
        return 0;
    });
})->purpose('STARTTOO 상점 공개 지도 좌표 재계산');

// 상담·채팅 진단: 실시간·큐·푸시·상점 상태. 키·토큰·메시지 내용은 출력하지 않는다.
Artisan::command('starttoo:chat-diagnose', function () {
    $set = fn ($v) => $v ? 'SET' : 'MISSING';
    $this->info('[설정]');
    $this->line('broadcast='.config('broadcasting.default').' reverb_key='.$set(config('broadcasting.connections.reverb.key'))
        .' reverb_secret='.$set(config('broadcasting.connections.reverb.secret')).' reverb_host(서버→Reverb)='.config('broadcasting.connections.reverb.options.host'));
    $this->line('app_realtime_host(앱→Reverb)='.config('starttoo.realtime_host').':'.config('starttoo.realtime_port').' '.config('starttoo.realtime_scheme')
        .' consultation_enabled='.var_export(config('starttoo.consultation_enabled'), true).' push_enabled='.var_export(config('starttoo.push_enabled'), true));
    $this->line('queue_default='.config('queue.default'));
    $this->info('[큐 대기·실패]');
    try {
        // 이벤트·푸시 작업은 database 큐 연결(별도 DB일 수 있음)에 쌓인다.
        $queueDb = \Illuminate\Support\Facades\DB::connection(config('queue.connections.database.connection'));
        $table = config('queue.connections.database.table', 'jobs');
        foreach (['chat-realtime', 'chat-push'] as $q) {
            $this->line("$q 대기=".$queueDb->table($table)->where('queue', $q)->count());
        }
        $failed = \Illuminate\Support\Facades\DB::connection(config('queue.failed.database') ?: config('queue.connections.database.connection'))
            ->table(config('queue.failed.table', 'failed_jobs'));
        foreach ($failed->whereIn('queue', ['chat-realtime', 'chat-push'])->orderByDesc('id')->limit(3)->get(['queue', 'failed_at', 'exception']) as $f) {
            $this->line("실패 {$f->failed_at} {$f->queue}: ".mb_substr(strtok($f->exception, "\n"), 0, 160));
        }
    } catch (\Throwable $e) { $this->warn('큐 테이블 확인 실패: '.$e->getMessage()); }
    return app(ProjectDatabase::class)->run('starttoo', function () {
        $db = app(ProjectDatabase::class)->database();
        $this->info('[최근 상담방]');
        foreach ($db->table('starttoo_consultations as c')->leftJoin('starttoo_shops as s', 's.user_id', '=', 'c.artist_id')
            ->orderByDesc('c.updated_at')->limit(5)->get(['c.id', 'c.customer_id', 'c.artist_id', 'c.status', 's.consult_status', 'c.last_message_id', 'c.customer_last_read_id', 'c.artist_last_read_id']) as $r) {
            $this->line("방 {$r->id}: 고객 {$r->customer_id} → 작가 {$r->artist_id} 상태={$r->status} 상점상담={$r->consult_status} 마지막={$r->last_message_id} 읽음(고객/작가)={$r->customer_last_read_id}/{$r->artist_last_read_id}");
        }
        $this->info('[채팅 푸시 발송함]');
        foreach ($db->table('starttoo_push_outbox')->whereNotNull('message_id')->orderByDesc('id')->limit(5)->get() as $b) {
            $states = $db->table('starttoo_push_deliveries')->where('outbox_id', $b->id)->pluck('state')->implode(',');
            $this->line("발송함 {$b->id} 메시지 {$b->message_id} 펼침=".($b->expanded_at ?? '아직')." 기기별=[{$states}]");
        }
        $this->info('[채팅 푸시 결과]');
        foreach ($db->table('starttoo_push_logs')->where('source_type', 'chat')->orderByDesc('id')->limit(5)->get(['created_at', 'platform', 'result', 'reason', 'http_status']) as $l) {
            $this->line("{$l->created_at} {$l->platform} {$l->result} {$l->reason} {$l->http_status}");
        }
        $this->line('등록 기기 수='.$db->table('starttoo_push_devices')->count());
        return 0;
    });
})->purpose('STARTTOO 상담·채팅 상태 진단(비밀값 미출력)');
