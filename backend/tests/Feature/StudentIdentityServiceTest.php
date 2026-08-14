<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Services\StudentIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StudentIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_director_must_have_both_campus_permissions_to_link(): void
    {
        $first = $this->student(1, '跨校服務王', '0912000001');
        $second = $this->student(2, '跨校服務王', '0912000001');
        $service = app(StudentIdentityService::class);

        $this->expectException(ValidationException::class);
        $service->linkStudents($first->id, $second->id, null, 'director', [1]);
    }

    public function test_super_admin_can_link_set_mode_and_audit_without_auto_merging(): void
    {
        $first = $this->student(1, '身份服務王', '0912000002');
        $second = $this->student(2, '身份服務王', '0912000002');
        $service = app(StudentIdentityService::class);

        $group = $service->linkStudents($first->id, $second->id, 99, 'super_admin', []);
        $this->assertCount(2, $service->activeMembers($group->id));
        $this->assertSame(StudentIdentityService::MODE_OFF, $service->accessMode($group->id));

        $access = $service->setMode($group->id, StudentIdentityService::MODE_READONLY, 99);
        $this->assertSame(StudentIdentityService::MODE_READONLY, $access->mode);
        $this->assertCount(2, $service->parentContext($first->id)[2]);
        $this->assertGreaterThanOrEqual(2, $service->auditHistory($group->id)->count());
        $this->assertNull($service->groupForStudent($this->student(1, '未關聯王', '0912000003')->id));
    }

    public function test_unlink_revokes_membership_and_keeps_audit_reason(): void
    {
        $first = $this->student(1, '解除身份王', '0912000004');
        $second = $this->student(2, '解除身份王', '0912000004');
        $service = app(StudentIdentityService::class);
        $group = $service->linkStudents($first->id, $second->id, null, 'super_admin', []);

        $member = $service->unlinkStudent($second->id, 99, '主任確認為不同家庭');

        $this->assertSame('revoked', $member->status);
        $this->assertCount(1, $service->activeMembers($group->id));
        $this->assertSame('主任確認為不同家庭', $service->auditHistory($group->id)->first()->metadata['reason']);
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
}
