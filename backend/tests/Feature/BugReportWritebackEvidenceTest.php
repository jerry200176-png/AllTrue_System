<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\BugReportComment;
use App\Models\User;
use App\Services\BugReportService;
use App\Services\BugReportWritebackEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BugReportWritebackEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detail_proof_binds_time_and_report_and_hides_content(): void
    {
        $bug = $this->bug();
        $detail = BugReportService::getDetail($bug->id, true);
        $evidence = $detail['writeback_evidence'];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $evidence['proof']);
        $this->assertTrue(BugReportWritebackEvidence::verify($bug, $evidence['captured_at'], $evidence['proof']));
        $this->assertStringNotContainsString('private description', json_encode($evidence));
        $this->travelTo(\Illuminate\Support\Carbon::parse($evidence['captured_at'])->addMinutes(15));
        $this->assertTrue(BugReportWritebackEvidence::verify($bug, $evidence['captured_at'], $evidence['proof']));
        $this->travelTo(now()->addMicrosecond());
        $this->assertFalse(BugReportWritebackEvidence::verify($bug, $evidence['captured_at'], $evidence['proof']));
        $this->assertFalse(BugReportWritebackEvidence::verify($bug, now()->toISOString(), $evidence['proof']));
        $this->assertFalse(BugReportWritebackEvidence::verify($this->bug(), now()->toISOString(), $evidence['proof']));
    }

    public function test_report_and_comment_and_attachment_changes_invalidate_proof(): void
    {
        $bug = $this->bug();
        $comment = BugReportComment::create(['bug_report_id' => $bug->id, 'author_user_id' => $bug->reporter_user_id,
            'body' => 'private note', 'is_internal_note' => true]);
        $attachment = BugReportAttachment::create(['bug_report_id' => $bug->id, 'stored_path' => 'bug-reports/example.png',
            'original_name' => 'example.png', 'mime_type' => 'image/png', 'size' => 7]);
        foreach (['report', 'comment', 'attachment', 'new_comment', 'new_attachment'] as $change) {
            $evidence = BugReportWritebackEvidence::capture($bug->fresh());
            if ($change === 'report') {
                $bug->update(['description' => 'changed report']);
            } elseif ($change === 'comment') {
                $comment->update(['body' => 'changed note']);
            } elseif ($change === 'attachment') {
                $attachment->update(['stored_path' => 'bug-reports/changed.png']);
            } elseif ($change === 'new_comment') {
                BugReportComment::create(['bug_report_id' => $bug->id, 'author_user_id' => $bug->reporter_user_id,
                    'body' => 'new note', 'is_internal_note' => false]);
            } else {
                BugReportAttachment::create(['bug_report_id' => $bug->id, 'stored_path' => 'bug-reports/new.png',
                    'original_name' => 'new.png', 'mime_type' => 'image/png', 'size' => 8]);
            }
            $this->assertFalse(BugReportWritebackEvidence::verify($bug->fresh(), $evidence['captured_at'], $evidence['proof']), $change);
        }
    }

    public function test_empty_key_and_tampered_or_invalid_evidence_fail_closed(): void
    {
        $bug = $this->bug();
        $evidence = BugReportWritebackEvidence::capture($bug);
        $this->assertFalse(BugReportWritebackEvidence::verify($bug, $evidence['captured_at'], str_repeat('0', 64)));
        $this->assertFalse(BugReportWritebackEvidence::verify($bug, 'tomorrow', $evidence['proof']));
        config(['app.key' => '']);
        $this->assertNull(BugReportWritebackEvidence::capture($bug));
        $this->assertFalse(BugReportWritebackEvidence::verify($bug, $evidence['captured_at'], $evidence['proof']));
    }


    public function test_visibility_writer_cannot_commit_between_verification_and_protected_write(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires independent InnoDB connections for the real visibility race.');
        }
        $default = DB::getDefaultConnection();
        $config = config('database.connections.' . $default);
        config(['database.connections.evidence_race_a' => $config, 'database.connections.evidence_race_b' => $config]);
        $a = DB::connection('evidence_race_a');
        $b = DB::connection('evidence_race_b');
        $b->statement('SET SESSION innodb_lock_wait_timeout = 1');
        // Committed fixture on B: the default RefreshDatabase transaction cannot create a false lock conflict.
        $user = User::on('evidence_race_b')->create(['LoginName' => uniqid('proof-race-'), 'Name' => 'Race reader',
            'PSW' => 'fixture', 'type' => 'S', 'phone' => (string) random_int(900000000, 999999999)]);
        $bug = BugReport::on('evidence_race_b')->create(['CampusID' => 1, 'reporter_user_id' => $user->id,
            'title' => 'Race', 'description' => 'Race fixture', 'severity' => 'medium', 'status' => 'new']);
        $comment = BugReportComment::on('evidence_race_b')->create(['bug_report_id' => $bug->id,
            'author_user_id' => $user->id, 'body' => 'Reply', 'is_internal_note' => false]);
        try {
            $read = BugReport::on('evidence_race_a')->findOrFail($bug->id);
            $proof = BugReportWritebackEvidence::capture($read);
            $this->assertFalse(BugReportWritebackEvidence::verify($read, $proof['captured_at'], $proof['proof']),
                'verification outside a write transaction must fail closed');
            $a->beginTransaction();
            $locked = BugReport::on('evidence_race_a')->whereKey($bug->id)->lockForUpdate()->firstOrFail();
            $this->assertTrue(BugReportWritebackEvidence::verify($locked, $proof['captured_at'], $proof['proof']));
            DB::setDefaultConnection('evidence_race_b');
            try {
                BugReportService::updateCommentVisibility($bug->id, $comment->id, true);
                $this->fail('Visibility edit committed while verified child evidence was still in use.');
            } catch (QueryException $e) {
                $this->assertSame(1205, (int) ($e->errorInfo[1] ?? 0), 'only an InnoDB lock wait proves serialization');
            }
            $this->assertFalse((bool) $b->table('bug_report_comments')->where('id', $comment->id)->value('is_internal_note'));
            $a->rollBack();
            $this->assertTrue((bool) BugReportService::updateCommentVisibility($bug->id, $comment->id, true)->is_internal_note);
            $a->beginTransaction();
            $changed = BugReport::on('evidence_race_a')->whereKey($bug->id)->lockForUpdate()->firstOrFail();
            $this->assertFalse(BugReportWritebackEvidence::verify($changed, $proof['captured_at'], $proof['proof']));
        } finally {
            if ($a->transactionLevel() > 0) $a->rollBack();
            DB::setDefaultConnection($default);
            $b->table('bug_reports')->where('id', $bug->id)->delete();
            $b->table('User')->where('id', $user->id)->delete();
            DB::purge('evidence_race_a');
            DB::purge('evidence_race_b');
        }
    }

    private function bug(): BugReport
    {
        $user = User::create(['LoginName' => uniqid('proof-'), 'Name' => 'Evidence reader', 'PSW' => 'fixture',
            'type' => 'S', 'phone' => (string) random_int(900000000, 999999999)]);
        return BugReport::create(['CampusID' => 1, 'reporter_user_id' => $user->id, 'title' => 'Evidence test',
            'description' => 'private description', 'severity' => 'medium', 'status' => 'new'])->fresh();
    }
}
