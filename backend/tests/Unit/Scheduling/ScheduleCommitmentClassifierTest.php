<?php

namespace Tests\Unit\Scheduling;

use App\Services\Scheduling\CommitmentReasonCodes;
use App\Services\Scheduling\ScheduleCommitmentClassifier;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class ScheduleCommitmentClassifierTest extends TestCase
{
    private ScheduleCommitmentClassifier $classifier;
    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ScheduleCommitmentClassifier();
        $this->today = Carbon::parse('2026-07-13', 'Asia/Taipei'); // Monday
    }

    public function test_explicit_commitment_when_contract_slots_complete_and_no_history_conflict(): void
    {
        $course = $this->course([
            'week' => 1,
            'time' => '16:00',
            'TeacherID' => 10,
            'SubjectID' => 2,
            'CampusID' => 22,
        ]);
        // History matches Monday 16:00
        $history = [
            ['SessionDate' => '2026-07-06', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'],
            ['SessionDate' => '2026-06-29', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'],
        ];
        $r = $this->classifier->classify($course, $history, $this->today);
        $this->assertSame(CommitmentReasonCodes::CLASS_EXPLICIT, $r['classification']);
        $this->assertTrue($r['auto_ensure_eligible']);
        $this->assertNotEmpty($r['commitment_fingerprint']);
    }

    public function test_legacy_inferred_when_no_contract_slots_but_history_cadence(): void
    {
        $course = $this->course([]); // no week/time
        $history = [
            ['SessionDate' => '2026-07-06', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'],
            ['SessionDate' => '2026-06-29', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'],
        ];
        $r = $this->classifier->classify($course, $history, $this->today);
        $this->assertSame(CommitmentReasonCodes::CLASS_LEGACY, $r['classification']);
        $this->assertSame(CommitmentReasonCodes::LEGACY_INFERRED_CANDIDATE, $r['primary_reason']);
        $this->assertFalse($r['auto_ensure_eligible']);
        $this->assertTrue($r['guess_required']);
    }

    public function test_conflict_when_contract_differs_from_history_cadence(): void
    {
        $course = $this->course([
            'week' => 3, // Wednesday
            'time' => '19:00',
            'TeacherID' => 10,
            'SubjectID' => 2,
            'CampusID' => 22,
        ]);
        $history = [
            ['SessionDate' => '2026-07-06', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'], // Mon
            ['SessionDate' => '2026-06-29', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'],
        ];
        $r = $this->classifier->classify($course, $history, $this->today);
        $this->assertSame(CommitmentReasonCodes::CLASS_CONFLICT, $r['classification']);
        $this->assertSame(CommitmentReasonCodes::BLOCK_COMMITMENT_CONFLICT, $r['primary_reason']);
    }

    public function test_flexible_when_no_slots_and_no_cadence(): void
    {
        $course = $this->course([]);
        $history = [
            ['SessionDate' => '2026-07-06', 'StartTime' => '16:00:00', 'EndTime' => '18:00:00'],
            ['SessionDate' => '2026-07-08', 'StartTime' => '17:00:00', 'EndTime' => '19:00:00'],
        ];
        $r = $this->classifier->classify($course, $history, $this->today);
        $this->assertSame(CommitmentReasonCodes::CLASS_FLEXIBLE, $r['classification']);
        $this->assertSame(CommitmentReasonCodes::INFO_FLEXIBLE_NO_COMMITMENT, $r['primary_reason']);
    }

    public function test_incomplete_when_slot_present_but_teacher_missing(): void
    {
        $course = $this->course([
            'week' => 1,
            'time' => '16:00',
            'TeacherID' => 0,
            'SubjectID' => 2,
            'CampusID' => 22,
        ]);
        $r = $this->classifier->classify($course, [], $this->today);
        $this->assertSame(CommitmentReasonCodes::CLASS_INCOMPLETE, $r['classification']);
        $this->assertSame(CommitmentReasonCodes::BLOCK_COMMITMENT_INCOMPLETE, $r['primary_reason']);
    }

    /** @param array<string,mixed> $over */
    private function course(array $over): array
    {
        return array_merge([
            'ID' => 1001,
            'ScheduleMode' => 'count',
            'Stop' => 0,
            'RemainingSessions' => 8,
            'TeacherID' => 1,
            'SubjectID' => 1,
            'CampusID' => 1,
            'PackageID' => 0,
            'StartDate' => '2026-05-01',
            'EndDate' => '2026-12-31',
            'SessionDuration' => 120,
            'week' => null,
            'time' => null,
        ], $over);
    }
}
