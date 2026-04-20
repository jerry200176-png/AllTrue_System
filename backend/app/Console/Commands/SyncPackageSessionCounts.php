<?php

namespace App\Console\Commands;

use App\Http\Controllers\StudentClassController;
use App\Models\CoursePackage;
use App\Models\StudentClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 修復歷史資料：把方案成員課程的 SessionCount / PackageTotalSessions
 * 同步到 course_packages.total_sessions。
 *
 * 對 SessionCount < total_sessions 的成員同時補排 ClassSession
 * （對應 FR-001 上線後的一次性 backfill）。
 *
 * 使用：
 *   php artisan packages:sync-session-counts --dry-run   # 只列差異
 *   php artisan packages:sync-session-counts             # 正式執行
 */
class SyncPackageSessionCounts extends Command
{
    protected $signature = 'packages:sync-session-counts
                            {--dry-run : 只輸出差異，不寫入}
                            {--package= : 僅處理指定 package_id}';

    protected $description = '同步方案成員 StudentClass.SessionCount 至 CoursePackage.total_sessions，必要時補排 ClassSession';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pkgFilter = $this->option('package');

        $this->info($dry ? '[DRY-RUN] 模擬執行' : '[LIVE] 正式執行');

        $packages = CoursePackage::query()
            ->where('billing_mode', 'count')
            ->when($pkgFilter, fn ($q) => $q->where('id', (int) $pkgFilter))
            ->get();

        $totalDiff = 0;
        $totalFixed = 0;
        $totalExtended = 0;

        /** @var StudentClassController $scController */
        $scController = app()->make(StudentClassController::class);

        foreach ($packages as $pkg) {
            $members = StudentClass::where('PackageID', $pkg->id)->get();
            foreach ($members as $member) {
                $current = (int) $member->SessionCount;
                $correct = (int) $pkg->total_sessions;
                if ($current === $correct) {
                    continue;
                }

                $totalDiff++;
                $this->line(sprintf(
                    '  pkg_id=%d student_class_id=%d current=%d correct=%d',
                    $pkg->id, $member->ID, $current, $correct
                ));

                if ($dry) {
                    continue;
                }

                DB::transaction(function () use ($pkg, $member, $current, $correct, $scController, &$totalFixed, &$totalExtended) {
                    StudentClass::where('ID', $member->ID)->update([
                        'SessionCount'         => $correct,
                        'PackageTotalSessions' => $correct,
                    ]);
                    $totalFixed++;

                    if ($current < $correct) {
                        $before = \App\Models\ClassSession::where('StudentClassID', $member->ID)
                            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                            ->count();

                        $scController->extendSessionsIfNeeded($member->fresh(), $correct);

                        $after = \App\Models\ClassSession::where('StudentClassID', $member->ID)
                            ->whereNotIn('Status', ['cancelled', 'leave', 'leave_adjusted'])
                            ->count();
                        $totalExtended += max(0, $after - $before);
                    }
                });
            }

            if (!$dry) {
                $pkg->remaining_sessions = max(0, (int) $pkg->total_sessions - (int) $pkg->used_sessions);
                $pkg->save();
            }
        }

        $this->info("差異成員數：{$totalDiff}");
        if (!$dry) {
            $this->info("已修正 SessionCount：{$totalFixed}");
            $this->info("補排 ClassSession 總數：{$totalExtended}");
            Log::info('packages:sync-session-counts completed', [
                'fixed' => $totalFixed, 'extended' => $totalExtended, 'scanned' => $packages->count(),
            ]);
        }

        return self::SUCCESS;
    }
}
