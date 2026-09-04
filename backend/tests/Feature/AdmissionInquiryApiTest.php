<?php

namespace Tests\Feature;

use App\Models\AdmissionInquiry;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdmissionInquiryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_submit_is_generic_and_deduplicated(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campus = Campus::factory()->create(['active' => true]);
        $payload = ['campus_id' => $campus->id, 'parent_name' => '王家長', 'parent_phone' => '0912-345-678', 'student_name' => '王小明', 'grade' => 'P5', 'school_name' => '測試國小', 'subject' => 'Math', 'preferred_slots' => ['週六上午'], 'consent' => 'yes'];
        $this->postJson('/api/v1/admission-inquiries', $payload)->assertStatus(202)->assertJsonMissing(['id', 'parent_phone', 'student_name']);
        $this->postJson('/api/v1/admission-inquiries', $payload)->assertStatus(202);
        $this->assertSame(1, AdmissionInquiry::count());
        $row = AdmissionInquiry::first();
        $this->assertSame('王小明', $row->student_name);
        $this->assertNotSame('0912-345-678', (string) $row->getRawOriginal('parent_phone'));
    }

    public function test_director_cannot_read_another_campus_inquiry(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        $campusA = Campus::factory()->create(['active' => true]);
        $campusB = Campus::factory()->create(['active' => true]);
        $this->postJson('/api/v1/admission-inquiries', ['campus_id' => $campusB->id, 'parent_name' => '李家長', 'parent_phone' => '0987654321', 'student_name' => '李小華', 'grade' => 'J1', 'school_name' => '測試中學', 'subject' => 'English', 'preferred_slots' => [], 'consent' => 'yes'])->assertStatus(202);
        $token = $this->directorToken((int) $campusA->id);
        $id = AdmissionInquiry::query()->value('id');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson("/api/v1/admission-inquiries/{$id}")->assertForbidden();
    }

    public function test_trial_handoff_creates_one_student_and_is_idempotent(): void
    {
        config(['perfflags.admissions_funnel_v1' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-04 10:00:00', 'Asia/Taipei'));
        $campus = Campus::factory()->create(['active' => true]);
        $teacher = User::create(['LoginName' => 'admission-teacher-' . uniqid() . '@example.com', 'Name' => '試聽老師', 'PSW' => 'secret', 'type' => 'T', 'MustChangePassword' => false]);
        UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1]);
        $this->postJson('/api/v1/admission-inquiries', ['campus_id' => $campus->id, 'parent_name' => '陳家長', 'parent_phone' => '0911222333', 'student_name' => '陳小安', 'grade' => 'P5', 'school_name' => '測試國小', 'subject' => 'Math', 'preferred_slots' => ['週六上午'], 'consent' => 'yes'])->assertStatus(202);
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
        $this->assertSame(1, \App\Models\Student::where('name', '陳小安')->count());
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->postJson("/api/v1/admission-inquiries/{$inquiry->id}/trial", $payload)->assertOk();
        $this->assertSame(1, \App\Models\Student::where('name', '陳小安')->count());
        $this->assertSame('trial_scheduled', $inquiry->fresh()->status);
        Carbon::setTestNow();
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
