<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #889 PII deletion completeness: purging a student must not leave orphan rows
 * in student_line_bindings, parent_feedback(_replies), learning_record_feedbacks(_replies),
 * feedback_push_log, or learning_record_teacher_comments.
 */
class StudentPurgeOrphanPiiTest extends TestCase
{
    use RefreshDatabase;

    private function auth(): array
    {
        $user = User::create([
            'LoginName' => 'purge-pii-' . uniqid() . '@example.com',
            'Name' => '測試主任',
            'PSW' => 'secret',
            'type' => 'D',
            'phone' => '0900000001',
        ]);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return ['Authorization' => "Bearer {$token}"];
    }

    private function seedStudentWithPiiRelations(string $label): array
    {
        $student = Student::create([
            'name' => $label,
            'CampusID' => 1,
            'ClassID' => 1,
            'SchoolName' => '測試校',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $course = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'TotalHours' => 10,
            'Charge' => 0,
            'Paid' => 0,
            'Rate' => 0,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 4,
            'SessionDuration' => 60,
            'RemainingSessions' => 4,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);

        $sessionId = DB::table('ClassSession')->insertGetId([
            'StudentClassID' => $course->ID,
            'SessionDate' => '2026-04-05',
            'StartTime' => '18:00:00',
            'EndTime' => '19:00:00',
            'Status' => 'scheduled',
            'Note' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lrId = DB::table('LearningRecord')->insertGetId([
            'StudentClassID' => $course->ID,
            'ClassSessionID' => $sessionId,
            'TeacherID' => 99,
            'Content' => '單元一練習',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('student_line_bindings')->insert([
            'student_id' => $student->id,
            'line_user_id' => 'U' . bin2hex(random_bytes(8)),
            'campus_id' => 1,
            'bound_at' => now(),
        ]);

        $pfId = DB::table('parent_feedback')->insertGetId([
            'student_id' => $student->id,
            'campus_id' => 1,
            'category' => 'other',
            'content' => '家長回饋內容',
            'is_read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('parent_feedback_replies')->insert([
            'feedback_id' => $pfId,
            'user_id' => 1,
            'replier_role' => 'director',
            'content' => '主任回覆內容',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lrfId = DB::table('learning_record_feedbacks')->insertGetId([
            'learning_record_id' => $lrId,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'teacher_id' => 99,
            'campus_id' => 1,
            'content' => '學習紀錄家長留言',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learning_record_feedback_replies')->insert([
            'feedback_id' => $lrfId,
            'author_role' => 'teacher',
            'content' => '老師回覆留言',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('feedback_push_log')->insert([
            'feedback_id' => $lrfId,
            'direction' => 'to_staff',
            'campus_id' => 1,
            'learning_record_id' => $lrId,
            'last_pushed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('learning_record_teacher_comments')->insert([
            'learning_record_id' => $lrId,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'teacher_id' => 99,
            'campus_id' => 1,
            'author_user_id' => 1,
            'content' => '老師內部評語',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['student' => $student, 'pfId' => $pfId, 'lrfId' => $lrfId];
    }

    public function test_destroy_purges_orphan_pii_relations_without_touching_other_students(): void
    {
        $target = $this->seedStudentWithPiiRelations('待刪除合成生A');
        $other = $this->seedStudentWithPiiRelations('保留合成生B');

        $res = $this->deleteJson("/api/v1/students/{$target['student']->id}", [], $this->auth())
            ->assertOk();

        $res->assertJsonPath('deleted.student_line_bindings', 1)
            ->assertJsonPath('deleted.parent_feedback', 1)
            ->assertJsonPath('deleted.parent_feedback_replies', 1)
            ->assertJsonPath('deleted.learning_record_feedbacks', 1)
            ->assertJsonPath('deleted.learning_record_feedback_replies', 1)
            ->assertJsonPath('deleted.feedback_push_log', 1)
            ->assertJsonPath('deleted.learning_record_teacher_comments', 1)
            ->assertJsonPath('deleted.Student', 1);

        $targetId = $target['student']->id;
        $otherId = $other['student']->id;

        $this->assertSame(0, DB::table('student_line_bindings')->where('student_id', $targetId)->count());
        $this->assertSame(0, DB::table('parent_feedback')->where('student_id', $targetId)->count());
        $this->assertSame(0, DB::table('parent_feedback_replies')->where('feedback_id', $target['pfId'])->count());
        $this->assertSame(0, DB::table('learning_record_feedbacks')->where('student_id', $targetId)->count());
        $this->assertSame(0, DB::table('learning_record_feedback_replies')->where('feedback_id', $target['lrfId'])->count());
        $this->assertSame(0, DB::table('feedback_push_log')->where('feedback_id', $target['lrfId'])->count());
        $this->assertSame(0, DB::table('learning_record_teacher_comments')->where('student_id', $targetId)->count());

        // Other student's records remain completely untouched.
        $this->assertSame(1, DB::table('student_line_bindings')->where('student_id', $otherId)->count());
        $this->assertSame(1, DB::table('parent_feedback')->where('student_id', $otherId)->count());
        $this->assertSame(1, DB::table('parent_feedback_replies')->where('feedback_id', $other['pfId'])->count());
        $this->assertSame(1, DB::table('learning_record_feedbacks')->where('student_id', $otherId)->count());
        $this->assertSame(1, DB::table('learning_record_feedback_replies')->where('feedback_id', $other['lrfId'])->count());
        $this->assertSame(1, DB::table('feedback_push_log')->where('feedback_id', $other['lrfId'])->count());
        $this->assertSame(1, DB::table('learning_record_teacher_comments')->where('student_id', $otherId)->count());
    }

    public function test_bulk_destroy_also_purges_orphan_pii_relations(): void
    {
        $s1 = $this->seedStudentWithPiiRelations('批次刪除合成生1');
        $s2 = $this->seedStudentWithPiiRelations('批次刪除合成生2');

        $this->postJson('/api/v1/students/bulk-delete', [
            'student_ids' => [$s1['student']->id, $s2['student']->id],
        ], $this->auth())->assertOk()
            ->assertJsonPath('deleted.student_line_bindings', 2)
            ->assertJsonPath('deleted.parent_feedback', 2)
            ->assertJsonPath('deleted.parent_feedback_replies', 2)
            ->assertJsonPath('deleted.learning_record_feedbacks', 2)
            ->assertJsonPath('deleted.learning_record_feedback_replies', 2)
            ->assertJsonPath('deleted.feedback_push_log', 2)
            ->assertJsonPath('deleted.learning_record_teacher_comments', 2);

        $this->assertSame(0, DB::table('student_line_bindings')->whereIn('student_id', [$s1['student']->id, $s2['student']->id])->count());
        $this->assertSame(0, DB::table('parent_feedback')->whereIn('student_id', [$s1['student']->id, $s2['student']->id])->count());
        $this->assertSame(0, DB::table('learning_record_feedbacks')->whereIn('student_id', [$s1['student']->id, $s2['student']->id])->count());
        $this->assertSame(0, DB::table('learning_record_teacher_comments')->whereIn('student_id', [$s1['student']->id, $s2['student']->id])->count());
    }
}
