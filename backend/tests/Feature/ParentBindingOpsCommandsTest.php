<?php

namespace Tests\Feature;

use App\Models\Campus;
use App\Models\ParentBindingAttempt;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * PB-00 (#1436): read-only ops commands.
 */
class ParentBindingOpsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'parent_binding.observability_enabled' => true,
            'parent_binding.timezone' => 'Asia/Taipei',
        ]);
    }

    public function test_report_command_json_and_invalid_days(): void
    {
        $campus = Campus::factory()->create();
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440000',
            'occurred_at' => now(),
            'channel' => 'line',
            'method' => 'name',
            'outcome' => 'failure',
            'reason_code' => 'PHONE_MISMATCH',
            'campus_id' => $campus->id,
            'student_id' => null,
            'phone_fingerprint' => null,
            'candidate_count' => 1,
            'phone_match_count' => 0,
        ]);
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440001',
            'occurred_at' => now(),
            'channel' => 'parent_portal',
            'method' => 'name',
            'outcome' => 'success',
            'reason_code' => null,
            'campus_id' => $campus->id,
            'student_id' => 1,
            'phone_fingerprint' => null,
            'candidate_count' => 1,
            'phone_match_count' => 1,
        ]);

        $exit = Artisan::call('parent-binding:report', [
            '--days' => 7,
            '--format' => 'json',
        ]);
        $this->assertSame(0, $exit);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(2, $json['total_attempts']);
        $this->assertSame(1, $json['success_count']);
        $this->assertSame(1, $json['failure_count']);
        $this->assertArrayHasKey('PHONE_MISMATCH', $json['by_reason_code']);
        $this->assertSame('Asia/Taipei', $json['timezone']);

        $blob = Artisan::output();
        $this->assertStringNotContainsString('0912345678', $blob);

        $bad = Artisan::call('parent-binding:report', ['--days' => 0, '--format' => 'json']);
        $this->assertSame(1, $bad);
    }

    public function test_missing_contact_command_aggregates_by_campus(): void
    {
        $campus = Campus::factory()->create(['name' => '測試分校']);
        Student::create([
            'name' => '有手機',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '',
            'parent_phone' => '0912345678',
            'status' => 'active',
        ]);
        Student::create([
            'name' => '缺手機甲',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '',
            'parent_phone' => '',
            'status' => 'active',
        ]);
        Student::create([
            'name' => '畢業生不算',
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => '',
            'parent_phone' => '',
            'status' => 'graduated',
        ]);

        $exit = Artisan::call('parent-binding:missing-contact', [
            '--campus' => $campus->id,
            '--format' => 'json',
        ]);
        $this->assertSame(0, $exit);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame('parent_phone → Phone (StudentContactPhone)', $json['authoritative_rule']);
        $row = $json['campuses'][0];
        $this->assertSame(2, $row['active_student_count']);
        $this->assertSame(1, $row['missing_contact_count']);
        $this->assertSame(0.5, $row['missing_contact_percentage']);

        $out = Artisan::output();
        $this->assertStringNotContainsString('缺手機甲', $out);
        $this->assertStringNotContainsString('0912345678', $out);
    }

    public function test_report_campus_scope_and_invalid_campus(): void
    {
        $a = Campus::factory()->create();
        $b = Campus::factory()->create();
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440010',
            'occurred_at' => now(),
            'channel' => 'line',
            'method' => 'name',
            'outcome' => 'failure',
            'reason_code' => 'STUDENT_NOT_FOUND',
            'campus_id' => $a->id,
            'student_id' => null,
        ]);
        ParentBindingAttempt::create([
            'correlation_id' => '550e8400-e29b-41d4-a716-446655440011',
            'occurred_at' => now(),
            'channel' => 'line',
            'method' => 'name',
            'outcome' => 'failure',
            'reason_code' => 'PHONE_MISMATCH',
            'campus_id' => $b->id,
            'student_id' => null,
        ]);

        Artisan::call('parent-binding:report', [
            '--days' => 7,
            '--campus' => $a->id,
            '--format' => 'json',
        ]);
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(1, $json['total_attempts']);
        $this->assertArrayHasKey('STUDENT_NOT_FOUND', $json['by_reason_code']);

        $this->assertSame(1, Artisan::call('parent-binding:report', [
            '--days' => 7,
            '--campus' => 'nope',
            '--format' => 'json',
        ]));
    }
}
