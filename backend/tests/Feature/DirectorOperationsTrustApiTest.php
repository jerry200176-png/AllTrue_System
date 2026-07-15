<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\BusinessDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * E-OPS-TRUST: director operations-trust API + campus isolation + Trust KPI shape.
 */
class DirectorOperationsTrustApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_trust_requires_campus_and_isolates(): void
    {
        $token = $this->createDirectorToken([1], 'ops-trust-dir@example.com');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/director/operations-trust')
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/director/operations-trust?branch_id=2')
            ->assertForbidden();
    }

    public function test_operations_trust_returns_trust_kpis_scoped_to_campus(): void
    {
        // Campus 1: stranded prepaid (3 sessions × 500)
        DB::table('Student')->insert([
            'id' => 96001, 'name' => 'Trust Campus1', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'StudentID' => 96001, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(40)->toDateTimeString(),
            'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 3, 'UsedSessions' => 5, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        // Campus 2 noise: should NOT appear when branch_id=1
        DB::table('Student')->insert([
            'id' => 96002, 'name' => 'Trust Campus2', 'CampusID' => 2, 'ClassID' => 1, 'enable' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'StudentID' => 96002, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 900, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(40)->toDateTimeString(),
            'SessionCount' => 10, 'SessionDuration' => 60,
            'RemainingSessions' => 7, 'UsedSessions' => 3, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        $token = $this->createDirectorToken([1], 'ops-trust-scope@example.com');

        $json = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/director/operations-trust?branch_id=1')
            ->assertOk()
            ->json('data');

        $this->assertSame(1, (int) $json['branch_id']);
        $this->assertSame(3, (int) $json['revenue']['stranded_sessions']);
        $this->assertEqualsWithDelta(1500.0, (float) $json['revenue']['stranded_amount'], 0.5);
        $this->assertSame('red', $json['trust']['paid_class_visibility']['status']);
        $this->assertSame('policy', $json['trust']['invoice_trust']['status']);
        $this->assertSame('forward_only_no_historical_amend', $json['trust']['invoice_trust']['policy']);
        $this->assertFalse($json['trust']['dormant_hold']['auto_generate']);
        $this->assertSame(1, (int) $json['retention']['dormant_prepaid_students']);
        $this->assertArrayHasKey('calendar_trust', $json['trust']);
        $this->assertArrayHasKey('ledger_trust', $json['trust']);
    }

    public function test_service_campus_filter_excludes_other_branch_stranded(): void
    {
        DB::table('Student')->insert([
            'id' => 96011, 'name' => 'Only2', 'CampusID' => 2, 'ClassID' => 1, 'enable' => 1,
        ]);
        DB::table('StudentClass')->insert([
            'StudentID' => 96011, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 400, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(20)->toDateTimeString(),
            'SessionCount' => 5, 'SessionDuration' => 60,
            'RemainingSessions' => 2, 'UsedSessions' => 3, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);

        $m1 = app(BusinessDigestService::class)->metrics(1);
        $m2 = app(BusinessDigestService::class)->metrics(2);

        $this->assertSame(0, (int) $m1['revenue']['stranded_sessions']);
        $this->assertSame(2, (int) $m2['revenue']['stranded_sessions']);
        $this->assertSame('green', $m1['trust']['paid_class_visibility']['status']);
        $this->assertSame('red', $m2['trust']['paid_class_visibility']['status']);
    }

    /** @return array<string,string> */
    private function authHeaders(string $token): array
    {
        return [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];
    }

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => 'Ops Trust 主任',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => 923456700 + random_int(1, 99),
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::create([
                'CampusID' => $campusId,
                'UserID' => $user->id,
                'Admin' => 1,
                'Approved' => 1,
            ]);
        }

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
