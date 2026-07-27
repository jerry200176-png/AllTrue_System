<?php

namespace Tests\Feature;

use App\Models\AuthToken;
use App\Models\Campus;
use App\Models\CampusSnapshot;
use App\Models\User;
use App\Models\UserCampus;
use Carbon\Carbon;
use Database\Factories\CampusSnapshotFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DashboardController — authorised API endpoints for director analytics.
 *
 * Tests the documented contract:
 *   - GET /api/v1/dashboard/kpi-summary
 *   - GET /api/v1/dashboard/student-trend
 *   - GET /api/v1/dashboard/revenue-trend
 *   - GET /api/v1/dashboard/attendance-trend
 *   - GET /api/v1/dashboard/teacher-hours
 *   - GET /api/v1/dashboard/campus-comparison (P1 stub)
 *   - GET /api/v1/dashboard/ai-insights (P2 stub)
 *   - POST /api/v1/dashboard/export (P1 stub)
 *
 * Auth layer:
 *   - 401 without token (AttachAuthUser)
 *   - 403 for wrong role (RequireRole middleware)
 *   - Campus isolation via require_campus middleware
 *
 * ⚠️ Depends on Phases 1-3 (Model, Services, Controller).
 *   Tests marked skipped until everything is implemented.
 *   Unskip when DashboardController + routes are registered.
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private Campus $campus1;
    private string $directorToken;
    private string $teacherToken;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Asia/Taipei'));
        $this->campus1 = Campus::factory()->create(['name' => '大安', 'code' => 'daan']);

        // Seed snapshot data for all tests
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-07-01', [
            'student_count' => 62,
            'active_teachers' => 12,
            'monthly_revenue' => 240000,
            'monthly_sessions' => 420,
            'monthly_attendance' => 385,
            'monthly_teaching_hours' => 560,
        ]);
        CampusSnapshotFactory::createSnapshotSet($this->campus1->id, '2026-06-01', [
            'student_count' => 55,
            'active_teachers' => 11,
            'monthly_revenue' => 215000,
            'monthly_sessions' => 395,
            'monthly_attendance' => 372,
            'monthly_teaching_hours' => 530,
        ]);

        // Second campus (for campus isolation tests)
        $this->campus2 = Campus::factory()->create(['name' => '新店', 'code' => 'xindian']);

        // Authenticated users
        $this->directorToken = $this->createDirectorToken([$this->campus1->id], 'director-dash@example.com');
        $this->teacherToken = $this->createTeacherToken($this->campus1->id, 'teacher-dash@example.com');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Authentication & Authorization ──────────────────────────────────

    /** @test */
    public function it_returns_401_without_token(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $this->getJson('/api/v1/dashboard/kpi-summary')
            ->assertStatus(401);
    }

    /** @test */
    public function it_returns_403_for_teacher_role(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        // Dashboard requires role:director — teacher should get 403
        $this->withHeaders([
            'Authorization' => "Bearer {$this->teacherToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/kpi-summary')
            ->assertStatus(403);
    }

    // ─── KPI Summary ─────────────────────────────────────────────────────

    /** @test */
    public function director_can_get_kpi_summary(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/kpi-summary?campus_id=' . $this->campus1->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'period',
                    'cards' => [
                        '*' => ['key', 'label', 'current', 'previous', 'change', 'changePercent', 'unit', 'trend'],
                    ],
                    'byCampus',
                ],
                'message',
            ]);
    }

    /** @test */
    public function kpi_summary_returns_correct_values(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/kpi-summary?campus_id=' . $this->campus1->id);

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame('2026-07', $data['period']);

        $cards = collect($data['cards'])->keyBy('key');
        $this->assertEquals(62, $cards['student_count']['current']);
        $this->assertEquals(55, $cards['student_count']['previous']);
        $this->assertEquals(7, $cards['student_count']['change']);
    }

    /** @test */
    public function kpi_summary_handles_missing_campus_id(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/kpi-summary');

        $response->assertOk();
        $this->assertArrayHasKey('cards', $response->json('data'));
    }

    /** @test */
    public function kpi_summary_returns_empty_data_for_nonexistent_campus(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/kpi-summary?campus_id=999');

        // Should still return 200 with zero/empty data or note, not 500
        $response->assertOk();
        $cards = $response->json('data.cards');
        $hasZero = collect($cards)->contains(fn ($c) => $c['current'] === 0 || $c['current'] === null);
        $this->assertTrue($hasZero, 'Non-existent campus should return zero/empty values.');
    }

    // ─── Student Trend ───────────────────────────────────────────────────

    /** @test */
    public function director_can_get_student_trend(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/student-trend?campus_id=' . $this->campus1->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'labels',
                    'datasets' => [
                        '*' => ['campusId', 'campusName', 'data', 'color'],
                    ],
                    'availableMonths',
                ],
                'message',
            ]);
    }

    /** @test */
    public function student_trend_uses_default_months(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/student-trend?campus_id=' . $this->campus1->id);

        $response->assertOk();
        // With only 2 months data, availableMonths should be 2
        $this->assertEquals(2, $response->json('data.availableMonths'));
    }

    // ─── Revenue Trend ───────────────────────────────────────────────────

    /** @test */
    public function director_can_get_revenue_trend(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/revenue-trend?campus_id=' . $this->campus1->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'labels',
                    'datasets' => [
                        '*' => ['campusId', 'campusName', 'data', 'color'],
                    ],
                    'groupBy',
                ],
                'message',
            ]);
    }

    // ─── Attendance Trend ────────────────────────────────────────────────

    /** @test */
    public function director_can_get_attendance_trend(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/attendance-trend?campus_id=' . $this->campus1->id);

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'labels',
                    'datasets' => [
                        '*' => ['campusId', 'campusName', 'data', 'color'],
                    ],
                    'baseline' => ['value', 'label', 'color', 'dashed'],
                ],
                'message',
            ]);
    }

    // ─── Teacher Hours ───────────────────────────────────────────────────

    /** @test */
    public function director_can_get_teacher_hours(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/teacher-hours?campus_id=' . $this->campus1->id . '&month=2026-07');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'month',
                    'chartData' => ['labels', 'datasets'],
                    'tableData' => [
                        '*' => ['teacherName', 'campusName', 'hours', 'studentCount', 'avgHoursPerStudent'],
                    ],
                ],
                'message',
            ]);
    }

    // ─── Campus Comparison (P1 stub) ─────────────────────────────────────

    /** @test */
    public function campus_comparison_returns_p1_stub(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/campus-comparison');

        // P1: may return 200 with data, or 404 if not yet implemented
        if ($response->status() === 200) {
            $response->assertJsonStructure([
                'data' => ['period', 'columns', 'rows'],
                'message',
            ]);
        } else {
            $response->assertStatus(404);
        }
    }

    // ─── AI Insights (P2 stub) ───────────────────────────────────────────

    /** @test */
    public function ai_insights_returns_empty_array(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/ai-insights');

        // Per spec: P2 stub returns { "insights": [] }
        $response->assertOk();
        $this->assertEmpty($response->json('data.insights'));
    }

    // ─── Export (P1 stub) ────────────────────────────────────────────────

    /** @test */
    public function export_returns_success_or_501(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->postJson('/api/v1/dashboard/export', [
            'format' => 'excel',
            'dateRange' => ['start' => '2026-01', 'end' => '2026-07'],
            'campusIds' => [$this->campus1->id],
            'sections' => ['kpi', 'student_trend'],
        ]);

        // P1: may return 200 (file download) or 501 (not yet implemented)
        $this->assertContains($response->status(), [200, 501],
            'Export should return 200 (done) or 501 (stub).');
    }

    // ─── Parameter Validation ────────────────────────────────────────────

    /** @test */
    public function it_validates_query_parameters(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        // Invalid months parameter
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/student-trend?months=-1');

        // Should either ignore or validate; either way no 500
        $response->assertStatus(200);
    }

    /** @test */
    public function it_validates_teacher_month_format(): void
    {
        $this->markTestSkipped('Phase 3 not yet implemented — DashboardController + routes not registered.');
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->directorToken}",
            'Accept' => 'application/json',
        ])->getJson('/api/v1/dashboard/teacher-hours?month=invalid');

        // Should gracefully handle bad format
        $this->assertContains($response->status(), [200, 422]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function createDirectorToken(array $campusIds, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '主任測試',
            'PSW' => 'secret',
            'type' => 'A',
            'phone' => '0911000000',
            'MustChangePassword' => false,
        ]);

        foreach ($campusIds as $campusId) {
            UserCampus::firstOrCreate([
                'CampusID' => $campusId,
                'UserID' => $user->id,
            ], [
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

    private function createTeacherToken(int $campusId, string $loginName): string
    {
        $user = User::create([
            'LoginName' => $loginName,
            'Name' => '老師測試',
            'PSW' => 'secret',
            'type' => 'T',
            'phone' => '0922111111',
            'MustChangePassword' => false,
        ]);

        UserCampus::create([
            'CampusID' => $campusId,
            'UserID' => $user->id,
            'Admin' => 0,
            'Approved' => 1,
        ]);

        $token = bin2hex(random_bytes(16));
        AuthToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'expires_at' => now()->addDay(),
        ]);

        return $token;
    }
}
