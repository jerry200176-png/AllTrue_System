<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\ClassSession;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyCorrectionPreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_preview_is_read_only_scoped_and_has_no_browser_execute_route(): void
    {
        $user = User::create(['LoginName' => 'monthly-preview-fixture', 'Name' => 'Fixture', 'PSW' => 'test', 'type' => 'A', 'phone' => '']);
        UserCampus::create(['UserID' => $user->id, 'CampusID' => 1, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);
        $student = Student::create(['name' => 'Preview fixture', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $source = StudentClass::create(['StudentID' => $student->id, 'TeacherID' => 1, 'GradeID' => 1, 'SubjectID' => 1,
            'by1' => 2, 'Period' => 4, 'StartDate' => '2026-08-01', 'EndDate' => '2026-09-30',
            'TotalHours' => 2, 'Charge' => 4000, 'Rate' => 1000, 'Paid' => 1, 'MDate' => now(), 'Stop' => 0, 'ScheduleMode' => 'date']);
        ClassSession::create(['StudentClassID' => $source->ID, 'SessionDate' => '2026-09-02', 'StartTime' => '18:00', 'EndTime' => '20:00', 'Status' => 'attended']);
        $input = ['source_start' => '2026-08-01', 'source_end' => '2026-08-31', 'target_start' => '2026-09-01',
            'target_end' => '2026-09-30', 'source_charge' => 4000, 'target_charge' => 4000,
            'payment_evidence_reference' => 'reviewed-fixture-only'];
        $path = "/api/v1/student-classes/{$source->ID}/monthly-contract-correction";
        $preview = $this->withToken($token)->postJson($path . '/preview', $input)->assertOk()
            ->assertJsonPath('execution', 'founder_approved_pop_only')->assertJsonPath('source_paid_amount', null);
        $this->assertArrayNotHasKey('snapshot', $preview->json());
        $this->withToken($token)->putJson("/api/v1/student-classes/{$source->ID}", ['end_date' => '2026-10-31'])
            ->assertStatus(422)->assertJsonPath('code', 'monthly_paid_period_extension_requires_renewal');
        $this->withToken($token)->postJson($path . '/execute', $input)->assertNotFound();
        $student->CampusID = 2;
        $student->save();
        $this->withToken($token)->postJson($path . '/preview', $input)->assertForbidden();
        $this->assertSame(1, StudentClass::count());
        $this->assertSame('2026-09-30', substr((string) $source->fresh()->EndDate, 0, 10));
        $this->assertSame(0, \App\Models\SessionCorrection::count());
    }
}
