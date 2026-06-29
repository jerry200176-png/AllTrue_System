<?php

namespace Tests\Feature;

use App\Exports\StudentsExport;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #987: StudentsExport must (a) scope rows to the caller's campuses (PII) and
 * (b) stream in bounded chunks instead of Student::all().
 */
class StudentsExportTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $name, int $campusId): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campusId,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
        ]);
    }

    public function test_export_is_scoped_to_given_campuses(): void
    {
        $this->student('校一甲', 1);
        $this->student('校一乙', 1);
        $this->student('校二甲', 2);

        $rows = (new StudentsExport([1]))->query()->get();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [1, 1],
            $rows->pluck('CampusID')->map(fn ($c) => (int) $c)->all()
        );
    }

    public function test_empty_campus_list_means_no_restriction(): void
    {
        $this->student('校一甲', 1);
        $this->student('校二甲', 2);
        $this->student('校三甲', 3);

        $rows = (new StudentsExport([]))->query()->get();

        $this->assertCount(3, $rows);
    }

    public function test_multi_campus_scope(): void
    {
        $this->student('校一甲', 1);
        $this->student('校二甲', 2);
        $this->student('校三甲', 3);

        $rows = (new StudentsExport([1, 3]))->query()->get();

        $this->assertEqualsCanonicalizing([1, 3], $rows->pluck('CampusID')->map(fn ($c) => (int) $c)->all());
    }

    public function test_uses_bounded_chunk_reading(): void
    {
        $this->assertSame(500, (new StudentsExport())->chunkSize());
    }
}
