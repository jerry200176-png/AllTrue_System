<?php

namespace App\Services\Billing;

use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * F7 S5 shadow mode: after an outbound site has decided by the legacy predicate (!isEffectivelyPaid),
 * compare that decision with BillingPayableResolver and log one PII-free line per run per site.
 * Observation only: never throws, never feeds back into who is messaged.
 */
class PaidStatusShadow
{
    private const MAX_IDS = 50;
    private const OWES = ['unpaid', 'partial', 'unbilled'];

    public function __construct(private BillingPayableResolver $resolver)
    {
    }

    /** @param Collection<int, StudentClass> $courses */
    public function compare(Collection $courses, string $site): void
    {
        if (!config('billing.paid_status_shadow', true)) {
            return;
        }
        try {
            $courses = $courses->unique(fn (StudentClass $c) => (int) $c->getAttribute('ID'))->values();
            $statuses = [];
            foreach (array_chunk($courses->all(), 500) as $chunk) {
                $ids = array_map(fn (StudentClass $c) => (int) $c->getAttribute('ID'), $chunk);
                $statuses += $this->resolver->courseStatusesByStudentClassIds($ids, $chunk);
            }

            $counts = ['old_owes_resolver_settled' => 0, 'old_settled_resolver_owes' => 0, 'review_required' => 0, 'missing' => 0];
            $diffIds = [];
            foreach ($courses as $course) {
                $id = (int) $course->getAttribute('ID');
                $status = $statuses[$id]['status'] ?? null;
                if ($status === null) {
                    $counts['missing']++;
                    continue;
                }
                if ($status === 'review_required') {
                    $counts['review_required']++;
                    continue;
                }
                $oldOwes = !$course->isEffectivelyPaid();
                $newOwes = in_array($status, self::OWES, true);
                if ($oldOwes === $newOwes) {
                    continue;
                }
                $counts[$oldOwes ? 'old_owes_resolver_settled' : 'old_settled_resolver_owes']++;
                $diffIds[] = $id;
            }

            Log::info('paid_status_shadow', [
                'site' => $site,
                'total' => $courses->count(),
                'diff_total' => count($diffIds),
                'diff_course_ids' => array_slice($diffIds, 0, self::MAX_IDS),
            ] + $counts);
        } catch (Throwable $e) {
            Log::warning('paid_status_shadow_failed', ['site' => $site, 'error' => $e::class]);
        }
    }
}
