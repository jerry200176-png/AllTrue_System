<?php

namespace App\Services\Billing;

use App\Models\{Invoice, Payment, StudentClass, SubjectCreditTransfer};
use Brick\Math\BigRational;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Accept only this workflow's fully linked prior movements; unknown legacy transfer chains remain refused. */
final class SubjectCreditFunding
{
    /** @return array<int,true> source payments whose transfer provenance was verified */
    public function knownPayments(StudentClass $source, Invoice $invoice, Collection $payments, int $campusId): array
    {
        if (!Schema::hasTable('contract_subject_credit_transfers')) {
            return [];
        }
        $operations = SubjectCreditTransfer::where('source_student_class_id', $source->ID)->orderBy('id')->lockForUpdate()->get();
        $sourcePayments = $payments->keyBy('id');
        $allowed = [];
        foreach ($operations as $r) {
            if ((int) $r->student_id !== (int) $source->StudentID || (int) $r->campus_id !== $campusId
                || (int) $r->source_invoice_id !== (int) $invoice->id || (int) $r->lesson_minutes !== (int) $source->SessionDuration
                || !BigRational::of((string) $r->unit_price)->isEqualTo((string) $source->Rate)) {
                $this->refuse();
            }
            $out = $sourcePayments->get((int) $r->transfer_out_payment_id);
            $in = Payment::whereKey($r->transfer_in_payment_id)->lockForUpdate()->first();
            if (!$out || !$in || $out->Method !== 'transfer_out' || (int) $out->Amount !== -(int) $r->amount
                || $in->Method !== 'transfer_in' || (int) $in->Amount !== (int) $r->amount
                || (int) $in->InvoiceID !== (int) $r->target_invoice_id || isset($allowed[(int) $out->id])) {
                $this->refuse();
            }
            $allowed[(int) $out->id] = true;
            if ($r->reversed_at !== null) {
                $back = $sourcePayments->get((int) $r->reverse_in_payment_id);
                $reverseOut = Payment::whereKey($r->reverse_out_payment_id)->lockForUpdate()->first();
                if (!$back || !$reverseOut || $back->Method !== 'transfer_reversal_in' || (int) $back->Amount !== (int) $r->amount
                    || $reverseOut->Method !== 'transfer_reversal_out' || (int) $reverseOut->Amount !== -(int) $r->amount
                    || (int) $reverseOut->InvoiceID !== (int) $r->target_invoice_id || isset($allowed[(int) $back->id])) {
                    $this->refuse();
                }
                $allowed[(int) $back->id] = true;
            }
        }
        if ($operations->isNotEmpty()) {
            $cash = fn ($rows) => collect($rows)->filter(fn ($p) => (int) data_get($p, 'Amount') > 0
                && !str_starts_with((string) data_get($p, 'Method'), 'transfer_'))
                ->map(fn ($p) => ['id' => (int) data_get($p, 'id'), 'Amount' => (int) data_get($p, 'Amount'), 'Method' => (string) data_get($p, 'Method')])->values()->all();
            if (SubjectCreditState::digest($cash($operations->first()->source_before['payments'])) !== SubjectCreditState::digest($cash($payments))) {
                $this->refuse();
            }
        }
        return $allowed;
    }

    private function refuse(): never
    {
        abort(response()->json(['message' => '先前轉用的帳務連結未能核對，請先對帳。', 'code' => 'subject_credit_funding_unclear'], 409));
    }
}
