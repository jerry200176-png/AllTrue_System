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

        $amendment = $courseId > 0
            ? StudentClassPricingAmendment::query()
                ->where('student_class_id', $courseId)
                ->whereNull('voided_at')
                ->whereDate('effective_from', '<=', $dateValue)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first()
            : null;

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
