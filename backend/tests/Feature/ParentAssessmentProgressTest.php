<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentRemediationAction;
use App\Models\AssessmentResult;
use App\Models\Student;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentAssessmentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_sees_only_reviewed_results_and_safe_remediation_summary(): void
    {
        $student = $this->createStudent('家長檢測學生', '0913000333');
        $course = $this->createStudentClass($student->id);
        $assessment = Assessment::create([
            'campus_id' => 1,
            'student_class_id' => $course->ID,
            'subject_id' => 1,
            'title' => '英文單元檢測',
            'assessment_type' => 'checkpoint',
            'status' => 'published',
            'max_score' => 100,
            'passing_score' => 70,
        ]);
        $reviewed = AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'attempt_no' => 1,
            'score' => 62,
            'max_score_snapshot' => 100,
            'percent' => 62,
            'status' => 'reviewed',
            'notes' => '老師內部備註，不應出現在家長端',
            'reviewed_at' => now(),
        ]);
        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'attempt_no' => 2,
            'score' => 88,
            'max_score_snapshot' => 100,
            'percent' => 88,
            'status' => 'submitted',
        ]);
        AssessmentRemediationAction::create([
            'assessment_id' => $assessment->id,
            'assessment_result_id' => $reviewed->id,
            'campus_id' => 1,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'knowledge_tag' => '英文／過去式',
            'action_type' => 'practice',
            'status' => 'open',
            'plan' => '內部補強計畫，不應出現在家長端',
        ]);
        $draftAssessment = Assessment::create([
            'campus_id' => 1,
            'student_class_id' => $course->ID,
            'title' => '尚未發布的檢測',
            'assessment_type' => 'checkpoint',
            'status' => 'draft',
            'max_score' => 100,
        ]);
        AssessmentResult::create([
            'assessment_id' => $draftAssessment->id,
            'student_id' => $student->id,
            'student_class_id' => $course->ID,
            'attempt_no' => 1,
            'score' => 100,
            'max_score_snapshot' => 100,
            'percent' => 100,
            'status' => 'reviewed',
            'reviewed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $this->parentLogin($student->name, '0913000333'),
        ]);

        $response->assertOk()->assertJsonStructure([
            'assessment_progress' => [
                'version',
                'items' => [[
                    'student_name', 'campus_name', 'title', 'subject', 'score',
                    'max_score', 'percent', 'outcome', 'outcome_label',
                    'remediation_status', 'remediation_status_label', 'focus_areas', 'reviewed_at',
                ]],
                'meta' => ['total_reviewed', 'returned', 'has_more'],
            ],
        ]);

        $payload = $response->json('assessment_progress');
        $this->assertSame('v1', $payload['version']);
        $this->assertSame(1, $payload['meta']['total_reviewed']);
        $this->assertCount(1, $payload['items']);
        $item = $payload['items'][0];
        $this->assertSame('建議再練習', $item['outcome_label']);
        $this->assertSame('待開始補強', $item['remediation_status_label']);
        $this->assertSame(['英文／過去式'], $item['focus_areas']);
        $this->assertArrayNotHasKey('notes', $item);
        $this->assertArrayNotHasKey('plan', $item);
        $this->assertArrayNotHasKey('id', $item);
    }

    public function test_parent_does_not_receive_another_students_assessment(): void
    {
        $student = $this->createStudent('家長範圍學生', '0913000444');
        $other = $this->createStudent('其他家庭學生', '0913000555');
        $this->createStudentClass($student->id);
        $otherCourse = $this->createStudentClass($other->id);
        $assessment = Assessment::create([
            'campus_id' => 1,
            'student_class_id' => $otherCourse->ID,
            'title' => '不應外洩的檢測',
            'assessment_type' => 'baseline',
            'status' => 'published',
            'max_score' => 50,
        ]);
        AssessmentResult::create([
            'assessment_id' => $assessment->id,
            'student_id' => $other->id,
            'student_class_id' => $otherCourse->ID,
            'attempt_no' => 1,
            'score' => 49,
            'max_score_snapshot' => 50,
            'percent' => 98,
            'status' => 'reviewed',
            'reviewed_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/parent/dashboard', [
            'Authorization' => 'Bearer ' . $this->parentLogin($student->name, '0913000444'),
        ]);

        $response->assertOk();
        $this->assertSame([], $response->json('assessment_progress.items'));
        $this->assertSame(0, $response->json('assessment_progress.meta.total_reviewed'));
    }

    private function createStudent(string $name, string $phone): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
        ]);
    }

    private function createStudentClass(int $studentId): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => '2026-04-01',
            'EndDate' => '2026-12-31',
            'TotalHours' => 8,
            'Charge' => 8800,
            'Paid' => 1,
            'Rate' => 1100,
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 8,
            'SessionDuration' => 60,
            'RemainingSessions' => 8,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }

    private function parentLogin(string $name, string $phone): string
    {
        return $this->postJson('/api/v1/parent/login', ['Name' => $name, 'Phone' => $phone])
            ->assertOk()
            ->json('token');
    }
}
