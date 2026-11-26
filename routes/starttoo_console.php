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
    $this->line('app_realtime_host(앱→Reverb, 점 없는 내부 이름이면 API 도메인으로 대체)='.config('starttoo.realtime_host').':'.config('starttoo.realtime_port').' '.config('starttoo.realtime_scheme')
        .' consultation_enabled='.var_export(config('starttoo.consultation_enabled'), true).' push_enabled='.var_export(config('starttoo.push_enabled'), true));
    $this->line('queue_default='.config('queue.default'));
    $opt = config('broadcasting.connections.reverb.options', []);
    $this->line('서버→Reverb 주소='.($opt['scheme'] ?? '?').'://'.($opt['host'] ?? '?').':'.($opt['port'] ?? '?'));
    $client = app(\App\Services\Starttoo\Consultations::class)->realtime(0, 'www.starttoo.com');
    $this->line('앱에 알려줄 주소(www.starttoo.com 요청 기준)='.($client['host'] ?? 'null').':'.$client['port'].' enabled='.var_export($client['enabled'], true));
    // 서버→Reverb 이벤트 발송 시험(진단 전용 채널, 받는 사람 없음)
    try {
        app(\Illuminate\Broadcasting\BroadcastManager::class)->connection('reverb')
            ->broadcast(['starttoo.diagnose'], 'diagnose', ['at' => now()->toIso8601String()]);
        $this->line('서버→Reverb 발송 시험=OK');
    } catch (\Throwable $e) {
        // 요청 주소의 인증 쿼리(auth_key·서명)는 지우고 원인만 보여 준다.
        $this->error('서버→Reverb 발송 시험=실패: '.mb_substr(preg_replace('/\?\S*/', '', strtok($e->getMessage(), "\n")), 0, 200));
    }
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

// 실시간 종단 시험: 앱과 같은 순서(공개 주소 접속 → 채널 서명 → 구독 → 상담 이벤트 큐 발송 → 수신)를 서버에서 재현한다.
// 키·서명 값은 출력하지 않는다.
Artisan::command('starttoo:realtime-selftest {user : 시험할 회원 번호} {--host=www.starttoo.com} {--internal : chat-reverb:8080으로 직접 접속}', function () {
    $user = (int) $this->argument('user');
    $key = config('broadcasting.connections.reverb.key');
    if (!$key) { $this->error('REVERB_APP_KEY 없음'); return 1; }
    $internal = (bool) $this->option('internal');
    $host = $internal ? 'chat-reverb' : $this->option('host');
    $target = $internal ? "tcp://chat-reverb:8080" : "tls://{$host}:443";
    $step = fn (string $name, bool $ok, string $detail = '') => $ok ? $this->line("✔ $name $detail") : $this->error("✘ $name $detail");
    $sock = @stream_socket_client($target, $errno, $errstr, 8, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]));
    $step("1. 접속 $target", (bool) $sock, $sock ? '' : "$errno $errstr");
    if (!$sock) return 1;
    stream_set_timeout($sock, 10);
    $wsKey = base64_encode(random_bytes(16));
    fwrite($sock, "GET /app/{$key}?protocol=7&client=selftest&version=1.0 HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
        ."Sec-WebSocket-Key: {$wsKey}\r\nSec-WebSocket-Version: 13\r\n\r\n");
    $head = '';
    while (!str_contains($head, "\r\n\r\n") && !feof($sock)) $head .= fread($sock, 1);
    $step('2. 웹소켓 업그레이드', str_contains($head, ' 101 '), strtok($head, "\r\n"));
    if (!str_contains($head, ' 101 ')) return 1;
    $read = function () use ($sock): ?array {
        $h = fread($sock, 2);
        if (strlen($h) < 2) return null;
        $len = ord($h[1]) & 0x7f;
        if ($len === 126) $len = unpack('n', fread($sock, 2))[1];
        elseif ($len === 127) $len = unpack('J', fread($sock, 8))[1];
        $data = '';
        while (strlen($data) < $len && !feof($sock)) $data .= fread($sock, $len - strlen($data));
        return json_decode($data, true);
    };
    $send = function (array $msg) use ($sock) {
        $p = json_encode($msg); $len = strlen($p); $mask = random_bytes(4);
        $h = chr(0x81).($len < 126 ? chr(0x80 | $len) : chr(0x80 | 126).pack('n', $len)).$mask;
        $m = ''; for ($i = 0; $i < $len; $i++) $m .= $p[$i] ^ $mask[$i % 4];
        fwrite($sock, $h.$m);
    };
    $hello = $read();
    $socketId = json_decode($hello['data'] ?? '{}', true)['socket_id'] ?? null;
    $step('3. 연결 확인(connection_established)', (bool) $socketId, $socketId ? '' : json_encode($hello, JSON_UNESCAPED_UNICODE));
    if (!$socketId) return 1;
    $channel = "private-starttoo.user.{$user}";
    $auth = app(ProjectDatabase::class)->run('starttoo', fn () => app(\App\Services\Starttoo\Consultations::class)
        ->authorizeChannel($user, ['socket_id' => $socketId, 'channel_name' => $channel])['auth']);
    $send(['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth]]);
    $sub = $read();
    $step("4. 구독 $channel", ($sub['event'] ?? '') === 'pusher_internal:subscription_succeeded', $sub['event'] ?? json_encode($sub, JSON_UNESCAPED_UNICODE));
    if (($sub['event'] ?? '') !== 'pusher_internal:subscription_succeeded') return 1;
    event(new \App\Events\StarttooConsultationChanged([$user], 0, 'selftest'));
    $this->line('5. 상담 이벤트를 큐(chat-realtime)로 보냄 — 작업기가 Reverb로 전달하길 최대 15초 기다림');
    $until = microtime(true) + 15;
    while (microtime(true) < $until) {
        $msg = $read();
        if ($msg === null) continue;
        if (($msg['event'] ?? '') === 'pusher:ping') { $send(['event' => 'pusher:pong', 'data' => []]); continue; }
        if (($msg['event'] ?? '') === 'consultation.changed') {
            $step('6. 이벤트 수신', true, $msg['data'] ?? '');
            fclose($sock);
            return 0;
        }
    }
    $step('6. 이벤트 수신', false, '15초 안에 오지 않음: 큐 작업기(starttoo_chat_queue_rt)가 chat-realtime 큐를 처리하는지 확인');
    fclose($sock);
    return 1;
})->purpose('STARTTOO 실시간 종단 시험(비밀값 미출력)');

// 상담 건 점검: 대화방 수 · 건이 없는 대화방 · 상태별 건 수. --fix면 건이 없는 대화방을 현재 상태 그대로 한 건으로 만든다.
Artisan::command('starttoo:cases-check {--fix} {--user=}', function () {
    app(ProjectDatabase::class)->run('starttoo', function () {
        $db = app(ProjectDatabase::class)->database();
        $hasTable = $db->getSchemaBuilder()->hasTable('starttoo_consultation_cases');
        $this->line('cases 테이블='.($hasTable ? '있음' : '없음(마이그레이션 000037 필요)'));
        if (!$hasTable) return;
        $this->line('closed_from 칸='.($db->getSchemaBuilder()->hasColumn('starttoo_consultation_cases', 'closed_from') ? '있음' : '없음(000038 필요)'));
        $rooms = $db->table('starttoo_consultations')->count();
        $missing = $db->table('starttoo_consultations')->whereNull('current_case_id')->count();
        $this->line("대화방=$rooms · 건 없는 대화방=$missing · 전체 건=".$db->table('starttoo_consultation_cases')->count());
        foreach ($db->table('starttoo_consultation_cases')->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $this->line("  {$row->status}={$row->n}");
        }
        // --user=회원번호: 관리 목록 API가 그 회원에게 돌려주는 필터별 숫자(3-06 customer · 3-07 artist)
        if ($user = (int) $this->option('user')) {
            $c = app(\App\Services\Starttoo\Consultations::class);
            foreach (['customer', 'artist'] as $role) {
                $counts = $c->caseCounts($user, $role);
                $this->line("[$role] ".collect($counts)->map(fn ($n, $k) => "$k=$n")->implode(' '));
            }
        }
        if (!$missing || !$this->option('fix')) return;
        foreach ($db->table('starttoo_consultations')->whereNull('current_case_id')->orderBy('id')->get() as $room) {
            $db->transaction(function () use ($db, $room) {
                $caseId = $db->table('starttoo_consultation_cases')->insertGetId([
                    'consultation_id' => $room->id, 'customer_id' => $room->customer_id, 'artist_id' => $room->artist_id,
                    'status' => $room->status, 'created_at' => $room->created_at, 'updated_at' => $room->last_message_at ?? $room->updated_at,
                ]);
                $db->table('starttoo_consultations')->where('id', $room->id)->update(['current_case_id' => $caseId]);
                $db->table('starttoo_consultation_requests')->where('consultation_id', $room->id)->whereNull('case_id')->update(['case_id' => $caseId]);
                $db->table('starttoo_quotes')->where('consultation_id', $room->id)->whereNull('case_id')->update(['case_id' => $caseId]);
            });
        }
        $this->info("건 없는 대화방 {$missing}개에 건을 만들었어요.");
    });
})->purpose('상담 건 점검(개인정보 없이 숫자만)');

// Phase 4: 시술일 지난 확정 예약 → 자동 완료, 시간 지난 확정 대기 → 만료
Artisan::command('starttoo:bookings-sweep', function () {
    $r = app(ProjectDatabase::class)->run('starttoo', fn () => app(\App\Services\Starttoo\Bookings::class)->sweep());
    $this->info("자동 완료 {$r['done']}건 · 만료 {$r['expired']}건");
    return 0;
})->purpose('예약 자동 완료·만료');
\Illuminate\Support\Facades\Schedule::command('starttoo:bookings-sweep')->hourly()->withoutOverlapping();

// 자체 광고 관리. 자리(slot): community_top = 커뮤니티 피드 맨 위 카드
// 목록) php artisan starttoo:ad
// 등록) php artisan starttoo:ad add --slot=community_top --advertiser="잉크하우스" --title="12월 예약 오픈" --artist=12 --image=ads/a.jpg --from=2026-12-01 --until=2026-12-31
//       (--artist=작가 회원 번호 → 상점으로 이동 / 또는 --link=/community/post?id=3 → 앱 안 화면으로 이동)
// 끄기 · 켜기) php artisan starttoo:ad off 3   ·   php artisan starttoo:ad on 3
Artisan::command('starttoo:ad {action=list} {id?} {--slot=community_top} {--advertiser=} {--title=} {--artist=} {--link=} {--image=} {--from=} {--until=}', function () {
    $ads = app(\App\Services\Starttoo\Ads::class);
    $action = (string) $this->argument('action');
    try {
        return app(ProjectDatabase::class)->run('starttoo', function () use ($ads, $action) {
            if ($action === 'add') {
                $image = $this->option('image');
                if ($image && !str_starts_with($image, '/')) $image = base_path($image);
                $id = $ads->add(['image' => $image] + $this->options());
                $this->info("광고 {$id}번을 등록했어요.");
                return 0;
            }
            if (in_array($action, ['on', 'off'], true)) {
                $ok = $ads->setActive((int) $this->argument('id'), $action === 'on');
                $ok ? $this->info('광고를 '.($action === 'on' ? '켰어요.' : '껐어요.')) : $this->error('그 번호의 광고가 없어요.');
                return $ok ? 0 : 1;
            }
            $rows = array_map(fn ($a) => [
                $a->id, $a->slot, $a->active ? '켜짐' : '꺼짐', $a->advertiser, $a->title,
                $a->artist_id ? "상점 {$a->artist_id}" : (string) $a->link,
                ($a->starts_at ? substr((string) $a->starts_at, 0, 10) : '바로').' ~ '.($a->ends_at ? substr((string) $a->ends_at, 0, 10) : '계속'),
                $a->impressions, $a->clicks,
            ], $ads->all());
            $this->table(['번호', '자리', '상태', '광고주', '문구', '이동', '기간', '노출', '누름'], $rows);
            return 0;
        });
    } catch (\InvalidArgumentException $e) {
        $this->error($e->getMessage());
        return 1;
    }
})->purpose('자체 광고 등록 · 목록 · 끄기');

// 가상 피팅: 가상 모델 피부 영역 미리 만들기(앱에서 처음 열 때 기다리지 않게). --redo = 전부 다시
// 예) php artisan starttoo:fit-model-skins
Artisan::command('starttoo:fit-model-skins {--redo}', function () {
    $r = app(ProjectDatabase::class)->run('starttoo', fn () => app(\App\Services\Starttoo\Fittings::class)
        ->buildModelSkins((bool) $this->option('redo'), function ($m, bool $ok) {
            $this->line(($ok ? '완료 ' : '실패 ')."{$m->id} · {$m->part} · {$m->face} · ".($m->gender ?? '-'));
        }));
    $this->info("피부 영역 만듦 {$r[0]}개 · 실패 {$r[1]}개 (실패는 로그 「Fit skin」 확인)");
    return 0;
})->purpose('가상 모델 피부 영역 미리 만들기');

// 가상 피팅: 운영자가 올린 모델 사진 등록. 폴더 {dir}/{부위}/{면}/파일.jpg (파일 이름 규칙은 Fittings::importModels)
// 예) php artisan starttoo:fit-models-import fit-models   ·   끄기: --off=파일이름일부 또는 번호
Artisan::command('starttoo:fit-models-import {dir=fit-models} {--off=}', function () {
    $F = app(\App\Services\Starttoo\Fittings::class);
    if ($off = $this->option('off')) {
        $n = app(ProjectDatabase::class)->run('starttoo', fn () => $F->deactivateModels((string) $off));
        $this->info("모델 사진 {$n}장을 목록에서 뺐어요.");
        return 0;
    }
    $dir = str_starts_with($this->argument('dir'), '/') ? $this->argument('dir') : base_path($this->argument('dir'));
    if (!is_dir($dir)) {
        $this->error("폴더가 없어요: {$this->argument('dir')}");
        return 1;
    }
    $r = app(ProjectDatabase::class)->run('starttoo', fn () => $F->importModels($dir));
    $this->info("등록 {$r['added']}장 · 이미 있음 {$r['skipped']}장");
    foreach ($r['unknown'] as $u) $this->warn("건너뜀(부위/면 폴더 확인): {$u}");
    $counts = app(ProjectDatabase::class)->run('starttoo', fn () => app(ProjectDatabase::class)->database()
        ->table('starttoo_fit_models')->where('status', 'ready')->where('is_active', true)
        ->selectRaw('part, face, count(*) as n')->groupBy('part', 'face')->get());
    foreach ($counts as $c) $this->line("  {$c->part} · {$c->face}: {$c->n}장");
    return 0;
})->purpose('가상 피팅 모델 사진 등록');

// 가상 피팅: 가상 모델 사진 미리 생성(나노바나나, 조건당 4장). 조건을 비우면 전체 조합.
// 예) php artisan starttoo:fit-models --part=전완 --tone=2 --build=보통   ·   --retry 실패한 사진 다시 생성   ·   --dry 개수만
// --one: 조건당 1장(정면 · 자연광)만. 기본 세트 예) --gender=female --tone=0 --build=보통 --one → 26장
// --gender=female|male (기본 female)
// --redo: 이미 있는 조건의 사진을 같은 자리에 다시 생성(예: --gender=female --part=등 --redo)
// --uploaded: --redo 할 때 운영자가 올린 사진(인터넷 사진 등)도 AI 사진으로 바꾼다(기본은 AI로 만든 사진만)
Artisan::command('starttoo:fit-models {--gender=female} {--part=} {--face=} {--tone=} {--build=} {--one} {--redo} {--uploaded} {--retry} {--dry}', function () {
    $F = \App\Services\Starttoo\Fittings::class;
    $r = app(ProjectDatabase::class)->run('starttoo', function () use ($F) {
        $db = app(ProjectDatabase::class)->database();
        if ($this->option('retry')) {
            $ids = $db->table('starttoo_fit_models')->where('status', 'failed')->pluck('id');
            if (!$this->option('dry')) {
                $db->table('starttoo_fit_models')->whereIn('id', $ids)->update(['status' => 'pending', 'updated_at' => now()]);
                foreach ($ids as $id) app($F)->dispatchModel((int) $id);
            }
            return ['combos' => 0, 'queued' => count($ids)];
        }
        $combos = 0;
        $queued = 0;
        foreach ($F::PARTS as $part => $def) {
            if ($this->option('part') && $this->option('part') !== $part) continue;
            foreach (array_keys($def['faces']) as $face) {
                if ($this->option('face') && $this->option('face') !== $face) continue;
                foreach (array_keys($F::TONES) as $tone) {
                    if ($this->option('tone') !== null && (int) $this->option('tone') !== $tone) continue;
                    foreach (array_keys($F::BUILDS) as $build) {
                        if ($this->option('build') && $this->option('build') !== $build) continue;
                        $gender = in_array($this->option('gender'), ['female', 'male'], true) ? $this->option('gender') : 'female';
                        $q = compact('part', 'face', 'gender', 'tone', 'build');
                        if ($db->table('starttoo_fit_models')->where($q)->exists()) {
                            if (!$this->option('redo')) continue;
                            $sel = $db->table('starttoo_fit_models')->where($q);
                            if (!$this->option('uploaded')) $sel->whereNull('source_hash');
                            $ids = $sel->pluck('id');
                            $combos++;
                            $queued += count($ids);
                            if (!$this->option('dry')) {
                                // 올린 사진을 AI로 바꾸면 「올린 사진」 표시를 지운다(같은 파일을 다시 올릴 수 있게)
                                $db->table('starttoo_fit_models')->whereIn('id', $ids)->update(['status' => 'pending', 'updated_at' => now()]
                                    + ($this->option('uploaded') ? ['source_hash' => null, 'source_name' => null] : []));
                                foreach ($ids as $id) app($F)->dispatchModel((int) $id);
                            }
                            continue;
                        }
                        // --redo는 있는 사진만 다시 만든다. 없는 조건(다른 톤 · 체형)을 새로 만들지 않는다.
                        if ($this->option('redo')) continue;
                        $combos++;
                        $n = $this->option('one') ? 1 : count($F::VARIANTS);
                        $queued += $this->option('dry') ? $n : app($F)->queueModels($q, $n);
                    }
                }
            }
        }
        return compact('combos', 'queued');
    });
    $this->info(($this->option('dry') ? '[미리보기] ' : '')."조건 {$r['combos']}개 · 사진 {$r['queued']}장 생성 대기");
    return 0;
})->purpose('가상 피팅 모델 사진 생성');
