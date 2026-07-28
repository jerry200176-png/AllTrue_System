<?php
/**
 * One-shot containment: 木柵・高瑞樸・楊智超・數學・2026-07-30 19:00–21:00
 * Hardcoded targets only. Modes: dry-run | apply | verify
 */
declare(strict_types=1);
const CASE_ID = 'muzha-gaorui-2026-07-30';
const TARGET_DATE = '2026-07-30';
const START_HM = '19:00';
const END_HM = '21:00';
const ISO_WEEKDAY = 4;
const NOTE = 'incident-repair-muzha-gaorui-2026-07-30';
const CONFIRM_PHRASE = 'I_APPROVE_MUZHA_GAORUI_2026_07_30_CONTAINMENT';
const BILLING_FIELDS = [
    'Rate', 'Charge', 'Paid', 'settlement_day', 'monthly_sessions',
    'TeacherID', 'StudentID', 'SubjectID', 'ScheduleMode', 'StartDate',
];
$opts = getopt('', ['mode:', 'confirm::', 'actor::', 'workflow-sha::', 'script-sha256::', 'backend-root::']);
$mode = (string) ($opts['mode'] ?? '');
$confirm = (string) ($opts['confirm'] ?? '');
$actor = (string) ($opts['actor'] ?? 'gha:muzha-gaorui-2026-07-30');
$workflowSha = (string) ($opts['workflow-sha'] ?? '');
$scriptSha = (string) ($opts['script-sha256'] ?? '');
$backendRoot = (string) ($opts['backend-root'] ?? getenv('BACKEND_ROOT') ?: '/home/admin/backend');
$evidence = baseEvidence($mode, $workflowSha, $scriptSha, $actor);
try {
    if (!in_array($mode, ['dry-run', 'apply', 'verify'], true)) {
        abortCode($evidence, 'ABORT_INVALID_MODE');
    }
    if (!is_file($backendRoot . '/bootstrap/app.php')) {
        abortCode($evidence, 'ABORT_BACKEND_ROOT_MISSING');
    }
    chdir($backendRoot);
    require $backendRoot . '/vendor/autoload.php';
    $app = require $backendRoot . '/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $pre = runPreflight($evidence);
    $evidence = $pre['evidence'];
    if ($pre['phase'] === 'ALREADY_CONTAINED') {
        $evidence['phase'] = 'ALREADY_CONTAINED';
        $evidence['write']['noop'] = true;
        $evidence['ok_unique_scheduled'] = true;
        emit($evidence);
        exit(0);
    }
    if ($mode === 'dry-run') {
        $evidence['phase'] = 'READY_TO_APPLY';
        $evidence['write']['noop'] = true;
        emit($evidence);
        exit(0);
    }
    if ($mode === 'verify') {
        $ok = (bool) ($evidence['ok_unique_scheduled'] ?? false);
        $evidence['phase'] = $ok ? 'ALREADY_CONTAINED' : 'VERIFY_FAILED';
        $evidence['write']['noop'] = true;
        emit($evidence);
        exit($ok ? 0 : 1);
    }
    // apply
    if ($confirm !== CONFIRM_PHRASE) {
        abortCode($evidence, 'ABORT_CONFIRM_MISMATCH');
    }
    if ((string) (getenv('ALLOW_PROD_REPAIR') ?: ($_ENV['ALLOW_PROD_REPAIR'] ?? '')) !== '1') {
        abortCode($evidence, 'ABORT_ALLOW_PROD_REPAIR_REQUIRED');
    }
    if ((string) app()->environment() !== 'production') {
        abortCode($evidence, 'ABORT_APP_ENV_NOT_PRODUCTION');
    }
    $pre = runPreflight($evidence);
    $evidence = $pre['evidence'];
    if ($pre['phase'] === 'ALREADY_CONTAINED') {
        $evidence['phase'] = 'ALREADY_CONTAINED';
        $evidence['write']['noop'] = true;
        emit($evidence);
        exit(0);
    }
    if ($pre['phase'] !== 'READY_TO_APPLY') {
        abortCode($evidence, 'ABORT_PREFLIGHT_NOT_READY');
    }
    $evidence['backup'] = createScopedBackup($backendRoot);
    if (!$evidence['backup']['verified']) {
        abortCode($evidence, 'ABORT_BACKUP_UNVERIFIED');
    }
    $ctx = $pre['ctx'];
    $mut = applyContainmentInTransaction($ctx);
    $evidence['write'] = $mut['write'];
    $evidence['rollback_evidence'] = $mut['rollback_evidence'];
    $after = loadAfterState((int) $ctx['student_class_id'], (int) $ctx['student_id'], (int) $ctx['teacher_id'], $ctx['billing_before']);
    $evidence['after'] = $after['after'];
    $evidence['billing_fields_unchanged'] = $after['billing_fields_unchanged'];
    $evidence['ok_unique_scheduled'] = $after['ok_unique_scheduled'];
    if (!$after['ok_unique_scheduled'] || !$after['billing_fields_unchanged']) {
        $evidence['phase'] = 'APPLIED_POSTVERIFY_FAILED';
        $evidence['aborts'][] = 'DO NOT RERUN APPLY BLINDLY — mutation may already be committed';
        emit($evidence);
        fwrite(STDERR, "DO NOT RERUN APPLY BLINDLY\n");
        exit(42);
    }
    $evidence['phase'] = 'CONTAINED';
    emit($evidence);
    exit(0);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    $evidence['phase'] = 'ABORTED';
    if (!in_array($msg, $evidence['aborts'], true)) {
        $evidence['aborts'][] = str_starts_with($msg, 'ABORT_') ? $msg : ('ABORT_UNHANDLED:' . $msg);
    }
    emit($evidence);
    exit(1);
}
/** @return array<string,mixed> */
function baseEvidence(string $mode, string $workflowSha, string $scriptSha, string $actor): array
{
    return [
        'case' => CASE_ID,
        'mode' => $mode,
        'phase' => 'start',
        'workflow_sha' => $workflowSha,
        'script_sha256' => $scriptSha,
        'actor' => $actor,
        'campus_id' => null,
        'student_id' => null,
        'teacher_id' => null,
        'student_class_id' => null,
        'before' => ['end_date' => null, 'same_slot_sessions' => [], 'billing' => null],
        'backup' => ['created' => false, 'path' => null, 'verified' => false],
        'write' => ['noop' => null, 'path' => null, 'end_date_extended' => false, 'session_id' => null],
        'after' => ['end_date' => null, 'active_count' => null, 'session' => null, 'billing' => null],
        'billing_fields_unchanged' => null,
        'ok_unique_scheduled' => null,
        'aborts' => [],
        'rollback_evidence' => null,
    ];
}
/**
 * @param  array<string,mixed>  $evidence
 * @return array{phase:string,evidence:array<string,mixed>,ctx:?array<string,mixed>}
 */
function runPreflight(array $evidence): array
{
    $campusIds = App\Models\Campus::query()->where('name', 'like', '%木柵%')->pluck('id')->map(fn ($id) => (int) $id)->all();
    if (count($campusIds) !== 1) {
        abortCode($evidence, 'ABORT_CAMPUS_NOT_UNIQUE:count=' . count($campusIds));
    }
    $campusId = $campusIds[0];
    $evidence['campus_id'] = $campusId;
    $students = App\Models\Student::query()->where('name', 'like', '%高瑞樸%')->where('CampusID', $campusId)->get(['id', 'name', 'CampusID']);
    if ($students->count() !== 1) {
        abortCode($evidence, 'ABORT_STUDENT_NOT_UNIQUE:count=' . $students->count());
    }
    $student = $students->first();
    $evidence['student_id'] = (int) $student->id;
    $teachers = App\Models\User::query()->where('Name', 'like', '%楊智超%')->get(['id', 'Name', 'type', 'status']);
    if ($teachers->count() !== 1) {
        abortCode($evidence, 'ABORT_TEACHER_NOT_UNIQUE:count=' . $teachers->count());
    }
    $teacher = $teachers->first();
    $evidence['teacher_id'] = (int) $teacher->id;
    $mathIds = Illuminate\Support\Facades\DB::table('BaseData')->where('Name', '課程')
        ->where(fn ($q) => $q->where('Val', 'like', '%數學%')->orWhere('Val', 'like', '%Math%'))
        ->pluck('id')->map(fn ($id) => (int) $id)->all();
    if ($mathIds === [] && class_exists(App\Models\Subject::class)) {
        $mathIds = App\Models\Subject::query()
            ->where(fn ($q) => $q->where('name', 'like', '%數學%')->orWhere('name', 'like', '%Math%'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
    if ($mathIds === []) {
        abortCode($evidence, 'ABORT_MATH_SUBJECT_IDS_EMPTY');
    }
    $classes = App\Models\StudentClass::query()
        ->where('StudentID', (int) $student->id)->where('TeacherID', (int) $teacher->id)
        ->where('ScheduleMode', 'date')->whereIn('SubjectID', $mathIds)->orderBy('ID')->get();
    $matches = [];
    foreach ($classes as $cand) {
        if (hasThu1900($cand)) { $matches[] = $cand; }
    }
    if (count($matches) !== 1) {
        abortCode($evidence, 'ABORT_STUDENT_CLASS_NOT_UNIQUE:count=' . count($matches));
    }
    /** @var App\Models\StudentClass $sc */
    $sc = $matches[0];
    $scId = (int) $sc->ID;
    $evidence['student_class_id'] = $scId;
    if ((int) ($sc->Stop ?? 0) === 1) {
        abortCode($evidence, 'ABORT_COURSE_STOPPED');
    }
    if ($sc->isUsageSettlementLocked()) {
        abortCode($evidence, 'ABORT_SETTLEMENT_LOCKED');
    }
    $billingBefore = snapshotBilling($sc);
    $endDate = $sc->EndDate ? substr((string) $sc->EndDate, 0, 10) : null;
    $evidence['before']['end_date'] = $endDate;
    $evidence['before']['billing'] = $billingBefore;
    $existingCs = App\Models\ClassSession::query()
        ->where('StudentClassID', $scId)->whereDate('SessionDate', TARGET_DATE)
        ->whereRaw('SUBSTRING(StartTime,1,5) = ?', [START_HM])->orderBy('id')
        ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'Note', 'SubjectID']);
    $evidence['before']['same_slot_sessions'] = $existingCs->map(fn ($r) => sessionBrief($r))->all();
    $activeSame = $existingCs->filter(fn ($r) => strtolower((string) $r->Status) !== 'cancelled')->values();
    if ($activeSame->count() > 1) {
        abortCode($evidence, 'ABORT_MULTIPLE_ACTIVE_SAME_SLOT');
    }
    if ($activeSame->count() === 1) {
        $one = $activeSame->first();
        if (strtolower((string) $one->Status) === 'scheduled' && substr((string) $one->EndTime, 0, 5) === END_HM) {
            $after = loadAfterState($scId, (int) $student->id, (int) $teacher->id, $billingBefore);
            $evidence['after'] = $after['after'];
            $evidence['billing_fields_unchanged'] = $after['billing_fields_unchanged'];
            $evidence['ok_unique_scheduled'] = $after['ok_unique_scheduled'];
            $evidence['write']['session_id'] = (int) $one->id;
            return ['phase' => 'ALREADY_CONTAINED', 'evidence' => $evidence, 'ctx' => null];
        }
        abortCode($evidence, 'ABORT_EXISTING_NON_SCHEDULED_OR_BAD_END');
    }
    $cancelledSame = $existingCs->filter(fn ($r) => strtolower((string) $r->Status) === 'cancelled')->values();
    if ($cancelledSame->count() > 1) {
        abortCode($evidence, 'ABORT_AMBIGUOUS_CANCELLED_ROWS:count=' . $cancelledSame->count());
    }
    $cancelledId = $cancelledSame->count() === 1 ? (int) $cancelledSame->first()->id : null;
    $schedEx = App\Models\Schedule::query()->where('student_course_id', $scId)->whereDate('schedule_date', TARGET_DATE)->get();
    $badSched = $schedEx->filter(function ($r) {
        $st = strtolower((string) ($r->status ?? ''));
        return in_array($st, ['leave', 'rescheduled', 'cancelled'], true)
            || (substr((string) ($r->start_time ?? ''), 0, 5) === START_HM && $st !== '' && $st !== 'scheduled');
    });
    if ($badSched->isNotEmpty()) {
        abortCode($evidence, 'ABORT_SCHEDULE_EXCEPTION');
    }
    assertNoOverlapConflicts((int) $student->id, (int) $teacher->id, $scId);
    $evidence['ok_unique_scheduled'] = false;
    return [
        'phase' => 'READY_TO_APPLY',
        'evidence' => $evidence,
        'ctx' => [
            'student_class_id' => $scId,
            'student_id' => (int) $student->id,
            'teacher_id' => (int) $teacher->id,
            'billing_before' => $billingBefore,
            'end_date_before' => $endDate,
            'cancelled_session_id' => $cancelledId,
        ],
    ];
}
function hasThu1900(App\Models\StudentClass $sc): bool
{
    foreach ([['week', 'time'], ['week1', 'time1'], ['week2', 'time2'], ['week3', 'time3'], ['week4', 'time4'], ['week5', 'time5'], ['week6', 'time6']] as [$wf, $tf]) {
        if ((int) ($sc->{$wf} ?? 0) === ISO_WEEKDAY && substr((string) ($sc->{$tf} ?? ''), 0, 5) === START_HM) {
            return true;
        }
    }
    return false;
}
/** Overlap: StartTime < END and EndTime > START (not exact StartTime-only). */
function assertNoOverlapConflicts(int $studentId, int $teacherId, int $scId): void
{
    $studentScIds = App\Models\StudentClass::query()->where('StudentID', $studentId)->pluck('ID');
    $n = App\Models\ClassSession::query()->whereIn('StudentClassID', $studentScIds)
        ->whereDate('SessionDate', TARGET_DATE)->whereRaw('LOWER(Status) <> ?', ['cancelled'])
        ->where('StudentClassID', '!=', $scId)
        ->whereRaw('SUBSTRING(StartTime,1,5) < ?', [END_HM])
        ->whereRaw('SUBSTRING(EndTime,1,5) > ?', [START_HM])->count();
    if ($n > 0) {
        throw new RuntimeException('ABORT_STUDENT_SLOT_CONFLICT:count=' . $n);
    }
    $teacherScIds = App\Models\StudentClass::query()->where('TeacherID', $teacherId)->pluck('ID');
    $n = App\Models\ClassSession::query()->whereIn('StudentClassID', $teacherScIds)
        ->whereDate('SessionDate', TARGET_DATE)->whereRaw('LOWER(Status) <> ?', ['cancelled'])
        ->where('StudentClassID', '!=', $scId)
        ->whereRaw('SUBSTRING(StartTime,1,5) < ?', [END_HM])
        ->whereRaw('SUBSTRING(EndTime,1,5) > ?', [START_HM])->count();
    if ($n > 0) {
        throw new RuntimeException('ABORT_TEACHER_SLOT_CONFLICT:count=' . $n);
    }
}
/** @return array{created:bool,path:?string,verified:bool} */
function createScopedBackup(string $backendRoot): array
{
    $path = '/home/admin/backups/emergency/db_pre_muzha_gaorui_730_' . date('Y-m-d_His') . '.sql.gz';
    if (!is_dir('/home/admin/backups/emergency') && !mkdir('/home/admin/backups/emergency', 0750, true) && !is_dir('/home/admin/backups/emergency')) {
        return ['created' => false, 'path' => $path, 'verified' => false];
    }
    $password = '';
    foreach (file($backendRoot . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with($line, 'DB_PASSWORD=')) {
            $password = trim(substr($line, strlen('DB_PASSWORD=')), " \t\"'");
            break;
        }
    }
    $cmd = 'mysqldump -h 127.0.0.1 -u admin -p' . escapeshellarg($password)
        . ' --single-transaction AllTrue StudentClass ClassSession schedules | gzip > ' . escapeshellarg($path);
    $exit = 0;
    system($cmd, $exit);
    if ($exit !== 0 || !is_file($path) || filesize($path) <= 0) {
        return ['created' => false, 'path' => $path, 'verified' => false];
    }
    $gzExit = 0;
    system('gzip -t ' . escapeshellarg($path), $gzExit);
    return ['created' => true, 'path' => $path, 'verified' => $gzExit === 0];
}
/**
 * APPLY-ONLY. Must not be called from dry-run/verify.
 *
 * @param  array<string,mixed>  $ctx
 * @return array{write:array<string,mixed>,rollback_evidence:array<string,mixed>}
 */
function applyContainmentInTransaction(array $ctx): array
{
    $scId = (int) $ctx['student_class_id'];
    $cancelledId = $ctx['cancelled_session_id'];
    $billingBefore = $ctx['billing_before'];
    $endBefore = $ctx['end_date_before'];
    return Illuminate\Support\Facades\DB::transaction(function () use ($scId, $cancelledId, $billingBefore, $endBefore, $ctx) {
        /** @var App\Models\StudentClass|null $locked */
        $locked = App\Models\StudentClass::query()->where('ID', $scId)->lockForUpdate()->first();
        if (!$locked || (int) ($locked->Stop ?? 0) === 1 || $locked->isUsageSettlementLocked()) {
            throw new RuntimeException('ABORT_LOCK_STATE_CHANGED');
        }
        assertNoOverlapConflicts((int) $ctx['student_id'], (int) $ctx['teacher_id'], $scId);
        $endNow = $locked->EndDate ? substr((string) $locked->EndDate, 0, 10) : null;
        $extended = false;
        if ($endNow === null || $endNow < TARGET_DATE) {
            $locked->EndDate = TARGET_DATE;
            $locked->save();
            $extended = true;
        }
        if ($cancelledId !== null) {
            $cancelledRows = App\Models\ClassSession::query()
                ->where('StudentClassID', $scId)->whereDate('SessionDate', TARGET_DATE)
                ->whereRaw('SUBSTRING(StartTime,1,5) = ?', [START_HM])
                ->whereRaw('LOWER(Status) = ?', ['cancelled'])->lockForUpdate()->orderBy('id')->get();
            if ($cancelledRows->count() !== 1 || (int) $cancelledRows->first()->id !== (int) $cancelledId) {
                throw new RuntimeException('ABORT_AMBIGUOUS_CANCELLED_ROWS');
            }
            $row = $cancelledRows->first();
            $row->Status = 'scheduled';
            $row->EndTime = END_HM . ':00';
            $row->StartTime = START_HM . ':00';
            $row->SubjectID = $locked->SubjectID;
            $row->Note = NOTE;
            if (Illuminate\Support\Facades\Schema::hasColumn('ClassSession', 'IsContractException')) {
                $row->IsContractException = 0;
            }
            $row->save();
            $path = 'restore_cancelled';
            $sessionId = (int) $row->id;
        } else {
            $result = app(App\Services\ClassSessionMaterializationService::class)->upsertSlot([
                'StudentClassID' => $scId,
                'SubjectID' => (int) $locked->SubjectID,
                'SessionDate' => TARGET_DATE,
                'StartTime' => START_HM,
                'EndTime' => END_HM,
                'Status' => 'scheduled',
                'Note' => NOTE,
                'IsContractException' => 0,
                '_student_class' => $locked,
            ]);
            $session = $result['session'];
            if (!$result['created'] && strtolower((string) $session->Status) === 'cancelled') {
                throw new RuntimeException('ABORT_UPSERT_RETURNED_CANCELLED_WITHOUT_RESTORE');
            }
            if (strtolower((string) $session->Status) !== 'scheduled') {
                throw new RuntimeException('ABORT_UPSERT_RETURNED_NON_SCHEDULED');
            }
            $path = 'upsertSlot';
            $sessionId = (int) $session->id;
        }
        $active = App\Models\ClassSession::query()
            ->where('StudentClassID', $scId)->whereDate('SessionDate', TARGET_DATE)
            ->whereRaw('SUBSTRING(StartTime,1,5) = ?', [START_HM])
            ->whereRaw('LOWER(Status) <> ?', ['cancelled'])->get();
        if ($active->count() !== 1) {
            throw new RuntimeException('ABORT_TX_POSTCONDITION_ACTIVE_COUNT');
        }
        $one = $active->first();
        if (strtolower((string) $one->Status) !== 'scheduled'
            || substr((string) $one->StartTime, 0, 5) !== START_HM
            || substr((string) $one->EndTime, 0, 5) !== END_HM
            || (int) $one->StudentClassID !== $scId) {
            throw new RuntimeException('ABORT_TX_POSTCONDITION_SESSION_SHAPE');
        }
        $fresh = App\Models\StudentClass::query()->where('ID', $scId)->first();
        $billingAfter = snapshotBilling($fresh);
        foreach (BILLING_FIELDS as $field) {
            if ((string) ($billingBefore[$field] ?? '') !== (string) ($billingAfter[$field] ?? '')) {
                throw new RuntimeException('ABORT_TX_BILLING_FIELD_CHANGED:' . $field);
            }
        }
        $endAfter = $fresh->EndDate ? substr((string) $fresh->EndDate, 0, 10) : null;
        if ($extended && $endAfter !== TARGET_DATE) {
            throw new RuntimeException('ABORT_TX_ENDDATE_NOT_EXTENDED');
        }
        if (!$extended && $endAfter !== $endBefore) {
            throw new RuntimeException('ABORT_TX_ENDDATE_UNEXPECTED_CHANGE');
        }
        return [
            'write' => [
                'noop' => false,
                'path' => $path,
                'end_date_extended' => $extended,
                'session_id' => $sessionId,
            ],
            'rollback_evidence' => [
                'inverse' => [
                    'StudentClass.EndDate' => $extended ? ('restore to ' . ($endBefore ?? 'NULL')) : 'unchanged',
                    'ClassSession' => $path === 'upsertSlot'
                        ? ('set Status=cancelled for id=' . $sessionId)
                        : ('set Status=cancelled for restored id=' . $sessionId),
                ],
                'note' => 'Do NOT delayed full-table restore — may overwrite later writes. Prefer precise inverse ops.',
            ],
        ];
    });
}
/**
 * @param  array<string,mixed>  $billingBefore
 * @return array{after:array<string,mixed>,billing_fields_unchanged:bool,ok_unique_scheduled:bool}
 */
function loadAfterState(int $scId, int $studentId, int $teacherId, array $billingBefore): array
{
    $afterSc = App\Models\StudentClass::query()->where('ID', $scId)->first();
    $billingAfter = snapshotBilling($afterSc);
    $unchanged = true;
    foreach (BILLING_FIELDS as $field) {
        if ((string) ($billingBefore[$field] ?? '') !== (string) ($billingAfter[$field] ?? '')) {
            $unchanged = false;
            break;
        }
    }
    $afterCs = App\Models\ClassSession::query()
        ->where('StudentClassID', $scId)->whereDate('SessionDate', TARGET_DATE)
        ->whereRaw('SUBSTRING(StartTime,1,5) = ?', [START_HM])
        ->whereRaw('LOWER(Status) <> ?', ['cancelled'])->orderBy('id')
        ->get(['id', 'StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status', 'Note', 'SubjectID']);
    $ok = (
        $afterCs->count() === 1 && $afterSc !== null
        && strtolower((string) $afterCs->first()->Status) === 'scheduled'
        && substr((string) $afterCs->first()->StartTime, 0, 5) === START_HM
        && substr((string) $afterCs->first()->EndTime, 0, 5) === END_HM
        && (int) $afterCs->first()->StudentClassID === $scId
        && (int) $afterSc->StudentID === $studentId
        && (int) $afterSc->TeacherID === $teacherId
    );
    return [
        'after' => [
            'end_date' => $afterSc && $afterSc->EndDate ? substr((string) $afterSc->EndDate, 0, 10) : null,
            'active_count' => $afterCs->count(),
            'session' => $afterCs->count() === 1 ? sessionBrief($afterCs->first()) : $afterCs->map(fn ($r) => sessionBrief($r))->all(),
            'billing' => $billingAfter,
        ],
        'billing_fields_unchanged' => $unchanged,
        'ok_unique_scheduled' => $ok,
    ];
}
/** @return array<string,mixed> */
function snapshotBilling(?App\Models\StudentClass $sc): array
{
    if ($sc === null) {
        return [];
    }
    $out = [];
    foreach (BILLING_FIELDS as $field) {
        $val = $sc->{$field} ?? null;
        $out[$field] = $val instanceof DateTimeInterface ? $val->format('Y-m-d H:i:s') : $val;
    }
    return $out;
}
/** @return array<string,mixed> */
function sessionBrief(object $r): array
{
    return [
        'id' => (int) $r->id,
        'StudentClassID' => (int) $r->StudentClassID,
        'SessionDate' => substr((string) $r->SessionDate, 0, 10),
        'StartTime' => substr((string) $r->StartTime, 0, 5),
        'EndTime' => substr((string) $r->EndTime, 0, 5),
        'Status' => (string) $r->Status,
        'Note' => isset($r->Note) ? (string) $r->Note : null,
        'SubjectID' => isset($r->SubjectID) ? (int) $r->SubjectID : null,
    ];
}
/** @param array<string,mixed> $evidence */
function abortCode(array &$evidence, string $code): never
{
    $evidence['phase'] = 'ABORTED';
    $evidence['aborts'][] = $code;
    throw new RuntimeException($code);
}
/** @param array<string,mixed> $evidence */
function emit(array $evidence): void
{
    echo json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
}
