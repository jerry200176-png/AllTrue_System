<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\PaymentReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        if (!empty($campusIds)) {
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
        $amount = (int) $txn->amount;
        $date = $txn->transaction_date;

        $candidates = PaymentReport::query()
            ->where('status', 'confirmed')
            ->whereNull('reconciled_at')
            ->whereRaw('CAST(reported_amount AS SIGNED) = ?', [$amount])
            ->whereBetween('payment_date', [$date->copy()->subDays(3), $date->copy()->addDays(3)])
            ->with('student:id,name')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'payment_report_id' => (int) $r->id,
                'student_name' => $r->student?->name ?? '',
                'amount' => (int) round((float) $r->reported_amount),
                'payment_date' => $r->payment_date->toDateString(),
                'confidence' => $r->payment_date->toDateString() === $date->toDateString() ? 'high' : 'medium',
            ]);

        return response()->json(['suggestions' => $candidates]);
    }

    public function reconcile(Request $request, int $id): JsonResponse
    {
        if (!Schema::hasTable('bank_transactions')) {
            return response()->json(['message' => 'not available'], 503);
        }

        $request->validate([
            'payment_report_id' => 'required|integer',
        ]);

        $txn = BankTransaction::findOrFail($id);
        if ($txn->status === 'reconciled') {
            return response()->json(['message' => '此筆已勾稽'], 422);
        }

        $user = $request->attributes->get('auth_user');

        $txn->update([
            'matched_payment_id' => (int) $request->input('payment_report_id'),
            'reconciled_at' => now(),
            'reconciled_by' => (int) $user->id,
            'status' => 'reconciled',
        ]);

        return response()->json(['message' => '勾稽成功']);
    }
}
