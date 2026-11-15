<?php

namespace App\Services;

use App\Models\StudentClassPricingAmendment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/** Resolve effective pricing without rewriting the legacy course Rate. */
class StudentClassPricingService
{
    /** @var array<string, array{rate:int, rate_unit:string, source:string, amendment_id:int|null}> */
    private array $cache = [];

    /**
     * #3756: a course's non-voided amendments, read once per service instance and reused for
     * every date (monthly billing and backlog catch-up call forDate() per lesson).
     *
     * @var array<int, \Illuminate\Support\Collection<int, StudentClassPricingAmendment>>
     */
    private array $amendmentsByCourse = [];

    /**
     * @return array{rate:int, rate_unit:string, source:string, amendment_id:int|null}
     */
    public function forDate(Model $course, string|CarbonInterface $date): array
    {
        $dateValue = $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
        $courseId = (int) $course->getAttribute('ID');
        $key = $courseId . '|' . $dateValue;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $amendments = $course->relationLoaded('pricingAmendments')
            ? $course->getRelation('pricingAmendments')
            : ($courseId > 0
                ? ($this->amendmentsByCourse[$courseId] ??= StudentClassPricingAmendment::query()
                    ->where('student_class_id', $courseId)
                    ->whereNull('voided_at')
                    ->get())
                : collect());
        // Latest effective_from on or before the date; the higher id wins a tie (stable sorts).
        $amendment = $amendments
            ->filter(fn ($row) => !$row->voided_at && substr((string) $row->effective_from, 0, 10) <= $dateValue)
            ->sortByDesc('id')->sortByDesc('effective_from')->first();

        if ($amendment) {
            return $this->cache[$key] = [
                'rate' => (int) $amendment->getAttribute('rate'),
                'rate_unit' => $this->normalizeRateUnit((string) $amendment->getAttribute('rate_unit')),
                'source' => 'pricing_amendment',
                'amendment_id' => (int) $amendment->getKey(),
            ];
        }

        return $this->cache[$key] = [
            'rate' => max(0, (int) round((float) ($course->getAttribute('Rate') ?? 0))),
            'rate_unit' => $this->normalizeRateUnit((string) ($course->getAttribute('rate_unit') ?? 'session')),
            'source' => 'student_class_rate',
            'amendment_id' => null,
        ];
    }

    /**
     * 單價 printed on a payment slip (Founder 2026-11-16), display only: the course's price in force over the slip's
     * service dates, from the same resolver the monthly bill is priced with. Null when no price is set, or when the
     * price at the start and at the end of those dates differ: no single figure would be true for the whole slip.
     * ponytail: compares the two ends only; a change that starts and is undone inside one period is not noticed.
     *
     * @return array{unit_price: int|null, unit_price_unit: string}
     */
    public function slipUnitPrice(Model $course, ?string $from = null, ?string $to = null): array
    {
        $from = $from ? substr($from, 0, 10) : now('Asia/Taipei')->toDateString();
        $first = $this->forDate($course, $from);
        $last = $this->forDate($course, $to ? substr($to, 0, 10) : $from);
        $single = $first['rate'] > 0 && $first['rate'] === $last['rate'] && $first['rate_unit'] === $last['rate_unit'];

        return ['unit_price' => $single ? $first['rate'] : null, 'unit_price_unit' => $first['rate_unit']];
    }

    private function normalizeRateUnit(string $rateUnit): string
    {
        return in_array(strtolower(trim($rateUnit)), ['session', 'hour'], true)
            ? strtolower(trim($rateUnit))
            : 'session';
    }
}
