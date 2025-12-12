<?php

namespace App\Console\Commands;

use App\Services\PortfolioStatService;
use Illuminate\Console\Command;

class UpdatePortfolioStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'portfolio:update-stats 
                            {--days=7 : 최근 기간 (일수)}
                            {--portfolio-id= : 특정 포트폴리오 ID만 업데이트}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '포트폴리오 통계 업데이트 (최근 통계 계산)';

    /**
     * Execute the console command.
     */
    public function handle(PortfolioStatService $service): int
    {
        $days = (int) $this->option('days');
        $portfolioId = $this->option('portfolio-id');

        $this->info('포트폴리오 통계 업데이트를 시작합니다...');

        try {
            if ($portfolioId) {
                // 특정 포트폴리오만 업데이트
                $this->info("포트폴리오 ID {$portfolioId}의 통계를 업데이트합니다...");
                $service->updatePortfolioRecentStats((int) $portfolioId, $days);
                $this->info("✓ 포트폴리오 ID {$portfolioId} 통계 업데이트 완료");
            } else {
                // 모든 포트폴리오 업데이트
                $this->info("모든 포트폴리오의 최근 {$days}일 통계를 업데이트합니다...");
                $service->updateRecentStats($days);
                $this->info('✓ 모든 포트폴리오 통계 업데이트 완료');
            }

            $this->newLine();
            $this->info('통계 업데이트가 성공적으로 완료되었습니다.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('통계 업데이트 중 오류가 발생했습니다: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}

