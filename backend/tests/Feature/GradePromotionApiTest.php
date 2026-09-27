<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\GradePromotionResult;
use App\Models\Student;
use App\Models\User;
use App\Models\UserCampus;
use App\Services\GradePromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradePromotionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_marks_already_promoted_non_actionable(): void
    {
        $director = $this->director([1]);
        $a = $this->student(1, 'Ada', 7);
        $b = $this->student(1, 'Ben', 12);
        GradePromotionResult::create([
            'batch_id' => 1, 'student_id' => $a->id, 'season_year' => 2026,
            'from_grade' => 'P6', 'to_grade' => 'J1', 'graduated' => false, 'created_at' => now(),
        ]);

        $res = $this->withHeaders($this->bearer($director['tok']))
            ->getJson('/api/v1/grade-promotions/preview?branch_id=1&season_year=2026')
            ->assertOk();
        $byId = collect($res->json('data'))->keyBy('student_id');
        $this->assertFalse($byId[$a->id]['actionable']);
        $this->assertTrue($byId[$a->id]['already_promoted']);
        $this->assertTrue($byId[$b->id]['actionable']);
        $this->assertTrue($byId[$b->id]['graduated']);
        $this->assertSame('2026-08-01', $res->json('admin_date'));
    }

    public function test_confirm_promotes_and_graduates_without_course_stop(): void
    {
        $director = $this->director([1]);
        $j1 = $this->student(1, 'Jay', 7);
        $h3 = $this->student(1, 'Hal', 12);
        $payload = ['branch_id' => 1, 'season_year' => 2026, 'idempotency_key' => 'promo-test-key-0001'];

        $res = $this->withHeaders($this->bearer($director['tok']))
            ->postJson('/api/v1/grade-promotions/confirm', $payload)
            ->assertCreated();
        $this->assertFalse($res->json('replayed'));
        $this->assertSame(2, $res->json('summary.applied'));

        $j1->refresh();
        $h3->refresh();
        $this->assertSame(8, (int) $j1->ClassID);
        $this->assertSame('graduated', $h3->status);
        $this->assertSame(12, (int) $h3->ClassID);

        $replay = $this->withHeaders($this->bearer($director['tok']))
            ->postJson('/api/v1/grade-promotions/confirm', $payload)
            ->assertOk();
        $this->assertTrue($replay->json('replayed'));

        $late = $this->student(1, 'Late', 1);
        $second = $this->withHeaders($this->bearer($director['tok']))
            ->postJson('/api/v1/grade-promotions/confirm', [
                'branch_id' => 1, 'season_year' => 2026, 'idempotency_key' => 'promo-test-key-0002',
            ])
            ->assertCreated();
        $this->assertSame(1, $second->json('summary.applied'));
        $late->refresh();
        $this->assertSame(2, (int) $late->ClassID);
    }

    public function test_exclude_and_campus_forbidden(): void
    {
        $director = $this->director([1]);
        $keep = $this->student(1, 'Keep', 1);
        $skip = $this->student(1, 'Skip', 2);

        $this->withHeaders($this->bearer($director['tok']))
            ->postJson('/api/v1/grade-promotions/confirm', [
                'branch_id' => 1, 'season_year' => 2026, 'idempotency_key' => 'promo-exclude-1',
                'exclude_student_ids' => [$skip->id],
            ])
            ->assertCreated()
            ->assertJsonPath('summary.applied', 1);

        $keep->refresh();
        $skip->refresh();
        $this->assertSame(2, (int) $keep->ClassID);
        $this->assertSame(2, (int) $skip->ClassID);

        $other = $this->director([2], 'other-dir@example.com');
        $this->withHeaders($this->bearer($other['tok']))
            ->getJson('/api/v1/grade-promotions/preview?branch_id=1&season_year=2026')
            ->assertForbidden();
    }

    public function test_admin_date_is_centralized(): void
    {
        $svc = app(GradePromotionService::class);
        $this->assertSame('2026-08-01', $svc->adminDateForYear(2026)->toDateString());
        $this->assertSame('J2', $svc->nextGrade('J1'));
        $this->assertNull($svc->nextGrade('H3'));
    }

    /** @return array{id:int,tok:string} */
    private function director(array $campusIds, string $email = 'dir-promo@example.com'): array
    {
        $user = User::create([
            'LoginName' => $email, 'Name' => 'Director',
            'PSW' => password_hash('secret-123', PASSWORD_DEFAULT),
            'type' => 'A', 'phone' => '0911111111', 'MustChangePassword' => false,
        ]);
        foreach ($campusIds as $cid) {
            UserCampus::create(['UserID' => $user->id, 'CampusID' => $cid, 'Admin' => 1, 'Approved' => 1]);
        }
        $tok = bin2hex(random_bytes(16));
        AuthToken::create(['user_id' => $user->id, 'token' => $tok, 'expires_at' => now()->addDay()]);

        return ['id' => (int) $user->id, 'tok' => $tok];
    }

    private function student(int $campusId, string $name, int $classId): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $campusId, 'ClassID' => $classId,
            'status' => 'active', 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '',
        ]);
    }

    /** @return array<string, string> */
    private function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
