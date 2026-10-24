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
        } catch (QueryException|PDOException $e) {
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
        if (!$student || $this->sqlId($student->id) !== $studentId || ($campusId = $this->sqlId($student->CampusID)) < 1) {
            throw new RuntimeException('Ordinary-close activity owner unavailable.');
        }

        // Admission locks all same-student parents in PK order before any child source.
        $this->requireColumns('StudentClass', ['ID', 'StudentID', 'trial_converted_to_id', 'trial_target_class_type', 'purchase_request_key']);
        $parents = DB::table('StudentClass')->where('StudentID', $studentId)->orderBy('ID')
            ->limit($remainingFactBudget + 2)->lockForUpdate()->get()->all();
        if (count($parents) > $remainingFactBudget + 1) throw new RuntimeException('Ordinary-close activity fact budget exceeded.');
        $parentIds = [];
        $target = null;
        foreach ($parents as $parent) {
            $id = $this->sqlId($parent->ID);
            if ($this->sqlId($parent->StudentID) !== $studentId) {
                throw new RuntimeException('Ordinary-close activity owner binding changed.');
            }
            $parentIds[] = $id;
            if ($id === $courseId) $target = $parent;
        }
        if (!$target) {
            throw new RuntimeException('Ordinary-close activity owner unavailable.');
        }
        $successors = array_values(array_filter($parents, fn ($row) => $this->sqlId($row->ID) !== $courseId));
        $successorIds = array_map(fn ($row) => $this->sqlId($row->ID), $successors);
        $this->captureRows('StudentClass', 'successor_courses', 'ID', $successors);

        $this->requireColumns('ClassSession', ['id', 'StudentClassID']);
        $sessions = $this->bounded(DB::table('ClassSession')->where('StudentClassID', $courseId)
            ->orderBy('id')->lockForUpdate(), 1000, true);
        $sessionIds = [];
        foreach ($sessions as $session) {
            if ($this->sqlId($session->StudentClassID) !== $courseId) {
                throw new RuntimeException('Ordinary-close activity session binding changed.');
            }
            $sessionIds[] = $this->sqlId($session->id);
        }

        $ledger = $this->selectOwned('session_deduction_ledger', 'id', 'student_class_id', $courseId, 'class_session_id', $sessionIds);
        foreach ($ledger as $row) {
            $this->assertLink($row->student_class_id ?? null, $courseId, 'Ordinary-close activity owner link unsupported.');
            $this->assertLink($row->class_session_id ?? null, $sessionIds, 'Ordinary-close activity session link unsupported.');
        }
        $this->captureRows('session_deduction_ledger', 'deduction_events', 'id', $ledger);

        $this->requireColumns('StudentSingIn', ['StudentID', 'CampusID']);
        $signins = $this->selectOwned('StudentSingIn', 'id', 'StudentClassID', $courseId, 'ClassSessionID', $sessionIds);
        foreach ($signins as $row) {
            $this->assertLink($row->StudentClassID ?? null, $courseId, 'Ordinary-close activity owner link unsupported.');
            if ($this->sqlId($row->StudentID) !== $studentId
                || ($row->CampusID !== null && $this->sqlId($row->CampusID) !== $campusId)) {
                throw new RuntimeException('Ordinary-close activity attendance binding changed.');
            }
            $this->assertLink($row->ClassSessionID ?? null, $sessionIds, 'Ordinary-close activity session link unsupported.');
        }
        $this->captureRows('StudentSingIn', 'sign_ins', 'id', $signins);

        $this->requireColumns('LearningRecord', ['StudentID']);
        $records = $this->selectOwned('LearningRecord', 'id', 'StudentClassID', $courseId, 'ClassSessionID', $sessionIds);
        foreach ($records as $row) {
            $this->assertLink($row->StudentClassID ?? null, $courseId, 'Ordinary-close activity owner link unsupported.');
            $recordStudentId = $this->sqlId($row->StudentID, true);
            if ($recordStudentId > 0 && $recordStudentId !== $studentId) {
                throw new RuntimeException('Ordinary-close activity learning binding changed.');
            }
            $this->assertLink($row->ClassSessionID ?? null, $sessionIds, 'Ordinary-close activity session link unsupported.');
        }
        $this->captureRows('LearningRecord', 'learning_records', 'id', $records);

        $this->requireColumns('schedules', ['id', 'student_course_id', 'student_id', 'branch_id', 'original_schedule_id']);
        $schedules = $this->bounded(DB::table('schedules')->where('student_course_id', $courseId)
            ->orderBy('id')->lockForUpdate(), $this->budget);
        $scheduleIds = array_map(fn ($row) => $this->sqlId($row->id), $schedules);
        $scheduleMap = array_fill_keys($scheduleIds, true);
        foreach ($schedules as $row) {
            if ($this->sqlId($row->student_id) !== $studentId || $this->sqlId($row->branch_id) !== $campusId) {
                throw new RuntimeException('Ordinary-close activity schedule binding changed.');
            }
            $original = $this->sqlId($row->original_schedule_id, true);
            if ($original > 0 && !isset($scheduleMap[$original])) {
                $anchor = DB::table('schedules')->select(['id', 'student_course_id', 'student_id'])
                    ->where('id', $original)->lockForUpdate()->first();
                if (!$anchor || $this->sqlId($anchor->student_course_id, true) !== $courseId
                    || $this->sqlId($anchor->student_id) !== $studentId) {
                    throw new RuntimeException('Ordinary-close activity schedule lineage unsupported.');
                }
                throw new RuntimeException('Ordinary-close activity schedule lineage incomplete.');
            }
        }
        foreach ($schedules as $row) {
            $seen = [$this->sqlId($row->id) => true];
            $parentId = $this->sqlId($row->original_schedule_id, true);
            while ($parentId > 0) {
                if (isset($seen[$parentId])) throw new RuntimeException('Ordinary-close activity schedule cycle.');
                $seen[$parentId] = true;
                $parentRow = $schedules[array_search($parentId, $scheduleIds, true)] ?? null;
                if (!$parentRow) throw new RuntimeException('Ordinary-close activity schedule lineage incomplete.');
                $parentId = $this->sqlId($parentRow->original_schedule_id, true);
            }
        }
        $this->captureRows('schedules', 'schedules', 'id', $schedules);

        $this->requireColumns('session_corrections', [
            'id', 'session_id', 'replaced_by_session_id', 'preserved_learning_record_id', 'keeper_learning_record_id',
        ]);
        $corrections = $sessionIds === [] ? [] : $this->bounded(DB::table('session_corrections')
            ->where(function ($q) use ($sessionIds) {
                $q->whereIn('session_id', $sessionIds)->orWhereIn('replaced_by_session_id', $sessionIds);
            })->orderBy('id')->lockForUpdate(), $this->budget);
        // The OR predicate retains both superseded-source and keeper-side history.
        $recordsById = [];
        foreach ($records as $record) $recordsById[$this->sqlId($record->id)] = $record;
        foreach ($corrections as $row) {
            $sourceSessionId = $this->sqlId($row->session_id);
            $keeperSessionId = $this->sqlId($row->replaced_by_session_id, true);
            $this->assertLink($row->session_id ?? null, $sessionIds, 'Ordinary-close activity session link unsupported.');
            $this->assertLink($row->replaced_by_session_id ?? null, $sessionIds, 'Ordinary-close activity session link unsupported.');
            foreach ([
                'preserved_learning_record_id' => $sourceSessionId,
                'keeper_learning_record_id' => $keeperSessionId,
            ] as $field => $expectedSessionId) {
                $lrId = $this->sqlId($row->{$field}, true);
                if ($lrId > 0 && ($expectedSessionId < 1 || !isset($recordsById[$lrId])
                    || $this->sqlId($recordsById[$lrId]->ClassSessionID) !== $expectedSessionId)) {
                    throw new RuntimeException('Ordinary-close activity correction learning binding unsupported.');
                }
            }
        }
        $this->captureRows('session_corrections', 'session_corrections', 'id', $corrections);

        $this->requireColumns('course_contract_group_members', ['id', 'group_id', 'student_class_id']);
        $memberRows = $this->bounded(DB::table('course_contract_group_members')
            ->whereIn('student_class_id', array_merge([$courseId], $successorIds))->orderBy('id')->lockForUpdate(), $this->budget);
        $groupIds = array_values(array_unique(array_map(fn ($row) => $this->sqlId($row->group_id), $memberRows)));
        $this->requireColumns('course_contract_groups', ['id', 'student_id', 'campus_id']);
        $groups = $groupIds === [] ? [] : $this->bounded(DB::table('course_contract_groups')->whereIn('id', $groupIds)->orderBy('id')->lockForUpdate(), $this->budget);
        if (count($groups) !== count($groupIds)) throw new RuntimeException('Ordinary-close activity group link unsupported.');
        foreach ($groups as $group) {
            if ($this->sqlId($group->student_id) !== $studentId || $this->sqlId($group->campus_id) !== $campusId) {
                throw new RuntimeException('Ordinary-close activity group binding changed.');
            }
        }
        // Fetch all members of referenced groups so foreign members cannot be hidden by the seed selector.
        $allMembers = $groupIds === [] ? [] : $this->bounded(DB::table('course_contract_group_members')->whereIn('group_id', $groupIds)->orderBy('id')->lockForUpdate(), $this->budget);
        foreach ($allMembers as $member) {
            if (!in_array($this->sqlId($member->student_class_id), $parentIds, true)) {
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
    private function bounded($query, int $limit, bool $context = false): array
    {
        $rows = $query->limit($limit + 1)->get()->all();
        if (count($rows) > $limit) throw new RuntimeException($context ? 'Ordinary-close activity context limit exceeded.' : 'Ordinary-close activity fact budget exceeded.');
        return $rows;
    }
    private function selectOwned(string $table, string $pk, string $ownerColumn, ?int $owner, string $sessionColumn, array $sessionIds): array
    {
        $this->requireColumns($table, [$pk, $ownerColumn, $sessionColumn]);
        return $this->bounded(DB::table($table)->where(function ($q) use ($ownerColumn, $owner, $sessionColumn, $sessionIds) {
            if ($owner !== null) $q->where($ownerColumn, $owner);
            if ($sessionIds !== []) $owner === null ? $q->whereIn($sessionColumn, $sessionIds) : $q->orWhereIn($sessionColumn, $sessionIds);
        })->orderBy($pk)->lockForUpdate(), $this->budget);
    }
    private function captureRows(string $table, string $category, string $pk, array $rows): void
    {
        $map = [];
        foreach ($rows as $row) {
            $data = (array) $row; $id = $data[$pk] ?? null;
            $identity = (string) $this->sqlId($id);
            $map[$identity] = $data;
        }
        ksort($map, SORT_NUMERIC);
        if (count($map) > $this->budget || $this->factCount() + count($map) > $this->budget) throw new RuntimeException('Ordinary-close activity fact budget exceeded.');
        $this->ids[$category] = array_map(static fn ($id) => (int) $id, array_keys($map));
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
    private function assertLink($value, int|array $expected, string $message): void
    {
        $id = $this->sqlId($value, true);
        if ($id > 0 && (is_array($expected) ? !in_array($id, $expected, true) : $id !== $expected)) throw new RuntimeException($message);
    }
    private function sqlId(mixed $value, bool $nullable = false): int
    {
        if ($nullable && ($value === null || $value === 0 || $value === '0')) return 0;
        $id = is_int($value) ? $value : (is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false);
        if (!is_int($id) || $id < 1 || (string) $id !== (string) $value) {
            throw new RuntimeException('Ordinary-close activity identity unsupported.');
        }
        return $id;
    }
    private function validateContinuationPointers(array $parents): void
    {
        $ids = array_fill_keys(array_map(fn ($row) => $this->sqlId($row->ID), $parents), true);
        $edges = [];
        foreach ($parents as $row) {
            $to = $this->sqlId($row->trial_converted_to_id, true);
            if ($to > 0 && !isset($ids[$to])) {
                throw new RuntimeException('Ordinary-close activity continuation unsupported.');
            }
            if ($to > 0) $edges[$this->sqlId($row->ID)] = $to;
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
