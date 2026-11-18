<?php

namespace App\Services\Billing;

use App\Models\{Invoice, Payment, SecurityAuditEvent, StudentClass};
use App\Services\{ClassSessionIndexReadService, InvoiceAmountReconciliationService, MonthlyBillingService, StudentClassPricingService};
use App\Support\DeadlockRetry;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * RFC_MONTHLY_BILL_LIFECYCLE §3.6 (step 2): a director fixes one bill in the app. The bill is voided and replaced by a new one
 * (InvoiceIssuer); everything paid so far moves with a transfer_out / transfer_in pair, so cash is unchanged and the old bill ends at 0.
 *
 * Two shapes. Count: the director types the new total. Monthly: the server adds the lessons no live bill covers (coverage gap).
 * One `invoice_corrections` row (kind bill_correction; new_charge = the new bill total, old_charge = the course Charge before).
 * Off unless the bill's campus is listed in config billing.bill_correction_campus_ids (empty = off).
 * Lock order: course, reports, bills, items, payments. Apply recomputes the plan under the locks and needs the preview's state_hash.
 */
final class BillCorrection
{
    public const KIND = 'bill_correction';
    public const AUDIT_EVENT = 'billing.bill_corrected';
    private const HASH_VERSION = 'v1';
    /** Money rows only a correction may have written (tagged); any other row of these methods means a refund or another transfer touched the bill. */
    private const MOVED_METHODS = ['transfer_in', 'transfer_out', 'transfer_reversal_in', 'transfer_reversal_out', 'refund'];

    public function __construct(private InvoiceAmountReconciliationService $amounts)
    {
    }

    public static function tag(int $correctionId): string
    {
        return "[bill_correction:{$correctionId}]";
    }

    public static function idOf(?string $note): ?int
    {
        return preg_match('/^\[bill_correction:(\d+)\]/', (string) $note, $m) ? (int) $m[1] : null;
    }

    public static function campusOn(int $campusId): bool
    {
        return $campusId > 0 && in_array($campusId, array_map('intval', (array) config('billing.bill_correction_campus_ids', [])), true);
    }

    /** The extension columns of the #4132 history table; without them nothing here may run (fail closed). */
    public static function schemaReady(): bool
    {
        return Schema::hasColumns('invoice_corrections', ['kind', 'campus_id', 'old_charge', 'new_charge', 'original_invoice_total', 'request_hash', 'state_hash'])
            && Schema::hasTable('security_audit_events');
    }

    /** Whether a correction of this kind moved the bill's money away (void() must then follow it). */
    public static function isCorrected(int $invoiceId): bool
    {
        return self::schemaReady() && DB::table('invoice_corrections')->where('kind', self::KIND)->where('original_invoice_id', $invoiceId)->exists();
    }

    /** For the confirm refusals: the bill to correct when the course is count mode and its Charge differs from the bill total, else null. */
    public static function correctableInvoiceId(int $courseId, ?Invoice $invoice = null): ?int
    {
        $course = StudentClass::query()->whereKey($courseId)->first();
        if (!$course || (string) $course->ScheduleMode === 'date' || (int) $course->PackageID > 0
            || !self::campusOn((int) DB::table('Student')->where('id', $course->StudentID)->value('CampusID'))) {
            return null;
        }
        $bill = $invoice ?? Invoice::query()->notVoided()->where('StudentClassID', $courseId)->orderByDesc('id')->first();

        return $bill && (int) $course->Charge !== (int) $bill->TotalAmount ? (int) $bill->id : null;
    }

    /**
     * @param array{new_total?:int|null, reason:string, state_hash?:string|null} $input
     * @param array{id:int, role:string, campus_ids:list<int>} $actor
     * @return array{status:int, body:array<string, mixed>}
     */
    public function preview(int $invoiceId, array $input, array $actor): array
    {
        return $this->run($invoiceId, $input, $actor, false);
    }

    /** @return array{status:int, body:array<string, mixed>} 201 written, 200 replay of the same request */
    public function apply(int $invoiceId, array $input, array $actor): array
    {
        return $this->run($invoiceId, $input, $actor, true);
    }

    private function run(int $invoiceId, array $input, array $actor, bool $apply): array
    {
        // Routing read before any lock: authz and the campus switch answer before any state is looked at.
        $route = DB::table('Invoice')->join('Student', 'Student.id', '=', 'Invoice.StudentID')->where('Invoice.id', $invoiceId)
            ->first(['Invoice.StudentClassID as course_id', 'Student.CampusID as campus_id']);
        if (!$route) {
            return $this->refuse('invoice_not_found', '找不到這張帳單。', 404);
        }
        $campusId = (int) $route->campus_id;
        if (!in_array($actor['role'], ['director', 'super_admin'], true) || ($actor['role'] !== 'super_admin' && !in_array($campusId, $actor['campus_ids'], true))) {
            return ['status' => 403, 'body' => ['message' => 'Forbidden']];
        }
        if (!self::campusOn($campusId)) {
            return $this->refuse('bill_correction_off', '這個分校還沒有開放「更正帳單」。', 404);
        }
        if (!self::schemaReady()) {
            return $this->refuse('unavailable', '更正功能的資料表尚未準備完成。', 503);
        }
        $courseId = (int) $route->course_id;
        if ($courseId <= 0) {
            return $this->refuse('unsupported_bill', '這張帳單沒有對應的課程，不能更正。');
        }
        if (!$apply) {
            return $this->plan($invoiceId, $courseId, $campusId, $input, false)['out'];
        }

        return DB::transaction(function () use ($invoiceId, $courseId, $campusId, $input, $actor) {
            $plan = $this->plan($invoiceId, $courseId, $campusId, $input, true, (string) ($input['state_hash'] ?? ''), (int) $actor['id']);
            if (isset($plan['replay'])) {
                return $plan['replay'];
            }
            if ($plan['out']['status'] !== 200) {
                return $plan['out'];
            }
            if (!hash_equals((string) $plan['out']['body']['state_hash'], (string) ($input['state_hash'] ?? ''))) {
                return $this->refuse('state_changed', '畫面上的帳單資料已經變動，請重新預覽確認後再送出。', 409);
            }

            return $this->write($plan['ctx'], $input, $actor, $campusId);
        }, DeadlockRetry::ATTEMPTS);
    }

    /**
     * Load (with locks when $lock), check and price one correction. `out` is the preview answer or a refusal; `ctx` the rows to write;
     * `replay` the stored answer when the same request was already applied.
     *
     * @return array{out:array<string, mixed>, ctx?:array<string, mixed>, replay?:array<string, mixed>}
     */
    private function plan(int $invoiceId, int $courseId, int $campusId, array $input, bool $lock, string $stateHash = '', int $actorId = 0): array
    {
        $q = fn ($query) => $lock ? $query->lockForUpdate() : $query;
        $course = $q(StudentClass::query()->whereKey($courseId))->first();
        $reports = $q(DB::table('payment_reports')->where(fn ($w) => $w->where('StudentClassID', $courseId)->orWhere('InvoiceID', $invoiceId))->orderBy('id'))->get();
        $invoices = $q(DB::table('Invoice')->where(fn ($w) => $w->where('StudentClassID', $courseId)->orWhere('id', $invoiceId))->orderBy('id'))->get();
        $ids = $invoices->pluck('id')->all();
        $items = $q(DB::table('InvoiceItem')->whereIn('InvoiceID', $ids)->orderBy('id'))->get();
        $payments = $q(DB::table('Payment')->whereIn('InvoiceID', $ids)->orderBy('id'))->get();
        $bill = $invoices->firstWhere('id', $invoiceId);
        if (!$course || !$bill) {
            return ['out' => $this->refuse('invoice_not_found', '找不到這張帳單。', 404)];
        }
        $prior = DB::table('invoice_corrections')->where('original_invoice_id', $invoiceId)->first();
        if ($prior) {
            $same = $prior->kind === self::KIND && $stateHash !== '' && hash_equals((string) $prior->idempotency_key, self::idempotencyKey($invoiceId, $stateHash));

            // The same preview with another total, reason or operator is not the same request.
            if ($same && !hash_equals((string) $prior->request_hash, self::requestHash($invoiceId, $input, $actorId))) {
                return ['out' => $this->refuse('idempotency_conflict', '這張帳單已用另一組內容更正過，請重新整理學生帳務。', 409)];
            }

            return $same ? ['out' => $this->refuse('replay', ''), 'replay' => ['status' => 200, 'body' => ['replayed' => true] + $this->describe($prior)]]
                : ['out' => $this->refuse('already_corrected', '這張帳單已更正過，請重新整理學生帳務。', 409)];
        }
        $mine = $items->where('InvoiceID', $invoiceId)->values();
        $monthly = (string) $course->ScheduleMode === 'date';
        $refusal = $this->eligible($course, $bill, $mine, $invoices, $payments->where('InvoiceID', $invoiceId), $reports, $monthly);
        if ($refusal) {
            return ['out' => $refusal];
        }
        $model = Invoice::query()->whereKey($invoiceId)->firstOrFail();
        $model->setRelation('payments', Payment::query()->where('InvoiceID', $invoiceId)->get());
        $model->setRelation('items', \App\Models\InvoiceItem::query()->where('InvoiceID', $invoiceId)->get());
        $money = $this->amounts->resolve($model, $course);
        $moved = (int) $money['net_applied'];
        // A paid state the Payment rows do not back (legacy / stored-only) would move 0 and then clear the course paid flag.
        if ((int) $bill->PaidAmount !== $moved || ((int) $course->Paid === 1
                && !$payments->contains(fn ($p) => (int) $p->Amount > 0 && (string) $p->Method !== 'void' && !Payment::isCreditMovement($p)))) {
            return ['out' => $this->refuse('paid_state_unverified', '這張帳單的已收金額跟收款紀錄對不上，請回報總部處理，不要自行更正。')];
        }
        $extra = ['added_lessons' => [], 'period_end' => null];
        if ($monthly) {
            if ($money['repriceable']) {
                return ['out' => $this->refuse('repriceable', '這張月結帳單還沒收款，金額會自己照上課堂數重算，不需要更正。')];
            }
            $gap = $this->gap($course, $bill, $mine->first(), $invoices, $items);
            if (isset($gap['refusal'])) {
                return ['out' => $gap['refusal']];
            }
            $extra = $gap;
            $newTotal = (int) $bill->TotalAmount + $gap['amount'];
        } else {
            $newTotal = (int) ($input['new_total'] ?? 0);
            if ($newTotal < 1 || $newTotal > 999999) {
                return ['out' => $this->refuse('new_total_required', '請填新的帳單總額（1 到 999999）。')];
            }
            if ($newTotal === (int) $bill->TotalAmount && $newTotal === (int) $course->Charge) {
                return ['out' => $this->refuse('unchanged_total', '新的總額跟現在一樣，不需要更正。')];
            }
        }
        $outstanding = max(0, $newTotal - $moved);
        $after = ['outstanding_amount' => $outstanding, 'net_applied' => $moved];
        $hash = hash('sha256', json_encode([self::HASH_VERSION, $campusId, $newTotal, $course->getRawOriginal(), $invoices, $items, $payments, $reports, $extra], JSON_THROW_ON_ERROR));
        $body = ['shape' => $monthly ? 'monthly' : 'count', 'invoice_id' => $invoiceId, 'old_total' => (int) $bill->TotalAmount, 'new_total' => $newTotal,
            'moved_amount' => $moved, 'outstanding_after' => $outstanding, 'overpaid_after' => max(0, $moved - $newTotal),
            'status_after' => Invoice::statusFromProjection($after), 'added_lessons' => $extra['added_lessons'], 'range_shortfall' => $extra['range_shortfall'] ?? 0, 'period_end_after' => $extra['period_end'],
            'effects' => ['cash_total_change' => 0, 'receipt' => 0, 'refund' => 0, 'notification' => 0], 'state_hash' => $hash];

        return ['out' => ['status' => 200, 'body' => $body], 'ctx' => compact('course', 'bill', 'mine', 'model', 'moved', 'newTotal', 'extra', 'hash', 'monthly')];
    }

    /** @return array{status:int, body:array<string, mixed>}|null */
    private function eligible(StudentClass $course, object $bill, $mine, $invoices, $payments, $reports, bool $monthly): ?array
    {
        if ((int) $course->StudentID !== (int) $bill->StudentID) {
            return $this->refuse('state_changed', '課程與帳單的學生不一致，請重新開啟帳務。', 409);
        }
        if ((string) $bill->Status === 'void') {
            return $this->refuse('invoice_void', '這張帳單已作廢，不能更正。');
        }
        if ($reports->contains(fn ($r) => $r->status === 'pending')) {
            return $this->refuse('pending_report', '這門課還有待確認的繳費回報，請先確認或退回再更正。');
        }
        if ((int) $course->PackageID > 0 || strtolower(trim((string) $course->ClassType)) === 'tutoring'
            || in_array((string) $course->getAttribute('closed_reason'), StudentClass::TERMINAL_MONEY_CLOSURES, true)) {
            return $this->refuse('unsupported_course', '套餐、輔導、已退費或確認不收的課程，不能在這裡更正。');
        }
        if ($mine->count() > 1 || $mine->contains(fn ($i) => !empty($i->StudentClassID) && (int) $i->StudentClassID !== (int) $course->ID) || ($monthly && $mine->count() !== 1)) {
            return $this->refuse('unsupported_bill', '這張帳單包含多個明細或多門課，請先拆開再更正。');
        }
        if ((int) $bill->TotalAmount <= 0 || !empty($bill->zero_amount_reason)) {
            return $this->refuse('unsupported_bill', '金額為 0 的帳單不能在這裡更正。');
        }
        if ($payments->contains(fn ($p) => in_array((string) $p->Method, self::MOVED_METHODS, true) && self::idOf($p->Note) === null)
            || $payments->contains(fn ($p) => (string) $p->Method === 'refund')) {
            return $this->refuse('money_moved', '這張帳單的錢已經被退費或轉用，請先處理那一筆再更正。');
        }
        if (!$monthly && $invoices->filter(fn ($i) => $i->Status !== 'void')->count() !== 1) {
            return $this->refuse('ambiguous_invoice', '這門課有多張有效帳單，請先核對應收歸屬。');
        }

        return null;
    }

    /**
     * Monthly shape: the lessons held but covered by no live bill, priced per lesson on its own day. They must follow the bill's own range.
     *
     * @return array{refusal?:array<string, mixed>, amount?:int, added_lessons?:list<string>, period_end?:string}
     */
    private function gap(StudentClass $course, object $bill, object $item, $invoices, $items): array
    {
        if (!$item->PeriodStart || !$item->PeriodEnd) {
            return ['refusal' => $this->refuse('unsupported_bill', '這張帳單沒有服務期間，不能算出漏收的課。')];
        }
        // The lesson status the 月對帳表 學收 uses: the calendar's sign-in-aware status, then the bill engine's billable set.
        $held = DB::table('ClassSession as cs')
            ->leftJoin('StudentSingIn as si', fn ($j) => $j->on('si.ClassSessionID', '=', 'cs.id')->whereRaw(ClassSessionIndexReadService::LATEST_SIGN_IN_SQL))
            ->where('cs.StudentClassID', $course->ID)->orderBy('cs.id')->select(['cs.SessionDate'])
            ->selectRaw(...ClassSessionIndexReadService::effectiveStatusSelect())->get()
            ->filter(fn ($s) => in_array(strtolower(rtrim((string) $s->effective_status)), MonthlyBillingService::BILLABLE_STATUSES, true))
            ->map(fn ($s) => substr((string) $s->SessionDate, 0, 10));
        $covered = BillingPeriod::datesCoveredByBills($course, $held->unique()->values()->all());
        $end = substr((string) $item->PeriodEnd, 0, 10);
        // Only the rest of the item's own calendar month (and the contract): later lessons belong to the next month's bill.
        $cap = min(date('Y-m-t', strtotime($end)), substr((string) $course->EndDate, 0, 10));
        $open = $held->reject(fn ($d) => in_array($d, $covered, true) || $d <= $end || $d > $cap)->sort()->values();
        // A date inside the range counts as covered, but the frozen amount only carries the lessons held when it was issued:
        // what the range's lessons price to beyond the stored total is the shortfall (late-added lessons inside the range).
        $start = substr((string) $item->PeriodStart, 0, 10);
        $pricing = app(StudentClassPricingService::class);
        $inside = $held->filter(fn ($d) => $d >= $start && $d <= $end);
        $shortfall = 0;
        $amount = 0;
        foreach ([[$inside, 'inside'], [$open, 'open']] as [$dates, $kind]) {
            foreach ($dates as $date) {
                $p = $pricing->forDate($course, $date);
                // ponytail: per-session price only; an hourly price needs the lesson length (MonthlyBillingService::sessionAmount is private).
                if ($p['rate'] <= 0 || $p['rate_unit'] !== 'session') {
                    return ['refusal' => $this->refuse('missing_rate', '漏收的課沒有每堂單價（或是按小時計價），請找總部核對。')];
                }
                $kind === 'inside' ? $shortfall += $p['rate'] : $amount += $p['rate'];
            }
            if ($kind === 'inside') {
                $shortfall = max(0, $shortfall - (int) $bill->TotalAmount);
            }
        }
        if ($open->isEmpty() && $shortfall === 0) {
            return ['refusal' => $this->refuse('no_gap', '沒有找到這張帳單漏收的課。')];
        }
        $newEnd = $open->isEmpty() ? $end : (string) $open->last();
        if ($invoices->filter(fn ($i) => $i->Status !== 'void' && (int) $i->id !== (int) $bill->id)->contains(fn ($i) => $items->where('InvoiceID', $i->id)
            ->contains(fn ($it) => $it->PeriodStart && $it->PeriodEnd && substr((string) $it->PeriodStart, 0, 10) <= $newEnd && substr((string) $it->PeriodEnd, 0, 10) > $end))) {
            return ['refusal' => $this->refuse('gap_covered_elsewhere', '漏收的日期已有另一張有效帳單涵蓋，請先核對。', 409)];
        }

        return ['amount' => $amount + $shortfall, 'added_lessons' => $open->all(), 'range_shortfall' => $shortfall, 'period_end' => $newEnd];
    }

    /** One transaction's writes; the caller holds every lock and has matched the state_hash. */
    private function write(array $ctx, array $input, array $actor, int $campusId): array
    {
        $course = $ctx['course'];
        $old = $ctx['bill'];
        $item = $ctx['mine']->first();
        $newTotal = (int) $ctx['newTotal'];
        $moved = (int) $ctx['moved'];
        $reason = trim((string) $input['reason']);
        $today = now()->toDateString();
        $new = app(InvoiceIssuer::class)->issue(['StudentID' => $old->StudentID, 'StudentClassID' => $course->ID, 'IssueDate' => $today, 'DueDate' => $old->DueDate,
            'TotalAmount' => $newTotal, 'billing_period' => $old->billing_period, 'ScheduleModeAtIssue' => $old->ScheduleModeAtIssue, 'Note' => '帳單更正，原帳單 #'.$old->id],
            [['Description' => data_get($item, 'Description') ?? '帳單更正', 'Amount' => $newTotal, 'PeriodStart' => data_get($item, 'PeriodStart'),
                'PeriodEnd' => $ctx['extra']['period_end'] ?? data_get($item, 'PeriodEnd')]]);
        $note = (string) $old->Note;
        $reference = ' [帳單更正→#'.$new->id.']';
        DB::table('Invoice')->where('id', $old->id)->update(['Status' => 'void', 'PaidAmount' => 0] + (mb_strlen($note.$reference) <= 255 ? ['Note' => $note.$reference] : []));
        $key = self::idempotencyKey((int) $old->id, $ctx['hash']);
        $row = ['kind' => self::KIND, 'campus_id' => $campusId, 'student_class_id' => (int) $course->ID, 'original_invoice_id' => (int) $old->id,
            'replacement_invoice_id' => (int) $new->id, 'payment_report_id' => null, 'amount' => $moved, 'old_charge' => (int) $course->Charge, 'new_charge' => $newTotal,
            'original_invoice_total' => (int) $old->TotalAmount, 'reason' => $reason, 'idempotency_key' => $key, 'operator_user_id' => $actor['id'],
            'request_hash' => self::requestHash((int) $old->id, $input, (int) $actor['id']), 'state_hash' => $ctx['hash'], 'created_at' => now()];
        $row['id'] = DB::table('invoice_corrections')->insertGetId($row);
        $tag = self::tag((int) $row['id']);
        if ($moved > 0) {
            Payment::create(['InvoiceID' => $old->id, 'Amount' => -$moved, 'PaidAt' => $today, 'Method' => 'transfer_out', 'Note' => "{$tag} 更正帳單：轉到新帳單 #{$new->id}"]);
            Payment::create(['InvoiceID' => $new->id, 'Amount' => $moved, 'PaidAt' => $today, 'Method' => 'transfer_in', 'Note' => "{$tag} 更正帳單：由原帳單 #{$old->id} 轉入"]);
        }
        self::settleBill($new);
        if (!$ctx['monthly']) {
            DB::table('StudentClass')->where('ID', $course->ID)->update(['Charge' => $newTotal]); // query builder: no entitlement hooks, as AgreedFeeCorrection
        }
        $fresh = StudentClass::query()->whereKey($course->ID)->firstOrFail();
        (string) $new->fresh()->Status === 'paid' ? CollectionTarget::settle($fresh, null, 'paid', false, $today) : CollectionTarget::unsettle($fresh, null, true);
        SecurityAuditEvent::append(self::AUDIT_EVENT, 'success', ['campus_id' => $campusId, 'actor_type' => 'user', 'actor_id' => $actor['id'],
            'subject_type' => 'invoice_correction', 'subject_id' => (int) $row['id'], 'correlation_id' => $key],
            ['old_total' => (int) $old->TotalAmount, 'new_total' => $newTotal, 'moved' => $moved, 'reason_hash' => hash('sha256', $reason)], true); // required: an audit failure rolls it back

        return ['status' => 201, 'body' => ['replayed' => false] + $this->describe((object) $row)];
    }

    /**
     * PaymentReportController::void() for a receipt on a bill that a correction voided: the money now sits on the live bill at the
     * end of the chain (A to B to C). Take it back newest hop first with transfer_reversal pairs, then the normal reversal on the
     * original bill, which stays void. Null = done; otherwise the refusal (nothing written).
     *
     * @return array{status:int, body:array<string, mixed>}|null
     */
    public function reverseReceipt(Invoice $original, int $reportId, int $amount, string $reason): ?array
    {
        $hops = [];
        for ($cur = (int) $original->id; count($hops) < 20 && ($row = DB::table('invoice_corrections')->where('kind', self::KIND)->where('original_invoice_id', $cur)->first()); $cur = (int) $row->replacement_invoice_id) {
            $hops[] = [$cur, (int) $row->replacement_invoice_id, (int) $row->id];
        }
        $ids = array_values(array_unique(array_merge(...array_map(fn ($h) => [$h[0], $h[1]], $hops))));
        sort($ids);
        Invoice::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id']);
        $rows = Payment::query()->whereIn('InvoiceID', $ids)->orderBy('id')->lockForUpdate()->get();
        $live = end($hops)[1];
        if ($rows->contains(fn ($p) => (string) $p->Method === 'refund')) {
            return $this->refuse('void_refunded', '這筆錢已經退費，不能再撤銷收據；請先處理退費。', 409);
        }
        if ($rows->contains(fn ($p) => Payment::reversesReport($p, $reportId))) {
            return null;
        }
        // As the ordinary branch of void(): a reversal no receipt is linked to may belong to this one; moving 'all' back could take it twice.
        if ($rows->contains(fn ($p) => Payment::isUnlinkedLegacyReversal($p))) {
            return $this->refuse('legacy_reversal_unreconciled', '這張帳單有舊的沖銷紀錄還沒對帳，請先找系統管理員處理', 409);
        }
        $liveModel = Invoice::query()->whereKey($live)->firstOrFail();
        $liveModel->setRelation('payments', $rows->where('InvoiceID', $live)->values());
        if ((string) $liveModel->Status === 'void' || (int) $this->amounts->resolve($liveModel, null)['net_applied'] - $amount < 0) {
            return $this->refuse('void_transferred_credit', "這筆收款已轉到帳單 #{$live}，那張帳單的已收不夠倒轉，請先處理那張帳單。", 409);
        }
        $today = now()->toDateString();
        foreach (array_reverse($hops) as [$from, $to, $cid]) {
            $tag = self::tag($cid);
            Payment::create(['InvoiceID' => $to, 'Amount' => -$amount, 'PaidAt' => $today, 'Method' => 'transfer_reversal_out', 'Note' => "{$tag} 撤銷收據 #{$reportId}：轉回原帳單 #{$from}"]);
            Payment::create(['InvoiceID' => $from, 'Amount' => $amount, 'PaidAt' => $today, 'Method' => 'transfer_reversal_in', 'Note' => "{$tag} 撤銷收據 #{$reportId}：由帳單 #{$to} 轉回"]);
        }
        Payment::create(['InvoiceID' => $original->id, 'Amount' => -$amount, 'PaidAt' => $today, 'Method' => 'void', 'Note' => $reason, 'payment_report_id' => $reportId]);
        foreach ($ids as $id) {
            self::settleBill(Invoice::query()->whereKey($id)->firstOrFail());
        }

        return null;
    }

    /** PaidAmount and Status from the bill's Payment rows through the resolver; a void bill stays void. */
    private static function settleBill(Invoice $invoice): void
    {
        $invoice->setRelation('payments', $invoice->payments()->get());
        $invoice->loadMissing('items');
        $money = app(InvoiceAmountReconciliationService::class)->resolve($invoice, StudentClass::query()->where('ID', (int) $invoice->StudentClassID)->first());
        $invoice->update(['PaidAmount' => (int) $money['net_applied'], 'Status' => (string) $invoice->Status === 'void' ? 'void' : Invoice::statusFromProjection($money)]);
    }

    /** The stored answer (identical for the first reply and every replay). */
    private function describe(object $row): array
    {
        $new = Invoice::query()->whereKey((int) $row->replacement_invoice_id)->firstOrFail();
        $new->loadMissing(['payments', 'items']);
        $money = $this->amounts->resolve($new, StudentClass::query()->where('ID', (int) $new->StudentClassID)->first());

        return ['correction_id' => (int) $row->id, 'original_invoice_id' => (int) $row->original_invoice_id, 'replacement_invoice_id' => (int) $row->replacement_invoice_id,
            'old_total' => (int) $row->original_invoice_total, 'new_total' => (int) $row->new_charge, 'moved_amount' => (int) $row->amount,
            'status' => (string) $new->Status, 'outstanding_after' => (int) $money['outstanding_amount'], 'overpaid_after' => (int) $money['overpaid_amount']];
    }

    /** What the caller asked for (total as typed, trimmed reason, operator): a replay must carry the same. */
    private static function requestHash(int $invoiceId, array $input, int $actorId): string
    {
        return hash('sha256', json_encode([$invoiceId, isset($input['new_total']) ? (int) $input['new_total'] : null, trim((string) ($input['reason'] ?? '')), $actorId], JSON_THROW_ON_ERROR));
    }

    /** Scoped to the bill and the exact plan the screen saw: the same preview applied twice is one correction. */
    private static function idempotencyKey(int $invoiceId, string $stateHash): string
    {
        $h = hash('sha256', "{$invoiceId}|{$stateHash}");

        return substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20, 12);
    }

    /** @return array{status:int, body:array<string, mixed>} */
    private function refuse(string $code, string $message, int $status = 422): array
    {
        return ['status' => $status, 'body' => ['message' => $message, 'code' => $code]];
    }
}
