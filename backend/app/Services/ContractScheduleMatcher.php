<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\StudentClass;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Series (StudentClass week/time) vs occurrence (ClassSession) contract matching.
 * One-off 調課／補課 must set IsContractException and must not rewrite series slots.
 *
 * R84: the single writer of this flag is ClassSessionObserver::saving(), which
 * calls applyExceptionFlag() on every save where the time fields are dirty.
 * Do not call this from individual controllers/services — that's exactly the
 * per-call-site discipline that let #556's atomic-reschedule gap happen. New
 * write paths get the flag for free as long as they save() through Eloquent.
 */
class ContractScheduleMatcher
{
    /**
     * Recompute IsContractException on the in-memory model. Does not save —
     * the caller (ClassSessionObserver::saving) is already mid-persist.
     */
    public function applyExceptionFlag(ClassSession $session, ?StudentClass $studentClass = null): void
    {
        if (!Schema::hasColumn('ClassSession', 'IsContractException')) {
            return;
        }

        $sessionDate = $session->SessionDate ? substr((string) $session->SessionDate, 0, 10) : null;
        if (!$sessionDate) {
            return;
        }

        $studentClass = $studentClass ?: StudentClass::query()
            ->where('ID', $session->getAttribute('StudentClassID'))
            ->first();
        if (!$studentClass) {
            return;
        }

        $startHm = substr((string) $session->StartTime, 0, 5);
        $endHm = $session->EndTime ? substr((string) $session->EndTime, 0, 5) : null;
        $isException = !$this->matchesContract($sessionDate, $startHm, $endHm, $studentClass);
        $session->setAttribute('IsContractException', $isException ? 1 : 0);
    }

    public function matchesContract(string $sessionDate, string $startTime, ?string $endTime, StudentClass $studentClass): bool
    {
        $isoDow = (int) Carbon::parse($sessionDate)->dayOfWeekIso;
        $startHm = substr($startTime, 0, 5);
        $sessionDuration = (int) $studentClass->getAttribute('SessionDuration');
        $globalDurHours = $sessionDuration > 0
            ? round($sessionDuration / 60, 1)
            : 2;

        $weekFields = ['week', 'week1', 'week2', 'week3', 'week4', 'week5', 'week6'];
        $timeFields = ['time', 'time1', 'time2', 'time3', 'time4', 'time5', 'time6'];
        $durationFields = [null, 'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6'];

        foreach ($weekFields as $index => $wf) {
            $day = (int) ($studentClass->{$wf} ?? 0);
            if ($day < 1 || $day > 7) {
                continue;
            }
            $tf = $timeFields[$index];
            $rawTime = (string) ($studentClass->{$tf} ?? $studentClass->time ?? '');
            $slotStart = $rawTime ? substr($rawTime, 0, 5) : '';
            if ($slotStart === '') {
                continue;
            }
            $df = $durationFields[$index] ?? null;
            $perDayMin = $df ? (int) ($studentClass->{$df} ?? 0) : 0;
            $slotDurHours = $perDayMin > 0 ? round($perDayMin / 60, 1) : $globalDurHours;

            $sessDurHours = $globalDurHours;
            if ($endTime) {
                $startM = ((int) substr($startHm, 0, 2)) * 60 + (int) substr($startHm, 3, 2);
                $endM = ((int) substr($endTime, 0, 2)) * 60 + (int) substr($endTime, 3, 2);
                $sessDurHours = max(0, $endM - $startM) > 0 ? round(($endM - $startM) / 60, 1) : $globalDurHours;
            }

            if ($day === $isoDow && $slotStart === $startHm && $slotDurHours === $sessDurHours) {
                return true;
            }
        }

        return false;
    }
}
