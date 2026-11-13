<?php

namespace App\Services;

use App\Models\SchoolExamPeriod;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * The school exam periods that apply to one student this term (#4970 step 1 rule, shared with the learning plan).
 * Same campus, current ROC academic year and semester, same normalised school name, and the student's grade.
 */
class StudentExamPeriodResolver
{
    public function __construct(private SchoolNameGrouper $grouper)
    {
    }

    /** @return array{rows: Collection<int, SchoolExamPeriod>, academic_year: int, semester: int} */
    public function forStudent(Student $student): array
    {
        [$year, $semester] = $this->currentTerm();
        $key = $this->grouper->key((string) $student->SchoolName);
        $rows = $key === '' ? collect() : SchoolExamPeriod::query()
            ->where('campus_id', $student->CampusID)->where('academic_year', $year)->where('semester', $semester)
            ->orderBy('seq')->get()
            ->filter(fn ($p) => $this->grouper->key($p->school_name) === $key
                && ($p->grade_ids === null || in_array((int) $student->ClassID, array_map('intval', $p->grade_ids), true)))
            ->values();

        return ['rows' => $rows, 'academic_year' => $year, 'semester' => $semester];
    }

    /** ROC year + semester: Aug–Jan = 1st, Feb–Jul = 2nd. */
    public function currentTerm(): array
    {
        $now = now();
        $m = (int) $now->format('n');
        $y = (int) $now->format('Y') - 1911;

        return $m >= 8 ? [$y, 1] : ($m === 1 ? [$y - 1, 1] : [$y - 1, 2]);
    }
}
