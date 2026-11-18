<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportEvidence;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BugReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class BugReportEvidenceModelTest extends TestCase
{
    use RefreshDatabase;

    /** #5498 prerequisite: unknown/internal append-only data must stay private even after a producer rollback. */
    public function test_public_evidence_is_an_explicit_type_allowlist_without_deleting_internal_rows(): void
    {
        $admin = $this->seedAdmin();
        $public = $this->makeEvidence($admin);
        foreach (['native_support_comment_origin', 'unreviewed_future_type', ''] as $i => $type) {
            BugReportEvidence::create([
                'bug_report_id' => $public->bug_report_id, 'evidence_type' => $type,
                'production_revision' => str_repeat('a', 40), 'deploy_run_id' => '123',
                'source_ref' => 'synthetic-private-type:' . $i, 'verified_by' => $admin->id,
                'verified_at' => now(), 'created_at' => now(),
                'metadata' => ['private_marker' => 'SYNTHETIC-PRIVATE-PROOF'],
            ]);
        }
        foreach ([false, true] as $includeInternalNotes) {
            $detail = BugReportService::getDetail((int) $public->bug_report_id, $includeInternalNotes);
            $this->assertSame([$public->id], array_column($detail['evidence'], 'id'));
            $this->assertSame($public->production_revision, $detail['evidence'][0]['production_revision']);
            $this->assertSame(1, $detail['product_loop']['evidence_count']);
            $this->assertStringNotContainsString('SYNTHETIC-PRIVATE-PROOF', json_encode($detail));
        }
        $this->assertSame(4, BugReportEvidence::where('bug_report_id', $public->bug_report_id)->count(), 'append-only audit rows remain intact');
    }

    public function test_append_only_evidence_enables_timeout_without_rewriting_status_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));
        $admin = $this->seedAdmin();
        [$bug, $resolvedAt] = $this->makeLegacyResolvedBug($admin, Carbon::parse('2026-09-01 12:00:00'));

        BugReportEvidence::create([
            'bug_report_id' => $bug->id,
            'evidence_type' => BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION,
            'production_revision' => str_repeat('a', 40),
            'source_ref' => 'probe:legacy-current:'.$bug->id,
            'verified_by' => $admin->id,
            'verified_at' => $resolvedAt->copy()->addDay(),
            'metadata' => ['probe' => ['type' => 'targeted_current_production']],
            'created_at' => $resolvedAt->copy()->addDay(),
        ]);

        $eligible = BugReportService::listEligibleForReporterTimeout(7);

        $this->assertContains($bug->id, array_column($eligible, 'bug_id'));
        $this->assertSame('resolved', $bug->fresh()->status);
        $this->assertDatabaseCount('bug_report_status_logs', 1);
        Carbon::setTestNow();
    }

    public function test_evidence_model_rejects_update(): void
    {
        $admin = $this->seedAdmin();
        $evidence = $this->makeEvidence($admin);
        $evidence->metadata = ['changed' => true];

        $this->expectException(LogicException::class);
        $evidence->save();
    }

    public function test_evidence_model_rejects_delete(): void
    {
        $admin = $this->seedAdmin();
        $evidence = $this->makeEvidence($admin);

        $this->expectException(LogicException::class);
        $evidence->delete();
    }

    private function seedAdmin(): User
    {
        $user = User::create([
            'LoginName' => 'evidence-'.Str::random(8).'@test.com',
            'Name' => 'Evidence Admin',
            'PSW' => 'secret',
            'type' => 'S',
            'phone' => rand(900000000, 999999999),
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        return $user;
    }

    /** @return array{0:BugReport,1:Carbon} */
    private function makeLegacyResolvedBug(User $admin, Carbon $resolvedAt): array
    {
        $reporter = User::create([
            'LoginName' => 'evidence-reporter-'.Str::random(8).'@test.com',
            'Name' => 'Evidence Reporter',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => rand(900000000, 999999999),
        ]);
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $reporter->id,
            'title' => 'Legacy evidence candidate',
            'description' => 'D',
            'severity' => 'low',
            'status' => 'resolved',
        ]);
        BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $admin->id,
            'body' => '已上線，請再試一次並確認是否修好',
            'is_internal_note' => false,
            'created_at' => $resolvedAt,
        ]);
        BugReportStatusLog::create([
            'bug_report_id' => $bug->id,
            'changed_by' => $admin->id,
            'from_status' => 'in_progress',
            'to_status' => 'resolved',
            'note' => 'legacy resolve without machine evidence',
            'created_at' => $resolvedAt,
        ]);
        return [$bug, $resolvedAt];
    }

    private function makeEvidence(User $admin): BugReportEvidence
    {
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $admin->id,
            'title' => 'Evidence immutability',
            'description' => 'D',
            'severity' => 'low',
            'status' => 'resolved',
        ]);

        return BugReportEvidence::create([
            'bug_report_id' => $bug->id,
            'evidence_type' => BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION,
            'production_revision' => str_repeat('b', 40),
            'source_ref' => 'probe:immutable:'.$bug->id,
            'verified_by' => $admin->id,
            'verified_at' => now(),
            'created_at' => now(),
        ]);
    }
}
