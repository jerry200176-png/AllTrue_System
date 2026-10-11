<?php

namespace Tests\Feature;

use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sentry Subject_Name N+1 family (#3814/#3815/#3816/#2491): per-row displaySubjectName() must not query per course. */
class StudentClassDisplaySubjectNameQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_many_courses_of_one_subject_cost_one_subject_query(): void
    {
        DB::table('Subject')->insert(['id' => 9701, 'School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => 'Chinese']);
        $courses = collect(range(1, 20))->map(fn () => (new StudentClass())->forceFill(['SubjectID' => 9701]));

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'Subject')) {
                $queries++;
            }
        });
        $names = $courses->map(fn (StudentClass $c) => $c->displaySubjectName())->unique()->values()->all();

        $this->assertSame(['Chinese'], $names);
        $this->assertLessThanOrEqual(1, $queries);
    }
}
