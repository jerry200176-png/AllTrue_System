<?php

namespace Tests\Feature;

use App\Models\AdmissionInquiry;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdmissionInquiryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_flag_off_hides_public_and_staff_endpoints(): void
    {
        config(['perfflags.admissions_funnel_v1' => false]);
        $campus = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id))->assertNotFound();
        $token = $this->directorToken((int) $campus->id);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/admission-inquiries')
            ->assertNotFound();
    }

    public function test_public_submit_is_generic_and_deduplicated(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $payload = $this->payload($campus->id);
        $this->postJson('/api/v1/admission-inquiries', $payload)->assertStatus(202)->assertJsonMissing(['id', 'parent_phone', 'student_name']);
        $this->postJson('/api/v1/admission-inquiries', $payload)->assertStatus(202);
        $this->assertSame(1, AdmissionInquiry::count());
        $row = AdmissionInquiry::first();
        $this->assertSame('王小明', $row->student_name);
        $this->assertNotSame('0912-345-678', (string) $row->getRawOriginal('parent_phone'));
        $this->assertDatabaseHas('security_audit_events', [
            'event_type' => 'admission_inquiry.submit',
            'outcome' => 'success',
        ]);
    }

    public function test_list_masks_pii_and_filters_status(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id))->assertStatus(202);
        $token = $this->directorToken((int) $campus->id);
        $list = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/admission-inquiries?campus_id=' . $campus->id)
            ->assertOk()
            ->json('data.0');
        $this->assertSame('王***', $list['student_name']);
        $this->assertStringEndsWith('5678', $list['parent_phone']);
        $this->assertStringNotContainsString('0912345678', $list['parent_phone']);
        $this->assertSame('contact', $list['next_action']);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/admission-inquiries?campus_id=' . $campus->id . '&status=contacted')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_director_cannot_read_another_campus_inquiry(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campusA = Campus::factory()->create(['active' => true]);
        $campusB = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campusB->id, '李家長', '0987654321', '李小華', 'J1', 'English'))->assertStatus(202);
        $token = $this->directorToken((int) $campusA->id);
        $id = AdmissionInquiry::query()->value('id');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson("/api/v1/admission-inquiries/{$id}")->assertForbidden();
    }

    public function test_teacher_cannot_manage_inquiries(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id))->assertStatus(202);
        $teacher = User::create([
            'LoginName' => 'admission-teacher-deny-' . uniqid() . '@example.com',
            'Name' => '無權老師', 'PSW' => 'secret', 'type' => 'T', 'MustChangePassword' => false,
        ]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $teacher->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/admission-inquiries?campus_id=' . $campus->id)
            ->assertForbidden();
    }

    public function test_invalid_state_transitions_are_rejected(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id))->assertStatus(202);
        $inquiry = AdmissionInquiry::firstOrFail();
        $token = $this->directorToken((int) $campus->id);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial-result", ['trial_result' => 'attended'])
            ->assertStatus(422);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/enroll", ['student_class_id' => 1])
            ->assertStatus(422);
    }

    public function test_trial_handoff_creates_one_student_and_is_idempotent(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Taipei'));
        $campus = Campus::factory()->create(['active' => true]);
        $teacher = User::create(['LoginName' => 'admission-teacher-' . uniqid() . '@example.com', 'Name' => '試聽老師', 'PSW' => 'secret', 'type' => 'T', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id, '陳家長', '0911222333', '陳小安'))->assertStatus(202);
        $inquiry = AdmissionInquiry::firstOrFail();
        $token = $this->directorToken((int) $campus->id);
        $payload = ['teacher_id' => $teacher->id, 'trial_date' => '2026-09-12', 'start_time' => '23:00', 'duration_minutes' => 30];
        $trialLockQueries = [];
        DB::listen(static function ($query) use (&$trialLockQueries): void {
            $sql = strtolower((string) $query->sql);
            if (str_contains($sql, 'admission_inquiries') && str_contains($sql, 'for update')) {
                $trialLockQueries[] = $sql;
            }
        });
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", $payload)->assertCreated();
        $this->assertNotEmpty($trialLockQueries, 'Trial handoff must lock the inquiry before creating the trial student.');
        $this->assertSame(1, Student::where('name', '陳小安')->count());
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", $payload)->assertOk();
        $this->assertSame(1, Student::where('name', '陳小安')->count());
        $this->assertSame('trial_scheduled', $inquiry->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_enrollment_link_reuses_same_student_and_is_idempotent(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id, '林家長', '0911000111', '林小宇'))->assertStatus(202);
        $inquiry = AdmissionInquiry::firstOrFail();
        $student = Student::create([
            'name' => '林小宇', 'CampusID' => $campus->id, 'ClassID' => 1,
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
        $trial = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'ClassType' => 'trial', 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-01', 'SessionCount' => 1, 'SessionDuration' => 120,
            'RemainingSessions' => 0, 'UsedSessions' => 1, 'TotalHours' => 2,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 800, 'Stop' => 1,
            'closed_reason' => 'converted_trial', 'MDate' => now(),
            'ScheduleMode' => 'count', 'week' => 2, 'time' => '16:00:00',
        ]);
        $formal = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'ClassType' => 'one_on_one', 'by1' => 1, 'Period' => 4,
            'StartDate' => '2026-09-08', 'SessionCount' => 8, 'SessionDuration' => 120,
            'RemainingSessions' => 8, 'UsedSessions' => 0, 'TotalHours' => 16,
            'Charge' => 0, 'Paid' => 0, 'Rate' => 800, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count', 'week' => 2, 'time' => '16:00:00',
        ]);
        $trial->trial_converted_to_id = $formal->ID;
        $trial->save();
        $inquiry->update([
            'status' => AdmissionInquiry::STATUS_TRIAL_COMPLETED,
            'trial_result' => 'attended',
            'student_id' => $student->id,
            'trial_student_class_id' => $trial->ID,
            'trial_completed_at' => now(),
        ]);
        $token = $this->directorToken((int) $campus->id);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/enroll", ['student_class_id' => $formal->ID])
            ->assertOk()
            ->assertJsonPath('student_id', $student->id);
        $this->assertSame(1, Student::where('name', '林小宇')->count());
        $this->assertSame('enrolled', $inquiry->fresh()->status);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/enroll", ['student_class_id' => $formal->ID])
            ->assertOk();
        $this->assertSame(1, Student::where('name', '林小宇')->count());
    }

    public function test_mark_lost_is_terminal_and_idempotent(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', $this->payload($campus->id))->assertStatus(202);
        $inquiry = AdmissionInquiry::firstOrFail();
        $token = $this->directorToken((int) $campus->id);
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/lost", ['staff_notes' => '家長暫不試聽'])
            ->assertOk()
            ->assertJsonPath('status', 'lost');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/lost", [])
            ->assertOk();
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson("/api/v1/admission-inquiries/{$inquiry->id}/contact", [])
            ->assertStatus(422);
    }

    private function payload(
        int $campusId,
        string $parentName = '王家長',
        string $phone = '0912-345-678',
        string $studentName = '王小明',
        string $grade = 'P5',
        string $subject = 'Math'
    ): array {
        return [
            'campus_id' => $campusId,
            'parent_name' => $parentName,
            'parent_phone' => $phone,
            'student_name' => $studentName,
            'grade' => $grade,
            'school_name' => '測試國小',
            'subject' => $subject,
            'preferred_slots' => ['週六上午'],
            'consent' => 'yes',
        ];
    }

    private function directorToken(int $campusId): string
    {
        $director = User::create(['LoginName' => 'admission-director-' . uniqid() . '@example.com', 'Name' => '招生主任', 'PSW' => 'secret', 'type' => 'A', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campusId, 'UserID' => $director->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $director->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        return $token;
    }
}
