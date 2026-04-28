<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Recover historical teacher sign-ins that were swallowed by a pre-fix RFID collision.
 *
 * Safety model:
 * - dry-run by default;
 * - --apply requires --teacher-id;
 * - only inserts TeacherSingIn rows, never deletes or voids StudentSingIn rows.
 */
class RecoverTeacherRfidCollisionSignIns extends Command
{
    protected $signature = 'teacher-signin:recover-rfid-collisions
        {--date= : Local date to inspect, format YYYY-MM-DD}
        {--teacher-id= : Required with --apply; limits recovery to one teacher}
        {--campus-id= : Optional campus filter}
        {--limit=50 : Maximum candidate rows to inspect}
        {--apply : Insert recovered TeacherSingIn rows}';

    protected $description = 'Dry-run or recover TeacherSingIn rows from historical teacher/student RFID collisions';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: now()->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be YYYY-MM-DD');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $teacherId = $this->option('teacher-id') ? (int) $this->option('teacher-id') : null;
        $campusId = $this->option('campus-id') ? (int) $this->option('campus-id') : null;
        $limit = max(1, min(500, (int) ($this->option('limit') ?: 50)));

        if ($apply && ! $teacherId) {
            $this->error('--apply requires --teacher-id to avoid broad production writes.');
            return self::FAILURE;
        }

        $candidates = $this->candidateRows($date, $teacherId, $campusId, $limit);
        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . " teacher RFID collision recovery for {$date}");
        $this->info("Candidates: {$candidates->count()}");

        if ($candidates->isEmpty()) {
            return self::SUCCESS;
        }

        $rows = $candidates->map(fn ($row) => [
            'student_signin_id' => $row->student_signin_id,
            'teacher_id' => $row->teacher_id,
            'teacher_name' => $row->teacher_name,
            'campus_id' => $row->campus_id,
            'student_id' => $row->student_id,
            'student_name' => $row->student_name,
            'sign_in_dt' => $row->sign_in_dt,
            'sign_out_dt' => $row->sign_out_dt ?? '',
            'rfid' => $row->rfid,
        ])->all();
        $this->table(array_keys($rows[0]), array_map('array_values', $rows));

        if (! $apply) {
            $this->warn('Dry-run only. Re-run with --apply --teacher-id=<id> after confirming the candidate row.');
            return self::SUCCESS;
        }

        $inserted = 0;
        DB::transaction(function () use ($candidates, $date, &$inserted) {
            foreach ($candidates as $row) {
                if ($this->teacherAlreadyHasRecord((int) $row->teacher_id, $date)) {
                    continue;
                }

                $data = [
                    'TeacherID' => (int) $row->teacher_id,
                    'CampusID' => (int) $row->campus_id,
                    'SignInDT' => $row->sign_in_dt,
                    'SignOutDT' => $row->sign_out_dt,
                    'MDT' => now()->toDateTimeString(),
                    'Source' => 'rfid',
                    'Status' => $this->resolveStatus((int) $row->teacher_id, (string) $row->sign_in_dt),
                ];

                if (Schema::hasColumn('TeacherSingIn', 'Memo')) {
                    $data['Memo'] = mb_substr("Recovered from StudentSingIn #{$row->student_signin_id} RFID collision", 0, 255);
                }

                DB::table('TeacherSingIn')->insert($data);
                $inserted++;
            }
        });

        $this->info("Inserted {$inserted} TeacherSingIn record(s).");
        return self::SUCCESS;
    }

    private function candidateRows(string $date, ?int $teacherId, ?int $campusId, int $limit)
    {
        $query = DB::table('StudentSingIn as si')
            ->join('Student as s', 's.id', '=', 'si.StudentID')
            ->join('UserCampus as uc', function ($join) {
                $join->on('uc.CampusID', '=', 'si.CampusID')
                    ->on('uc.RFID', '=', 's.RFID');
            })
            ->join('Teacher as t', function ($join) {
                $join->on('t.id', '=', 'uc.UserID')
                    ->where('t.Enable', '=', 1);
            })
            ->leftJoin('User as u', 'u.id', '=', 'uc.UserID')
            ->select([
                'si.id as student_signin_id',
                'si.StudentID as student_id',
                's.name as student_name',
                's.RFID as rfid',
                'si.CampusID as campus_id',
                'si.SignInDT as sign_in_dt',
                'si.SignOutDT as sign_out_dt',
                'uc.UserID as teacher_id',
                DB::raw("COALESCE(t.T_Name, u.Name, '') as teacher_name"),
            ])
            ->whereDate('si.SignInDT', $date)
            ->whereNull('si.VoidedAt')
            ->whereNotNull('s.RFID')
            ->where('s.RFID', '!=', '')
            ->where(function ($q) {
                $q->where('uc.Approved', true)->orWhereNull('uc.Approved');
            })
            ->whereNotExists(function ($sub) use ($date) {
                $sub->select(DB::raw(1))
                    ->from('TeacherSingIn as ts')
                    ->whereColumn('ts.TeacherID', 'uc.UserID')
                    ->whereDate('ts.SignInDT', $date);
            })
            ->orderBy('si.SignInDT')
            ->limit($limit);

        if ($teacherId) {
            $query->where('uc.UserID', $teacherId);
        }
        if ($campusId) {
            $query->where('si.CampusID', $campusId);
        }

        return $query->get();
    }

    private function teacherAlreadyHasRecord(int $teacherId, string $date): bool
    {
        return DB::table('TeacherSingIn')
            ->where('TeacherID', $teacherId)
            ->whereDate('SignInDT', $date)
            ->exists();
    }

    private function resolveStatus(int $teacherId, string $signInDt): string
    {
        $swipeAt = Carbon::parse($signInDt);
        $date = $swipeAt->toDateString();

        $firstClass = DB::table('schedules')
            ->where('teacher_id', $teacherId)
            ->where('schedule_date', $date)
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->first();

        if (! $firstClass) {
            return 'source_only';
        }

        $threshold = Carbon::parse("{$date} {$firstClass->start_time}")->addMinutes(10);
        return $swipeAt->lte($threshold) ? 'normal' : 'late';
    }
}
