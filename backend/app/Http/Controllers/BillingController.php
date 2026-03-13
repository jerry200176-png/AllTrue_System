<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillingController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query();

        if ($request->filled('student_id')) {
            $query->where('StudentID', $request->input('student_id'));
        }

        if ($request->filled('status')) {
            $query->where('Status', $request->input('status'));
        }

        $invoices = $query->orderBy('id', 'desc')->paginate(20);

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

            return response()->json([
                'invoice' => $invoice,
                'payment' => $payment,
            ]);
        });
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
