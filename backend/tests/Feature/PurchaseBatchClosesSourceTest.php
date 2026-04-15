<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseBatchClosesSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_batch_closes_paid_zero_remaining_source(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '加購測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 0,
            'UsedSessions' => 10,
            'SessionCount' => 10,
        ]);

        $alertsBefore = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');
        $alertsBefore->assertOk();
        $idsBefore = collect($alertsBefore->json())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertContains((int) $source->ID, $idsBefore, 'Source should appear in alerts before purchase');

        $purchaseRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 10,
            'start_date' => now()->addDay()->toDateString(),
        ]);
        $purchaseRes->assertStatus(201);
        $this->assertTrue($purchaseRes->json('source_closed'));
        $this->assertSame(1, $purchaseRes->json('source_course.stop'));
        $this->assertSame('settled', $purchaseRes->json('source_course.closed_reason'));

        $source->refresh();
        $this->assertSame(1, (int) $source->Stop);
        $this->assertSame('settled', $source->closed_reason);
        $this->assertNotNull($source->EndDate);

        $alertsAfter = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');
        $alertsAfter->assertOk();
        $idsAfter = collect($alertsAfter->json())->pluck('id')->map(fn ($v) => (int) $v)->all();
        $this->assertNotContains((int) $source->ID, $idsAfter, 'Source should NOT appear in alerts after purchase');

        $newId = $purchaseRes->json('new_course.id');
        $this->assertContains($newId, $idsAfter, 'New course (unpaid) should appear in alerts');
    }

    public function test_purchase_batch_does_not_close_unpaid_source(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '未繳加購生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createCountModeClass($student->id, [
            'Paid' => 0,
            'RemainingSessions' => 0,
            'UsedSessions' => 10,
            'SessionCount' => 10,
        ]);

        $purchaseRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 5,
            'start_date' => now()->addDay()->toDateString(),
        ]);
        $purchaseRes->assertStatus(201);
        $this->assertFalse($purchaseRes->json('source_closed'));

        $source->refresh();
        $this->assertSame(0, (int) $source->Stop);
    }

    public function test_purchase_batch_does_not_close_source_with_remaining(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '有堂加購生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $source = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 2,
            'UsedSessions' => 8,
            'SessionCount' => 10,
        ]);

        $purchaseRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$source->ID}/purchase-batch", [
            'sessions' => 10,
            'start_date' => now()->addDay()->toDateString(),
        ]);
        $purchaseRes->assertStatus(201);
        $this->assertFalse($purchaseRes->json('source_closed'));

        $source->refresh();
        $this->assertSame(0, (int) $source->Stop);
    }

    public function test_manual_pause_sets_closed_reason_null(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '暫停測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 5,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'pause',
        ]);
        $res->assertOk();

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertNull($course->closed_reason);
    }

    public function test_close_course_with_reason_completed(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '結案測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 0,
        ]);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'pause',
            'reason' => 'completed',
        ]);
        $res->assertOk();

        $course->refresh();
        $this->assertSame(1, (int) $course->Stop);
        $this->assertSame('completed', $course->closed_reason);
    }

    public function test_resume_clears_closed_reason(): void
    {
        $token = $this->createDirectorToken([1]);
        $student = Student::create([
            'name' => '恢復測試生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $course = $this->createCountModeClass($student->id, [
            'Paid' => 1,
            'RemainingSessions' => 0,
            'Stop' => 1,
        ]);
        $course->closed_reason = 'settled';
        $course->save();

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/student-classes/{$course->ID}/pause", [
            'action' => 'resume',
        ]);
        $res->assertOk();

        $course->refresh();
        $this->assertSame(0, (int) $course->Stop);
        $this->assertNull($course->closed_reason);
    }

    private function createDirectorToken(array $campusIds): string
    {
        $user = User::create([
            'LoginName' => 'director-purchase@example.com',
            'Name' => '主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }

    private function createCountModeClass(int $studentId, array $overrides = []): StudentClass
    {
        return StudentClass::create(array_merge([
            'StudentID' => $studentId,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 99,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now(),
            'EndDate' => null,
            'TotalHours' => 20,
            'Memo' => null,
            'Charge' => 1000,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => 0,
            'Disconunt' => null,
            'Rate' => 100,
            'LearnTimeID' => null,
            'RoomID' => 'R1',
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => 5,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ], $overrides));
    }
}
