<?php

namespace Tests\Feature;

use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Services\StudentIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrossCampusParentPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_group_can_aggregate_two_campus_dashboards(): void
    {
        $first = $this->student(1, '跨校王小明', '0912888000');
        $second = $this->student(2, '跨校王小明', '0912888000');
        $firstClass = $this->course($first->id, 8);
        $secondClass = $this->course($second->id, 6);

        $service = app(StudentIdentityService::class);
        $group = $service->linkStudents($first->id, $second->id, null, 'super_admin', []);
        $service->setMode($group->id, StudentIdentityService::MODE_READONLY, null);

        $login = $this->postJson('/api/v1/parent/login', [
            'Name' => '跨校王小明',
            'Phone' => '0912888000',
        ])->assertOk();

        $dashboard = $this->getJson('/api/v1/parent/dashboard?scope=all', [
            'Authorization' => 'Bearer ' . $login->json('token'),
        ])->assertOk();

        $dashboard->assertJsonPath('identity_group_id', $group->id);
        $dashboard->assertJsonPath('active_scope', 'all');
        $this->assertCount(2, $dashboard->json('enrollments'));
        $this->assertEqualsCanonicalizing([$firstClass->ID, $secondClass->ID], collect($dashboard->json('classes'))->pluck('id')->all());
        $this->assertEqualsCanonicalizing([1, 2], collect($dashboard->json('classes'))->pluck('campus_id')->all());
    }

    public function test_same_name_and_phone_without_confirmation_remains_ambiguous(): void
    {
        $this->student(1, '未確認王小明', '0912777000');
        $this->student(2, '未確認王小明', '0912777000');

        $this->postJson('/api/v1/parent/login', [
            'Name' => '未確認王小明',
            'Phone' => '0912777000',
        ])->assertStatus(409);
    }

    public function test_confirmed_group_with_different_parent_phones_does_not_aggregate(): void
    {
        $first = $this->student(1, '不同家長手機王', '0912111000');
        $second = $this->student(2, '不同家長手機王', '0912222000');
        $firstClass = $this->course($first->id, 8);
        $this->course($second->id, 6);
        $service = app(StudentIdentityService::class);
        $group = $service->linkStudents($first->id, $second->id, null, 'super_admin', []);
        $service->setMode($group->id, StudentIdentityService::MODE_READONLY, null);

        $login = $this->postJson('/api/v1/parent/login', [
            'Name' => '不同家長手機王',
            'Phone' => '0912111000',
        ])->assertOk();
        $dashboard = $this->getJson('/api/v1/parent/dashboard?scope=all', [
            'Authorization' => 'Bearer ' . $login->json('token'),
        ])->assertOk();

        $this->assertNull($dashboard->json('identity_group_id'));
        $this->assertSame([$firstClass->ID], collect($dashboard->json('classes'))->pluck('id')->all());
    }

    public function test_cross_campus_leave_requires_actions_mode_and_uses_target_member(): void
    {
        $first = $this->student(1, '跨校請假王', '0912666000');
        $second = $this->student(2, '跨校請假王', '0912666000');
        $course = $this->course($second->id, 5);
        $session = ClassSession::create([
            'StudentClassID' => $course->ID,
            'SessionDate' => now()->addDay()->toDateString(),
            'StartTime' => '18:00',
            'EndTime' => '19:00',
            'Status' => 'scheduled',
        ]);

        $service = app(StudentIdentityService::class);
        $group = $service->linkStudents($first->id, $second->id, null, 'super_admin', []);
        $service->setMode($group->id, StudentIdentityService::MODE_READONLY, null);
        $readonlyLogin = $this->postJson('/api/v1/parent/login', ['Name' => '跨校請假王', 'Phone' => '0912666000'])->assertOk();
        $this->postJson('/api/v1/parent/sessions/' . $session->id . '/leave', [], [
            'Authorization' => 'Bearer ' . $readonlyLogin->json('token'),
        ])->assertStatus(403);

        $service->setMode($group->id, StudentIdentityService::MODE_ACTIONS, null);
        $actionsLogin = $this->postJson('/api/v1/parent/login', ['Name' => '跨校請假王', 'Phone' => '0912666000'])->assertOk();
        $this->postJson('/api/v1/parent/sessions/' . $session->id . '/leave', ['reason' => '測試'], [
            'Authorization' => 'Bearer ' . $actionsLogin->json('token'),
        ])->assertOk();
    }

    public function test_enrollment_branch_member_is_new_legacy_student_without_mutating_source(): void
    {
        $source = $this->student(1, '跨校報名王', '0912555000');
        $existingBranch = $this->student(2, '跨校報名王', '0912555000');
        $service = app(StudentIdentityService::class);
        $group = $service->linkStudents($source->id, $existingBranch->id, null, 'super_admin', []);

        $newMember = $service->createBranchMember($group->id, $source->id, 3, null, 'super_admin', []);

        $this->assertNotSame($source->id, $newMember->id);
        $this->assertSame(3, (int) $newMember->CampusID);
        $this->assertSame(1, (int) Student::find($source->id)->CampusID);
        $this->assertSame(3, $service->activeMembers($group->id)->count());
    }

    public function test_director_without_both_campus_permissions_cannot_link(): void
    {
        $first = $this->student(1, '權限王', '0912333000');
        $second = $this->student(2, '權限王', '0912333000');

        $this->expectException(ValidationException::class);
        app(StudentIdentityService::class)->linkStudents($first->id, $second->id, null, 'director', [1]);
    }

    private function student(int $campusId, string $name, string $phone): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'Phone' => $phone,
        ]);
    }

    private function course(int $studentId, int $remaining): StudentClass
    {
        return StudentClass::create([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'StartDate' => '2026-08-01',
            'EndDate' => '2026-09-01',
            'Charge' => 1000,
            'TotalHours' => 1,
            'Paid' => 0,
            'Rate' => 1000,
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => $remaining,
            'RemainingSessions' => $remaining,
            'UsedSessions' => 0,
            'ClassType' => 'one_on_one',
            'MDate' => now(),
        ]);
    }
}
