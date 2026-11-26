<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;

/**
 * 회원 제재(운영 콘솔 신고 처리).
 * - 경고: 이력만 남긴다.
 * - 기간 정지(7 · 30일): status suspended + suspended_until. 끝나면 로그인할 때 · 매시간 정리에서 풀린다.
 * - 영구 정지: status suspended, suspended_until 없음.
 * - 정지하면 모든 기기 로그인 · 푸시 기기를 끊는다. 공개 목록은 active 회원만 보여 주므로 바로 안 보인다.
 * - 탈퇴한 회원은 정지하지 않는다(이력만 남김).
 */
class Sanctions
{
    public const PENALTIES = ['경고' => ['warning', null], '7일 정지' => ['suspend', 7], '30일 정지' => ['suspend', 30], '영구 정지' => ['ban', null]];

    private function db() { return app(ProjectDatabase::class)->database(); }

    public function apply(int $userId, string $penalty, string $reason, ?int $reportId, ?int $adminId): array
    {
        if (!isset(self::PENALTIES[$penalty])) throw new \InvalidArgumentException('제재 단계를 확인해주세요.');
        [$kind, $days] = self::PENALTIES[$penalty];
        $db = $this->db();
        return $db->transaction(function () use ($db, $userId, $kind, $days, $reason, $reportId, $adminId) {
            $user = $db->table('starttoo_users')->where('id', $userId)->lockForUpdate()->first();
            if (!$user) throw new \InvalidArgumentException('회원을 찾을 수 없어요.');
            $until = $days ? now()->addDays($days) : null;
            $suspend = $kind !== 'warning' && $user->status !== 'withdrawn';
            if ($suspend) {
                // 더 긴 정지가 이미 걸려 있으면 줄이지 않는다
                $current = $user->status === 'suspended' ? ($user->suspended_until ? \Illuminate\Support\Carbon::parse($user->suspended_until) : 'ban') : null;
                if ($current !== 'ban' && !($current && $until && $current->gt($until))) {
                    $db->table('starttoo_users')->where('id', $userId)->update([
                        'status' => 'suspended', 'suspended_until' => $kind === 'ban' ? null : $until, 'updated_at' => now(),
                    ]);
                }
                $db->table('starttoo_push_devices')->where('user_id', $userId)->delete();
                $db->table('starttoo_auth_sessions')->where('user_id', $userId)->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'previous_result' => null, 'updated_at' => now()]);
            }
            $sanctionId = $db->table('starttoo_user_sanctions')->insertGetId([
                'user_id' => $userId, 'kind' => $kind, 'days' => $days, 'until' => $kind === 'suspend' ? $until : null,
                'reason' => mb_substr(trim($reason), 0, 1000), 'report_id' => $reportId, 'admin_id' => $adminId, 'created_at' => now(),
            ]);
            // 작성자에게 제재 안내(알림함 · 푸시). 정지는 기기 연결을 끊어 푸시는 가지 않고, 정지가 풀린 뒤 알림함에 보인다.
            if ($user->status !== 'withdrawn') {
                app(Notifications::class)->notice($userId, 'sanction', 'sanction', (string) $sanctionId, $kind);
            }
            return ['user_id' => $userId, 'kind' => $kind, 'until' => $until?->toIso8601String(), 'applied' => $kind === 'warning' || $suspend];
        }, 3);
    }

    /** 기간이 끝난 정지를 푼다(로그인 시 해당 회원, 매시간 전체). */
    public function liftExpired(?int $userId = null): int
    {
        $q = $this->db()->table('starttoo_users')->where('status', 'suspended')->whereNotNull('suspended_until')->where('suspended_until', '<=', now());
        if ($userId !== null) $q->where('id', $userId);
        $ids = $q->pluck('id')->all();
        if (!$ids) return 0;
        $this->db()->table('starttoo_users')->whereIn('id', $ids)->where('status', 'suspended')
            ->update(['status' => 'active', 'suspended_until' => null, 'updated_at' => now()]);
        foreach ($ids as $id) {
            $this->db()->table('starttoo_user_sanctions')->insert(['user_id' => $id, 'kind' => 'lift', 'reason' => '정지 기간 종료', 'created_at' => now()]);
        }
        return count($ids);
    }
}
