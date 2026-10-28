<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\FeeCharge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\Billing\BillingPeriodInvoiceExists;
use App\Services\Billing\ContractMoneyState;
use App\Services\Billing\InvoiceIssuer;
use App\Services\BillingPayableResolver;
use App\Services\InvoiceAmountReconciliationService;
use App\Services\MonthlyBillingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    public function __construct(
        private InvoiceAmountReconciliationService $invoiceAmounts,
        private MonthlyBillingService $monthlyBilling
    )
    {
    }

    public function index(Request $request)
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = Invoice::query();

        // 與學生列表一致：依分校／可管校區篩選（Invoice 本身無 CampusID，經 Student 關聯）
        if ($request->filled('branch_id') || $request->filled('campus_id')) {
            $cid = (int) ($request->input('branch_id') ?? $request->input('campus_id'));
            if ($role !== 'super_admin' && !empty($campusIds) && !in_array($cid, $campusIds, true)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->whereHas('student', function ($q) use ($cid) {
                $q->where('CampusID', $cid);
            });
        } elseif (!empty($campusIds)) {
            $query->whereHas('student', function ($q) use ($campusIds) {
                $q->whereIn('CampusID', $campusIds);
            });
        }

        if ($request->filled('student_id')) {
            $query->where('StudentID', (int) $request->input('student_id'));
        }

        if ($request->filled('status')) {
            $query->where('Status', $request->input('status'));
        } else {
            $query->notVoided();
        }

        $invoices = $query
            ->with([
                'student',
                'studentClass',
                'payments' => fn ($payment) => $payment->select(['id', 'InvoiceID', 'Amount', 'Method']),
                'items',
                'items.studentClass',
            ])
            ->orderBy('id', 'desc')
            ->paginate(20);

        /** @var \Illuminate\Pagination\LengthAwarePaginator $invoices */
        $invoices->getCollection()->transform(function (Invoice $invoice) {
            $projection = $this->invoiceAmounts->resolve($invoice, $invoice->getRelationValue('studentClass'));
            $invoice->setAttribute('TotalAmount', $projection['total_amount']);
            $invoice->setAttribute('stored_total_amount', $projection['stored_total_amount']);
            $invoice->setAttribute('computed_total_amount', $projection['computed_total_amount']);
            $invoice->setAttribute('amount_source', $projection['amount_source']);
            $invoice->setAttribute('amount_discrepancy', $projection['amount_discrepancy']);
            $invoice->setAttribute('period_sessions', $projection['period_sessions']);
            return $invoice;
        });

        return response()->json($invoices);
    }

    public function store(Request $request, InvoiceIssuer $issuer)
    {
        $data = $request->validate([
            'StudentID' => 'required|integer',
            'StudentClassID' => 'nullable|integer',
            'IssueDate' => 'required|date',
            'DueDate' => 'nullable|date',
            'TotalAmount' => 'required|integer|min:0',
            'billing_period' => 'nullable|date_format:Y-m',
            'Note' => 'nullable|string|max:255',
            'Items' => 'nullable|array',
            'Items.*.Description' => 'required_with:Items|string|max:255',
            'Items.*.Amount' => 'required_with:Items|integer|min:0',
            'Items.*.StudentClassID' => 'nullable|integer',
            'Items.*.PeriodStart' => 'nullable|date',
            'Items.*.PeriodEnd' => 'nullable|date',
            'MonthlySplit' => 'nullable|boolean',
            'SplitStart' => 'nullable|date',
            'SplitEnd' => 'nullable|date|after_or_equal:SplitStart',
        ]);
        // #3991 (Founder 2026-10-13): a missing or 0 fee is never free by itself. The only NT$0 bill is the single-course,
        // single-term 免費／贈送 bill recorded from 登記收款 (director-record), which checks the course's fee evidence.
        if ((int) $data['TotalAmount'] === 0) {
            return response()->json([
                'message' => 'NT$0 帳單請從「登記收款」勾選「' . Invoice::ZERO_REASON_FREE_GIFT_LABEL . '」登錄；沒填費用的課程請先設定費用。沒有建立任何帳單。',
                'code' => 'zero_amount_unmarked',
            ], 422);
        }

        // Campus scope: the invoice student, the anchor contract and every item contract.
        $this->assertInvoiceStudentCampusAllowed($request, (int) (Student::query()->whereKey($data['StudentID'])->value('CampusID') ?? 0));
        $itemCourseIds = collect($data['Items'] ?? [])->pluck('StudentClassID')->push($data['StudentClassID'] ?? null)
            ->filter()->map(fn ($id) => (int) $id)->unique()->all();
        foreach (StudentClass::with('student:id,CampusID')->whereIn('ID', $itemCourseIds)->get() as $itemCourse) {
            $this->assertInvoiceStudentCampusAllowed($request, (int) $itemCourse->student?->CampusID);
        }

        return DB::transaction(function () use ($data, $issuer) {
            // Lock order everywhere: student, then courses, then invoices. Purge locks the student
            // first too, so an invoice can never be written for a student deleted concurrently (#3593).
            if (!Student::query()->whereKey($data['StudentID'])->lockForUpdate()->first(['id'])) {
                return response()->json(['message' => '找不到此學生，可能已被刪除'], 404);
            }
            // Lock every referenced course once, in ID order, so concurrent multi-course invoices cannot deadlock.
            $courseIds = collect([$data['StudentClassID'] ?? null])->merge(array_column($data['Items'] ?? [], 'StudentClassID'))
                ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if ($courseIds !== []) {
                $found = StudentClass::query()->whereIn('ID', $courseIds)->orderBy('ID')->lockForUpdate()->get(['ID', 'StudentID']);
                if ($found->count() !== count($courseIds)) {
                    return response()->json(['message' => '找不到部分課程，可能已被刪除'], 404); // never bill a deleted contract
                }
                // #3991: a bill is fee evidence for its courses, so every course on it must belong to the billed student.
                if ($found->contains(fn (StudentClass $c) => (int) $c->getAttribute('StudentID') !== (int) $data['StudentID'])) {
                    return response()->json([
                        'message' => '帳單上的課程不屬於這位學生，不能建立帳單。沒有建立任何帳單。',
                        'code' => 'invoice_course_student_mismatch',
                    ], 422);
                }
            }
            if (!empty($data['StudentClassID'])) {
                $course = StudentClass::query()->whereKey($data['StudentClassID'])->lockForUpdate()->first();
                if ($refusal = ContractMoneyState::waivedRefusal($course, '此合約已確認不收，不能再建立帳單', 'course_waived')) {
                    return $refusal;
                }
                if ($course && strtolower(trim((string) ($course->ClassType ?? ''))) === 'tutoring') {
                    return response()->json([
                        'message' => '輔導課無須繳費，不能建立帳單或付款義務。請先檢查課程帳務資料。',
                        'code' => 'tutoring_no_payment_obligation',
                    ], 422);
                }
            }
            $tutoringItem = StudentClass::query()->whereIn('ID', $courseIds)
                ->whereRaw("LOWER(TRIM(COALESCE(ClassType, ''))) = 'tutoring'")->exists();
            if ($tutoringItem) {
                return response()->json([
                    'message' => '輔導課無須繳費，不能建立帳單或付款義務。請先檢查課程帳務資料。',
                    'code' => 'tutoring_no_payment_obligation',
                ], 422);
            }
            $scheduleModeAtIssue = !empty($data['StudentClassID'])
                ? StudentClass::where('ID', $data['StudentClassID'])->first()?->ScheduleMode
                : null;
            $items = $data['Items'] ?? [];

            if (empty($items) && !empty($data['MonthlySplit']) && !empty($data['SplitStart']) && !empty($data['SplitEnd'])) {
                $items = $this->buildMonthlySplitItems(
                    $data['TotalAmount'],
                    $data['SplitStart'],
                    $data['SplitEnd']
                );
            }

            try {
                $invoice = $issuer->issue([
                    'StudentID' => $data['StudentID'],
                    'StudentClassID' => $data['StudentClassID'] ?? null,
                    'IssueDate' => $data['IssueDate'],
                    'DueDate' => $data['DueDate'] ?? null,
                    'TotalAmount' => $data['TotalAmount'],
                    'ScheduleModeAtIssue' => $scheduleModeAtIssue,
                    'Note' => $data['Note'] ?? '',
                    'billing_period' => $data['billing_period'] ?? null,
                ], $items, true);
            } catch (BillingPeriodInvoiceExists) {
                return response()->json([
                    'message' => '此課程該月份已有有效帳單，請勿重複建立。',
                    'code' => 'billing_period_invoice_exists',
                ], 409);
            }

            return response()->json($invoice, 201);
        });
    }

    public function recordPayment(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('student');
        if (!$invoice->student) {
            return response()->json(['message' => 'Invoice has no linked student'], 404); // as voidInvoice(): no campus, no write
        }
        $this->assertInvoiceStudentCampusAllowed($request, (int) $invoice->student->CampusID);

        $data = $request->validate([
            'Amount' => 'required|integer|min:1',
            'PaidAt' => 'nullable|date',
            // `void` marks a reversal in the Payment-row projection; reversals go through the audited void flows only.
            'Method' => 'nullable|string|max:32|not_in:void',
            'Note' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($invoice, $data) {
            // Keep course-before-invoice ordering with invoice void and correction writers.
            $courseIds = $this->lockInvoiceCourses($invoice, true);
            // 先鎖帳單再判斷，避免與確認不收（void）競爭。
            $fresh = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->first();
            if (!$fresh || (string) $fresh->getAttribute('Status') === 'void' || $fresh->isMarkedFreeGift()) { // #3991: a 免費／贈送 bill takes no payment
                return response()->json(['message' => $fresh?->isMarkedFreeGift() ? '免費／贈送帳單不能登記收款；這門課要收費的話，請先作廢這張帳單再開立新帳單' : '作廢帳單不可登記收款'], 422);
            }
            $this->assertInvoiceOwnersUnchanged($fresh, $courseIds, (int) $invoice->StudentID);
            $invoice = $fresh;
            // R28 (#3873 item 4), same rule as payment-report confirm: what is paid comes from Payment rows, never
            // the stored PaidAmount, and a payment may only take what the bill still owes by those rows.
            // A bill spanning several courses cannot be priced from its anchor course's lessons alone.
            $multiCourse = count($courseIds) > 1;
            $before = $this->lockedInvoiceProjection($invoice, $multiCourse);
            $repriced = $before['amount_source'] === 'billable_sessions';
            if ((int) $before['total_amount'] > 0 && (int) $before['outstanding_amount'] <= 0) {
                return response()->json([
                    'message' => '這張帳單已有確認的收款，不能再登記；若帳單狀態對不上，請在學生帳務核對收款紀錄。沒有登記任何款項。',
                    'code' => 'course_already_paid',
                ], 422);
            }
            if (((int) $before['total_amount'] > 0 || $repriced) && (int) $data['Amount'] > (int) $before['outstanding_amount']) {
                return response()->json([
                    'message' => sprintf('這張帳單只欠 NT$%d，請改成 %d 以下。', $before['outstanding_amount'], $before['outstanding_amount']),
                    'code' => 'amount_exceeds_outstanding',
                    'expected_amount' => (int) $before['outstanding_amount'],
                    'invoice_id' => (int) $invoice->id,
                ], 422);
            }
            if ($repriced) {
                // Unpaid monthly bill priced from its held lessons: keep that amount, as confirm does.
                $invoice->TotalAmount = (int) $before['total_amount'];
                if (empty($invoice->billing_snapshot)) {
                    // Same immutable evidence confirm stores, so later session edits cannot explain the amount away.
                    $period = (string) $before['billing_period'];
                    $course = StudentClass::query()->where('ID', (int) $invoice->getAttribute('StudentClassID'))->firstOrFail();
                    $invoice->billing_period = $period;
                    $invoice->billing_snapshot = [
                        'version' => 1,
                        'source' => 'monthly_billable_sessions',
                        'billing_period' => $period,
                        'period_start' => $before['period_start'],
                        'period_end' => $before['period_end'],
                        'period_sessions' => (int) $before['period_sessions'],
                        'charge' => (int) $before['total_amount'],
                        'sessions' => $this->monthlyBilling->billableSessionDetailsForPeriod($course, $period),
                    ];
                }
            }
            $payment = Payment::create([
                'InvoiceID' => $invoice->id,
                'Amount' => $data['Amount'],
                'PaidAt' => $data['PaidAt'] ?? now(),
                'Method' => $data['Method'] ?? 'cash',
                'Note' => $data['Note'] ?? '',
            ]);

            $after = $this->lockedInvoiceProjection($invoice, $multiCourse);
            $invoice->PaidAmount = (int) $after['net_applied'];
            $invoice->Status = (int) $after['outstanding_amount'] <= 0
                ? 'paid'
                : ((int) $after['net_applied'] > 0 ? 'partial' : 'unpaid');

            $invoice->save();

            self::syncStudentClassPaidFromInvoice($invoice);

            return response()->json([
                'invoice' => $invoice,
                'payment' => $payment,
            ]);
        });
    }

    /**
     * The invoice's money from its Payment rows, read under lock (the caller holds the invoice row lock).
     * A multi-course bill keeps its stored total: the held-lesson price covers only the anchor course.
     */
    private function lockedInvoiceProjection(Invoice $invoice, bool $multiCourse): array
    {
        $invoice->setRelation('payments', $invoice->payments()->lockForUpdate()->get());
        $invoice->loadMissing('items');
        $projection = $this->invoiceAmounts->resolve($invoice);
        if ($multiCourse && $projection['amount_source'] === 'billable_sessions') {
            $total = (int) $projection['stored_total_amount'];
            $net = (int) $projection['net_applied'];
            $projection = ['total_amount' => $total, 'amount_source' => 'invoice_total', 'applied_amount' => min($total, $net),
                'overpaid_amount' => max(0, $net - $total), 'outstanding_amount' => max(0, $total - $net)] + $projection;
        }

        return $projection;
    }

    /**
     * Void joins the header owner's count-correction mutex, or the sole item
     * owner when there is no header. Never add a multi-course void lock set:
     * it would invert existing transfer locks. Direct payments retain their
     * pre-existing sorted multi-course protocol.
     */
    private function lockInvoiceCourses(Invoice $invoice, bool $allCourses = false): array
    {
        $headerId = DB::table('Invoice')->where('id', $invoice->getKey())->value('StudentClassID');
        $courseIds = DB::table('InvoiceItem')->where('InvoiceID', $invoice->getKey())->pluck('StudentClassID')
            ->push($headerId)->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        $lockIds = $allCourses ? $courseIds : ($headerId ? [(int) $headerId] : (count($courseIds) === 1 ? $courseIds : []));
        if ($lockIds !== []) {
            StudentClass::query()->whereIn('ID', $lockIds)->orderBy('ID')->lockForUpdate()->get(['ID']);
        }

        return $courseIds;
    }

    /** Called under the fresh invoice lock; locking items bypasses an old RR snapshot. */
    private function assertInvoiceOwnersUnchanged(Invoice $invoice, array $expected, int $studentId): void
    {
        $current = DB::table('InvoiceItem')->where('InvoiceID', $invoice->getKey())->orderBy('id')
            ->lockForUpdate()->pluck('StudentClassID')->push($invoice->StudentClassID)
            ->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if ($current !== $expected || (int) $invoice->StudentID !== $studentId) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(response()->json([
                'message' => '帳單所屬課程已變更，請重新載入後再操作。沒有變更帳款。',
                'code' => 'invoice_owner_changed',
            ], 409));
        }
    }

    public function voidInvoice(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('student');
        if (!$invoice->student) {
            return response()->json(['message' => 'Invoice has no linked student'], 404);
        }
        $this->assertInvoiceStudentCampusAllowed($request, (int) $invoice->student->CampusID);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        return DB::transaction(function () use ($request, $invoice, $data) {
            $studentId = (int) $invoice->StudentID;
            $owners = $this->lockInvoiceCourses($invoice);
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertInvoiceOwnersUnchanged($invoice, $owners, $studentId);
            $status = strtolower((string) ($invoice->Status ?? ''));

            if ($status === 'void') {
                return response()->json(['message' => '此帳單已作廢。'], 422);
            }

            $netApplied = $this->invoiceNetAppliedAmount((int) $invoice->id);

            $freeGiftOnly = $invoice->isMarkedFreeGift() && (int) $invoice->TotalAmount === 0 && !$invoice->payments()->exists(); // #3991: wrong-period mark is correctable
            if ((in_array($status, ['paid', 'partial'], true) && !$freeGiftOnly) || (int) ($invoice->PaidAmount ?? 0) > 0 || $netApplied > 0) {
                return response()->json([
                    'message' => '此帳單已有收款紀錄，請先走收款撤銷/沖銷流程，不可直接作廢帳單。',
                ], 422);
            }

            $authUser = $request->attributes->get('auth_user');
            $reason = trim((string) $data['reason']);
            $audit = sprintf(
                '[void: %s; user_id=%s; at=%s]',
                str_replace(["\r", "\n", '[', ']'], ' ', $reason), // D3: user text cannot forge a note tag
                $authUser?->id ?? 'system',
                now()->format('Y-m-d H:i:s')
            );

            $note = trim((string) ($invoice->Note ?? ''));
            $invoice->Status = 'void';
            $invoice->Note = trim(mb_substr($note, 0, max(0, 254 - mb_strlen($audit))) . ' ' . $audit); // VARCHAR(255): keep the whole audit tag
            $invoice->save();

            Log::info('[InvoiceVoid] invoice voided', [
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->StudentID,
                'student_class_id' => $invoice->StudentClassID,
                'campus_id' => $invoice->student?->CampusID,
                'user_id' => $authUser?->id,
            ]);

            return response()->json([
                'message' => '帳單已作廢。',
                'invoice' => [
                    'id' => (int) $invoice->id,
                    'status' => $invoice->Status,
                    'note' => $invoice->Note,
                ],
            ]);
        });
    }

    public function exceptionVoidInvoice(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('student');
        if (!$invoice->student) {
            return response()->json(['message' => 'Invoice has no linked student'], 404);
        }
        $this->assertInvoiceStudentCampusAllowed($request, (int) $invoice->student->CampusID);

        $data = $request->validate([
            'reason' => 'required|string|min:3|max:255',
        ]);

        return DB::transaction(function () use ($request, $invoice, $data) {
            $studentId = (int) $invoice->StudentID;
            $owners = $this->lockInvoiceCourses($invoice);
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $this->assertInvoiceOwnersUnchanged($invoice, $owners, $studentId);
            $status = strtolower((string) ($invoice->Status ?? ''));

            if ($status === 'void') {
                return response()->json(['message' => '此帳單已作廢。'], 422);
            }

            // In-app #436: voiding the bill under a confirmed receipt left the receipt counted and impossible to undo.
            // A locking (current) read: a plain read keeps this transaction's earlier snapshot and could miss a receipt
            // confirmed while this request waited for the course lock.
            if (PaymentReport::query()->where('InvoiceID', $invoice->id)->where('status', 'confirmed')->lockForUpdate()->get(['id'])->isNotEmpty()) {
                return response()->json([
                    'message' => '這張帳單還有已確認的收據，請先在收款紀錄撤銷收據，再作廢帳單。',
                    'code' => 'confirmed_receipt_on_invoice',
                ], 422);
            }

            $payments = Payment::where('InvoiceID', $invoice->id)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();
            $positiveTotal = (int) $payments
                ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
                ->sum(fn ($payment) => (int) ($payment->Amount ?? 0));
            $voidedTotal = abs((int) $payments
                ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
                ->sum(fn ($payment) => (int) ($payment->Amount ?? 0)));
            $netApplied = max(0, $positiveTotal - $voidedTotal);

            $authUser = $request->attributes->get('auth_user');
            $reason = trim((string) $data['reason']);
            $positivePaymentIds = $payments
                ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $auditType = $netApplied > 0 || (int) ($invoice->PaidAmount ?? 0) > 0 ? 'exception-void' : 'void';
            $audit = sprintf(
                '[%s: %s; user_id=%s; at=%s; positive_payments=%s; net=%s]',
                $auditType,
                str_replace(["\r", "\n"], ' ', $reason),
                $authUser?->id ?? 'system',
                now()->format('Y-m-d H:i:s'),
                implode(',', $positivePaymentIds),
                $netApplied
            );

            $reversalPayment = null;
            if ($netApplied > 0) {
                $reversalPayment = Payment::create([
                    'InvoiceID' => $invoice->id,
                    'Amount' => -$netApplied,
                    'PaidAt' => now(),
                    'Method' => 'void',
                    'Note' => mb_substr('帳單例外沖銷作廢：' . $reason, 0, 255),
                ]);
            }

            $note = trim((string) ($invoice->Note ?? ''));
            $invoice->Status = 'void';
            $invoice->PaidAmount = 0;
            $invoice->Note = mb_substr(trim($note . ' ' . $audit), 0, 255);
            $invoice->save();

            Log::info('[InvoiceExceptionVoid] invoice reversed and voided', [
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->StudentID,
                'student_class_id' => $invoice->StudentClassID,
                'campus_id' => $invoice->student?->CampusID,
                'user_id' => $authUser?->id,
                'net_applied' => $netApplied,
                'reversal_payment_id' => $reversalPayment?->id,
            ]);

            return response()->json([
                'message' => '帳單已沖銷作廢。',
                'invoice' => [
                    'id' => (int) $invoice->id,
                    'status' => $invoice->Status,
                    'paid_amount' => (int) $invoice->PaidAmount,
                    'note' => $invoice->Note,
                ],
                'reversal_payment' => $reversalPayment ? [
                    'id' => (int) $reversalPayment->id,
                    'amount' => (int) $reversalPayment->Amount,
                    'method' => (string) $reversalPayment->Method,
                ] : null,
            ]);
        });
    }

    public function slipData(Invoice $invoice, Request $request)
    {
        $invoice->load([
            'student',
            'studentClass',
            'payments' => fn ($payment) => $payment->select(['id', 'InvoiceID', 'Amount', 'Method']),
            'items',
            'items.studentClass',
        ]);

        if (!$invoice->student) {
            return response()->json(['message' => 'Invoice has no linked student'], 404);
        }
        $this->assertInvoiceStudentCampusAllowed($request, (int) $invoice->student->CampusID);

        // Founder D2 (#4121 review): the 繳費明細 ledger reads this payload for its class dates, so it never fails. An
        // unpaid bill on a 金額待確認 course carries no amounts and the flag; the parent slip refuses to render it.
        $slipCourse = $invoice->getRelationValue('studentClass');
        $courseAmountPending = false;
        if ($slipCourse instanceof StudentClass) {
            $slipCourseId = (int) $slipCourse->getAttribute('ID');
            $slipVerdict = app(BillingPayableResolver::class)
                ->courseStatusesByStudentClassIds([$slipCourseId], [$slipCourse])[$slipCourseId] ?? [];
            $courseAmountPending = BillingPayableResolver::isAmountPendingConfirmation($slipCourse, $slipVerdict);
        }

        $campus = null;
        if ($invoice->student && $invoice->student->CampusID) {
            $campus = Campus::find($invoice->student->CampusID);
        }

        $projection = $this->invoiceAmounts->resolve($invoice, $invoice->getRelationValue('studentClass'));
        $totalAmount = $projection['total_amount'];
        $paidAmount = $projection['net_applied'];
        $remaining = $projection['outstanding_amount'];

        $singleItem = $invoice->items->first();
        $singleCourseItem = $invoice->items->count() === 1
            && $singleItem !== null
            && ($invoice->getRelationValue('studentClass') !== null
                || (int) ($singleItem->StudentClassID ?? 0) > 0);
        $useReconciledItem = $singleCourseItem
            && $projection['amount_discrepancy']
            && $projection['amount_source'] === 'billable_sessions'
            && $projection['period_start'] !== null
            && $projection['period_end'] !== null;

        $items = $invoice->items->map(function ($item) use ($invoice, $projection, $useReconciledItem) {
            $itemAmount = (int) $item->Amount;
            $itemDescription = (string) $item->Description;
            $itemPeriodStart = $item->PeriodStart;
            $itemPeriodEnd = $item->PeriodEnd;

            if ($useReconciledItem) {
                $itemAmount = (int) $projection['total_amount'];
                $itemPeriodStart = $projection['period_start'];
                $itemPeriodEnd = $projection['period_end'];

                $course = $invoice->getRelationValue('studentClass') ?? $item->getRelationValue('studentClass');
                $subject = $course?->displaySubjectName() ?: '課程';
                $billingPeriod = (string) ($projection['billing_period'] ?? '');
                $periodLabel = preg_match('/^(\d{4})-(\d{2})$/', $billingPeriod, $matches)
                    ? sprintf('%s年%d月', $matches[1], (int) $matches[2])
                    : $billingPeriod;
                $itemDescription = sprintf(
                    '%s月結費用 %s（%d堂）',
                    $subject,
                    $periodLabel,
                    (int) ($projection['period_sessions'] ?? 0)
                );
            }

            return [
                'description' => $itemDescription,
                'amount'      => $itemAmount,
                'stored_amount' => (int) $item->Amount,
                'period_start' => $itemPeriodStart,
                'period_end'   => $itemPeriodEnd,
            ];
        });

        $sessions = $this->monthlyBilling->invoiceCoveredSessions($invoice, $projection);
        $amountPending = $courseAmountPending && $remaining > 0; // a paid bill on the same course keeps its amounts
        if ($amountPending) {
            $items = $items->map(fn (array $item) => array_merge($item, ['amount' => null, 'stored_amount' => null]));
        }

        $authUser = $request->attributes->get('auth_user');
        Log::info('[InvoiceSlip] generated', [
            'user_id' => $authUser?->id,
            'invoice_id' => $invoice->id,
            'student_id' => $invoice->StudentID,
            'campus_id' => $invoice->student->CampusID ?? null,
        ]);

        return response()->json([
            'invoice_id'   => $invoice->id,
            'student_name' => $invoice->student->name ?? '—',
            'campus_name'  => $campus->name ?? '',
            'issue_date'   => $invoice->IssueDate,
            'due_date'     => $invoice->DueDate,
            'amount_pending_confirmation' => $amountPending,
            'total_amount' => $amountPending ? null : $totalAmount,
            'stored_total_amount' => $amountPending ? null : $projection['stored_total_amount'],
            'computed_total_amount' => $amountPending ? null : $projection['computed_total_amount'],
            'amount_source' => $projection['amount_source'],
            'amount_discrepancy' => $projection['amount_discrepancy'],
            'period_sessions' => $projection['period_sessions'],
            'paid_amount'  => $amountPending ? null : $paidAmount,
            'remaining'    => $amountPending ? null : $remaining,
            'status'       => $invoice->Status,
            'note'         => $invoice->Note,
            'items'        => $items,
            'sessions' => $sessions,
        ] + FeeCharge::slipBlock( // #439: beside tuition, never in it
            (int) $invoice->student->id,
            (int) $invoice->student->CampusID,
            $invoice->billing_period ?: substr((string) $invoice->IssueDate, 0, 7),
        ));
    }

    /**
     * Decision matrix: Invoice payment → StudentClass.Paid / PayDate sync.
     *
     * | Scenario                            | Paid  | PayDate                  |
     * |-------------------------------------|-------|--------------------------|
     * | Invoice fully paid (PaidAmount>=Total)| 1    | MAX(Payment.PaidAt)      |
     * | Invoice partially paid              | (unchanged) | (unchanged)        |
     * | Director explicitly marks unpaid    | 0     | (preserved via PUT API)  |
     *
     * F7 S7: Paid=1 only when BillingPayableResolver says the course is `paid` over all its non-void
     * invoices; a partial payment leaves the flag as is. Called inside the recordPayment transaction.
     */
    public static function syncStudentClassPaidFromInvoice(Invoice $invoice): void
    {
        $classIds = self::collectStudentClassIdsFromInvoice($invoice);
        if (empty($classIds)) {
            return;
        }

        $paidAt = DB::table('Payment')
            ->where('InvoiceID', $invoice->id)
            ->max('PaidAt');
        $paidDate = $paidAt ? substr($paidAt, 0, 10) : now()->toDateString();

        foreach ($classIds as $classId) {
            $sc = StudentClass::where('ID', $classId)->first();
            if (!$sc) {
                continue;
            }
            if ((app(BillingPayableResolver::class)->courseStatusesByStudentClassIds([(int) $classId])[(int) $classId]['status'] ?? null) !== 'paid') {
                continue;
            }
            $oldPaid = (int) ($sc->Paid ?? 0);
            $oldPayDate = $sc->PayDate;

            $sc->Paid = 1;
            if (empty($sc->PayDate)) {
                $sc->PayDate = $paidDate;
            }
            $sc->save();

            Log::info('[PaymentSync] StudentClass paid status synced', [
                'invoice_id' => $invoice->id,
                'student_class_id' => $classId,
                'old_paid' => $oldPaid,
                'new_paid' => 1,
                'old_pay_date' => $oldPayDate,
                'new_pay_date' => $sc->PayDate,
            ]);
        }
    }

    public static function collectStudentClassIdsFromInvoice(Invoice $invoice): array
    {
        $ids = [];
        if ((int) ($invoice->StudentClassID ?? 0) > 0) {
            $ids[] = (int) $invoice->StudentClassID;
        }
        $invoice->loadMissing('items');
        foreach ($invoice->getRelationValue('items') as $item) {
            $itemClassId = (int) ($item->StudentClassID ?? 0);
            if ($itemClassId > 0) {
                $ids[] = $itemClassId;
            }
        }
        return array_values(array_unique($ids));
    }

    private function assertInvoiceStudentCampusAllowed(Request $request, int $studentCampusId): void
    {
        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin' ? [] : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if (!empty($campusIds) && !in_array($studentCampusId, $campusIds, true)) {
            abort(403);
        }
    }

    private function buildMonthlySplitItems(int $totalAmount, string $start, string $end): array
    {
        $startDate = Carbon::parse($start)->startOfMonth();
        $endDate = Carbon::parse($end)->startOfMonth();

        $months = [];
        $cursor = $startDate->copy();

        while ($cursor->lte($endDate)) {
            $months[] = $cursor->copy();
            $cursor->addMonth();
        }

        $count = max(count($months), 1);
        $base = intdiv($totalAmount, $count);
        $remainder = $totalAmount - ($base * $count);

        $items = [];

        foreach ($months as $index => $month) {
            $amount = $base + ($index === ($count - 1) ? $remainder : 0);
            // Whole months in between; the first and last items keep the
            // requested endpoints (amounts are still split evenly per month).
            $periodStart = max($month->copy()->startOfMonth()->toDateString(), Carbon::parse($start)->toDateString());
            $periodEnd = min($month->copy()->endOfMonth()->toDateString(), Carbon::parse($end)->toDateString());
            $items[] = [
                'Description' => 'Monthly tuition ' . $month->format('Y-m'),
                'Amount' => $amount,
                'PeriodStart' => $periodStart,
                'PeriodEnd' => $periodEnd,
            ];
        }

        return $items;
    }

    private function invoiceNetAppliedAmount(int $invoiceId): int
    {
        $payments = Payment::where('InvoiceID', $invoiceId)->get(['Amount', 'Method']);
        $positiveTotal = (int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) > 0 && (string) ($payment->Method ?? '') !== 'void')
            ->sum(fn ($payment) => (int) ($payment->Amount ?? 0));
        $voidedTotal = abs((int) $payments
            ->filter(fn ($payment) => (int) ($payment->Amount ?? 0) < 0 || (string) ($payment->Method ?? '') === 'void')
            ->sum(fn ($payment) => (int) ($payment->Amount ?? 0)));

        return max(0, $positiveTotal - $voidedTotal);
    }
}
