<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\StudentGuardian;
use App\Services\ParentBinding\GuardianSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncGuardiansFromLegacyCommandTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $overrides = []): Student
    {
        return Student::create(array_merge([
            'name' => 'Backfill 生',
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_name' => '李爸',
            'parent_phone' => '0918000111',
        ], $overrides));
    }

    public function test_dry_run_reports_without_writing(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $student = $this->student();

        $this->artisan('guardians:sync-from-legacy', ['--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame(
            0,
            StudentGuardian::where('student_id', $student->id)->count()
        );
    }

    public function test_apply_writes_primary_in_testing(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $student = $this->student();

        $this->artisan('guardians:sync-from-legacy', ['--apply' => true])
            ->assertExitCode(0);

        $link = StudentGuardian::query()
            ->with('guardian')
            ->where('student_id', $student->id)
            ->where('is_primary', true)
            ->first();
        $this->assertNotNull($link);
        $this->assertSame('0918000111', $link->guardian->phone);
        $this->assertSame('李爸', $link->guardian->display_name);
        $student->refresh();
        $this->assertSame('0918000111', $student->parent_phone);
    }

    public function test_apply_is_idempotent_when_already_synced(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $student = $this->student();
        app(GuardianSyncService::class)->syncPrimaryFromStudent($student);

        $this->artisan('guardians:sync-from-legacy', ['--apply' => true])
            ->expectsOutput('{"mode":"apply","scanned":1,"already_ok":1,"would_write":0,"written":0,"skipped_empty":0,"flag_multi_guardian":false}')
            ->assertExitCode(0);

        $this->assertSame(
            1,
            StudentGuardian::where('student_id', $student->id)->where('status', '!=', 'revoked')->count()
        );
    }

    public function test_verify_ok_after_apply(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $this->student();
        $this->artisan('guardians:sync-from-legacy', ['--apply' => true])->assertExitCode(0);

        $this->artisan('guardians:sync-from-legacy', ['--verify' => true])
            ->expectsOutput('VERIFY_OK')
            ->assertExitCode(0);
    }

    public function test_verify_fails_when_primary_missing(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $this->student();

        $this->artisan('guardians:sync-from-legacy', ['--verify' => true])
            ->expectsOutput('VERIFY_FAILED')
            ->assertExitCode(1);
    }
}
