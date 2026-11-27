<?php

namespace App\Services\Starttoo;

use App\Services\ProjectDatabase;
use Carbon\Carbon;

/**
 * 스튜디오 「고객 관리」 · 「매출 · 통계」. 예약 · 견적 기록으로 만든다(결제는 Phase 6).
 * 매출은 시술 완료된 예약의 견적 총액 기준(실제 결제 금액이 아니라 「견적 기준」).
 */
class StudioInsights
{
    private function db() { return app(ProjectDatabase::class)->database(); }

    /** 고객 관리: 나에게 예약한 고객(최근 예약 순). 고객마다 예약 · 완료 수, 최근 · 다음 예약, 누적 견적 */
    public function customers(int $artist, array $input): array
    {
        $page = max(1, (int) ($input['page'] ?? 1));
        $q = trim((string) ($input['q'] ?? ''));
        $base = $this->db()->table('starttoo_bookings as b')->join('starttoo_users as u', 'u.id', '=', 'b.customer_id')
            ->where('b.artist_id', $artist);
        if ($q !== '') $base->where('u.nickname', 'like', '%'.addcslashes(mb_substr($q, 0, 30), '%_\\').'%');
        $rows = (clone $base)->groupBy('b.customer_id', 'u.nickname', 'u.avatar_path', 'u.status')
            ->selectRaw("b.customer_id, u.nickname, u.avatar_path, u.status,
                count(*) as bookings,
                sum(case when b.status = 'completed' then 1 else 0 end) as completed,
                max(b.starts_at) as last_at,
                max(b.consultation_id) as consultation_id")
            ->orderByDesc('last_at')->offset(($page - 1) * 20)->limit(21)->get();
        $ids = $rows->take(20)->pluck('customer_id')->all();
        // 다음 예약(앞으로 있을 확정 · 대기 예약) · 누적 견적(완료 기준)
        $next = $ids ? $this->db()->table('starttoo_bookings')->where('artist_id', $artist)->whereIn('customer_id', $ids)
            ->whereIn('status', ['pending', 'confirmed', 'change_requested'])->where('starts_at', '>=', now())
            ->groupBy('customer_id')->selectRaw('customer_id, min(starts_at) as next_at')->pluck('next_at', 'customer_id') : collect();
        $spent = $ids ? $this->db()->table('starttoo_bookings as b')->join('starttoo_quotes as q', 'q.id', '=', 'b.quote_id')
            ->where('b.artist_id', $artist)->whereIn('b.customer_id', $ids)->where('b.status', 'completed')
            ->groupBy('b.customer_id')->selectRaw('b.customer_id, sum(q.total) as total')->pluck('total', 'customer_id') : collect();
        $urls = app(MediaUrls::class);
        return [
            'total' => (clone $base)->distinct()->count('b.customer_id'),
            'has_more' => $rows->count() > 20,
            'items' => $rows->take(20)->map(fn ($r) => [
                'id' => (int) $r->customer_id,
                'nickname' => $r->status === 'active' ? $r->nickname : '탈퇴한 회원',
                'avatar_url' => $r->status === 'active' ? $urls->url($r->avatar_path, 300) : null,
                'bookings' => (int) $r->bookings, 'completed' => (int) $r->completed,
                'last_at' => Carbon::parse($r->last_at)->toIso8601String(),
                'next_at' => isset($next[$r->customer_id]) ? Carbon::parse($next[$r->customer_id])->toIso8601String() : null,
                'spent' => (int) ($spent[$r->customer_id] ?? 0),
                'consultation_id' => (int) $r->consultation_id,
            ])->values()->all(),
        ];
    }

    /** 매출 · 통계: 최근 6개월 월별 예약 · 완료 · 취소 수와 완료 견적 합계, 이번 달 요약 */
    public function stats(int $artist): array
    {
        $from = now()->startOfMonth()->subMonths(5);
        $rows = $this->db()->table('starttoo_bookings as b')->leftJoin('starttoo_quotes as q', 'q.id', '=', 'b.quote_id')
            ->where('b.artist_id', $artist)->where('b.starts_at', '>=', $from)
            ->selectRaw("DATE_FORMAT(b.starts_at, '%Y-%m') as ym,
                count(*) as bookings,
                sum(case when b.status = 'completed' then 1 else 0 end) as completed,
                sum(case when b.status in ('cancelled', 'rejected', 'expired') then 1 else 0 end) as cancelled,
                sum(case when b.status = 'completed' then coalesce(q.total, 0) else 0 end) as revenue,
                sum(case when b.status = 'completed' then coalesce(q.deposit, 0) else 0 end) as deposit")
            ->groupBy('ym')->get()->keyBy('ym');
        // 광고비: 승인된 작가 광고(심사 중 · 반려 제외)의 결제 금액(광고비 + 부가세)을 노출 시작한 달에 넣는다.
        // 결제(Phase 6) 전이라 실제 결제 · 환불 기록이 아니라 광고 신청 금액 기준.
        $ads = $this->db()->table('starttoo_ads')->where('artist_id', $artist)->whereNotNull('product')
            ->whereNotIn('status', ['reviewing', 'rejected'])->whereNotNull('starts_at')
            ->where('starts_at', '>=', $from)->where('starts_at', '<', $from->copy()->addMonths(6))
            ->get(['amount', 'starts_at']);
        $adCost = [];
        foreach ($ads as $ad) {
            $ym = Carbon::parse($ad->starts_at)->format('Y-m');
            $amount = (int) $ad->amount;
            $adCost[$ym] = ($adCost[$ym] ?? 0) + $amount + (int) floor($amount * ArtistAds::VAT_RATE);
        }
        $months = [];
        for ($i = 0; $i < 6; $i++) {
            $ym = $from->copy()->addMonths($i)->format('Y-m');
            $r = $rows[$ym] ?? null;
            $months[] = ['month' => $ym, 'bookings' => (int) ($r->bookings ?? 0), 'completed' => (int) ($r->completed ?? 0),
                'cancelled' => (int) ($r->cancelled ?? 0), 'revenue' => (int) ($r->revenue ?? 0), 'deposit' => (int) ($r->deposit ?? 0),
                'ad_cost' => $adCost[$ym] ?? 0];
        }
        $customers = $this->db()->table('starttoo_bookings')->where('artist_id', $artist)->where('status', 'completed')
            ->groupBy('customer_id')->selectRaw('customer_id, count(*) n')->get();
        return [
            'months' => $months,
            'this_month' => end($months),
            'upcoming' => $this->db()->table('starttoo_bookings')->where('artist_id', $artist)->where('status', 'confirmed')->where('starts_at', '>=', now())->count(),
            'pending' => $this->db()->table('starttoo_bookings')->where('artist_id', $artist)->where('status', 'pending')->count(),
            'customers' => $customers->count(),
            'returning' => $customers->where('n', '>=', 2)->count(),
            'reviews' => app(Reviews::class)->summary($artist, $artist),
        ];
    }
}
