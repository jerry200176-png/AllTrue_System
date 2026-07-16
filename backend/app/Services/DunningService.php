<?php

namespace App\Services;

use App\Models\DunningEvent;
use App\Models\StudentClass;
use Illuminate\Support\Facades\Schema;

class DunningService
{
    /**
     * Rules aligned with DIRECTOR_PAYMENT_ALERT_RULES.md.
     * DO NOT change conditions without user approval.
     */
    public const RULES = [
        'unpaid_reminder' => [
            'description' => '未繳費提醒（堂數制 Paid!=1）',
            'min_days_overdue' => 0,
            'cooldown_days' => 7,
        ],
        'low_sessions' => [
            'description' => '剩餘堂數不足提醒（≤2堂）',
            'min_days_overdue' => 0,
            'cooldown_days' => 14,
        ],
        'monthly_overdue' => [
            'description' => '月結逾期提醒（過繳費日未繳）',
            'min_days_overdue' => 1,
            'cooldown_days' => 7,
        ],
        'monthly_due_soon' => [
            'description' => '月結繳費日即將到來（0~4天）',
            'min_days_overdue' => 0,
            'cooldown_days' => 30,
        ],
    ];

    private const WEEKLY_CAP_PER_STUDENT = 3;

    public function evaluateAll(?int $campusId = null): array
    {
        if (!Schema::hasTable('dunning_events')) {
            return [];
        }

        $results = [];
        $results = array_merge($results, $this->evaluateCountMode($campusId));
        $results = array_merge($results, $this->evaluateDateMode($campusId));

        return $results;
    }

    private function evaluateCountMode(?int $campusId): array
    {
        $query = StudentClass::with('student')
            ->where('Stop', 0)
            ->where('ScheduleMode', 'count');

        if ($campusId) {
            $query->whereHas('student', fn ($q) => $q->where('CampusID', $campusId));
        }

        $events = [];
        foreach ($query->cursor() as $course) {
            if ((int) ($course->Paid ?? 0) !== 1) {
                $event = $this->tryCreateEvent(
                    (int) $course->StudentID,
                    (int) $course->ID,
                    (int) ($course->student->CampusID ?? 0),
                    'unpaid_reminder'
                );
                if ($event) {
                    $events[] = $event;
                }
            }

            if ((int) ($course->RemainingSessions ?? 99) <= 2) {
                if (!$this->hasRenewal($course)) {
                    $event = $this->tryCreateEvent(
                        (int) $course->StudentID,
                        (int) $course->ID,
                        (int) ($course->student->CampusID ?? 0),
                        'low_sessions'
                    );
                    if ($event) {
                        $events[] = $event;
                    }
                }
            }
        }

        return $events;
    }

    private function evaluateDateMode(?int $campusId): array
    {
        $query = StudentClass::with('student')
            ->where('Stop', 0)
            ->where('ScheduleMode', 'date')
            ->whereNotNull('settlement_day')
            ->where('settlement_day', '>', 0);

        if ($campusId) {
            $query->whereHas('student', fn ($q) => $q->where('CampusID', $campusId));
        }

        $today = now()->startOfDay();
        $events = [];

        foreach ($query->cursor() as $course) {
            $settlementDay = (int) $course->settlement_day;
            $maxDay = $today->copy()->endOfMonth()->day;
            $effectiveDay = min($settlementDay, $maxDay);
            $dueDate = $today->copy()->setDay($effectiveDay)->startOfDay();

            if ($dueDate->isAfter($today)) {
                $dueDate = $today->copy()->subMonth()->setDay(min($settlementDay, $today->copy()->subMonth()->endOfMonth()->day))->startOfDay();
            }

            $isPaid = (int) ($course->Paid ?? 0) === 1;
            $daysFromDue = $today->diffInDays($dueDate, false);

            // Paid date-mode courses may still show monthly_due_soon in director alerts,
            // but dunning events are customer-facing reminders and must not fire once paid.
            if ($isPaid) {
                continue;
            }

            if ($daysFromDue > 0) {
                $event = $this->tryCreateEvent(
                    (int) $course->StudentID,
                    (int) $course->ID,
                    (int) ($course->student->CampusID ?? 0),
                    'monthly_overdue'
                );
                if ($event) {
                    $events[] = $event;
                }
            } elseif (abs($daysFromDue) < 5) {
                $event = $this->tryCreateEvent(
                    (int) $course->StudentID,
                    (int) $course->ID,
                    (int) ($course->student->CampusID ?? 0),
                    'monthly_due_soon'
                );
                if ($event) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    private function tryCreateEvent(int $studentId, int $studentClassId, int $campusId, string $ruleKey): ?array
    {
        $rule = self::RULES[$ruleKey] ?? null;
        if (!$rule) {
            return null;
        }

        $cooldown = $rule['cooldown_days'];
        $recentEvent = DunningEvent::query()
            ->where('student_class_id', $studentClassId)
            ->where('rule_key', $ruleKey)
            ->where('sent_at', '>=', now()->subDays($cooldown))
            ->exists();

        if ($recentEvent) {
            return null;
        }

        $weeklyCount = DunningEvent::query()
            ->where('student_id', $studentId)
            ->where('sent_at', '>=', now()->subDays(7))
            ->count();

        if ($weeklyCount >= self::WEEKLY_CAP_PER_STUDENT) {
            return null;
        }

        $event = DunningEvent::create([
            'student_id' => $studentId,
            'student_class_id' => $studentClassId,
            'campus_id' => $campusId,
            'rule_key' => $ruleKey,
            'channel' => 'in_app',
            'sent_at' => now(),
        ]);

        return [
            'id' => (int) $event->id,
            'student_id' => $studentId,
            'student_class_id' => $studentClassId,
            'rule_key' => $ruleKey,
        ];
    }

    private function hasRenewal(StudentClass $course): bool
    {
        return StudentClass::where('StudentID', $course->StudentID)
            ->where('SubjectID', $course->SubjectID)
            ->where('Stop', 0)
            ->where('RemainingSessions', '>', 2)
            ->where('ID', '!=', $course->ID)
            ->exists();
    }
}
