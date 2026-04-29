<?php

namespace Tests\Feature;

use App\Models\ExceptionWorkflow;
use App\Models\Student;
use App\Services\ExceptionWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExceptionWorkflowCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_create_or_get_is_idempotent_by_source_key(): void
    {
        $student = $this->createStudent(1, '閉環學生');
        $service = app(ExceptionWorkflowService::class);

        $first = $service->createOrGet([
            'source_key' => 'parent_leave:class_session:1001',
            'campus_id' => 1,
            'student_id' => $student->id,
            'type' => 'student_leave',
            'status' => 'open',
            'severity' => 'medium',
            'source_type' => 'parent_portal',
            'source_id' => '1001',
            'payload' => ['reason' => '學生請假'],
        ]);

        $second = $service->createOrGet([
            'source_key' => 'parent_leave:class_session:1001',
            'campus_id' => 1,
            'student_id' => $student->id,
            'type' => 'student_leave',
            'status' => 'candidate_ready',
            'severity' => 'high',
            'source_type' => 'parent_portal',
            'source_id' => '1001',
            'payload' => ['reason' => '重送請假'],
        ]);

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertDatabaseCount('exception_workflows', 1);
        $this->assertDatabaseHas('exception_workflows', [
            'id' => $first->id,
            'source_key' => 'parent_leave:class_session:1001',
            'status' => 'open',
            'severity' => 'medium',
        ]);
    }

    public function test_workflow_queries_are_scoped_by_campus(): void
    {
        $service = app(ExceptionWorkflowService::class);
        $studentA = $this->createStudent(1, '大安學生');
        $studentB = $this->createStudent(2, '木柵學生');

        $workflowA = $service->createOrGet([
            'source_key' => 'system:campus1:leave',
            'campus_id' => 1,
            'student_id' => $studentA->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);
        $workflowB = $service->createOrGet([
            'source_key' => 'system:campus2:leave',
            'campus_id' => 2,
            'student_id' => $studentB->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);

        $visibleIds = $service->queryForCampusIds([1])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertContains((int) $workflowA->id, $visibleIds);
        $this->assertNotContains((int) $workflowB->id, $visibleIds);
    }

    public function test_candidate_snapshots_can_be_replaced_for_workflow(): void
    {
        $service = app(ExceptionWorkflowService::class);
        $student = $this->createStudent(1, '候選學生');
        $workflow = $service->createOrGet([
            'source_key' => 'parent_leave:class_session:2001',
            'campus_id' => 1,
            'student_id' => $student->id,
            'type' => 'student_leave',
            'status' => 'open',
        ]);

        $service->replaceCandidates($workflow, [
            [
                'rank' => 1,
                'candidate_date' => '2026-05-06',
                'start_time' => '18:30',
                'end_time' => '20:30',
                'teacher_id' => 8,
                'room_id' => 3,
                'status' => 'available',
                'score' => 95,
                'reasons' => ['同老師', '教室尚有容量'],
                'expires_at' => '2026-05-06 18:20:00',
            ],
        ]);

        $service->replaceCandidates($workflow, [
            [
                'rank' => 1,
                'candidate_date' => '2026-05-07',
                'start_time' => '19:00',
                'end_time' => '21:00',
                'teacher_id' => 8,
                'room_id' => 4,
                'status' => 'warning',
                'score' => 80,
                'reasons' => ['不同教室'],
                'expires_at' => '2026-05-07 18:50:00',
            ],
        ]);

        $this->assertDatabaseCount('exception_workflow_candidates', 1);
        $this->assertDatabaseHas('exception_workflow_candidates', [
            'workflow_id' => $workflow->id,
            'candidate_date' => '2026-05-07',
            'start_time' => '19:00:00',
            'status' => 'warning',
            'score' => 80,
        ]);
    }

    private function createStudent(int $campusId, string $name): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'SchoolName' => '測試學校',
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }
}
