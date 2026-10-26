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

    private function normalizeRateUnit(string $rateUnit): string
    {
        return in_array(strtolower(trim($rateUnit)), ['session', 'hour'], true)
            ? strtolower(trim($rateUnit))
            : 'session';
    }
}
