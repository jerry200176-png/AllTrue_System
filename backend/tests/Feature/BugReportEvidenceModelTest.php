<?php

namespace Tests\Feature;

use App\Models\BugReport;
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
        $bug = BugReport::create([
            'CampusID' => 1,
            'reporter_user_id' => $admin->id,
            'title' => 'Legacy evidence candidate',
            'description' => 'D',
            'severity' => 'low',
            'status' => 'resolved',
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
