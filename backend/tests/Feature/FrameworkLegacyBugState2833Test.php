<?php

namespace Tests\Feature;

use App\Models\BugReport;
use App\Models\BugReportStatusLog;
use App\Models\Campus;
use App\Models\User;
use App\Services\BugReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FrameworkLegacyBugState2833Test extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_stored_enum_state_is_rejected_without_status_writes(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertStringStartsWith('alltrue_test', strtolower(DB::connection()->getDatabaseName()));
        [$bug, $actor] = $this->makeBug();
        $originalMode = (string) DB::selectOne('SELECT @@SESSION.sql_mode AS value')->value;
        $fixtureMode = implode(',', array_filter(explode(',', $originalMode), static function (string $mode): bool {
            return !in_array($mode, ['STRICT_TRANS_TABLES', 'STRICT_ALL_TABLES'], true);
        }));

        // Reproduce MySQL's legacy invalid-ENUM empty value for one fixture
        // write only. Restore strict mode before exercising the application.
        try {
            DB::statement('SET SESSION sql_mode = ?', [$fixtureMode]);
            DB::table('bug_reports')->where('id', $bug->id)->update(['status' => 'legacy_unknown']);
        } finally {
            DB::statement('SET SESSION sql_mode = ?', [$originalMode]);
        }
        $this->assertSame($originalMode, DB::selectOne('SELECT @@SESSION.sql_mode AS value')->value);
        $this->assertSame('', $bug->fresh()->status);

        $result = BugReportService::changeStatus($bug->id, $actor->id, 'triaged');

        $this->assertFalse($result['ok']);
        $this->assertSame('invalid_transition', $result['code']);
        $this->assertSame('', $bug->fresh()->status);
        $this->assertSame(0, BugReportStatusLog::where('bug_report_id', $bug->id)->count());
    }

    public function test_known_stored_state_keeps_the_normal_transition_and_log(): void
    {
        [$bug, $actor] = $this->makeBug();
        $result = BugReportService::changeStatus($bug->id, $actor->id, 'triaged');

        $this->assertTrue($result['ok']);
        $this->assertSame('triaged', $bug->fresh()->status);
        $this->assertDatabaseHas('bug_report_status_logs', [
            'bug_report_id' => $bug->id,
            'from_status' => 'new',
            'to_status' => 'triaged',
        ]);
    }

    private function makeBug(): array
    {
        $campus = Campus::factory()->create();
        $actor = User::create([
            'LoginName' => 'stored-state-' . uniqid() . '@example.test',
            'Name' => 'Isolated state fixture',
            'PSW' => 'not-an-application-session',
            'type' => 'T',
            'phone' => random_int(900000000, 999999999),
        ]);
        $bug = BugReport::create([
            'CampusID' => $campus->id,
            'reporter_user_id' => $actor->id,
            'title' => 'Isolated stored-state contract',
            'description' => 'No production case or reporter acceptance is represented.',
            'severity' => 'medium',
            'status' => 'new',
        ]);

        return [$bug, $actor];
    }
}
