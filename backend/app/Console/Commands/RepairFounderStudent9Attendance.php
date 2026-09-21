<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\ScheduleAuditLog;
use App\Models\SessionCorrection;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\LearningRecordBackfillService;
use App\Services\SessionDeductionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
class RepairFounderStudent9Attendance extends Command
{
    protected $signature = 'repair:founder-student9-attendance
                            {--manifest= : Immutable repair manifest JSON}
                            {--dry-run}
                            {--execute}
                            {--rollback}
                            {--force}
                            {--snapshot=}
                            {--actor=}
                            {--actor-user-id=4}';

    protected $description = 'Repair the Founder-approved StudentID=9 attendance records';

    private const REF = 'founder-student9-attendance-20260921';
    private const STUDENT_ID = 9;
    private const CAMPUS_ID = 15;
    /** @var array<string,array<string,mixed>> */
    private const TARGETS = [
        'biology_28451' => [
            'kind' => 'restore',
            'class_id' => 2819,
            'session_id' => 28451,
            'date' => '2026-08-03',
            'start' => '10:00',
            'end' => '12:00',
            'teacher_id' => 67,
            'learning_record_id' => 15134,
            'sign_in_id' => 8493,
            'status' => 'attended',
        ],
        'social_0728' => [
            'kind' => 'create',
            'class_id' => 2812,
            'session_id' => null,
            'date' => '2026-07-28',
            'start' => '13:00',
            'end' => '15:00',
            'teacher_id' => 49,
            'status' => 'attended',
            'rate' => 2750,
        ],
    ];

    public function handle(LearningRecordBackfillService $backfill): int
    {
        if (!$this->validateManifest()) {
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $rollback = (bool) $this->option('rollback');
        if ($execute && $rollback) {
            $this->error('--execute and --rollback are mutually exclusive');
            return self::FAILURE;
        }
        if (($execute || $rollback) && !$this->productionAllowed()) {
            return self::FAILURE;
        }

        $plan = $this->plan($rollback);
        $mode = $rollback ? 'ROLLBACK' : ($execute ? 'EXECUTE' : 'DRY RUN');
        $this->line("=== {$mode} " . self::REF . ' ===');
        $this->line(json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        if (!$execute && !$rollback) {
            return self::SUCCESS;
        }

        $actionable = array_values(array_filter(
            $plan,
            fn (array $row): bool => $row['error'] === null && !$row['already']
        ));
        if ($actionable === []) {
            $this->info($rollback ? 'No active repair to roll back.' : 'Repair is already applied.');
            return self::SUCCESS;
        }

        $snapshotPath = (string) ($this->option('snapshot') ?: storage_path(
            'app/repair-snapshots/' . self::REF . '-' . now()->format('YmdHis') . '.json'
        ));
        if (!is_dir(dirname($snapshotPath))) {
            mkdir(dirname($snapshotPath), 0755, true);
        }
        file_put_contents($snapshotPath, json_encode([
            'case' => self::REF,
            'generated_at' => now()->toIso8601String(),
            'rollback' => $rollback,
            'plan' => $plan,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL);
        $this->line("Snapshot: {$snapshotPath}");

        foreach ($actionable as $row) {
            if ($row['target']['kind'] === 'restore') {
                $rollback ? $this->rollbackBiology($row) : $this->restoreBiology($row);
            } else {
                $rollback ? $this->rollbackSocial($row) : $this->createSocial($row, $backfill);
            }
        }

        $this->info(($rollback ? 'Rolled back ' : 'Applied ') . count($actionable) . ' repair item(s).');
        return self::SUCCESS;
    }
    /** @return list<array<string,mixed>> */
    private function plan(bool $rollback): array
    {
        $out = [];
        foreach (self::TARGETS as $key => $target) {
            $studentClass = DB::table('StudentClass')->where('ID', $target['class_id'])->first();
            $student = $studentClass
                ? DB::table('Student')->where('id', $studentClass->StudentID)->first()
                : null;
            $sessions = DB::table('ClassSession')
                ->where('StudentClassID', $target['class_id'])
                ->whereDate('SessionDate', $target['date'])
                ->orderBy('id')
                ->get();
            $createdIds = $this->createdSessionIds();
            $session = $target['session_id']
                ? $sessions->firstWhere('id', $target['session_id'])
                : $sessions->first(fn ($candidate): bool => in_array((int) $candidate->id, $createdIds, true));
            $correction = SessionCorrection::query()
                ->where('decision_reference', self::REF)
                ->whereNull('rolled_back_at')
                ->whereIn('session_id', $target['kind'] === 'create'
                    ? $createdIds
                    : [(int) $target['session_id']])
                ->latest('id')
                ->first();

            $error = $correction
                ? null
                : $this->validateTarget($target, $studentClass, $student, $sessions, $session, $rollback);
            $out[] = [
                'key' => $key,
                'target' => $target,
                'error' => $error,
                'already' => $rollback ? $correction === null : $correction !== null,
                'student' => $student ? ['id' => (int) $student->id, 'campus_id' => (int) $student->CampusID] : null,
                'student_class' => $studentClass ? [
                    'id' => (int) $studentClass->ID,
                    'student_id' => (int) $studentClass->StudentID,
                    'teacher_id' => (int) $studentClass->TeacherID,
                    'rate' => (int) $studentClass->Rate,
                ] : null,
                'matching_sessions' => $sessions->map(fn ($row): array => (array) $row)->all(),
                'session' => $session ? (array) $session : null,
                'active_learning_record_ids' => $session ? LearningRecord::query()->where('ClassSessionID', $session->id)->whereNull('VoidedAt')->pluck('id')->map(fn ($id): int => (int) $id)->all() : [],
                'active_sign_in_ids' => $session ? StudentSignIn::query()->where('ClassSessionID', $session->id)->whereNull('VoidedAt')->pluck('id')->map(fn ($id): int => (int) $id)->all() : [],
                'active_correction_id' => $correction?->id,
            ];
        }

        return $out;
    }
    private function validateTarget(array $target, ?object $studentClass, ?object $student, $sessions, ?object $session, bool $rollback): ?string
    {
        if (!$studentClass || (int) $studentClass->StudentID !== self::STUDENT_ID) {
            return 'STUDENT_CLASS_DRIFT';
        }
        if (!$student || (int) $student->CampusID !== self::CAMPUS_ID) {
            return 'CAMPUS_DRIFT';
        }
        if ((int) $studentClass->TeacherID !== (int) $target['teacher_id'] && $target['kind'] === 'restore') {
            return 'TEACHER_DRIFT';
        }
        if ($target['kind'] === 'create') {
            if ($rollback) {
                return $session ? null : 'CREATED_SESSION_MISSING';
            }
            return $sessions->isEmpty() ? null : 'DATE_ALREADY_HAS_SESSION';
        }
        if (!$session) {
            return 'TARGET_SESSION_MISSING';
        }
        if ((int) $session->StudentClassID !== $target['class_id']
            || substr((string) $session->SessionDate, 0, 10) !== $target['date']
            || substr((string) $session->StartTime, 0, 5) !== $target['start']
            || substr((string) $session->EndTime, 0, 5) !== $target['end']) {
            return 'SESSION_METADATA_DRIFT';
        }
        if (!$rollback && strtolower((string) $session->Status) !== 'cancelled') {
            return 'SESSION_NOT_CANCELLED';
        }
        if ($rollback && strtolower((string) $session->Status) !== 'attended') {
            return 'SESSION_NOT_ATTENDED';
        }
        return null;
    }

    private function restoreBiology(array $row): void
    {
        $target = $row['target'];
        DB::transaction(function () use ($target): void {
            $session = DB::table('ClassSession')->where('id', $target['session_id'])->lockForUpdate()->first();
            if (!$session || strtolower((string) $session->Status) !== 'cancelled') {
                throw new \RuntimeException('BIOLOGY_SESSION_DRIFT');
            }
            $now = now();
            $reason = self::REF . ' — Founder confirmed 2026-08-03 biology attended';
            $old = (array) $session;
            $oldNote = DB::table('schedule_audit_logs')
                ->where('session_id', $target['session_id'])
                ->where('id', 31016)
                ->value('old_data');
            $decodedOld = is_string($oldNote) ? json_decode($oldNote, true) : null;
            $note = is_array($decodedOld) ? (string) ($decodedOld['Note'] ?? '') : (string) $session->Note;

            DB::table('ClassSession')->where('id', $target['session_id'])->update([
                'Status' => 'attended', 'Note' => $note, 'updated_at' => $now,
            ]);
            $lr = DB::table('LearningRecord')->where('id', $target['learning_record_id'])
                ->where('ClassSessionID', $target['session_id'])->whereNotNull('VoidedAt')->first();
            $signIn = DB::table('StudentSingIn')->where('id', $target['sign_in_id'])
                ->where('ClassSessionID', $target['session_id'])->whereNotNull('VoidedAt')->first();
            if (!$lr || !$signIn) {
                throw new \RuntimeException('BIOLOGY_ATTENDANCE_ROW_DRIFT');
            }
            DB::table('LearningRecord')->where('id', $target['learning_record_id'])->update([
                'VoidedAt' => null, 'VoidedByUserID' => null, 'VoidReason' => null, 'updated_at' => $now,
            ]);
            DB::table('StudentSingIn')->where('id', $target['sign_in_id'])->update([
                'VoidedAt' => null, 'VoidedByUserID' => null, 'VoidReason' => null,
            ]);
            $deducted = SessionDeductionService::deductForSession(
                (int) $target['class_id'], (int) $target['session_id'], 'attendance', $this->actorId(), $reason
            );
            if (!$deducted) {
                throw new \RuntimeException('BIOLOGY_DEDUCTION_NOT_RECORDED');
            }
            SessionDeductionService::recomputeCounters((int) $target['class_id']);
            $this->recordCorrection($target['session_id'], 'cancelled', 'attended', $old, $reason);
            $this->recordAudit($target['session_id'], $old, (array) DB::table('ClassSession')->where('id', $target['session_id'])->first(), $reason);
        });
    }

    private function createSocial(array $row, LearningRecordBackfillService $backfill): void
    {
        $target = $row['target'];
        DB::transaction(function () use ($target, $backfill): void {
            $sc = StudentClass::query()->whereKey($target['class_id'])->lockForUpdate()->first();
            if (!$sc || (int) $sc->StudentID !== self::STUDENT_ID || (int) $sc->TeacherID !== $target['teacher_id']) {
                throw new \RuntimeException('SOCIAL_COURSE_DRIFT');
            }
            if (DB::table('ClassSession')->where('StudentClassID', $target['class_id'])->whereDate('SessionDate', $target['date'])->exists()) {
                throw new \RuntimeException('SOCIAL_DATE_ALREADY_HAS_SESSION');
            }
            $now = now();
            $reason = self::REF . ' — Founder confirmed 2026-07-28 social attended';
            $session = (new ClassSession([
                'StudentClassID' => $target['class_id'],
                'SessionDate' => $target['date'],
                'StartTime' => $target['start'] . ':00',
                'EndTime' => $target['end'] . ':00',
                'Status' => 'attended',
                'Note' => $reason,
                'session_charge' => (int) $target['rate'],
            ]))->setAllowStudentOverlap(false);
            $session->save();

            $signIn = new StudentSignIn([
                'StudentClassID' => $target['class_id'],
                'StudentID' => self::STUDENT_ID,
                'TeacherID' => $target['teacher_id'],
                'RecordedByUserID' => $this->actorId(),
                'SubjectID' => (int) $sc->SubjectID,
                'SignInDT' => $target['date'] . ' ' . $target['start'] . ':00',
                'ClassSessionID' => $session->id,
                'Status' => 'present',
                'CampusID' => self::CAMPUS_ID,
                'SessionDeducted' => false,
                'Memo' => $reason,
            ]);
            $signIn->save();
            if (!SessionDeductionService::deductOnAttendance($sc, $signIn, $session->id)) {
                throw new \RuntimeException('SOCIAL_DEDUCTION_NOT_RECORDED');
            }
            $backfill->ensureRequiredForAttendanceSession($session->fresh());
            SessionDeductionService::recomputeCounters((int) $target['class_id']);
            $this->recordCorrection($session->id, 'missing', 'attended', ['created_session_id' => $session->id], $reason);
            $this->recordAudit($session->id, [], (array) $session->fresh(), $reason);
        });
    }

    private function rollbackBiology(array $row): void
    {
        $this->rollbackSession((int) $row['target']['session_id'], (int) $row['target']['class_id'], false);
    }

    private function rollbackSocial(array $row): void
    {
        $this->rollbackSession((int) $row['session']['id'], (int) $row['target']['class_id'], true);
    }

    private function rollbackSession(int $sessionId, int $classId, bool $deleteCreated): void
    {
        DB::transaction(function () use ($sessionId, $classId, $deleteCreated): void {
            $session = DB::table('ClassSession')->where('id', $sessionId)->lockForUpdate()->first();
            if (!$session || strtolower((string) $session->Status) !== 'attended') {
                throw new \RuntimeException('ROLLBACK_SESSION_DRIFT_' . $sessionId);
            }
            $now = now();
            $reason = self::REF . ' — rollback';
            $old = (array) $session;
            DB::table('StudentSingIn')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->update([
                'VoidedAt' => $now, 'VoidedByUserID' => $this->actorId(), 'VoidReason' => $reason,
            ]);
            DB::table('LearningRecord')->where('ClassSessionID', $sessionId)->whereNull('VoidedAt')->update([
                'VoidedAt' => $now, 'VoidedByUserID' => $this->actorId(), 'VoidReason' => $reason, 'updated_at' => $now,
            ]);
            SessionDeductionService::reverseForSession($classId, $sessionId, 'status_adjust', $this->actorId(), $reason);
            DB::table('ClassSession')->where('id', $sessionId)->update([
                'Status' => 'cancelled', 'updated_at' => $now, 'Note' => trim((string) $session->Note . ' ' . $reason),
            ]);
            SessionDeductionService::recomputeCounters($classId);
            $this->recordCorrection($sessionId, 'attended', 'cancelled', $old, $reason);
            $this->recordAudit($sessionId, $old, (array) DB::table('ClassSession')->where('id', $sessionId)->first(), $reason);
            if ($deleteCreated) {
            }
        });
    }

    private function recordCorrection(int $sessionId, string $previous, string $new, array $snapshot, string $reason): void
    {
        SessionCorrection::query()->create([
            'session_id' => $sessionId,
            'replaced_by_session_id' => null,
            'correction_reason' => 'founder_attendance_repair',
            'decision_reference' => self::REF,
            'decided_at' => now(),
            'decided_by_user_id' => $this->actorId(),
            'decided_by_actor' => (string) ($this->option('actor') ?: self::REF),
            'previous_status' => $previous,
            'new_status' => $new,
            'snapshot_before' => $snapshot,
        ]);
    }

    private function recordAudit(int $sessionId, array $old, array $new, string $reason): void
    {
        $branchId = (int) (DB::table('Student as st')
            ->join('StudentClass as sc', 'sc.StudentID', '=', 'st.id')
            ->join('ClassSession as cs', 'cs.StudentClassID', '=', 'sc.ID')
            ->where('cs.id', $sessionId)
            ->value('st.CampusID') ?? self::CAMPUS_ID);
        ScheduleAuditLog::query()->create([
            'session_id' => $sessionId,
            'action_type' => 'update',
            'description' => '資料修復：' . $reason,
            'operator_id' => $this->actorId(),
            'branch_id' => $branchId,
            'old_data' => $old,
            'new_data' => $new,
        ]);
    }

    /** @return list<int> */
    private function createdSessionIds(): array
    {
        return DB::table('session_corrections')
            ->where('decision_reference', self::REF)
            ->where('correction_reason', 'founder_attendance_repair')
            ->pluck('session_id')->map(fn ($id): int => (int) $id)->all();
    }

    private function validateManifest(): bool
    {
        $path = (string) ($this->option('manifest') ?: '');
        if ($path === '' || !is_file($path)) {
            $this->error('--manifest is required and must point to the committed repair manifest');
            return false;
        }
        $manifest = json_decode((string) file_get_contents($path), true);
        if (($manifest['kind'] ?? '') !== 'founder_student9_attendance_repair_manifest'
            || ($manifest['decision_reference'] ?? '') !== self::REF
            || ($manifest['student_id'] ?? null) !== self::STUDENT_ID
            || ($manifest['campus_id'] ?? null) !== self::CAMPUS_ID
            || ($manifest['approved_session_ids'] ?? null) !== [28451]) {
            $this->error('repair manifest mismatch');
            return false;
        }
        return true;
    }

    private function actorId(): ?int
    {
        $id = (int) $this->option('actor-user-id');
        return $id > 0 ? $id : null;
    }

    private function productionAllowed(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force') || env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires --force and ALLOW_PROD_REPAIR=1');
            return false;
        }
        return true;
    }
}
