<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Student;
use App\Services\GradePromotionScheduledPreviewService;
use App\Services\GradePromotionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GradePromotionScheduledPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'grade_promotion.auto_confirm' => false,
            'grade_promotion.campus_allowlist' => [9],
            'grade_promotion.timezone' => 'Asia/Taipei',
            'grade_promotion.admin_month' => 8,
            'grade_promotion.admin_day' => 1,
        ]);
    }

    public function test_empty_allowlist_fails_closed(): void
    {
        config(['grade_promotion.campus_allowlist' => []]);
        $svc = app(GradePromotionScheduledPreviewService::class);
        $result = $svc->run(Carbon::parse('2026-08-01 08:00:00', 'Asia/Taipei'));
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('empty_campus_allowlist', $result['reason']);
    }

    public function test_skips_when_not_admin_date(): void
    {
        $svc = app(GradePromotionScheduledPreviewService::class);
        $result = $svc->run(Carbon::parse('2026-07-15 08:00:00', 'Asia/Taipei'));
        $this->assertSame('skipped', $result['status']);
        $this->assertSame('not_admin_date', $result['reason']);
    }

    public function test_admin_date_runs_preview_and_staff_notification_without_confirm(): void
    {
        Student::create(['CampusID' => 9, 'name' => 'Promo', 'ClassID' => 7, 'status' => 'active']);
        $svc = app(GradePromotionScheduledPreviewService::class);
        $at = Carbon::parse('2026-08-01 08:05:00', 'Asia/Taipei');
        $result = $svc->run($at);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(9, $result['campuses'][0]['campus_id']);
        $this->assertGreaterThanOrEqual(1, $result['campuses'][0]['actionable']);

        $note = Notification::where('SourceKey', 'grade-promotion:preview-reminder:9:2026')->first();
        $this->assertNotNull($note);
        $this->assertSame('grade_promotion_preview', $note->Type);
        $this->assertSame(9, (int) $note->CampusID);
        $this->assertStringContainsString('不會自動確認', $note->Body);
    }

    public function test_auto_confirm_flag_rejected_in_phase_b1(): void
    {
        config(['grade_promotion.auto_confirm' => true]);
        $this->expectException(\RuntimeException::class);
        app(GradePromotionScheduledPreviewService::class)->run(
            Carbon::parse('2026-08-01 08:00:00', 'Asia/Taipei')
        );
    }

    public function test_command_force_date_succeeds_on_non_admin_calendar_day(): void
    {
        Student::create(['CampusID' => 9, 'name' => 'Cmd', 'ClassID' => 7, 'status' => 'active']);
        $this->artisan('grade-promotion:scheduled-preview', ['--force-date' => true])
            ->assertSuccessful();
    }

    public function test_uses_existing_grade_promotion_writer_only(): void
    {
        $promotion = $this->createMock(GradePromotionService::class);
        $promotion->method('defaultSeasonYear')->willReturn(2026);
        $promotion->method('adminDateForYear')->willReturn(Carbon::parse('2026-08-01', 'Asia/Taipei'));
        $promotion->expects($this->once())->method('preview')->with(9, 2026)->willReturn([
            ['student_id' => 1, 'actionable' => true],
        ]);
        $svc = new GradePromotionScheduledPreviewService($promotion);
        $result = $svc->run(Carbon::parse('2026-08-01 08:00:00', 'Asia/Taipei'));
        $this->assertSame('ok', $result['status']);
    }
}
