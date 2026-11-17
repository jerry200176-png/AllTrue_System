<?php

namespace App\Services\Billing;

use App\Models\{ClassSession, StudentClass};
use App\Services\Scheduling\ClassSessionDeleteGuard;
use Brick\Math\BigRational;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Cancellable candidates only; never grants credit or cancels a lesson. Caller holds the student/course/session mutexes. */
final class ExistingCourseCreditReservations
{
    /**
     * @param Collection<int,ClassSession> $sessions authorized current locking rows
     * @return Collection<int,ClassSession>
     */
    public function eligibleLocked(StudentClass $source, Collection $sessions): Collection
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Credit release candidates require a locked transaction.');
        }
        foreach ($sessions as $session) {
            if ((int) $session->StudentClassID !== (int) $source->ID) {
                throw new \LogicException('Credit release candidates must belong to the locked source course.');
            }
        }
        $blocked = ClassSessionDeleteGuard::referencedSessionIds($sessions->pluck('id')->all(), true)
            + ClassSessionDeleteGuard::datedScheduleSessionIds($sessions, true);
        $rate = BigRational::of((string) $source->Rate);
        return $sessions->filter(function (ClassSession $session) use ($source, $blocked, $rate) {
            if (strtolower((string) $session->Status) !== 'scheduled' || isset($blocked[(int) $session->id])
                || !$session->SessionDate || !$session->StartTime || !$session->EndTime) return false;
            $date = substr((string) $session->SessionDate, 0, 10);
            $start = Carbon::parse($date . ' ' . $session->StartTime);
            $end = Carbon::parse($date . ' ' . $session->EndTime);
            if ($end->lessThanOrEqualTo($start)) $end->addDay();
            return $start->isFuture() && (int) $start->diffInSeconds($end) === (int) $source->SessionDuration * 60
                && ($session->session_charge === null || $rate->isEqualTo((string) $session->session_charge));
        })->sort(fn (ClassSession $a, ClassSession $b) =>
            [$b->SessionDate, $b->StartTime, (int) $b->id] <=> [$a->SessionDate, $a->StartTime, (int) $a->id])->values();
    }
}
