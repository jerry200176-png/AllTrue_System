<?php

namespace Database\Factories;

use App\Models\Campus;
use App\Models\CampusSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampusSnapshotFactory extends Factory
{
    protected $model = CampusSnapshot::class;

    public function definition(): array
    {
        return [
            'campus_id' => Campus::factory(),
            'snapshot_date' => now()->startOfMonth(),
            'metric_key' => $this->faker->randomElement([
                'student_count',
                'active_teachers',
                'monthly_revenue',
                'monthly_sessions',
                'monthly_attendance',
                'monthly_teaching_hours',
            ]),
            'metric_value' => $this->faker->randomFloat(2, 0, 999999.99),
        ];
    }

    /**
     * Set a specific metric key-value pair.
     */
    public function metric(string $key, float $value): self
    {
        return $this->state(fn () => [
            'metric_key' => $key,
            'metric_value' => $value,
        ]);
    }

    /**
     * Set a specific snapshot date.
     */
    public function forDate(string $date): self
    {
        return $this->state(fn () => [
            'snapshot_date' => $date,
        ]);
    }

    /**
     * Set a specific campus.
     */
    public function forCampus(int $campusId): self
    {
        return $this->state(fn () => [
            'campus_id' => $campusId,
        ]);
    }

    /**
     * Create a full set of metrics for one campus on a given date.
     */
    public static function createSnapshotSet(int $campusId, string $date, array $metrics = []): void
    {
        $defaults = [
            'student_count' => 50,
            'active_teachers' => 10,
            'monthly_revenue' => 200000,
            'monthly_sessions' => 400,
            'monthly_attendance' => 360,
            'monthly_teaching_hours' => 520,
        ];

        $metrics = array_merge($defaults, $metrics);

        foreach ($metrics as $key => $value) {
            CampusSnapshot::create([
                'campus_id' => $campusId,
                'snapshot_date' => $date,
                'metric_key' => $key,
                'metric_value' => $value,
            ]);
        }
    }
}
