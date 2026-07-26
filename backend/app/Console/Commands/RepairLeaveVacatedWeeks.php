<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\StudentClass;
use App\Services\CourseLeaveCascadeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Scan / repair silent vacated weeks caused by legacy leave SHIFT cascade.
 *
 * Default: dry-run. Writes require --apply and ALLOW_PROD_REPAIR=1 on production.
 *
 * Buckets:
 *  A future-safe — leave in past/today, vacated recurrence still in future, no collision
 *  B historical — vacated date already past → review only
 *  C ambiguous — cannot prove provenance → review only
 */
class RepairLeaveVacatedWeeks extends Command
{
    protected $signature = 'repair:leave-vacated-weeks
                            {--dry-run : Preview only (default)}
                            {--apply : Apply future-safe repairs}
                            {--campus-id= : Limit to CampusID}
                            {--course-id= : Limit to StudentClass ID}
                            {--student-id= : Limit to Student ID}
                            {--from= : Leave date from (YYYY-MM-DD)}
                            {--to= : Leave date to (YYYY-MM-DD)}
                            {--limit=200 : Max leave rows to scan}
                            {--force : Required with --apply on production}
                            {--actor= : Executor audit label}';

    protected $description = 'Scan/repair silent vacated weeks from legacy leave shift cascade';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $dryRun = !$apply;
        if ($apply && !$this->assertProductionAllowed()) {
            return self::FAILURE;
        }

        $cases = $this->scanCases();
        $futureSafe = array_values(array_filter($cases, fn ($c) => $c['bucket'] === 'A_future_safe'));
        $historical = array_values(array_filter($cases, fn ($c) => $c['bucket'] === 'B_historical'));
        $ambiguous = array_values(array_filter($cases, fn ($c) => $c['bucket'] === 'C_ambiguous'));

        $this->info(sprintf(
            'scan: total=%d future_safe=%d historical_review=%d ambiguous=%d',
            count($cases),
            count($futureSafe),
            count($historical),
            count($ambiguous)
        ));

        foreach ($cases as $case) {
            $this->line(json_encode($case, JSON_UNESCAPED_UNICODE));
        }

        $applied = 0;
        $blockers = 0;
        if ($apply) {
            foreach ($futureSafe as $case) {
                try {
                    $this->applyFutureSafeRepair($case);
                    $applied++;
                    Log::info('repair.leave_vacated_weeks.applied', [
                        'actor' => (string) ($this->option('actor') ?? 'cli'),
                        'case' => $case,
                    ]);
                } catch (\Throwable $e) {
                    $blockers++;
                    $this->error('apply failed course=' . $case['course_id'] . ' leave=' . $case['leave_date'] . ' ' . $e->getMessage());
                    Log::warning('repair.leave_vacated_weeks.apply_failed', [
                        'case' => $case,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info(sprintf(
            'done dry_run=%s applied=%d blockers=%d',
            $dryRun ? '1' : '0',
            $applied,
            $blockers
        ));

        if ($blockers > 0) {
            return self::FAILURE;
        }
        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function scanCases(): array
    {
        $limit = max(1, (int) $this->option('limit'));
        $campusId = (int) ($this->option('campus-id') ?? 0);
        $courseId = (int) ($this->option('course-id') ?? 0);
        $studentId = (int) ($this->option('student-id') ?? 0);
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'))->toDateString() : null;
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to'))->toDateString() : null;
        $today = Carbon::now('Asia/Taipei')->toDateString();

        $q = DB::table('ClassSession as cs')
            ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
            ->join('Student as st', 'st.id', '=', 'sc.StudentID')
            ->whereRaw("LOWER(cs.Status) = 'leave'")
            ->where(function ($w) {
                $w->where('sc.ScheduleMode', 'count')
                    ->orWhere(function ($w2) {
                        $w2->whereNull('sc.ScheduleMode')->where('sc.SessionCount', '>', 0);
                    });
            })
            ->select([
                'cs.id as leave_session_id',
                'cs.SessionDate as leave_date',
                'cs.Note as leave_note',
                'cs.StudentClassID as course_id',
                'sc.SessionCount',
                'sc.ScheduleMode',
                'sc.TeacherID',
                'st.id as student_id',
                'st.CampusID as campus_id',
                'st.name as student_name',
            ])
            ->orderBy('cs.SessionDate')
            ->limit($limit);

        if ($campusId > 0) {
            $q->where('st.CampusID', $campusId);
        }
        if ($courseId > 0) {
            $q->where('cs.StudentClassID', $courseId);
        }
        if ($studentId > 0) {
            $q->where('st.id', $studentId);
        }
        if ($from) {
            $q->whereDate('cs.SessionDate', '>=', $from);
        }
        if ($to) {
            $q->whereDate('cs.SessionDate', '<=', $to);
        }

        $cases = [];
        foreach ($q->get() as $row) {
            $course = StudentClass::query()->where('ID', (int) $row->course_id)->first();
            if (!$course) {
                continue;
            }
            $leaveDate = Carbon::parse((string) $row->leave_date)->toDateString();
            $sessions = ClassSession::query()
                ->where('StudentClassID', (int) $row->course_id)
                ->orderBy('SessionDate')
                ->orderBy('id')
                ->get();

            $isVacated = CourseLeaveCascadeService::detectLegacyVacatedWeekPattern(
                $sessions,
                $leaveDate,
                $course
            );
            if (!$isVacated) {
                continue;
            }

            $weekdays = CourseLeaveCascadeService::resolveCourseWeekdays(
                $course,
                (int) Carbon::parse($leaveDate)->dayOfWeekIso
            );
            $vacatedDate = CourseLeaveCascadeService::nextRecurringDate(
                Carbon::parse($leaveDate)->startOfDay(),
                $weekdays,
                [$leaveDate => true]
            );

            $hasManualReschedule = $sessions->contains(function ($s) use ($leaveDate) {
                $note = strtolower((string) ($s->Note ?? ''));
                $d = Carbon::parse($s->SessionDate)->toDateString();
                return $d > $leaveDate && (
                    str_contains($note, 'reschedule')
                    || str_contains($note, 'makeup')
                    || (!empty($s->IsContractException) && Schema::hasColumn('ClassSession', 'IsContractException'))
                );
            });

            $append = CourseLeaveCascadeService::findAppendedSessionForLeave(
                $sessions,
                $leaveDate,
                (int) $row->leave_session_id
            );

            $collision = ClassSession::query()
                ->where('StudentClassID', (int) $row->course_id)
                ->whereDate('SessionDate', $vacatedDate)
                ->whereRaw("LOWER(Status) <> 'cancelled'")
                ->exists();

            $bucket = 'A_future_safe';
            $reason = 'legacy_shift_vacated_future';
            if ($hasManualReschedule || !$append) {
                $bucket = 'C_ambiguous';
                $reason = $hasManualReschedule ? 'manual_or_exception_present' : 'missing_append_provenance';
            } elseif ($vacatedDate <= $today || $collision) {
                $bucket = 'B_historical';
                $reason = $collision ? 'vacated_slot_occupied' : 'vacated_date_past';
            }

            $cases[] = [
                'bucket' => $bucket,
                'reason' => $reason,
                'campus_id' => (int) $row->campus_id,
                'student_id' => (int) $row->student_id,
                'student_name' => (string) $row->student_name,
                'course_id' => (int) $row->course_id,
                'leave_session_id' => (int) $row->leave_session_id,
                'leave_date' => $leaveDate,
                'vacated_date' => $vacatedDate,
                'append_session_id' => $append ? (int) $append->id : null,
                'append_date' => $append ? Carbon::parse($append->SessionDate)->toDateString() : null,
                'purchased' => (int) ($row->SessionCount ?? 0),
                'before' => [
                    'session_dates' => $sessions->map(fn ($s) => Carbon::parse($s->SessionDate)->toDateString() . ':' . $s->Status)->values()->all(),
                ],
                'proposed_after' => $bucket === 'A_future_safe'
                    ? $this->proposeAfter($sessions, $leaveDate, $vacatedDate, $append, $weekdays, $course)
                    : null,
            ];
        }

        return $cases;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ClassSession>  $sessions
     * @param  array<int>  $weekdays
     * @return array<string,mixed>
     */
    private function proposeAfter($sessions, string $leaveDate, string $vacatedDate, $append, array $weekdays, StudentClass $course): array
    {
        // Reverse one SHIFT: move scheduled sessions after leave back one recurrence,
        // restore vacated date occupancy, remove safe append.
        $moves = [];
        $movable = $sessions->filter(function ($s) use ($leaveDate, $append) {
            if ($append && (int) $s->id === (int) $append->id) {
                return false;
            }
            $st = strtolower((string) ($s->Status ?? ''));
            $d = Carbon::parse($s->SessionDate)->toDateString();
            return $d > $leaveDate && $st === 'scheduled';
        })->values();

        $occupied = [$leaveDate => true];
        foreach ($sessions as $s) {
            if ($append && (int) $s->id === (int) $append->id) {
                continue;
            }
            if ($movable->contains(fn ($m) => (int) $m->id === (int) $s->id)) {
                continue;
            }
            $occupied[Carbon::parse($s->SessionDate)->toDateString()] = true;
        }

        foreach ($movable as $s) {
            $from = Carbon::parse($s->SessionDate)->toDateString();
            $to = CourseLeaveCascadeService::prevRecurringDate(
                Carbon::parse($from)->startOfDay(),
                $weekdays,
                $occupied,
                $leaveDate
            );
            $moves[] = ['id' => (int) $s->id, 'from' => $from, 'to' => $to];
            $occupied[$to] = true;
        }

        return [
            'moves' => $moves,
            'remove_append_session_id' => $append ? (int) $append->id : null,
            'vacated_restored' => $vacatedDate,
        ];
    }

    /** @param  array<string,mixed>  $case */
    private function applyFutureSafeRepair(array $case): void
    {
        if (($case['bucket'] ?? '') !== 'A_future_safe') {
            throw new \InvalidArgumentException('not future-safe');
        }
        $plan = $case['proposed_after'] ?? null;
        if (!is_array($plan)) {
            throw new \InvalidArgumentException('missing proposed_after');
        }

        DB::transaction(function () use ($case, $plan) {
            $courseId = (int) $case['course_id'];
            $course = StudentClass::query()->where('ID', $courseId)->lockForUpdate()->first();
            if (!$course) {
                throw new \InvalidArgumentException('course missing');
            }

            // Earliest-first: each move lands on a slot freed by the previous move.
            foreach (($plan['moves'] ?? []) as $move) {
                /** @var ClassSession|null $session */
                $session = ClassSession::query()->where('id', (int) $move['id'])->lockForUpdate()->first();
                if (!$session || strtolower((string) $session->Status) !== 'scheduled') {
                    throw new \InvalidArgumentException('movable session not safe id=' . ($move['id'] ?? ''));
                }
                $from = Carbon::parse($session->SessionDate)->toDateString();
                if ($from !== $move['from']) {
                    throw new \InvalidArgumentException('date drift before apply id=' . $session->id);
                }
                app(\App\Services\ClassSessionMaterializationService::class)
                    ->assertSlotAvailable($courseId, $move['to'], $session->StartTime, (int) $session->id);
                $session->SessionDate = $move['to'];
                CourseLeaveCascadeService::alignSessionTimesToContractWeekday($course, $session, $move['to']);
                $session->Note = CourseLeaveCascadeService::appendNote(
                    $session->Note,
                    'repaired-from-leave-vacated-week'
                );
                $session->save();
                CourseLeaveCascadeService::syncLearningRecordSessionDate($session);
            }

            $appendId = (int) ($plan['remove_append_session_id'] ?? 0);
            if ($appendId > 0) {
                $append = ClassSession::query()->where('id', $appendId)->lockForUpdate()->first();
                if ($append && CourseLeaveCascadeService::isSafeToRemoveAutoAppend($append)) {
                    $append->delete();
                } elseif ($append) {
                    throw new \InvalidArgumentException('append not safe to remove');
                }
            }

            $end = ClassSession::query()->where('StudentClassID', $courseId)->max('SessionDate');
            if ($end) {
                DB::table('StudentClass')->where('ID', $courseId)->update([
                    'EndDate' => substr((string) $end, 0, 10),
                ]);
            }
        });
    }

    private function assertProductionAllowed(): bool
    {
        $env = (string) config('app.env', 'production');
        if (in_array($env, ['local', 'testing'], true)) {
            return true;
        }
        if (!(bool) $this->option('force')) {
            $this->error('--force required with --apply outside local/testing');
            return false;
        }
        if (getenv('ALLOW_PROD_REPAIR') !== '1' && ($_ENV['ALLOW_PROD_REPAIR'] ?? null) !== '1') {
            $this->error('ALLOW_PROD_REPAIR=1 required for production apply');
            return false;
        }
        return true;
    }
}
