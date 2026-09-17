<?php

namespace App\Services;

use App\Models\BugReport;
use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * In-app #297 Phase-B.1 — scheduled preview + staff reminder only (no auto-confirm).
 */
class GradePromotionScheduledPreviewService
{
    public function __construct(private readonly GradePromotionService $promotion)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(?Carbon $now = null): array
    {
        if ((bool) config('grade_promotion.auto_confirm', false)) {
            throw new \RuntimeException('GRADE_PROMOTION_AUTO_CONFIRM must remain false in Phase-B.1');
        }

        $allowlist = $this->campusAllowlist();
        if ($allowlist === []) {
            return ['status' => 'skipped', 'reason' => 'empty_campus_allowlist'];
        }

        $tz = (string) config('grade_promotion.timezone', 'Asia/Taipei');
        $now = ($now ?? Carbon::now($tz))->timezone($tz);
        $seasonYear = $this->promotion->defaultSeasonYear($now);
        $adminDate = $this->promotion->adminDateForYear($seasonYear);

        if (!$now->isSameDay($adminDate)) {
            return [
                'status' => 'skipped',
                'reason' => 'not_admin_date',
                'admin_date' => $adminDate->toDateString(),
                'today' => $now->toDateString(),
            ];
        }

        $campusResults = [];
        foreach ($allowlist as $campusId) {
            $preview = $this->promotion->preview($campusId, $seasonYear);
            $actionable = count(array_filter($preview, static fn (array $row) => (bool) ($row['actionable'] ?? false)));
            $this->notifyDirectors($campusId, $seasonYear, $actionable);
            $campusResults[] = [
                'campus_id' => $campusId,
                'season_year' => $seasonYear,
                'actionable' => $actionable,
                'total' => count($preview),
            ];
        }

        return ['status' => 'ok', 'season_year' => $seasonYear, 'campuses' => $campusResults];
    }

    /**
     * @return list<int>
     */
    public function campusAllowlist(): array
    {
        $raw = config('grade_promotion.campus_allowlist', []);
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id) => is_numeric($id) ? (int) $id : 0,
            $raw
        ), static fn (int $id) => $id > 0)));
    }

    public function recordFailure(Throwable $exception, ?Carbon $at = null): void
    {
        $tz = (string) config('grade_promotion.timezone', 'Asia/Taipei');
        $at = ($at ?? Carbon::now($tz))->timezone($tz);
        $day = $at->toDateString();

        Log::error('grade_promotion_scheduled_preview_failed', [
            'message' => $exception->getMessage(),
            'class' => $exception::class,
            'day' => $day,
        ]);

        if (Schema::hasTable('Notifications')) {
            Notification::updateOrCreate(
                ['SourceKey' => "grade-promotion:scheduler-failure:{$day}"],
                [
                    'CampusID' => 0,
                    'Type' => 'grade_promotion_scheduler',
                    'Severity' => 'error',
                    'Title' => '年級升級排程預覽失敗',
                    'Body' => '行政日自動預覽未成功。請工程確認 scheduler 與 grade promotion 設定；主任仍可使用手動預覽／確認。',
                    'SourceType' => 'grade_promotion_scheduler',
                    'SourceID' => 0,
                    'Payload' => [
                        'error_class' => $exception::class,
                        'error_message' => $exception->getMessage(),
                        'day' => $day,
                    ],
                    'OccurredAt' => $at,
                    'ResolvedAt' => null,
                ]
            );
        }

        if (Schema::hasTable('bug_reports')) {
            BugReport::firstOrCreate(
                [
                    'title' => "[ops] grade-promotion scheduled preview failed {$day}",
                ],
                [
                    'CampusID' => 0,
                    'reporter_user_id' => null,
                    'description' => 'Scheduler grade-promotion preview failed on admin date. Phase-B.1 is preview+reminder only; no auto-confirm.',
                    'severity' => 'high',
                    'status' => 'new',
                    'page_key' => 'ops/grade-promotion-scheduler',
                    'url' => '',
                    'client_info' => json_encode([
                        'error_class' => $exception::class,
                        'error_message' => $exception->getMessage(),
                    ], JSON_UNESCAPED_UNICODE),
                ]
            );
        }
    }

    private function notifyDirectors(int $campusId, int $seasonYear, int $actionable): void
    {
        if (!Schema::hasTable('Notifications')) {
            return;
        }

        $sourceKey = "grade-promotion:preview-reminder:{$campusId}:{$seasonYear}";
        Notification::updateOrCreate(
            ['SourceKey' => $sourceKey],
            [
                'CampusID' => $campusId,
                'Type' => 'grade_promotion_preview',
                'Severity' => 'info',
                'Title' => '年級升級預覽提醒',
                'Body' => $actionable > 0
                    ? "行政升級日已到：分校 {$campusId} 共有 {$actionable} 位學生可預覽升級。請至學生管理使用「預覽」確認名單後再執行確認（本階段不會自動確認）。"
                    : "行政升級日已到：分校 {$campusId} 目前無需升級的學生。若名單異常請回報。",
                'SourceType' => 'grade_promotion_preview',
                'SourceID' => $seasonYear,
                'Payload' => [
                    'campus_id' => $campusId,
                    'season_year' => $seasonYear,
                    'actionable' => $actionable,
                    'auto_confirm' => false,
                ],
                'OccurredAt' => now(),
                'ResolvedAt' => null,
            ]
        );
    }
}
