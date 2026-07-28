<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Parent-message awaiting_staff_reply semantics (PRD 2026-07-28).
 *
 * Stable ordering proof (no migration):
 * - Parent follow-ups and content-change events are stored as rows in
 *   learning_record_feedback_replies with author_role=parent.
 * - Staff public replies use the same table with author_role in (teacher, director).
 * - Latest event comparison uses (created_at, id) **within that single table** —
 *   auto-increment id is a total order; same-second ties are resolved by id.
 * - Body-only threads (feedback row, zero parent replies): awaiting iff no staff reply.
 * - Historical content edits that only bumped feedback.updated_at (pre-fix) are not
 *   reconstructed without G3 backfill; forward path writes a parent reply on real edits.
 *
 * Must NOT reuse analytics.unreplied_records.
 */
class ParentFeedbackAwaitingService
{
    public const STAFF_PUBLIC_ROLES = ['teacher', 'director'];

    public function normalizeContent(string $content): string
    {
        return trim($content);
    }

    /**
     * @param  iterable<int, object>|null  $replies  objects with author_role, created_at, id
     */
    public function isAwaitingStaffReply(?object $feedback, $replies = null): bool
    {
        if (!$feedback) {
            return false;
        }

        $rows = $replies;
        if ($rows === null) {
            $rows = DB::table('learning_record_feedback_replies')
                ->where('feedback_id', (int) $feedback->id)
                ->get(['id', 'author_role', 'created_at']);
        }

        $parentLatest = null;
        $staffLatest = null;
        foreach ($rows as $row) {
            $role = (string) ($row->author_role ?? '');
            $key = $this->eventSortKey($row->created_at ?? null, (int) ($row->id ?? 0));
            if ($role === 'parent') {
                if ($parentLatest === null || $this->compareSortKeys($key, $parentLatest) > 0) {
                    $parentLatest = $key;
                }
            } elseif (in_array($role, self::STAFF_PUBLIC_ROLES, true)) {
                if ($staffLatest === null || $this->compareSortKeys($key, $staffLatest) > 0) {
                    $staffLatest = $key;
                }
            }
        }

        if ($parentLatest === null) {
            // Body-only: awaiting until first staff public reply.
            return $staffLatest === null;
        }

        if ($staffLatest === null) {
            return true;
        }

        return $this->compareSortKeys($parentLatest, $staffLatest) > 0;
    }

    /**
     * Restrict a LearningRecord query to rows whose parent feedback is awaiting staff reply.
     *
     * @param  EloquentBuilder|QueryBuilder  $query
     */
    public function constrainLearningRecordsAwaitingReply($query, string $learningRecordIdColumn): void
    {
        $query->whereExists(function ($sub) use ($learningRecordIdColumn) {
            $sub->select(DB::raw(1))
                ->from('learning_record_feedbacks as lrf_await')
                ->whereColumn('lrf_await.learning_record_id', $learningRecordIdColumn)
                ->where(function ($outer) {
                    // A) No staff public reply at all → awaiting (body-only or unanswered).
                    $outer->whereNotExists(function ($noStaff) {
                        $noStaff->select(DB::raw(1))
                            ->from('learning_record_feedback_replies as r_staff0')
                            ->whereColumn('r_staff0.feedback_id', 'lrf_await.id')
                            ->whereIn('r_staff0.author_role', self::STAFF_PUBLIC_ROLES);
                    })->orWhere(function ($parentBeatsStaff) {
                        // B) Latest parent reply strictly after latest staff reply (same-table order).
                        $parentBeatsStaff->whereExists(function ($hasParent) {
                            $hasParent->select(DB::raw(1))
                                ->from('learning_record_feedback_replies as r_parent')
                                ->whereColumn('r_parent.feedback_id', 'lrf_await.id')
                                ->where('r_parent.author_role', 'parent')
                                ->whereRaw(
                                    "NOT EXISTS (
                                        SELECT 1 FROM learning_record_feedback_replies AS r_staff
                                        WHERE r_staff.feedback_id = lrf_await.id
                                          AND r_staff.author_role IN ('teacher', 'director')
                                          AND (
                                            r_staff.created_at > r_parent.created_at
                                            OR (
                                              r_staff.created_at = r_parent.created_at
                                              AND r_staff.id >= r_parent.id
                                            )
                                          )
                                      )"
                                )
                                ->whereRaw(
                                    "r_parent.id = (
                                        SELECT p2.id FROM learning_record_feedback_replies AS p2
                                        WHERE p2.feedback_id = lrf_await.id
                                          AND p2.author_role = 'parent'
                                        ORDER BY p2.created_at DESC, p2.id DESC
                                        LIMIT 1
                                    )"
                                );
                        });
                    });
                });
        });
    }

    /**
     * Count feedback threads awaiting staff reply under role scope.
     * Teacher: all campuses for that teacher_id.
     * Director: single campus (branchId) unless $allAuthorizedCampuses with campusIds.
     */
    public function countAwaitingForStaff(
        string $role,
        int $teacherId = 0,
        array $campusIds = [],
        int $branchId = 0,
        bool $allAuthorizedCampuses = false
    ): int {
        $q = DB::table('learning_record_feedbacks as lrf');

        if ($role === 'teacher') {
            if ($teacherId <= 0) {
                return 0;
            }
            $q->where('lrf.teacher_id', $teacherId);
        } else {
            // director path only for P0 (no super_admin expansion).
            if ($allAuthorizedCampuses) {
                if (empty($campusIds)) {
                    return 0;
                }
                $q->whereIn('lrf.campus_id', array_map('intval', $campusIds));
            } else {
                if ($branchId <= 0) {
                    return 0;
                }
                $q->where('lrf.campus_id', $branchId);
            }
        }

        $q->where(function ($outer) {
            $outer->whereNotExists(function ($noStaff) {
                $noStaff->select(DB::raw(1))
                    ->from('learning_record_feedback_replies as r_staff0')
                    ->whereColumn('r_staff0.feedback_id', 'lrf.id')
                    ->whereIn('r_staff0.author_role', self::STAFF_PUBLIC_ROLES);
            })->orWhereExists(function ($hasParent) {
                $hasParent->select(DB::raw(1))
                    ->from('learning_record_feedback_replies as r_parent')
                    ->whereColumn('r_parent.feedback_id', 'lrf.id')
                    ->where('r_parent.author_role', 'parent')
                    ->whereRaw(
                        "NOT EXISTS (
                            SELECT 1 FROM learning_record_feedback_replies AS r_staff
                            WHERE r_staff.feedback_id = lrf.id
                              AND r_staff.author_role IN ('teacher', 'director')
                              AND (
                                r_staff.created_at > r_parent.created_at
                                OR (
                                  r_staff.created_at = r_parent.created_at
                                  AND r_staff.id >= r_parent.id
                                )
                              )
                          )"
                    )
                    ->whereRaw(
                        "r_parent.id = (
                            SELECT p2.id FROM learning_record_feedback_replies AS p2
                            WHERE p2.feedback_id = lrf.id
                              AND p2.author_role = 'parent'
                            ORDER BY p2.created_at DESC, p2.id DESC
                            LIMIT 1
                        )"
                    );
            });
        });

        return (int) $q->count();
    }

    /**
     * @return array{0:string,1:int} [datetime string, id]
     */
    private function eventSortKey($createdAt, int $id): array
    {
        if ($createdAt instanceof \DateTimeInterface) {
            $ts = $createdAt->format('Y-m-d H:i:s');
        } else {
            $ts = (string) $createdAt;
        }

        return [$ts, $id];
    }

    /** @param array{0:string,1:int} $a @param array{0:string,1:int} $b */
    private function compareSortKeys(array $a, array $b): int
    {
        if ($a[0] !== $b[0]) {
            return $a[0] <=> $b[0];
        }

        return $a[1] <=> $b[1];
    }
}
