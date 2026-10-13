<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\BugReportComment;
use App\Models\User;
use App\Services\BugReportService;
use App\Services\BugReportWritebackEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function bug(): BugReport
    {
        $user = User::create(['LoginName' => uniqid('proof-'), 'Name' => 'Evidence reader', 'PSW' => 'fixture',
            'type' => 'S', 'phone' => (string) random_int(900000000, 999999999)]);
        return BugReport::create(['CampusID' => 1, 'reporter_user_id' => $user->id, 'title' => 'Evidence test',
            'description' => 'private description', 'severity' => 'medium', 'status' => 'new'])->fresh();
    }
}
