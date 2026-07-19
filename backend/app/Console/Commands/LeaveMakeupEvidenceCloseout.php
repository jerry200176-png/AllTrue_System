<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use App\Services\CourseLeaveCascadeService;
use App\Services\SessionDeductionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Production evidence: drift dry-run + disposable __CLOSEOUT_TEST__ probes. */
class LeaveMakeupEvidenceCloseout extends Command
{
    protected $signature = 'evidence:leave-makeup-closeout
                            {--limit=200}
                            {--skip-fixture : Skip disposable probes}';

    protected $description = 'Leave drift dry-run + disposable leave/makeup probes';

    private const MARK = '__CLOSEOUT_TEST__';

    public function handle(): int
    {
        $t0 = microtime(true);
        $this->info('=== evidence:leave-makeup-closeout ===');
        $this->line('deploy_head=' . trim((string) @shell_exec('git -C /home/admin/backend rev-parse HEAD 2>/dev/null')));

        $this->locateWedSat();
        $this->call('repair:leave-cascade-slot-times', ['--limit' => (int) $this->option('limit'), '--dry-run' => true]);

        $leave = ['ok' => true, 'skipped' => true];
        $makeup = ['ok' => true, 'skipped' => true];
        if (!(bool) $this->option('skip-fixture')) {
            $leave = $this->probeLeave();
            $makeup = $this->probeMakeup();
        }

        $summary = [
            'elapsed_ms' => (int) round((microtime(true) - $t0) * 1000),
            'leave_probe_ok' => (bool) ($leave['ok'] ?? false),
            'makeup_probe_ok' => (bool) ($makeup['ok'] ?? false),
            'leave_probe' => $leave,
            'makeup_probe' => $makeup,
        ];
        $this->info('=== SUMMARY_JSON ' . json_encode($summary, JSON_UNESCAPED_UNICODE) . ' ===');

        return (($leave['ok'] ?? false) && ($makeup['ok'] ?? false)) ? self::SUCCESS : self::FAILURE;
    }

    private function locateWedSat(): void
    {
        $this->info('--- locate Wed17/Sat10 contracts (PII-free) ---');
        $rows = DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')
            ->where('sc.Stop', 0)
            ->where(function ($q) {
                $q->where(function ($w) {
                    $w->where('sc.week', 3)->whereRaw("LEFT(sc.time,5)='17:00'")
                        ->where('sc.week1', 6)->whereRaw("LEFT(sc.time1,5)='10:00'");
                })->orWhere(function ($w) {
                    $w->where('sc.week', 6)->whereRaw("LEFT(sc.time,5)='10:00'")
                        ->where('sc.week1', 3)->whereRaw("LEFT(sc.time1,5)='17:00'");
                });
            })
            ->orderBy('sc.ID')->limit(30)
            ->get(['sc.ID as sc_id', 's.CampusID as campus_id', 'sc.week', 'sc.time', 'sc.week1', 'sc.time1', 'sc.ScheduleMode']);

        $this->line('wed17_sat10_contracts=' . $rows->count());
        foreach ($rows as $r) {
            $leaves = (int) ClassSession::where('StudentClassID', (int) $r->sc_id)
                ->whereRaw("LOWER(Status) IN ('leave','leave_adjusted','leave_requested')")->count();
            $this->line(sprintf(
                'sc=%d campus=%d %s@%s + %s@%s mode=%s leave_rows=%d',
                (int) $r->sc_id,
                (int) $r->campus_id,
                $r->week,
                substr((string) $r->time, 0, 5),
                $r->week1,
                substr((string) $r->time1, 0, 5),
                (string) $r->ScheduleMode,
                $leaves
            ));
        }
    }

    /** @return array<string,mixed> */
    private function probeLeave(): array
    {
        $this->info('--- leave fixture probe ---');
        $ids = [];
        try {
            return DB::transaction(function () use (&$ids) {
                [$studentId, $courseId] = $this->seedCourse(true);
                $ids = compact('studentId', 'courseId');
                foreach ([
                    ['2026-07-01', '17:00:00', '19:00:00'],
                    ['2026-07-04', '10:00:00', '12:00:00'],
                    ['2026-07-08', '17:00:00', '19:00:00'],
                    ['2026-07-11', '10:00:00', '12:00:00'],
                ] as [$d, $s, $e]) {
                    $this->insertSession($courseId, $d, $s, $e);
                }
                $exId = null;
                if (Schema::hasColumn('ClassSession', 'IsContractException')) {
                    $exId = $this->insertSession($courseId, '2026-07-15', '14:00:00', '16:00:00', true);
                }

                CourseLeaveCascadeService::applyLeaveCascade($courseId, '2026-07-01');
                $after = ClassSession::where('StudentClassID', $courseId)
                    ->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")->get();
                $satOk = $wedOk = true;
                foreach ($after as $cs) {
                    if ($exId && (int) $cs->id === $exId) {
                        $satOk = $satOk && substr((string) $cs->StartTime, 0, 5) === '14:00';
                        continue;
                    }
                    $iso = (int) Carbon::parse((string) $cs->SessionDate)->dayOfWeekIso;
                    $st = substr((string) $cs->StartTime, 0, 5);
                    if ($iso === 6) {
                        $satOk = $satOk && $st === '10:00';
                    }
                    if ($iso === 3) {
                        $wedOk = $wedOk && $st === '17:00';
                    }
                }

                CourseLeaveCascadeService::undoLeaveCascade($courseId, '2026-07-01');
                $undoOk = true;
                foreach (ClassSession::where('StudentClassID', $courseId)->whereRaw("LOWER(Status) NOT IN ('cancelled','voided')")->get() as $cs) {
                    if ($exId && (int) $cs->id === $exId) {
                        continue;
                    }
                    $iso = (int) Carbon::parse((string) $cs->SessionDate)->dayOfWeekIso;
                    $st = substr((string) $cs->StartTime, 0, 5);
                    if ($iso === 6 && $st !== '10:00') {
                        $undoOk = false;
                    }
                    if ($iso === 3 && $st !== '17:00') {
                        $undoOk = false;
                    }
                }

                $result = [
                    'ok' => $satOk && $wedOk && $undoOk,
                    'sat_ok' => $satOk,
                    'wed_ok' => $wedOk,
                    'undo_ok' => $undoOk,
                    'exception_preserved' => $exId
                        ? substr((string) ClassSession::find($exId)->StartTime, 0, 5) === '14:00'
                        : null,
                ];
                $this->line('LEAVE_PROBE_JSON ' . json_encode($result, JSON_UNESCAPED_UNICODE));
                throw new CloseoutFixtureRollback($result);
            });
        } catch (CloseoutFixtureRollback $e) {
            $this->cleanup($ids);

            return $e->result;
        } catch (\Throwable $e) {
            $this->cleanup($ids);
            $this->error($e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array<string,mixed> */
    private function probeMakeup(): array
    {
        $this->info('--- makeup fixture probe ---');
        $ids = [];
        try {
            return DB::transaction(function () use (&$ids) {
                [$studentId, $courseId] = $this->seedCourse(false);
                $ids = compact('studentId', 'courseId');
                $date = Carbon::yesterday()->toDateString();
                $campusId = (int) DB::table('Student')->where('id', $studentId)->value('CampusID');
                $teacherId = (int) DB::table('StudentClass')->where('ID', $courseId)->value('TeacherID');
                DB::table('schedules')->insert([
                    'student_id' => $studentId,
                    'teacher_id' => $teacherId,
                    'subject' => 'Math',
                    'day_of_week' => Carbon::parse($date)->dayOfWeekIso,
                    'start_time' => '14:00:00',
                    'end_time' => '17:00:00',
                    'class_type' => 'one_on_one',
                    'status' => 'scheduled',
                    'type' => 'extra',
                    'deduction' => 1,
                    'branch_id' => $campusId,
                    'schedule_date' => $date,
                    'student_course_id' => $courseId,
                    'original_schedule_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sid = $this->insertSession($courseId, $date, '14:00:00', '17:00:00');
                $sc = StudentClass::findOrFail($courseId);
                $charge0 = (int) $sc->Charge;

                $first = SessionDeductionService::deductOnAttendance($sc, null, $sid);
                $sc->refresh();
                $second = SessionDeductionService::deductOnAttendance($sc, null, $sid);
                $mins = (int) SessionDeductionLedger::where('student_class_id', $courseId)
                    ->where('class_session_id', $sid)->where('event_type', 'deduct')->value('minutes');
                $after = StudentClass::findOrFail($courseId);

                $rev = SessionDeductionService::reverseForSession($courseId, $sid, 'status_adjust');
                $rev2 = SessionDeductionService::reverseForSession($courseId, $sid, 'status_adjust');
                $restored = StudentClass::findOrFail($courseId);
                $third = SessionDeductionService::deductOnAttendance($restored, null, $sid);

                $nid = $this->insertSession($courseId, Carbon::yesterday()->subDay()->toDateString(), '14:00:00', '17:00:00');
                $final = StudentClass::findOrFail($courseId);
                $normal = SessionDeductionService::deductOnAttendance($final, null, $nid);
                $nMins = SessionDeductionLedger::where('student_class_id', $courseId)
                    ->where('class_session_id', $nid)->where('event_type', 'deduct')->value('minutes');

                $hasMin = Schema::hasColumn('StudentClass', 'RemainingMinutes');
                $result = [
                    'ok' => $first && !$second && $mins === 180
                        && ($hasMin ? (int) $after->RemainingMinutes === 300 : (int) $after->RemainingSessions === 3)
                        && $rev && !$rev2
                        && ($hasMin ? (int) $restored->RemainingMinutes === 480 : (int) $restored->RemainingSessions === 4)
                        && $third && $normal && $nMins === null
                        && (int) $final->Charge === $charge0,
                    'ledger_minutes' => $mins,
                    'second_idempotent' => !$second,
                    'reverse_idempotent' => !$rev2,
                    'normal_longer_whole' => $nMins === null,
                    'charge_unchanged' => (int) $final->Charge === $charge0,
                    'deduct_rows' => (int) SessionDeductionLedger::where('student_class_id', $courseId)->where('event_type', 'deduct')->count(),
                    'reverse_rows' => (int) SessionDeductionLedger::where('student_class_id', $courseId)->where('event_type', 'reverse')->count(),
                ];
                $this->line('MAKEUP_PROBE_JSON ' . json_encode($result, JSON_UNESCAPED_UNICODE));
                throw new CloseoutFixtureRollback($result);
            });
        } catch (CloseoutFixtureRollback $e) {
            $this->cleanup($ids);

            return $e->result;
        } catch (\Throwable $e) {
            $this->cleanup($ids);
            $this->error($e->getMessage());

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return array{0:int,1:int} */
    private function seedCourse(bool $multiWeekday): array
    {
        $campusId = (int) (DB::table('Campus')->orderBy('id')->value('id') ?? 1);
        $teacherId = (int) (DB::table('User')->where('type', 'T')->orderBy('id')->value('id') ?? 0);
        if ($teacherId <= 0) {
            throw new \RuntimeException('no teacher');
        }
        $studentId = (int) DB::table('Student')->insertGetId([
            'name' => self::MARK . ($multiWeekday ? ' leave' : ' makeup'),
            'CampusID' => $campusId,
            'ClassID' => 1,
            'SchoolName' => 'Closeout',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $payload = [
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => $teacherId,
            'by1' => 1,
            'Period' => 4,
            'TotalHours' => 0,
            'Charge' => 5000,
            'Pay' => 5000,
            'Paid' => 0,
            'Rate' => 500,
            'ClassType' => 'one_on_one',
            'StartDate' => '2026-07-01',
            'EndDate' => Carbon::now()->addMonths(3)->toDateString(),
            'SessionCount' => 4,
            'SessionDuration' => 120,
            'RemainingSessions' => 4,
            'UsedSessions' => 0,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ];
        if ($multiWeekday) {
            $payload['week'] = 3;
            $payload['time'] = '17:00:00';
            $payload['week1'] = 6;
            $payload['time1'] = '10:00:00';
            if (Schema::hasColumn('StudentClass', 'duration1')) {
                $payload['duration1'] = 120;
            }
            $payload['SessionCount'] = 8;
            $payload['RemainingSessions'] = 6;
        }
        if (Schema::hasColumn('StudentClass', 'PurchasedMinutes')) {
            $payload['PurchasedMinutes'] = ((int) $payload['SessionCount']) * 120;
            $payload['RemainingMinutes'] = $payload['PurchasedMinutes'];
        }

        return [$studentId, (int) DB::table('StudentClass')->insertGetId($payload)];
    }

    private function insertSession(int $courseId, string $date, string $start, string $end, bool $exception = false): int
    {
        $row = [
            'StudentClassID' => $courseId,
            'SessionDate' => $date,
            'StartTime' => $start,
            'EndTime' => $end,
            'Status' => 'scheduled',
            'Note' => self::MARK,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        if ($exception && Schema::hasColumn('ClassSession', 'IsContractException')) {
            $row['IsContractException'] = 1;
        }

        return (int) DB::table('ClassSession')->insertGetId($row);
    }

    /** @param  array<string,mixed>  $ids */
    private function cleanup(array $ids): void
    {
        try {
            $studentIds = DB::table('Student')->where('name', 'like', self::MARK . '%')->pluck('id');
            if (!empty($ids['studentId'])) {
                $studentIds = $studentIds->merge([(int) $ids['studentId']])->unique();
            }
            if ($studentIds->isEmpty()) {
                return;
            }
            $courseIds = DB::table('StudentClass')->whereIn('StudentID', $studentIds)->pluck('ID');
            if ($courseIds->isNotEmpty()) {
                DB::table('ClassSession')->whereIn('StudentClassID', $courseIds)->delete();
                DB::table('session_deduction_ledger')->whereIn('student_class_id', $courseIds)->delete();
                DB::table('schedules')->whereIn('student_course_id', $courseIds)->delete();
                DB::table('StudentClass')->whereIn('ID', $courseIds)->delete();
            }
            DB::table('Student')->whereIn('id', $studentIds)->delete();
        } catch (\Throwable $e) {
            $this->warn('cleanup: ' . $e->getMessage());
        }
    }
}

/** @internal */
class CloseoutFixtureRollback extends \RuntimeException
{
    /** @param  array<string,mixed>  $result */
    public function __construct(public array $result)
    {
        parent::__construct('closeout-fixture-rollback');
    }
}
