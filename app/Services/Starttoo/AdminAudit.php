<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Illuminate\Support\Carbon;

/**
 * 운영 콘솔 감사 로그. 접속 · 실패 · 모든 처리를 남긴다.
 * 구분: 접속 · 심사 · 제재 · 콘텐츠 · 설정. 비밀값(비밀번호 · 인증 코드 · 토큰)은 절대 넣지 않는다.
 */
class AdminAudit
{
    public const CATEGORIES = ['접속', '심사', '제재', '콘텐츠', '설정'];

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function record(?object $admin, string $category, string $action, string $target = '-', ?string $ip = null, ?array $changes = null): void
    {
        $this->db()->table('starttoo_admin_audit_logs')->insert([
            'admin_id' => $admin?->id, 'admin_name' => mb_substr($admin?->name ?? '알 수 없음', 0, 50),
            'category' => in_array($category, self::CATEGORIES, true) ? $category : '설정',
            'action' => mb_substr($action, 0, 100), 'target' => mb_substr($target === '' ? '-' : $target, 0, 200),
            'ip' => $ip ?? request()->ip(),
            'changes' => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** 목록(최신 순). category = null 이면 전체 */
    public function query(?string $category)
    {
        return $this->db()->table('starttoo_admin_audit_logs')
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderByDesc('id');
    }

    public function row(object $l): array
    {
        return [
            't' => Carbon::parse($l->created_at)->timezone('Asia/Seoul')->format('m.d H:i:s'),
            'who' => $l->admin_name, 'cat' => $l->category, 'what' => $l->action, 'target' => $l->target, 'ip' => $l->ip ?? '-',
        ];
    }
}
