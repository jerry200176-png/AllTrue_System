<?php

namespace App\Operations\Strategies;

use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Schedule;
use App\Models\ScheduleChangeLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * R-1 (design §5): where one occurrence has more than one live row, keep the row on the live ClassSession slot and
 * move the rest to `superseded` (reversible, logged `repair_supersede`). Anything ambiguous is quarantined untouched.
 */
final class Td076CollisionKeepersStrategy extends Td076OccurrenceRepair
{
    private const LIVE = ['scheduled', 'leave'];

    protected function ref(): string
    {
        return 'repair-td076-r1-collision-keepers-20261006';
    }

    protected function event(): string
    {
        return 'pop.td076_r1_collision_keepers';
    }

    protected function build(int $campusId): array
    {
        $rows = Schedule::query()->whereIn('status', self::LIVE)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '<>', 'extra'))
            ->whereIn('student_course_id', fn ($q) => $q->select('sc.ID')->from('StudentClass as sc')
                ->join('Student as s', 's.id', '=', 'sc.StudentID')->where('s.CampusID', $campusId))
            ->orderBy('id')->get()->keyBy(fn (Schedule $r) => (int) $r->id);
        $anchors = Schedule::query()->whereIn('id', $rows->pluck('original_schedule_id')->filter()->unique())->get()->keyBy('id');

        $actions = $quarantine = [];
        foreach ($this->components($rows) as $group) {
            [$keeper, $reason] = $this->keeper($group, $anchors);
            if (!$keeper) {
                $quarantine[] = ['reason' => $reason, 'schedule_ids' => $group->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all()];
                continue;
            }
            foreach ($group->reject(fn ($r) => (int) $r->id === (int) $keeper->id) as $loser) {
                $actions[] = ['type' => 'supersede', 'schedule_id' => (int) $loser->id, 'keeper_id' => (int) $keeper->id,
                    'from_status' => (string) $loser->status, 'teacher_id' => (int) $loser->teacher_id];
            }
        }
        usort($actions, fn ($a, $b) => $a['schedule_id'] <=> $b['schedule_id']);
        usort($quarantine, fn ($a, $b) => $a['schedule_ids'] <=> $b['schedule_ids']);

        return ['actions' => $actions, 'quarantine' => $quarantine];
    }

    /**
     * Rows sharing a frozen identity or a current slot, merged transitively (a row can be in both kinds of group).
     *
     * @param Collection<int, Schedule> $rows @return list<Collection<int, Schedule>>
     */
    private function components(Collection $rows): array
    {
        /** @var array<int,int> $parent */
        $parent = [];
        $seen = [];
        $find = function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $x = $parent[$x];
            }

            return $x;
        };
        foreach ($rows as $id => $row) {
            $parent[$id] = $id;
            $keys = ['s|' . $this->key($row, 'schedule_date', 'start_time')];
            if ($row->original_schedule_date && $row->original_start_time) {
                $keys[] = 'i|' . $this->key($row, 'original_schedule_date', 'original_start_time');
            }
            foreach ($keys as $k) {
                isset($seen[$k]) ? $parent[$find($id)] = $find($seen[$k]) : $seen[$k] = $id;
            }
        }
        $groups = [];
        foreach ($rows as $id => $row) {
            $groups[$find($id)][] = $id;
        }

        return array_values(array_map(fn ($ids) => $rows->only($ids), array_filter($groups, fn ($ids) => count($ids) > 1)));
    }

    private function key(Schedule $row, string $dateCol, string $timeCol): string
    {
        return $row->student_course_id . '|' . substr((string) $row->$dateCol, 0, 10) . '|' . substr((string) $row->$timeCol, 0, 5);
    }

    /**
     * @param Collection<int, Schedule> $group @param Collection<int, Schedule> $anchors
     * @return array{0: ?Schedule, 1: ?string} keeper, or the quarantine reason
     */
    private function keeper(Collection $group, Collection $anchors): array
    {
        if ($group->pluck('status')->unique()->count() > 1) {
            return [null, 'mixed_status'];
        }
        foreach ($group as $row) { // #3590 item 2: a frozen identity that is not the anchor's slot is the legacy shape
            $anchor = $anchors->get($row->original_schedule_id);
            if ($anchor && $row->original_schedule_date
                && $this->key($anchor, 'schedule_date', 'start_time') !== $this->key($row, 'original_schedule_date', 'original_start_time')) {
                return [null, 'identity_anchor_mismatch'];
            }
        }
        $course = (int) $group->first()->student_course_id;
        $dates = $group->map(fn ($r) => substr((string) $r->schedule_date, 0, 10))->unique()->all();
        $sessions = ClassSession::query()->where('StudentClassID', $course)->whereIn(DB::raw('DATE(SessionDate)'), $dates)->get()
            ->filter(fn ($s) => $group->contains(fn ($r) => $this->sameSlot($r, $s)));
        if ($sessions->count() !== 1) {
            return [null, $sessions->isEmpty() ? 'no_session' : 'multiple_sessions'];
        }
        $session = $sessions->first();
        $match = $group->filter(fn ($r) => $this->sameSlot($r, $session));
        if ($match->count() <= 1) {
            return [$match->first(), 'no_slot_match'];
        }
        $teachers = LearningRecord::query()->where('ClassSessionID', $session->id)->whereNull('VoidedAt')
            ->pluck('TeacherID')->map(fn ($t) => (int) $t)->filter()->unique();
        $pick = $teachers->count() === 1
            ? $match->filter(fn ($r) => $r->original_schedule_id && (int) $r->teacher_id === $teachers->first())->sortByDesc('id')->first()
            : null;

        return [$pick, 'teacher_conflict'];
    }

    private function sameSlot(Schedule $row, ClassSession $session): bool
    {
        return substr((string) $row->schedule_date, 0, 10) === substr((string) $session->SessionDate, 0, 10)
            && substr((string) $row->start_time, 0, 5) === substr((string) $session->StartTime, 0, 5);
    }

    protected function apply(array $actions): array
    {
        $entries = [];
        foreach ($actions as $a) {
            $row = Schedule::query()->whereKey($a['schedule_id'])->lockForUpdate()->first();
            if (!$row || $row->status !== $a['from_status']) {
                throw new \RuntimeException('td076_r1_drift_' . $a['schedule_id']);
            }
            $this->move($row, Schedule::STATUS_SUPERSEDED);
            $entries[] = ['id' => $a['schedule_id'], 'from_status' => $a['from_status']];
        }

        return $entries;
    }

    protected function undo(array $entry): bool
    {
        $row = Schedule::query()->whereKey($entry['id'])->lockForUpdate()->first();
        if (!$row || $row->status !== Schedule::STATUS_SUPERSEDED) {
            return false; // already moved on: leave it alone
        }
        $this->move($row, $entry['from_status']);

        return true;
    }

    private function move(Schedule $row, string $to): void
    {
        $from = (string) $row->status;
        $row->status = $to;
        $row->save();
        $date = substr((string) $row->schedule_date, 0, 10);
        $time = substr((string) $row->start_time, 0, 5);
        ScheduleChangeLog::query()->create([
            'schedule_id' => $row->id, 'student_course_id' => $row->student_course_id,
            'original_schedule_date' => $row->original_schedule_date, 'original_start_time' => $row->original_start_time,
            'from_date' => $date, 'from_time' => $time, 'to_date' => $date, 'to_time' => $time,
            'from_teacher_id' => $row->teacher_id, 'to_teacher_id' => $row->teacher_id,
            'from_status' => $from, 'to_status' => $to, 'reason' => 'repair_supersede', 'created_at' => now(),
        ]);
    }
}
