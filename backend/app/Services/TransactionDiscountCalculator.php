<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

/**
 * Calculates one transaction-level discount using integer TWD arithmetic.
 * Decimal strings are parsed explicitly so PHP floats never become money.
 */
class TransactionDiscountCalculator
{
    private const TYPES = ['NONE', 'FIXED_AMOUNT', 'PERCENTAGE'];

    /** @return array{transaction_id:string,original_amount:int,type:string,value:string,discount_amount:int,final_amount:int,reason:string,actor_id:int,actor_role:string,created_at:string} */
    public function calculate(int $originalAmount, ?array $input, int $actorId, string $actorRole): array
    {
        $originalAmount = max(0, $originalAmount);
        $discount = is_array($input) ? $input : [];
        $type = strtoupper(trim((string) ($discount['type'] ?? 'NONE')));
        $rawValue = $discount['value'] ?? '0';
        $reason = trim((string) ($discount['reason'] ?? ''));

        if (!in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['discount.type' => ['折扣類型無效。']]);
        }

        if ($type === 'NONE') {
            return $this->snapshot($originalAmount, $type, '0', 0, '', $actorId, $actorRole);
        }

        if (!is_string($rawValue)) {
            throw ValidationException::withMessages(['discount.value' => ['折扣值必須是十進位字串。']]);
        }

        $value = trim($rawValue);
        if ($type === 'FIXED_AMOUNT') {
            if (!preg_match('/^[0-9]+$/', $value)) {
                throw ValidationException::withMessages(['discount.value' => ['固定折扣必須是整數元。']]);
            }
            $amount = $this->decimalStringToInt($value);
            if ($amount === 0) {
                return $this->snapshot($originalAmount, 'NONE', '0', 0, '', $actorId, $actorRole);
            }
            if ($amount > $originalAmount) {
                throw ValidationException::withMessages(['discount.value' => ['固定折扣不可超過原始金額。']]);
            }
            $discountAmount = $amount;
            $normalizedValue = (string) $amount;
        } else {
            if (!preg_match('/^(?:[0-9]+)(?:\.[0-9]{1,2})?$/', $value)) {
                throw ValidationException::withMessages(['discount.value' => ['百分比必須是最多兩位小數的十進位字串。']]);
            }
            [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
            $basisPoints = ($this->decimalStringToInt($whole) * 100) + (int) str_pad($fraction, 2, '0');
            if ($basisPoints === 0) {
                return $this->snapshot($originalAmount, 'NONE', '0', 0, '', $actorId, $actorRole);
            }
            if ($basisPoints > 10000) {
                throw ValidationException::withMessages(['discount.value' => ['百分比不可超過 100%。']]);
            }
            $discountAmount = intdiv(($originalAmount * $basisPoints) + 5000, 10000);
            $normalizedValue = rtrim(rtrim(number_format($basisPoints / 100, 2, '.', ''), '0'), '.');
        }

        if ($reason === '') {
            throw ValidationException::withMessages(['discount.reason' => ['非零折扣必須填寫原因。']]);
        }

        return $this->snapshot(
            $originalAmount,
            $type,
            $normalizedValue,
            min($originalAmount, $discountAmount),
            $reason,
            $actorId,
            $actorRole
        );
    }

    /** @param list<int> $originalAmounts @return list<int> */
    public function allocate(array $originalAmounts, int $finalAmount): array
    {
        $total = array_sum(array_map('intval', $originalAmounts));
        if ($total <= 0) {
            return array_fill(0, count($originalAmounts), 0);
        }
        $finalAmount = max(0, $finalAmount);
        $allocations = [];
        $remainders = [];
        foreach ($originalAmounts as $index => $amount) {
            $product = max(0, (int) $amount) * $finalAmount;
            $allocations[$index] = intdiv($product, $total);
            $remainders[$index] = $product % $total;
        }
        $remaining = $finalAmount - array_sum($allocations);
        arsort($remainders, SORT_NUMERIC);
        foreach (array_keys($remainders) as $index) {
            if ($remaining <= 0) break;
            $allocations[$index]++;
            $remaining--;
        }
        ksort($allocations);
        return array_values($allocations);
    }

    private function decimalStringToInt(string $value): int
    {
        $value = ltrim($value, '0');
        return $value === '' ? 0 : (int) $value;
    }

    private function snapshot(int $original, string $type, string $value, int $discount, string $reason, int $actorId, string $actorRole): array
    {
        return [
            // One generated identity represents the whole transaction. Callers
            // creating multiple subject rows calculate once and persist this
            // same snapshot on every row.
            'transaction_id' => (string) Str::uuid(),
            'original_amount' => $original,
            'type' => $type,
            'value' => $value,
            'discount_amount' => $discount,
            'final_amount' => $original - $discount,
            'reason' => $reason,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'created_at' => now()->toISOString(),
        ];
    }
}
