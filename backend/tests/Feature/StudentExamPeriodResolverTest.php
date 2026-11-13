<?php

namespace Tests\Feature;

use App\Models\SchoolExamPeriod;
use App\Models\Student;
use App\Services\StudentExamPeriodResolver;
use Database\Factories\CampusFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** forRange (#4970 step 4a): exams inside a date range, any term; forStudent keeps its current-term rule. */
class StudentExamPeriodResolverTest extends TestCase
{
    use RefreshDatabase;

    private function period(int $campus, string $school, int $year, int $sem, int $seq, string $date, ?array $grades = null): void
    {
        SchoolExamPeriod::create(['campus_id' => $campus, 'school_name' => $school, 'academic_year' => $year, 'semester' => $sem,
            'seq' => $seq, 'label' => "L$seq", 'start_date' => $date, 'end_date' => $date, 'grade_ids' => $grades,
            'grade_key' => $grades ? implode(',', $grades) : 'all']);
    }

    public function test_for_range_spans_terms_and_filters_by_school_grade_and_campus(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $b = (int) CampusFactory::new()->create()->id;
        $student = Student::create(['name' => '虛構學生', 'CampusID' => $a, 'ClassID' => 3, 'SchoolName' => '甲測試國中',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->period($a, '甲測試國中', 115, 1, 2, '2026-12-01');
        $this->period($a, '甲測試國中', 115, 2, 1, '2027-03-20');
        $this->period($a, '甲測試國中', 115, 1, 1, '2026-10-20');
        $this->period($a, '甲測試國中', 115, 2, 3, '2027-09-01');           // outside the range
        $this->period($a, '乙測試國中', 115, 1, 1, '2026-10-21');           // other school
        $this->period($a, '甲測試國中', 115, 1, 3, '2027-01-04', [9]);      // other grade
        $this->period($b, '甲測試國中', 115, 1, 1, '2026-10-22');           // other campus

        $rows = app(StudentExamPeriodResolver::class)
            ->forRange($student, Carbon::parse('2026-09-01'), Carbon::parse('2027-06-30'));

        $this->assertSame(['2026-10-20', '2026-12-01', '2027-03-20'], $rows->map(fn ($p) => $p->start_date->toDateString())->all());
        $this->assertSame([[115, 1], [115, 1], [115, 2]], $rows->map(fn ($p) => [$p->academic_year, $p->semester])->all());
    }

    public function test_for_range_is_empty_without_a_school_and_range_ends_are_inclusive(): void
    {
        $a = (int) CampusFactory::new()->create()->id;
        $this->period($a, '甲測試國中', 115, 1, 1, '2026-10-20');
        $this->period($a, '甲測試國中', 115, 1, 2, '2026-12-01');
        $resolver = app(StudentExamPeriodResolver::class);
        $make = fn (?string $school) => Student::create(['name' => '虛構學生', 'CampusID' => $a, 'ClassID' => 3,
            'SchoolName' => $school, 'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);

        $this->assertCount(0, $resolver->forRange($make(null), Carbon::parse('2026-09-01'), Carbon::parse('2027-01-01')));
        $this->assertCount(2, $resolver->forRange($make('甲測試國中'), Carbon::parse('2026-10-20'), Carbon::parse('2026-12-01')));
    }

    public function test_for_student_still_uses_the_current_term_only(): void
    {
        Carbon::setTestNow('2026-11-13 10:00:00');
        $a = (int) CampusFactory::new()->create()->id;
        $student = Student::create(['name' => '虛構學生', 'CampusID' => $a, 'ClassID' => 3, 'SchoolName' => '甲測試國中',
            'enable' => 1, 'MDT' => now(), 'Notify_Token' => '']);
        $this->period($a, '甲測試國中', 115, 1, 1, '2026-10-20');
        $this->period($a, '甲測試國中', 115, 2, 1, '2027-03-20');

        $found = app(StudentExamPeriodResolver::class)->forStudent($student);
        Carbon::setTestNow();

        $this->assertSame([115, 1], [$found['academic_year'], $found['semester']]);
        $this->assertCount(1, $found['rows']);
    }
}
