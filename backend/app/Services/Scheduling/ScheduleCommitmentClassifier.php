<?php

namespace App\Services\Scheduling;

use Carbon\Carbon;

/**
 * ADR-006 §9.4 three-way (+ incomplete/flexible) commitment classification.
 * Read-only; no DB writes.
 */
final class ScheduleCommitmentClassifier
{
    public function __construct(
        private ?ContractSlotParser $slotParser = null,
        private ?HistoryCadenceInferrer $cadenceInferrer = null,
    ) {
        $this->slotParser = $slotParser ?? new ContractSlotParser();
        $this->cadenceInferrer = $cadenceInferrer ?? new HistoryCadenceInferrer();
    }

    /**
     * @param  object|array<string,mixed>  $course  StudentClass-like
     * @param  list<object|array<string,mixed>>  $recentSessions  newest-first
     * @return array<string,mixed>
     */
    public function classify(object|array $course, array $recentSessions, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today('Asia/Taipei'))->copy()->startOfDay();
        $get = static function (object|array $c, string $key): mixed {
            return is_array($c) ? ($c[$key] ?? null) : ($c->{$key} ?? null);
        };

        $studentClassId = (int) ($get($course, 'ID') ?? $get($course, 'id') ?? 0);
        $scheduleMode = strtolower((string) ($get($course, 'ScheduleMode') ?? 'count'));
        $stop = (int) ($get($course, 'Stop') ?? 0);
        $remaining = (int) ($get($course, 'RemainingSessions') ?? 0);
        $packageId = (int) ($get($course, 'PackageID') ?? 0);
        $teacherId = (int) ($get($course, 'TeacherID') ?? 0);
        $subjectId = (int) ($get($course, 'SubjectID') ?? 0);
        $campusId = (int) ($get($course, 'CampusID') ?? 0);
        $startDate = $get($course, 'StartDate');
        $endDate = $get($course, 'EndDate');

        $base = [
            'student_class_id' => $studentClassId,
            'rule_version' => CommitmentReasonCodes::RULE_VERSION,
            'schedule_mode' => $scheduleMode,
            'package_id' => $packageId > 0 ? $packageId : null,
            'remaining_sessions' => $remaining,
            'campus_id' => $campusId > 0 ? $campusId : null,
            'contract_slots' => [],
            'history_cadence' => null,
            'commitment_snapshot' => null,
            'commitment_fingerprint' => null,
            'guess_required' => false,
            'auto_ensure_eligible' => false,
        ];

        if ($stop === 1) {
            return array_merge($base, [
                'classification' => CommitmentReasonCodes::CLASS_SKIPPED,
                'primary_reason' => CommitmentReasonCodes::SKIP_STOPPED,
                'bucket' => 'skipped_stopped',
            ];
        }

        if ($scheduleMode !== 'count') {
            return array_merge($base, [
                'classification' => CommitmentReasonCodes::CLASS_SKIPPED,
                'primary_reason' => CommitmentReasonCodes::SKIP_NOT_COUNT_MODE,
                'bucket' => 'skipped_not_count',
            ];
        }

        $parsed = $this->slotParser->parse($course);
        $history = $this->cadenceInferrer->infer($recentSessions);
        $base['contract_slots'] = $parsed['slots'];
        $base['history_cadence'] = $history;

        $lastSessionDate = null;
        if ($recentSessions !== []) {
            $first = $recentSessions[0];
            $lastSessionDate = is_array($first)
                ? ($first['SessionDate'] ?? null)
                : ($first->SessionDate ?? null);
        }
        $dormant = false;
        if ($lastSessionDate) {
            $last = Carbon::parse((string) $lastSessionDate)->startOfDay();
            $dormant = $last->lt($today->copy()->subWeeks(3));
        }

        if ($parsed['conflicting']) {
            return $this->withFingerprint(array_merge($base, [
                'classification' => CommitmentReasonCodes::CLASS_CONFLICT,
                'primary_reason' => CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT,
                'bucket' => 'commitment_conflict',
                'guess_required' => true,
                'detail' => 'contract_weekday_time_self_conflict',
            ], $course, $parsed['slots']);
        }

        $hasCompleteSlots = $parsed['unique'] && count($parsed['slots']) >= 1 && !$parsed['partial'];
        $missingTeacher = $teacherId <= 0;
        $missingSubject = $subjectId <= 0;
        $missingCampus = $campusId <= 0;

        if ($hasCompleteSlots) {
            if ($missingTeacher || $missingSubject || $missingCampus) {
                $reason = $missingTeacher
                    ? CommitmentReasonCodes::SKIP_MISSING_TEACHER
                    : ($missingSubject
                        ? CommitmentReasonCodes::SKIP_MISSING_SUBJECT
                        : CommitmentReasonCodes::SKIP_MISSING_BRANCH);

                return $this->withFingerprint(array_merge($base, [
                    'classification' => CommitmentReasonCodes::CLASS_INCOMPLETE,
                    'primary_reason' => CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE,
                    'bucket' => 'commitment_incomplete',
                    'guess_required' => true,
                    'detail' => $reason,
                ], $course, $parsed['slots']);
            }

            if ($this->cadenceInferrer->conflictsWithContract($parsed['slots'], $history)) {
                return $this->withFingerprint(array_merge($base, [
                    'classification' => CommitmentReasonCodes::CLASS_CONFLICT,
                    'primary_reason' => CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT,
                    'bucket' => 'commitment_conflict',
                    'guess_required' => true,
                    'detail' => 'contract_vs_history_mismatch',
                ], $course, $parsed['slots']);
            }

            // Start/End/Stop consistency: EndDate before StartDate → incomplete
            if ($startDate && $endDate) {
                try {
                    if (Carbon::parse((string) $endDate)->lt(Carbon::parse((string) $startDate))) {
                        return $this->withFingerprint(array_merge($base, [
                            'classification' => CommitmentReasonCodes::CLASS_INCOMPLETE,
                            'primary_reason' => CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE,
                            'bucket' => 'commitment_incomplete',
                            'guess_required' => true,
                            'detail' => 'end_before_start',
                        ], $course, $parsed['slots']);
                    }
                } catch (\Throwable) {
                    // ignore parse errors — treat as incomplete below if needed
                }
            }

            $result = array_merge($base, [
                'classification' => CommitmentReasonCodes::CLASS_EXPLICIT,
                'primary_reason' => CommitmentReasonCodes::OK_PLAN,
                'bucket' => 'explicit_commitment',
                'guess_required' => false,
                'auto_ensure_eligible' => true,
                'dormant_signal' => $dormant,
            ];

            return $this->withFingerprint($result, $course, $parsed['slots']);
        }

        // No complete contract slots.
        if ($parsed['partial']) {
            return $this->withFingerprint(array_merge($base, [
                'classification' => CommitmentReasonCodes::CLASS_INCOMPLETE,
                'primary_reason' => CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE,
                'bucket' => 'commitment_incomplete',
                'guess_required' => true,
                'detail' => CommitmentReasonCodes::SKIP_MISSING_SLOT,
            ], $course, $parsed['slots']);
        }

        if ($history['confirmed']) {
            return $this->withFingerprint(array_merge($base, [
                'classification' => CommitmentReasonCodes::CLASS_LEGACY,
                'primary_reason' => CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE,
                'bucket' => 'legacy_inferred_candidate',
                'guess_required' => true,
                'auto_ensure_eligible' => false,
                'dormant_signal' => $dormant,
            ], $course, []);
        }

        // No contract slots, no confirmed history → legitimate flexible prepaid.
        return $this->withFingerprint(array_merge($base, [
            'classification' => CommitmentReasonCodes::CLASS_FLEXIBLE,
            'primary_reason' => CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT,
            'bucket' => 'flexible_no_commitment',
            'guess_required' => false,
            'auto_ensure_eligible' => false,
            'dormant_signal' => $dormant,
        ], $course, []);
    }

    /**
     * @param  array<string,mixed>  $result
     * @param  object|array<string,mixed>  $course
     * @param  list<array<string,mixed>>  $slots
     * @return array<string,mixed>
     */
    private function withFingerprint(array $result, object|array $course, array $slots): array
    {
        $get = static function (object|array $c, string $key): mixed {
            return is_array($c) ? ($c[$key] ?? null) : ($c->{$key} ?? null);
        };

        $snapshot = [
            'student_class_id' => (int) ($get($course, 'ID') ?? $get($course, 'id') ?? 0),
            'slots' => array_map(static fn ($s) => [
                'iso_weekday' => $s['iso_weekday'],
                'start_hm' => $s['start_hm'],
            ], $slots),
            'start_date' => $get($course, 'StartDate'),
            'end_date' => $get($course, 'EndDate'),
            'teacher_id' => (int) ($get($course, 'TeacherID') ?? 0),
            'subject_id' => (int) ($get($course, 'SubjectID') ?? 0),
            'campus_id' => (int) ($get($course, 'CampusID') ?? 0),
            'schedule_mode' => (string) ($get($course, 'ScheduleMode') ?? ''),
            'package_id' => (int) ($get($course, 'PackageID') ?? 0),
            'stop' => (int) ($get($course, 'Stop') ?? 0),
            'schema_version' => CommitmentReasonCodes::RULE_VERSION,
        ];
        $result['commitment_snapshot'] = $snapshot;
        $result['commitment_fingerprint'] = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE));

        return $result;
    }
}
