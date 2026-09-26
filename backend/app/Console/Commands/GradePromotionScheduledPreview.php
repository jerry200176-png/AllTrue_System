<?php

namespace App\Console\Commands;

use App\Services\GradePromotionScheduledPreviewService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GradePromotionScheduledPreview extends Command
{
    protected $signature = 'grade-promotion:scheduled-preview
                            {--force-date : Run even when today is not the configured admin date (tests/staging only)}';

    protected $description = 'Phase-B.1: run campus allowlisted grade-promotion previews on the admin date and notify directors (no auto-confirm).';

    public function handle(GradePromotionScheduledPreviewService $service): int
    {
        try {
            $now = Carbon::now((string) config('grade_promotion.timezone', 'Asia/Taipei'));
            if ($this->option('force-date')) {
                $admin = app(\App\Services\GradePromotionService::class)->adminDateForYear(
                    app(\App\Services\GradePromotionService::class)->defaultSeasonYear($now)
                );
                $now = $admin->copy()->setTimeFromTimeString('08:00:00');
            }

            $result = $service->run($now);
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE));

            return ($result['status'] ?? '') === 'ok' ? self::SUCCESS : self::SUCCESS;
        } catch (\Throwable $exception) {
            $service->recordFailure($exception);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
