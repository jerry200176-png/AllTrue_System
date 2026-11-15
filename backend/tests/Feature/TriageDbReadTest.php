<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Founder GO 2026-11-16: a read-only triage token reads rows of the business tables, numbers / dates / codes only. CI only. */
final class TriageDbReadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['LoginName' => 'db-triage-admin@test.com', 'Name' => 'Triage Admin', 'PSW' => 'secret', 'type' => 'S', 'phone' => '0910000902']);
        $this->token = (string) $this->postJson('/api/v1/admin/triage-tokens', ['name' => 'ci'], ['X-User-Id' => (string) $this->admin->id, 'Accept' => 'application/json'])->assertCreated()->json('token');
    }

    public function test_rows_carry_numbers_dates_and_codes_and_never_names_notes_or_credentials(): void
    {
        $teacher = User::create(['LoginName' => 'db-triage-teacher@test.com', 'Name' => 'Amy', 'PSW' => 'secret', 'type' => 'T', 'phone' => '0922000902']);
        $student = Student::create(['name' => '資料學生', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => 'tok_abc123']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'TeacherID' => $teacher->id, 'GradeID' => 1, 'SubjectID' => 1, 'ClassType' => 'one_on_one', 'by1' => 1,
            'ScheduleMode' => 'date', 'SessionCount' => 4, 'RemainingSessions' => 4, 'Rate' => 2200, 'Charge' => 8800, 'Paid' => 0, 'Period' => 4,
            'SessionDuration' => 120, 'TotalHours' => 8, 'StartDate' => '2026-08-01', 'EndDate' => '2026-08-31', 'RoomID' => '', 'MDate' => now(), 'Memo' => 'typed memo',
        ]);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-05', 'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'attended', 'Note' => 'typed note']);
        ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-08-12', 'StartTime' => '10:00:00', 'EndTime' => '12:00:00', 'Status' => 'leave', 'Note' => '']);

        $contract = $this->getJson("/api/v1/triage/db/StudentClass?eq[ID]={$course->ID}", $this->bearer())->assertOk();
        $contract->assertJsonPath('table', 'StudentClass')->assertJsonPath('count', 1)
            ->assertJsonPath('rows.0.ID', $course->ID)->assertJsonPath('rows.0.StudentID', $student->id)
            ->assertJsonPath('rows.0.ScheduleMode', 'date')->assertJsonPath('rows.0.SessionCount', 4);
        $this->assertEquals(2200, $contract->json('rows.0.Rate'));
        $this->assertEquals(8800, $contract->json('rows.0.Charge'));
        $this->assertArrayNotHasKey('Memo', $contract->json('rows.0'));

        $lessons = $this->getJson("/api/v1/triage/db/ClassSession?eq[StudentClassID]={$course->ID}&gte[SessionDate]=2026-08-10&order=SessionDate&dir=desc", $this->bearer())->assertOk();
        $lessons->assertJsonPath('count', 1)->assertJsonPath('rows.0.Status', 'leave');
        $this->assertArrayNotHasKey('Note', $lessons->json('rows.0'));

        $people = [
            $this->getJson("/api/v1/triage/db/Student?eq[id]={$student->id}", $this->bearer())->assertOk()->assertJsonPath('rows.0.CampusID', 1),
            $this->getJson('/api/v1/triage/db/User?in[id]=' . $this->admin->id . ',' . $teacher->id, $this->bearer())->assertOk()->assertJsonPath('count', 2),
        ];
        foreach ([$contract, $lessons, ...$people] as $response) {
            $body = (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
            foreach (['資料學生', 'Amy', 'Triage Admin', '@test.com', 'secret', '0922000902', '0910000902', 'tok_abc123', 'typed', 'PSW', 'LoginName', 'Notify_Token', 'phone'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body);
            }
        }
        // The audit row says which table was read.
        $this->assertTrue(DB::table('security_audit_events')->where('event_type', 'triage_token.read')->where('outcome', 'success')
            ->pluck('metadata')->contains(fn ($meta) => (json_decode((string) $meta, true)['source'] ?? null) === 'StudentClass'));
        // Sign-in counters and anything named like a pin, phone or birthday never show up as a column.
        foreach ($people[1]->json('columns') as $column) {
            $this->assertDoesNotMatchRegularExpression('/pin|phone|psw|birth|token/i', $column);
        }
        // A whole number of nine digits or more may be a phone or identity number in an old column: withheld, and not searchable.
        DB::table('StudentClass')->where('ID', $course->ID)->update(['Charge' => 912345678]);
        $this->assertNull($this->getJson("/api/v1/triage/db/StudentClass?eq[ID]={$course->ID}", $this->bearer())->assertOk()->json('rows.0.Charge'));
        $this->getJson('/api/v1/triage/db/StudentClass?gte[Charge]=912345678', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/StudentClass?in[Charge]=1,912345678', $this->bearer())->assertStatus(422);
        // Forms the database would still read as that number.
        foreach (['912345678.0', '9.12e8', '912345678abc', '+912345678', ' 912345678', '0912345678'] as $disguised) {
            $this->getJson('/api/v1/triage/db/StudentClass?lte[Charge]=' . urlencode($disguised), $this->bearer())->assertStatus(422);
        }
        $this->getJson('/api/v1/triage/db/StudentClass?lte[Charge]=99999999&gte[StartDate]=2026-08-01', $this->bearer())->assertOk();
    }

    public function test_withheld_columns_cannot_be_filtered_or_sorted_and_sign_in_tables_are_unreachable(): void
    {
        // Filtering or sorting on a withheld column would leak it one question at a time.
        $this->getJson('/api/v1/triage/db/Student?eq[name]=x', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/User?gte[PSW]=a', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/User?order=phone', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/User?in[LoginName]=a,b', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/Student?limit=201', $this->bearer())->assertStatus(422);
        // A code column: exact match against a code only. A range, an ordering or a typed-text value could find withheld text.
        $this->getJson('/api/v1/triage/db/ClassSession?eq[Status]=leave', $this->bearer())->assertOk();
        $this->getJson('/api/v1/triage/db/ClassSession?in[Status]=leave,attended', $this->bearer())->assertOk();
        $this->getJson('/api/v1/triage/db/ClassSession?gte[Status]=a', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/ClassSession?order=Status', $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/ClassSession?eq[Status]=' . urlencode('請假 補課'), $this->bearer())->assertStatus(422);
        $this->getJson('/api/v1/triage/db/ClassSession?in[Status]=leave,' . urlencode('typed text'), $this->bearer())->assertStatus(422);
        // A code is a lowercase word, a short role or a YYYY-MM period: a name or a phone typed into a code column is not one.
        foreach (['Amy', '0912345678', 'JohnSmith'] as $typed) {
            $this->getJson('/api/v1/triage/db/ClassSession?eq[Status]=' . $typed, $this->bearer())->assertStatus(422);
        }
        // A column the application lets a person type into is not offered at all, whatever its name.
        $this->assertNotContains('closed_reason', $this->getJson('/api/v1/triage/db/course_packages', $this->bearer())->assertOk()->json('columns'));
        $this->getJson('/api/v1/triage/db/course_packages?eq[closed_reason]=settled', $this->bearer())->assertStatus(422);

        foreach (['auth_tokens', 'personal_access_tokens', 'password_reset_requests', 'ApiClient', 'ParentSession', 'student_line_bindings', 'no_such_table'] as $table) {
            $this->getJson("/api/v1/triage/db/{$table}", $this->bearer())->assertNotFound();
        }
        $tables = $this->getJson('/api/v1/triage/db', $this->bearer())->assertOk()->json('tables');
        $this->assertContains('StudentClass', $tables);
        $this->assertContains('Invoice', $tables);
        $this->assertSame([], array_values(array_intersect($tables, ['auth_tokens', 'personal_access_tokens', 'password_reset_requests', 'ApiClient', 'ParentSession', 'student_line_bindings'])));
    }

    public function test_needs_a_triage_token_takes_no_writes_and_has_a_switch(): void
    {
        $this->getJson('/api/v1/triage/db/Student')->assertUnauthorized();
        $this->getJson('/api/v1/triage/db/Student', ['X-User-Id' => (string) $this->admin->id, 'Accept' => 'application/json'])->assertUnauthorized();
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->json($method, '/api/v1/triage/db/Student', [], $this->bearer())->assertStatus(405);
        }
        config(['triage_tokens.db_read_enabled' => false]);
        $this->getJson('/api/v1/triage/db/Student', $this->bearer())->assertNotFound();
        $this->getJson('/api/v1/triage/db', $this->bearer())->assertNotFound();
    }

    private function bearer(): array
    {
        return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
    }
}
