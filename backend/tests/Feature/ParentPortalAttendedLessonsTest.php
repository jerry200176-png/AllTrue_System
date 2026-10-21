<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * in-app #418 (GitHub #4274): each count-mode course card in the parent portal
 * lists the dates behind its own 「已上 N 堂」, so the parent does not have to
 * switch to the mixed 學習 timeline and count by subject.
 */
class ParentPortalAttendedLessonsTest extends TestCase
{
    use RefreshDatabase;

    private function makeStudent(string $phone): Student
    {
        return Student::create([
            'name' => '上課日期測試生', 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '', 'Phone' => $phone,
        ]);
    }

    private function token(Student $student): string
    {
        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);

        return $raw;
    }

    private function makeCourse(Student $s, int $subjectId, int $count, ?int $packageId = null): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $s->id, 'GradeID' => 1, 'SubjectID' => $subjectId, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subMonths(2)->toDateString(), 'TotalHours' => $count,
            'Charge' => 0, 'Paid' => 1, 'Rate' => 0, 'MDate' => now()->toDateString(),
            'Stop' => 0, 'ScheduleMode' => 'count', 'SessionCount' => $count, 'RemainingSessions' => $count,
            'UsedSessions' => 0, 'SessionDuration' => 90, 'ClassType' => 'one_on_one',
            'PackageID' => $packageId, 'CampusID' => 1,
        ]);
    }

    private function session(StudentClass $c, string $date, string $status, string $start = '16:00:00'): ClassSession
    {
        return ClassSession::create([
            'StudentClassID' => $c->ID, 'SessionDate' => $date,
            'StartTime' => $start, 'EndTime' => '17:30:00', 'Status' => $status,
        ]);
    }

    private function deductedSignIn(StudentClass $c, ClassSession $cs, string $status): void
    {
        StudentSignIn::create([
            'StudentClassID' => $c->ID, 'StudentID' => $c->StudentID, 'TeacherID' => 1,
            'Memo' => 'test', 'SignInDT' => "{$cs->SessionDate} 16:00:00", 'SignOutDT' => "{$cs->SessionDate} 17:30:00",
            'MDT' => now(), 'ClassSessionID' => $cs->id, 'Status' => $status, 'CampusID' => 1,
            'PersonType' => 'student', 'SessionDeducted' => true,
        ]);
    }

    private function dashboard(Student $s)
    {
        return $this->getJson('/api/v1/parent/dashboard', ['Authorization' => 'Bearer ' . $this->token($s)]);
    }

    public function test_count_course_lists_own_dates_newest_first_matching_used_count(): void
    {
        $s = $this->makeStudent('0911000418');
        $course = $this->makeCourse($s, 3, 10);
        $d1 = now()->subDays(20)->toDateString();
        $d2 = now()->subDays(13)->toDateString();
        $d3 = now()->subDays(6)->toDateString();
        $first = $this->session($course, $d1, 'completed');
        $this->deductedSignIn($course, $first, 'present');
        $this->deductedSignIn($course, $this->session($course, $d2, 'attended'), 'late');
        $noShow = $this->session($course, $d3, 'absent');
        $this->deductedSignIn($course, $noShow, 'absent');
        $this->session($course, now()->addDays(3)->toDateString(), 'scheduled');

        // Another family's lessons must never appear.
        $other = $this->makeStudent('0911000419');
        $otherCourse = $this->makeCourse($other, 3, 10);
        $this->session($otherCourse, now()->subDays(2)->toDateString(), 'completed');

        $res = $this->dashboard($s)->assertOk();
        $card = collect($res->json('classes'))->firstWhere('id', $course->ID);
        $this->assertNotNull($card);

        $lessons = $card['attended_lessons'];
        $this->assertSame([$d3, $d2, $d1], array_column($lessons, 'date'), 'newest first, future and other student excluded');
        $this->assertCount($card['used_sessions'], $lessons, 'list length equals the card 已上 count');
        $this->assertSame('16:00-17:30', $lessons[2]['time']);
        $this->assertSame('缺席（已扣堂）', $lessons[0]['note']);
        $this->assertNull($lessons[1]['note']);
        $this->assertCount(1, $res->json('classes'), 'only the bound student course is returned');
    }

    public function test_package_members_show_their_own_dates_under_the_shared_total(): void
    {
        $s = $this->makeStudent('0911000420');
        $pkg = CoursePackage::create([
            'student_id' => $s->id, 'campus_id' => 1, 'name' => '共用方案',
            'billing_mode' => 'count', 'total_sessions' => 12, 'used_sessions' => 3,
            'remaining_sessions' => 9, 'rate' => 0, 'rate_unit' => 'session',
            'class_type' => 'one_on_one', 'paid' => true, 'stop' => false, 'enabled' => true,
        ]);
        $math = $this->makeCourse($s, 3, 12, $pkg->id);
        $sci = $this->makeCourse($s, 4, 12, $pkg->id);
        $m1 = now()->subDays(9)->toDateString();
        $m2 = now()->subDays(2)->toDateString();
        $p1 = now()->subDays(5)->toDateString();
        $this->session($math, $m1, 'completed');
        $this->session($math, $m2, 'completed');
        $this->session($sci, $p1, 'completed', '18:00:00');

        $classes = collect($this->dashboard($s)->assertOk()->json('classes'));
        $mathCard = $classes->firstWhere('id', $math->ID);
        $sciCard = $classes->firstWhere('id', $sci->ID);

        $this->assertSame(3, $mathCard['used_sessions'], 'shared total stays the pool');
        $this->assertSame([$m2, $m1], array_column($mathCard['attended_lessons'], 'date'));
        $this->assertSame([$p1], array_column($sciCard['attended_lessons'], 'date'));
    }
}
