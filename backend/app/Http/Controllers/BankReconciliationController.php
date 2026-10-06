<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\StudentClass;
use App\Services\BillingPayableResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BankReconciliationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (!Schema::hasTable('bank_transactions')) {
            return response()->json(['transactions' => [], 'summary' => []]);
        }

        $role = $request->attributes->get('auth_role');
        $campusIds = $role === 'super_admin'
            ? []
            : array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        $query = BankTransaction::query()->orderByDesc('transaction_date');

        if ($request->filled('branch_id')) {
            $query->where('campus_id', (int) $request->input('branch_id'));
        } elseif (!empty($campusIds)) {
            $query->whereIn('campus_id', $campusIds);
        }

        $status = $request->input('status', 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $transactions = $query->limit(200)->get()->map(fn ($t) => [
            'id' => (int) $t->id,
            'campus_id' => (int) $t->campus_id,
            'transaction_date' => $t->transaction_date->toDateString(),
            'amount' => (int) $t->amount,
            'reference' => $t->reference,
            'description' => $t->description,
            'bank_name' => $t->bank_name,
            'status' => $t->status,
            'matched_payment_id' => $t->matched_payment_id ? (int) $t->matched_payment_id : null,
            'reconciled_at' => $t->reconciled_at?->toIso8601String(),
        ]);

        $allQ = BankTransaction::query();
        if ($request->filled('branch_id')) {
            $allQ->where('campus_id', (int) $request->input('branch_id'));
        } elseif (!empty($campusIds)) {
            $allQ->whereIn('campus_id', $campusIds);
        }

        return response()->json([
            'transactions' => $transactions,
            'summary' => [
                'total' => (clone $allQ)->count(),
                'unmatched' => (clone $allQ)->where('status', 'unmatched')->count(),
                'matched' => (clone $allQ)->where('status', 'matched')->count(),
                'reconciled' => (clone $allQ)->where('status', 'reconciled')->count(),
            ],
        ]);
    }

    public function importCsv(Request $request): JsonResponse
    {
        if (!Schema::hasTable('bank_transactions')) {
            return response()->json(['message' => 'Table not available'], 503);
        }

        $request->validate([
            'campus_id' => 'required|integer',
            'transactions' => 'required|array|min:1|max:500',
            'transactions.*.date' => 'required|date',
            'transactions.*.amount' => 'required|integer',
            'transactions.*.reference' => 'nullable|string|max:100',
            'transactions.*.description' => 'nullable|string|max:255',
            'transactions.*.bank_name' => 'nullable|string|max:50',
        ]);

        $campusId = (int) $request->input('campus_id');
        $imported = 0;
        $skipped = 0;

        foreach ($request->input('transactions') as $row) {
            $exists = BankTransaction::where('campus_id', $campusId)
                ->where('transaction_date', $row['date'])
                ->where('amount', (int) $row['amount'])
                ->where('reference', $row['reference'] ?? null)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            BankTransaction::create([
                'campus_id' => $campusId,
                'transaction_date' => $row['date'],
                'amount' => (int) $row['amount'],
                'reference' => $row['reference'] ?? null,
                'description' => $row['description'] ?? null,
                'bank_name' => $row['bank_name'] ?? null,
                'status' => 'unmatched',
            ]);
            $imported++;
        }

        return response()->json([
            'imported' => $imported,
            'skipped' => $skipped,
        ]);
    }

    public function suggestMatches(Request $request, int $id): JsonResponse
    {
        if (!Schema::hasTable('bank_transactions')) {
            return response()->json(['suggestions' => []]);
        }

        $txn = BankTransaction::findOrFail($id);
        $role = $request->attributes->get('auth_role');
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));
        if ($role !== 'super_admin' && !in_array((int) $txn->campus_id, $campusIds, true)) {
            abort(403);
        }
        $amount = (int) $txn->amount;
        // F7 S3d (B24): match the bank amount against what is still owed on open invoices (resolver
        // outstanding of unpaid/partial courses), not the legacy StudentClass.Pay / Paid=1 pair.
        // No date window any more (an unsettled invoice has no payment date): payment_date is the oldest
        // open invoice IssueDate and confidence is high only when exactly one course matches.
        // Bounded: only the transaction's campus, and only invoices whose stored remaining or total equals
        // the bank amount (cheap SQL pre-filter); the resolver then confirms the real outstanding.
        $open = DB::table('Invoice')
            ->join('Student', 'Student.id', '=', 'Invoice.StudentID')
            ->where('Student.CampusID', (int) $txn->campus_id)
            ->whereNotNull('Invoice.StudentClassID')
            ->where(fn ($q) => $q->where('Invoice.TotalAmount', $amount)
                ->orWhereRaw('Invoice.TotalAmount - COALESCE(Invoice.PaidAmount, 0) = ?', [$amount]))
            ->where(fn ($q) => $q->whereNull('Invoice.Status')->orWhereNotIn('Invoice.Status', ['void', 'paid']))
            ->groupBy('Invoice.StudentClassID')
            ->selectRaw('Invoice.StudentClassID AS StudentClassID, MIN(Invoice.IssueDate) AS oldest_issue')
            ->limit(200)
            ->pluck('oldest_issue', 'StudentClassID');
        $matches = [];
        foreach ($open->keys()->chunk(500) as $ids) {
            $courses = StudentClass::with('student')->whereIn('ID', $ids->all())->get();
            $statuses = app(BillingPayableResolver::class)->courseStatusesByStudentClassIds($ids->all(), $courses);
            foreach ($courses as $c) {
                $st = $statuses[(int) $c->ID] ?? null;
                if ($st && in_array($st['status'], ['unpaid', 'partial'], true) && (int) $st['outstanding'] === $amount) {
                    $matches[] = [
                        'student_class_id' => (int) $c->ID,
                        'student_name' => $c->student->name ?? '',
                        'amount' => $amount,
                        'payment_date' => $open[(int) $c->ID] ? substr((string) $open[(int) $c->ID], 0, 10) : null,
                    ];
                }
            }
        }
        $candidates = collect($matches)->take(10)
            ->map(fn ($m) => $m + ['confidence' => count($matches) === 1 ? 'high' : 'medium']);

        return response()->json(['suggestions' => $candidates]);
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        if (!Schema::hasTable('bank_transactions')) {
            return response()->json(['message' => 'not available'], 503);
        }

        $request->validate([
            'student_class_id' => 'required|integer',
        ]);

        $txn = BankTransaction::findOrFail($id);
        if ($txn->status === 'reconciled') {
            return response()->json(['message' => '此筆已勾稽'], 422);
        }

        $user = $request->attributes->get('auth_user');

        $txn->update([
            'matched_payment_id' => (int) $request->input('student_class_id'),
            'reconciled_at' => now(),
            'reconciled_by' => (int) $user->id,
            'status' => 'reconciled',
        ]);

        return response()->json(['message' => '勾稽成功']);
    }
}
