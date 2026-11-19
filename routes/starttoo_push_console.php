<?php

use App\Services\ProjectDatabase;
use App\Services\Starttoo\PushCampaigns;
use Illuminate\Support\Facades\Artisan;

Artisan::command('starttoo:push-info {--user=}', function () {
    return app(ProjectDatabase::class)->run('starttoo', function () {
        $db = app(ProjectDatabase::class)->database();
        $path = config('starttoo.fcm_credentials');
        $readable = is_string($path) && is_readable($path);
        $credentials = $readable ? json_decode(file_get_contents($path), true) : null;
        $tables = [];
        foreach (['starttoo_push_devices', 'starttoo_push_outbox', 'starttoo_push_logs', 'starttoo_push_campaigns'] as $table) {
            $tables[$table] = $db->getSchemaBuilder()->hasTable($table);
        }
        $result = [
            'push_enabled' => (bool) config('starttoo.push_enabled'), 'marketing_enabled' => (bool) config('starttoo.marketing_push_enabled'),
            'firebase_project' => config('starttoo.firebase_project_id'), 'credentials_readable' => $readable,
            'credentials_project_matches' => is_array($credentials) && ($credentials['project_id'] ?? null) === config('starttoo.firebase_project_id'),
            'credentials_email_present' => is_array($credentials) && !empty($credentials['client_email']),
            'credentials_private_key_present' => is_array($credentials) && !empty($credentials['private_key']), 'tables' => $tables,
        ];
        if ($tables['starttoo_push_devices'] && $this->option('user') !== null) {
            $user = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user === false) { $this->error('--user에는 회원 번호를 입력해주세요.'); return 1; }
            $result['devices'] = $db->table('starttoo_push_devices as d')->join('starttoo_auth_sessions as s', 's.id', '=', 'd.session_id')
                ->where('d.user_id', $user)->whereNull('s.revoked_at')->where('s.idle_expires_at', '>', now())->where('s.absolute_expires_at', '>', now())
                ->get(['d.id', 'd.platform', 'd.updated_at'])->all();
        }
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        return 0;
    });
});

// SSH 접근 권한을 가진 운영자 전용이다. 외부 회원 API로 광고 발송을 열지 않는다.
Artisan::command('starttoo:push-campaign {action} {id?} {--file=}', function () {
    try {
        return app(ProjectDatabase::class)->run('starttoo', function () {
            $action = $this->argument('action');
            $db = app(ProjectDatabase::class)->database();
            $service = app(PushCampaigns::class);
            if ($action === 'create') {
                $path = $this->option('file');
                if (!is_string($path) || !is_readable($path) || filesize($path) > 200000) throw new \InvalidArgumentException('읽을 수 있는 캠페인 JSON 파일 경로를 --file로 전달해주세요.');
                $data = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($data) || array_is_list($data)) throw new \InvalidArgumentException('JSON 객체가 필요해요.');
                $this->info('작성한 캠페인 UUID: '.$service->create($data));
                return 0;
            }
            $id = $this->argument('id');
            if (!is_string($id) || !\Illuminate\Support\Str::isUuid($id)) throw new \InvalidArgumentException('캠페인 UUID를 입력해주세요.');
            if ($action === 'status') {
                $campaign = $db->table('starttoo_push_campaigns')->where('id', $id)->first();
                if (!$campaign) throw new \InvalidArgumentException('캠페인을 찾을 수 없어요.');
                $counts = $db->table('starttoo_campaign_deliveries')->where('campaign_id', $id)->groupBy('state')->selectRaw('state, COUNT(*) AS total')->get()->all();
                $logs = $db->table('starttoo_push_logs')->where('source_type', 'campaign')->where('source_id', $id)->groupBy('result')->selectRaw('result, COUNT(*) AS total')->get()->all();
                $this->line(json_encode(['campaign' => $campaign, 'devices' => $counts, 'attempts_and_opens' => $logs], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                return 0;
            }
            $service->control($id, $action);
            $this->info('캠페인 상태를 변경했습니다.');
            return 0;
        });
    } catch (\InvalidArgumentException|\JsonException $e) {
        $this->error($e->getMessage());
        return 1;
    }
});
