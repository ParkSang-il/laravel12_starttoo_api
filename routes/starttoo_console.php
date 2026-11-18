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
