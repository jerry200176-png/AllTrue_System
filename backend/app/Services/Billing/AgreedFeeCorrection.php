<?php

namespace App\Services\Billing;

use App\Models\{Invoice, SecurityAuditEvent, StudentClass};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Http\Exceptions\HttpResponseException;

/** Changes only the stored agreed fee and, when selected, replaces one unpaid obligation. */
final class AgreedFeeCorrection
{
    public const KIND = 'agreed_fee';

    public static function available(): bool
    {
        return config('billing.agreed_fee_correction_enabled') === true
            && Schema::hasColumns('invoice_corrections', ['kind', 'campus_id', 'old_charge', 'new_charge', 'original_invoice_total', 'request_hash', 'state_hash'])
            && Schema::hasTable('security_audit_events');
    }

    /** Role/campus gate shared by the controller and the read-only availability hint. */
    public static function actorMayCorrect(string $role, array $campusIds, int $campusId): bool
    {
        return in_array($role, ['director', 'super_admin'], true) && $campusId > 0
            && ($role === 'super_admin' || in_array($campusId, array_map('intval', $campusIds), true));
    }

    /** Bounded course/campus allowlist; empty config fails closed. */
    public static function inAllowlist(int $courseId, int $campusId): bool
    {
        return in_array($courseId, array_map('intval', (array) config('billing.agreed_fee_correction_course_ids', [])), true)
            && in_array($campusId, array_map('intval', (array) config('billing.agreed_fee_correction_campus_ids', [])), true);
    }

    /** Read-only UI hint: true only when the controller's flag, role/campus and allowlist gates would all pass. */
    public static function isAvailableFor(StudentClass $course, string $role, array $campusIds): bool
    {
        if (config('billing.agreed_fee_correction_enabled') !== true) return false;
        $campusId = (int) $course->student?->CampusID;
        return self::actorMayCorrect($role, $campusIds, $campusId) && self::inAllowlist((int) $course->ID, $campusId);
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
            $this->eligible($course, $request, $invoices, $items, $reports, $amendments);
            $original = $request['scope'] === 'billed' ? $invoices->firstWhere('id', $request['original_invoice_id']) : null;
            $plan = ['scope' => $request['scope'], 'original_invoice_id' => $original?->id,
                'original_outstanding' => $original ? (int) $original->TotalAmount : null, 'old_charge' => (int) $course->Charge,
                'original_invoice_total' => $original ? (int) $original->TotalAmount : null, 'new_charge' => $request['new_charge'],
                'charge_delta' => $request['new_charge'] - (int) $course->Charge,
                'obligation_delta' => $original ? $request['new_charge'] - (int) $original->TotalAmount : null,
                'new_outstanding' => $original ? $request['new_charge'] : null,
                'unchanged' => $course->only(['Rate', 'SessionCount', 'RemainingSessions', 'UsedSessions', 'StartDate', 'EndDate']),
                'effects' => ['payment' => 0, 'payment_report' => 0, 'receipt' => 0, 'refund' => 0, 'notification' => 0],
                'message' => $original ? '只更正約定費用，原帳單作廢並建立新未繳帳單，不收款。' : '只更正約定費用，尚未開帳單，不收款。'];
            if (!$apply) return $plan + ['preview_token' => $this->sign($requestHash, $stateHash)];
            $replacement = null;
            if ($original) {
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
            $row = ['kind' => $kind, 'campus_id' => $campusId, 'student_class_id' => $courseId,
                'original_invoice_id' => $original?->id, 'replacement_invoice_id' => $replacement?->id, 'payment_report_id' => null,
                'amount' => $replacement ? $request['new_charge'] : null, 'old_charge' => $plan['old_charge'], 'new_charge' => $request['new_charge'],
                'original_invoice_total' => $plan['original_invoice_total'], 'reason' => $request['reason'], 'idempotency_key' => $key,
                'operator_user_id' => $actor['id'], 'request_hash' => $requestHash, 'state_hash' => $stateHash, 'created_at' => now()];
            $row['id'] = DB::table('invoice_corrections')->insertGetId($row);
            SecurityAuditEvent::append('billing.agreed_fee_corrected', 'success', ['campus_id' => $campusId,
                'actor_type' => 'user', 'actor_id' => $actor['id'], 'subject_type' => 'student_class', 'subject_id' => $courseId, 'correlation_id' => $key],
                ['old_charge' => $plan['old_charge'], 'new_charge' => $request['new_charge'], 'reason_hash' => hash('sha256', $request['reason'])], true);
            return $this->result((object) $row);
        }, 3);
    }

    private function eligible(StudentClass $course, array $request, $invoices, $items, $reports, $amendments): void
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
            'new_charge' => (int) $row->new_charge];
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
