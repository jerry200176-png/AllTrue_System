<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;

final class MonthlyContractBoundaryService
{
    public function extensionRequiresRenewal(StudentClass $course, array $mapped): bool
    {
        if ((string) $course->getAttribute('ScheduleMode') !== 'date' || (int) $course->getAttribute('PackageID') > 0 || !isset($mapped['EndDate']) || !$course->getAttribute('EndDate')) return false;
        Validator::make($mapped, ['EndDate' => 'date'])->validate();
        if (Carbon::parse($mapped['EndDate'])->lte(Carbon::parse($course->getAttribute('EndDate')))) return false;
        // A dated invoice or legacy collection is evidence for the agreed old interval. Extending it is a
        // renewal, never a plain settings edit. F7 S6 (B26): "positive money" is the resolver's applied amount
        // (net of voids, the Paid flag only counts while no non-void invoice exists), not Flag / PaidAmount / raw rows.
        // Fail closed: no resolver answer (error, unsaved course) or an unattributable state forces a renewal.
        try {
            $id = (int) $course->getAttribute('ID');
            $status = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([$id], [$course])[$id] ?? null;
        } catch (\Throwable) {
            return true;
        }
        if ($status === null || $status['status'] === 'review_required') {
            return true;
        }
        // Legacy collection: Paid=1 with no non-void invoice, or an invoice whose stored PaidAmount has no Payment rows.
        if (((int) $course->getAttribute('Paid') === 1 && $status['source'] !== 'invoice') || ((int) $status['applied'] + (int) $status['overpaid']) > 0) {
            return true;
        }

        return (new Invoice)->scopeNotVoided(Invoice::query())->where('StudentClassID', $course->getAttribute('ID'))
            ->where('PaidAmount', '>', 0)->whereDoesntHave('payments')->exists();
    }
}
