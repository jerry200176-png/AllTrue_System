<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-account staff identity merge, PHASE 1: read-only candidates + dry-run plan (query-builder reads only).
 * Survivor S = director (type D), retired R = teacher (type T). Output is ids/counts only, never name/phone/LineID.
 * Missing optional tables/columns yield `skip table=<x> reason=missing`.
 */
final class StaffIdentityMergeService
{
    private const OPEN = ['scheduled', 'leave_requested'];

    /** @var list<string> */
    private array $out = [];
    /** @var array<string, list<int|string>> */
    private array $fp = [];
    /** @var list<string> */
    private array $nogo = [];
    private int $s = 0;
    private int $r = 0;
    private string $cut = '';

    /** @return list<array{d:int,t:int,confidence:string,signals:list<string>,t_status:string}> */
    public function candidates(): array
    {
        $users = DB::table('User')->whereIn('type', ['D', 'T'])->get(['id', 'type', 'LoginName', 'Name', 'phone', 'LineID', 'status']);
        $campus = !Schema::hasTable('UserCampus') ? [] : DB::table('UserCampus')
            ->when(Schema::hasColumn('UserCampus', 'Approved'), fn ($w) => $w->where('Approved', 1))->get(['UserID', 'CampusID'])
            ->groupBy('UserID')->map(static fn ($g) => $g->pluck('CampusID')->map('intval')->all())->all();
        $keys = static function (object $u): array {
            $digits = (string) preg_replace('/\D+/', '', (string) $u->phone);
            return ['login' => strtolower(trim((string) $u->LoginName)), 'line' => trim((string) $u->LineID),
                'phone' => strlen($digits) >= 8 ? substr($digits, -9) : '', 'name' => trim((string) $u->Name)];
        };
        $rows = [];
        foreach ($users->where('type', 'D') as $d) {
            foreach ($users->where('type', 'T') as $t) {
                $kt = $keys($t);
                $hit = array_filter($keys($d), static fn ($v, $k) => $v !== '' && $v === $kt[$k], ARRAY_FILTER_USE_BOTH);
                if ($hit === []) {
                    continue;
                }
                $shared = array_intersect($campus[$d->id] ?? [], $campus[$t->id] ?? []) !== [];
                $has = static fn (string $k) => isset($hit[$k]);
                $conf = $has('login') || $has('line') || ($has('phone') && $shared) ? 'HIGH'
                    : (($has('name') && $shared) || $has('phone') ? 'MEDIUM' : 'LOW');
                $rows[] = ['d' => (int) $d->id, 't' => (int) $t->id, 'confidence' => $conf,
                    'signals' => array_merge(array_keys($hit), $shared ? ['campus'] : []), 't_status' => (string) ($t->status ?? 'active')];
            }
        }
        usort($rows, static fn ($a, $b) => [$a['d'], $a['t']] <=> [$b['d'], $b['t']]);
        return $rows;
    }

    /** @return array{result:string,fingerprint:string,lines:list<string>} */
    public function plan(int $survivorId, int $retiredId, string $cutover): array
    {
        [$this->s, $this->r, $this->cut, $this->out, $this->fp, $this->nogo] = [$survivorId, $retiredId, $cutover, [], [], []];

        $s = DB::table('User')->where('id', $survivorId)->first(['id', 'type', 'status', 'employment_type', 'LineID', 'phone']);
        $r = DB::table('User')->where('id', $retiredId)->first(['id', 'type', 'status', 'employment_type', 'LineID', 'phone']);
        $d = \DateTime::createFromFormat('!Y-m-d', $cutover);
        $checks = [
            'cutover-valid' => $d !== false && $d->format('Y-m-d') === $cutover,
            'distinct-users' => $survivorId !== $retiredId,
            'users-exist' => $s !== null && $r !== null,
            'survivor-is-director' => $s !== null && $s->type === 'D',
            'retired-is-teacher' => $r !== null && $r->type === 'T',
            'survivor-active' => $s !== null && !in_array($s->status, ['inactive', 'suspended'], true),
            'retired-not-already-inactive' => $r !== null && $r->status !== 'inactive',
        ];
        foreach ($checks as $name => $ok) {
            $this->out[] = "refuse-check {$name}=" . ($ok ? 'ok' : 'FAIL');
        }
        if (in_array(false, $checks, true) || $s === null || $r === null) {
            return $this->finish('REFUSED', 'none', $cutover);
        }

        $moved = $this->classes();
        $this->moves($moved);
        $this->copies();
        $this->identity($r);
        $this->conflicts($moved);
        foreach (['multi-role-flag-off' => !config('staff_capabilities.multi_role_v1_enabled'),
            'merge-journal-table-missing' => !Schema::hasTable('staff_identity_merges'),
            'scope-teachers-prerequisite-missing' => !method_exists(User::class, 'scopeTeachers')] as $code => $bad) {
            $bad && $this->nogo[] = $code;
        }
        foreach (['StudentClass', 'ClassSession', 'LearningRecord', 'UserCampus', 'user_capability_grants', 'User'] as $core) {
            Schema::hasTable($core) || $this->nogo[] = "schema-missing-{$core}";
        }
        $this->nogo[] = 'history-impact-not-computed'; // always NO-GO in phase 1: phase 2 adds history counts and a real GO path
        ksort($this->fp);
        $fingerprint = hash('sha256', (string) json_encode([$survivorId, $retiredId, $cutover, $this->fp]));
        return $this->finish('NO-GO', $fingerprint, $cutover);
    }

    /** @return array{result:string,fingerprint:string,lines:list<string>} */
    private function finish(string $result, string $fingerprint, string $cutover): array
    {
        $lines = array_merge(
            ["merge-plan survivor={$this->s} retired={$this->r} cutover={$cutover} plan_fingerprint={$fingerprint}"],
            $this->out,
            array_map(static fn ($n) => "nogo reason={$n}", array_values(array_unique($this->nogo))),
            ["merge-dry-run-result={$result}", 'READ_ONLY=true'],
        );
        return ['result' => $result, 'fingerprint' => $fingerprint, 'lines' => $lines];
    }

    /** @param list<string> $cols */
    private function has(string $table, array $cols = []): bool
    {
        $ok = Schema::hasTable($table) && Schema::hasColumns($table, $cols);
        $ok || $this->out[] = "skip table={$table} reason=missing";
        return $ok;
    }

    private function classes(): array
    {
        $ids = $this->move('StudentClass', 'TeacherID', function (Builder $q) {
            // Conservative reading: active + not closed + (not ended OR has a future session). Stopped/closed stay on R.
            $q->where(fn ($w) => $w->where('Stop', 0)->orWhereNull('Stop'))
                ->whereNull('closed_reason')
                ->where(fn ($w) => $w->whereNull('EndDate')->orWhere('EndDate', '>=', $this->cut)
                    ->orWhereExists(fn ($x) => $x->select(DB::raw(1))->from('ClassSession')
                        ->whereColumn('ClassSession.StudentClassID', 'StudentClass.ID')
                        ->where('SessionDate', '>=', $this->cut)->whereIn('Status', self::OPEN)));
        }, ['Stop', 'closed_reason', 'EndDate'], 'ID');
        return array_map('intval', $ids);
    }

    private function moves(array $moved): void
    {
        $c = $this->cut;
        $future = fn (Builder $q) => $q->whereExists(fn ($x) => $x->select(DB::raw(1))->from('ClassSession')
            ->whereColumn('ClassSession.id', 'LearningRecord.ClassSessionID')->where('SessionDate', '>=', $c));
        $this->move('schedules', 'teacher_id', fn (Builder $q) => $q->whereNotNull('original_schedule_id')->where('status', 'scheduled')
            ->where('schedule_date', '>=', $c), ['original_schedule_id', 'status', 'schedule_date'], 'id', 'substitute');
        $this->move('schedules', 'teacher_id', fn (Builder $q) => $q->whereNull('schedule_date')->where('status', 'scheduled')
            ->whereIn('student_course_id', $moved), ['status', 'schedule_date', 'student_course_id'], 'id', 'template');
        $this->move('LearningRecord', 'TeacherID', fn (Builder $q) => $future($q->where('Status', 'pending')), ['Status', 'ClassSessionID']);
        $this->move('truefit_lesson_preps', 'teacher_user_id', fn (Builder $q) => $q->where('session_date', '>=', $c), ['session_date']);
        $this->move('exception_workflows', 'owner_user_id', fn (Builder $q) => $q->whereNull('closed_at'), ['closed_at']);
        $this->move('exception_workflow_candidates', 'teacher_id', fn (Builder $q) => $q->where('status', 'available')
            ->where('expires_at', '>', now()->toDateTimeString()), ['status', 'expires_at']);
        $this->move('parent_feedback', 'teacher_id', fn (Builder $q) => $q->where('is_read', 0), ['is_read']);
        $this->move('admission_inquiries', 'assigned_to', fn (Builder $q) => $q->whereNotIn('status', ['enrolled', 'lost']), ['status']);
        $this->move('teacher_payroll_events', 'teacher_id', fn (Builder $q) => $q->where('event_date', '>=', $c), ['event_date']);
        foreach (['teacher_payroll_achievements', 'teacher_payroll_deductions'] as $t) {
            $this->move($t, 'teacher_id', fn (Builder $q) => $q->where('starts_on', '>=', $c), ['starts_on']);
        }
    }

    /**
     * @param callable(Builder):mixed $scope
     * @param list<string> $need
     * @return list<int|string>
     */
    private function move(string $table, string $col, callable $scope, array $need = [], string $pk = 'id', string $kind = ''): array
    {
        if (!$this->has($table, array_merge([$col, $pk], $need))) {
            return [];
        }
        $q = DB::table($table)->where($col, $this->r);
        $scope($q);
        $ids = $q->pluck($pk)->map('intval')->sort()->values()->all();
        $this->fp[$table . '.' . $col . ($kind !== '' ? ".{$kind}" : '')] = $ids;
        $this->out[] = "move table={$table} col={$col}" . ($kind !== '' ? " kind={$kind}" : '') . ' count=' . count($ids) . ' sample=' . implode(',', array_slice($ids, 0, 20));
        return $ids;
    }

    private function copies(): void
    {
        $this->copy('part_time_rate_cards', 'user_id', ['branch_id', 'class_size', 'session_type'], true);
        $this->copy('payroll_teacher_branch_rules', 'teacher_user_id', ['branch_id'], false);
        $this->copy('fulltime_salary_profiles', 'teacher_id', ['branch_id'], false);
        foreach ([['teacher_subjects', 'teacher_id', ['subject_id']], ['teacher_subject_levels', 'teacher_id', ['subject_id', 'level']],
            ['teacher_branches', 'teacher_id', ['branch_id']], ['chat_thread_members', 'user_id', ['thread_id']]] as [$t, $owner, $keys]) {
            if (!$this->has($t, array_merge([$owner], $keys))) {
                continue;
            }
            $side = fn (int $uid) => DB::table($t)->where($owner, $uid)
                ->when($t === 'chat_thread_members' && Schema::hasColumn($t, 'left_at'), fn ($q) => $q->whereNull('left_at'))
                ->get($keys)->map(static fn ($row) => implode(':', array_map(static fn ($k) => $row->$k, $keys)))->all();
            $add = array_values(array_diff($side($this->r), $side($this->s)));
            sort($add);
            $this->fp["union.{$t}"] = $add;
            $this->out[] = "union table={$t} add=" . count($add);
        }
    }

    /** @param list<string> $group */
    private function copy(string $table, string $owner, array $group, bool $until): void
    {
        if (!$this->has($table, array_merge([$owner, 'effective_from'], $group, $until ? ['effective_until'] : []))) {
            return;
        }
        $q = DB::table($table)->where($owner, $this->r)->where('effective_from', '<=', $this->cut);
        $q->when($until, fn ($w) => $w->where(fn ($x) => $x->whereNull('effective_until')->orWhere('effective_until', '>=', $this->cut)));
        $rows = $q->orderByDesc('effective_from')->orderByDesc('id')->get(array_merge(['id'], $group))
            ->unique(static fn ($row) => implode('|', array_map(static fn ($g) => $row->$g, $group)));
        $ids = $rows->pluck('id')->map('intval')->sort()->values()->all();
        $branches = $rows->pluck('branch_id')->filter()->map('intval')->unique()->values()->all();
        $this->fp["copy.{$table}"] = $ids;
        $this->out[] = "copy table={$table} src_ids=" . implode(',', $ids) . " effective_from={$this->cut}";
        // S row that still covers >= C in the same branch (ponytail: null branch_id and no-until tables use "any S row in branch").
        $hit = $branches === [] ? [] : DB::table($table)->where($owner, $this->s)->whereIn('branch_id', $branches)
            ->when($until, fn ($w) => $w->where(fn ($x) => $x->where('effective_from', '>=', $this->cut)
                ->orWhereNull('effective_until')->orWhere('effective_until', '>=', $this->cut)))
            ->distinct()->pluck('branch_id')->map('intval')->sort()->values()->all();
        if ($hit !== []) {
            $this->out[] = "conflict rate-overlap table={$table} branch_ids=" . implode(',', $hit);
            $this->nogo[] = 'rate-overlap';
        }
    }

    private function identity(object $r): void
    {
        if ($this->has('UserCampus', ['UserID', 'CampusID', 'Approved', 'RFID'])) {
            $rows = fn (int $u) => DB::table('UserCampus')->where('UserID', $u)->when($u === $this->r, fn ($q) => $q->where('Approved', 1))
                ->get(['CampusID', 'RFID'])->keyBy('CampusID');
            $mine = $rows($this->s);
            $add = $conflict = [];
            $move = 0;
            foreach ($rows($this->r) as $campus => $row) {
                $mine->has($campus) || $add[] = (int) $campus;
                $rf = trim((string) $row->RFID);
                $sf = trim((string) ($mine->get($campus)->RFID ?? ''));
                if ($rf !== '') {
                    ($sf === '' || $sf === $rf) ? $move++ : $conflict[] = (int) $campus;
                }
            }
            sort($add);
            sort($conflict);
            $this->fp['union.UserCampus'] = $add;
            $this->out[] = 'union table=UserCampus add_campuses=' . implode(',', $add) . " rfid_move_rows={$move}";
            if ($conflict !== []) {
                $this->out[] = 'conflict rfid-collision campus_ids=' . implode(',', $conflict);
                $this->nogo[] = 'rfid-collision';
            }
        }
        if ($this->has('auth_tokens', ['user_id'])) {
            $ids = DB::table('auth_tokens')->where('user_id', $this->r)->pluck('id')->map('intval')->sort()->values()->all();
            $this->fp['revoke.auth_tokens'] = $ids;
            $this->out[] = 'revoke auth_tokens count=' . count($ids);
        }
        $this->out[] = "disable user={$this->r} status={$r->status}->inactive";
    }

    private function conflicts(array $moved): void
    {
        if ($this->has('ClassSession', ['StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])) {
            $sessions = fn ($cond) => DB::table('ClassSession')->where('SessionDate', '>=', $this->cut)->whereIn('Status', self::OPEN)
                ->whereIn('StudentClassID', $cond)->orderBy('id')->get(['id', 'SessionDate', 'StartTime', 'EndTime']);
            $sideS = $sessions(DB::table('StudentClass')->where('TeacherID', $this->s)->pluck('ID'))->groupBy('SessionDate');
            $ra = $sb = [];
            foreach ($sessions($moved) as $a) {
                foreach ($sideS->get($a->SessionDate, []) as $b) {
                    if ($a->StartTime < $b->EndTime && $b->StartTime < $a->EndTime) {
                        $ra[(int) $a->id] = $sb[(int) $b->id] = true;
                    }
                }
            }
            if ($ra !== []) {
                $this->out[] = 'conflict slot-overlap retired_session_ids=' . implode(',', array_slice(array_keys($ra), 0, 20))
                    . ' survivor_session_ids=' . implode(',', array_slice(array_keys($sb), 0, 20));
                $this->nogo[] = 'slot-overlap';
            }
        }
        if ($this->has('LearningRecord', ['TeacherID', 'Status', 'ClassSessionID'])) {
            $n = DB::table('LearningRecord')->where('TeacherID', $this->r)->where('Status', 'pending')
                ->whereExists(fn ($x) => $x->select(DB::raw(1))->from('ClassSession')
                    ->whereColumn('ClassSession.id', 'LearningRecord.ClassSessionID')->where('SessionDate', '<', $this->cut))->count();
            if ($n > 0) {
                $this->out[] = "conflict pending-past-learning-records count={$n}";
                $this->nogo[] = 'retired-has-pending-past-learning-records';
            }
        }
        $campuses = $moved === [] || !$this->has('Student', ['CampusID']) ? [] : DB::table('StudentClass as sc')
            ->join('Student as s', 's.id', '=', 'sc.StudentID')->whereIn('sc.ID', $moved)
            ->distinct()->pluck('s.CampusID')->map('intval')->all();
        $grants = $this->has('user_capability_grants', ['user_id', 'capability', 'campus_id', 'revoked_at'])
            ? DB::table('user_capability_grants')->where('user_id', $this->s)->whereNull('revoked_at')->get(['capability', 'campus_id'])
                ->groupBy('capability')->map(static fn ($g) => $g->pluck('campus_id')->map('intval')->all())->all() : [];
        foreach (['director', 'teacher'] as $cap) {
            if (($grants[$cap] ?? []) === [] || array_diff($campuses, $grants[$cap]) !== []) {
                $this->nogo[] = "survivor-missing-{$cap}-grant";
            }
        }
    }
}
