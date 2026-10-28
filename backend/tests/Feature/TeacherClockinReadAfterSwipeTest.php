<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Synthetic writer-to-reader contract for the teacher dashboard clock-in card. */
class TeacherClockinReadAfterSwipeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_reads_the_authenticated_teachers_latest_swipe_across_campuses_and_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-20 23:40:00', 'Asia/Taipei'));
        $a = Campus::factory()->create(['Token' => 'synthetic-campus-a']);
        $b = Campus::factory()->create(['Token' => 'synthetic-campus-b']);
        $teacher = User::create(['LoginName' => 'clockin-a@example.test', 'Name' => 'T-A', 'PSW' => 'x',
            'type' => 'T', 'status' => 'active', 'phone' => '0900000056', 'MustChangePassword' => false]);
        $other = User::create(['LoginName' => 'clockin-b@example.test', 'Name' => 'T-B', 'PSW' => 'x',
            'type' => 'T', 'status' => 'active', 'phone' => '0900000057', 'MustChangePassword' => false]);
        foreach ([$a, $b] as $campus) {
            UserCampus::create(['CampusID' => $campus->id, 'UserID' => $teacher->id, 'Admin' => 0, 'Approved' => 1, 'RFID' => 'SYNTHETIC-A']);
        }
        UserCampus::create(['CampusID' => $a->id, 'UserID' => $other->id, 'Admin' => 0, 'Approved' => 1, 'RFID' => 'SYNTHETIC-B']);
        AuthToken::create(['user_id' => $teacher->id, 'token' => 'synthetic-teacher-session', 'expires_at' => now()->addDays(2)]);
        $headers = ['Authorization' => 'Bearer synthetic-teacher-session', 'Accept' => 'application/json'];
        $swipe = fn ($campus, $rfid, $token) => $this->postJson('/api/v1/swipe-rfid',
            ['branch_code' => (string) $campus->id, 'rfid' => $rfid], ['Authorization' => 'Bearer ' . $token]);

        $swipe($a, 'SYNTHETIC-B', 'synthetic-campus-a')->assertCreated()->assertJsonPath('action', 'sign_in');
        $this->withHeaders($headers)->getJson('/api/v1/teacher-attendance/today?teacher_id=' . $other->id)
            ->assertOk()->assertJsonPath('teacher_id', $teacher->id)->assertJsonPath('status', 'no_record')->assertJsonPath('sign_in_dt', null);
        Carbon::setTestNow(Carbon::parse('2026-12-20 23:45:00', 'Asia/Taipei'));
        $swipe($a, 'SYNTHETIC-A', 'synthetic-campus-a')->assertCreated()->assertJsonPath('action', 'sign_in');
        $this->withHeaders($headers)->getJson('/api/v1/teacher-attendance/today')
            ->assertOk()->assertJsonPath('date', '2026-12-20')->assertJsonPath('teacher_id', $teacher->id)
            ->assertJsonPath('status', 'source_only')->assertJsonPath('source', 'rfid')
            ->assertJsonPath('sign_in_dt', '2026-12-20 23:45:00');

        Carbon::setTestNow(Carbon::parse('2026-12-20 23:47:00', 'Asia/Taipei'));
        $swipe($b, 'SYNTHETIC-A', 'synthetic-campus-b')->assertCreated()->assertJsonPath('action', 'sign_in');
        $this->withHeaders($headers)->getJson('/api/v1/teacher-attendance/today')
            ->assertOk()->assertJsonPath('status', 'source_only')->assertJsonPath('sign_in_dt', '2026-12-20 23:47:00')
            ->assertJsonPath('sign_out_dt', null);

        Carbon::setTestNow(Carbon::parse('2026-12-21 00:05:00', 'Asia/Taipei'));
        $this->withHeaders($headers)->getJson('/api/v1/teacher-attendance/today')
            ->assertOk()->assertJsonPath('date', '2026-12-21')->assertJsonPath('status', 'no_record')->assertJsonPath('sign_in_dt', null);
        $swipe($a, 'SYNTHETIC-A', 'synthetic-campus-a')->assertCreated()->assertJsonPath('action', 'sign_in');
        $this->withHeaders($headers)->getJson('/api/v1/teacher-attendance/today')
            ->assertOk()->assertJsonPath('date', '2026-12-21')->assertJsonPath('status', 'source_only')
            ->assertJsonPath('sign_in_dt', '2026-12-21 00:05:00');
    }
}
