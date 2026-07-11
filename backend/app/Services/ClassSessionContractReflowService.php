<?php

namespace App\Services;

use App\Exceptions\SlotOccupiedException;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClassSessionContractReflowService
{
    public function __construct(private ClassSessionMaterializationService $slotService)
    {
    }

    /**
     * Move a set of scheduled sessions onto their recalculated contract slots.
     *
     * The two-phase park/place transaction supports permutations where a target
     * is still occupied by another row in the same reflow under the active-slot
     * unique index. LearningRecord and schedule anchors move atomically with it.
     *
     * @param  array<int, true>  $reflowIds
     * @param  array<int, array{
     *     session: ClassSession,
     *     oldDate: ?string,
     *     oldStartShort: ?string,
     *     newDate: string,
     *     newStart: string,
     *     newEnd: string
     * }>  $moves
     */
    public function move(int $courseId, array $reflowIds, array $moves): int
    {
        foreach ($moves as $move) {
            $conflict = $this->slotService->findActiveSlotConflict(
                $courseId,
                $move['newDate'],
                $move['newStart'],
                (int) $move['session']->id
            );
            if ($conflict !== null && !isset($reflowIds[(int) $conflict->getKey()])) {
                throw SlotOccupiedException::fromConflict(
                    $courseId,
                    $move['newDate'],
                    $move['newStart'],
                    $conflict
                );
            }
        }

        return DB::transaction(function () use ($moves) {
            // Snapshot schedule row identities before any move. Updating by the
            // old date in sequence can move the first row onto the next move's
            // old date, causing that same schedule to cascade forward twice.
            $scheduleIdsByMove = [];
            foreach ($moves as $index => $move) {
                if ($move['oldDate'] === null || $move['oldDate'] === $move['newDate']) {
                    continue;
                }
                $scheduleQuery = Schedule::query()
                    ->where('student_course_id', (int) $move['session']->StudentClassID)
                    ->whereDate('schedule_date', $move['oldDate']);
                if ($move['oldStartShort']) {
                    $scheduleQuery->where('start_time', $move['oldStartShort']);
                }
                $scheduleIdsByMove[$index] = $scheduleQuery->pluck('id')->all();
            }

            $sentinelBase = Carbon::parse('2100-01-01');
            foreach ($moves as $move) {
                foreach ([$move['oldDate'], $move['newDate']] as $date) {
                    if ($date) {
                        $candidate = Carbon::parse($date);
                        if ($candidate->greaterThan($sentinelBase)) {
                            $sentinelBase = $candidate->copy();
                        }
                    }
                }
            }
            $sentinelBase = $sentinelBase->addYear();

            foreach ($moves as $index => $move) {
                $session = $move['session'];
                $session->SessionDate = $sentinelBase->copy()->addDays($index)->toDateString();
                $session->save();
            }

            $updated = 0;
            foreach ($moves as $index => $move) {
                $session = $move['session'];
                $session->SessionDate = $move['newDate'];
                $session->StartTime = $move['newStart'];
                $session->EndTime = $move['newEnd'];
                $session->save();

                LearningRecord::query()
                    ->where('ClassSessionID', (int) $session->id)
                    ->whereNull('VoidedAt')
                    ->update([
                        'SessionDate' => $move['newDate'],
                        'StartTime' => $move['newStart'],
                        'EndTime' => $move['newEnd'],
                    ]);

                $scheduleIds = $scheduleIdsByMove[$index] ?? [];
                if (!empty($scheduleIds)) {
                    Schedule::query()->whereIn('id', $scheduleIds)->update([
                        'schedule_date' => $move['newDate'],
                        'start_time' => substr($move['newStart'], 0, 5),
                        'end_time' => substr($move['newEnd'], 0, 5),
                        'day_of_week' => (int) Carbon::parse($move['newDate'])->dayOfWeekIso,
                    ]);
                }

                $updated++;
            }

            return $updated;
        });
    }
}
