<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;

/**
 * 작가 광고 환불 계산(운영 콘솔 「광고 · 프리미엄 → 환불 계산」). 계산만 하고 돈은 움직이지 않는다.
 * 기준은 광고 운영 정책 「12. 환불」 · 「13. 서비스 장애 보상」. 정책의 숫자를 바꾸면 여기 상수도 같이 바꾼다.
 * 결제(PG)가 아직 없어 결제 금액 = 광고비(amount, 부가세 별도) + 부가세로 본다.
 */
class ArtistAdRefunds
{
    /** 한 달 단위: 시작일 N일 전까지 취소하면 전액 */
    public const FULL_CANCEL_DAYS = 7;
    /** 한 달 단위: 그 뒤 시작 전 취소는 이 비율만 환불(나머지는 취소 수수료) */
    public const LATE_CANCEL_RATE = 0.9;
    public const MAX_OUTAGE_DAYS = 31;

    public const REASONS = [
        'artist' => '광고주가 끝냄 · 탈퇴',
        'service' => '서비스 사정(서비스 종료 · 상품 폐지)',
        'outage' => '서비스 장애로 노출 안 됨(기간을 늘릴 수 없을 때)',
        'violation' => '정책 · 약관 위반으로 내림',
    ];

    private const DAY = 86400;

    private function db() { return app(ProjectDatabase::class)->database(); }

    /**
     * @param  Carbon|null  $at  끝내는 시각(비우면 지금). 이미 끝난 광고는 끝낸 시각을 쓴다.
     * @return array{ad: array, rows: list<array{0: string, 1: string}>, refund: int, rule: string, notes: list<string>}
     */
    public function calculate(int $id, string $reason, ?Carbon $at = null, int $outageDays = 0): array
    {
        if (!isset(self::REASONS[$reason])) throw new \InvalidArgumentException('환불 사유를 골라주세요.');
        $ad = $this->db()->table('starttoo_ads as ad')->leftJoin('starttoo_users as u', 'u.id', '=', 'ad.artist_id')
            ->select('ad.*', 'u.nickname as artist_nickname')->where('ad.id', $id)->whereNotNull('ad.product')->first();
        if (!$ad) throw new \InvalidArgumentException("작가 광고 #{$id}를 찾을 수 없어요(운영자 광고는 환불 대상이 아니에요).");

        $product = ArtistAds::PRODUCTS[$ad->product] ?? ['name' => $ad->product, 'unit' => 'day'];
        $monthly = $product['unit'] === 'month';
        $amount = (int) $ad->amount;
        $paid = $amount + (int) floor($amount * ArtistAds::VAT_RATE);
        $start = $ad->starts_at ? Carbon::parse($ad->starts_at) : null;
        $end = $ad->ends_at ? Carbon::parse($ad->ends_at) : null;
        $ended = $ad->status === 'ended';
        // 이미 끝낸 광고: 끝낸 시각(마지막 변경). 멈춰 있는 광고: 멈춘 뒤로는 쓰지 않은 시간이다.
        $ref = $ended ? Carbon::parse($ad->updated_at) : ($at ?? now());
        $usedRef = $ref;
        if (!$ended && $ad->paused_at) {
            $pausedAt = Carbon::parse($ad->paused_at);
            $usedRef = $start && $start->gt($pausedAt) ? $start : $pausedAt;
        }

        $notes = [];
        $total = $monthly ? ($start?->daysInMonth ?? 30) : max(1, (int) $ad->days);
        $perDay = $paid / $total;
        $rows = [
            ['광고', "#{$ad->id} · " . ($product['name'] ?? $ad->product) . ' · ' . ($monthly ? '한 달 단위' : '하루 단위')],
            ['광고주', $ad->advertiser . ($ad->artist_nickname ? ' · ' . $ad->artist_nickname : '')],
            ['상태', $this->stateLabel($ad)],
            ['노출 기간', ($start ? $start->format('Y.m.d H:i') : '-') . ' ~ ' . ($end ? $end->format('Y.m.d H:i') : '-') . ($ended ? ' (끝낸 시각)' : '')],
            ['결제 금액', $this->won($paid) . ' (광고비 ' . $this->won($amount) . ' + 부가세 ' . $this->won($paid - $amount) . ')'],
            [$monthly ? '그 달 날수' : '결제한 일수', $total . '일 · 하루 ' . $this->won((int) floor($perDay))],
            ['기준 시각', $ref->format('Y.m.d H:i') . ($ended ? ' (광고를 끝낸 시각)' : ($ad->paused_at ? ' · ' . Carbon::parse($ad->paused_at)->format('m.d H:i') . '부터 멈춤' : ''))],
        ];

        $result = fn (int $refund, string $rule) => [
            'ad' => ['id' => (int) $ad->id, 'paid' => $paid],
            'rows' => $rows, 'refund' => max(0, min($paid, $refund)), 'rule' => $rule, 'notes' => $notes,
        ];

        if (in_array($ad->status, ['reviewing', 'rejected'], true) || !$start) {
            return $result(0, '심사 중 · 반려 광고는 결제 전이라 청구된 광고비가 없어요.');
        }
        if ($reason === 'violation') {
            $notes[] = '위반이 아닌 것으로 확인되면 사유를 「서비스 사정」으로 바꿔 다시 계산하거나, 멈춘 기간만큼 노출 기간을 늘려 주세요.';
            return $result(0, '정책 · 약관 위반으로 내린 광고는 환불하지 않아요(정책 12. 환불하지 않는 경우).');
        }
        if ($reason === 'outage') {
            if ($outageDays < 1) throw new \InvalidArgumentException('노출되지 않은 일수를 적어주세요.');
            $days = min($outageDays, $total);
            $rows[] = ['노출 안 된 일수', $days . '일'];
            $notes[] = '기간을 늘려 줄 수 있으면 환불 대신 노출 기간을 늘리는 것이 먼저예요(정책 13).';
            return $result((int) floor($perDay * $days), "노출되지 않은 {$days}일 × 하루 " . $this->won((int) floor($perDay)) . ' (정책 13. 서비스 장애 보상)');
        }

        // ── 노출 시작 전 ─────────────────────
        if ($ref->lt($start)) {
            if (!$monthly || $reason === 'service') return $result($paid, '노출 시작 전에 끝내서 전액 환불해요.');
            $left = $ref->diffInSeconds($start, true) / self::DAY;
            $rows[] = ['시작까지', number_format($left, 1) . '일'];
            if ($ref->lte($start->copy()->subDays(self::FULL_CANCEL_DAYS))) {
                return $result($paid, '시작일 ' . self::FULL_CANCEL_DAYS . '일 전까지 취소해서 전액 환불해요.');
            }
            $fee = (int) round((1 - self::LATE_CANCEL_RATE) * 100);
            return $result((int) floor($paid * self::LATE_CANCEL_RATE),
                '시작일 ' . self::FULL_CANCEL_DAYS . "일 전이 지나 시작 전에 취소해서 광고비의 " . (100 - $fee) . "%를 환불해요(취소 수수료 {$fee}%).");
        }

        // ── 노출 중 · 끝난 뒤: 남은 일수 ─────────────
        // 광고주가 끝냄: 끝낸 날은 쓴 날(남은 날 버림). 서비스 사정: 남은 기간 전액(남은 날 올림).
        $round = $reason === 'service' ? 'ceil' : 'floor';
        if ($ended) {
            $usedSec = max(0, $start->diffInSeconds($ref, false)) / self::DAY;
            // 쓴 날: 광고주가 끝냄은 올림(끝낸 날 포함), 서비스 사정은 버림
            $remaining = $total - (int) ($reason === 'service' ? floor($usedSec) : ceil($usedSec));
            $notes[] = '지난 멈춤 기록은 남지 않아서, 멈춘 적이 있는 광고는 실제보다 적게 계산될 수 있어요. 광고 관리 기록을 확인하고 광고주에게 유리하게 처리해 주세요.';
        } else {
            $remaining = $end && $end->gt($usedRef) ? (int) $round($usedRef->diffInSeconds($end, true) / self::DAY) : 0;
        }
        $remaining = max(0, min($total, $remaining));
        $rows[] = ['남은 일수', $remaining . '일' . ($reason === 'service' ? ' (하루가 안 되는 남은 시간도 하루로)' : ' (끝낸 날은 쓴 날로)')];

        if ($remaining === 0) return $result(0, '남은 노출 기간이 없어서 환불할 금액이 없어요.');
        $refund = (int) floor($perDay * $remaining);
        $rule = $monthly
            ? '광고비 × 남은 ' . $remaining . '일 ÷ 그 달 ' . $total . '일'
            : '남은 ' . $remaining . '일 × 하루 ' . $this->won((int) floor($perDay));
        return $result($refund, $rule . ($reason === 'service' ? ' (서비스 사정 · 남은 기간 전액)' : ''));
    }

    private function stateLabel(object $ad): string
    {
        return match (app(ArtistAds::class)->state($ad)) {
            'reviewing' => '심사 중', 'rejected' => '반려', 'ended' => '종료',
            'paused' => '멈춤', 'scheduled' => '노출 예정', default => '노출 중',
        };
    }

    private function won(int $n): string
    {
        return '₩' . number_format($n);
    }
}
