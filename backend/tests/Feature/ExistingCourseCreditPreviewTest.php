<?php

namespace Tests\Feature;

use App\Models\{ClassSession, Invoice, InvoiceItem, LearningRecord, Student, StudentClass};
use App\Services\Billing\ExistingCourseCreditPreview;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Tests\Support\SubjectCreditFixtures;
use Tests\TestCase;

class ExistingCourseCreditPreviewTest extends TestCase
{
    use RefreshDatabase, SubjectCreditFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-30 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_fully_reserved_paid_header_funds_two_existing_used_targets_only_after_exact_releases(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $before = $this->businessSnapshot();
        $result = $this->preview($source, $targets, $ids);
        $this->assertSame(3, $result['plan']['source_sessions']);
        $this->assertSame(2, $result['plan']['source_used']);
        $this->assertSame(1, $result['plan']['source_remaining']);
        $this->assertSame(4500, $result['plan']['source_amount']);
        $this->assertSame([1, 4], array_column($result['plan']['allocations'], 'sessions'));
        $this->assertSame([1, 3], array_column($result['plan']['allocations'], 'used'));
        $this->assertSame([1500, 6000], array_column($result['plan']['allocations'], 'amount'));
        $this->assertSame($before, $this->businessSnapshot(), 'Preview never cancels reservations, pays a bill or rewrites taught history.');
        $this->assertSame($result['state_hash'], $this->preview($source, array_reverse($targets), array_reverse($ids))['state_hash']);
    }

    public function test_readonly_proposal_displays_the_exact_minimal_tail_and_matches_explicit_confirmation_without_writes(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $campus = (int) Student::findOrFail($source->StudentID)->CampusID;
        $before = $this->businessSnapshot();
        $proposal = app(ExistingCourseCreditPreview::class)->propose($source, ['target_course_ids' => $targets], [$campus]);
        $this->assertSame($ids, $proposal['plan']['released_session_ids']);
        $this->assertSame($this->preview($source, $targets, $ids), $proposal, 'Proposal and explicit confirmation share the same final state hash and complete eligibility checks.');
        $this->assertSame(5, count($proposal['released_lessons']));
        $this->assertFalse($proposal['execution_available']);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertRefused($source, $targets, [], 'existing_credit_release_invalid');
        try {
            app(ExistingCourseCreditPreview::class)->propose($source, ['target_course_ids' => $targets], []);
            $this->fail('A readonly proposal cannot grant campus access.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_missing_excess_foreign_and_now_referenced_releases_are_rejected_without_writes(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        foreach ([[], array_slice($ids, 1), [...$ids, 999999]] as $bad) {
            $this->assertRefused($source, $targets, $bad, 'existing_credit_release_invalid');
        }
        LearningRecord::create(['ClassSessionID' => $ids[0], 'StudentClassID' => $source->ID,
            'TeacherID' => $source->TeacherID, 'SessionDate' => '2026-10-02', 'Content' => '', 'Subject' => 'synthetic',
            'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'pending']);
        $this->assertRefused($source, $targets, $ids, 'existing_credit_release_invalid');
    }

    public function test_any_existing_target_bill_or_payment_report_blocks_new_funding(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $target = StudentClass::findOrFail($targets[1]);
        $bill = Invoice::create(['StudentID' => $source->StudentID, 'StudentClassID' => $target->ID,
            'IssueDate' => '2026-09-01', 'TotalAmount' => 6000, 'PaidAmount' => 0, 'Status' => 'unpaid']);
        $this->assertRefused($source, $targets, $ids, 'existing_credit_target_funded');
        $bill->delete();
        DB::table('payment_reports')->insert(['StudentID' => $source->StudentID, 'StudentClassID' => $target->ID,
            'reported_amount' => 6000, 'reported_by_name' => 'isolated reporter', 'payment_date' => '2026-09-01',
            'report_token_hash' => hash('sha256', 'allocation-report'), 'token_expires_at' => now()->addDay(),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertRefused($source, $targets, $ids, 'existing_credit_target_funded');
    }

    public function test_target_usage_counter_drift_and_different_unit_are_not_silently_repaired(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $target = StudentClass::findOrFail($targets[1]);
        $target->forceFill(['UsedSessions' => 0, 'RemainingSessions' => 4])->save();
        $this->assertRefused($source, $targets, $ids, 'existing_credit_usage_unclear');
        $target->forceFill(['UsedSessions' => 3, 'RemainingSessions' => 1, 'SessionDuration' => 60])->save();
        $this->assertRefused($source, $targets, $ids, 'subject_credit_units_differ');
    }

    public function test_empty_campus_scope_cannot_use_a_valid_allocation_preview(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ExistingCourseCreditPreview::class)->run($source, ['target_course_ids' => $targets, 'released_session_ids' => $ids], []);
    }

    public static function unassignedReleaseModes(): array { return [['run'], ['propose']]; }

    #[\PHPUnit\Framework\Attributes\DataProvider('unassignedReleaseModes')]
    public function test_unassigned_source_teacher_cannot_offer_unverified_calendar_releases(string $mode): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $source->forceFill(['TeacherID' => 0])->save();
        $campus = (int) Student::findOrFail($source->StudentID)->CampusID;
        $before = $this->businessSnapshot();
        $input = ['target_course_ids' => $targets] + ($mode === 'run' ? ['released_session_ids' => $ids] : []);
        try {
            app(ExistingCourseCreditPreview::class)->{$mode}($source, $input, [$campus]);
            $this->fail('A release without verifiable native teacher/room metadata must not be offered.');
        } catch (HttpResponseException $e) {
            $this->assertSame('existing_credit_calendar_unverified', $e->getResponse()->getData(true)['code']);
        }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_legacy_new_batch_mode_still_refuses_fully_reserved_source_even_with_extra_release_input(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $before = $this->businessSnapshot();
        try {
            app(\App\Services\Billing\SubjectCreditPreview::class)->run($source,
                ['target_course_id' => $targets[0], 'sessions' => 1, 'released_session_ids' => $ids], null);
            $this->fail('The original new-batch flow must never implicitly release a reservation.');
        } catch (HttpResponseException $exception) {
            $this->assertSame('subject_credit_unavailable', $exception->getResponse()->getData(true)['code']);
        }
        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_completed_target_can_be_funded_but_settlement_locked_or_foreign_targets_cannot(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        $target = StudentClass::findOrFail($targets[0]);
        $target->forceFill(['Stop' => 1, 'closed_reason' => 'completed'])->save();
        $this->assertSame(1, $this->preview($source, $targets, $ids)['plan']['allocations'][0]['used']);
        $target->forceFill(['settlement_locked_at' => now()])->save();
        $this->assertRefused($source, $targets, $ids, 'existing_credit_targets_invalid');
        $target->forceFill(['settlement_locked_at' => null, 'StudentID' => 999999])->save();
        $this->assertRefused($source, $targets, $ids, 'subject_credit_bad_target');
    }

    public function test_selected_cross_day_release_requires_a_calendar_restore_check_the_native_day_guard_cannot_prove(): void
    {
        [$source, $targets, $ids] = $this->allocationFixture();
        ClassSession::whereKey($ids[0])->update(['StartTime' => '23:00', 'EndTime' => '01:00']);
        $this->assertRefused($source, $targets, $ids, 'existing_credit_calendar_unverified');
    }

    private function allocationFixture(): array
    {
        [, $source, $target, $invoice] = $this->fixture();
        InvoiceItem::where('InvoiceID', $invoice->id)->delete(); // Actual legacy paid-header shape, not a new invoice repair.
        $debt = Invoice::where('StudentClassID', $target->ID)->firstOrFail();
        InvoiceItem::where('InvoiceID', $debt->id)->delete(); $debt->delete();
        $target->forceFill(['SessionCount' => 1, 'Charge' => 1500, 'UsedSessions' => 1, 'RemainingSessions' => 0])->save();
        $this->appendClassSession($target, '2026-09-10', 'attended');
        $other = $target->replicate();
        $other->forceFill(['SubjectID' => 970003, 'SessionCount' => 4, 'Charge' => 6000, 'UsedSessions' => 3, 'RemainingSessions' => 1])->save();
        for ($i = 0; $i < 3; $i++) $this->appendClassSession($other, '2026-09-' . (11 + $i), 'attended');
        for ($i = 0; $i < 6; $i++) {
            DB::table('ClassSession')->insert(['StudentClassID' => $source->ID, 'SessionDate' => '2026-10-' . sprintf('%02d', $i + 1),
                'StartTime' => '08:00', 'EndTime' => '10:00', 'Status' => 'scheduled']);
        }
        $ids = ClassSession::where('StudentClassID', $source->ID)->where('Status', 'scheduled')->orderByDesc('id')->limit(5)->pluck('id')->all();
        sort($ids);
        return [$source, [(int) $target->ID, (int) $other->ID], $ids];
    }

    private function preview(StudentClass $source, array $targets, array $ids): array
    {
        return app(ExistingCourseCreditPreview::class)->run($source,
            ['target_course_ids' => $targets, 'released_session_ids' => $ids], [(int) Student::findOrFail($source->StudentID)->CampusID]);
    }

    private function assertRefused(StudentClass $source, array $targets, array $ids, string $code): void
    {
        $before = $this->businessSnapshot();
        try { $this->preview($source, $targets, $ids); $this->fail('Unsafe allocation was accepted.'); }
        catch (HttpResponseException $exception) { $this->assertSame($code, $exception->getResponse()->getData(true)['code']); }
        $this->assertSame($before, $this->businessSnapshot());
    }
}
