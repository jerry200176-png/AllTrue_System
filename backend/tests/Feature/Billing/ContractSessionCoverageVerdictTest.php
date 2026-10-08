<?php

namespace Tests\Feature\Billing;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ARCH2 PR2: ContractSessionCoverageController answers through ContractMoneyVerdict::lessons (output unchanged). */
class ContractSessionCoverageVerdictTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMoneyFixtures;

    private function token(): string
    {
        $user = User::create(['LoginName' => 'cov-' . uniqid() . '@example.com', 'Name' => 'cov', 'PSW' => 'secret', 'type' => 'D', 'phone' => '0900000000']);
        UserCampus::create(['CampusID' => 1, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    public function test_coverage_endpoint_tags_lessons_and_counts_unscheduled(): void
    {
        $course = $this->course([['2026-08', 10000, 'partial', 4000, [[4000, 'cash']]]], ['SessionCount' => 10], ['2026-08-03', '2026-08-10']);

        $res = $this->getJson("/api/v1/accounting/contracts/{$course->ID}/sessions", ['Authorization' => 'Bearer ' . $this->token()])->assertOk();

        $res->assertJsonPath('student_class_id', (int) $course->ID)->assertJsonPath('unscheduled_count', 8);
        $this->assertSame(['partial', 'partial'], array_column($res->json('sessions'), 'payment'));
    }

    public function test_lesson_without_any_invoice_reads_no_invoice(): void
    {
        $course = $this->course([], [], ['2026-08-03']);

        $res = $this->getJson("/api/v1/accounting/contracts/{$course->ID}/sessions", ['Authorization' => 'Bearer ' . $this->token()])->assertOk();

        $this->assertSame(['no_invoice'], array_column($res->json('sessions'), 'payment'));
    }
}
