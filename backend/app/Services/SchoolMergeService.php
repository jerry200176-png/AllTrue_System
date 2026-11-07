<?php

namespace App\Services;

use App\Models\SchoolExamPeriod;
use App\Models\SecurityAuditEvent;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 學校整理 (#517): rename school spellings to one canonical name in one transaction.
 * Audit + undo data live in security_audit_events (180-day retention), no new table.
 */
class SchoolMergeService
{
    public const APPLIED = 'school.merge.applied';
    public const UNDONE = 'school.merge.undone';

    public function __construct(private SchoolNameGrouper $grouper)
    {
    }

    /**
     * @param  list<string>  $variants
     * @return array{request_id:string,students_updated:int,periods_renamed:int,periods_skipped:int}
     */
    public function merge(int $campusId, int $actorId, string $canonical, array $variants): array
    {
        return DB::transaction(function () use ($campusId, $actorId, $canonical, $variants) {
            $this->lockCampus($campusId);
            $students = Student::query()->where('CampusID', $campusId)->where('enable', 1)
                ->whereIn(DB::raw('TRIM(SchoolName)'), $variants)->whereRaw('BINARY SchoolName != ?', [$canonical])
                ->lockForUpdate()->get(['id', 'SchoolName']);
            $s = [];
            foreach ($students as $row) {
                $s[(string) $row->SchoolName][] = (int) $row->id;
            }
            [$p, $skipped] = $this->renamePeriods($campusId, $canonical, $variants, $students->pluck('id')->all());
            if ($s === [] && $p === []) {
                throw new SchoolMergeException('沒有需要整理的學生');
            }
            Student::query()->whereIn('id', $students->pluck('id'))->update(['SchoolName' => $canonical]);

            $payload = json_encode(['s' => $s, 'p' => $p], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($payload) > (int) config('audit.restore_payload_max', SecurityAuditEvent::LONG_METADATA['restore_payload'])) {
                throw new SchoolMergeException('一次整理的學生太多，請分批');
            }
            $requestId = (string) Str::uuid();
            SecurityAuditEvent::append(self::APPLIED, 'success', [
                'campus_id' => $campusId, 'actor_type' => 'user', 'actor_id' => $actorId, 'correlation_id' => $requestId,
            ], [
                'canonical_name' => $canonical, 'student_count' => $students->count(), 'restore_payload' => $payload,
            ], true);

            return [
                'request_id' => $requestId, 'students_updated' => $students->count(),
                'periods_renamed' => array_sum(array_map('count', $p)), 'periods_skipped' => $skipped,
            ];
        });
    }

    /** @return array{students_restored:int,periods_restored:int}|null null = unknown request for this campus */
    public function undo(int $campusId, int $actorId, string $requestId): ?array
    {
        return DB::transaction(function () use ($campusId, $actorId, $requestId) {
            $this->lockCampus($campusId);
            $event = DB::table('security_audit_events')->where('event_type', self::APPLIED)
                ->where('correlation_id', $requestId)->where('campus_id', $campusId)->lockForUpdate()->first();
            if (!$event) {
                return null;
            }
            if ($event->retention_until && now()->gt($event->retention_until)) {
                throw new SchoolMergeException('已超過 180 天，無法復原');
            }
            if (DB::table('security_audit_events')->where('event_type', self::UNDONE)->where('correlation_id', $requestId)->exists()) {
                throw new SchoolMergeException('這次整理已經復原過了');
            }
            $meta = json_decode((string) $event->metadata, true) ?: [];
            $canonical = (string) ($meta['canonical_name'] ?? '');
            $payload = json_decode((string) ($meta['restore_payload'] ?? ''), true) ?: [];
            $this->assertNewestFirst($event, $payload);

            $students = 0;
            foreach ($payload['s'] ?? [] as $old => $ids) {
                // Only rows still carrying the canonical name: later manual edits win.
                $students += Student::query()->where('CampusID', $campusId)->whereIn('id', $ids)
                    ->where('SchoolName', $canonical)->update(['SchoolName' => (string) $old]);
            }
            $periods = 0;
            foreach ($payload['p'] ?? [] as $old => $ids) {
                foreach (SchoolExamPeriod::query()->where('campus_id', $campusId)->whereIn('id', $ids)
                    ->where('school_name', $canonical)->lockForUpdate()->get() as $period) {
                    if (!$this->conflicts($period, (string) $old)) {
                        $periods += SchoolExamPeriod::query()->whereKey($period->id)->where('school_name', $canonical)
                            ->update(['school_name' => (string) $old]);
                    }
                }
            }
            if ($students + $periods === 0 && ($payload['s'] ?? []) + ($payload['p'] ?? []) !== []) {
                // Nothing restorable (rows edited since): don't burn the undo.
                throw new SchoolMergeException('這些資料之後已被修改，無法復原', 409);
            }
            SecurityAuditEvent::append(self::UNDONE, 'success', [
                'campus_id' => $campusId, 'actor_type' => 'user', 'actor_id' => $actorId, 'correlation_id' => $requestId,
            ], ['canonical_name' => $canonical, 'student_count' => $students], true);

            return ['students_restored' => $students, 'periods_restored' => $periods];
        });
    }

    /** Serialize all merges/undos of one campus: the newest-first check and the writes must not interleave. Take first. */
    private function lockCampus(int $campusId): void
    {
        DB::table('Campus')->where('id', $campusId)->lockForUpdate()->first();
    }

    /** LIFO: a later, still-undoable merge that touched the same rows must be undone first (else this undo is wasted and can't be redone). */
    private function assertNewestFirst(object $event, array $payload): void
    {
        $undone = DB::table('security_audit_events')->where('event_type', self::UNDONE)
            ->where('campus_id', $event->campus_id)->pluck('correlation_id')->all();
        $ids = fn (array $p, string $k) => array_merge([], ...array_values($p[$k] ?? []));
        $mine = ['s' => $ids($payload, 's'), 'p' => $ids($payload, 'p')];
        $later = DB::table('security_audit_events')->where('event_type', self::APPLIED)->where('campus_id', $event->campus_id)
            ->where('id', '>', $event->id)->where('retention_until', '>', now())->whereNotIn('correlation_id', $undone)->get(['metadata']);
        foreach ($later as $row) {
            $theirs = json_decode((string) (json_decode((string) $row->metadata, true)['restore_payload'] ?? ''), true) ?: [];
            foreach (['s', 'p'] as $k) {
                if (array_intersect($mine[$k], $ids($theirs, $k)) !== []) {
                    throw new SchoolMergeException('請先復原較新的合併', 409);
                }
            }
        }
    }

    /** @return list<array{request_id:string,occurred_at:string,canonical_name:string,student_count:int}> */
    public function history(int $campusId): array
    {
        $undone = DB::table('security_audit_events')->where('event_type', self::UNDONE)
            ->where('campus_id', $campusId)->pluck('correlation_id')->all();

        return DB::table('security_audit_events')->where('event_type', self::APPLIED)->where('campus_id', $campusId)
            ->where('retention_until', '>', now())->whereNotIn('correlation_id', $undone)
            ->orderByDesc('id')->limit(20)->get(['correlation_id', 'occurred_at', 'metadata'])
            ->map(function ($r) {
                $m = json_decode((string) $r->metadata, true) ?: [];

                return [
                    'request_id' => (string) $r->correlation_id, 'occurred_at' => (string) $r->occurred_at,
                    'canonical_name' => (string) ($m['canonical_name'] ?? ''), 'student_count' => (int) ($m['student_count'] ?? 0),
                ];
            })->all();
    }

    /** @return array{0: array<string,list<int>>, 1: int} renamed period ids by old name, skipped count */
    private function renamePeriods(int $campusId, string $canonical, array $variants, array $mergedIds): array
    {
        $renamed = [];
        $skipped = 0;
        // Periods are stored as SchoolNameGrouper::display(), so match by school identity, not raw spelling.
        $keys = array_map(fn ($v) => $this->grouper->key($v), $variants);
        $periods = SchoolExamPeriod::query()->where('campus_id', $campusId)->lockForUpdate()->orderBy('id')->get()
            ->filter(fn ($p) => $p->school_name !== $canonical && in_array($this->grouper->key($p->school_name), $keys, true));
        // A period is shared by every spelling of its school: only move it when no student outside the merge uses it.
        // By student id (SQL DISTINCT/collation would fold U+3000 and ASCII spaces together), keyed in PHP.
        $staying = Student::query()->where('CampusID', $campusId)->where('enable', 1)->whereNotNull('SchoolName')
            ->get(['id', 'SchoolName'])
            ->reject(fn ($st) => in_array((int) $st->id, $mergedIds, true) || (string) $st->SchoolName === $canonical)
            ->map(fn ($st) => $this->grouper->key((string) $st->SchoolName))->unique()->all();
        foreach ($periods as $period) {
            if (in_array($this->grouper->key($period->school_name), $staying, true) || $this->conflicts($period, $canonical)) {
                $skipped++;
                continue;
            }
            $old = (string) $period->school_name;
            $period->update(['school_name' => $canonical]);
            $renamed[$old][] = (int) $period->id;
        }

        return [$renamed, $skipped];
    }

    /** Another period in the same campus/term/seq/grade scope already holds this school identity. */
    private function conflicts(SchoolExamPeriod $period, string $name): bool
    {
        $key = $this->grouper->key($name);

        return SchoolExamPeriod::query()->where('campus_id', $period->campus_id)
            ->where('academic_year', $period->academic_year)->where('semester', $period->semester)
            ->where('seq', $period->seq)->where('grade_key', $period->grade_key)->where('id', '!=', $period->id)
            ->pluck('school_name')->contains(fn ($n) => $n === $name || $this->grouper->key($n) === $key);
    }
}
