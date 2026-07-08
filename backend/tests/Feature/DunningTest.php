<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\DunningEvent;
use App\Models\Student;
use App\Models\StudentClass;
use App\Models\User;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DunningTest extends TestCase
{
    use RefreshDatabase;

    public function test_dunning_rules_returns_all_defined_rules(): void
    {
        [$token] = $this->seedDirector();
        $r = $this->getJson('/api/v1/dunning/rules', $this->bearer($token));
        $r->assertOk();
        $keys = array_column($r->json('rules'), 'key');
        $this->assertContains('unpaid_reminder', $keys);
        $this->assertContains('low_sessions', $keys);
        $this->assertContains('monthly_overdue', $keys);
        $this->assertContains('monthly_due_soon', $keys);
    }

    public function test_trigger_creates_events_for_unpaid_courses(): void
    {
        [$token, $campus] = $this->seedDirector();
        $student = Student::create([
            'name' => 'Dunning Student', 'CampusID' => $campus->id, 'ClassID' => 0, 'SchoolName' => 'T',
        ]);
        StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'UsedSessions' => 0,
            'Charge' => 5000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 100, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        $r = $this->postJson('/api/v1/dunning/trigger', ['campus_id' => $campus->id], $this->bearer($token));
        $r->assertOk();
        $this->assertGreaterThanOrEqual(1, $r->json('triggered'));
    }

    public function test_dunning_cooldown_prevents_duplicate(): void
    {
        [$token, $campus] = $this->seedDirector();
        $student = Student::create([
            'name' => 'Cooldown Student', 'CampusID' => $campus->id, 'ClassID' => 0, 'SchoolName' => 'T',
        ]);
        $sc = StudentClass::create([
            'StudentID' => $student->id, 'GradeID' => 1, 'SubjectID' => 1,
            'TeacherID' => 1, 'ClassType' => 'one_on_one',
            'by1' => 1, 'Period' => 4, 'StartDate' => now()->subDays(10)->toDateString(),
            'TotalHours' => 10, 'SessionCount' => 5, 'SessionDuration' => 120,
            'RemainingSessions' => 5, 'UsedSessions' => 0,
            'Charge' => 5000, 'Pay' => 0, 'Paid' => 0, 'Rate' => 100, 'Stop' => 0,
            'MDate' => now(), 'ScheduleMode' => 'count',
        ]);

        DunningEvent::create([
            'student_id' => $student->id,
            'student_class_id' => $sc->ID,
            'campus_id' => $campus->id,
            'rule_key' => 'unpaid_reminder',
            'channel' => 'in_app',
            'sent_at' => now()->subDays(3),
        ]);

        $r = $this->postJson('/api/v1/dunning/trigger', ['campus_id' => $campus->id], $this->bearer($token));
        $r->assertOk();
        $unpaidEvents = array_filter($r->json('events'), fn ($e) => $e['rule_key'] === 'unpaid_reminder' && $e['student_class_id'] === (int) $sc->ID);
        $this->assertEmpty($unpaidEvents);
    }

    public function test_dunning_history_returns_events(): void
    {
        [$token, $campus] = $this->seedDirector();
        DunningEvent::create([
            'student_id' => 1, 'student_class_id' => 1, 'campus_id' => $campus->id,
            'rule_key' => 'unpaid_reminder', 'channel' => 'in_app', 'sent_at' => now(),
        ]);

        $r = $this->getJson('/api/v1/dunning/history?branch_id=' . $campus->id, $this->bearer($token));
        $r->assertOk();
        $this->assertCount(1, $r->json('events'));
    }

    private function seedDirector(): array
    {
        $campus = CampusFactory::new()->create();
        $user = User::create([
            'LoginName' => 'dir_dun_' . uniqid() . '@test.com',
            'Name' => 'Dir', 'PSW' => password_hash('s', PASSWORD_DEFAULT),
            'type' => 'A', 'phone' => '0900000000',
        ]);
        \App\Models\UserCampus::create([
            'UserID' => $user->id, 'CampusID' => $campus->id, 'Approved' => 1,
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
