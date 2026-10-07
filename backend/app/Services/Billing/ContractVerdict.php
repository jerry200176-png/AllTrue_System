<?php

namespace App\Services\Billing;

/**
 * Immutable Contract Money Verdict for one StudentClass (see GLOSSARY.md).
 * status: free | unbilled | unpaid | partial | paid | review_required.
 * source: invoice | legacy_flag | none (legacy flag only when no non-void invoice exists, Founder 1A).
 */
final class ContractVerdict
{
    /** @param list<array<string, mixed>> $periods per billing period: billing_period, invoice_ids, total, applied, outstanding, status */
    public function __construct(
        public readonly int $studentClassId,
        public readonly string $status,
        public readonly int $owed,
        public readonly int $applied,
        public readonly int $outstanding,
        public readonly int $overpaid,
        public readonly string $source,
        public readonly ?int $currentInvoiceId,
        public readonly ?string $currentPeriod,
        public readonly array $periods,
    ) {
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['paid', 'free'], true);
    }
}
