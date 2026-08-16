<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\Student;
use App\Models\StudentLineBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * @see \App\Console\Commands\CleanupOrphanBindings
 */
class CleanupOrphanBindingsTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus;
    private string $lineUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->campus = Campus::factory()->create();
        $this->lineUserId = 'U' . str_repeat('a', 32);
    }

    // expectsOutputToContain() isn't available on this Laravel version's
    // PendingCommand, so run via Artisan::call() and assert on the raw
    // output buffer instead -- same "contains" semantics, no version dependency.
    private function runCleanup(): string
    {
        $exitCode = Artisan::call('bindings:cleanup-orphans');
        $this->assertSame(0, $exitCode);

        return Artisan::output();
    }

    public function test_deletes_bindings_for_nonexistent_students(): void
    {
        // Create a binding pointing to a non-existent student
        StudentLineBinding::create([
            'student_id' => 99999,
            'line_user_id' => $this->lineUserId,
            'campus_id' => $this->campus->id,
            'bound_at' => now(),
        ]);

        $output = $this->runCleanup();
        $this->assertStringContainsString('orphan_bindings_deleted=1', $output);

        $this->assertDatabaseMissing('student_line_bindings', [
            'line_user_id' => $this->lineUserId,
        ]);
    }

    public function test_deletes_bindings_for_disabled_students(): void
    {
        $student = Student::factory()->create(['enable' => 0]);

        StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $this->lineUserId,
            'campus_id' => $this->campus->id,
            'bound_at' => now(),
        ]);

        $output = $this->runCleanup();
        $this->assertStringContainsString('orphan_bindings_deleted=1', $output);

        $this->assertDatabaseMissing('student_line_bindings', [
            'student_id' => $student->id,
        ]);
    }

    public function test_keeps_bindings_for_active_students(): void
    {
        $student = Student::factory()->create(['enable' => 1]);

        StudentLineBinding::create([
            'student_id' => $student->id,
            'line_user_id' => $this->lineUserId,
            'campus_id' => $this->campus->id,
            'bound_at' => now(),
        ]);

        $output = $this->runCleanup();
        $this->assertStringContainsString('orphan_bindings_deleted=0', $output);

        $this->assertDatabaseHas('student_line_bindings', [
            'student_id' => $student->id,
        ]);
    }

    public function test_reports_anomaly_for_students_with_more_than_3_bindings(): void
    {
        $student = Student::factory()->create(['enable' => 1]);

        for ($i = 0; $i < 4; $i++) {
            StudentLineBinding::create([
                'student_id' => $student->id,
                'line_user_id' => 'U' . str_repeat(dechex($i), 32),
                'campus_id' => $this->campus->id,
                'bound_at' => now(),
            ]);
        }

        $output = $this->runCleanup();
        $this->assertStringContainsString('orphan_bindings_deleted=0', $output);
        $this->assertStringContainsString('anomaly_students=1', $output);
    }

    public function test_handles_empty_database_gracefully(): void
    {
        $output = $this->runCleanup();
        $this->assertStringContainsString('orphan_bindings_deleted=0', $output);
        $this->assertStringContainsString('anomaly_students=0', $output);
    }
}
