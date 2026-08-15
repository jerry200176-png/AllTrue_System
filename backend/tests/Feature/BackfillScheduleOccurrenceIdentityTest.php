<?php

namespace Tests\Feature;

use App\Models\Schedule;
use App\Services\BackfillScheduleOccurrenceIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class BackfillScheduleOccurrenceIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_freezes_two_hop_chain_on_live_head_only(): void
    {
        $courseId = 2688;
        $ghost = $this->row($courseId, '2026-08-01', '10:00', 'scheduled', null);
        $anchor1 = $this->row($courseId, '2026-08-01', '10:00', 'rescheduled', null);
        $mid = $this->row($courseId, '2026-08-02', '17:00', 'scheduled', (int) $anchor1->id);
        $anchor2 = $this->row($courseId, '2026-08-02', '17:00', 'rescheduled', null);
        $live = $this->row($courseId, '2026-08-03', '09:00', 'scheduled', (int) $anchor2->id);
        $this->row($courseId, '2026-08-04', '11:00', 'scheduled', null, 'extra');

        $plan = app(BackfillScheduleOccurrenceIdentityService::class)->plan($courseId);

        $this->assertSame(1, count($plan['candidates']));
        $this->assertSame((int) $live->id, $plan['candidates'][0]['id']);
        $this->assertSame('2026-08-01', $plan['candidates'][0]['identity_date']);
        $this->assertSame('10:00', $plan['candidates'][0]['identity_time']);
        $supersededIds = collect($plan['superseded'])->pluck('id')->all();
        $this->assertContains((int) $ghost->id, $supersededIds);
        $this->assertContains((int) $mid->id, $supersededIds);
        $this->assertSame(1, $plan['extras_skipped']);
        $this->assertSame([], $plan['collisions']);

        $this->artisan('schedules:backfill-occurrence-identity', [
            '--student-course-id' => $courseId,
        ])->assertOk();

        $live->refresh();
        $this->assertNull($live->original_schedule_date);
    }

    public function test_execute_stamps_live_head_and_rollback_clears_it(): void
    {
        $courseId = 1249;
        $anchor1 = $this->row($courseId, '2026-07-01', '10:00', 'rescheduled', null);
        $this->row($courseId, '2026-07-02', '17:00', 'scheduled', (int) $anchor1->id);
        $anchor2 = $this->row($courseId, '2026-07-02', '17:00', 'rescheduled', null);
        $live = $this->row($courseId, '2026-07-03', '09:00', 'scheduled', (int) $anchor2->id);

        $snapshot = storage_path('app/repair-snapshots/td076-test.json');
        @unlink($snapshot);

        $this->artisan('schedules:backfill-occurrence-identity', [
            '--student-course-id' => $courseId,
            '--execute' => true,
            '--snapshot' => $snapshot,
        ])->assertOk();

        $live->refresh();
        $this->assertSame('2026-07-01', substr((string) $live->original_schedule_date, 0, 10));
        $this->assertSame('10:00', substr((string) $live->original_start_time, 0, 5));

        $this->artisan('schedules:backfill-occurrence-identity', [
            '--rollback' => true,
            '--execute' => true,
            '--snapshot' => $snapshot,
        ])->assertOk();

        $live->refresh();
        $this->assertNull($live->original_schedule_date);
        $this->assertNull($live->original_start_time);
        @unlink($snapshot);
    }

    public function test_collision_two_live_heads_same_identity_are_not_stamped(): void
    {
        $courseId = 99;
        $root = $this->row($courseId, '2026-06-01', '10:00', 'rescheduled', null);
        $a = $this->row($courseId, '2026-06-02', '11:00', 'scheduled', (int) $root->id);
        $b = $this->row($courseId, '2026-06-03', '12:00', 'scheduled', (int) $root->id);

        $plan = app(BackfillScheduleOccurrenceIdentityService::class)->plan($courseId);
        $this->assertNotEmpty($plan['collisions']);
        $this->assertSame([], $plan['candidates']);

        $snapshot = storage_path('app/repair-snapshots/td076-collision.json');
        @unlink($snapshot);
        $this->artisan('schedules:backfill-occurrence-identity', [
            '--student-course-id' => $courseId,
            '--execute' => true,
            '--snapshot' => $snapshot,
        ])->assertOk();

        $a->refresh();
        $b->refresh();
        $this->assertNull($a->original_schedule_date);
        $this->assertNull($b->original_schedule_date);
        @unlink($snapshot);
    }

    public function test_production_execute_is_blocked_without_repair_gate(): void
    {
        $prev = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $code = Artisan::call('schedules:backfill-occurrence-identity', [
                '--execute' => true,
                '--snapshot' => storage_path('app/repair-snapshots/td076-blocked.json'),
            ]);
            $this->assertSame(1, $code);
            $this->assertStringContainsString('Production requires --force', Artisan::output());
        } finally {
            $this->app['env'] = $prev;
        }
    }

    private function row(
        int $courseId,
        string $date,
        string $start,
        string $status,
        ?int $originalId,
        string $type = 'normal'
    ): Schedule {
        return Schedule::create([
            'student_id' => 1,
            'teacher_id' => 1,
            'subject' => 'Math',
            'day_of_week' => 1,
            'start_time' => $start,
            'end_time' => '12:00',
            'duration_hours' => 2,
            'class_type' => 'one_on_one',
            'status' => $status,
            'type' => $type,
            'deduction' => $status === 'scheduled' ? 1 : 0,
            'branch_id' => 1,
            'schedule_date' => $date,
            'student_course_id' => $courseId,
            'original_schedule_id' => $originalId,
        ]);
    }
}
