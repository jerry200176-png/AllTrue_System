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
        if ((string) $course->ScheduleMode !== 'date' || (int) $course->PackageID > 0 || !isset($mapped['EndDate']) || !$course->EndDate) return false;
        Validator::make($mapped, ['EndDate' => 'date'])->validate();
        if (Carbon::parse($mapped['EndDate'])->lte(Carbon::parse($course->EndDate))) return false;
        // A dated invoice or legacy collection is evidence for the agreed old
        // interval. Extending it is a renewal, never a plain settings edit.
        return (int) $course->Paid === 1 || Invoice::query()->notVoided()->where('StudentClassID', $course->ID)
            ->where(function ($query) {
                $query->where('PaidAmount', '>', 0)->orWhereHas('payments', fn ($payments) => $payments->where('Amount', '>', 0)->where(function ($methods) { $methods->whereNull('Method')->orWhere('Method', '!=', 'void'); }));
            })->exists();
    }
}
