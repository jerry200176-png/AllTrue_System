<?php

namespace App\Services\Billing;

use App\Models\{Invoice, SecurityAuditEvent, StudentClass};
use App\Services\InvoiceAmountReconciliationService;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Changes only the stored agreed fee and, when selected, the one live obligation.
 * Unpaid bill: void + reissue. Bill with confirmed money (Founder 2026-10-21): the same bill's total is corrected
 * in place and its paid status recomputed from Payment rows; money rows are never touched and a refund is refused.
 */
final class AgreedFeeCorrection
{
    public const KIND = 'agreed_fee';
    public const KIND_IN_PLACE = 'agreed_fee_in_place';

    public static function available(): bool
    {
        return config('billing.agreed_fee_correction_enabled') === true
            && Schema::hasColumns('invoice_corrections', ['kind', 'campus_id', 'old_charge', 'new_charge', 'original_invoice_total', 'request_hash', 'state_hash'])
            && Schema::hasTable('security_audit_events');
    }

    public function run(int $courseId, array $input, array $actor, bool $apply = false): array
    {
        if (!self::available()) $this->refuse('更正功能尚未啟用或資料表尚未準備完成。', 'unavailable', 503);
        // No consistent read before the locks: InnoDB REPEATABLE READ must observe the winning writer.
        return DB::transaction(function () use ($courseId, $input, $actor, $apply) {
            $studentId = $actor['student_id'];
            $student = DB::table('Student')->where('id', $studentId)->lockForUpdate()->first();
            $courseQuery = StudentClass::query()->whereKey($courseId);
            $courseQuery->lockForUpdate();
            $course = $courseQuery->firstOrFail();
            if (!$course instanceof StudentClass) abort(404);
            if ((int) $course->StudentID !== (int) $studentId) $this->refuse('課程學生已變更，請重新開啟帳務。', 'state_changed', 409);
            $campusId = (int) ($student->CampusID ?? 0);
            if (!$student || $campusId <= 0 || !in_array($actor['role'], ['director', 'super_admin'], true)
                || ($actor['role'] !== 'super_admin' && !in_array($campusId, $actor['campus_ids'], true))) abort(403);
            if (!in_array($courseId, array_map('intval', (array) config('billing.agreed_fee_correction_course_ids', [])), true)
                || !in_array($campusId, array_map('intval', (array) config('billing.agreed_fee_correction_campus_ids', [])), true)) {
                $this->refuse('此課程不在本次限定更正範圍。', 'course_out_of_scope', 404);
            }
            $kind = $input['scope'] === 'unbilled' ? 'agreed_fee_unbilled' : self::KIND;
            $request = ['kind' => $kind, 'scope' => $input['scope'], 'course_id' => $courseId,
                'original_invoice_id' => isset($input['original_invoice_id']) ? (int) $input['original_invoice_id'] : null, 'new_charge' => (int) $input['new_charge'],
                'reason' => trim($input['reason']), 'operator_user_id' => $actor['id'], 'idempotency_key' => strtolower($input['idempotency_key'])];
            $requestHash = $this->digest($request);
            $key = strtolower($input['idempotency_key']);
            $existing = DB::table('invoice_corrections')->where('student_class_id', $courseId)->where('idempotency_key', $key)->first();
            if ($existing) {
                // A billed request may have been stored as the in-place kind; the request hash still binds the payload.
                if ($kind === self::KIND && $existing->kind === self::KIND_IN_PLACE) $kind = self::KIND_IN_PLACE;
                if ($existing->kind !== $kind || !hash_equals((string) $existing->request_hash, $requestHash)
                    || (int) $existing->operator_user_id !== $actor['id']) $this->refuse('此操作識別碼已用於其他內容，請重新預覽。', 'idempotency_conflict', 409);
                return $this->result($existing);
            }
            if ($request['original_invoice_id'] && DB::table('invoice_corrections')->where('original_invoice_id', $request['original_invoice_id'])->exists()) {
                $this->refuse('這張帳單已更正，請重新整理學生帳務。', 'already_corrected', 409);
            }
            $invoices = DB::table('Invoice')->where('StudentClassID', $courseId)->orderBy('id')->lockForUpdate()->get();
            $ids = $invoices->pluck('id')->all();
            $items = DB::table('InvoiceItem')->where(fn ($q) => $q->whereIn('InvoiceID', $ids)->orWhere('StudentClassID', $courseId))->orderBy('id')->lockForUpdate()->get();
            $payments = DB::table('Payment')->whereIn('InvoiceID', $ids)->orderBy('id')->lockForUpdate()->get();
            // All money writers hold the course through their writes (confirm/void are course-first).
            // Read reports under that lock; a pre-existing pending/confirmed report blocks here.
            $reports = DB::table('payment_reports')->where(fn ($q) => $q->where('StudentClassID', $courseId)->orWhereIn('InvoiceID', $ids))->orderBy('id')->get();
            $amendments = DB::table('student_class_pricing_amendments')->where('student_class_id', $courseId)->orderBy('id')->get();
            $sessions = DB::table('ClassSession')->where('StudentClassID', $courseId)->orderBy('ID')->get();
            $deductions = DB::table('session_deduction_ledger')->where('student_class_id', $courseId)->orderBy('id')->get();
            $transfers = DB::table('session_entitlement_transfers')->where(fn ($q) => $q->where('source_student_class_id', $courseId)->orWhere('target_student_class_id', $courseId))->orderBy('id')->get();
            $stateHash = $this->digest([$campusId, $course->getRawOriginal(), $invoices, $items, $payments, $reports, $amendments, $sessions, $deductions, $transfers]);
            if ($apply) $this->verify((string) ($input['preview_token'] ?? ''), $requestHash, $stateHash);
            $paid = $request['scope'] === 'billed' ? $this->netPaid($course, $invoices, $payments, (int) $request['original_invoice_id']) : 0;
            $inPlace = $paid > 0;
            $this->eligible($course, $request, $invoices, $items, $reports, $amendments, $paid);
            $original = $request['scope'] === 'billed' ? $invoices->firstWhere('id', $request['original_invoice_id']) : null;
            $plan = ['scope' => $request['scope'], 'original_invoice_id' => $original?->id,
                'original_outstanding' => $original ? max(0, (int) $original->TotalAmount - $paid) : null, 'old_charge' => (int) $course->Charge,
                'original_invoice_total' => $original ? (int) $original->TotalAmount : null, 'new_charge' => $request['new_charge'],
                'charge_delta' => $request['new_charge'] - (int) $course->Charge,
                'obligation_delta' => $original ? $request['new_charge'] - (int) $original->TotalAmount : null,
                'new_outstanding' => $original ? $request['new_charge'] - $paid : null,
                'mode' => $original ? ($inPlace ? 'in_place' : 'reissue') : 'unbilled', 'paid_amount' => $original ? $paid : null,
                'unchanged' => $course->only(['Rate', 'SessionCount', 'RemainingSessions', 'UsedSessions', 'StartDate', 'EndDate']),
                'effects' => ['payment' => 0, 'payment_report' => 0, 'receipt' => 0, 'refund' => 0, 'notification' => 0],
                'message' => $inPlace ? '已收款項不變，原帳單金額改為新約定費用；收款等於新費用時帳單即為已繳清。'
                    : ($original ? '只更正約定費用，原帳單作廢並建立新未繳帳單，不收款。' : '只更正約定費用，尚未開帳單，不收款。')];
            if (!$apply) return $plan + ['preview_token' => $this->sign($requestHash, $stateHash)];
            $replacement = null;
            if ($original && $inPlace) {
                // Same recompute as payment confirmation: PaidAmount/Status from Payment rows against the corrected total.
                $invoice = Invoice::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
                $invoice->setRelation('payments', $invoice->payments()->lockForUpdate()->get());
                $invoice->TotalAmount = $request['new_charge'];
                $after = app(InvoiceAmountReconciliationService::class)->resolve($invoice, $course);
                $net = (int) $after['net_applied'];
                if ($net !== $paid) $this->refuse('預覽已過期或帳務／課程已變更，請重新預覽。', 'state_changed', 409);
                $attrs = ['PaidAmount' => $net, 'Status' => (int) $after['outstanding_amount'] <= 0 ? 'paid' : ($net > 0 ? 'partial' : 'unpaid')];
                $reference = ' [約定費用更正：原帳單金額 '.(int) $original->TotalAmount.']';
                if (mb_strlen((string) $original->Note.$reference) <= 255) $attrs['Note'] = (string) $original->Note.$reference;
                $invoice->fill($attrs)->save();
            } elseif ($original) {
                $replacement = app(InvoiceIssuer::class)->issue(['StudentID' => $studentId, 'StudentClassID' => $courseId,
                    'IssueDate' => now()->toDateString(), 'DueDate' => $original->DueDate, 'TotalAmount' => $request['new_charge'],
                    'ScheduleModeAtIssue' => 'count', 'Note' => '約定費用更正，原帳單 #'.$original->id],
                    [['Description' => '約定費用更正', 'Amount' => $request['new_charge']]]);
                $note = (string) $original->Note;
                $reference = ' [約定費用更正→#'.$replacement->id.']';
                $attrs = ['Status' => 'void'];
                if (mb_strlen($note.$reference) <= 255) $attrs['Note'] = $note.$reference;
                DB::table('Invoice')->where('id', $original->id)->update($attrs);
            }
            // Query-builder update intentionally avoids any entitlement/model update hooks.
            DB::table('StudentClass')->where('ID', $courseId)->update(['Charge' => $request['new_charge']]);
            $row = ['kind' => $inPlace ? self::KIND_IN_PLACE : $kind, 'campus_id' => $campusId, 'student_class_id' => $courseId,
                'original_invoice_id' => $original?->id, 'replacement_invoice_id' => $replacement?->id, 'payment_report_id' => null,
                'amount' => $replacement || $inPlace ? $request['new_charge'] : null, 'old_charge' => $plan['old_charge'], 'new_charge' => $request['new_charge'],
                'original_invoice_total' => $plan['original_invoice_total'], 'reason' => $request['reason'], 'idempotency_key' => $key,
                'operator_user_id' => $actor['id'], 'request_hash' => $requestHash, 'state_hash' => $stateHash, 'created_at' => now()];
            $row['id'] = DB::table('invoice_corrections')->insertGetId($row);
            SecurityAuditEvent::append('billing.agreed_fee_corrected', 'success', ['campus_id' => $campusId,
                'actor_type' => 'user', 'actor_id' => $actor['id'], 'subject_type' => 'student_class', 'subject_id' => $courseId, 'correlation_id' => $key],
                ['old_charge' => $plan['old_charge'], 'new_charge' => $request['new_charge'], 'reason_hash' => hash('sha256', $request['reason']),
                    'reason_code' => $row['kind']] + ($original ? ['paid_before' => $paid, 'outstanding_after' => $plan['new_outstanding']] : []), true);
            return $this->result((object) $row);
        }, 3);
    }

    /** Confirmed net money on the named live bill, by the canonical Payment-row projection (positive receipts minus every reversal). */
    private function netPaid(StudentClass $course, $invoices, $payments, int $invoiceId): int
    {
        $row = $invoices->first(fn ($i) => (int) $i->id === $invoiceId && $i->Status !== 'void');
        if (!$row) return 0;
        $invoice = (new Invoice())->newFromBuilder((array) $row);
        $invoice->setRelation('payments', $payments->filter(fn ($p) => (int) $p->InvoiceID === $invoiceId)->values());
        return (int) app(InvoiceAmountReconciliationService::class)->resolve($invoice, $course)['net_applied'];
    }

    private function eligible(StudentClass $course, array $request, $invoices, $items, $reports, $amendments, int $paid = 0): void
    {
        if ($course->ScheduleMode !== 'count' || (int) $course->PackageID > 0 || strtolower(trim((string) $course->ClassType)) === 'tutoring'
            || (int) $course->Charge <= 0 || strtolower(trim((string) $course->getAttribute('closed_reason'))) === 'waived') {
            $this->refuse('目前只支援有正數約定費用的單科堂數課（含試聽）；方案、月結、輔導、免費與確認不收請另行核對。', 'unsupported_course');
        }
        if ($request['scope'] === 'unbilled' && $request['new_charge'] === (int) $course->Charge) $this->refuse('約定費用沒有變更。', 'unchanged_charge');
        if ((int) $course->Paid !== 0 || (int) $course->Pay !== 0 || !empty($course->PayDate)) $this->refuse('課程留有已收標記，請先核對付款證據；費用更正不會清除舊收款標記。', 'payment_evidence_blocks');
        if ($amendments->contains(fn ($a) => $a->voided_at === null)) $this->refuse('已有有效調價紀錄，請先核對調價約定。', 'active_pricing_amendment');
        if ($request['scope'] === 'unbilled') {
            if ($invoices->isNotEmpty() || $items->isNotEmpty() || $reports->isNotEmpty()) $this->refuse('曾有帳單或回報，不能當作尚未開單；請核對原帳單，已作廢歷史需另案處理。', 'never_billed_required');
            return;
        }
        $live = $invoices->filter(fn ($i) => $i->Status !== 'void');
        if ($live->isEmpty()) $this->refuse('沒有有效原帳單；尚未開單請使用未開單費用更正，曾作廢帳單請另案核對。', 'live_invoice_required');
        if ($live->count() !== 1) $this->refuse('有多張有效帳單，請先核對應收歸屬。', 'ambiguous_invoice');
        $invoice = $live->first();
        if ((int) $invoice->id !== (int) $request['original_invoice_id'] || (int) $invoice->StudentID !== (int) $course->StudentID
            || $items->contains(fn ($i) => !in_array((int) $i->InvoiceID, $invoices->pluck('id')->map(fn ($id) => (int) $id)->all(), true)
                || (!empty($i->StudentClassID) && (int) $i->StudentClassID !== (int) $course->ID))) $this->refuse('帳單與課程歸屬不明，請先核對。', 'invoice_mismatch');
        if ($request['new_charge'] === (int) $course->Charge && $request['new_charge'] === (int) $invoice->TotalAmount) $this->refuse('約定費用與帳單金額都沒有變更。', 'unchanged_charge');
        if ($paid > 0) {
            // Money already received stays exactly as recorded; only a bill that does not exceed the new fee may be corrected.
            if ($paid > $request['new_charge']) $this->refuse('已收款項超過新約定費用，需退款處理，不能只更正費用；請先依退款流程核對。', 'refund_required');
            // Stored PaidAmount must agree with the Payment rows; a confirmed report must belong to this bill; nothing may be pending;
            // every reversal must still match exactly one voided report and receipt (only this bill's non-zero net is allowed).
            if (!in_array($invoice->Status, ['partial', 'paid'], true) || (int) $invoice->TotalAmount <= 0 || !empty($invoice->zero_amount_reason)
                || (int) $invoice->PaidAmount !== $paid
                || $reports->contains(fn ($r) => $r->status === 'pending' || ($r->status === 'confirmed' && (int) $r->InvoiceID !== (int) $invoice->id))
                || ContractMoneyState::blocksUnpaidCorrection((int) $course->ID, true, (int) $invoice->id)) {
                $this->refuse('仍有待核對回報、無法匹配的沖銷紀錄或收款金額不一致；請先完成核對／撤銷再重新預覽。', 'payment_evidence_blocks');
            }
            return;
        }
        if ($invoice->Status !== 'unpaid' || (int) $invoice->TotalAmount <= 0 || !empty($invoice->zero_amount_reason)
            || $reports->contains(fn ($r) => in_array($r->status, ['pending', 'confirmed'], true))
            || ContractMoneyState::blocksUnpaidCorrection((int) $course->ID, true)) $this->refuse('仍有收款、待核對回報或無法匹配的沖銷紀錄；請先完成核對／撤銷再重新預覽。', 'payment_evidence_blocks');
    }

    private function result(object $row): array
    {
        return ['correction_id' => (int) $row->id, 'scope' => $row->original_invoice_id === null ? 'unbilled' : 'billed',
            'original_invoice_id' => $row->original_invoice_id === null ? null : (int) $row->original_invoice_id,
            'replacement_invoice_id' => $row->replacement_invoice_id === null ? null : (int) $row->replacement_invoice_id,
            'old_charge' => (int) $row->old_charge, 'original_invoice_total' => $row->original_invoice_total === null ? null : (int) $row->original_invoice_total,
            'new_charge' => (int) $row->new_charge,
            'mode' => $row->kind === self::KIND_IN_PLACE ? 'in_place' : ($row->original_invoice_id === null ? 'unbilled' : 'reissue')];
    }

    private function digest(mixed $value): string { return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)); }
    private function signingKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') $this->refuse('預覽簽章尚未準備完成，請聯絡管理員。', 'signing_unavailable', 503);
        return $key;
    }
    private function sign(string $requestHash, string $stateHash): string
    {
        $payload = base64_encode(json_encode([$requestHash, $stateHash, now()->addMinutes(10)->timestamp], JSON_THROW_ON_ERROR));
        return $payload.'.'.hash_hmac('sha256', 'agreed-fee-v1|'.$payload, $this->signingKey());
    }
    private function verify(string $token, string $requestHash, string $stateHash): void
    {
        $parts = explode('.', $token);
        $body = count($parts) === 2 ? json_decode(base64_decode($parts[0], true) ?: '', true) : null;
        if (!is_array($body) || count($body) !== 3 || !hash_equals(hash_hmac('sha256', 'agreed-fee-v1|'.$parts[0], $this->signingKey()), $parts[1])
            || $body[0] !== $requestHash || $body[1] !== $stateHash || !is_int($body[2]) || $body[2] < now()->timestamp) {
            $this->refuse('預覽已過期或帳務／課程已變更，請重新預覽。', 'state_changed', 409);
        }
    }
    private function refuse(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(response()->json(compact('message', 'code'), $status));
    }
}
