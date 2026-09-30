<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-account staff identity merge, PHASE 1: read-only candidates + dry-run plan (query-builder reads only).
 * Survivor S = teacher (type T), retired R = director (type D). Output is ids/counts only, never name/phone/LineID.
 * Missing optional tables/columns yield `skip table=<x> reason=missing`.
 */
final class StaffIdentityMergeService
{
    /** @var list<string> */
    private array $out = [];
    /** @var array<string, list<int|string>> */
    private array $fp = [];
    /** @var list<string> */
    private array $nogo = [];
    private int $s = 0;
    private int $r = 0;

    /** @return list<array{teacher:int,director:int,confidence:string,signals:list<string>}> */
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
        foreach ($users->where('type', 'T') as $t) {
            foreach ($users->where('type', 'D') as $d) {
                $kd = $keys($d);
                $hit = array_filter($keys($t), static fn ($v, $k) => $v !== '' && $v === $kd[$k], ARRAY_FILTER_USE_BOTH);
                if ($hit === []) {
                    continue;
                }
                $shared = array_intersect($campus[$t->id] ?? [], $campus[$d->id] ?? []) !== [];
                $has = static fn (string $k) => isset($hit[$k]);
                $conf = $has('login') || $has('line') || ($has('phone') && $shared) ? 'HIGH'
                    : (($has('name') && $shared) || $has('phone') ? 'MEDIUM' : 'LOW');
                $rows[] = ['teacher' => (int) $t->id, 'director' => (int) $d->id, 'confidence' => $conf,
                    'signals' => array_merge(array_keys($hit), $shared ? ['campus'] : [])];
            }
        }
        usort($rows, static fn ($a, $b) => [$a['teacher'], $a['director']] <=> [$b['teacher'], $b['director']]);
        return $rows;
    }

    /** Survivor S = teacher (type T), retired R = director (type D); S's teaching data is never touched. @return array{result:string,fingerprint:string,lines:list<string>} */
    public function plan(int $survivorId, int $retiredId, string $cutover): array
    {
        [$this->s, $this->r, $this->out, $this->fp, $this->nogo] = [$survivorId, $retiredId, [], [], []];

        $cols = ['id', 'type', 'status', 'LoginName'];
        $s = DB::table('User')->where('id', $survivorId)->first($cols);
        $r = DB::table('User')->where('id', $retiredId)->first($cols);
        $d = \DateTime::createFromFormat('!Y-m-d', $cutover);
        $checks = [
            'cutover-valid' => $d !== false && $d->format('Y-m-d') === $cutover,
            'distinct-users' => $survivorId !== $retiredId,
            'users-exist' => $s !== null && $r !== null,
            'survivor-is-teacher' => $s !== null && $s->type === 'T',
            'retired-is-director' => $r !== null && $r->type === 'D',
            'retired-not-super-admin' => $r !== null && $r->type !== 'S',
            'survivor-active' => $s !== null && !in_array($s->status, ['inactive', 'suspended'], true),
            'retired-not-already-inactive' => $r !== null && $r->status !== 'inactive',
        ];
        foreach ($checks as $name => $ok) {
            $this->out[] = "refuse-check {$name}=" . ($ok ? 'ok' : 'FAIL');
        }
        if (in_array(false, $checks, true) || $s === null || $r === null) {
            return $this->finish('REFUSED', 'none', $cutover);
        }

        $this->moves();
        $this->identity();
        $this->keeps();
        $same = strtolower(trim((string) $s->LoginName)) === strtolower(trim((string) $r->LoginName));
        $this->out[] = 'alias retired_login=' . ($same ? 'same' : 'different');
        $this->out[] = "disable user={$this->r} status={$r->status}->inactive";
        $this->selfApproval();
        foreach (['multi-role-flag-off' => !config('staff_capabilities.multi_role_v1_enabled'),
            'merge-journal-table-missing' => !Schema::hasTable('staff_identity_merges'),
            'phase1-read-only' => true] as $code => $bad) {
            $bad && $this->nogo[] = $code;
        }
        foreach (['User', 'UserCampus', 'user_capability_grants'] as $core) {
            Schema::hasTable($core) || $this->nogo[] = "schema-missing-{$core}";
        }
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

    /** R's open / forward-looking objects that would move to S. */
    private function moves(): void
    {
        $this->move('exception_workflows', 'owner_user_id', fn (Builder $q) => $q->whereNull('closed_at'), ['closed_at']);
        $this->move('admission_inquiries', 'assigned_to', fn (Builder $q) => $q->whereNotIn('status', ['enrolled', 'lost']), ['status']);
        $this->move('exception_workflow_candidates', 'teacher_id', fn (Builder $q) => $q->where('status', 'available')
            ->where('expires_at', '>', now()->toDateTimeString()), ['status', 'expires_at']);
        $this->move('NotificationReads', 'UserID', fn (Builder $q) => $q->whereNull('ReadAt'), ['ReadAt']);
        $this->move('bug_report_user_reads', 'user_id', fn (Builder $q) => $q);
        $this->move('user_notification_preferences', 'user_id', fn (Builder $q) => $q->whereNotExists(
            fn ($x) => $x->select(DB::raw(1))->from('user_notification_preferences as p')->where('p.user_id', $this->s)));
        if ($this->has('chat_thread_members', ['thread_id', 'user_id', 'left_at'])) {
            $side = fn (int $u) => DB::table('chat_thread_members')->where('user_id', $u)->whereNull('left_at')->pluck('thread_id')->map('intval')->all();
            $add = array_values(array_diff($side($this->r), $side($this->s)));
            sort($add);
            $this->fp['union.chat_thread_members'] = $add;
            $this->out[] = 'union table=chat_thread_members add=' . count($add);
        }
    }

    /**
     * @param callable(Builder):mixed $scope
     * @param list<string> $need
     */
    private function move(string $table, string $col, callable $scope, array $need = []): void
    {
        if (!$this->has($table, array_merge([$col, 'id'], $need))) {
            return;
        }
        $q = DB::table($table)->where($col, $this->r);
        $scope($q);
        $ids = $q->pluck('id')->map('intval')->sort()->values()->all();
        $this->fp["{$table}.{$col}"] = $ids;
        $this->out[] = "move table={$table} col={$col} count=" . count($ids) . ' sample=' . implode(',', array_slice($ids, 0, 20));
    }

    /** Director grants to create on S, campus union, RFID (copy only if S has none). */
    private function identity(): void
    {
        if (!$this->has('UserCampus', ['UserID', 'CampusID', 'Approved', 'RFID'])) {
            return;
        }
        $rows = fn (int $u) => DB::table('UserCampus')->where('UserID', $u)->when($u === $this->r, fn ($q) => $q->where('Approved', 1))
            ->get(['CampusID', 'RFID'])->keyBy('CampusID');
        $mine = $rows($this->s);
        $theirs = $rows($this->r);
        $add = $conflict = [];
        $move = 0;
        foreach ($theirs as $campus => $row) {
            $mine->has($campus) || $add[] = (int) $campus;
            $rf = trim((string) $row->RFID);
            $sf = trim((string) ($mine->get($campus)->RFID ?? ''));
            if ($rf !== '') {
                ($sf === '' || $sf === $rf) ? $move++ : $conflict[] = (int) $campus;
            }
        }
        $granted = $this->has('user_capability_grants', ['user_id', 'capability', 'campus_id', 'revoked_at'])
            ? DB::table('user_capability_grants')->where('user_id', $this->s)->where('capability', 'director')->whereNull('revoked_at')
                ->pluck('campus_id')->map('intval')->all() : [];
        $grant = array_values(array_diff($theirs->keys()->map('intval')->all(), $granted));
        sort($add);
        sort($grant);
        sort($conflict);
        $this->fp['union.UserCampus'] = $add;
        $this->fp['grant.director'] = $grant;
        $this->out[] = 'grant table=user_capability_grants create=director campuses=' . implode(',', $grant);
        $this->out[] = 'union table=UserCampus add_campuses=' . implode(',', $add) . " rfid_copy_rows={$move}";
        if ($conflict !== []) {
            $this->out[] = 'conflict rfid-collision campus_ids=' . implode(',', $conflict);
            $this->nogo[] = 'rfid-collision';
        }
        if ($this->has('auth_tokens', ['user_id'])) {
            $ids = DB::table('auth_tokens')->where('user_id', $this->r)->pluck('id')->map('intval')->sort()->values()->all();
            $this->fp['revoke.auth_tokens'] = $ids;
            $this->out[] = 'revoke auth_tokens count=' . count($ids);
        }
    }

    /** Historical actor columns stay on R's id (counts only; resolved through an alias later). */
    private function keeps(): void
    {
        foreach ([['fulltime_salary_profiles', 'approved_by'], ['teacher_payroll_deductions', 'hq_approved_by'], ['teacher_payroll_deductions', 'director_confirmed_by'],
            ['teacher_payroll_achievements', 'verified_by'], ['teacher_payroll_events', 'approved_by'], ['session_entitlement_transfers', 'decided_by_user_id'],
            ['session_corrections', 'decided_by_user_id'], ['exception_workflows', 'created_by_user_id'], ['payroll_audit_log', 'user_id'],
            ['chat_messages', 'sender_user_id'], ['chat_threads', 'created_by']] as [$t, $c]) {
            $this->has($t, [$c]) && $this->out[] = "keep table={$t} col={$c} count=" . DB::table($t)->where($c, $this->r)->count();
        }
    }

    /** After the merge S holds director grants, so pending approvals whose subject is S would be self-approvable. */
    private function selfApproval(): void
    {
        $n = 0;
        foreach (['fulltime_salary_profiles', 'teacher_payroll_deductions', 'teacher_payroll_achievements'] as $t) {
            $this->has($t, ['teacher_id', 'status']) && $n += DB::table($t)->where('teacher_id', $this->s)->where('status', 'pending')->count();
        }
        if ($n > 0) {
            $this->out[] = "conflict self-approval pending_rows={$n}";
            $this->nogo[] = 'pending-approvals-with-survivor-as-subject';
        }
    }
}
