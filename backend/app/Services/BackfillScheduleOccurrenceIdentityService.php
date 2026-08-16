<?php

namespace App\Services;

use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * TD-076 Phase 3: stamp frozen original slot on live schedule heads.
 * Default callers are dry-run. Execute does not touch extras, ghosts, billing, or ClassSession.
 */
final class BackfillScheduleOccurrenceIdentityService
{
    private const LIVE_STATUSES = ['scheduled', 'leave'];
    private const MAX_HOPS = 40;

    /** @return array<string, mixed> */
    public function plan(?int $studentCourseId = null): array
    {
        if (!Schema::hasColumn('schedules', 'original_schedule_date')) {
            return [
                'ok' => false,
                'blocked' => true,
                'reason' => 'MISSING_IDENTITY_COLUMNS',
                'candidates' => [],
                'collisions' => [],
                'superseded' => [],
                'extras_skipped' => 0,
                'already_ok' => 0,
                'drift' => [],
            ];
        }

        $rows = $this->loadRows($studentCourseId);
        $candidates = [];
        $superseded = [];
        $extrasSkipped = 0;
        $alreadyOk = 0;
        $drift = [];

        $byCourse = [];
        foreach ($rows as $row) {
            $byCourse[(int) $row->getAttribute('student_course_id')][] = $row;
        }
        foreach ($byCourse as $courseRows) {
            $courseCollection = collect($courseRows);
            $byId = $courseCollection->keyBy(fn (Schedule $row) => (int) $row->getKey());
            $slotIndex = $this->slotIndex($courseCollection);

            foreach ($courseRows as $row) {
                $type = strtolower(trim((string) $row->getAttribute('type')));
                if ($type === 'extra') {
                    $extrasSkipped++;
                    continue;
                }
                $status = strtolower(trim((string) $row->getAttribute('status')));
                if (!in_array($status, self::LIVE_STATUSES, true)) {
                    continue;
                }
                if ($this->isSuperseded($row, $slotIndex)) {
                    $superseded[] = $this->rowRef($row, null);
                    continue;
                }

                $identity = $this->computeIdentity($row, $byId, $slotIndex);
                $item = $this->rowRef($row, $identity);
                $existingDate = $row->getAttribute('original_schedule_date');
                $existingTime = $row->getAttribute('original_start_time');
                if ($existingDate && $existingTime) {
                    $haveDate = Carbon::parse((string) $existingDate)->toDateString();
                    $haveTime = substr((string) $existingTime, 0, 5);
                    if ($haveDate === $identity['date'] && $haveTime === $identity['time']) {
                        $alreadyOk++;
                        continue;
                    }
                    $drift[] = $item + ['existing_date' => $haveDate, 'existing_time' => $haveTime];
                    continue;
                }
                $candidates[] = $item;
            }
        }

        $collisions = $this->collisionGroups($candidates);
        $collisionIds = [];
        foreach ($collisions as $group) {
            foreach ($group['rows'] as $hit) {
                $collisionIds[(int) $hit['id']] = true;
            }
        }
        $stampable = array_values(array_filter(
            $candidates,
            fn (array $item) => !isset($collisionIds[(int) $item['id']])
        ));

        return [
            'ok' => $drift === [] && $collisions === [],
            'blocked' => false,
            'reason' => null,
            'candidates' => $stampable,
            'collisions' => $collisions,
            'superseded' => $superseded,
            'extras_skipped' => $extrasSkipped,
            'already_ok' => $alreadyOk,
            'drift' => $drift,
            'will_change' => ['schedules.original_schedule_date', 'schedules.original_start_time'],
            'will_not_change' => ['ClassSession', 'Invoice', 'Payment', 'schedule_change_log'],
        ];
    }

    /** @return array<string, mixed> */
    public function execute(?int $studentCourseId, string $snapshotPath): array
    {
        $plan = $this->plan($studentCourseId);
        if ($plan['blocked']) {
            throw new RuntimeException((string) $plan['reason']);
        }
        $this->writeSnapshot($snapshotPath, $plan, $studentCourseId);

        $stamped = [];
        foreach ($plan['candidates'] as $item) {
            $row = Schedule::query()->whereKey((int) $item['id'])->first();
            if (!$row) {
                continue;
            }
            $row->setAttribute('original_schedule_date', $item['identity_date']);
            $row->setAttribute('original_start_time', $item['identity_time']);
            $row->save();
            $stamped[] = (int) $item['id'];
        }

        return $plan + ['stamped_ids' => $stamped, 'stamped_count' => count($stamped)];
    }

    /** @return array<string, mixed> */
    public function rollback(string $snapshotPath): array
    {
        if (!File::exists($snapshotPath)) {
            throw new RuntimeException('SNAPSHOT_MISSING');
        }
        /** @var array<string, mixed> $snapshot */
        $snapshot = json_decode((string) File::get($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
        $restored = [];
        foreach ($snapshot['candidates'] ?? [] as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $row = Schedule::query()->whereKey($id)->first();
            if (!$row) {
                continue;
            }
            if ((string) $row->getAttribute('original_schedule_date') === (string) ($item['identity_date'] ?? '')
                && substr((string) $row->getAttribute('original_start_time'), 0, 5) === (string) ($item['identity_time'] ?? '')) {
                $row->setAttribute('original_schedule_date', null);
                $row->setAttribute('original_start_time', null);
                $row->save();
                $restored[] = $id;
            }
        }

        return ['ok' => true, 'restored_ids' => $restored, 'restored_count' => count($restored)];
    }

    /** @return Collection<int, Schedule> */
    private function loadRows(?int $studentCourseId): Collection
    {
        $query = Schedule::query()->orderBy('id');
        if ($studentCourseId !== null && $studentCourseId > 0) {
            $query->where('student_course_id', $studentCourseId);
        }

        return $query->get();
    }

    /**
     * @param Collection<int, Schedule> $courseRows
     * @return array<string, list<Schedule>>
     */
    private function slotIndex(Collection $courseRows): array
    {
        $index = [];
        foreach ($courseRows as $row) {
            $index[$this->slotKey($row)][] = $row;
        }

        return $index;
    }

    private function slotKey(Schedule $row): string
    {
        $date = $row->getAttribute('schedule_date')
            ? Carbon::parse((string) $row->getAttribute('schedule_date'))->toDateString()
            : '';
        $time = substr((string) $row->getAttribute('start_time'), 0, 5);

        return $date . '|' . $time;
    }

    /** @param array<string, list<Schedule>> $slotIndex */
    private function isSuperseded(Schedule $row, array $slotIndex): bool
    {
        $mates = $slotIndex[$this->slotKey($row)] ?? [];
        $selfId = (int) $row->getKey();
        foreach ($mates as $mate) {
            if ((int) $mate->getKey() === $selfId) {
                continue;
            }
            if (strtolower(trim((string) $mate->getAttribute('status'))) !== 'rescheduled') {
                continue;
            }
            if ((int) $mate->getKey() > $selfId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Collection<int, Schedule> $byId
     * @param array<string, list<Schedule>> $slotIndex
     * @return array{date: string, time: string}
     */
    private function computeIdentity(Schedule $row, Collection $byId, array $slotIndex): array
    {
        $current = $row;
        $seen = [];
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            $id = (int) $current->getKey();
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;

            $parentId = (int) ($current->getAttribute('original_schedule_id') ?? 0);
            if ($parentId > 0 && $byId->has($parentId) && !isset($seen[$parentId])) {
                $current = $byId->get($parentId);
                continue;
            }

            $advanced = false;
            foreach ($slotIndex[$this->slotKey($current)] ?? [] as $mate) {
                $mateId = (int) $mate->getKey();
                $mateParent = (int) ($mate->getAttribute('original_schedule_id') ?? 0);
                if ($mateId === $id || isset($seen[$mateId]) || $mateParent <= 0) {
                    continue;
                }
                $current = $mate;
                $advanced = true;
                break;
            }
            if ($advanced) {
                continue;
            }
            break;
        }

        $date = $current->getAttribute('schedule_date')
            ? Carbon::parse((string) $current->getAttribute('schedule_date'))->toDateString()
            : '';

        return [
            'date' => $date,
            'time' => substr((string) $current->getAttribute('start_time'), 0, 5),
        ];
    }

    /**
     * @param array{date: string, time: string}|null $identity
     * @return array<string, mixed>
     */
    private function rowRef(Schedule $row, ?array $identity): array
    {
        return [
            'id' => (int) $row->getKey(),
            'student_course_id' => (int) $row->getAttribute('student_course_id'),
            'status' => (string) $row->getAttribute('status'),
            'schedule_date' => $row->getAttribute('schedule_date')
                ? Carbon::parse((string) $row->getAttribute('schedule_date'))->toDateString()
                : null,
            'start_time' => substr((string) $row->getAttribute('start_time'), 0, 5),
            'identity_date' => $identity['date'] ?? null,
            'identity_time' => $identity['time'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function collisionGroups(array $candidates): array
    {
        $buckets = [];
        foreach ($candidates as $item) {
            $key = (int) $item['student_course_id'] . '|' . $item['identity_date'] . '|' . $item['identity_time'];
            $buckets[$key][] = $item;
        }
        $collisions = [];
        foreach ($buckets as $key => $group) {
            if (count($group) < 2) {
                continue;
            }
            $collisions[] = ['identity' => $key, 'rows' => $group];
        }

        return $collisions;
    }

    /** @param array<string, mixed> $plan */
    private function writeSnapshot(string $path, array $plan, ?int $studentCourseId): void
    {
        if (File::exists($path)) {
            throw new RuntimeException('SNAPSHOT_EXISTS');
        }
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !File::isDirectory($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
        File::put($path, json_encode([
            'command' => 'schedules:backfill-occurrence-identity',
            'captured_at' => now()->toIso8601String(),
            'student_course_id' => $studentCourseId,
            'candidates' => $plan['candidates'],
            'collisions' => $plan['collisions'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
