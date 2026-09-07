<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportComment;
use App\Models\BugReportEvidence;
use App\Models\BugReportStatusLog;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillBugResolutionEvidenceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_command_is_dry_run_then_idempotent_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00'));
        $admin = $this->seedAdmin();
        [$bug, $comment] = $this->makeLegacyResolvedBug($admin, Carbon::parse('2026-09-01 12:00:00'));
        $sha = str_repeat('c', 40);
        $manifestPath = tempnam(sys_get_temp_dir(), 'bug-evidence-');
        $manifest = [
            'kind' => 'bug_resolution_evidence_backfill',
            'manifest_id' => 'test-manifest-1',
            'decision_reference' => 'Founder approval test',
            'evidence_type' => BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION,
            'approved_bug_ids' => [$bug->id],
            'items' => [[
                'bug_id' => $bug->id,
                'production_revision' => $sha,
                'source_ref' => 'probe:legacy-current:'.$bug->id,
                'verified_by' => $admin->id,
                'verified_at' => '2026-09-20T11:00:00+08:00',
                'metadata' => [
                    'public_resolution_comment_id' => $comment->id,
                    'probe' => [
                        'type' => 'targeted_current_production',
                        'verdict' => 'verified',
                        'health_only' => false,
                        'observed' => 'target behavior verified',
                        'evidence_ref' => 'run:probe-1',
                    ],
                ],
            ]],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->artisan('bugs:backfill-resolution-evidence', [
            'manifest' => $manifestPath,
            '--dry-run' => true,
            '--production-head' => $sha,
            '--json' => true,
        ])->assertSuccessful();
        $this->assertDatabaseCount('bug_report_evidence', 0);

        $this->artisan('bugs:backfill-resolution-evidence', [
            'manifest' => $manifestPath,
            '--apply' => true,
            '--confirmation' => 'BACKFILL_BUG_RESOLUTION_EVIDENCE',
            '--production-head' => $sha,
        ])->assertSuccessful();
        $this->assertDatabaseCount('bug_report_evidence', 1);
        $this->assertSame('resolved', $bug->fresh()->status);
        $this->assertDatabaseCount('bug_report_status_logs', 1);

        $this->artisan('bugs:backfill-resolution-evidence', [
            'manifest' => $manifestPath,
            '--apply' => true,
            '--confirmation' => 'BACKFILL_BUG_RESOLUTION_EVIDENCE',
            '--production-head' => $sha,
        ])->assertSuccessful();
        $this->assertDatabaseCount('bug_report_evidence', 1);

        Carbon::setTestNow();
    }

    public function test_backfill_command_rejects_health_only_probe(): void
    {
        $admin = $this->seedAdmin();
        [$bug, $comment] = $this->makeLegacyResolvedBug($admin, Carbon::parse('2026-09-01 12:00:00'));
        $sha = str_repeat('d', 40);
        $manifestPath = tempnam(sys_get_temp_dir(), 'bug-evidence-');
        $manifest = [
            'kind' => 'bug_resolution_evidence_backfill',
            'manifest_id' => 'test-manifest-health-only',
            'decision_reference' => 'Founder approval test',
            'evidence_type' => BugReportEvidence::TYPE_RESOLUTION_PRODUCTION_VERIFICATION,
            'approved_bug_ids' => [$bug->id],
            'items' => [[
                'bug_id' => $bug->id,
                'production_revision' => $sha,
                'source_ref' => 'probe:health-only:'.$bug->id,
                'verified_by' => $admin->id,
                'verified_at' => now()->toIso8601String(),
                'metadata' => [
                    'public_resolution_comment_id' => $comment->id,
                    'probe' => [
                        'type' => 'targeted_current_production',
                        'verdict' => 'verified',
                        'health_only' => true,
                        'observed' => 'health ok',
                        'evidence_ref' => 'run:health',
                    ],
                ],
            ]],
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->artisan('bugs:backfill-resolution-evidence', [
            'manifest' => $manifestPath,
            '--apply' => true,
            '--confirmation' => 'BACKFILL_BUG_RESOLUTION_EVIDENCE',
            '--production-head' => $sha,
        ])->assertFailed();
        $this->assertDatabaseCount('bug_report_evidence', 0);
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
        UserCampus::create([
            'CampusID' => 1,
            'UserID' => $user->id,
            'Admin' => 1,
            'Approved' => 1,
        ]);
        return $user;
    }

    /** @return array{0:BugReport,1:BugReportComment,2:Carbon} */
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
        $comment = BugReportComment::create([
            'bug_report_id' => $bug->id,
            'author_user_id' => $admin->id,
            'body' => '請重新整理後驗收',
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
        return [$bug, $comment, $resolvedAt];
    }
}
