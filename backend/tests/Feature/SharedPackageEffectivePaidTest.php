<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\CoursePackage;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Services\DunningService;
use App\Services\NotificationSyncService;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharedPackageEffectivePaidTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_sync_excludes_tutoring_from_tuition_candidates(): void
    {
        [$campus, , $sc] = $this->setupTutoringCase();
        $result = NotificationSyncService::sync([$campus->id]);

        $this->assertDatabaseMissing('Notifications', [
            'SourceKey' => "tuition:{$campus->id}:{$sc->ID}",
            'ResolvedAt' => null,
        ]);
    }

    public function test_tuition_reminder_command_excludes_tutoring_from_line_candidates(): void
    {
        [$campus, , $tutoring, $control] = $this->setupTutoringCase();
        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])
            ->expectsOutput('Found 1 overdue unpaid course(s).')
            ->assertSuccessful();
        $this->assertNotNull($campus);
        $this->assertNotSame((int) $tutoring->ID, (int) $control->ID);
    }

    private function campus(): object
    {
        return CampusFactory::new()->create(['name' => '分校', 'messaging_channel_token' => 'token']);
    }

    private function setupTutoringCase(): array
    {
        $campus = $this->campus();
        $student = Student::create([
            'name' => '免費輔導通知排除', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(15)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 0, 'UsedSessions' => 5, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 0, 'Rate' => 0, 'Stop' => 0, 'ClassType' => 'tutoring',
            'ScheduleMode' => 'count', 'MDate' => now()->subDays(15),
        ]);
        $controlStudent = Student::create([
            'name' => '付費課催繳控制', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $control = StudentClass::create([
            'StudentID' => $controlStudent->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(15)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'UsedSessions' => 0, 'Charge' => 1000, 'Pay' => 0,
            'Paid' => 0, 'Rate' => 200, 'Stop' => 0, 'ClassType' => 'one_on_one',
            'ScheduleMode' => 'count', 'MDate' => now()->subDays(15),
        ]);
        $cutoff = now()->subDays(8);
        $updates = ['MDate' => $cutoff];
        if (Schema::hasColumn('StudentClass', 'created_at')) {
            $updates['created_at'] = $cutoff;
        }
        DB::table('StudentClass')->whereIn('ID', [$sc->ID, $control->ID])->update($updates);
        return [$campus, $student, $sc, $control];
    }

    private function director(int $campusId): string
    {
        $u = User::create([
            'LoginName' => 'dir_' . Str::random(8), 'Name' => '主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => 912345678, 'password_changed_at' => now(),
        ]);
        DB::table('UserCampus')->insert(['UserID' => $u->id, 'CampusID' => $campusId, 'Admin' => 1, 'Approved' => 1]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $u->id, 'token' => $tok, 'expires_at' => now()->addDays(7)]);
        return $tok;
    }

    private function parentTok(Student $s): string
    {
        $raw = Str::random(32);
        ParentSession::create(['StudentID' => $s->id, 'TokenHash' => hash('sha256', $raw), 'ExpiresAt' => now()->addHours(2)]);
        return $raw;
    }

    private function setupPackageCase(bool $pkgPaid, int $classPaid = 0): array
    {
        $campus = $this->campus();
        $student = Student::create(['name' => '學生', 'CampusID' => $campus->id, 'ClassID' => 1, 'enable' => 1, 'MDT' => now()]);
        $pkg = CoursePackage::create([
            'student_id' => $student->id, 'campus_id' => $campus->id, 'name' => '方案',
            'total_sessions' => 20, 'remaining_sessions' => 15, 'used_sessions' => 5,
            'rate' => 1000, 'rate_unit' => 'session', 'class_type' => 'one_on_one',
            'paid' => $pkgPaid, 'paid_at' => $pkgPaid ? now()->toDateString() : null,
            'stop' => false, 'enabled' => true,
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(15)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 10, 'RemainingSessions' => 15, 'UsedSessions' => 0,
            'Charge' => 10000, 'Pay' => $classPaid ? 10000 : 0, 'Paid' => $classPaid,
            'Stop' => 0, 'PackageID' => $pkg->id, 'ScheduleMode' => 'count',
            'created_at' => now()->subDays(15), 'MDate' => now()->subDays(15),
        ]);
        return [$campus, $student, $pkg, $sc];
    }

    public function test_send_tuition_reminders_does_not_dun_paid_package_member(): void
    {
        $this->setupPackageCase(true, 0);
        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])
            ->expectsOutput('No overdue unpaid courses found.')
            ->assertSuccessful();
    }

    public function test_send_tuition_reminders_duns_unpaid_package_member(): void
    {
        $this->setupPackageCase(false, 0);
        $this->artisan('tuition:send-reminders', ['--dry-run' => true, '--overdue-days' => 7])
            ->expectsOutput('Found 1 overdue unpaid course(s).')
            ->assertSuccessful();
    }

    public function test_dunning_service_skips_paid_package_member(): void
    {
        [$campus] = $this->setupPackageCase(true, 0);
        $events = app(DunningService::class)->evaluateAll($campus->id, false);
        $this->assertEmpty($events);
    }

    public function test_notification_sync_service_resolves_tuition_when_package_paid(): void
    {
        [$campus, , $pkg, $sc] = $this->setupPackageCase(false, 0);
        $res1 = NotificationSyncService::sync([$campus->id]);
        $this->assertGreaterThanOrEqual(1, $res1['created']);

        $pkg->paid = true;
        $pkg->paid_at = now()->toDateString();
        $pkg->save();

        $res2 = NotificationSyncService::sync([$campus->id]);
        $this->assertGreaterThanOrEqual(1, $res2['resolved']);
        $this->assertDatabaseMissing('Notifications', ['SourceKey' => "tuition:{$campus->id}:{$sc->ID}", 'ResolvedAt' => null]);
    }

    public function test_alert_controller_does_not_alert_paid_package_member_as_unpaid(): void
    {
        [$campus, , , $sc] = $this->setupPackageCase(true, 0);
        $token = $this->director($campus->id);

        $res = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/alerts/tuition?branch_id={$campus->id}");
        $res->assertOk();
        $this->assertNull(collect($res->json())->firstWhere('id', $sc->ID));

        $slipRes = $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/alerts/tuition-slip/{$sc->ID}");
        $slipRes->assertStatus(422)->assertJson(['message' => '此課程已繳費，不需產生繳費單']);
    }

    public function test_parent_portal_tuition_bill_excludes_paid_package_member(): void
    {
        [$campus, $student, , $sc] = $this->setupPackageCase(true, 0);
        $dirToken = $this->director($campus->id);
        $parentToken = $this->parentTok($student);

        $msgRes = $this->withHeaders(['Authorization' => "Bearer {$dirToken}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/parent/payment-message/{$student->id}");
        $msgRes->assertOk()->assertJson(['message' => '此學生目前無待繳費課程']);

        $dashRes = $this->withHeaders(['Authorization' => "Bearer {$parentToken}", 'Accept' => 'application/json'])
            ->getJson("/api/v1/parent/dashboard");
        $dashRes->assertOk();
        $course = collect($dashRes->json('classes'))->firstWhere('id', $sc->ID);
        $this->assertNotNull($course);
        $this->assertTrue($course['paid']);
        $this->assertSame('paid', $course['payment_status']);
    }
}
