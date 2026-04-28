<?php

namespace App\Http\Controllers;

use App\Models\Campus;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
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

        $invoices = $query->with(['student', 'items', 'items.studentClass'])->orderBy('id', 'desc')->paginate(20);

        return response()->json($invoices);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'StudentID' => 'required|integer',
            'StudentClassID' => 'nullable|integer',
            'IssueDate' => 'required|date',
            'DueDate' => 'nullable|date',
            'TotalAmount' => 'required|integer|min:0',
            'Note' => 'nullable|string|max:255',
            'Items' => 'nullable|array',
            'Items.*.Description' => 'required_with:Items|string|max:255',
            'Items.*.Amount' => 'required_with:Items|integer|min:0',
            'Items.*.StudentClassID' => 'nullable|integer',
            'Items.*.PeriodStart' => 'nullable|date',
            'Items.*.PeriodEnd' => 'nullable|date',
            'MonthlySplit' => 'nullable|boolean',
            'SplitStart' => 'nullable|date',
            'SplitEnd' => 'nullable|date',
        ]);

        return DB::transaction(function () use ($data) {
            $invoice = Invoice::create([
                'StudentID' => $data['StudentID'],
                'StudentClassID' => $data['StudentClassID'] ?? null,
                'IssueDate' => $data['IssueDate'],
                'DueDate' => $data['DueDate'] ?? null,
                'TotalAmount' => $data['TotalAmount'],
                'PaidAmount' => 0,
                'Status' => 'unpaid',
                'Note' => $data['Note'] ?? '',
            ]);

            $items = $data['Items'] ?? [];

            if (empty($items) && !empty($data['MonthlySplit']) && !empty($data['SplitStart']) && !empty($data['SplitEnd'])) {
                $items = $this->buildMonthlySplitItems(
                    $data['TotalAmount'],
                    $data['SplitStart'],
                    $data['SplitEnd']
                );
            }

            foreach ($items as $item) {
                InvoiceItem::create([
                    'InvoiceID' => $invoice->id,
                    'StudentClassID' => $item['StudentClassID'] ?? $data['StudentClassID'] ?? null,
                    'Description' => $item['Description'],
                    'Amount' => $item['Amount'],
                    'PeriodStart' => $item['PeriodStart'] ?? null,
                    'PeriodEnd' => $item['PeriodEnd'] ?? null,
                ]);
            }

            return response()->json($invoice, 201);
        });
    }

    public function recordPayment(Request $request, Invoice $invoice)
    {
        $invoice->loadMissing('student');
        if ($invoice->student) {
            $this->assertInvoiceStudentCampusAllowed($request, (int) $invoice->student->CampusID);
        }

        $data = $request->validate([
            'Amount' => 'required|integer|min:1',
            'PaidAt' => 'nullable|date',
            'Method' => 'nullable|string|max:32',
            'Note' => 'nullable|string|max:255',
        ]);

        return DB::transaction(function () use ($invoice, $data) {
            $payment = Payment::create([
                'InvoiceID' => $invoice->id,
                'Amount' => $data['Amount'],
                'PaidAt' => $data['PaidAt'] ?? now(),
                'Method' => $data['Method'] ?? 'cash',
                'Note' => $data['Note'] ?? '',
            ]);

            $invoice->PaidAmount = (int) $invoice->PaidAmount + (int) $data['Amount'];

            if ($invoice->PaidAmount >= $invoice->TotalAmount) {
                $invoice->Status = 'paid';
            } elseif ($invoice->PaidAmount > 0) {
                $invoice->Status = 'partial';
            }

            $invoice->save();

            self::syncStudentClassPaidFromInvoice($invoice);

            return response()->json([
                'invoice' => $invoice,
                'payment' => $payment,
            ]);
        });
    }

    public function slipData(Invoice $invoice, Request $request)
    {
        $invoice->load(['student', 'items', 'items.studentClass']);

        if (!$invoice->student) {
            return response()->json(['message' => 'Invoice has no linked student'], 404);
        }
        $this->assertInvoiceStudentCampusAllowed($request, (int) $invoice->student->CampusID);

        $campus = null;
        if ($invoice->student && $invoice->student->CampusID) {
            $campus = Campus::find($invoice->student->CampusID);
        }

        $remaining = max(0, (int) $invoice->TotalAmount - (int) $invoice->PaidAmount);

        $items = $invoice->items->map(function ($item) {
            return [
                'description' => $item->Description,
                'amount'      => (int) $item->Amount,
                'period_start' => $item->PeriodStart,
                'period_end'   => $item->PeriodEnd,
            ];
        });

        $studentClassIds = $invoice->items
            ->pluck('StudentClassID')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        if ((int) ($invoice->StudentClassID ?? 0) > 0) {
            $studentClassIds[] = (int) $invoice->StudentClassID;
            $studentClassIds = array_values(array_unique($studentClassIds));
        }
        $sessions = ClassSession::sessionsForPaymentSlip($studentClassIds);

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
            'total_amount' => (int) $invoice->TotalAmount,
            'paid_amount'  => (int) $invoice->PaidAmount,
            'remaining'    => $remaining,
            'status'       => $invoice->Status,
            'note'         => $invoice->Note,
            'items'        => $items,
            'sessions' => $sessions,
        ]);
    }

    /**
     * Decision matrix: Invoice payment → StudentClass.Paid / PayDate sync.
     *
     * | Scenario                            | Paid  | PayDate                  |
     * |-------------------------------------|-------|--------------------------|
     * | Invoice fully paid (PaidAmount>=Total)| 1    | MAX(Payment.PaidAt)      |
     * | Invoice partially paid              | 1     | MAX(Payment.PaidAt)      |
     * | Director explicitly marks unpaid    | 0     | (preserved via PUT API)  |
     *
     * Called inside the recordPayment transaction.
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
        foreach ($invoice->items as $item) {
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
            $periodStart = $month->copy()->startOfMonth()->toDateString();
            $periodEnd = $month->copy()->endOfMonth()->toDateString();
            $items[] = [
                'Description' => 'Monthly tuition ' . $month->format('Y-m'),
                'Amount' => $amount,
                'PeriodStart' => $periodStart,
                'PeriodEnd' => $periodEnd,
            ];
        }

        return $items;
    }
}
