<?php

namespace App\Support;

use App\Services\ProjectDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * 디자인(STARTTOO 운영 콘솔 · M-01~M-09)의 상태 알약 · 탭 · 스위치 스타일을 그대로 옮긴 헬퍼.
 * 뷰는 디자인과 같은 inline style 을 쓰므로, 상태에 따라 바뀌는 스타일만 여기서 만든다.
 */
final class AdminUi
{
    private const PILL = [
        'green' => 'background: #E3F4E9; color: #1D6B3A',
        'amber' => 'background: #FFF1D6; color: #7A4F00',
        'red' => 'background: #FDE4DE; color: #A3301A',
        'gray' => 'background: #ECEAE5; color: #55524C',
        'blue' => 'background: #E3EEFF; color: #1F4F99',
        'ink' => 'background: #141413; color: #F2EFEA',
    ];

    public static function pill(string $kind): string
    {
        return 'display: inline-flex; align-items: center; height: 24px; padding: 0 9px; border-radius: 12px; '
            . 'font-size: 12px; font-weight: 700; white-space: nowrap; ' . (self::PILL[$kind] ?? self::PILL['gray']);
    }

    public static function tab(bool $on): string
    {
        return 'height: 36px; padding: 0 14px; border-radius: 18px; font-size: 13px; font-weight: 700; '
            . ($on ? 'background: #141413; color: #FFFFFF; border: 1px solid #141413'
                   : 'background: #FFFFFF; color: #55524C; border: 1px solid #E4E0D8');
    }

    /** 탭 목록: [label, href, style] — 현재 쿼리를 유지하고 $key 만 바꿈(페이지 · 선택은 초기화) */
    public static function tabs(array $labels, string $current, string $key = 'tab', array $reset = ['sel', 'page']): array
    {
        return array_map(fn (string $label) => [
            'label' => $label,
            'href' => self::url([$key => $label] + array_fill_keys($reset, null)),
            'style' => self::tab($label === $current),
        ], $labels);
    }

    /** 현재 URL 에 쿼리만 바꾼 주소 (null 이면 제거) */
    public static function url(array $query): string
    {
        $merged = array_filter(array_merge(request()->query(), $query), fn ($v) => $v !== null && $v !== '');

        return request()->url() . ($merged ? '?' . http_build_query($merged) : '');
    }

    public static function switchTrack(bool $on, bool $locked = false, int $pad = 3, int $width = 44): string
    {
        $base = "width: {$width}px; height: 26px; border-radius: 13px; border: 0; padding: {$pad}px; display: flex; flex-shrink: 0; ";
        if ($locked) {
            return $base . ($on ? 'background: #141413; justify-content: flex-end; opacity: 0.6' : 'background: #ECEAE5; justify-content: flex-start; opacity: 0.6');
        }

        return $base . ($on ? 'background: #141413; justify-content: flex-end' : 'background: #D9D4CB; justify-content: flex-start');
    }

    /** 디자인 페이지 버튼(‹ 1 2 3 ›): 현재 페이지 주변 5개 */
    public static function pages(int $page, bool $hasMore, int $lastPage): array
    {
        $last = max(1, $lastPage);
        $from = max(1, min($page - 2, $last - 4));
        $out = [];
        for ($p = $from; $p <= min($last, $from + 4); $p++) {
            $out[] = ['n' => $p, 'href' => self::url(['page' => $p > 1 ? $p : null]), 'on' => $p === $page];
        }
        return [
            'items' => $out,
            'prev' => $page > 1 ? self::url(['page' => $page - 1 > 1 ? $page - 1 : null]) : null,
            'next' => $page < $last ? self::url(['page' => $page + 1]) : null,
        ];
    }

    /** 사이드바 배지: 심사 대기 · 미처리 신고(1분 캐시) */
    public static function navBadges(): array
    {
        return Cache::remember('starttoo:admin:badges', 60, function () {
            $db = app(ProjectDatabase::class)->database();
            return [
                'artistReview' => $db->table('starttoo_artist_applications')->whereIn('status', ['submitted', 'reviewing'])->count(),
                'reports' => $db->table('starttoo_reports')->where('status', 'pending')->count(),
            ];
        });
    }

    public static function forgetBadges(): void
    {
        Cache::forget('starttoo:admin:badges');
    }

    public static function date(mixed $at, string $format = 'Y.m.d'): string
    {
        return $at ? Carbon::parse($at)->timezone('Asia/Seoul')->format($format) : '-';
    }

    /** 방금 전 · 12분 전 · 3시간 전 · 어제 · 5일 전 · 2주 전 · 2026.05.03 */
    public static function ago(mixed $at): string
    {
        if (!$at) return '-';
        $t = Carbon::parse($at);
        $s = max(0, (int) abs(now()->diffInSeconds($t)));
        return match (true) {
            $s < 60 => '방금 전',
            $s < 3600 => intdiv($s, 60) . '분 전',
            $s < 86400 => intdiv($s, 3600) . '시간 전',
            $s < 172800 => '어제',
            $s < 604800 => intdiv($s, 86400) . '일 전',
            $s < 2592000 => intdiv($s, 604800) . '주 전',
            default => self::date($t),
        };
    }

    public static function won(int $amount): string
    {
        return number_format($amount) . '원';
    }

    /** 실명 가리기: 김하늘 → 김*늘, 이준 → 이* */
    public static function maskName(?string $name): string
    {
        if (!$name) return '-';
        $chars = mb_str_split($name);
        $n = count($chars);
        if ($n <= 1) return '*';
        if ($n === 2) return $chars[0] . '*';
        return $chars[0] . str_repeat('*', $n - 2) . $chars[$n - 1];
    }

    /** 면허 번호 가리기: 끝 2자리만 */
    public static function maskCode(?string $code): string
    {
        if (!$code) return '-';
        $len = mb_strlen($code);
        return $len <= 2 ? str_repeat('*', $len) : str_repeat('*', $len - 2) . mb_substr($code, -2);
    }
}
