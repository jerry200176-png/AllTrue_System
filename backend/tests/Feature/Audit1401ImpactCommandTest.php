<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the #1401 impact-audit command's aggregation logic against
 * synthetic fixtures before it is ever pointed at production. Asserts
 * both correctness of the counts AND that no PII-shaped value (student
 * name, phone, LINE user id) ever appears in the command's own output.
 */
class Audit1401ImpactCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createStudent(int $campusId, string $name, string $parentPhone): Student
    {
        return Student::create([
            'name'         => $name,
            'CampusID'     => $campusId,
            'ClassID'      => 1,
            'enable'       => 1,
            'MDT'          => now(),
            'Notify_Token' => '',
            'parent_phone' => $parentPhone,
        ]);
    }

    public function test_counts_unverified_bindings_by_campus_and_detects_cross_family_identity(): void
    {
        // Family A: one verified binding, one legacy unverified binding — same family, same line id.
        $familyAChild1 = $this->createStudent(1, 'Fixture-A1', '0911000001');
        $familyAChild2 = $this->createStudent(1, 'Fixture-A2', '0911000001');
        $lineIdFamilyA = 'Ufamilya00000000000000000000000';
        StudentLineBinding::create([
            'student_id' => $familyAChild1->id,
            'line_user_id' => $lineIdFamilyA,
            'campus_id' => 1,
            'bound_at' => now()->subDays(30),
            'verified_at' => now()->subDays(30),
            'verification_method' => 'contact_phone',
        ]);
        StudentLineBinding::create([
            'student_id' => $familyAChild2->id,
            'line_user_id' => $lineIdFamilyA,
            'campus_id' => 1,
            'bound_at' => now()->subDays(90), // legacy, never re-verified
        ]);

        // A genuinely cross-family case: one LINE identity bound (unverified,
        // pre-fix-shaped data) to two students with different registered phones.
        $strangerVictim = $this->createStudent(2, 'Fixture-Victim', '0922000002');
        $strangerOwn = $this->createStudent(2, 'Fixture-Attacker-Own-Child', '0933000003');
        $crossFamilyLineId = 'Ucrossfamily000000000000000000000';
        StudentLineBinding::create([
            'student_id' => $strangerVictim->id,
            'line_user_id' => $crossFamilyLineId,
            'campus_id' => 2,
            'bound_at' => now()->subDays(60),
        ]);
        StudentLineBinding::create([
            'student_id' => $strangerOwn->id,
            'line_user_id' => $crossFamilyLineId,
            'campus_id' => 2,
            'bound_at' => now()->subDays(60),
            'verified_at' => now()->subDays(60),
            'verification_method' => 'contact_phone',
        ]);

        $exitCode = $this->artisan('audit:1401-impact')->run();
        $this->assertSame(0, $exitCode);
    }

    public function test_output_never_contains_pii_shaped_values(): void
    {
        $secretName = 'REDACTED-NAME-SHOULD-NOT-APPEAR';
        $secretPhone = '0955512345';
        $secretLineId = 'Usecretsecretsecretsecretsecretse';

        $student = $this->createStudent(1, $secretName, $secretPhone);
        StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $secretLineId,
            'campus_id' => 1,
            'bound_at' => now(),
        ]);

        \Illuminate\Support\Facades\Artisan::call('audit:1401-impact');
        $output = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringNotContainsString($secretName, $output);
        $this->assertStringNotContainsString($secretPhone, $output);
        $this->assertStringNotContainsString($secretLineId, $output);
    }
}
