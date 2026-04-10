<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Invoice;
use App\Models\LearningRecord;
use App\Models\Notification;
use App\Models\PendingSwipe;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_generates_notifications_and_deduplicates_by_source_key(): void
    {
        $token = $this->createDirectorToken([1]);

        $studentA = Student::create([
            'name' => '學生A',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
        $studentB = Student::create([
            'name' => '學生B',
            'CampusID' => 2,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $classA = $this->createStudentClass($studentA->id, 0, 1);
        $classB = $this->createStudentClass($studentB->id, 0, 1);
        $paidClass = $this->createStudentClass($studentA->id, 1, 1, 0);

        $session = ClassSession::create([
            'StudentClassID' => $classA->ID,
            'SessionDate' => now()->toDateString(),
            'StartTime' => '10:00:00',
            'EndTime' => '12:00:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        LearningRecord::create([
            'StudentClassID' => $classA->ID,
            'ClassSessionID' => $session->id,
            'TeacherID' => 99,
            'Content' => '待審內容',
            'Status' => 'pending',
            'Subject' => 'Math',
            'SessionDate' => now()->toDateString(),
        ]);

        PendingSwipe::create([
            'RFID' => 'RFID-100',
            'StudentID' => null,
            'CampusID' => 1,
            'SwipeAt' => now(),
            'Reason' => 'unknown_card',
            'Payload' => null,
        ]);

        $invoiceA = Invoice::create([
            'StudentID' => $studentA->id,
            'StudentClassID' => $classA->ID,
            'IssueDate' => now()->subDays(10)->toDateString(),
            'DueDate' => now()->subDays(2)->toDateString(),
            'TotalAmount' => 6000,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
        ]);
        $invoiceB = Invoice::create([
            'StudentID' => $studentB->id,
            'StudentClassID' => $classB->ID,
            'IssueDate' => now()->subDays(10)->toDateString(),
            'DueDate' => now()->subDays(3)->toDateString(),
            'TotalAmount' => 6200,
            'PaidAmount' => 0,
            'Status' => 'unpaid',
            'Note' => '',
        ]);

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/notifications/sync', [
            'branch_id' => 1,
        ]);

        $first->assertOk();
        $this->assertSame(4, (int) $first->json('active_count'));
        $this->assertDatabaseCount('Notifications', 4);
        $this->assertDatabaseHas('Notifications', [
            'SourceType' => 'Invoice',
            'SourceID' => (string) $invoiceA->id,
            'Severity' => 'low',
            'Title' => '學生A 學費提醒（逾期 2 天）',
        ]);
        $this->assertDatabaseMissing('Notifications', [
            'SourceType' => 'StudentClass',
            'SourceID' => (string) $paidClass->ID,
            'SourceKey' => "tuition:1:{$paidClass->ID}",
        ]);
        $this->assertDatabaseMissing('Notifications', [
            'CampusID' => 2,
            'Type' => 'tuition',
            'SourceID' => (string) $invoiceB->id,
        ]);

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/notifications/sync', [
            'branch_id' => 1,
        ]);

        $second->assertOk();
        $this->assertSame(0, (int) $second->json('created'));
        $this->assertDatabaseCount('Notifications', 4);
    }

    public function test_read_status_is_isolated_per_user(): void
    {
        $tokenA = $this->createDirectorToken([1], 'director-a@example.com');
        $tokenB = $this->createDirectorToken([1], 'director-b@example.com');

        $notification = Notification::create([
            'CampusID' => 1,
            'Type' => 'tuition',
            'Severity' => 'high',
            'Title' => '測試通知',
            'Body' => '測試內容',
            'SourceType' => 'StudentClass',
            'SourceID' => '100',
            'SourceKey' => 'tuition:1:100',
            'Payload' => ['class_id' => 100],
            'OccurredAt' => now(),
            'ResolvedAt' => null,
        ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/notifications/unread-count?branch_id=1')
            ->assertOk()
            ->assertJson([
                'unread_count' => 1,
                'urgent_unread_count' => 1,
            ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/notifications/unread-count?branch_id=1')
            ->assertOk()
            ->assertJson([
                'unread_count' => 1,
                'urgent_unread_count' => 1,
            ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJson([
                'unread_count' => 0,
                'urgent_unread_count' => 0,
            ]);

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenA}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/notifications?branch_id=1&read=unread')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withHeaders([
            'Authorization' => "Bearer {$tokenB}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/notifications?branch_id=1&read=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tuition_alert_endpoint_excludes_paid_classes_even_with_zero_remaining_sessions(): void
    {
        $token = $this->createDirectorToken([1], 'director-alerts@example.com');

        $student = Student::create([
            'name' => '提醒學生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $unpaidClass = $this->createStudentClass($student->id, 0, 1, 0);
        $paidClass = $this->createStudentClass($student->id, 1, 1, 0);

        $res = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/alerts/tuition?branch_id=1');

        $res->assertOk();
        $data = $res->json();
        $this->assertIsArray($data);

        $ids = collect($data)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains((int) $unpaidClass->ID, $ids);
        $this->assertNotContains((int) $paidClass->ID, $ids);
    }

    private function createDirectorToken(array $campusIds, string $loginName = 'director@example.com'): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
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

    private function createStudentClass(int $studentId, int $paid, int $campusId, int $remainingSessions = 1): StudentClass
    {
        return StudentClass::create([
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
            'Charge' => null,
            'Pay' => null,
            'PayDate' => null,
            'Paid' => $paid,
            'Disconunt' => null,
            'Rate' => null,
            'LearnTimeID' => null,
            'RoomID' => "R{$campusId}",
            'MDate' => now(),
            'Stop' => 0,
            'ScheduleMode' => 'count',
            'SessionCount' => 10,
            'SessionDuration' => 120,
            'RemainingSessions' => $remainingSessions,
            'ClassType' => 'one_on_one',
            'UsedSessions' => 0,
        ]);
    }
}
