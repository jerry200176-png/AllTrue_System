<?php

/**
 * Bounded, read-only production attendance case probe.
 * Inputs: CAMPUS_ID, DATE_FROM, DATE_TO, optional STUDENT_NAME.
 * This file is transferred by the diagnostic workflow and never writes data.
 */

use Illuminate\Support\Facades\DB;

$campusId = (int) (getenv('CAMPUS_ID') ?: 0);
$from = (string) (getenv('DATE_FROM') ?: '');
$to = (string) (getenv('DATE_TO') ?: '');
$studentName = trim((string) (getenv('STUDENT_NAME') ?: ''));

if ($campusId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    throw new InvalidArgumentException('CAMPUS_ID, DATE_FROM and DATE_TO are required');
}
$fromDate = new DateTimeImmutable($from);
$toDate = new DateTimeImmutable($to);
if ($toDate < $fromDate || $fromDate->diff($toDate)->days > 31) {
    throw new InvalidArgumentException('date range must be ordered and no longer than 31 days');
}

$studentsQuery = DB::table('Student')->where('CampusID', $campusId);
if ($studentName !== '') {
    $studentsQuery->where('name', $studentName);
}
$students = $studentsQuery->orderBy('id')->get(['id', 'name', 'CampusID']);
$studentIds = $students->pluck('id')->map(fn ($id) => (int) $id)->all();

$classes = $studentIds === [] ? collect() : DB::table('StudentClass as sc')
    ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
    ->leftJoin('User as teacher', 'teacher.id', '=', 'sc.TeacherID')
    ->whereIn('sc.StudentID', $studentIds)
    ->orderBy('sc.ID')
    ->get([
        'sc.ID as class_id', 'sc.StudentID as student_id', 'sc.TeacherID as teacher_id',
        'teacher.Name as teacher_name', 'sc.SubjectID as subject_id', 'sub.Subject_Name as subject_name',
        'sc.Stop as stop', 'sc.ScheduleMode as schedule_mode', 'sc.SessionCount as session_count',
        'sc.UsedSessions as used_sessions', 'sc.RemainingSessions as remaining_sessions',
        'sc.Charge as charge', 'sc.Rate as rate', 'sc.StartDate as start_date', 'sc.EndDate as end_date',
        'sc.week', 'sc.time', 'sc.week1', 'sc.time1', 'sc.duration1', 'sc.week2', 'sc.time2', 'sc.duration2',
        'sc.week3', 'sc.time3', 'sc.duration3', 'sc.week4', 'sc.time4', 'sc.duration4',
        'sc.week5', 'sc.time5', 'sc.duration5', 'sc.week6', 'sc.time6', 'sc.duration6',
        'sc.MDate as modified_at',
    ]);
$classIds = $classes->pluck('class_id')->map(fn ($id) => (int) $id)->all();

$sessions = $classIds === [] ? collect() : DB::table('ClassSession as cs')
    ->join('StudentClass as sc', 'sc.ID', '=', 'cs.StudentClassID')
    ->leftJoin('Subject as sub', 'sub.id', '=', 'sc.SubjectID')
    ->whereIn('cs.StudentClassID', $classIds)
    ->whereBetween('cs.SessionDate', [$from, $to])
    ->orderBy('cs.SessionDate')->orderBy('cs.StartTime')->orderBy('cs.id')
    ->get([
        'cs.id as session_id', 'cs.StudentClassID as class_id', 'sc.StudentID as student_id',
        'sub.Subject_Name as subject_name', 'cs.SessionDate as session_date', 'cs.StartTime as start_time',
        'cs.EndTime as end_time', 'cs.Status as status', 'cs.Note as note',
        'cs.IsContractException as is_contract_exception', 'cs.session_charge as session_charge',
        'cs.created_at', 'cs.updated_at',
    ]);
$sessionIds = $sessions->pluck('session_id')->map(fn ($id) => (int) $id)->all();

$audit = $sessionIds === [] ? collect() : DB::table('schedule_audit_logs as sal')
    ->leftJoin('User as u', 'u.id', '=', 'sal.operator_id')
    ->whereIn('sal.session_id', $sessionIds)
    ->orderBy('sal.created_at')->orderBy('sal.id')
    ->get([
        'sal.id as audit_id', 'sal.session_id', 'sal.action_type', 'sal.description',
        'sal.operator_id', 'u.Name as operator_name', 'sal.branch_id', 'sal.old_data', 'sal.new_data',
        'sal.created_at',
    ]);

$learningRecords = $sessionIds === [] ? collect() : DB::table('LearningRecord as lr')
    ->leftJoin('User as u', 'u.id', '=', 'lr.CreatedByUserID')
    ->whereIn('lr.ClassSessionID', $sessionIds)
    ->orderBy('lr.id')
    ->get([
        'lr.id as learning_record_id', 'lr.ClassSessionID as session_id', 'lr.TeacherID as teacher_id',
        'lr.CreatedByUserID as created_by_user_id', 'u.Name as created_by_name', 'lr.Status as status',
        'lr.VoidedAt as voided_at', 'lr.VoidReason as void_reason', 'lr.created_at', 'lr.updated_at',
    ]);

$signIns = $sessionIds === [] ? collect() : DB::table('StudentSingIn as si')
    ->leftJoin('User as u', 'u.id', '=', 'si.RecordedByUserID')
    ->whereIn('si.ClassSessionID', $sessionIds)
    ->orderBy('si.id')
    ->get([
        'si.id as sign_in_id', 'si.ClassSessionID as session_id', 'si.StudentClassID as class_id',
        'si.TeacherID as teacher_id', 'si.RecordedByUserID as recorded_by_user_id',
        'u.Name as recorded_by_name', 'si.Status as status', 'si.SessionDeducted as session_deducted',
        'si.SignInDT as sign_in_at', 'si.VoidedAt as voided_at', 'si.VoidReason as void_reason',
    ]);

$ledger = $sessionIds === [] ? collect() : DB::table('session_deduction_ledger')
    ->whereIn('class_session_id', $sessionIds)
    ->orderBy('id')
    ->get([
        'id as ledger_id', 'student_class_id as class_id', 'class_session_id as session_id',
        'event_type', 'source', 'minutes', 'created_at',
    ]);

$schedules = $classIds === [] ? collect() : DB::table('schedules')
    ->whereIn('student_course_id', $classIds)
    ->whereBetween('schedule_date', [$from, $to])
    ->orderBy('schedule_date')->orderBy('start_time')->orderBy('id')
    ->get([
        'id as schedule_id', 'student_course_id as class_id', 'schedule_date as session_date',
        'start_time', 'end_time', 'status', 'type', 'original_schedule_id', 'teacher_id', 'created_at',
    ]);

$normalise = static function ($row): array {
    $item = (array) $row;
    foreach (['session_id', 'class_id', 'student_id', 'audit_id', 'operator_id', 'learning_record_id',
        'created_by_user_id', 'sign_in_id', 'recorded_by_user_id', 'ledger_id', 'schedule_id',
        'teacher_id', 'subject_id', 'session_count', 'used_sessions', 'remaining_sessions'] as $key) {
        if (array_key_exists($key, $item) && $item[$key] !== null) {
            $item[$key] = (int) $item[$key];
        }
    }
    return $item;
};

$out = [
    'ok' => true,
    'read_only' => true,
    'campus_id' => $campusId,
    'date_from' => $from,
    'date_to' => $to,
    'student_name_filter' => $studentName !== '' ? $studentName : null,
    'students' => $students->map($normalise)->values()->all(),
    'student_classes' => $classes->map($normalise)->values()->all(),
    'class_sessions' => $sessions->map($normalise)->values()->all(),
    'schedule_audit_logs' => $audit->map($normalise)->values()->all(),
    'learning_records' => $learningRecords->map($normalise)->values()->all(),
    'student_sign_ins' => $signIns->map($normalise)->values()->all(),
    'session_deduction_ledger' => $ledger->map($normalise)->values()->all(),
    'schedules' => $schedules->map($normalise)->values()->all(),
    'generated_at' => gmdate('c'),
];

// Keep the workflow transport one-line so the runner can parse it without
// accidentally treating a pretty-printed nested object as a new document.
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
