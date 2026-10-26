<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportAttachment;
use App\Models\BugReportComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TriageReporterHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    public function test_reporter_history_pages_across_campuses_and_includes_closed_reports(): void
    {
        $headers = $this->headers();
        $reporter = $this->user('T');
        $other = $this->user('T');
        $first = $this->bug($reporter, 'new', 1);
        $this->bug($other, 'new', 1);
        $closed = $this->bug($reporter, 'closed', 2);
        $last = $this->bug($reporter, 'triaged', 1);
        BugReportComment::create(['bug_report_id' => $closed->id, 'author_user_id' => $reporter->id, 'body' => 'history fixture', 'is_internal_note' => true]);
        BugReportAttachment::create(['bug_report_id' => $closed->id, 'stored_path' => 'bug-reports/history.png', 'original_name' => 'history.png', 'mime_type' => 'image/png', 'size' => 1]);
        $url = '/api/v1/triage/bug-reports?reporter_user_id=' . $reporter->id . '&limit=2';

        $page = $this->getJson($url, $headers)->assertOk();
        $this->assertSame([$first->id, $closed->id], array_column($page->json('data'), 'id'));
        $page->assertJsonPath('data.1.status', 'closed')
            ->assertJsonPath('data.1.campus_id', 2)
            ->assertJsonPath('data.1.comments_count', 1)
            ->assertJsonPath('data.1.attachments_count', 1)
            ->assertJsonPath('next_since_id', $closed->id);

        $next = $this->getJson($url . '&since_id=' . $closed->id, $headers)->assertOk();
        $this->assertSame([$last->id], array_column($next->json('data'), 'id'));
        $this->getJson($url . '&since_id=' . $last->id, $headers)->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('next_since_id', null);
    }

    public function test_reporter_filter_combines_with_status_without_changing_unfiltered_reads(): void
    {
        $headers = $this->headers();
        $reporter = $this->user('T');
        $own = $this->bug($reporter, 'triaged', 1);
        $this->bug($reporter, 'closed', 2);
        $other = $this->bug($this->user('T'), 'triaged', 1);

        $filtered = $this->getJson('/api/v1/triage/bug-reports?status=triaged&reporter_user_id=' . $reporter->id, $headers)->assertOk();
        $this->assertSame([$own->id], array_column($filtered->json('data'), 'id'));
        $unfiltered = $this->getJson('/api/v1/triage/bug-reports?status=triaged', $headers)->assertOk();
        $this->assertSame([$own->id, $other->id], array_column($unfiltered->json('data'), 'id'));
    }

    /** @dataProvider invalidReporterIds */
    public function test_invalid_reporter_filter_is_rejected($value): void
    {
        $query = $value === null ? 'reporter_user_id' : http_build_query(['reporter_user_id' => $value]);
        $this->getJson('/api/v1/triage/bug-reports?' . $query, $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('reporter_user_id');
    }

    public static function invalidReporterIds(): array
    {
        return [['0'], ['-1'], ['not-an-id'], ['1.5'], [[1]], [''], ['   '], [null]];
    }

    private function headers(): array
    {
        $admin = $this->user('S');
        $token = $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'history fixture'], ['X-User-Id' => (string) $admin->id])
            ->assertCreated()->json('token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function user(string $type): User
    {
        $id = ++$this->seq;

        return User::create(['LoginName' => "history{$id}@test.invalid", 'Name' => "History fixture {$id}", 'PSW' => 'fixture', 'type' => $type, 'phone' => (string) (910000000 + $id)]);
    }

    private function bug(User $reporter, string $status, int $campusId): BugReport
    {
        return BugReport::create(['CampusID' => $campusId, 'reporter_user_id' => $reporter->id, 'title' => 'History fixture', 'description' => 'Synthetic report', 'severity' => 'medium', 'status' => $status]);
    }
}
