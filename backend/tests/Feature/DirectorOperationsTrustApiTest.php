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
        $this->assertLessThanOrEqual(45, (int) $dc['score'], 'stranded Critical must hard-cap score');
        $this->assertTrue((bool) ($dc['score_rules']['critical_uses_hard_cap'] ?? false));
        $this->assertGreaterThanOrEqual(1, (int) ($dc['critical_count'] ?? 0));
        $keys = collect($dc['decisions'])->pluck('key')->all();
        $this->assertContains('stranded_paid', $keys);
        $this->assertContains('dormant_hold', $keys);
        $this->assertNotContains('invoice', $keys);
        foreach ($dc['decisions'] as $d) {
            foreach (['why', 'next_step', 'action_label', 'target'] as $f) {
                $this->assertNotEmpty($d[$f]);
            }
            $this->assertSame('director', $d['owner']);
            if (in_array($d['key'], ['stranded_paid', 'dormant_hold'], true)) {
                $this->assertTrue((bool) ($d['has_drilldown'] ?? false));
                $this->assertIsArray($d['people'] ?? null);
                $this->assertNotEmpty($d['people']);
                $person = $d['people'][0];
                foreach (['student_class_id', 'student_id', 'student_name', 'why', 'next_step'] as $pf) {
                    $this->assertArrayHasKey($pf, $person);
                }
                $this->assertArrayNotHasKey('phone', $person);
                $this->assertArrayNotHasKey('email', $person);
            }
        }
    }

    public function test_hard_cap_prevents_dilution_when_only_stranded(): void
    {
        $this->seedStranded(96011, 1, 2, 400);

        $dc = $this->withHeaders($this->authHeaders($this->directorToken([1], 'ops-trust-cap@example.com')))
            ->getJson('/api/v1/director/operations-trust?branch_id=1')
            ->assertOk()
            ->json('data.decision_center');

        $this->assertSame('red', $dc['status']);
        $this->assertLessThanOrEqual(45, (int) $dc['score']);
        $caps = collect($dc['score_rules']['hard_caps'] ?? [])->pluck('key')->all();
        $this->assertContains('stranded_paid', $caps);
    }

    public function test_dormant_alone_is_soft_warning_not_hard_cap(): void
    {
        // Coverage filler so empty-week Critical hard-cap does not fire.
        DB::table('Student')->insert([
            'id' => 96020, 'name' => 'Coverage Filler', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $coverSc = DB::table('StudentClass')->insertGetId([
            'StudentID' => 96020, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 300, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(10)->toDateTimeString(),
            'SessionCount' => 8, 'SessionDuration' => 60,
            'RemainingSessions' => 0, 'UsedSessions' => 8, 'Stop' => 0, 'ScheduleMode' => 'date',
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $coverSc,
            'SessionDate' => now()->addDay()->toDateString(),
            'StartTime' => '23:00:00',
            'EndTime' => '23:50:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        // Prepaid student with a session after 14d horizon → retention dormant without stranded Critical.
        DB::table('Student')->insert([
            'id' => 96021, 'name' => 'Dormant Soft', 'CampusID' => 1, 'ClassID' => 1, 'enable' => 1,
        ]);
        $scId = DB::table('StudentClass')->insertGetId([
            'StudentID' => 96021, 'GradeID' => 1, 'SubjectID' => 1, 'TeacherID' => 1,
            'by1' => 1, 'Period' => 4, 'TotalHours' => 0, 'Charge' => 0, 'Pay' => 0,
            'Paid' => 1, 'Rate' => 500, 'ClassType' => 'one_on_one',
            'StartDate' => now()->subDays(40)->toDateTimeString(),
            'SessionCount' => 10, 'SessionDuration' => 60,
            'RemainingSessions' => 4, 'UsedSessions' => 6, 'Stop' => 0, 'ScheduleMode' => 'count',
        ]);
        DB::table('ClassSession')->insert([
            'StudentClassID' => $scId,
            'SessionDate' => now()->addDays(20)->toDateString(),
            'StartTime' => '23:00:00',
            'EndTime' => '23:50:00',
            'Status' => 'scheduled',
            'Note' => '',
        ]);

        $dc = $this->withHeaders($this->authHeaders($this->directorToken([1], 'ops-trust-dorm@example.com')))
            ->getJson('/api/v1/director/operations-trust?branch_id=1')
            ->assertOk()
            ->json('data.decision_center');

        $keys = collect($dc['decisions'])->pluck('key')->all();
        $this->assertContains('dormant_hold', $keys);
        $this->assertNotContains('stranded_paid', $keys);
        $caps = $dc['score_rules']['hard_caps'] ?? [];
        $this->assertSame([], $caps);
        $this->assertTrue((bool) ($dc['score_rules']['dormant_is_retention_hold'] ?? false));
        $this->assertTrue((bool) ($dc['score_rules']['dormant_does_not_block_green'] ?? false));
        $this->assertSame('green', $dc['status'], 'legitimate dormant retention_hold must allow green');
        $this->assertGreaterThanOrEqual(90, (int) $dc['score']);
        $this->assertGreaterThan(45, (int) $dc['score'], 'dormant must not hard-cap like Critical');
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
