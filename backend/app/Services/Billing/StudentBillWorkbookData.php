<?php

namespace App\Services\Billing;

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\LearningRecordController;
use App\Http\Middleware\RequireCampus;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RequireRole;
use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\OccurrenceAssignmentService;
use App\Services\SubstituteScheduleService;
use App\Support\LearningRecordMutableOwnership;
use App\Support\TeacherProfileDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Builds only already-selected bill evidence; never materializes lessons or changes accounts. */
final class StudentBillWorkbookData
{
    public function __construct(private AccountingController $accounting)
    {
    }

    /** $sources/$summaries are server-rebuilt tuition rows and their existing eight-cell summaries, never request cells. */
    public function build(array $sources, array $summaries, Request $request): array
    {
        $this->requireExistingReadPolicy($request);
        $branch = (int) $request->input('branch_id');
        $role = (string) $request->attributes->get('auth_role');
        $campuses = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        abort_if($branch <= 0 || ($role !== 'super_admin' && !in_array($branch, $campuses, true)), 403);

        $sources = collect($sources)->unique(fn (array $row) => self::key($row))->values();
        $students = Student::query()->where('CampusID', $branch)->whereIn('id', $sources->pluck('student_id'))->get()->keyBy('id');
        $contracts = StudentClass::query()->with('subjectRecord')->whereIn('StudentID', $students->keys())
            ->where(function ($query) use ($sources) {
                $query->whereIn('ID', $sources->filter(fn ($row) => (int) ($row['package_id'] ?? 0) <= 0)->pluck('id')->filter())
                    ->orWhereIn('PackageID', $sources->pluck('package_id')->filter());
            })->get()->keyBy('ID');
        $packages = CoursePackage::query()->where('campus_id', $branch)->whereIn('student_id', $students->keys())
            ->whereIn('id', $sources->pluck('package_id')->filter())->get()->keyBy('id');
        $selected = [];
        foreach ($sources as $source) {
            $studentId = (int) $source['student_id'];
            abort_unless($students->has($studentId), 404, '清單已更新，請重新整理後再匯出。');
            $packageId = (int) ($source['package_id'] ?? 0);
            if ($packageId > 0) {
                $package = $packages->get($packageId);
                abort_unless($package && (int) $package->student_id === $studentId, 404, '清單已更新，請重新整理後再匯出。');
                // Never trust AlertController's unscoped member_class_ids array.
                $members = $contracts->filter(fn ($course) => (int) $course->StudentID === $studentId && (int) $course->PackageID === $packageId);
            } else {
                $course = $contracts->get((int) ($source['id'] ?? 0));
                abort_unless($course && (int) $course->StudentID === $studentId, 404, '清單已更新，請重新整理後再匯出。');
                $members = collect([$course]);
            }
            $selected[self::key($source)] = $members;
        }
        $classIds = collect($selected)->flatMap(fn ($members) => $members->pluck('ID'))->unique()->values()->all();
        $now = CarbonImmutable::now('Asia/Taipei');
        $sessions = ClassSession::query()->whereIn('StudentClassID', $classIds)
            ->whereIn(DB::raw('LOWER(TRIM(Status))'), ['attended', 'completed', 'late'])
            ->whereDate('SessionDate', '<=', $now->toDateString())
            ->orderBy('SessionDate')->orderBy('StartTime')->orderBy('id')->get();
        // Normal LR producers leave denormalized StudentID unset; validated contract/session ownership is authoritative.
        $records = LearningRecord::active()->whereIn('StudentClassID', $classIds)->whereIn('ClassSessionID', $sessions->pluck('id'))->get();
        $ordinals = LearningRecordController::batchSessionNumbers($records);
        $recordsBySession = $records->groupBy('ClassSessionID');
        $signIns = StudentSignIn::active()->whereIn('StudentID', $students->keys())->whereIn('StudentClassID', $classIds)->whereIn('ClassSessionID', $sessions->pluck('id'))->get()->groupBy('ClassSessionID');
        // Zero fallback distinguishes a legacy substitute from an unrecorded instructor, without inventing attendance ownership.
        $scheduledTeachers = SubstituteScheduleService::teachersForOccurrences($sessions->map(fn ($session) => [
            'course_id' => (int) $session->StudentClassID, 'date' => substr((string) $session->SessionDate, 0, 10),
            'start_time' => $session->StartTime, 'contract_teacher_id' => 0,
        ]));
        $occurrenceV2 = SubstituteScheduleService::anyCampusOn() && OccurrenceAssignmentService::enabledFor($branch);
        $lessons = [];
        $warnings = [];
        $teacherIds = [];
        foreach ($sessions as $session) {
            $courseId = (int) $session->StudentClassID;
            $course = $contracts->get($courseId);
            $date = substr((string) $session->SessionDate, 0, 10);
            $start = $session->StartTime ? CarbonImmutable::parse($date . ' ' . $session->StartTime, 'Asia/Taipei') : null;
            if ($start && $start->gt($now)) {
                continue;
            }
            $sameOwner = $recordsBySession->get($session->id, collect())->filter(fn ($record) => (int) $record->StudentClassID === $courseId
                && ((int) $record->StudentID === 0 || (int) $record->StudentID === (int) $course->StudentID));
            $record = $sameOwner->count() === 1 ? $sameOwner->first() : null;
            $attendance = $signIns->get($session->id, collect())->filter(fn ($row) => (int) $row->StudentClassID === $courseId && (int) $row->StudentID === (int) $course->StudentID);
            if ($attendance->contains(fn ($row) => in_array(strtolower(trim((string) $row->Status)), ['leave', 'leave_adjusted', 'cancelled', 'absent', 'excused'], true))) {
                $warnings[$courseId][] = $date . '：堂次與出缺席紀錄不一致，未當作已上課；請至原紀錄核對。';
                continue;
            }
            // The already-loaded, same-owner taught session is historical evidence in the canonical ownership helper.
            // Do not repeat its snapshot/session queries for each pending placeholder LR.
            $recordTeacher = $record && in_array(strtolower(trim((string) $session->Status)), LearningRecordMutableOwnership::TAUGHT_SESSION_STATUSES, true) ? (int) $record->TeacherID : 0;
            $attendanceTeachers = $attendance->pluck('TeacherID')->filter()->unique();
            $scheduledTeacher = $scheduledTeachers[SubstituteScheduleService::occurrenceKey($courseId, $date, $session->StartTime)] ?? 0;
            // Flag-on's restored/default contract teacher is not a substitute; historical LR ownership still wins.
            $substitute = $scheduledTeacher > 0 && (!$occurrenceV2 || $scheduledTeacher !== (int) $course->TeacherID) ? $scheduledTeacher : 0;
            $teacherId = $substitute > 0 ? $substitute : ($recordTeacher > 0 ? $recordTeacher : ($attendanceTeachers->count() === 1 ? (int) $attendanceTeachers->first() : 0));
            $end = $session->EndTime ? CarbonImmutable::parse($date . ' ' . $session->EndTime, 'Asia/Taipei') : null;
            $lessons[$courseId][] = [
                'date' => $date, 'ordinal' => $record ? ($ordinals[(int) $record->id] ?? null) : null,
                'teacher_id' => $teacherId, 'start_time' => $session->StartTime, 'end_time' => $session->EndTime,
                'minutes' => $start && $end && $end->gt($start) ? ($end->getTimestamp() - $start->getTimestamp()) / 60 : null,
                // InvoiceItem has no historical unit-price field; the discount snapshot is not a per-lesson price.
                'historical_unit_fee' => null,
                'note' => !$record ? '評量關聯不足，堂次序號未能確認' : '',
            ];
            $teacherIds[] = $teacherId;
        }
        $teacherNames = TeacherProfileDirectory::names($teacherIds);
        foreach ($lessons as &$rows) {
            foreach ($rows as &$row) {
                $row['teacher'] = $teacherNames[$row['teacher_id']] ?? null;
                unset($row['teacher_id']);
            }
            unset($row);
        }
        unset($rows);

        $out = [];
        foreach ($sources->groupBy('student_id') as $studentId => $studentSources) {
            $studentClassIds = $studentSources->flatMap(fn ($source) => $selected[self::key($source)]->pluck('ID'))->unique()->all();
            $ledger = [];
            if ($studentClassIds !== []) {
                $inner = Request::create('/api/v1/accounting/ledger', 'GET', ['student_class_id' => $studentClassIds[0], 'branch_id' => $branch]);
                $inner->attributes->replace($request->attributes->all());
                $response = $this->accounting->ledger($inner);
                abort_unless($response->status() === 200, $response->status(), '帳務明細無法確認，請重新整理後再匯出。');
                $ledger = $response->getData(true);
            }
            $sections = [];
            $emittedInvoiceIds = [];
            $emittedReportIds = [];
            $representedReports = collect($ledger['invoices'] ?? [])->filter(fn ($invoice) => array_intersect($studentClassIds,
                $invoice['covered_student_class_ids'] ?: [$invoice['student_class_id']]) !== [])
                ->flatMap(fn ($invoice) => array_column($invoice['payments'], 'report_id'))->filter()->unique();
            foreach ($studentSources as $source) {
                $key = self::key($source);
                abort_unless(isset($summaries[$key]) && count($summaries[$key]) === 8, 422, '帳單摘要欄位不完整。');
                $details = [];
                foreach ($selected[$key] as $course) {
                    // Same coverage union as AccountingLedgerModal: a combined invoice can cover a non-anchor contract.
                    $rawInvoices = collect($ledger['invoices'] ?? [])->filter(fn ($invoice) => in_array((int) $course->ID, array_map('intval', $invoice['covered_student_class_ids'] ?: [$invoice['student_class_id']]), true));
                    $invoices = [];
                    $sharedReferences = [];
                    foreach ($rawInvoices as $invoice) {
                        if (isset($emittedInvoiceIds[(int) $invoice['id']])) {
                            $sharedReferences[] = $invoice['invoice_no'];
                            continue;
                        }
                        $emittedInvoiceIds[(int) $invoice['id']] = true;
                        $row = $this->invoice($invoice);
                        $coveredIds = array_unique(array_map('intval', $invoice['covered_student_class_ids'] ?: [$invoice['student_class_id']]));
                        if (count($coveredIds) > 1) {
                            $row['scope_note'] = '跨合約帳單：下列為整張帳單金額與收款，未按合約分攤；同張帳單於本工作表僅列一次。'
                                . (array_diff($coveredIds, $studentClassIds) !== [] ? '帳單亦涵蓋本次未選取的合約，未加入其課程明細。' : '');
                        }
                        $invoices[] = $row;
                    }
                    $unmatchedReports = collect($ledger['receipts'] ?? [])->filter(fn ($report) => (int) $report['student_class_id'] === (int) $course->ID
                        || $rawInvoices->contains('id', $report['invoice_id']))
                        ->reject(fn ($report) => $representedReports->contains($report['report_id']) || isset($emittedReportIds[$report['report_id']]))
                        ->map(function ($report) use (&$emittedReportIds) {
                            $emittedReportIds[$report['report_id']] = true;
                            return $this->report($report, true);
                        })->values()->all();
                    $details[] = [
                        'subject' => $course->displaySubjectName(), 'start_date' => $course->StartDate ? substr((string) $course->StartDate, 0, 10) : null,
                        'current_rate' => $course->Rate !== null ? (float) $course->Rate : null, 'rate_unit' => $course->rate_unit,
                        'lessons' => $lessons[(int) $course->ID] ?? [], 'warnings' => $warnings[(int) $course->ID] ?? [],
                        'invoices' => $invoices, 'shared_invoice_references' => $sharedReferences, 'unmatched_reports' => $unmatchedReports,
                    ];
                }
                $sections[] = ['title' => (string) ($source['subject'] ?? '課程'), 'summary' => array_values($summaries[$key]), 'contracts' => $details];
            }
            $out[] = ['name' => (string) $students->get((int) $studentId)->name, 'sources' => $sections];
        }
        return $out;
    }

    public static function key(array $row): string
    {
        return !empty($row['package_id']) ? 'package:' . (int) $row['package_id'] : 'course:' . (int) ($row['id'] ?? 0);
    }

    private function invoice(array $invoice): array
    {
        $out = Arr::only($invoice, ['invoice_no', 'status', 'total_amount', 'calculated_applied_amount', 'voided_amount', 'outstanding_amount']);
        $out['payments'] = array_map(function (array $payment) {
            if (!in_array($payment['report_status'] ?? null, ['confirmed', 'voided'], true)) {
                $payment['receipt_no'] = null;
            }
            return Arr::only($payment, ['paid_at', 'receipt_no', 'application_status', 'amount', 'applied_amount', 'unapplied_amount', 'method']);
        }, $invoice['payments']);
        // Reports without an exported payment appear once in the contract's explicitly non-applied section.
        $out['unpaid_reports'] = [];
        return $out;
    }

    private function report(array $report, bool $unmatched): array
    {
        if ($report['status'] === 'confirmed') {
            $report['status'] = empty($report['payment_id']) ? 'confirmed_without_payment' : ($unmatched ? 'confirmed_outside_selected_invoice' : 'confirmed');
        } elseif ($report['status'] !== 'voided') {
            $report['receipt_no'] = null;
        }
        // Never carry ledger notes, contact details, account suffixes, or unrelated action/anomaly payloads into exports.
        return Arr::only($report, ['payment_date', 'receipt_no', 'status', 'amount', 'payment_method']);
    }

    private function requireExistingReadPolicy(Request $request): void
    {
        // Both the existing ledger and export routes use precisely these three middleware constraints.
        $guard = Request::create('/api/v1/accounting/bills/export', 'POST');
        $guard->attributes->replace($request->attributes->all());
        $result = (new Pipeline(app()))->send($guard)->through([
            RequireRole::class . ':director', RequireCampus::class, RequirePasswordChange::class,
        ])->thenReturn();
        if (!$result instanceof Request) {
            abort($result->status(), '沒有權限匯出帳務明細。');
        }
    }
}
