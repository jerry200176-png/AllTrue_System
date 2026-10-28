<?php

namespace App\Http\Controllers;

use App\Models\FeeCharge;
use App\Models\FeePayout;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * In-app #439: pass-through fees (代收) and payouts (代付) per campus. A separate ledger: nothing here reads or writes
 * Invoice, Payment or StudentClass, so tuition status, AR, revenue and the GL export are untouched.
 */
class FeeLedgerController extends Controller
{
    /** scope=month (default): charges charged and payouts paid in `month`; scope=unpaid: every unpaid charge. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => 'required|integer',
            'scope' => 'nullable|in:month,unpaid',
            'month' => 'required_unless:scope,unpaid|nullable|date_format:Y-m',
        ]);
        $campusId = $this->allowedCampus($request, (int) $data['campus_id']);
        $charges = FeeCharge::query()->with('student:id,name')->where('campus_id', $campusId);
        $payouts = FeePayout::query()->where('campus_id', $campusId);
        if (($data['scope'] ?? 'month') === 'unpaid') {
            $charges->whereNull('paid_on')->whereNull('voided_at');
            $payouts->whereRaw('1 = 0');
        } else {
            // '!' zeroes the unparsed fields: without it the 31st of today rolls 2026-09 into October.
            $month = Carbon::createFromFormat('!Y-m', $data['month']);
            $start = $month->copy()->startOfMonth()->toDateString();
            $end = $month->copy()->endOfMonth()->toDateString();
            $charges->whereBetween('charged_on', [$start, $end]);
            $payouts->whereBetween('paid_on', [$start, $end]);
        }
        $charges = $charges->orderByDesc('charged_on')->orderByDesc('id')->get();
        $payouts = $payouts->orderByDesc('paid_on')->orderByDesc('id')->get();

        return response()->json([
            'charges' => $charges->map(fn (FeeCharge $c) => $this->chargeRow($c))->values(),
            'payouts' => $payouts->map(fn (FeePayout $p) => $this->payoutRow($p))->values(),
            'summary' => $this->summary($charges, $payouts),
            'categories' => FeeCharge::CATEGORIES,
            'methods' => FeeCharge::METHODS,
        ]);
    }

    /** One charge per selected student (a class's books in one step). Every student must belong to the campus. */
    public function storeCharges(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => 'required|integer',
            'student_ids' => 'required|array|min:1|max:200',
            'student_ids.*' => 'required|integer|distinct',
            'category' => ['required', Rule::in(array_keys(FeeCharge::CATEGORIES))],
            'title' => 'required|string|max:60',
            'amount' => 'required|integer|min:1|max:200000',
            'charged_on' => 'nullable|date|before_or_equal:today',
        ]);
        $campusId = $this->allowedCampus($request, (int) $data['campus_id']);
        $ids = array_map('intval', $data['student_ids']);
        if (Student::query()->whereIn('id', $ids)->where('CampusID', $campusId)->count() !== count($ids)) {
            return response()->json(['message' => '有學生不在這個分校，請重新選擇。', 'code' => 'student_not_in_campus'], 422);
        }
        $userId = (int) $request->attributes->get('auth_user')->id;
        $created = DB::transaction(function () use ($ids, $campusId, $data, $userId) {
            // Same Student row lock (id order) as StudentController's purge, so a concurrent delete can't slip between
            // its money check and this insert; re-check inside the lock.
            $locked = DB::table('Student')->whereIn('id', $ids)->where('CampusID', $campusId)->orderBy('id')->lockForUpdate()->pluck('id');
            if ($locked->count() !== count($ids)) {
                return null;
            }

            return collect($ids)->map(fn (int $id) => FeeCharge::create([
                'campus_id' => $campusId, 'student_id' => $id, 'category' => $data['category'], 'title' => trim($data['title']),
                'amount' => (int) $data['amount'], 'charged_on' => $data['charged_on'] ?? Carbon::today()->toDateString(),
                'created_by' => $userId,
            ]));
        });
        if ($created === null) {
            return response()->json(['message' => '有學生不在這個分校，請重新選擇。', 'code' => 'student_not_in_campus'], 422);
        }

        return response()->json(['charges' => $created->map(fn (FeeCharge $c) => $this->chargeRow($c->load('student:id,name')))], 201);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'paid_on' => 'required|date|before_or_equal:today',
            'method' => ['required', Rule::in(array_keys(FeeCharge::METHODS))],
        ]);

        return DB::transaction(function () use ($request, $id, $data) {
            $charge = $this->lockedCharge($request, $id);
            if ($refusal = $this->chargeRefusal($charge)) {
                return $refusal;
            }
            $charge->update(['paid_on' => $data['paid_on'], 'paid_method' => $data['method'], 'paid_by' => (int) $request->attributes->get('auth_user')->id]);

            return response()->json(['charge' => $this->chargeRow($charge->load('student:id,name'))]);
        });
    }

    /** A paid charge can be voided too (money returned or recorded by mistake); the row keeps when it was paid. */
    public function voidCharge(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:100']);

        return DB::transaction(function () use ($request, $id, $data) {
            $charge = $this->lockedCharge($request, $id);
            if ($charge->voided_at !== null) {
                return response()->json(['message' => '這筆代收款已經作廢。', 'code' => 'fee_charge_voided'], 409);
            }
            $charge->update(['voided_at' => Carbon::now(), 'voided_by' => (int) $request->attributes->get('auth_user')->id, 'void_reason' => trim($data['reason'])]);

            return response()->json(['charge' => $this->chargeRow($charge->load('student:id,name'))]);
        });
    }

    public function storePayout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campus_id' => 'required|integer',
            'category' => ['required', Rule::in(array_keys(FeeCharge::CATEGORIES))],
            'title' => 'required|string|max:60',
            'amount' => 'required|integer|min:1|max:2000000',
            'paid_on' => 'required|date|before_or_equal:today',
        ]);
        $payout = FeePayout::create([
            'campus_id' => $this->allowedCampus($request, (int) $data['campus_id']), 'category' => $data['category'],
            'title' => trim($data['title']), 'amount' => (int) $data['amount'], 'paid_on' => $data['paid_on'],
            'created_by' => (int) $request->attributes->get('auth_user')->id,
        ]);

        return response()->json(['payout' => $this->payoutRow($payout)], 201);
    }

    public function voidPayout(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:100']);

        return DB::transaction(function () use ($request, $id, $data) {
            $payout = FeePayout::query()->whereKey($id)->lockForUpdate()->first();
            abort_unless($payout instanceof FeePayout, 404);
            $this->allowedCampus($request, (int) $payout->campus_id);
            if ($payout->voided_at !== null) {
                return response()->json(['message' => '這筆代付已經作廢。', 'code' => 'fee_payout_voided'], 409);
            }
            $payout->update(['voided_at' => Carbon::now(), 'voided_by' => (int) $request->attributes->get('auth_user')->id, 'void_reason' => trim($data['reason'])]);

            return response()->json(['payout' => $this->payoutRow($payout)]);
        });
    }

    private function allowedCampus(Request $request, int $campusId): int
    {
        if ($request->attributes->get('auth_role') !== 'super_admin'
            && !in_array($campusId, array_map('intval', (array) $request->attributes->get('auth_campus_ids', [])), true)) {
            abort(403);
        }

        return $campusId;
    }

    private function lockedCharge(Request $request, int $id): FeeCharge
    {
        $charge = FeeCharge::query()->whereKey($id)->lockForUpdate()->first();
        abort_unless($charge instanceof FeeCharge, 404);
        $this->allowedCampus($request, (int) $charge->campus_id);

        return $charge;
    }

    private function chargeRefusal(FeeCharge $charge): ?JsonResponse
    {
        if ($charge->voided_at !== null) {
            return response()->json(['message' => '這筆代收款已經作廢，不能再收款。', 'code' => 'fee_charge_voided'], 409);
        }
        if ($charge->paid_on !== null) {
            return response()->json(['message' => '這筆代收款已經收過了。', 'code' => 'fee_charge_already_paid'], 409);
        }

        return null;
    }

    private function chargeRow(FeeCharge $c): array
    {
        return [
            'id' => (int) $c->id, 'student_id' => (int) $c->student_id, 'student_name' => $c->student?->name,
            'category' => $c->category, 'category_label' => FeeCharge::CATEGORIES[$c->category] ?? $c->category,
            'title' => $c->title, 'amount' => (int) $c->amount, 'charged_on' => $c->charged_on?->toDateString(),
            'status' => $c->status(), 'paid_on' => $c->paid_on?->toDateString(), 'paid_method' => $c->paid_method,
            'voided_at' => $c->voided_at?->toIso8601String(), 'void_reason' => $c->void_reason,
        ];
    }

    private function payoutRow(FeePayout $p): array
    {
        return [
            'id' => (int) $p->id, 'category' => $p->category, 'category_label' => FeeCharge::CATEGORIES[$p->category] ?? $p->category,
            'title' => $p->title, 'amount' => (int) $p->amount, 'paid_on' => $p->paid_on?->toDateString(),
            'status' => $p->voided_at !== null ? 'voided' : 'active', 'voided_at' => $p->voided_at?->toIso8601String(), 'void_reason' => $p->void_reason,
        ];
    }

    /** Per category: charged, collected, outstanding (charged - collected), paid out, balance (collected - paid out). */
    private function summary(Collection $charges, Collection $payouts): array
    {
        $live = $charges->whereNull('voided_at');
        $paidOut = $payouts->whereNull('voided_at');
        $rows = [];
        foreach (FeeCharge::CATEGORIES as $key => $label) {
            $c = $live->where('category', $key);
            $charged = (int) $c->sum('amount');
            $collected = (int) $c->filter(fn (FeeCharge $x) => $x->paid_on !== null)->sum('amount');
            $out = (int) $paidOut->where('category', $key)->sum('amount');
            if ($charged === 0 && $out === 0) {
                continue;
            }
            $rows[] = ['category' => $key, 'label' => $label, 'charged' => $charged, 'collected' => $collected,
                'outstanding' => $charged - $collected, 'paid_out' => $out, 'balance' => $collected - $out];
        }
        $total = fn (string $k) => array_sum(array_column($rows, $k));

        return ['rows' => $rows, 'total' => ['charged' => $total('charged'), 'collected' => $total('collected'),
            'outstanding' => $total('outstanding'), 'paid_out' => $total('paid_out'), 'balance' => $total('balance')]];
    }
}
