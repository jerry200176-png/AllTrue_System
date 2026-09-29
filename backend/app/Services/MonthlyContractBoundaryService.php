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
        // A dated invoice or legacy collection is evidence for the agreed old
        // interval. Extending it is a renewal, never a plain settings edit.
        return (int) $course->getAttribute('Paid') === 1 || (new Invoice)->scopeNotVoided(Invoice::query())->where('StudentClassID', $course->getAttribute('ID'))
            ->where(function ($query) {
                $query->where('PaidAmount', '>', 0)->orWhereHas('payments', fn ($payments) => $payments->where('Amount', '>', 0)->where(function ($methods) { $methods->whereNull('Method')->orWhere('Method', '!=', 'void'); }));
            })->exists();
    }
}
