<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 回歸測試：已暫停課程（Stop=1）取消堂次後不應自動補建新堂。
 *
 * 根本原因：tryExtendOnLeave 未檢查 Stop=1，
 * 導致取消動作觸發「補建下一堂」，產生「取消完又補回」症狀。
 */
class CancelSessionPausedCourseTest extends TestCase
{
    use RefreshDatabase;

    private function makeToken(): string
    {
        $user = User::create([
            'LoginName' => 'pause-cancel-' . uniqid() . '@example.com',
            'Name'      => '暫停取消測試主任',
            'PSW'       => bcrypt('pw'),
            'type'      => 'A',
            'status'    => 'active',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }

    /**
     * @test
     * 暫停課程（Stop=1）取消 scheduled 堂次後，總堂次數不應增加。
     */
    public function cancelling_scheduled_session_on_paused_course_does_not_create_replacement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        try {
            $token   = $this->makeToken();
            $student = Student::create([
                'name' => '周宥叡', 'CampusID' => 1, 'ClassID' => 1,
                'SchoolName' => 'Test', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
            ]);
            $course = StudentClass::create([
                'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
                'TeacherID' => 1, 'by1' => 1, 'Period' => 4,
                'StartDate' => '2026-01-03', 'TotalHours' => 0, 'Charge' => 0,
                'Paid' => 0, 'Rate' => 0, 'MDate' => now(),
                'Stop' => 1,
                'ScheduleMode' => 'count',
                'SessionCount' => 10,
                'SessionDuration' => 60,
                'RemainingSessions' => 4,
                'UsedSessions' => 6,
                'ClassType' => 'one_on_one',
                'week' => 4, 'time' => '15:00',
            ]);

            DB::table('ClassSession')->insert([
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-01-09', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended', 'Note' => ''],
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-01-16', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended', 'Note' => ''],
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-01-23', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended', 'Note' => ''],
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-02-06', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended', 'Note' => ''],
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-02-13', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended', 'Note' => ''],
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-02-20', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'attended', 'Note' => ''],
                ['StudentClassID' => $course->ID, 'SessionDate' => '2026-05-14', 'StartTime' => '15:00', 'EndTime' => '16:00', 'Status' => 'scheduled', 'Note' => ''],
            ]);

            $sessionId   = DB::table('ClassSession')
                ->where('StudentClassID', $course->ID)->where('SessionDate', '2026-05-14')->value('id');
            $countBefore = DB::table('ClassSession')->where('StudentClassID', $course->ID)->count();

            $this->withHeaders(['Authorization' => "Bearer {$token}"])
                ->patchJson("/api/v1/class-sessions/{$sessionId}", ['status' => 'cancelled'])
                ->assertOk();

            $countAfter = DB::table('ClassSession')->where('StudentClassID', $course->ID)->count();

            $this->assertSame($countBefore, $countAfter,
                "暫停課程取消堂次後不應補建（before={$countBefore}, after={$countAfter}）");
            $this->assertSame('cancelled',
                DB::table('ClassSession')->where('id', $sessionId)->value('Status'));
        } finally {
            Carbon::setTestNow();
        }
    }
}
