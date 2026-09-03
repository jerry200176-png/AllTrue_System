<?php

namespace Tests\Feature;

use App\Models\Guardian;
use App\Models\ParentSession;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\StudentLineBinding;
use App\Services\ParentBinding\GuardianSyncService;
use App\Services\ParentBinding\ParentGuardianAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParentPortalGuardianAuthzTest extends TestCase
{
    use RefreshDatabase;

    private function student(array $overrides = []): Student
    {
        return Student::create(array_merge([
            'name' => 'Portal 生 ' . Str::random(4),
            'CampusID' => 1,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'parent_name' => '家長',
            'parent_phone' => '0912000' . random_int(100, 999),
        ], $overrides));
    }

    private function lineId(string $suffix = 'a'): string
    {
        return 'U' . str_repeat($suffix, 32);
    }

    public function test_flag_on_login_lists_multi_child_via_guardian_links(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('1');
        $childA = $this->student(['CampusID' => 1, 'name' => '甲童']);
        $childB = $this->student(['CampusID' => 1, 'name' => '乙童']);
        $guardian = Guardian::create([
            'display_name' => '爸',
            'line_user_id' => $line,
            'phone' => '0912111111',
            'phone_normalized' => '0912111111',
        ]);
        foreach ([$childA, $childB] as $child) {
            StudentGuardian::create([
                'student_id' => $child->id,
                'guardian_id' => $guardian->id,
                'campus_id' => 1,
                'role' => StudentGuardian::ROLE_FATHER,
                'is_primary' => $child->id === $childA->id,
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => true,
                'notify_tuition' => true,
                'source' => StudentGuardian::SOURCE_STAFF,
            ]);
        }

        $students = app(ParentGuardianAccessService::class)->studentsForLineUser($line);
        $this->assertCount(2, $students);
        $this->assertTrue(app(ParentGuardianAccessService::class)->lineMayAccessStudent($line, (int) $childB->id));
    }

    public function test_read_only_still_grants_access_suspended_does_not(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('2');
        $child = $this->student();
        $guardian = Guardian::create(['display_name' => '媽', 'line_user_id' => $line]);
        $link = StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_MOTHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_READ_ONLY,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        $this->assertTrue(app(ParentGuardianAccessService::class)->lineMayAccessStudent($line, (int) $child->id));

        $link->status = StudentGuardian::STATUS_SUSPENDED;
        $link->save();
        $this->assertFalse(app(ParentGuardianAccessService::class)->lineMayAccessStudent($line, (int) $child->id));
    }

    public function test_revoke_expires_matching_parent_session_immediately(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('3');
        $child = $this->student();
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        $link = StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        $raw = Str::random(48);
        $session = ParentSession::create([
            'StudentID' => $child->id,
            'line_user_id' => $line,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addDays(30),
        ]);

        app(GuardianSyncService::class)->revoke($link->fresh(['guardian']));

        $session->refresh();
        $this->assertTrue($session->ExpiresAt->lte(now()));
        $this->assertFalse(app(ParentGuardianAccessService::class)->lineMayAccessStudent($line, (int) $child->id));
    }

    public function test_revoked_guardian_wins_over_stale_slb_dual_read(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('4');
        $child = $this->student();
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_REVOKED,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
            'revoked_at' => now(),
        ]);
        StudentLineBinding::create([
            'student_id' => $child->id,
            'line_user_id' => $line,
            'campus_id' => 1,
            'verified_at' => now(),
            'bound_at' => now(),
        ]);

        $this->assertFalse(app(ParentGuardianAccessService::class)->lineMayAccessStudent($line, (int) $child->id));
    }

    public function test_flag_off_falls_back_to_slb_only(): void
    {
        config(['perfflags.multi_guardian_enabled' => false]);
        $line = $this->lineId('5');
        $child = $this->student();
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        // No SLB → empty when flag off
        $this->assertCount(0, app(ParentGuardianAccessService::class)->studentsForLineUser($line));

        StudentLineBinding::create([
            'student_id' => $child->id,
            'line_user_id' => $line,
            'campus_id' => 1,
            'verified_at' => now(),
            'bound_at' => now(),
        ]);
        $this->assertCount(1, app(ParentGuardianAccessService::class)->studentsForLineUser($line));
    }

    public function test_multi_guardian_same_student_independent_line_access(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $dadLine = $this->lineId('6');
        $momLine = $this->lineId('7');
        $child = $this->student();
        $dad = Guardian::create(['display_name' => '爸', 'line_user_id' => $dadLine]);
        $mom = Guardian::create(['display_name' => '媽', 'line_user_id' => $momLine]);
        foreach ([$dad, $mom] as $g) {
            StudentGuardian::create([
                'student_id' => $child->id,
                'guardian_id' => $g->id,
                'campus_id' => 1,
                'role' => $g->id === $dad->id ? StudentGuardian::ROLE_FATHER : StudentGuardian::ROLE_MOTHER,
                'is_primary' => $g->id === $dad->id,
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => true,
                'notify_tuition' => true,
                'source' => StudentGuardian::SOURCE_STAFF,
            ]);
        }
        $svc = app(ParentGuardianAccessService::class);
        $this->assertTrue($svc->lineMayAccessStudent($dadLine, (int) $child->id));
        $this->assertTrue($svc->lineMayAccessStudent($momLine, (int) $child->id));

        app(GuardianSyncService::class)->revoke(
            StudentGuardian::query()->where('guardian_id', $dad->id)->firstOrFail()
        );
        $this->assertFalse($svc->lineMayAccessStudent($dadLine, (int) $child->id));
        $this->assertTrue($svc->lineMayAccessStudent($momLine, (int) $child->id));
    }

    public function test_preferred_campus_orders_matching_student_first(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('8');
        $c1 = $this->student(['CampusID' => 1, 'name' => '校區一']);
        $c2 = $this->student(['CampusID' => 2, 'name' => '校區二']);
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        foreach ([$c1, $c2] as $child) {
            StudentGuardian::create([
                'student_id' => $child->id,
                'guardian_id' => $guardian->id,
                'campus_id' => (int) $child->CampusID,
                'role' => StudentGuardian::ROLE_FATHER,
                'is_primary' => true,
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => true,
                'notify_tuition' => true,
                'source' => StudentGuardian::SOURCE_STAFF,
            ]);
        }
        $ordered = app(ParentGuardianAccessService::class)->studentsForLineUser($line, 2);
        $this->assertSame((int) $c2->id, (int) $ordered->first()->id);
        $this->assertCount(2, $ordered);
    }

    public function test_login_line_http_uses_guardian_links_when_flag_on(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('9');
        $child = $this->student(['name' => 'HTTP童']);
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        \Illuminate\Support\Facades\Http::fake([
            'https://api.line.me/v2/profile' => \Illuminate\Support\Facades\Http::response(['userId' => $line], 200),
        ]);

        $res = $this->postJson('/api/v1/parent/login-line', ['access_token' => 'valid-token']);
        $res->assertOk();
        $this->assertContains($child->id, collect($res->json('students'))->pluck('id')->all());
        $this->assertDatabaseHas('ParentSession', [
            'StudentID' => $child->id,
            'line_user_id' => $line,
        ]);
    }

    public function test_phoneless_guardian_revoke_does_not_kill_phone_login_sessions(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $child = $this->student();
        $guardian = Guardian::create([
            'display_name' => '僅手機爸',
            'phone' => '0912999888',
            'phone_normalized' => '0912999888',
            'line_user_id' => null,
        ]);
        $link = StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        $raw = Str::random(48);
        $session = ParentSession::create([
            'StudentID' => $child->id,
            'line_user_id' => null,
            'TokenHash' => hash('sha256', $raw),
            'ExpiresAt' => now()->addDays(30),
        ]);

        app(GuardianSyncService::class)->revoke($link->fresh(['guardian']));

        $session->refresh();
        $this->assertTrue($session->ExpiresAt->gt(now()), 'phone-login sessions must survive phoneless guardian revoke');
    }

    public function test_revoke_unverifies_matching_slb(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('a');
        $child = $this->student();
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        $link = StudentGuardian::create([
            'student_id' => $child->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        StudentLineBinding::create([
            'student_id' => $child->id,
            'line_user_id' => $line,
            'campus_id' => 1,
            'verified_at' => now(),
            'bound_at' => now(),
        ]);

        app(GuardianSyncService::class)->revoke($link->fresh(['guardian']));

        $this->assertNull(
            StudentLineBinding::query()->where('student_id', $child->id)->where('line_user_id', $line)->value('verified_at')
        );
        $this->assertFalse(app(ParentGuardianAccessService::class)->lineMayAccessStudent($line, (int) $child->id));
    }

    public function test_canonical_ignores_slb_only_sibling_when_guardian_exists(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('b');
        $linked = $this->student(['name' => '有連結']);
        $slbOnly = $this->student(['name' => '僅SLB']);
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        StudentGuardian::create([
            'student_id' => $linked->id,
            'guardian_id' => $guardian->id,
            'campus_id' => 1,
            'role' => StudentGuardian::ROLE_FATHER,
            'is_primary' => true,
            'status' => StudentGuardian::STATUS_ACTIVE,
            'notify_learning_feedback' => true,
            'notify_tuition' => true,
            'source' => StudentGuardian::SOURCE_STAFF,
        ]);
        foreach ([$linked, $slbOnly] as $child) {
            StudentLineBinding::create([
                'student_id' => $child->id,
                'line_user_id' => $line,
                'campus_id' => 1,
                'verified_at' => now(),
                'bound_at' => now(),
            ]);
        }

        $svc = app(ParentGuardianAccessService::class);
        $this->assertTrue($svc->lineMayAccessStudent($line, (int) $linked->id));
        $this->assertFalse($svc->lineMayAccessStudent($line, (int) $slbOnly->id));
    }

    public function test_secondary_guardian_phone_login_match(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $child = $this->student(['parent_phone' => '0912111000']);
        $dad = Guardian::create([
            'display_name' => '爸',
            'phone' => '0912111000',
            'phone_normalized' => '0912111000',
        ]);
        $mom = Guardian::create([
            'display_name' => '媽',
            'phone' => '0912222000',
            'phone_normalized' => '0912222000',
        ]);
        foreach ([$dad, $mom] as $i => $g) {
            StudentGuardian::create([
                'student_id' => $child->id,
                'guardian_id' => $g->id,
                'campus_id' => 1,
                'role' => $i === 0 ? StudentGuardian::ROLE_FATHER : StudentGuardian::ROLE_MOTHER,
                'is_primary' => $i === 0,
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => true,
                'notify_tuition' => true,
                'source' => StudentGuardian::SOURCE_STAFF,
            ]);
        }

        $this->assertTrue(\App\Support\StudentContactPhone::matchesNormalizedInput($child, '0912222000'));
        $this->assertFalse(\App\Support\StudentContactPhone::matchesNormalizedInput($child, '0912333000'));
    }

    public function test_cross_campus_multi_child_via_guardian(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $line = $this->lineId('c');
        $c1 = $this->student(['CampusID' => 1, 'name' => '大安']);
        $c2 = $this->student(['CampusID' => 2, 'name' => '信義']);
        $guardian = Guardian::create(['display_name' => '爸', 'line_user_id' => $line]);
        foreach ([$c1, $c2] as $child) {
            StudentGuardian::create([
                'student_id' => $child->id,
                'guardian_id' => $guardian->id,
                'campus_id' => (int) $child->CampusID,
                'role' => StudentGuardian::ROLE_FATHER,
                'is_primary' => true,
                'status' => StudentGuardian::STATUS_ACTIVE,
                'notify_learning_feedback' => true,
                'notify_tuition' => true,
                'source' => StudentGuardian::SOURCE_STAFF,
            ]);
        }
        $svc = app(ParentGuardianAccessService::class);
        $this->assertTrue($svc->lineMayAccessStudent($line, (int) $c1->id));
        $this->assertTrue($svc->lineMayAccessStudent($line, (int) $c2->id));
        $ordered = $svc->studentsForLineUser($line, 2);
        $this->assertSame((int) $c2->id, (int) $ordered->first()->id);
    }

    public function test_find_or_create_does_not_steal_line_across_guardians(): void
    {
        config(['perfflags.multi_guardian_enabled' => true]);
        $child = $this->student(['parent_phone' => '0912444555']);
        $dadLine = $this->lineId('d');
        $momLine = $this->lineId('e');
        $sync = app(GuardianSyncService::class);
        $dadLink = $sync->linkFromLineBinding($child, $dadLine, 101);
        $momLink = $sync->linkFromLineBinding($child, $momLine, 102);
        $this->assertNotNull($dadLink);
        $this->assertNotNull($momLink);
        $this->assertNotSame((int) $dadLink->guardian_id, (int) $momLink->guardian_id);
        $this->assertSame($dadLine, $dadLink->guardian->line_user_id);
        $this->assertSame($momLine, $momLink->guardian->line_user_id);
    }
}
