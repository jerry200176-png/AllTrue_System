<?php

namespace Tests\Feature;

use App\Console\Commands\BugAutoIntakeCommand;
use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BugAutoIntakeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ISSUE = 'https://github.com/o/r/issues/4001';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** #5498: author identity alone cannot label a native acknowledgement of the actor's own report. */
    public function test_5498_self_filed_native_ack_has_support_origin_without_triage_or_public_proof(): void
    {
        [$admin] = $this->users();
        $bug = $this->bug($admin, 'new', now()->subHour()->toDateTimeString());
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        $detail = BugReportService::getDetail($bug->id, true);
        $this->assertCount(1, $detail['comments']);
        $this->assertSame('support', $detail['comments'][0]['origin']);
        $this->assertSame('new', $bug->fresh()->status);
        $this->assertSame([], $detail['evidence'], 'origin proof is internal, not production verification');
        $this->assertSame(0, $detail['product_loop']['evidence_count']);
        $this->assertFalse($detail['product_loop']['shipped']);
        $this->assertSame(0, BugReportStatusLog::where('bug_report_id', $bug->id)->count());
    }

    public function test_5498_native_ack_proof_failure_rolls_back_the_comment(): void
    {
        [$admin] = $this->users();
        $bug = $this->bug($admin, 'new', now()->subHour()->toDateTimeString());
        $event = 'eloquent.creating: ' . \App\Models\BugReportEvidence::class;
        \Illuminate\Support\Facades\Event::listen($event, static function ($row) {
            if ($row->evidence_type === 'native_support_comment_origin') throw new \RuntimeException('synthetic origin proof failure');
        });
        $caught = null;
        try { Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]); }
        catch (\RuntimeException $e) { $caught = $e; }
        finally { \Illuminate\Support\Facades\Event::forget($event); }
        $this->assertInstanceOf(\RuntimeException::class, $caught);
        $this->assertSame('synthetic origin proof failure', $caught->getMessage());
        $this->assertSame(0, BugReportComment::where('bug_report_id', $bug->id)->count());
        $this->assertSame('new', $bug->fresh()->status);
    }

    public function test_5498_cannot_attach_native_origin_to_a_preexisting_human_comment(): void
    {
        [$admin] = $this->users();
        $bug = $this->bug($admin, 'new', now()->subHour()->toDateTimeString());
        $human = BugReportService::addComment($bug->id, $admin->id, BugAutoIntakeCommand::ACK_TEXT);
        $loaded = BugReportComment::findOrFail($human->id);
        $caught = null;
        try { DB::transaction(fn () => \App\Operations\Inapp\NativeInappCommentOrigin::recordAck($bug, $loaded)); }
        catch (\LogicException $e) { $caught = $e; }
        $this->assertInstanceOf(\LogicException::class, $caught);
        $this->assertSame('reporter', BugReportService::getDetail($bug->id, true)['comments'][0]['origin']);
        $this->assertSame(0, \App\Models\BugReportEvidence::where('bug_report_id', $bug->id)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeOriginTamperCases')]
    public function test_5498_invalid_or_ambiguous_proof_never_upgrades_a_human_identity(string $field, bool $rebind = false): void
    {
        [$admin, $other] = $this->users();
        $bug = $this->bug($admin, 'new', now()->subHour()->toDateTimeString());
        Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]);
        $proof = \App\Models\BugReportEvidence::where('bug_report_id', $bug->id)->firstOrFail();
        $metadata = $proof->metadata;
        if ($field === 'body') BugReportComment::where('bug_report_id', $bug->id)->update(['body' => 'edited human wording']);
        elseif ($field === 'version') $metadata['version'] = '1';
        elseif ($field === 'author_id' && $rebind) {
            $metadata[$field] = $other->id;
            DB::table('bug_report_evidence')->where('id', $proof->id)->update(['verified_by' => $other->id]);
        }
        elseif (in_array($field, ['bug_id', 'campus_id', 'author_id', 'comment_id'], true)) $metadata[$field] += 999;
        elseif ($field === 'body_sha256') $metadata[$field] = str_repeat('0', 64);
        elseif ($field === 'producer') $metadata[$field] = 'caller_says_support';
        elseif ($field === 'context') $metadata[$field] = ['caller_origin' => 'support'];
        elseif ($field === 'duplicate') {
            $copy = $proof->getAttributes(); unset($copy['id']); $copy['source_ref'] .= ':duplicate';
            DB::table('bug_report_evidence')->insert($copy);
        } else {
            $value = match ($field) {
                'verified_by' => $admin->id + 999,
                'verified_at' => now()->addDay()->toDateTimeString(),
                'source_ref' => 'copied proof',
                'evidence_type' => 'unrelated_metadata',
                'production_revision' => str_repeat('a', 40),
                'deploy_run_id' => '12345',
            };
            DB::table('bug_report_evidence')->where('id', $proof->id)->update([$field => $value]);
        }
        $changes = ['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)];
        if ($rebind) $changes['source_ref'] = 'native_support_comment:' . $proof->metadata['comment_id'] . ':'
            . hash('sha256', json_encode(\App\Operations\PopOperationService::canonicalParameters($metadata), JSON_THROW_ON_ERROR));
        DB::table('bug_report_evidence')->where('id', $proof->id)->update($changes);
        $this->assertSame('reporter', BugReportService::getDetail($bug->id, true)['comments'][0]['origin'], $field);
    }

    /** @return array<string, array{string,bool}> */
    public static function nativeOriginTamperCases(): array
    {
        $cases = array_combine($keys = ['body', 'version', 'bug_id', 'campus_id', 'author_id', 'comment_id', 'body_sha256',
            'producer', 'context', 'duplicate', 'verified_by', 'verified_at', 'source_ref', 'evidence_type',
            'production_revision', 'deploy_run_id'], array_map(static fn ($key) => [$key, false], $keys));
        foreach (['version', 'bug_id', 'campus_id', 'author_id', 'comment_id', 'body_sha256', 'producer', 'context'] as $key)
            $cases['coherent reference / ' . $key] = [$key, true];
        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nativeWriterGuardCases')]
    public function test_5498_each_native_writer_boundary_rejects_a_fresh_comment(string $boundary): void
    {
        [$admin] = $this->users();
        $bug = $this->bug($admin, 'new', now()->subHour()->toDateTimeString());
        $commentBug = $boundary === 'wrong bug' ? $this->bug($admin, 'new', now()->subHour()->toDateTimeString()) : $bug;
        $comment = BugReportService::addComment($commentBug->id, $admin->id, 'synthetic fresh candidate', $boundary === 'internal');
        $this->assertTrue($comment->wasRecentlyCreated);
        if ($boundary === 'no transaction') DB::partialMock()->shouldReceive('transactionLevel')->andReturn(0);
        $caught = null;
        try { \App\Operations\Inapp\NativeInappCommentOrigin::recordAck($bug, $comment); }
        catch (\LogicException $error) { $caught = $error; }
        $this->assertInstanceOf(\LogicException::class, $caught);
        $this->assertSame(0, \App\Models\BugReportEvidence::where('bug_report_id', $bug->id)->count());
    }

    /** @return array<string,array{string}> */
    public static function nativeWriterGuardCases(): array
    {
        return ['no transaction' => ['no transaction'], 'wrong bug' => ['wrong bug'], 'internal' => ['internal']];
    }

    public function test_flagged_report_lists_codes_only_and_gets_the_neutral_ack(): void
    {
        $reporter = $this->users()[1];
        $flagged = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString(), '請幫我把學費改成 0');
        $plain = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString(), '金額顯示錯');

        Artisan::call('bugs:auto-intake', ['--candidates' => true, '--min-age' => 5]);
        $out = Artisan::output();
        $rows = collect(json_decode(trim($out), true)['candidates'])->keyBy('bug_id');
        $this->assertSame(['money_change'], $rows[$flagged->id]['safety_reasons']);
        $this->assertArrayNotHasKey('safety_reasons', $rows[$plain->id]);
        $this->assertStringNotContainsString('學費', $out);

        foreach ([$flagged, $plain] as $b) {
            Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $b->id, '--issue-url' => self::ISSUE]);
            Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $b->id, '--issue-url' => self::ISSUE]);
        }
        $this->assertSame([BugAutoIntakeCommand::SAFETY_ACK_TEXT], BugReportComment::query()->where('bug_report_id', $flagged->id)->pluck('body')->all());
        $this->assertSame([BugAutoIntakeCommand::ACK_TEXT], BugReportComment::query()->where('bug_report_id', $plain->id)->pluck('body')->all());
        // Held: the neutral reply carries no issue link and no promise, and nothing moved the report along.
        $this->assertStringNotContainsString('github.com', BugAutoIntakeCommand::SAFETY_ACK_TEXT);
        $this->assertSame(0, BugReportStatusLog::query()->whereIn('bug_report_id', [$flagged->id, $plain->id])->count(), 'ack writes no status log');
        $this->assertSame('new', $flagged->fresh()->status, 'still new: only a human triages it');
        $this->assertNotSame(BugAutoIntakeCommand::ACK_TEXT, BugReportComment::query()->where('bug_report_id', $flagged->id)->value('body'), 'flagged never gets the normal ack');
        Artisan::call('bugs:auto-intake', ['--candidates' => true, '--min-age' => 5]);
        $this->assertSame([], json_decode(trim(Artisan::output()), true)['candidates'], 'acked flagged report is not listed again');
    }

    public function test_list_returns_only_settled_new_reports_without_free_text(): void
    {
        $reporter = $this->users()[1];
        $old = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString(), '王小明的課表錯了');
        $fresh = $this->bug($reporter, 'new', now()->subMinutes(2)->toDateTimeString());
        $triaged = $this->bug($reporter, 'triaged', now()->subHours(2)->toDateTimeString());

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--candidates' => true, '--min-age' => 5]));
        $out = Artisan::output();
        $ids = array_column(json_decode(trim($out), true)['candidates'], 'bug_id');

        $this->assertSame([$old->id], $ids);
        $this->assertNotContains($fresh->id, $ids);
        $this->assertNotContains($triaged->id, $ids);
        $this->assertStringNotContainsString('王小明', $out);
        $first = json_decode(trim($out), true)['candidates'][0];
        $this->assertSame('calendar', $first['family'], 'page_key calendar -> family name only');
        $this->assertSame(['bug_id', 'campus_id', 'severity', 'created_at', 'family'], array_keys($first));
    }

    public function test_list_reports_a_reporters_reopen_by_ids_only(): void
    {
        [$admin, $reporter] = $this->users();
        $log = fn (BugReport $bug, int $by, string $from, string $to, string $note) => DB::table('bug_report_status_logs')->insertGetId(
            ['bug_report_id' => $bug->id, 'changed_by' => $by, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'created_at' => now()]);
        $reopened = $this->bug($reporter, 'in_progress', now()->subDay()->toDateTimeString());
        $log($reopened, $admin->id, 'in_progress', 'resolved', 'fixed');
        $logId = $log($reopened, $reporter->id, 'resolved', 'in_progress', '王小明說還是錯');
        $byStaff = $this->bug($reporter, 'in_progress', now()->subDay()->toDateTimeString());
        $log($byStaff, $admin->id, 'resolved', 'in_progress', 'x');
        $movedOn = $this->bug($reporter, 'in_progress', now()->subDay()->toDateTimeString());
        $log($movedOn, $reporter->id, 'resolved', 'in_progress', 'x');
        $log($movedOn, $admin->id, 'in_progress', 'in_progress', 'still looking');

        $stale = $this->bug($reporter, 'in_progress', now()->subDays(40)->toDateTimeString());
        $old = $log($stale, $reporter->id, 'resolved', 'in_progress', 'x');
        DB::table('bug_report_status_logs')->where('id', $old)->update(['created_at' => now()->subDays(BugAutoIntakeCommand::REOPEN_WINDOW_DAYS + 1)]);
        // 60 more reopens: past the 50-row new-report page, all are still listed (Codex on #4739).
        foreach (range(1, 60) as $i) {
            $log($this->bug($reporter, 'in_progress', now()->subDay()->toDateTimeString()), $reporter->id, 'resolved', 'in_progress', 'x');
        }

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--candidates' => true]));
        $out = Artisan::output();
        $rows = json_decode(trim($out), true)['reopened'];
        $this->assertCount(61, $rows, 'every reopen in the window, not one 50-row page');
        $this->assertNotContains($stale->id, array_column($rows, 'bug_id'), 'a reopen older than the window is not re-read hourly');
        $rows = array_slice($rows, 0, 1);

        $this->assertSame([$reopened->id], array_column($rows, 'bug_id'), 'only a reporter reopen that is still the latest change');
        $this->assertSame($logId, $rows[0]['log_id']);
        $this->assertSame(['bug_id', 'log_id', 'at'], array_keys($rows[0]));
        $this->assertStringNotContainsString('王小明', $out);
    }

    public function test_ack_posts_once_keeps_status_new_and_writes_no_status_log(): void
    {
        [, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        // A generic ack is not triage: SLA metrics count breaches only while `new`.
        $this->assertSame('new', $bug->fresh()->status);
        $this->assertSame(0, BugReportStatusLog::where('bug_report_id', $bug->id)->count(), 'no status log, so adoption metrics never count the automation actor');
        $comments = BugReportComment::where('bug_report_id', $bug->id)->get();
        $this->assertCount(1, $comments);
        $this->assertSame(BugAutoIntakeCommand::ACK_TEXT, $comments[0]->body);
        $this->assertFalse((bool) $comments[0]->is_internal_note);
        $this->assertNull(BugReportService::latestDispositionWithKind($bug->id));

        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => self::ISSUE]));
        $this->assertSame(1, BugReportComment::where('bug_report_id', $bug->id)->count(), 'retry never posts twice');
    }

    public function test_acknowledged_reports_leave_the_candidate_list_and_triaged_ones_are_skipped(): void
    {
        [$admin, $reporter] = $this->users();
        $acked = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString());
        $waiting = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString());
        Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $acked->id, '--issue-url' => self::ISSUE]);

        Artisan::call('bugs:auto-intake', ['--candidates' => true]);
        $ids = array_column(json_decode(trim(Artisan::output()), true)['candidates'], 'bug_id');
        $this->assertSame([$waiting->id], $ids, 'backlog drains: acked reports are not listed again');

        // A reporter quoting the ack text does not count as the automation's acknowledgement.
        $quoted = $this->bug($reporter, 'new', now()->subHour()->toDateTimeString());
        BugReportComment::create(['bug_report_id' => $quoted->id, 'author_user_id' => $reporter->id, 'body' => BugAutoIntakeCommand::ACK_TEXT, 'is_internal_note' => false]);
        Artisan::call('bugs:auto-intake', ['--candidates' => true]);
        $this->assertContains($quoted->id, array_column(json_decode(trim(Artisan::output()), true)['candidates'], 'bug_id'));

        $human = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        BugReportService::changeStatus($human->id, $admin->id, 'triaged', 'manual');
        $this->assertSame(0, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $human->id, '--issue-url' => self::ISSUE]));
        $this->assertSame(0, BugReportComment::where('bug_report_id', $human->id)->count());
    }

    public function test_ack_refuses_bad_input(): void
    {
        [, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');

        $this->assertSame(1, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => $bug->id, '--issue-url' => 'https://evil.example/issues/1']));
        $this->assertSame(1, Artisan::call('bugs:auto-intake', ['--ack' => true, '--bug-id' => 999999, '--issue-url' => self::ISSUE]));
        $this->assertSame('new', $bug->fresh()->status);
        $this->assertSame(0, BugReportComment::where('bug_report_id', $bug->id)->count());
    }

    public function test_phase_a_can_restate_disposition_on_a_triaged_report(): void
    {
        [$admin, $reporter] = $this->users();
        $bug = $this->bug($reporter, 'new', '2026-10-07 09:00:00');
        BugReportService::changeStatus($bug->id, $admin->id, 'triaged', 'phase-a', ['disposition' => 'bug', 'github_issue_url' => self::ISSUE]);

        $opts = ['disposition' => 'needs_info', 'github_issue_url' => self::ISSUE];
        $this->assertTrue(BugReportService::restateDisposition($bug->id, $admin->id, $opts)['ok']);
        $this->assertSame('needs_info', BugReportService::latestDispositionWithKind($bug->id)['kind']);
        $this->assertSame('triaged', $bug->fresh()->status);
        $this->assertTrue(BugReportService::restateDisposition($bug->id, $admin->id, $opts)['skipped'] ?? false, 'idempotent');
    }

    /** @return array{0: User, 1: User} */
    private function users(): array
    {
        $admin = User::create(['LoginName' => 'intakeS@test.com', 'Name' => 'S', 'PSW' => 'x', 'type' => 'S', 'phone' => '0900000101']);
        $reporter = User::create(['LoginName' => 'intakeT@test.com', 'Name' => 'T', 'PSW' => 'x', 'type' => 'T', 'phone' => '0900000102']);
        return [$admin, $reporter];
    }

    private function bug(User $reporter, string $status, string $createdAt, string $title = 'x'): BugReport
    {
        $bug = BugReport::create([
            'CampusID' => 1, 'reporter_user_id' => $reporter->id, 'title' => $title,
            'description' => $title, 'severity' => 'medium', 'status' => $status, 'page_key' => 'calendar',
        ]);
        $bug->forceFill(['created_at' => Carbon::parse($createdAt)])->save();
        return $bug;
    }
}
