<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** #4188: a close committed on another connection while a plain pause is in flight, CI MySQL only. */
class StudentClassPauseCloseConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // Like SwipeCancellationConcurrencyTest: both connections must see committed fixtures.
    protected $connectionsToTransact = [];

    public function test_close_committed_after_pause_first_read_is_not_overwritten(): void
    {
        $reader = DB::connection();
        if ($reader->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires InnoDB locking reads.');
        }
        $this->assertStringStartsWith('AllTrue_test', $reader->getDatabaseName());
        $this->assertSame(0, $reader->transactionLevel());
        Carbon::setTestNow(Carbon::parse('2026-10-16 10:00:00'));

        $campus = Campus::factory()->create();
        $user = User::create([
            'LoginName' => 'pause-race-' . uniqid() . '@example.com', 'Name' => '暫停競態測試主任',
            'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000',
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create([
            'name' => 'Pause race fixture', 'CampusID' => $campus->id, 'ClassID' => 1,
            'SchoolName' => 'Test School', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1,
            'Period' => 4, 'StartDate' => '2026-09-01', 'TotalHours' => 16, 'Charge' => 0, 'Paid' => 1,
            'Rate' => 0, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => 8,
            'SessionDuration' => 120, 'RemainingSessions' => 3, 'ClassType' => 'one_on_one', 'UsedSessions' => 5,
        ]);

        $name = 'pause_close_race';
        config(["database.connections.$name" => config('database.connections.' . config('database.default'))]);
        DB::purge($name);
        $writer = DB::connection($name);
        $originalEvents = $reader->getEventDispatcher();
        $events = clone $originalEvents;
        $reader->setEventDispatcher($events);
        $closed = false;
        $courseLocked = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use ($reader, $writer, $course, &$closed, &$courseLocked): void {
            if ($query->connection !== $reader) {
                return;
            }
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'for update') && str_contains($sql, 'from `studentclass`')) {
                $courseLocked = true;
            }
            // The pause request has read the course (route binding: still active) but has not
            // written yet. Another tab's close commits now, taking the same row lock first.
            if (!$closed && str_starts_with($sql, 'select') && str_contains($sql, 'from `studentclass`') && !str_contains($sql, 'for update')) {
                $closed = true;
                $writer->transaction(function () use ($writer, $course): void {
                    $writer->table('StudentClass')->where('ID', $course->ID)->lockForUpdate()->first();
                    $writer->table('StudentClass')->where('ID', $course->ID)->update([
                        'Stop' => 1, 'closed_reason' => 'settled', 'EndDate' => '2026-10-16',
                    ]);
                });
            }
        });

        try {
            $response = $this->postJson(
                "/api/v1/student-classes/{$course->ID}/pause",
                ['action' => 'pause'],
                ['Authorization' => "Bearer {$token}"]
            );
            $this->assertTrue($closed, 'The second connection must commit the close.');
            $this->assertSame(409, $response->getStatusCode(), (string) $response->getContent());
            $this->assertSame('course_already_closed', $response->json('error_code'));
            $this->assertTrue($courseLocked, 'The pause must lock the course row before deciding.');
            $row = $writer->table('StudentClass')->where('ID', $course->ID)->first();
            $this->assertSame(1, (int) $row->Stop);
            $this->assertSame('settled', $row->closed_reason);
        } finally {
            $reader->setEventDispatcher($originalEvents);
            $writer->table('ClassSession')->where('StudentClassID', $course->ID)->delete();
            $writer->table('StudentClass')->where('ID', $course->ID)->delete();
            $writer->table('Student')->where('id', $student->id)->delete();
            $writer->table('auth_tokens')->where('user_id', $user->id)->delete();
            $writer->table('UserCampus')->where('UserID', $user->id)->delete();
            $writer->table('User')->where('id', $user->id)->delete();
            $writer->table('Campus')->where('id', $campus->id)->delete();
            DB::purge($name);
            Carbon::setTestNow();
        }
    }
}
