<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseTeacherSignIn extends Command
{
    protected $signature = 'teacher-signin:diagnose
        {--date= : Local date to inspect, format YYYY-MM-DD}
        {--teacher-id= : Optional teacher User.id}
        {--teacher-name= : Optional teacher name keyword}
        {--login-name= : Optional User.LoginName exact match}
        {--campus-id= : Optional campus id filter}';

    protected $description = 'Read-only diagnostic report for missing teacher sign-in records';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: now()->toDateString());
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be YYYY-MM-DD');
            return self::FAILURE;
        }

        $teacherId = $this->option('teacher-id') ? (int) $this->option('teacher-id') : null;
        $teacherName = trim((string) ($this->option('teacher-name') ?: ''));
        $loginName = trim((string) ($this->option('login-name') ?: ''));
        $campusId = $this->option('campus-id') ? (int) $this->option('campus-id') : null;

        if (! $teacherId && $teacherName === '' && $loginName === '') {
            $this->error('Provide --teacher-id, --teacher-name, or --login-name.');
            return self::FAILURE;
        }

        $teachers = $this->findTeachers($teacherId, $teacherName, $loginName, $campusId);
        $this->info("Read-only teacher sign-in diagnostic for {$date}");
        $this->info("Teachers matched: {$teachers->count()}");

        if ($teachers->isEmpty()) {
            if ($loginName !== '') {
                $this->printLoginFallback($loginName);
            }
            return self::SUCCESS;
        }

        $this->table(['teacher_id', 'teacher_name', 'user_name', 'teacher_campus_id', 'user_campus_id', 'approved', 'admin', 'user_status', 'uc_rfid', 'legacy_rfid'], $teachers->map(function ($row) {
            return [
                $row->teacher_id,
                $row->teacher_name,
                $row->user_name,
                $row->teacher_campus_id,
                $row->user_campus_id,
                $row->approved,
                $row->admin,
                $row->user_status,
                $this->fingerprint($row->uc_rfid),
                $this->fingerprint($row->legacy_rfid),
            ];
        })->all());

        foreach ($teachers as $teacher) {
            $this->line('');
            $this->info("Teacher {$teacher->teacher_id} / {$teacher->teacher_name}");
            $this->printTeacherSignIns((int) $teacher->teacher_id, $date);
            $this->printRelatedRfidRows($teacher, $date);
        }

        return self::SUCCESS;
    }

    private function findTeachers(?int $teacherId, string $teacherName, string $loginName, ?int $campusId)
    {
        $query = DB::table('Teacher as t')
            ->leftJoin('User as u', 'u.id', '=', 't.id')
            ->leftJoin('UserCampus as uc', 'uc.UserID', '=', 't.id')
            ->leftJoin('Campus as c', 'c.id', '=', 'uc.CampusID')
            ->select([
                't.id as teacher_id',
                DB::raw("COALESCE(t.T_Name, '') as teacher_name"),
                DB::raw("COALESCE(u.Name, '') as user_name"),
                't.CampusID as teacher_campus_id',
                'uc.CampusID as user_campus_id',
                'uc.Approved as approved',
                'uc.Admin as admin',
                'u.status as user_status',
                'uc.RFID as uc_rfid',
                't.RFID as legacy_rfid',
                'c.name as campus_name',
            ])
            ->where('t.Enable', 1);

        if ($teacherId) {
            $query->where('t.id', $teacherId);
        }
        if ($teacherName !== '') {
            $query->where(function ($q) use ($teacherName) {
                $q->where('t.T_Name', 'like', "%{$teacherName}%")
                    ->orWhere('u.Name', 'like', "%{$teacherName}%");
            });
        }
        if ($loginName !== '') {
            $query->where('u.LoginName', $loginName);
        }
        if ($campusId) {
            $query->where(function ($q) use ($campusId) {
                $q->where('t.CampusID', $campusId)->orWhere('uc.CampusID', $campusId);
            });
        }

        return $query->orderBy('t.id')->limit(20)->get();
    }

    private function printTeacherSignIns(int $teacherId, string $date): void
    {
        $rows = DB::table('TeacherSingIn')
            ->where('TeacherID', $teacherId)
            ->whereDate('SignInDT', $date)
            ->orderBy('SignInDT')
            ->get(['id', 'TeacherID', 'CampusID', 'SignInDT', 'SignOutDT', 'Source', 'Status']);

        $this->info("TeacherSingIn rows: {$rows->count()}");
        if ($rows->isNotEmpty()) {
            $this->table(['id', 'teacher_id', 'campus_id', 'sign_in_dt', 'sign_out_dt', 'source', 'status'], $rows->map(fn ($row) => [
                $row->id,
                $row->TeacherID,
                $row->CampusID,
                $row->SignInDT,
                $row->SignOutDT,
                $row->Source,
                $row->Status,
            ])->all());
        }
    }

    private function printLoginFallback(string $loginName): void
    {
        $users = DB::table('User as u')
            ->leftJoin('UserCampus as uc', 'uc.UserID', '=', 'u.id')
            ->leftJoin('Campus as c', 'c.id', '=', 'uc.CampusID')
            ->leftJoin('Teacher as t', 't.id', '=', 'u.id')
            ->where('u.LoginName', $loginName)
            ->orderBy('u.id')
            ->get([
                'u.id as user_id',
                'u.Name as user_name',
                'u.type as user_type',
                'u.status as user_status',
                'uc.CampusID as user_campus_id',
                'uc.Approved as approved',
                'uc.Admin as admin',
                'uc.RFID as uc_rfid',
                'c.name as campus_name',
                't.id as teacher_id',
                't.T_Name as teacher_name',
                't.CampusID as teacher_campus_id',
                't.Enable as teacher_enable',
                't.RFID as legacy_rfid',
            ]);

        $this->info("Login fallback User/UserCampus/Teacher rows: {$users->count()}");
        if ($users->isEmpty()) {
            return;
        }

        $this->table(['user_id', 'user_name', 'user_type', 'user_status', 'user_campus_id', 'campus_name', 'approved', 'admin', 'uc_rfid', 'teacher_id', 'teacher_name', 'teacher_campus_id', 'teacher_enable', 'legacy_rfid'], $users->map(fn ($row) => [
            $row->user_id,
            $row->user_name,
            $row->user_type,
            $row->user_status,
            $row->user_campus_id,
            $row->campus_name,
            $row->approved,
            $row->admin,
            $this->fingerprint($row->uc_rfid),
            $row->teacher_id,
            $row->teacher_name,
            $row->teacher_campus_id,
            $row->teacher_enable,
            $this->fingerprint($row->legacy_rfid),
        ])->all());
    }

    private function printRelatedRfidRows(object $teacher, string $date): void
    {
        $rfids = collect([$teacher->uc_rfid, $teacher->legacy_rfid])
            ->filter(fn ($rfid) => is_string($rfid) && trim($rfid) !== '')
            ->map(fn ($rfid) => trim($rfid))
            ->unique()
            ->values();

        if ($rfids->isEmpty()) {
            $this->warn('No RFID is currently attached to this teacher.');
            return;
        }

        foreach ($rfids as $rfid) {
            $this->line('RFID fingerprint: ' . $this->fingerprint($rfid));
            $studentRows = DB::table('Student as s')
                ->leftJoin('StudentSingIn as si', function ($join) use ($date) {
                    $join->on('si.StudentID', '=', 's.id')->whereDate('si.SignInDT', $date);
                })
                ->where('s.RFID', $rfid)
                ->orderBy('s.id')
                ->get([
                    's.id as student_id',
                    's.name as student_name',
                    's.CampusID as student_campus_id',
                    's.enable',
                    'si.id as student_signin_id',
                    'si.CampusID as signin_campus_id',
                    'si.SignInDT as sign_in_dt',
                    'si.SignOutDT as sign_out_dt',
                    'si.Memo as memo',
                    'si.Status as status',
                    'si.VoidedAt as voided_at',
                ]);

            $this->info("Students / StudentSingIn with same RFID: {$studentRows->count()}");
            if ($studentRows->isNotEmpty()) {
                $this->table(['student_id', 'student_name', 'student_campus_id', 'enable', 'student_signin_id', 'signin_campus_id', 'sign_in_dt', 'sign_out_dt', 'memo', 'status', 'voided_at'], $studentRows->map(fn ($row) => [
                    $row->student_id,
                    $row->student_name,
                    $row->student_campus_id,
                    $row->enable,
                    $row->student_signin_id,
                    $row->signin_campus_id,
                    $row->sign_in_dt,
                    $row->sign_out_dt,
                    $row->memo,
                    $row->status,
                    $row->voided_at,
                ])->all());
            }

            if (Schema::hasTable('TempRfid')) {
                $tempRows = DB::table('TempRfid')
                    ->where('RFID', $rfid)
                    ->orderBy('created_at', 'desc')
                    ->get(['id', 'CampusID', 'created_at']);

                $this->info("TempRfid rows with same RFID: {$tempRows->count()}");
                if ($tempRows->isNotEmpty()) {
                    $this->table(['id', 'campus_id', 'created_at'], $tempRows->map(fn ($row) => [
                        $row->id,
                        $row->CampusID,
                        $row->created_at,
                    ])->all());
                }
            }
        }
    }

    private function fingerprint(?string $rfid): string
    {
        $value = trim((string) $rfid);
        if ($value === '') {
            return '';
        }

        return substr(hash('sha256', $value), 0, 12);
    }
}
