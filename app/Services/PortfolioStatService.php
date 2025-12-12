<?php

namespace App\Services;

use App\Models\PortfolioStat;
use App\Models\PortfolioLikeLog;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PortfolioStatService
{
    /**
     * 최근 통계 업데이트 (배치 작업용)
     * 최근 7일간의 통계를 계산하여 recent_* 필드 업데이트
     *
     * @param int $days 최근 기간 (기본값: 7일)
     * @return void
     */
    public function updateRecentStats(int $days = 7): void
    {
        $since = Carbon::now()->subDays($days);

        // 모든 포트폴리오 통계 조회
        $stats = PortfolioStat::all();

        foreach ($stats as $stat) {
            $portfolioId = $stat->portfolio_id;

            // 최근 7일간 좋아요 수 계산
            $recentLikes = PortfolioLikeLog::where('portfolio_id', $portfolioId)
                ->where('action', 'like')
                ->where('created_at', '>=', $since)
                ->count();

            // 최근 7일간 좋아요 취소 수 계산
            $recentUnlikes = PortfolioLikeLog::where('portfolio_id', $portfolioId)
                ->where('action', 'unlike')
                ->where('created_at', '>=', $since)
                ->count();

            // 최근 좋아요 수 = 최근 좋아요 - 최근 좋아요 취소
            $recentLikesCount = $recentLikes - $recentUnlikes;

            // TODO: 다른 통계도 추가 (조회수, 공유, 댓글 등)
            // 현재는 좋아요만 구현

            $stat->update([
                'recent_likes' => max(0, $recentLikesCount),
            ]);
        }
    }

    /**
     * 특정 포트폴리오의 최근 통계 업데이트
     *
     * @param int $portfolioId
     * @param int $days 최근 기간 (기본값: 7일)
     * @return void
     */
    public function updatePortfolioRecentStats(int $portfolioId, int $days = 7): void
    {
        $stat = PortfolioStat::find($portfolioId);

        if (!$stat) {
            return;
        }

        $since = Carbon::now()->subDays($days);

        // 최근 7일간 좋아요 수 계산
        $recentLikes = PortfolioLikeLog::where('portfolio_id', $portfolioId)
            ->where('action', 'like')
            ->where('created_at', '>=', $since)
            ->count();

        // 최근 7일간 좋아요 취소 수 계산
        $recentUnlikes = PortfolioLikeLog::where('portfolio_id', $portfolioId)
            ->where('action', 'unlike')
            ->where('created_at', '>=', $since)
            ->count();

        // 최근 좋아요 수 = 최근 좋아요 - 최근 좋아요 취소
        $recentLikesCount = $recentLikes - $recentUnlikes;

        $stat->update([
            'recent_likes' => max(0, $recentLikesCount),
        ]);
    }

    /**
     * 포트폴리오 통계 동기화 (portfolios 테이블과 portfolio_stats 테이블 동기화)
     *
     * @param int $portfolioId
     * @return void
     */
    public function syncPortfolioStats(int $portfolioId): void
    {
        $portfolio = \App\Models\Portfolio::find($portfolioId);

        if (!$portfolio) {
            return;
        }

        $stat = PortfolioStat::find($portfolioId);

        if (!$stat) {
            // 통계가 없으면 생성
            if ($portfolio->is_public) {
                PortfolioStat::initialize($portfolioId, $portfolio->created_at);
            }
            return;
        }

        // portfolios 테이블의 값과 동기화
        $stat->update([
            'total_views' => $portfolio->views ?? 0,
            'total_likes' => $portfolio->likes_count ?? 0,
        ]);
    }
}

