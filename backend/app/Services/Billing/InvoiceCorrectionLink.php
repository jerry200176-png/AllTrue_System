<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Founder D3 / B1 (#4128, #4132): a confirmed correction is void + reissue, and the two bills must be traceable.
 * There is no link column yet, so both notes name the other bill (number, operator, reason) and two audit rows
 * share one correlation id. Whether a bill was already replaced is read from those audit rows, never from the
 * free-text Note. The caller holds the course lock and the replaced bill's row lock inside one transaction.
 */
final class InvoiceCorrectionLink
{
    public const EVENT = 'invoice.correction_reissue';

    /** @return array{refusal: ?JsonResponse, replay: ?Invoice} replay = the bill an identical earlier request issued */
    public function precheck(?Invoice $replaced, StudentClass $course, bool $campusAllowed, string $reason, int $amount, string $key): array
    {
        $refuse = fn (int $status, string $code, string $message, array $extra = []) => ['refusal' => response()->json(
            ['message' => $message . '沒有登記任何款項。', 'code' => $code] + $extra, $status), 'replay' => null];
        if (!$replaced || !$campusAllowed) {
            return $refuse(404, 'replaces_invoice_not_found', '找不到要更正的帳單。');
        }
        if ((int) $replaced->StudentClassID !== (int) $course->getKey() || (int) $replaced->StudentID !== (int) $course->getAttribute('StudentID')) {
            return $refuse(422, 'replaces_invoice_mismatch', '要更正的帳單不是這位學生的這門課程。');
        }
        if (strtolower((string) ($replaced->Status ?? '')) !== 'void') {
            return $refuse(422, 'replaces_invoice_not_void', '請先作廢原帳單（需填原因），再登記更正。');
        }
        if (mb_strlen(self::cleanReason($reason)) < 2) {
            return $refuse(422, 'correction_reason_invalid', '請填寫更正原因。');
        }
        if ($prior = $this->priorCorrection($replaced)) {
            $existing = $prior['invoice'];
            if ($existing && strtolower((string) $existing->Status) !== 'void' && $prior['amount'] === $amount
                && hash_equals((string) $prior['request_hash'], self::requestHash($reason, $key))) {
                return ['refusal' => null, 'replay' => $existing]; // the same submit again: never a second bill
            }

            return $refuse(409, 'replaces_invoice_already_replaced', '這張帳單已經更正過，請改從更正後的帳單處理。',
                ['replacement_invoice_id' => $existing ? (int) $existing->id : null]);
        }

        return ['refusal' => null, 'replay' => null];
    }

    /**
     * Writes both notes and the audit rows. The old bill gets the full tag when it fits after trimming older free
     * text, else a short tag with the new bill id and the audit correlation id; when even that cannot fit beside
     * its existing tags (a very long void reason), its note is left as is and the audit rows carry the link, so a
     * long void reason never blocks a correction. Existing tags are never cut.
     *
     * Retention: these audit rows are the replacement state, so they are kept with retention_until = NULL. Any
     * purge of security_audit_events must skip NULL rows and this event type.
     *
     * @return bool whether the old bill's note got a tag
     */
    public function link(Invoice $replaced, Invoice $invoice, ?int $userId, string $reason, string $key): bool
    {
        $operator = 'user_id=' . ($userId ?? 'system');
        $correlationId = (string) Str::uuid();
        $newNote = self::appendNoteTag((string) $invoice->Note, '[更正自 #' . self::invoiceNo($replaced) . '／操作者：' . $operator . '／原因：' . self::cleanReason($reason) . ']');
        if ($newNote === null) {
            throw new HttpResponseException(response()->json([
                'message' => '帳單備註的紀錄已滿，無法再記錄更正單號。沒有登記任何款項。', 'code' => 'replaces_invoice_note_full'], 422));
        }
        $oldNote = self::appendNoteTag((string) $replaced->Note, sprintf('[replaced_by: #%s; %s; at=%s]', self::invoiceNo($invoice), $operator, now()->format('Y-m-d H:i:s')))
            ?? self::appendNoteTag((string) $replaced->Note, sprintf('[更正為 #%d audit=%s]', (int) $invoice->id, $correlationId));
        $invoice->Note = $newNote;
        $invoice->save();
        if ($oldNote !== null) {
            $replaced->Note = $oldNote;
            $replaced->save();
        }

        $campusId = (int) (DB::table('Student')->where('id', $invoice->StudentID)->value('CampusID') ?? 0) ?: null;
        foreach (['replaced' => $replaced, 'replacement' => $invoice] as $role => $subject) {
            SecurityAuditEvent::append(self::EVENT, 'success', [
                'correlation_id' => $correlationId, 'actor_type' => 'user', 'actor_id' => $userId,
                'subject_type' => 'invoice', 'subject_id' => $subject->id, 'campus_id' => $campusId,
            ], ['reason_code' => $role, 'old_charge' => (int) $replaced->TotalAmount, 'new_charge' => (int) $invoice->TotalAmount,
                'reason_hash' => self::requestHash($reason, $key)]);
        }
        // The audit rows are the replacement state, so a failed write must not leave an unlinked bill; keep them forever.
        DB::table('security_audit_events')->where('correlation_id', $correlationId)->update(['retention_until' => null]);
        if (DB::table('security_audit_events')->where('correlation_id', $correlationId)->count() !== 2) {
            throw new RuntimeException('invoice correction audit was not recorded');
        }

        return $oldNote !== null;
    }

    /** @return array{invoice: ?Invoice, amount: int, request_hash: ?string}|null */
    private function priorCorrection(Invoice $replaced): ?array
    {
        // Concurrent submits are serialized by the course and replaced-bill row locks the caller holds, so a plain
        // read here already sees a committed earlier correction.
        $oldRef = SecurityAuditEvent::ref('invoice', (int) $replaced->id);
        $row = DB::table('security_audit_events')->where('event_type', self::EVENT)->where('subject_ref', $oldRef)
            ->get(['correlation_id', 'metadata'])
            ->first(fn ($r) => (json_decode((string) $r->metadata, true)['reason_code'] ?? null) === 'replaced');
        if (!$row) {
            return null;
        }
        $meta = json_decode((string) $row->metadata, true);
        $newRef = DB::table('security_audit_events')->where('event_type', self::EVENT)
            ->where('correlation_id', $row->correlation_id)->where('subject_ref', '!=', $oldRef)->value('subject_ref');
        $invoice = Invoice::query()->where('StudentClassID', $replaced->StudentClassID)->where('id', '>', $replaced->id)->get()
            ->first(fn (Invoice $i) => $newRef !== null && hash_equals((string) $newRef, (string) SecurityAuditEvent::ref('invoice', (int) $i->id)));

        return ['invoice' => $invoice, 'amount' => (int) ($meta['new_charge'] ?? -1), 'request_hash' => $meta['reason_hash'] ?? null];
    }

    /** Staff-facing number, same format as the accounting ledger (INV-YYYYMM-000123). */
    private static function invoiceNo(Invoice $invoice): string
    {
        return 'INV-' . (preg_replace('/[^0-9]/', '', (string) ($invoice->billing_period ?: substr((string) $invoice->IssueDate, 0, 7))) ?: 'LEGACY')
            . '-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
    }

    /** The cleaned reason plus the client's idempotency key: a double submit matches, a new attempt does not. */
    private static function requestHash(string $reason, string $key): string
    {
        return hash('sha256', self::cleanReason($reason) . "\n" . strtolower(trim($key)));
    }

    /** Free text going into a tag: no brackets, separators or markers, so it cannot fake a tag or the operator. */
    public static function cleanReason(string $reason): string
    {
        $clean = str_replace(["\r", "\n", '[', ']', '／', '/', ';', '；', '：', ':', '操作者', '更正自', 'replaced_by'], ' ', $reason);

        return trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
    }

    /**
     * Invoice.Note is VARCHAR(255). Existing tags and the new tag are kept whole; only older free text is trimmed.
     * Null when the tags alone do not fit, or when the free text holds a bracket that is not a whole tag (never cut).
     */
    private static function appendNoteTag(string $note, string $tag): ?string
    {
        [$free, $tags] = self::parseNote($note);
        $tail = implode(' ', [...$tags, $tag]);
        if (mb_strlen($tail) > 255) {
            return null;
        }
        $room = 255 - mb_strlen($tail) - 1;
        if (mb_strlen($free) > $room) {
            if (str_contains($free, '[') || str_contains($free, ']')) {
                return null;
            }
            $free = $room > 0 ? trim(mb_substr($free, 0, $room)) : '';
        }

        return $free === '' ? $tail : $free . ' ' . $tail;
    }

    /**
     * Free text and whole tags. A void / exception-void tag is matched up to its fixed "user_id=…; at=…" ending, so an
     * older reason with brackets ("[void: 開錯 [註] …]") stays one tag.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function parseNote(string $note): array
    {
        $pattern = '/\[(?:void|exception-void): .*?; user_id=[^;\]]*; at=\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:; positive_payments=[^\]]*?; net=-?\d+)?\]|\[[^\[\]]*\]/su';
        preg_match_all($pattern, $note, $m);
        $free = trim(preg_replace('/\s+/u', ' ', preg_replace($pattern, ' ', $note) ?? '') ?? '');

        return [$free, $m[0]];
    }
}
