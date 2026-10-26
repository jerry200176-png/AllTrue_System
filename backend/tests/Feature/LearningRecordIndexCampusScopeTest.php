<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The learning-record list keeps the director's campus scope when it is filtered by course ids. Synthetic data only. */
class LearningRecordIndexCampusScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_student_class_ids_does_not_widen_the_directors_campus_scope(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-18 08:00:00', 'Asia/Taipei'));
        $student = Student::create(['name' => 'Index scope', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1, 'by1' => 1, 'Period' => 8,
            'StartDate' => '2026-04-01', 'TotalHours' => 16, 'Charge' => 0, 'Rate' => 1000, 'SessionCount' => 8,
            'RemainingSessions' => 5, 'SessionDuration' => 120, 'week' => 5, 'time' => '16:00:00', 'class_type' => 'one_on_one',
            'ScheduleMode' => 'count', 'Stop' => 0, 'Paid' => 1, 'MDT' => now(),
        ]);
        $session = ClassSession::create(['StudentClassID' => $course->ID, 'SessionDate' => '2026-04-10', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00', 'Status' => 'attended']);
        $record = LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $session->id, 'TeacherID' => 1, 'Subject' => 'Math',
            'Content' => '評量正文', 'Status' => 'pending', 'SessionDate' => '2026-04-10', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00',
        ]);
        $url = '/api/v1/learning-records?per_page=50&start_date=2026-04-01&end_date=2026-04-30&student_class_ids=' . $course->ID;

        $foreign = collect($this->api($this->directorToken([2]))->getJson($url)->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains((int) $record->id, $foreign->map(fn ($id) => (int) $id)->all(), 'a campus-2 director must not read a campus-1 record by course id');

        $own = collect($this->api($this->directorToken([1]))->getJson($url)->assertOk()->json('data'))->pluck('id');
        $this->assertContains((int) $record->id, $own->map(fn ($id) => (int) $id)->all());
    }

    /** @param list<int> $campusIds */
    private function directorToken(array $campusIds): string
    {
        $user = User::create(['LoginName' => 'idx-' . uniqid() . '@scope.example.com', 'Name' => 'dir', 'PSW' => 'x', 'type' => 'A',
            'phone' => '09' . random_int(10000000, 99999999), 'MustChangePassword' => false]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $raw = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $raw, 'expires_at' => now()->addDay()]);

        return $raw;
    }

    private function api(string $token)
    {
        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json']);
    }
}
