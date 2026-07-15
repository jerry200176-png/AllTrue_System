<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** E-OPS-TRUST: campus isolation + Decision Center shape. */
class DirectorOperationsTrustApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_trust_requires_campus_and_isolates(): void
    {
        $token = $this->directorToken([1], 'ops-trust-dir@example.com');
        $h = $this->authHeaders($token);
        $this->withHeaders($h)->getJson('/api/v1/director/operations-trust')->assertStatus(422);
        $this->withHeaders($h)->getJson('/api/v1/director/operations-trust?branch_id=2')->assertForbidden();
    }

    public function test_operations_trust_returns_decision_center_scoped_to_campus(): void
    {
        $this->seedStranded(96001, 1, 3, 500);
        $this->seedStranded(96002, 2, 7, 900); // other campus noise

        $json = $this->withHeaders($this->authHeaders($this->directorToken([1], 'ops-trust-scope@example.com')))
            ->getJson('/api/v1/director/operations-trust?branch_id=1')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, (int) $json['branch_id']);
        $this->assertSame(3, (int) $json['revenue']['stranded_sessions']);
        $this->assertEqualsWithDelta(1500.0, (float) $json['revenue']['stranded_amount'], 0.5);
        $this->assertSame(1, (int) $json['retention']['dormant_prepaid_students']);
        $this->assertArrayNotHasKey('trust', $json);

        $dc = $json['decision_center'];
        $this->assertLessThan(100, (int) $dc['score']);
        $this->assertSame('red', $dc['status']);
        $keys = collect($dc['decisions'])->pluck('key')->all();
        $this->assertContains('stranded_paid', $keys);
        $this->assertContains('dormant_hold', $keys);
        $this->assertNotContains('invoice', $keys);
        foreach ($dc['decisions'] as $d) {
            foreach (['why', 'next_step', 'action_label', 'target'] as $f) {
                $this->assertNotEmpty($d[$f]);
            }
            $this->assertSame('director', $d['owner']);
        }
    }

    /** @return array<string,string> */
    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function directorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName, 'Name' => 'Ops Trust 主任', 'PSW' => 'secret',
            'type' => 'A', 'phone' => 923456700 + random_int(1, 99),
        ]);
        foreach ($campusIds as $campusId) {
            UserCampus::create(['CampusID' => $campusId, 'UserID' => $user->id, 'Admin' => 1, 'Approved' => 1]);
        }
        $token = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $token, 'expires_at' => now()->addDay()]);

        return $token;
    }

    private function seedStranded(int $studentId, int $campusId, int $remaining, int $rate): void
    {
        DB::table('Student')->insert([
            'id' => $studentId, 'name' => "Trust {$campusId}", 'CampusID' => $campusId, 'ClassID' => 1, 'enable' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'StudentID' => $studentId, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => $rate, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(40)->toDateTimeString(),
            'SessionCount' => $remaining + 5, 'SessionDuration' => 60,
            'RemainingSessions' => $remaining, 'UsedSessions' => 5, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
    }
}
