<?php

namespace App\Services\Scheduling;

use App\Models\StudentClass;

/**
 * RFC_NONSTANDARD_SESSION_DURATION_BILLING §"課程契約鎖定" — the billing contract
 * becomes immutable once any entitlement has been consumed.
 *
 * Before the first deduction ledger row an authorised teacher/director may freely
 * change `standard_lesson_minutes`, `deduction_basis` and the purchased unit count.
 * After it they may not, through any ordinary edit path.
 *
 * The reason is not caution, it is arithmetic: entitlement is still derived as
 * `SessionCount x standard_lesson_minutes`, with no entitlement snapshot, effective
 * date, or allocation semantics. Editing any of those inputs therefore re-derives
 * the purchased total and retroactively reinterprets every deduction already
 * recorded against the old contract. v1 deliberately offers NO correction command
 * as an escape hatch — a command claiming to "only affect the future" could not
 * honour that promise in this data model. Post-deduction repair is deferred until
 * entitlement snapshots exist.
 *
 * A disabled field in the UI is a convenience, never the control: this guard is the
 * authoritative one and must be called from every server-side edit path.
 */
final class BillingContractLockGuard
{
    /** Fields frozen once entitlement has been consumed. */
    public const LOCKED_FIELDS = ['standard_lesson_minutes', 'deduction_basis', 'SessionCount'];

    /**
     * @param  array<string, mixed>  $incoming  Already-mapped payload about to be written.
     * @return array{locked:bool, attempted_fields:list<string>, message:?string}
     */
    public function inspect(StudentClass $course, array $incoming): array
    {
        $attempted = [];
        foreach (self::LOCKED_FIELDS as $field) {
            if (!array_key_exists($field, $incoming)) {
                continue;
            }
            // Only a genuine change counts: re-submitting the current value (which
            // ordinary "save the whole form" flows do constantly) must not be blocked.
            if ($this->isChanged($course, $field, $incoming[$field])) {
                $attempted[] = $field;
            }
        }

        if ($attempted === [] || !$course->hasDeductionHistory()) {
            return ['locked' => false, 'attempted_fields' => $attempted, 'message' => null];
        }

        return [
            'locked' => true,
            'attempted_fields' => $attempted,
            'message' => '此課程已有扣堂紀錄，計費契約（標準堂長／扣堂方式／購買堂數）不可再修改。'
                . '修改會連帶重新計算已購買總額度，等同追溯改寫既有扣堂紀錄。'
                . '如需更正，請改為結案後另開新課程。',
        ];
    }

    /** @param array<string, mixed> $incoming */
    public function isLocked(StudentClass $course, array $incoming): bool
    {
        return $this->inspect($course, $incoming)['locked'];
    }

    private function isChanged(StudentClass $course, string $field, mixed $incomingValue): bool
    {
        $current = $course->getAttribute($field);

        if ($field === 'deduction_basis') {
            return (string) ($incomingValue ?? DeductionBasis::FIXED_SESSION)
                !== (string) ($current ?? DeductionBasis::FIXED_SESSION);
        }

        // standard_lesson_minutes / SessionCount are nullable integers.
        $normalize = static fn (mixed $v): ?int => ($v === null || $v === '') ? null : (int) $v;

        return $normalize($incomingValue) !== $normalize($current);
    }
}
