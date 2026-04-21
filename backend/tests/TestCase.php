<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSubjectsIfNeeded();
    }

    private function seedSubjectsIfNeeded(): void
    {
        if (!Schema::hasTable('Subject')) {
            return;
        }
        if (DB::table('Subject')->exists()) {
            return;
        }
        $subjects = ['Chinese', 'English', 'Math', 'Physics', 'Chemistry', 'Science', 'Biology', 'Social'];
        $rows = [];
        foreach ($subjects as $name) {
            $rows[] = ['School_id' => 1, 'Grade_no' => 0, 'Subject_Name' => $name];
        }
        DB::table('Subject')->insert($rows);
    }
}
