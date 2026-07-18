<?php

namespace App\Console\Commands;

use App\Models\SessionCorrection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #173 FD1b C: merge eval fields LR#8883 → LR#9959. Dry-run default.
 * Prod: --execute --force + ALLOW_PROD_REPAIR=1. No LR create/delete/FK move; no billing/counters.
 */
class RepairMergeRenewalLearningRecord extends Command
{
    protected $signature = 'repair:merge-renewal-learning-record
                            {--case=173} {--dry-run} {--execute} {--force} {--snapshot=}
                            {--actor=} {--actor-user-id=} {--rollback} {--verify}';

    protected $description = 'Merge superseded LR eval into keeper LR (#173 FD1b C)';

    private const C = [
        'src_sid' => 11292, 'kep_sid' => 16951, 'src_lr' => 8883, 'kep_lr' => 9959,
        'old_sc' => 114, 'new_sc' => 2076, 'teacher' => 67, 'date' => '2026-06-10', 'start' => '19:00',
        'reason' => 'lr_eval_merge_from_superseded', 'ref' => 'in-app #173 LR-MERGE-C',
        'manifest' => 'RM-173-LR-MERGE-C-2026-07-18', 'b_ref' => 'in-app #173',
    ];

    private const MERGEABLE = [
        'Content', 'AttachmentUrl', 'HomeworkStatus', 'QuizScore', 'Progress',
        'NextHomework', 'NextWeekTestScope', 'Performance', 'Comment', 'Subject', 'ReviewNote',
    ];

    /** StudentClassController backfill stub — not teacher-authored content. */
    private const SYS_EMPTY = ['（系統補登加課）', '系統補登加課'];

    public function handle(): int
    {
        if ((string) $this->option('case') !== '173') {
            $this->error('--case supports only 173');

            return self::FAILURE;
        }
        if (!Schema::hasTable('session_corrections')) {
            $this->error('session_corrections missing');

            return self::FAILURE;
        }
        if ($this->option('verify')) {
            return $this->verifyOnly();
        }
        if ($this->option('rollback')) {
            return $this->rollback();
        }

        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }

        $plan = $this->plan();
        if ($plan['error']) {
            $this->error($plan['error']);
            if (!empty($plan['conflicts'])) {
                $this->line('CONFLICTS: ' . json_encode($plan['conflicts'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            return self::FAILURE;
        }

        $this->line($execute ? '=== EXECUTE repair:merge-renewal-learning-record ===' : '=== DRY RUN repair:merge-renewal-learning-record ===');
        $this->printPlan($plan);

        if (($plan['action']['type'] ?? '') === 'noop') {
            $this->info('ALREADY_APPLIED correction_id=' . $plan['action']['correction_id']);

            return self::SUCCESS;
        }
        if (!$execute) {
            $this->info('Dry-run complete; no production data was changed.');

            return self::SUCCESS;
        }

        $path = $this->option('snapshot') ?: storage_path('app/repair-snapshots/173-lr-merge-' . now()->format('YmdHis') . '.json');
        $this->dumpSnapshot($path, $plan);
        $this->info("Snapshot: {$path}");
        DB::transaction(fn () => $this->apply($plan));
        $this->info('Applied LR merge 8883→9959');

        if ($this->verifyOnly() !== self::SUCCESS) {
            $this->error('REPAIR_STATE=APPLIED_POSTVERIFY_FAILED — do NOT re-run execute; inspect session_corrections');

            return 42;
        }
        $this->info('REPAIR_STATE=APPLIED_AND_VERIFIED');

        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function plan(): array
    {
        $c = self::C;
        $srcCs = DB::table('ClassSession')->where('id', $c['src_sid'])->first();
        $kepCs = DB::table('ClassSession')->where('id', $c['kep_sid'])->first();
        $srcLr = DB::table('LearningRecord')->where('id', $c['src_lr'])->first();
        $kepLr = DB::table('LearningRecord')->where('id', $c['kep_lr'])->first();
        $oldSc = DB::table('StudentClass')->where('ID', $c['old_sc'])->first();
        $newSc = DB::table('StudentClass')->where('ID', $c['new_sc'])->first();
        if (!$srcCs || !$kepCs || !$srcLr || !$kepLr || !$oldSc || !$newSc) {
            return ['error' => 'required rows missing', 'action' => null, 'conflicts' => []];
        }
        if ($err = $this->assertSameLesson($srcCs, $kepCs, $srcLr, $kepLr, $oldSc, $newSc)) {
            return ['error' => $err, 'action' => null, 'conflicts' => []];
        }

        $bCorr = SessionCorrection::query()->where('session_id', $c['src_sid'])
            ->where('decision_reference', $c['b_ref'])->whereNull('rolled_back_at')->orderByDesc('id')->first();
        if (!$bCorr || (int) $bCorr->replaced_by_session_id !== $c['kep_sid']) {
            return ['error' => 'Option B supersede correction missing', 'action' => null, 'conflicts' => []];
        }
        if (strtolower((string) $srcCs->Status) !== 'cancelled') {
            return ['error' => 'source session must be cancelled', 'action' => null, 'conflicts' => []];
        }

        $open = SessionCorrection::query()->where('decision_reference', $c['ref'])
            ->whereNull('rolled_back_at')->orderByDesc('id')->first();
        if ($open) {
            return [
                'error' => null, 'action' => ['type' => 'noop', 'correction_id' => $open->id],
                'will_change' => [], 'will_not_change' => $this->unchanged(), 'merge' => ['fill' => [], 'keep' => []],
                'children' => $this->children(), 'projection' => [], 'rollback_payload' => [],
                'snapshot' => compact('srcCs', 'kepCs', 'srcLr', 'kepLr', 'oldSc', 'newSc', 'bCorr', 'open'), 'conflicts' => [],
            ];
        }

        if ((int) DB::table('LearningRecord')->where('ClassSessionID', $c['kep_sid'])->whereNull('VoidedAt')->count() !== 1) {
            return ['error' => 'keeper must have exactly 1 active LR', 'action' => null, 'conflicts' => []];
        }

        $fill = [];
        $keep = [];
        $conflicts = [];
        foreach (self::MERGEABLE as $f) {
            if (!Schema::hasColumn('LearningRecord', $f)) {
                continue;
            }
            $sv = $srcLr->{$f} ?? null;
            $kv = $kepLr->{$f} ?? null;
            $se = $this->empty($f, $sv);
            $ke = $this->empty($f, $kv);
            if (!$se && $ke) {
                $fill[$f] = $sv;
            } elseif ($se) {
                $keep[$f] = $kv;
            } elseif ($this->norm($sv) === $this->norm($kv)) {
                $keep[$f] = $kv;
            } else {
                $conflicts[$f] = ['source' => $sv, 'keeper' => $kv];
            }
        }
        if ($conflicts) {
            return ['error' => 'FIELD_CONFLICT — stop; Founder Decision required', 'action' => null, 'conflicts' => $conflicts, 'merge' => compact('fill', 'keep', 'conflicts')];
        }
        if (!$fill) {
            return ['error' => 'nothing to merge', 'action' => null, 'conflicts' => []];
        }

        $children = $this->children();
        if ($children['src_tc'] || $children['src_fb']) {
            return ['error' => 'CHILD_RECORDS_PRESENT — stop; no auto-merge for comments/feedbacks', 'action' => null, 'conflicts' => [], 'children' => $children];
        }

        $pre = $this->hash($kepLr);
        $postObj = (object) array_merge((array) $kepLr, $fill);
        $action = [
            'type' => 'lr_merge', 'manifest' => $c['manifest'], 'restored_from_lr_id' => $c['src_lr'],
            'keeper_lr_id' => $c['kep_lr'], 'restored_fields' => array_keys($fill), 'updates' => $fill,
            'pre_hash' => $pre, 'post_hash' => $this->hash($postObj),
            'correction_reason' => $c['reason'], 'decision_reference' => $c['ref'],
        ];
        $after = [];
        foreach (self::MERGEABLE as $f) {
            if (Schema::hasColumn('LearningRecord', $f)) {
                $after[$f] = $fill[$f] ?? ($kepLr->{$f} ?? null);
            }
        }

        return [
            'error' => null, 'action' => $action,
            'will_change' => ['LearningRecord' => ['id' => $c['kep_lr'], 'fields' => $fill]],
            'will_not_change' => $this->unchanged(),
            'merge' => ['fill' => $fill, 'keep' => $keep, 'conflicts' => []],
            'children' => $children,
            'projection' => ['user_visible_after' => $after],
            'rollback_payload' => ['keeper_lr_id' => $c['kep_lr'], 'restore_fields' => array_intersect_key((array) $kepLr, $fill)],
            'snapshot' => compact('srcCs', 'kepCs', 'srcLr', 'kepLr', 'oldSc', 'newSc', 'bCorr'),
            'conflicts' => [],
        ];
    }

    private function assertSameLesson($srcCs, $kepCs, $srcLr, $kepLr, $oldSc, $newSc): ?string
    {
        $c = self::C;
        if ((int) $srcLr->ClassSessionID !== $c['src_sid'] || (int) $kepLr->ClassSessionID !== $c['kep_sid']) {
            return 'LR↔session mismatch';
        }
        if ((int) $srcLr->StudentClassID !== $c['old_sc'] || (int) $kepLr->StudentClassID !== $c['new_sc']) {
            return 'LR↔SC mismatch';
        }
        if ((int) $srcCs->StudentClassID !== $c['old_sc'] || (int) $kepCs->StudentClassID !== $c['new_sc']) {
            return 'session↔SC mismatch';
        }
        if ((int) $oldSc->StudentID !== (int) $newSc->StudentID) {
            return 'StudentID mismatch';
        }
        if ((int) $oldSc->TeacherID !== $c['teacher'] || (int) $newSc->TeacherID !== $c['teacher']) {
            return 'TeacherID mismatch';
        }
        if ((int) $srcLr->TeacherID !== $c['teacher'] || (int) $kepLr->TeacherID !== $c['teacher']) {
            return 'LR TeacherID mismatch';
        }
        if ((int) $oldSc->SubjectID !== (int) $newSc->SubjectID) {
            return 'SubjectID mismatch';
        }
        $ds = substr((string) $srcCs->SessionDate, 0, 10);
        $dk = substr((string) $kepCs->SessionDate, 0, 10);
        $hs = substr((string) $srcCs->StartTime, 0, 5);
        $hk = substr((string) $kepCs->StartTime, 0, 5);
        if ($ds !== $c['date'] || $dk !== $c['date'] || $hs !== $c['start'] || $hk !== $c['start']) {
            return "slot mismatch {$ds} {$hs} vs {$dk} {$hk}";
        }
        if ($srcLr->VoidedAt !== null || $kepLr->VoidedAt !== null) {
            return 'voided LR';
        }

        return null;
    }

    private function empty(string $field, $value): bool
    {
        if ($value === null) {
            return true;
        }
        $s = is_string($value) ? trim($value) : (string) $value;
        if ($s === '') {
            return true;
        }

        return $field === 'Content' && in_array($s, self::SYS_EMPTY, true);
    }

    private function norm($v): string
    {
        return is_string($v) ? trim($v) : (string) $v;
    }

    private function hash($lr): string
    {
        $p = [];
        foreach (self::MERGEABLE as $f) {
            $p[$f] = $lr->{$f} ?? null;
        }

        return hash('sha256', json_encode($p, JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,int> */
    private function children(): array
    {
        $c = self::C;
        $o = ['src_tc' => 0, 'kep_tc' => 0, 'src_fb' => 0, 'kep_fb' => 0];
        if (Schema::hasTable('learning_record_teacher_comments')) {
            $o['src_tc'] = (int) DB::table('learning_record_teacher_comments')->where('learning_record_id', $c['src_lr'])->count();
            $o['kep_tc'] = (int) DB::table('learning_record_teacher_comments')->where('learning_record_id', $c['kep_lr'])->count();
        }
        if (Schema::hasTable('learning_record_feedbacks')) {
            $o['src_fb'] = (int) DB::table('learning_record_feedbacks')->where('learning_record_id', $c['src_lr'])->count();
            $o['kep_fb'] = (int) DB::table('learning_record_feedbacks')->where('learning_record_id', $c['kep_lr'])->count();
        }

        return $o;
    }

    /** @return array<string,string> */
    private function unchanged(): array
    {
        return [
            'source_lr' => 'LR#8883 retained (no DELETE/void/FK move)',
            'keys' => 'id/SC/CS/TeacherID/timestamps/author/SessionDeducted untouched',
            'counters' => 'Used/Remaining untouched',
            'billing' => 'Invoice/Payment untouched',
            'sessions' => 'ClassSession untouched',
        ];
    }

    /** @param array<string,mixed> $plan */
    private function printPlan(array $plan): void
    {
        $a = $plan['action'] ?? [];
        if (($a['type'] ?? '') === 'noop') {
            $this->line('ALREADY_APPLIED correction_id=' . $a['correction_id']);
        } else {
            $this->line('manifest=' . self::C['manifest']);
            $this->line('fill_fields=' . implode(',', $a['restored_fields'] ?? []));
            $this->line('pre_hash=' . ($a['pre_hash'] ?? ''));
            $this->line('post_hash=' . ($a['post_hash'] ?? ''));
        }
        $this->line('--- MERGE FILL ---');
        $this->line(json_encode($plan['merge']['fill'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('--- MERGE KEEP ---');
        $this->line(json_encode($plan['merge']['keep'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('--- CHILDREN ---');
        $this->line(json_encode($plan['children'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->line('--- USER PROJECTION AFTER ---');
        $this->line(json_encode($plan['projection']['user_visible_after'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->line('--- ROLLBACK PAYLOAD ---');
        $this->line(json_encode($plan['rollback_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (!empty($plan['snapshot']['oldSc'])) {
            $o = $plan['snapshot']['oldSc'];
            $n = $plan['snapshot']['newSc'];
            $this->line(sprintf(
                'COUNTERS (must stay): SC%d Used=%s Rem=%s | SC%d Used=%s Rem=%s | SessionDeducted LR8883=%s LR9959=%s',
                (int) $o->ID, $o->UsedSessions, $o->RemainingSessions,
                (int) $n->ID, $n->UsedSessions, $n->RemainingSessions,
                $plan['snapshot']['srcLr']->SessionDeducted ?? 'n/a',
                $plan['snapshot']['kepLr']->SessionDeducted ?? 'n/a'
            ));
        }
    }

    /** @param array<string,mixed> $plan */
    private function apply(array $plan): void
    {
        $c = self::C;
        $a = $plan['action'];
        $id = (int) $a['keeper_lr_id'];
        $row = DB::table('LearningRecord')->where('id', $id)->lockForUpdate()->first();
        if (!$row) {
            throw new \RuntimeException("LR {$id} missing");
        }
        if ($this->hash($row) !== $a['pre_hash']) {
            throw new \RuntimeException('keeper pre_hash drift');
        }
        $updates = $a['updates'];
        $updates['updated_at'] = now();
        DB::table('LearningRecord')->where('id', $id)->update($updates);

        $uid = $this->option('actor-user-id');
        $corr = new SessionCorrection([
            'session_id' => $c['src_sid'],
            'replaced_by_session_id' => $c['kep_sid'],
            'correction_reason' => $c['reason'],
            'decision_reference' => $c['ref'],
            'decided_at' => now(),
            'decided_by_user_id' => ($uid !== null && $uid !== '') ? (int) $uid : null,
            'decided_by_actor' => (string) ($this->option('actor') ?: 'artisan:repair:merge-renewal-learning-record'),
            'previous_status' => (string) ($plan['snapshot']['srcCs']->Status ?? 'cancelled'),
            'new_status' => (string) ($plan['snapshot']['srcCs']->Status ?? 'cancelled'),
            'preserved_learning_record_id' => $c['src_lr'],
            'keeper_learning_record_id' => $c['kep_lr'],
            'snapshot_before' => [
                'manifest' => $c['manifest'],
                'restored_from_lr_id' => $c['src_lr'],
                'restored_fields' => $a['restored_fields'],
                'pre_hash' => $a['pre_hash'],
                'post_hash' => $a['post_hash'],
                'keeper_before' => (array) $plan['snapshot']['kepLr'],
                'source_lr' => (array) $plan['snapshot']['srcLr'],
                'rollback_fields' => $plan['rollback_payload']['restore_fields'] ?? [],
                'reason' => 'FD1b C; system Content stub treated empty',
            ],
        ]);
        $corr->save();
    }

    private function rollback(): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && !$this->prodOk()) {
            return self::FAILURE;
        }
        $c = self::C;
        $corr = SessionCorrection::query()->where('decision_reference', $c['ref'])
            ->whereNull('rolled_back_at')->orderByDesc('id')->first();
        if (!$corr) {
            $this->error('No open LR-MERGE-C correction');

            return self::FAILURE;
        }
        $fields = $corr->snapshot_before['rollback_fields'] ?? [];
        $this->line($execute ? '=== EXECUTE ROLLBACK LR-MERGE-C ===' : '=== DRY RUN ROLLBACK LR-MERGE-C ===');
        $this->line('correction_id=' . $corr->id . ' restore=' . json_encode($fields, JSON_UNESCAPED_UNICODE));
        if (!$execute) {
            return self::SUCCESS;
        }
        DB::transaction(function () use ($corr, $fields, $c): void {
            if ($fields) {
                $fields['updated_at'] = now();
                DB::table('LearningRecord')->where('id', $c['kep_lr'])->update($fields);
            }
            $corr->rolled_back_at = now();
            $corr->save();
        });
        $this->info('Rollback ok correction_id=' . $corr->id);

        return self::SUCCESS;
    }

    private function verifyOnly(): int
    {
        $c = self::C;
        $kep = DB::table('LearningRecord')->where('id', $c['kep_lr'])->first();
        $src = DB::table('LearningRecord')->where('id', $c['src_lr'])->first();
        $corr = SessionCorrection::query()->where('decision_reference', $c['ref'])
            ->whereNull('rolled_back_at')->orderByDesc('id')->first();
        if (!$kep || !$src || !$corr) {
            $this->error('VERIFY_FAIL missing rows');

            return self::FAILURE;
        }
        foreach ($corr->snapshot_before['restored_fields'] ?? [] as $f) {
            if ($this->empty($f, $kep->{$f} ?? null)) {
                $this->error("VERIFY_FAIL keeper.{$f} empty");

                return self::FAILURE;
            }
        }
        if ((int) $src->ClassSessionID !== $c['src_sid'] || $src->VoidedAt !== null) {
            $this->error('VERIFY_FAIL source integrity');

            return self::FAILURE;
        }
        $this->line('VERIFY_OK correction_id=' . $corr->id);

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $plan */
    private function dumpSnapshot(string $path, array $plan): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, json_encode([
            'generated_at' => now()->toIso8601String(),
            'manifest' => self::C['manifest'],
            'action' => $plan['action'],
            'merge' => $plan['merge'] ?? null,
            'projection' => $plan['projection'] ?? null,
            'rollback_payload' => $plan['rollback_payload'] ?? null,
            'before' => json_decode(json_encode($plan['snapshot'] ?? null), true),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    }

    private function prodOk(): bool
    {
        if (!app()->environment('production')) {
            return true;
        }
        if (!$this->option('force')) {
            $this->error('Production requires --force');

            return false;
        }
        if (env('ALLOW_PROD_REPAIR') !== '1') {
            $this->error('Production requires ALLOW_PROD_REPAIR=1');

            return false;
        }

        return true;
    }
}
