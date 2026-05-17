<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceArAgingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ar_aging_returns_buckets_for_overdue_courses(): void
    {
        [$token, $campus] = $this->seedDirector();

        $student = Student::create([
            'name' => 'Test Student',
            'CampusID' => $campus->id,
            'SchoolName' => 'Test School',
        ]);

        StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'Rate' => 100,
            'Charge' => 5000,
            'Pay' => 2000,
            'Stop' => 0,
            'StartDate' => now()->subDays(45)->toDateString(),
        ]);

        $r = $this->getJson('/api/v1/finance/ar-aging?branch_id=' . $campus->id, $this->bearer($token));
        $r->assertOk();
        $this->assertArrayHasKey('students', $r->json());
        $this->assertArrayHasKey('totals', $r->json());
        $this->assertEquals(3000, $r->json('totals.grand_total'));
        $this->assertEquals(3000, $r->json('students.0.thirty'));
    }

    public function test_ar_aging_excludes_fully_paid(): void
    {
        [$token, $campus] = $this->seedDirector();

        $student = Student::create([
            'name' => 'Paid Student',
            'CampusID' => $campus->id,
            'SchoolName' => 'Good School',
        ]);

        StudentClass::create([
            'StudentID' => $student->id,
            'TeacherID' => 1,
            'GradeID' => 1,
            'SubjectID' => 1,
            'Rate' => 100,
            'Charge' => 5000,
            'Pay' => 5000,
            'Stop' => 0,
            'StartDate' => now()->subDays(45)->toDateString(),
        ]);

        $r = $this->getJson('/api/v1/finance/ar-aging?branch_id=' . $campus->id, $this->bearer($token));
        $r->assertOk();
        $this->assertEmpty($r->json('students'));
        $this->assertEquals(0, $r->json('totals.grand_total'));
    }

    /** @return array{string, Campus} */
    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'dir_ar_' . uniqid() . '@example.com',
            'Name' => 'Director',
            'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => 'A',
            'phone' => '0900000000',
        ]);
        \App\Models\UserCampus::create([
            'UserID' => $user->id,
            'CampusID' => $campus->id,
            'Approved' => 1,
        ]);
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);
        return [$tok, $campus];
    }

    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
