<?php

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\SecurityAuditEvent;
use App\Models\StudentClass;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Founder D3 / B1 (#4128, #4132): a confirmed correction is void + reissue, and the two bills must be traceable for
 * good. The link lives in invoice_corrections (UNIQUE original bill: the database refuses a second correction from any
 * request; the stored key + reason + amount let a retry of the same request get the same result). Both notes also name
 * the other bill, and an audit event records who did it. Nothing depends on APP_KEY or audit retention. The caller
 * holds the course lock and the replaced bill's row lock inside one transaction.
 */
final class InvoiceCorrectionLink
{
    public const EVENT = 'invoice.correction_reissue';

    /** @return array{refusal: ?JsonResponse, replay: ?object} replay = the invoice_corrections row of an identical earlier request */
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
        $prior = DB::table('invoice_corrections')->where('original_invoice_id', (int) $replaced->id)->lockForUpdate()->first();
        if ($prior) {
            if ((string) $prior->idempotency_key === strtolower(trim($key)) && (int) $prior->amount === $amount
                && (string) $prior->reason === self::cleanReason($reason)) {
                return ['refusal' => null, 'replay' => $prior]; // the same request again: its original result, never a second bill
            }

            return $refuse(409, 'replaces_invoice_already_replaced', '這張帳單已經更正過，請改從更正後的帳單處理。',
                ['replacement_invoice_id' => (int) $prior->replacement_invoice_id]);
        }

        return ['refusal' => null, 'replay' => null];
    }

    /**
     * Records the link row (UNIQUE original bill: a concurrent different request that got past the checks fails here
     * and gets 409), then both notes and an audit event. The old bill gets the full tag when it fits after trimming
     * older free text, else a short tag naming the new bill and the link row; if even that cannot fit beside its
     * existing tags (a very long void reason), its note is left as is and the link row carries the link. Existing
     * tags are never cut. @return int the invoice_corrections id
     */
    public function link(Invoice $replaced, Invoice $invoice, ?int $userId, string $reason, string $key): int
    {
        try {
            $id = (int) DB::table('invoice_corrections')->insertGetId(['original_invoice_id' => (int) $replaced->id,
                'replacement_invoice_id' => (int) $invoice->id, 'student_class_id' => (int) $invoice->StudentClassID,
                'amount' => (int) $invoice->TotalAmount, 'reason' => self::cleanReason($reason), 'idempotency_key' => strtolower(trim($key)),
                'operator_user_id' => $userId, 'created_at' => now()]);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
            throw new HttpResponseException(response()->json(['message' => '這張帳單已經更正過，請改從更正後的帳單處理。沒有登記任何款項。',
                'code' => 'replaces_invoice_already_replaced'], 409));
        }
        $operator = 'user_id=' . ($userId ?? 'system');
        $invoice->Note = self::appendNoteTag((string) $invoice->Note, '[更正自 #' . self::invoiceNo($replaced) . '／操作者：' . $operator . '／原因：' . self::cleanReason($reason) . ']')
            ?? throw new HttpResponseException(response()->json(['message' => '帳單備註已滿。沒有登記任何款項。', 'code' => 'replaces_invoice_note_full'], 422));
        $invoice->save();
        $oldNote = self::appendNoteTag((string) $replaced->Note, sprintf('[replaced_by: #%s; %s; at=%s]', self::invoiceNo($invoice), $operator, now()->format('Y-m-d H:i:s')))
            ?? self::appendNoteTag((string) $replaced->Note, sprintf('[更正為 #%d 更正紀錄 #%d]', (int) $invoice->id, $id));
        if ($oldNote !== null) {
            $replaced->Note = $oldNote;
            $replaced->save();
        }
        SecurityAuditEvent::append(self::EVENT, 'success', ['actor_type' => 'user', 'actor_id' => $userId, 'subject_type' => 'invoice',
            'subject_id' => $invoice->id, 'campus_id' => DB::table('Student')->where('id', $invoice->StudentID)->value('CampusID')],
            ['reason_code' => 'replacement', 'old_charge' => (int) $replaced->TotalAmount, 'new_charge' => (int) $invoice->TotalAmount]);

        return $id;
    }

    /** Staff-facing number, same format as the accounting ledger (INV-YYYYMM-000123). */
    private static function invoiceNo(Invoice $invoice): string
    {
        return 'INV-' . (preg_replace('/[^0-9]/', '', (string) ($invoice->billing_period ?: substr((string) $invoice->IssueDate, 0, 7))) ?: 'LEGACY')
            . '-' . str_pad((string) $invoice->id, 6, '0', STR_PAD_LEFT);
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
