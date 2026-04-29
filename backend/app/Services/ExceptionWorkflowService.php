<?php

namespace App\Services;

use App\Models\ExceptionWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ExceptionWorkflowService
{
    /**
     * Create a workflow once per source key. Repeated calls return the original
     * record so parent retries or duplicate button clicks do not fork the case.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createOrGet(array $attributes): ExceptionWorkflow
    {
        $sourceKey = trim((string) ($attributes['source_key'] ?? ''));
        if ($sourceKey === '') {
            throw new \InvalidArgumentException('source_key is required');
        }

        return DB::transaction(function () use ($sourceKey, $attributes) {
            $existing = ExceptionWorkflow::where('source_key', $sourceKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $payload = [
                'source_key' => $sourceKey,
                'campus_id' => (int) ($attributes['campus_id'] ?? 0),
                'type' => (string) ($attributes['type'] ?? 'student_leave'),
                'status' => (string) ($attributes['status'] ?? 'open'),
                'severity' => (string) ($attributes['severity'] ?? 'medium'),
            ];

            foreach ([
                'student_id',
                'student_class_id',
                'class_session_id',
                'source_type',
                'source_id',
                'owner_user_id',
                'due_at',
                'closed_at',
                'closed_reason',
                'payload',
                'created_by_user_id',
                'parent_session_id',
            ] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $payload[$key] = $attributes[$key];
                }
            }

            return ExceptionWorkflow::create($payload);
        });
    }

    /**
     * @param  array<int>  $campusIds  Empty array means all campuses.
     */
    public function queryForCampusIds(array $campusIds): Builder
    {
        $query = ExceptionWorkflow::query();
        $ids = array_values(array_unique(array_map('intval', $campusIds)));

        if (!empty($ids)) {
            $query->whereIn('campus_id', $ids);
        }

        return $query->orderByRaw('due_at IS NULL ASC')
            ->orderBy('due_at')
            ->orderByDesc('id');
    }

    /**
     * Replace the candidate snapshot for one workflow. The confirmation path
     * must still re-check conflicts; candidates are recommendations, not locks.
     *
     * @param  array<int, array<string, mixed>>  $candidates
     */
    public function replaceCandidates(ExceptionWorkflow $workflow, array $candidates): void
    {
        DB::transaction(function () use ($workflow, $candidates) {
            $workflow->candidates()->delete();

            foreach ($candidates as $candidate) {
                $workflow->candidates()->create([
                    'rank' => (int) ($candidate['rank'] ?? 1),
                    'candidate_date' => $candidate['candidate_date'],
                    'start_time' => $candidate['start_time'],
                    'end_time' => $candidate['end_time'],
                    'teacher_id' => $candidate['teacher_id'] ?? null,
                    'room_id' => $candidate['room_id'] ?? null,
                    'status' => (string) ($candidate['status'] ?? 'available'),
                    'score' => (int) ($candidate['score'] ?? 0),
                    'reasons' => $candidate['reasons'] ?? null,
                    'expires_at' => $candidate['expires_at'],
                ]);
            }
        });
    }
}
