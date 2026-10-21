<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\CoursePackage;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Services\SessionDeductionService;
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

    private function makeSession(StudentClass $c, string $date, string $status, string $start = '16:00:00'): ClassSession
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
        $first = $this->makeSession($course, $d1, 'completed');
        $this->deductedSignIn($course, $first, 'present');
        $this->deductedSignIn($course, $this->makeSession($course, $d2, 'attended'), 'late');
        $noShow = $this->makeSession($course, $d3, 'absent');
        $this->deductedSignIn($course, $noShow, 'absent');
        $this->makeSession($course, now()->addDays(3)->toDateString(), 'scheduled');

        // Another family's lessons must never appear.
        $other = $this->makeStudent('0911000419');
        $otherCourse = $this->makeCourse($other, 3, 10);
        $this->makeSession($otherCourse, now()->subDays(2)->toDateString(), 'completed');

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

    public function test_list_follows_the_winning_evidence_set_when_sources_disagree(): void
    {
        // Codex P1 on #4354: 2 completed sessions without sign-ins + 1 deducted no-show
        // elsewhere -> counter = max(1, 2, 0) = 2; the list must show those 2, not a union of 3.
        $s = $this->makeStudent('0911000421');
        $course = $this->makeCourse($s, 3, 10);
        $d1 = now()->subDays(15)->toDateString();
        $d2 = now()->subDays(8)->toDateString();
        $this->makeSession($course, $d1, 'completed');
        $this->makeSession($course, $d2, 'completed');
        $this->deductedSignIn($course, $this->makeSession($course, now()->subDays(1)->toDateString(), 'absent'), 'absent');

        $card = collect($this->dashboard($s)->assertOk()->json('classes'))->firstWhere('id', $course->ID);

        $this->assertSame(2, $card['used_sessions']);
        $this->assertSame([$d2, $d1], array_column($card['attended_lessons'], 'date'));
    }

    public function test_evidence_count_equals_the_counter_by_construction(): void
    {
        $s = $this->makeStudent('0911000422');
        $a = $this->makeCourse($s, 3, 10);
        $b = $this->makeCourse($s, 4, 10);
        $this->makeSession($a, now()->subDays(9)->toDateString(), 'completed');
        $this->deductedSignIn($a, $this->makeSession($a, now()->subDays(4)->toDateString(), 'attended'), 'present');
        $this->deductedSignIn($b, $this->makeSession($b, now()->subDays(3)->toDateString(), 'absent', '18:00:00'), 'absent');

        $ids = [$a->ID, $b->ID];
        $counts = SessionDeductionService::batchObservedUsedSessions($ids);
        $evidence = SessionDeductionService::batchObservedUsedSessionEvidence($ids);
        foreach ($ids as $id) {
            $this->assertSame($counts[$id], count($evidence[$id] ?? []), "class {$id}");
        }
        $this->assertSame(2, $counts[$a->ID]);

        // Two sign-ins on one session: the latest (MAX id) decides the note (Codex P2).
        $c = $this->makeCourse($s, 2, 10);
        $retaken = $this->makeSession($c, now()->subDays(2)->toDateString(), 'absent', '19:00:00');
        $this->deductedSignIn($c, $retaken, 'absent');
        $this->deductedSignIn($c, $retaken, 'present');
        $this->assertSame(1, SessionDeductionService::batchObservedUsedSessions([$c->ID])[$c->ID]);
        $rows = SessionDeductionService::batchObservedUsedSessionEvidence([$c->ID])[$c->ID];
        $this->assertCount(1, $rows);
        $this->assertSame('present', $rows[0]['status']);
        $this->assertSame(1, $counts[$b->ID]);
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
        $this->makeSession($math, $m1, 'completed');
        $this->makeSession($math, $m2, 'completed');
        $this->makeSession($sci, $p1, 'completed', '18:00:00');

        $classes = collect($this->dashboard($s)->assertOk()->json('classes'));
        $mathCard = $classes->firstWhere('id', $math->ID);
        $sciCard = $classes->firstWhere('id', $sci->ID);

        $this->assertSame(3, $mathCard['used_sessions'], 'shared total stays the pool');
        $this->assertSame([$m2, $m1], array_column($mathCard['attended_lessons'], 'date'));
        $this->assertSame([$p1], array_column($sciCard['attended_lessons'], 'date'));
    }
}
