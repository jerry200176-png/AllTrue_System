<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\LearningRecord;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\StudentSignIn;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** in-app #314 Option 2B — mutable LR ownership follows course teacher. */
class LearningRecordCourseTeacherOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:00:00', 'Asia/Taipei'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_mutable_pending_lr_follows_new_contract_teacher(): void
    {
        [$dir, $old, $neu, $course, $session, $lr] = $this->scenario(pendingFuture: true);
        $this->putTeacher($dir, $course->ID, $neu)->assertOk();
        $lr->refresh();
        $this->assertSame($neu, (int) $lr->TeacherID);
        $this->assertDatabaseHas('learning_record_teacher_changes', [
            'learning_record_id' => $lr->id,
            'reason' => 'course_teacher_change_unperformed_occurrence',
        ]);
        $this->assertEffective($dir, $lr->id, $neu, '新正班');
        $this->assertTeacherSees($old, $lr->id, false);
        $this->assertTeacherSees($neu, $lr->id, true);
    }

    public function test_past_mutable_pending_lr_also_follows_after_teacher_change(): void
    {
        [$dir, $old, $neu, $course, $session, $lr] = $this->scenario(pendingFuture: false);
        $this->assertSame('2026-09-13', $session->SessionDate);
        $this->putTeacher($dir, $course->ID, $neu)->assertOk();
        $lr->refresh();
        $this->assertSame($neu, (int) $lr->TeacherID);
        $falsePin = DB::table('schedules')
            ->where('student_course_id', $course->ID)
            ->whereDate('schedule_date', '2026-09-13')
            ->where('status', 'scheduled')
            ->whereNotNull('original_schedule_id')
            ->where('teacher_id', $old)
            ->exists();
        $this->assertFalse($falsePin);
        $this->assertTeacherSees($old, $lr->id, false);
        $this->assertTeacherSees($neu, $lr->id, true);
    }

    public function test_pending_with_attended_evidence_keeps_historical_teacher(): void
    {
        [$dir, $old, $neu, $course, $session, $lr] = $this->scenario(pendingFuture: true);
        $session->Status = 'attended';
        $session->save();
        StudentSignIn::create([
            'StudentClassID' => $course->ID, 'StudentID' => (int) $course->StudentID,
            'TeacherID' => $old, 'GradeID' => 1, 'SubjectID' => 1, 'CampusID' => 1,
            'SignInDT' => '2026-09-20 13:05:00', 'MDT' => now(),
            'ClassSessionID' => $session->id, 'Status' => 'present', 'SessionDeducted' => 1,
        ]);
        $this->putTeacher($dir, $course->ID, $neu)->assertOk();
        $lr->refresh();
        $this->assertSame($old, (int) $lr->TeacherID);
        $this->assertEffective($dir, $lr->id, $old, '舊正班');
    }

    public function test_approved_and_substantive_pending_are_not_restamped(): void
    {
        [$dir, $old, $neu, $course, , $lrApproved] = $this->scenario(pendingFuture: true, suffix: 'ap');
        $lrApproved->update(['Status' => 'approved', 'Progress' => '已授課', 'ApprovedAt' => now()]);
        [$dir2, $old2, $neu2, $course2, , $lrSubstantive] = $this->scenario(pendingFuture: true, suffix: 'su');
        $lrSubstantive->update(['Progress' => '第3單元', 'NextHomework' => 'p.10']);

        $this->putTeacher($dir, $course->ID, $neu)->assertOk();
        $this->putTeacher($dir2, $course2->ID, $neu2)->assertOk();

        $this->assertSame($old, (int) $lrApproved->fresh()->TeacherID);
        $this->assertSame($old2, (int) $lrSubstantive->fresh()->TeacherID);
        $this->assertDatabaseMissing('learning_record_teacher_changes', [
            'learning_record_id' => $lrApproved->id,
            'reason' => 'course_teacher_change_unperformed_occurrence',
        ]);
        $this->assertDatabaseMissing('learning_record_teacher_changes', [
            'learning_record_id' => $lrSubstantive->id,
            'reason' => 'course_teacher_change_unperformed_occurrence',
        ]);
    }

    public function test_formal_substitute_not_overridden(): void
    {
        [$dir, , $neu, $course, $session, $lr] = $this->scenario(pendingFuture: true);
        $sub = $this->teacher(1, 'sub-314@example.com', '代課314');
        $this->withHeaders($this->auth($dir))->postJson("/api/v1/class-sessions/{$session->id}/substitute", [
            'substitute_teacher_id' => $sub,
        ])->assertOk();
        $this->putTeacher($dir, $course->ID, $neu)->assertOk();
        $this->assertSame($sub, (int) $lr->fresh()->TeacherID);
        $this->assertEffective($dir, $lr->id, $sub, '代課314');
    }

    public function test_teacher_change_does_not_manufacture_lrs(): void
    {
        [$dir, , $neu, $course] = $this->scenario(pendingFuture: true, withLr: false);
        $this->assertSame(0, LearningRecord::where('StudentClassID', $course->ID)->count());
        $this->putTeacher($dir, $course->ID, $neu)->assertOk();
        $this->assertSame(0, LearningRecord::where('StudentClassID', $course->ID)->count());
    }

    public function test_stale_mutable_stamp_display_queue_and_edit_agree(): void
    {
        [$dir, $old, $neu, $course, , $lr] = $this->scenario(pendingFuture: true);
        DB::table('StudentClass')->where('ID', $course->ID)->update(['TeacherID' => $neu]);
        DB::table('LearningRecord')->where('id', $lr->id)->update(['TeacherID' => $old]);
        $lr->refresh();
        $this->assertEffective($dir, $lr->id, $neu, '新正班');
        $this->assertTeacherSees($old, $lr->id, false);
        $this->assertTeacherSees($neu, $lr->id, true);
        $newRes = $this->withHeaders($this->auth($this->tokenFor($neu)))
            ->putJson("/api/v1/learning-records/{$lr->id}", ['Progress' => 'ok']);
        $this->assertNotSame(403, $newRes->status(), 'new contract teacher must be actionable owner');
        $this->withHeaders($this->auth($this->tokenFor($old)))
            ->putJson("/api/v1/learning-records/{$lr->id}", ['Progress' => 'nope'])
            ->assertStatus(403);
    }

    private function scenario(bool $pendingFuture, bool $withLr = true, string $suffix = ''): array
    {
        $dir = $this->director([1], "dir-314{$suffix}@example.com");
        $old = $this->teacher(1, "old-314{$suffix}@example.com", '舊正班');
        $neu = $this->teacher(1, "new-314{$suffix}@example.com", '新正班');
        $student = Student::create([
            'name' => "Ownership{$suffix}", 'CampusID' => 1, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $course = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => $old,
            'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4, 'StartDate' => '2026-09-01',
            'TotalHours' => 16, 'SessionCount' => 8, 'SessionDuration' => 120, 'RemainingSessions' => 6,
            'UsedSessions' => 2, 'Charge' => 1600, 'Pay' => 12800, 'Paid' => 0, 'Rate' => 800,
            'Stop' => 0, 'MDate' => now(), 'ScheduleMode' => 'count',
        ]);
        $date = $pendingFuture ? '2026-09-20' : '2026-09-13';
        $session = ClassSession::create([
            'StudentClassID' => $course->ID, 'SessionDate' => $date,
            'StartTime' => '13:00', 'EndTime' => '15:00', 'Status' => 'scheduled',
        ]);
        if (!$withLr) {
            return [$dir, $old, $neu, $course, $session];
        }
        $lr = LearningRecord::create([
            'StudentClassID' => $course->ID, 'ClassSessionID' => $session->id,
            'TeacherID' => $old, 'Status' => 'pending', 'Content' => '',
            'SessionDate' => $date, 'StartTime' => '13:00', 'EndTime' => '15:00',
        ]);

        return [$dir, $old, $neu, $course, $session, $lr];
    }

    private function putTeacher(string $dir, int $courseId, int $teacherId)
    {
        return $this->withHeaders($this->auth($dir))->putJson("/api/v1/student-classes/{$courseId}", [
            'teacher_id' => $teacherId,
        ]);
    }

    private function assertEffective(string $dir, int $lrId, int $tid, string $name): void
    {
        $hit = collect($this->withHeaders($this->auth($dir))
            ->getJson('/api/v1/learning-records?branch_id=1&per_page=50')->json('data'))
            ->firstWhere('id', $lrId);
        $this->assertNotNull($hit);
        $this->assertSame($tid, (int) ($hit['effective_teacher_id'] ?? 0));
        $this->assertSame($name, $hit['teacher_name'] ?? '');
    }

    private function assertTeacherSees(int $teacherId, int $lrId, bool $shouldSee): void
    {
        $ids = collect($this->withHeaders($this->auth($this->tokenFor($teacherId)))
            ->getJson('/api/v1/learning-records')->json('data'))
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($shouldSee) {
            $this->assertContains($lrId, $ids);
        } else {
            $this->assertNotContains($lrId, $ids);
        }
    }

    private function director(array $campuses, string $login): string
    {
        $user = User::create([
            'LoginName' => $login, 'Name' => '主任314', 'PSW' => 'x', 'type' => 'A',
            'phone' => '0912000314', 'MustChangePassword' => false,
        ]);
        foreach ($campuses as $c) {
            UserCampus::create(['CampusID' => $c, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }

        return $this->tokenFor((int) $user->id);
    }

    private function teacher(int $campus, string $login, string $name): int
    {
        $user = User::create([
            'LoginName' => $login, 'Name' => $name, 'PSW' => 'x', 'type' => 'T',
            'phone' => '0912' . substr(md5($login), 0, 6), 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus, 'UserID' => $user->id, 'Admin' => 0, 'Approved' => 1]);

        return (int) $user->id;
    }

    private function tokenFor(int $userId): string
    {
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $userId, 'token' => $tok, 'expires_at' => now()->addDay()]);

        return $tok;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
