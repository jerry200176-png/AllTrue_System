<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Display-only labels for accounting receipt rows.
 * Does not change prepaid math: first live (non-cancelled) session remains the prepaid anchor.
 */
class AccountingCourseClarity
{
    public const CLASS_TYPE_LABELS = [
        'one_on_one' => '一對一',
        'one_on_two' => '一對二',
        'one_on_three' => '一對三',
        'tutoring' => '輔導',
        'trial' => '試聽',
    ];

    public static function classTypeLabel(?string $type): string
    {
        $key = (string) $type;

        return self::CLASS_TYPE_LABELS[$key] ?? '';
    }

    /**
     * @return array{code:string,label:string,is_history:bool}
     */
    public static function lifecycle(?Model $sc): array
    {
        if (!$sc) {
            return ['code' => 'unknown', 'label' => '', 'is_history' => false];
        }

        $reason = (string) ($sc->getAttribute('closed_reason') ?? '');
        $stop = (int) ($sc->getAttribute('Stop') ?? 0) === 1;
        $mode = (string) ($sc->getAttribute('ScheduleMode') ?? 'count');

        if ($reason === 'settled') {
            return ['code' => 'history_settled', 'label' => '已結算', 'is_history' => true];
        }
        if ($reason === 'completed') {
            return ['code' => 'history_completed', 'label' => '已完課', 'is_history' => true];
        }
        if ($stop && $mode !== 'date' && (int) ($sc->getAttribute('Paid') ?? 0) === 1 && (int) ($sc->getAttribute('RemainingSessions') ?? 0) <= 0) {
            return ['code' => 'history_completed', 'label' => '已完課', 'is_history' => true];
        }
        if ($stop && $mode === 'date') {
            return ['code' => 'history_completed', 'label' => '已完課', 'is_history' => true];
        }
        if ($stop) {
            return ['code' => 'paused', 'label' => '暫停', 'is_history' => false];
        }

        return ['code' => 'active', 'label' => '進行中', 'is_history' => false];
    }

    public static function contractStartDate(?Model $sc): ?string
    {
        if (!$sc) {
            return null;
        }
        $start = $sc->getAttribute('StartDate');
        if ($start instanceof DateTimeInterface) {
            return $start->format('Y-m-d');
        }
        $raw = trim((string) $start);

        return $raw === '' ? null : substr($raw, 0, 10);
    }

    /**
     * @param  array{first_live?:?string,first_any?:?string}  $meta
     * @return array{date:?string,display:?string,source:string,note:string}
     */
    public static function firstSession(array $meta, ?Model $sc): array
    {
        $live = self::ymd($meta['first_live'] ?? null);
        $any = self::ymd($meta['first_any'] ?? null);
        $contract = self::contractStartDate($sc);
        $life = self::lifecycle($sc);

        if ($live !== null) {
            return ['date' => $live, 'display' => $live, 'source' => 'session', 'note' => ''];
        }
        if ($any !== null) {
            $note = $life['is_history']
                ? '課程已進歷史，有效堂次已取消，此為歷史首堂'
                : '有效堂次已取消，此為歷史首堂';

            return ['date' => null, 'display' => $any, 'source' => 'cancelled', 'note' => $note];
        }
        if ($contract !== null) {
            $note = $life['is_history']
                ? '課程已進歷史，目前沒有有效堂次，顯示合約開課日'
                : '尚未排課，顯示合約開課日';

            return ['date' => null, 'display' => $contract, 'source' => 'contract', 'note' => $note];
        }

        return ['date' => null, 'display' => null, 'source' => 'none', 'note' => '沒有堂次也沒有合約開課日'];
    }

    public static function zeroReason(int $amount, ?string $classType): ?string
    {
        if ($amount !== 0) {
            return null;
        }
        if ($classType === 'trial') {
            return 'trial';
        }
        if ($classType === 'tutoring') {
            return 'tutoring';
        }

        return 'zero';
    }

    private static function ymd(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return substr($raw, 0, 10);
    }
}
