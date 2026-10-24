<?php

namespace App\Services\Billing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use RuntimeException;

/** A bounded, read-only census of attendance, schedule and continuation facts. */
final class OrdinaryCloseActivityCensus
{
    private const DOMAIN = 'alltrue-ordinary-close-activity-v1|';
    private const MAX_BYTES = 131072;
    private array $ids = [];
    private array $digests = [];
    private int $budget = 1000;
    private int $encodedBytes = 0;

    /** Caller must be in the close transaction. Parameters are routing hints only. */
    public function captureLocked(int $courseId, int $studentId, int $remainingFactBudget = 1000): array
    {
        try {
            return $this->captureLockedUnsafe($courseId, $studentId, $remainingFactBudget);
        } catch (QueryException $e) {
            throw new RuntimeException('Ordinary-close activity source unavailable.');
        } catch (PDOException $e) {
            throw new RuntimeException('Ordinary-close activity source unavailable.');
        }
    }

    private function captureLockedUnsafe(int $courseId, int $studentId, int $remainingFactBudget): array
    {
        if (DB::transactionLevel() < 1 || $courseId < 1 || $studentId < 1
            || $remainingFactBudget < 0 || $remainingFactBudget > 1000) {
            throw new RuntimeException('Ordinary-close activity census unavailable.');
        }
        $this->reset($remainingFactBudget);
        $this->requireColumns('Student', ['id', 'CampusID']);
        $student = DB::table('Student')->select(['id', 'CampusID'])->where('id', $studentId)->lockForUpdate()->first();
        if (!$student || (int) $student->id !== $studentId || (int) $student->CampusID < 1) {
            throw new RuntimeException('Ordinary-close activity owner unavailable.');
        }
        $campusId = (int) $student->CampusID;

        // Admission locks all same-student parents in PK order before any child source.
        $this->requireColumns('StudentClass', ['ID', 'StudentID', 'trial_converted_to_id', 'trial_target_class_type', 'purchase_request_key']);
        $parents = DB::table('StudentClass')->where('StudentID', $studentId)->orderBy('ID')
            ->limit($remainingFactBudget + 2)->lockForUpdate()->get()->all();
        if (count($parents) > $remainingFactBudget + 1) throw new RuntimeException('Ordinary-close activity fact budget exceeded.');
        $parentIds = [];
        $target = null;
        foreach ($parents as $parent) {
            $id = (int) $parent->ID;
            if ($id < 1 || (int) $parent->StudentID !== $studentId) {
                throw new RuntimeException('Ordinary-close activity owner binding changed.');
            }
            $parentIds[] = $id;
            if ($id === $courseId) $target = $parent;
        }
        if (!$target || !in_array($courseId, $parentIds, true)) {
            throw new RuntimeException('Ordinary-close activity owner unavailable.');
        }
        $successors = array_values(array_filter($parents, static fn ($row) => (int) $row->ID !== $courseId));
        $successorIds = array_map(static fn ($row) => (int) $row->ID, $successors);
        $this->captureRows('StudentClass', 'successor_courses', 'ID', $successors);

        $this->requireColumns('ClassSession', ['id', 'StudentClassID']);
        $sessions = $this->boundedContext(DB::table('ClassSession')->where('StudentClassID', $courseId)
            ->orderBy('id')->lockForUpdate());
        $sessionIds = [];
        foreach ($sessions as $session) {
            if ((int) $session->StudentClassID !== $courseId || (int) $session->id < 1) {
                throw new RuntimeException('Ordinary-close activity session binding changed.');
            }
            $sessionIds[] = (int) $session->id;
        }

        $ledger = $this->selectOwned('session_deduction_ledger', 'id', 'student_class_id', $courseId, 'class_session_id', $sessionIds);
        foreach ($ledger as $row) {
            $this->positiveLink($row->student_class_id ?? null, $courseId, 'session_deduction_ledger');
            $this->sessionLink($row->class_session_id ?? null, $sessionIds, 'session_deduction_ledger');
        }
        $this->captureRows('session_deduction_ledger', 'deduction_events', 'id', $ledger);

        $this->requireColumns('StudentSingIn', ['StudentID', 'CampusID']);
        $signins = $this->selectOwned('StudentSingIn', 'id', 'StudentClassID', $courseId, 'ClassSessionID', $sessionIds);
        foreach ($signins as $row) {
            $this->positiveLink($row->StudentClassID ?? null, $courseId, 'StudentSingIn');
            if ((int) $row->StudentID !== $studentId || ($row->CampusID !== null && (int) $row->CampusID !== $campusId)) {
                throw new RuntimeException('Ordinary-close activity attendance binding changed.');
            }
            $this->sessionLink($row->ClassSessionID ?? null, $sessionIds, 'StudentSingIn');
        }
        $this->captureRows('StudentSingIn', 'sign_ins', 'id', $signins);

        $this->requireColumns('LearningRecord', ['StudentID']);
        $records = $this->selectOwned('LearningRecord', 'id', 'StudentClassID', $courseId, 'ClassSessionID', $sessionIds);
        foreach ($records as $row) {
            $this->positiveLink($row->StudentClassID ?? null, $courseId, 'LearningRecord');
            if ((int) $row->StudentID > 0 && (int) $row->StudentID !== $studentId) {
                throw new RuntimeException('Ordinary-close activity learning binding changed.');
            }
            $this->sessionLink($row->ClassSessionID ?? null, $sessionIds, 'LearningRecord');
        }
        $this->captureRows('LearningRecord', 'learning_records', 'id', $records);

        $this->requireColumns('schedules', ['id', 'student_course_id', 'student_id', 'branch_id', 'original_schedule_id']);
        $schedules = $this->bounded(DB::table('schedules')->where('student_course_id', $courseId)
            ->orderBy('id')->lockForUpdate(), 'schedules');
        $scheduleIds = array_map(static fn ($row) => (int) $row->id, $schedules);
        $scheduleMap = array_fill_keys($scheduleIds, true);
        foreach ($schedules as $row) {
            if ((int) $row->student_id !== $studentId || ((int) ($row->branch_id ?? $campusId) !== $campusId)) {
                throw new RuntimeException('Ordinary-close activity schedule binding changed.');
            }
            $original = (int) ($row->original_schedule_id ?? 0);
            if ($original > 0 && !isset($scheduleMap[$original])) {
                $anchor = DB::table('schedules')->select(['id', 'student_course_id', 'student_id'])
                    ->where('id', $original)->lockForUpdate()->first();
                if (!$anchor || (int) ($anchor->student_course_id ?? 0) !== $courseId
                    || (int) $anchor->student_id !== $studentId) {
                    throw new RuntimeException('Ordinary-close activity schedule lineage unsupported.');
                }
                throw new RuntimeException('Ordinary-close activity schedule lineage incomplete.');
            }
        }
        foreach ($schedules as $row) {
            $seen = [(int) $row->id => true];
            $parentId = (int) ($row->original_schedule_id ?? 0);
            while ($parentId > 0) {
                if (isset($seen[$parentId])) throw new RuntimeException('Ordinary-close activity schedule cycle.');
                $seen[$parentId] = true;
                $parentRow = $schedules[array_search($parentId, $scheduleIds, true)] ?? null;
                if (!$parentRow) throw new RuntimeException('Ordinary-close activity schedule lineage incomplete.');
                $parentId = (int) ($parentRow->original_schedule_id ?? 0);
            }
        }
        $this->captureRows('schedules', 'schedules', 'id', $schedules);

        $this->requireColumns('session_corrections', [
            'id', 'session_id', 'replaced_by_session_id', 'preserved_learning_record_id', 'keeper_learning_record_id',
        ]);
        $corrections = $sessionIds === [] ? [] : $this->bounded(DB::table('session_corrections')
            ->where(function ($q) use ($sessionIds) {
                $q->whereIn('session_id', $sessionIds)->orWhereIn('replaced_by_session_id', $sessionIds);
            })->orderBy('id')->lockForUpdate(), 'session_corrections');
        // The OR predicate retains both superseded-source and keeper-side history.
        $recordsById = [];
        foreach ($records as $record) $recordsById[(int) $record->id] = $record;
        foreach ($corrections as $row) {
            if ((int) $row->session_id < 1) throw new RuntimeException('Ordinary-close activity correction link unsupported.');
            $this->sessionLink($row->session_id ?? null, $sessionIds, 'session_corrections');
            $this->sessionLink($row->replaced_by_session_id ?? null, $sessionIds, 'session_corrections');
            foreach ([
                'preserved_learning_record_id' => (int) $row->session_id,
                'keeper_learning_record_id' => (int) ($row->replaced_by_session_id ?? 0),
            ] as $field => $expectedSessionId) {
                $lrId = (int) ($row->{$field} ?? 0);
                if ($lrId > 0 && ($expectedSessionId < 1 || !isset($recordsById[$lrId])
                    || (int) $recordsById[$lrId]->ClassSessionID !== $expectedSessionId)) {
                    throw new RuntimeException('Ordinary-close activity correction learning binding unsupported.');
                }
            }
        }
        $this->captureRows('session_corrections', 'session_corrections', 'id', $corrections);

        $this->requireColumns('course_contract_group_members', ['id', 'group_id', 'student_class_id']);
        $memberRows = $this->bounded(DB::table('course_contract_group_members')
            ->whereIn('student_class_id', array_merge([$courseId], $successorIds))->orderBy('id')->lockForUpdate(), 'course_contract_group_members');
        $groupIds = array_values(array_unique(array_map(static fn ($row) => (int) $row->group_id, $memberRows)));
        $this->requireColumns('course_contract_groups', ['id', 'student_id', 'campus_id']);
        $groups = $groupIds === [] ? [] : $this->bounded(DB::table('course_contract_groups')->whereIn('id', $groupIds)->orderBy('id')->lockForUpdate(), 'course_contract_groups');
        if (count($groups) !== count($groupIds)) throw new RuntimeException('Ordinary-close activity group link unsupported.');
        foreach ($groups as $group) {
            if ((int) $group->student_id !== $studentId || (int) $group->campus_id !== $campusId) {
                throw new RuntimeException('Ordinary-close activity group binding changed.');
            }
        }
        // Fetch all members of referenced groups so foreign members cannot be hidden by the seed selector.
        $allMembers = $groupIds === [] ? [] : $this->bounded(DB::table('course_contract_group_members')->whereIn('group_id', $groupIds)->orderBy('id')->lockForUpdate(), 'course_contract_group_members');
        foreach ($allMembers as $member) {
            if (!in_array((int) $member->student_class_id, $parentIds, true)) {
                throw new RuntimeException('Ordinary-close activity group member unsupported.');
            }
        }
        $this->captureRows('course_contract_group_members', 'group_memberships', 'id', $allMembers);
        $this->captureRows('course_contract_groups', 'contract_groups', 'id', $groups);

        $this->validateContinuationPointers($parents);
        return [
            'manifest_scope' => 'partial_activity',
            'owner_binding' => ['course_id' => $courseId, 'student_id' => $studentId, 'campus_id' => $campusId],
            'captured_sources' => array_keys($this->digests),
            'id_sets' => $this->ids,
            'table_digests' => $this->digests,
            'fact_count' => $this->factCount(),
            'context_ids' => ['session_ids' => $sessionIds, 'parent_ids' => $parentIds],
        ];
    }

    private function reset(int $budget): void { $this->ids = []; $this->digests = []; $this->budget = $budget; $this->encodedBytes = 0; }
    private function requireColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) throw new RuntimeException('Ordinary-close activity source unavailable.');
        foreach ($columns as $column) if (!Schema::hasColumn($table, $column)) throw new RuntimeException('Ordinary-close activity source incomplete.');
    }
    private function bounded($query, string $table): array
    {
        $rows = $query->limit($this->budget + 1)->get()->all();
        if (count($rows) > $this->budget) throw new RuntimeException('Ordinary-close activity fact budget exceeded.');
        return $rows;
    }
    private function boundedContext($query): array
    {
        $rows = $query->limit(1001)->get()->all();
        if (count($rows) > 1000) throw new RuntimeException('Ordinary-close activity context limit exceeded.');
        return $rows;
    }
    private function selectOwned(string $table, string $pk, string $ownerColumn, ?int $owner, string $sessionColumn, array $sessionIds): array
    {
        $this->requireColumns($table, [$pk, $ownerColumn, $sessionColumn]);
        return $this->bounded(DB::table($table)->where(function ($q) use ($ownerColumn, $owner, $sessionColumn, $sessionIds) {
            if ($owner !== null) $q->where($ownerColumn, $owner);
            if ($sessionIds !== []) $owner === null ? $q->whereIn($sessionColumn, $sessionIds) : $q->orWhereIn($sessionColumn, $sessionIds);
        })->orderBy($pk)->lockForUpdate(), $table);
    }
    private function captureRows(string $table, string $category, string $pk, array $rows): void
    {
        $this->requireColumns($table, [$pk]);
        $map = [];
        foreach ($rows as $row) {
            $data = (array) $row; $id = $data[$pk] ?? null;
            if (!is_numeric($id) || (int) $id < 1) throw new RuntimeException('Ordinary-close activity source identity invalid.');
            $identity = (string) (int) $id;
            $map[$identity] = $data;
        }
        ksort($map, SORT_NUMERIC);
        if (count($map) > $this->budget || $this->factCount() + count($map) > $this->budget) throw new RuntimeException('Ordinary-close activity fact budget exceeded.');
        $this->ids[$category] = array_map('intval', array_keys($map));
        $columns = $map === [] ? Schema::getColumnListing($table) : array_keys(reset($map));
        $payload = ['domain' => self::DOMAIN, 'table' => $table, 'role' => $category, 'columns' => $columns, 'rows' => array_values($map)];
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new RuntimeException('Ordinary-close activity evidence is malformed.');
        }
        $this->encodedBytes += strlen($json);
        if ($this->encodedBytes > self::MAX_BYTES) throw new RuntimeException('Ordinary-close activity evidence exceeds byte limit.');
        $this->digests[$category] = hash_hmac('sha256', $json, $this->key());
    }
    private function key(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) $key = base64_decode(substr($key, 7), true) ?: '';
        if (strlen($key) < 32) throw new RuntimeException('Ordinary-close activity key unavailable.');
        return $key;
    }
    private function factCount(): int { return array_sum(array_map('count', $this->ids)); }
    private function positiveLink($value, int $expected, string $source): void
    { if ((int) ($value ?? 0) > 0 && (int) $value !== $expected) throw new RuntimeException('Ordinary-close activity owner link unsupported.'); }
    private function sessionLink($value, array $sessionIds, string $source): void
    { if ((int) ($value ?? 0) > 0 && !in_array((int) $value, $sessionIds, true)) throw new RuntimeException('Ordinary-close activity session link unsupported.'); }
    private function validateContinuationPointers(array $parents): void
    {
        $ids = array_fill_keys(array_map(static fn ($row) => (int) $row->ID, $parents), true);
        $edges = [];
        foreach ($parents as $row) {
            $to = (int) ($row->trial_converted_to_id ?? 0);
            if ($to > 0 && !isset($ids[$to])) {
                throw new RuntimeException('Ordinary-close activity continuation unsupported.');
            }
            if ($to > 0) $edges[(int) $row->ID] = $to;
        }
        foreach (array_keys($edges) as $start) {
            $seen = [];
            $current = $start;
            while (isset($edges[$current])) {
                if (isset($seen[$current])) throw new RuntimeException('Ordinary-close activity continuation cycle.');
                $seen[$current] = true;
                $current = $edges[$current];
            }
        }
    }
}
