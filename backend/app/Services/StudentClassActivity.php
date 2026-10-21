<?php

namespace App\Services;

use App\Models\CoursePackage;
use App\Models\StudentClass;
use Illuminate\Support\Collection;

/**
 * The one server definition of 「進行中」 vs 歷史 for a course (in-app #427).
 *
 * The student-classes list puts `history_reason` / `is_active_course` on every row (學生管理 and 課程管理
 * read those flags), and the duplicate-course prompt and the create-time duplicate guard call evaluate().
 * All three feed the same inputs to historyReason(): the self-healed own remaining (selfHealedUsage, also
 * what the list shows) and the BillingPayableResolver settlement verdict.
 *
 * Display/guard only: it never decides what is owed or changes a billing state.
 */
final class StudentClassActivity
{
    /** Resolver verdicts that mean nothing is left to collect. */
    private const SETTLED = ['paid', 'free'];

    /** Closures that always file a course under history. */
    private const HISTORY_CLOSURES = ['settled', 'settled_pending', 'waived', 'completed'];

    /**
     * Count-mode self-heal: purchased − actually used. Null when the stored counters stand (monthly,
     * nothing purchased, or a partial-minutes balance where minutes are authoritative, #613 A1).
     *
     * @return array{used: int, remaining: int}|null
     */
    public static function selfHealedUsage(StudentClass $course, int $observedUsed): ?array
    {
        $purchased = (int) ($course->SessionCount ?? 0);
        if (($course->ScheduleMode ?? 'count') !== 'count' || $purchased <= 0) {
            return null;
        }
        $perSessionMin = max(1, (int) ($course->SessionDuration ?: 60));
        $remainingMinutes = $course->RemainingMinutes;
        if ($remainingMinutes !== null && ((int) $remainingMinutes % $perSessionMin !== 0)) {
            return null;
        }
        $used = min($purchased, $observedUsed);

        return ['used' => $used, 'remaining' => max(0, $purchased - $used)];
    }

    /**
     * Why the course is history, or null when it belongs in the course list's active section.
     * A stopped course without a closure stays there as paused unless it is clearly finished.
     */
    public static function historyReason(StudentClass $course, int $ownRemaining, ?string $settlement): ?string
    {
        $reason = (string) ($course->closed_reason ?? '');
        $running = empty($course->Stop);
        if (in_array($reason, self::HISTORY_CLOSURES, true)) {
            return $reason;
        }
        if ($reason !== '') {
            // converted_trial / contract_amended / usage_settled: a running row with a closure is over;
            // a stopped one keeps its existing place (pending amendments are worked from the list).
            return $running ? $reason : null;
        }
        if (($course->ScheduleMode ?? 'count') !== 'count') {
            return $running ? null : 'completed';
        }
        if ($running && (int) ($course->PackageID ?? 0) > 0) {
            return null; // FR-001: a package member draws on the shared pool.
        }
        // A positive minutes balance (#613 A1) is entitlement left even when it rounds to 0 lessons.
        if ((int) ($course->RemainingMinutes ?? 0) > 0) {
            return null;
        }
        $settled = in_array($settlement, self::SETTLED, true);

        return $settled && $ownRemaining <= 0 ? 'completed' : null;
    }

    /** 進行中 = running and not history (what the duplicate prompt and guard count). */
    public static function isActive(StudentClass $course, ?string $historyReason): bool
    {
        return empty($course->Stop) && $historyReason === null;
    }

    /**
     * Batch verdict for courses outside the list endpoint, from the same inputs the list uses.
     *
     * @param Collection<int, StudentClass> $courses
     * @return array<int, array{history_reason: ?string, is_active_course: bool, remaining_sessions: int, sessions_used: int}>
     */
    public function evaluate(Collection $courses): array
    {
        $ids = $courses->map(fn (StudentClass $c) => (int) $c->ID)->filter()->values()->all();
        if ($ids === []) {
            return [];
        }
        $diagnostics = SessionDeductionService::batchExpectedUsedSessionDiagnostics($ids);
        $statuses = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds($ids, $courses->all());
        $packages = CoursePackage::query()->whereIn('id', $courses->pluck('PackageID')->filter()->unique()->values()->all())
            ->get()->keyBy('id');
        $planning = app(SharedPackagePlanningService::class)->summarizeMany($packages->values());

        $out = [];
        foreach ($courses as $course) {
            $id = (int) $course->ID;
            $healed = self::selfHealedUsage($course, (int) ($diagnostics[$id]['observed_used'] ?? 0));
            $own = $healed['remaining'] ?? (int) ($course->RemainingSessions ?? 0);
            $reason = self::historyReason($course, $own, $statuses[$id]['status'] ?? null);
            $pkg = $packages->get((int) ($course->PackageID ?? 0));
            $out[$id] = [
                'history_reason' => $reason,
                'is_active_course' => self::isActive($course, $reason),
                // The number the course card shows: the shared pool for package members.
                'remaining_sessions' => $pkg !== null
                    ? (int) ($planning[(int) $pkg->id]['remaining_sessions'] ?? max(0, (int) $pkg->getAttribute('remaining_sessions')))
                    : $own,
                'sessions_used' => $healed['used'] ?? (int) ($course->UsedSessions ?? 0),
            ];
        }

        return $out;
    }
}
