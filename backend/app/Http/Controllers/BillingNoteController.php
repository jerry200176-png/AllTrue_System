<?php

namespace App\Http\Controllers;

use App\Models\BillingNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReport;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * #3836 — append-only billing-center notes. GET lists and POST adds; no edit or delete route exists.
 * Directors and super admins only (route group); every request is scoped to the campus of the
 * subject's student. The legacy single note of the row is returned first, read-only.
 */
class BillingNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(BillingNote::SUBJECT_TYPES)],
            'subject_id' => 'required|integer|min:1',
        ]);
        $subject = $this->resolveSubject((string) $data['subject_type'], (int) $data['subject_id']);
        // #4041: deleting a course voids its invoice; that invoice's notes stay readable (never appendable).
        $readOnly = $subject === null && $data['subject_type'] === 'invoice'
            && ($subject = $this->resolveDeletedCourseInvoice((int) $data['subject_id'])) !== null;
        if ($subject === null) {
            return response()->json(['message' => '找不到這筆帳務資料'], 404);
        }
        if ($denied = $this->denyOtherCampus($request, $subject['campus_id'])) {
            return $denied;
        }

        $notes = BillingNote::query()
            ->where('subject_type', $data['subject_type'])
            ->where('subject_id', (int) $data['subject_id'])
            ->when($readOnly, fn ($q) => $q->where('campus_id', $subject['campus_id']))
            ->orderBy('created_at')->orderBy('id')
            ->get();

        $entries = [];
        if ($subject['legacy_note'] !== '') {
            $entries[] = ['id' => null, 'body' => $subject['legacy_note'], 'author_name' => null, 'created_at' => null, 'legacy' => true];
        }
        foreach ($notes as $note) {
            $entries[] = $this->entry($note);
        }

        return response()->json(['notes' => $entries] + ($readOnly ? ['read_only' => true, 'read_only_label' => BillingNote::VOIDED_INVOICE_LABEL] : []));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(BillingNote::SUBJECT_TYPES)],
            'subject_id' => 'required|integer|min:1',
            'body' => 'required|string|max:' . BillingNote::MAX_BODY,
        ]);
        $body = trim((string) $data['body']);
        if ($body === '') {
            return response()->json(['message' => '備註不能空白'], 422);
        }
        $subject = $this->resolveSubject((string) $data['subject_type'], (int) $data['subject_id']);
        if ($subject === null) {
            return response()->json(['message' => '找不到這筆帳務資料'], 404);
        }
        if ($denied = $this->denyOtherCampus($request, $subject['campus_id'])) {
            return $denied;
        }

        $author = $request->attributes->get('auth_user');
        $note = new BillingNote();
        $note->fill([
            'campus_id' => $subject['campus_id'],
            'subject_type' => $data['subject_type'],
            'subject_id' => (int) $data['subject_id'],
            'body' => $body,
            'author_user_id' => (int) ($author->id ?? 0),
            'author_name' => mb_substr((string) ($author->Name ?? ''), 0, 100),
        ]);
        $note->save();

        return response()->json($this->entry($note), 201);
    }

    /** @return array<string, mixed> */
    private function entry(BillingNote $note): array
    {
        return [
            'id' => (int) $note->id,
            'body' => $note->body,
            'author_name' => (string) $note->author_name,
            'created_at' => $note->created_at?->toIso8601String(),
            'legacy' => false,
        ];
    }

    /** @return array{campus_id:int, legacy_note:string}|null */
    private function resolveSubject(string $type, int $id): ?array
    {
        [$studentId, $legacy] = match ($type) {
            'invoice' => ($m = Invoice::query()->whereKey($id)->first()) instanceof Invoice ? [$this->invoiceStudent($m), (string) ($m->Note ?? '')] : [0, ''],
            'payment' => ($m = Payment::query()->find($id))
                ? [$this->invoiceStudent(Invoice::query()->whereKey((int) $m->getAttribute('InvoiceID'))->first()), (string) ($m->getAttribute('Note') ?? '')]
                : [0, ''],
            'payment_report' => ($m = PaymentReport::query()->with('payment')->whereKey($id)->first()) instanceof PaymentReport ? [(int) $m->getAttribute('StudentID'), $this->reportNote($m)] : [0, ''],
            default => [0, ''],
        };
        $campusId = $studentId > 0 ? (int) Student::query()->whereKey($studentId)->value('CampusID') : 0;

        return $campusId > 0 ? ['campus_id' => $campusId, 'legacy_note' => trim($legacy)] : null;
    }

    /** @return array{campus_id:int, legacy_note:string}|null */
    private function resolveDeletedCourseInvoice(int $id): ?array
    {
        $invoice = BillingNote::deletedCourseVoidInvoices()->whereKey($id)->first();
        $campusId = $invoice ? (int) Student::query()->whereKey((int) $invoice->StudentID)->value('CampusID') : 0;

        return $invoice && $campusId > 0 ? ['campus_id' => $campusId, 'legacy_note' => trim((string) ($invoice->Note ?? ''))] : null;
    }

    /** #3978 review: a linked payment outside the report's student never lends its note (another campus's text). */
    private function reportNote(PaymentReport $report): string
    {
        $payment = $report->getRelationValue('payment');
        if (!$payment instanceof Payment) {
            return $report->displayNote();
        }
        $owner = $this->invoiceStudent(Invoice::query()->whereKey((int) $payment->getAttribute('InvoiceID'))->first());

        return $owner !== (int) $report->getAttribute('StudentID') ? (string) ($report->getAttribute('note') ?? '') : $report->displayNote();
    }

    private function invoiceStudent(?Invoice $invoice): int
    {
        if (!$invoice || (int) $invoice->StudentID <= 0) {
            return 0;
        }
        $studentId = (int) $invoice->StudentID;
        $courseIds = array_values(array_unique(array_filter(array_map('intval', array_merge(
            [(int) $invoice->StudentClassID], $invoice->items()->pluck('StudentClassID')->all()
        )))));
        return StudentClass::query()->whereIn('ID', $courseIds)->where('StudentID', $studentId)->count() === count($courseIds) ? $studentId : 0;
    }

    private function denyOtherCampus(Request $request, int $campusId): ?JsonResponse
    {
        if ($request->attributes->get('auth_role') === 'super_admin') {
            return null;
        }
        $campusIds = array_map('intval', (array) $request->attributes->get('auth_campus_ids', []));

        return in_array($campusId, $campusIds, true) ? null : response()->json(['message' => '沒有權限執行此操作'], 403);
    }
}
