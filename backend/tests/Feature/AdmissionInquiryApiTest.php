<?php

namespace Tests\Feature;

use App\Models\AdmissionInquiry;
use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->postJson('/api/v1/admission-inquiries', ['campus_id' => $campusB->id, 'parent_name' => '李家長', 'parent_phone' => '0987654321', 'student_name' => '李小華', 'grade' => 'J1', 'school_name' => '測試中學', 'subject' => 'English', 'preferred_slots' => [], 'consent' => 'yes'])->assertAccepted();
        $token = $this->directorToken((int) $campusA->id);
        $id = AdmissionInquiry::query()->value('id');
        $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson("/api/v1/admission-inquiries/{$id}")->assertForbidden();
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
