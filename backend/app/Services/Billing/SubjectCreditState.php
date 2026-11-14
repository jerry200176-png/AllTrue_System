<?php

namespace App\Services\Billing;

use App\Models\{Invoice, InvoiceItem, Payment, StudentClass};
use App\Services\SessionDeductionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Bounded financial/history evidence for a single credit operation, without duplicating names or free text. */
final class SubjectCreditState
{
    public const COURSE_FIELDS = ['ID', 'StudentID', 'SubjectID', 'TeacherID', 'SessionCount', 'Charge', 'Pay', 'Paid',
        'PayDate', 'Rate', 'SessionDuration', 'rate_unit', 'deduction_basis', 'ScheduleMode', 'scheduling_policy',
        'UsedSessions', 'RemainingSessions', 'Stop', 'closed_reason', 'EndDate', 'Disconunt'];

    public static function capture(StudentClass $course, Invoice $invoice): array
    {
        $id = (int) $course->getKey();
        $data = [
            'course' => $course->fresh()->only(self::COURSE_FIELDS),
            'invoice' => $invoice->fresh()->only(['id', 'StudentID', 'StudentClassID', 'TotalAmount', 'PaidAmount', 'Status']),
            'items' => InvoiceItem::where('InvoiceID', $invoice->getKey())->orderBy('id')->get(['id', 'StudentClassID', 'Amount'])->toArray(),
            'payments' => Payment::where('InvoiceID', $invoice->getKey())->orderBy('id')->get(['id', 'InvoiceID', 'Amount', 'Method', 'PaidAt', 'payment_report_id'])->toArray(),
            'sessions_digest' => self::digest(DB::table('ClassSession')->where('StudentClassID', $id)->orderBy('id')->get(['id', 'SessionDate', 'StartTime', 'EndTime', 'Status'])->map(fn ($s) => (array) $s)->all()),
            'usage' => SessionDeductionService::batchExpectedUsedSessionDiagnostics([$id])[$id] ?? null,
        ];

        // Normalize dates/DB scalars once. MySQL JSON reorders object keys, so compare canonical data, never byte order.
        return json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function digest(array $state): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($state), JSON_THROW_ON_ERROR));
    }

    public static function lockBookings(int $courseId): \Illuminate\Support\Collection
    {
        // Same materialization whitelist as ScheduleController::ensureClassSessionForScheduleData.
        return DB::table('schedules')->where('student_course_id', $courseId)->where(function ($q) {
            $q->where('status', 'scheduled')->orWhere(fn ($extra) => $extra->where('type', 'extra')->where('status', 'rescheduled'));
        })->orderBy('id')->lockForUpdate()->get();
    }
}
