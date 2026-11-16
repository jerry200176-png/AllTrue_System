<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CleansCommittedRows;
use Tests\TestCase;

/** #5379: a concurrent bill must not be hidden behind an edit's earlier RR snapshot. */
class StudentClassHourlyQuantityRaceTest extends TestCase
{
    use CleansCommittedRows;
    use RefreshDatabase;

    protected $connectionsToTransact = [];

    public function test_teacher_and_duration_edit_serializes_with_a_concurrent_invoice_writer(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
        try {
            $isolation = DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level;
        } catch (QueryException) {
            $isolation = DB::selectOne('SELECT @@tx_isolation AS isolation_level')->isolation_level;
        }
        $this->assertSame('REPEATABLE-READ', $isolation);
        $director = User::create(['LoginName' => uniqid('hourly-race-').'@test.com', 'Name' => 'Synthetic director',
            'PSW' => 'secret', 'type' => 'A', 'phone' => '0900000000', 'MustChangePassword' => false]);
        $teacher = User::create(['LoginName' => uniqid('hourly-teacher-').'@test.com', 'Name' => 'Synthetic teacher',
            'PSW' => 'secret', 'type' => 'B', 'phone' => '0900000001', 'MustChangePassword' => false]);
        foreach ([$director->id, $teacher->id] as $id) {
            UserCampus::create(['CampusID' => 1, 'UserID' => $id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'Synthetic RR student', 'CampusID' => 1,
            'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create(['StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 99, 'by1' => 1, 'Period' => 4, 'StartDate' => '2030-01-07', 'TotalHours' => 1,
            'Rate' => 1200, 'rate_unit' => 'hour', 'Charge' => 1200, 'Paid' => 0, 'Pay' => 0,
            'SessionCount' => 1, 'RemainingSessions' => 1, 'SessionDuration' => 60, 'UsedSessions' => 0,
            'Stop' => 0, 'ScheduleMode' => 'count', 'ClassType' => 'one_on_one', 'MDate' => now()]);
        config(['database.connections.hourly_rr_writer' => config('database.connections.'.config('database.default'))]);
        DB::purge('hourly_rr_writer');
        $writer = DB::connection('hourly_rr_writer');
        $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $attempted = false;
        $committed = false;
        $blocked = false;
        DB::listen(function (QueryExecuted $event) use ($writer, $course, $student, &$attempted, &$committed, &$blocked): void {
            // The teacher-change branch's first plain read inside update's transaction fixes the RR view.
            if ($attempted || $event->connectionName === 'hourly_rr_writer' || DB::transactionLevel() !== 1
                || !str_contains(strtolower($event->sql), 'from `student`')
                || !str_contains(strtolower($event->sql), '`campusid`')) {
                return;
            }
            $attempted = true;
            try {
                $writer->transaction(function () use ($writer, $course, $student): void {
                    // Same course-first issue mutex as InvoiceIssuer; the peer must see committed fixtures.
                    $writer->table('StudentClass')->where('ID', $course->ID)->lockForUpdate()->first();
                    $writer->table('Invoice')->insert(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
                        'IssueDate' => '2030-01-07', 'TotalAmount' => 1200, 'PaidAmount' => 0, 'Status' => 'unpaid']);
                });
                $committed = true;
            } catch (QueryException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1205) {
                    throw $e;
                }
                $blocked = true;
            }
        });
        try {
            $this->withToken($token)->putJson("/api/v1/student-classes/{$course->ID}", [
                'teacher_id' => $teacher->id, 'duration_hours' => 2,
            ])->assertOk();
            $this->assertTrue($attempted, 'Must exercise the actual teacher-change transaction read');
            $this->assertTrue($committed || $blocked, 'Peer either commits first or waits for the course mutex');
            $course->refresh();
            if ($committed) {
                $this->assertSame([1, 1200], [(int) $course->TotalHours, (int) $course->Charge],
                    'An invoice committed during the edit may not be missed by an old RR view');
            } else {
                $this->assertSame([2, 2400], [(int) $course->TotalHours, (int) $course->Charge]);
                $this->assertSame(0, $writer->table('Invoice')->where('StudentClassID', $course->ID)->count());
                $writer->transaction(function () use ($writer, $course, $student): void {
                    $fresh = $writer->table('StudentClass')->where('ID', $course->ID)->lockForUpdate()->first();
                    $writer->table('Invoice')->insert(['StudentID' => $student->id, 'StudentClassID' => $course->ID,
                        'IssueDate' => '2030-01-07', 'TotalAmount' => $fresh->Charge, 'PaidAmount' => 0, 'Status' => 'unpaid']);
                });
            }
            $this->assertSame((int) $course->Charge,
                (int) $writer->table('Invoice')->where('StudentClassID', $course->ID)->value('TotalAmount'));
            fwrite(STDERR, 'hourly_quantity_race='.($committed ? 'invoice-first-preserved' : 'edit-first-writer-wait-retry')."\n");
        } finally {
            DB::disconnect('hourly_rr_writer');
        }
    }
}
