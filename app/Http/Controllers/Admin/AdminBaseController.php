<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ProjectDatabase;
use App\Services\Starttoo\AdminAccounts;
use App\Services\Starttoo\AdminAudit;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

abstract class AdminBaseController extends Controller
{
    protected const PER_PAGE = 20;

    protected function db() { return app(ProjectDatabase::class)->database(); }

    protected function admin(Request $request): object
    {
        return $request->attributes->get('admin');
    }

    protected function can(Request $request, string $ability): bool
    {
        return app(AdminAccounts::class)->can($this->admin($request), $ability);
    }

    /** 권한이 없으면 처리하지 않는다(버튼도 숨기지만, 주소를 직접 보내도 막는다). */
    protected function need(Request $request, string $ability): void
    {
        abort_unless($this->can($request, $ability), 403, '이 처리를 할 권한이 없어요.');
    }

    protected function audit(Request $request, string $category, string $action, string $target = '-', ?array $changes = null): void
    {
        app(AdminAudit::class)->record($this->admin($request), $category, $action, $target, $request->ip(), $changes);
    }

    protected function page(Request $request): int
    {
        return max(1, min(100000, (int) $request->query('page', 1)));
    }

    /**
     * 엑셀에서 바로 열리는 CSV(UTF-8 BOM).
     * $rows 는 미리 만든 배열이어야 한다: 내려받기 본문은 미들웨어(스타투 DB 문맥)가 끝난 뒤에 쓰인다.
     */
    protected function csv(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $row) {
                // 수식으로 해석되지 않게(=, +, -, @ 로 시작하는 값)
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
