<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\DunningEvent;
use App\Models\Notification;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\DunningService;
use App\Services\NotificationSyncService;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedPackageEffectivePaidTest extends TestCase
{
    use RefreshDatabase;

    private function createCampus(): object
    {
        return CampusFactory::new()->create([
            'name' => '測試分校',
            'messaging_channel_token' => 'dummy_token',
        ]);
    }

    private function createDirectorToken(int $campusId): string
    {
        $user = User::create([
            'LoginName' => 'dir_' . Str::random(8),
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 912345678,
            'password_changed_at' => now(),
        ]);

        DB::table('UserCampus')->insert([
            'UserID' => $user->id,
            'CampusID' => $campusId,
            'Admin' => 1,
            'Approved' => 1,
        ]);

        $rawToken = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $rawToken,
            'expires_at' => now()->addDays(7),
        ]);

        return $rawToken;
    }

    private function createParentToken(Student $student): string
    {
        $raw = Str::random(32);
        ParentSession::create([
            'StudentID' => $student->id,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addHours(2),
        ]);
        return $raw;
    }

    public function test_send_tuition_reminders_does_not_dun_paid_package_member(): void
    {
        $campus = $this->createCampus();
        $student = Student::create([
            'name' => '已結清學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'name' => '雙科方案已繳',
            'total_sessions' => 20,
            'remaining_sessions' => 20,
            'used_sessions' => 0,
            'rate' => 1000,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => true,
            'paid_at' => now()->toDateString(),
            'stop' => false,
            'enabled' => true,
        ]);

        // Member class with Paid = 0, created 15 days ago (overdue)
        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(15)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'created_at' => now()->subDays(15),
            'MDate' => now()->subDays(15),
        ]);

        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])
            ->expectsOutput('No overdue unpaid courses found.')
            ->assertSuccessful();
    }

    public function test_send_tuition_reminders_duns_unpaid_package_member(): void
    {
        $campus = $this->createCampus();
        $student = Student::create([
            'name' => '未結清學生',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);

        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'name' => '雙科方案未繳',
            'total_sessions' => 20,
            'remaining_sessions' => 20,
            'used_sessions' => 0,
            'rate' => 1000,
            'rate_unit' => 'session',
            'class_type' => 'one_on_one',
            'paid' => false,
            'paid_at' => null,
            'stop' => false,
            'enabled' => true,
        ]);

        // Member class with Paid = 0, created 15 days ago (overdue)
        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(15)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'created_at' => now()->subDays(15),
            'MDate' => now()->subDays(15),
        ]);

        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])
            ->expectsOutput('Found 1 overdue unpaid course(s).')
            ->assertSuccessful();
    }

    public function test_dunning_service_skips_paid_package_member(): void
    {
        $campus = $this->createCampus();
        $student = Student::create([
            'name' => 'Dunning Test Student',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
        ]);

        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'name' => 'Paid Pkg',
            'total_sessions' => 10,
            'remaining_sessions' => 10,
            'used_sessions' => 0,
            'rate' => 1000,
            'paid' => true,
            'paid_at' => now()->toDateString(),
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $service = app(DunningService::class);
        $events = $service->evaluateAll($campus->id, false);

        // Neither unpaid_reminder nor low_sessions should fire
        $this->assertEmpty($events);
    }

    public function test_notification_sync_service_resolves_tuition_when_package_paid(): void
    {
        $campus = $this->createCampus();
        $student = Student::create([
            'name' => 'Notification Sync Student',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
        ]);

        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'name' => 'Initial Unpaid Pkg',
            'total_sessions' => 10,
            'remaining_sessions' => 10,
            'used_sessions' => 0,
            'rate' => 1000,
            'paid' => false,
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(5)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        // First sync: creates tuition notification
        $res1 = NotificationSyncService::sync([$campus->id]);
        $this->assertGreaterThanOrEqual(1, $res1['created']);
        $this->assertDatabaseHas('Notifications', [
            'SourceKey' => "tuition:{$campus->id}:{$sc->ID}",
            'ResolvedAt' => null,
        ]);

        // Now package is marked paid (e.g. by payment report confirmation)
        $pkg->paid = true;
        $pkg->paid_at = now()->toDateString();
        $pkg->save();

        // Second sync: existing tuition notification must be resolved!
        $res2 = NotificationSyncService::sync([$campus->id]);
        $this->assertGreaterThanOrEqual(1, $res2['resolved']);
        $this->assertDatabaseMissing('Notifications', [
            'SourceKey' => "tuition:{$campus->id}:{$sc->ID}",
            'ResolvedAt' => null,
        ]);
    }

    public function test_alert_controller_does_not_alert_paid_package_member_as_unpaid(): void
    {
        $campus = $this->createCampus();
        $token = $this->createDirectorToken($campus->id);

        $student = Student::create([
            'name' => 'Alert Test Student',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
        ]);

        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'name' => 'Alert Pkg Paid',
            'total_sessions' => 20,
            'remaining_sessions' => 15,
            'used_sessions' => 5,
            'rate' => 1000,
            'paid' => true,
            'paid_at' => now()->toDateString(),
            'stop' => false,
            'enabled' => true,
        ]);

        // Member class with Paid = 0, remaining = 15 (> 2)
        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(5)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 15,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition?branch_id={$campus->id}");

        $response->assertOk();
        $rows = collect($response->json());

        // The member class must not appear as an individual unpaid alert row
        $memberRow = $rows->firstWhere('id', $sc->ID);
        $this->assertNull($memberRow, 'Paid package member with remaining > 2 must not appear in alerts/tuition as unpaid.');

        // tuitionSlipData must return 422
        $slipRes = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/alerts/tuition-slip/{$sc->ID}");

        $slipRes->assertStatus(422);
        $this->assertSame('此課程已繳費，不需產生繳費單', $slipRes->json('message'));
    }

    public function test_parent_portal_tuition_bill_excludes_paid_package_member(): void
    {
        $campus = $this->createCampus();
        $directorToken = $this->createDirectorToken($campus->id);

        $student = Student::create([
            'name' => 'Parent Portal Student',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
        ]);

        $parentToken = $this->createParentToken($student);

        $pkg = CoursePackage::create([
            'student_id' => $student->id,
            'campus_id' => $campus->id,
            'name' => 'Portal Paid Pkg',
            'total_sessions' => 20,
            'remaining_sessions' => 18,
            'used_sessions' => 2,
            'rate' => 1000,
            'paid' => true,
            'paid_at' => now()->toDateString(),
            'stop' => false,
            'enabled' => true,
        ]);

        $sc = StudentClass::create([
            'StudentID' => $student->id,
            'GradeID' => 1,
            'SubjectID' => 1,
            'TeacherID' => 1,
            'by1' => 1,
            'Period' => 4,
            'StartDate' => now()->subDays(5)->toDateString(),
            'TotalHours' => 10,
            'SessionCount' => 10,
            'RemainingSessions' => 10,
            'UsedSessions' => 0,
            'Charge' => 10000,
            'Pay' => 0,
            'Paid' => 0,
            'Stop' => 0,
            'PackageID' => $pkg->id,
            'ScheduleMode' => 'count',
            'MDate' => now(),
        ]);

        // Director payment message: must say no unpaid courses
        $msgRes = $this->withHeaders([
            'Authorization' => "Bearer {$directorToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/parent/payment-message/{$student->id}");

        $msgRes->assertOk();
        $this->assertSame('此學生目前無待繳費課程', $msgRes->json('message'));

        // Parent dashboard: paid flag must be true
        $dashRes = $this->withHeaders([
            'Authorization' => "Bearer {$parentToken}",
            'Accept' => 'application/json',
        ])->getJson("/api/v1/parent/dashboard");

        $dashRes->assertOk();
        $classes = collect($dashRes->json('classes'));
        $memberCourse = $classes->firstWhere('id', $sc->ID);
        $this->assertNotNull($memberCourse);
        $this->assertTrue($memberCourse['paid']);
        $this->assertSame('paid', $memberCourse['payment_status']);
    }
}
