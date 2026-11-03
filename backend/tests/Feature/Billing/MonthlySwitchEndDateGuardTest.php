<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** In-app #464: a count course switched to monthly with EndDate = StartDate while 4 weekly lessons are scheduled. */
class MonthlySwitchEndDateGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 18:00:00');
        $user = User::create(['LoginName' => 'd4765@test.com', 'Name' => 'd', 'PSW' => 'x', 'type' => 'A', 'phone' => 912345678]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $this->token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $this->token, 'expires_at' => now()->addDay()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_switch_with_end_date_before_scheduled_lessons_is_refused_and_writes_nothing(): void
    {
        $sc = $this->countCourse();
        $rows = fn () => ClassSession::where('StudentClassID', $sc->ID)->orderBy('ID')->get(['ID', 'SessionDate', 'Status'])->toArray();
        $before = $rows();
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['payment_type' => 'monthly', 'start_date' => '2026-11-03', 'end_date' => '2026-11-03'])
            ->assertStatus(422)->assertJsonPath('code', 'contract_dates_exclude_scheduled_lessons')
            ->assertJsonPath('lesson_dates', ['2026-11-10', '2026-11-17', '2026-11-24'])
            ->assertJsonPath('message', '月結合約的結束日 11/3 之後還有 11/10、11/17、11/24 的課。請把結束日改到最後一堂（11/24）或之後，或先取消這些堂次。沒有修改任何資料。');
        $this->assertSame('count', $sc->fresh()->ScheduleMode);
        $this->assertSame($before, $rows());
    }

    public function test_switch_with_covering_end_date_is_saved(): void
    {
        $sc = $this->countCourse();
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['payment_type' => 'monthly', 'start_date' => '2026-11-03', 'end_date' => '2026-11-24'])
            ->assertOk();
        $this->assertSame('date', $sc->fresh()->ScheduleMode);
    }

    public function test_shortening_a_monthly_end_date_over_scheduled_lessons_is_refused_even_without_switch(): void
    {
        $sc = $this->countCourse();
        $sc->update(['ScheduleMode' => 'date', 'EndDate' => '2026-11-24']);
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", ['end_date' => '2026-11-10'])
            ->assertStatus(422)->assertJsonPath('code', 'contract_dates_exclude_scheduled_lessons');
        $this->assertSame('2026-11-24', substr((string) $sc->fresh()->EndDate, 0, 10));
    }

    public function test_edit_whose_schedule_rebuild_leaves_nothing_outside_is_saved(): void
    {
        $sc = $this->countCourse();
        $sc->update(['ScheduleMode' => 'date', 'EndDate' => '2026-11-24']);
        $this->api('putJson', "/api/v1/student-classes/{$sc->ID}", [
            'start_date' => '2026-11-03', 'end_date' => '2026-11-10', 'duration_hours' => 2, 'days_of_week' => [2], 'start_time' => '18:00',
            'day_time_slots' => [['day' => 2, 'start_time' => '18:00', 'duration_minutes' => 120]],
        ])->assertOk();
        $this->assertSame('2026-11-10', substr((string) $sc->fresh()->EndDate, 0, 10));
        $this->assertSame(0, ClassSession::where('StudentClassID', $sc->ID)->where('SessionDate', '>', '2026-11-10')->count());
    }

    private function countCourse(): StudentClass
    {
        $sc = $this->course([], ['StartDate' => '2026-11-03', 'EndDate' => null, 'ScheduleMode' => 'count', 'SessionCount' => 4, 'RemainingSessions' => 4]);
        foreach (['2026-11-03', '2026-11-10', '2026-11-17', '2026-11-24'] as $d) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $d, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'scheduled']);
        }

        return $sc;
    }

    private function course(array $lessons = ['2026-09-29' => 'attended', '2026-09-30' => 'attended'], array $overrides = []): StudentClass
    {
        $student = Student::create(['name' => 's'.uniqid(), 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'Phone' => '0912345678', 'MDT' => now(), 'Notify_Token' => '']);
        $sc = StudentClass::create($overrides + [
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 99, 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-10-10', 'EndDate' => '2026-10-31', 'TotalHours' => 0, 'Charge' => 1800, 'Paid' => 0, 'Rate' => 1800,
            'rate_unit' => 'session', 'MDate' => now(), 'Stop' => 1, 'ScheduleMode' => 'date', 'SessionCount' => 0,
            'SessionDuration' => 120, 'RemainingSessions' => 0, 'ClassType' => 'one_on_one', 'UsedSessions' => 0,
        ]);
        foreach ($lessons as $date => $status) {
            ClassSession::create(['StudentClassID' => $sc->ID, 'SessionDate' => $date, 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => $status]);
        }

        return $sc;
    }

    private function api(string $method, string $uri, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->{$method}($uri, $body);
    }
}
