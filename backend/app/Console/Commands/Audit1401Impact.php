<?php

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\StudentLineBinding;
use App\Support\StudentContactPhone;
use Illuminate\Console\Command;

/**
 * Read-only privacy-incident impact audit for issue #1401.
 *
 * Answers whether the former LINE authorization weakness (fixed in PR
 * #1400) resulted in actual cross-family access or notification delivery,
 * using ONLY counts, booleans, timestamps, and one-way hashes. Never
 * reads or emits a student name, phone, LINE user ID, token, message
 * body, or raw row. Writes nothing — output is this command's stdout
 * only, which the calling workflow treats as the sole (ephemeral,
 * access-controlled) artifact.
 *
 * Fails closed: any query error surfaces as an explicit "unavailable"
 * line for that item rather than a silent zero, so "no evidence found"
 * is never confused with "couldn't check."
 */
class Audit1401Impact extends Command
{
    protected $signature = 'audit:1401-impact';
    protected $description = 'Read-only, PII-free impact audit for issue #1401 (parent-portal LINE cross-family exposure)';

    public function handle(): int
    {
        $this->line('=== #1401 impact audit — aggregate/hashed output only ===');
        $failedClosed = false;

        $failedClosed |= !$this->itemUnverifiedCounts();
        $failedClosed |= !$this->itemBindingWindow();
        $failedClosed |= !$this->itemCrossFamilyIdentities();
        $failedClosed |= !$this->itemSwitchAuditLog();
        $failedClosed |= !$this->itemNotificationDeliveryLog();

        $this->line('=== end ===');
        $this->line($failedClosed
            ? 'RESULT: one or more items unavailable/error — see lines above. Do not treat missing items as no-evidence-found.'
            : 'RESULT: all items produced a result. Overall confirmed-impact / no-evidence-found / insufficient-logs classification is a Founder judgment call across the items above, not auto-computed here.');

        return self::SUCCESS;
    }

    /** Item 1 + 2: unverified/legacy binding count, total and by campus_id only. */
    private function itemUnverifiedCounts(): bool
    {
        try {
            $total = StudentLineBinding::query()->whereNull('verified_at')->count();
            $this->line("1. unverified_binding_count_total\t{$total}");

            $byCampus = StudentLineBinding::query()->whereNull('verified_at')
                ->selectRaw('campus_id, count(*) as cnt')
                ->groupBy('campus_id')
                ->orderBy('campus_id')
                ->get();
            foreach ($byCampus as $row) {
                $this->line("2. unverified_binding_count_campus\t{$row->campus_id}\t{$row->cnt}");
            }
            if ($byCampus->isEmpty()) {
                $this->line('2. unverified_binding_count_campus\t(none)');
            }
            return true;
        } catch (\Throwable $e) {
            $this->line('1-2. UNAVAILABLE — ' . get_class($e));
            return false;
        }
    }

    /** Item 6: first/last bound_at among unverified bindings (data-derived exposure bound). */
    private function itemBindingWindow(): bool
    {
        try {
            $min = StudentLineBinding::query()->whereNull('verified_at')->min('bound_at');
            $max = StudentLineBinding::query()->whereNull('verified_at')->max('bound_at');
            $this->line('6. unverified_binding_bound_at_min\t' . ($min ?? 'null'));
            $this->line('6. unverified_binding_bound_at_max\t' . ($max ?? 'null'));
            $this->line('6. note: this is a lower bound from current data only. Git history independently shows the');
            $this->line('   vulnerable binding code existed from at least 2026-04-10 (repo sync commit 49880c80) to the');
            $this->line('   2026-07-24 fix (7e757c9b) -- ~3.5 months -- and may predate this repo\'s own git history.');
            return true;
        } catch (\Throwable $e) {
            $this->line('6. UNAVAILABLE — ' . get_class($e));
            return false;
        }
    }

    /**
     * Item 3: does any single LINE identity span more than one distinct
     * family? "Family" is approximated as the hashed, normalized
     * registered contact phone of each bound student -- never the phone
     * itself, never the line_user_id, never which students.
     */
    private function itemCrossFamilyIdentities(): bool
    {
        try {
            $rows = StudentLineBinding::query()
                ->select('line_user_id', 'student_id', 'verified_at')
                ->get()
                ->groupBy('line_user_id');

            $crossFamilyAll = 0;
            $crossFamilyVerifiedOnly = 0;

            foreach ($rows as $lineUserId => $bindings) {
                $studentIds = $bindings->pluck('student_id')->unique();
                $students = Student::query()->whereIn('id', $studentIds)->get(['id', 'parent_phone', 'Phone']);

                $familyHashesAll = $students->map(
                    fn ($s) => hash('sha256', StudentContactPhone::normalizedDigits($s))
                )->unique();
                if ($familyHashesAll->count() > 1) {
                    $crossFamilyAll++;
                }

                $verifiedStudentIds = $bindings->whereNotNull('verified_at')->pluck('student_id')->unique();
                $verifiedFamilyHashes = $students
                    ->whereIn('id', $verifiedStudentIds)
                    ->map(fn ($s) => hash('sha256', StudentContactPhone::normalizedDigits($s)))
                    ->unique();
                if ($verifiedFamilyHashes->count() > 1) {
                    $crossFamilyVerifiedOnly++;
                }
            }

            $this->line("3. line_identities_spanning_multiple_families_all_time\t{$crossFamilyAll}");
            $this->line("3. line_identities_spanning_multiple_families_verified_only\t{$crossFamilyVerifiedOnly}");
            $this->line('3. note: "family" = distinct hashed registered contact phone; a student with no');
            $this->line('   registered contact phone hashes to a constant empty-string digest and cannot be');
            $this->line('   distinguished from another such student by this method alone.');
            return true;
        } catch (\Throwable $e) {
            $this->line('3. UNAVAILABLE — ' . get_class($e));
            return false;
        }
    }

    /**
     * Item 4: was a per-switch audit trail ever recorded? Answered
     * structurally, not by searching log files this command has no
     * access to browse safely -- ParentSession has no history table and
     * switchStudent() has zero Log:: calls in the current codebase, so
     * this is a code-level fact, not a data query.
     */
    private function itemSwitchAuditLog(): bool
    {
        $this->line('4. switch_student_audit_trail_exists\tfalse');
        $this->line('4. note: ParentSession has no switch-history table, and switchStudent() has no Log:: call');
        $this->line('   in the current codebase -- there is no persisted record, allowed or forbidden, of any');
        $this->line('   historical switch-student attempt to check. This item is insufficient-logs by design,');
        $this->line('   not because a query returned zero rows.');
        return true;
    }

    /**
     * Item 5: can outbound notification delivery be tied to a specific
     * binding's verification status at send time? feedback_push_log is a
     * dedupe/merge-window log keyed by (feedback_id, direction) with no
     * binding_id column; SendTuitionReminders logs only a per-campus
     * count. Neither can answer this at binding granularity.
     */
    private function itemNotificationDeliveryLog(): bool
    {
        $this->line('5. notification_delivery_binding_granularity_available\tfalse');
        $this->line('5. note: feedback_push_log has no binding_id/line_user_id/verification-status column (it');
        $this->line('   is a send-dedupe log keyed by feedback_id+direction only); tuition_reminders_sent log');
        $this->line('   entries record only {campus_id, student_count} aggregates. Neither can determine,');
        $this->line('   after the fact, whether a specific historical send went through an unverified or');
        $this->line('   cross-family binding. This item is insufficient-logs by design.');
        return true;
    }
}
