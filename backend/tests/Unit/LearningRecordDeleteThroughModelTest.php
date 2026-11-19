<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TD-074: LearningRecord keeps a teacher-written body as a snapshot in its `deleting` model event. A query-builder
 * delete (`LearningRecord::where(...)->delete()`, `DB::table('LearningRecord')->...->delete()`) skips model events,
 * so the text is lost for good. Load the rows and delete them one by one (`->get()->each->delete()`).
 */
class LearningRecordDeleteThroughModelTest extends TestCase
{
    /** Whole-data purges that must not leave copies behind. */
    private const ALLOWED = [
        'Http/Controllers/ResetDataController.php',  // test-environment reset of every table
        'Http/Controllers/StudentController.php',    // purgeStudentRecords(): only for students with no history
    ];

    public function test_learning_records_are_never_bulk_deleted_past_the_model(): void
    {
        $app = realpath(__DIR__ . '/../../app');
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($app));
        foreach ($files as $file) {
            $rel = substr((string) $file, strlen($app) + 1);
            if (!str_ends_with($rel, '.php') || in_array($rel, self::ALLOWED, true)) {
                continue;
            }
            // One statement = text up to the next `;`; flag those that start a LearningRecord query and end in delete().
            foreach (explode(';', (string) file_get_contents((string) $file)) as $statement) {
                if (preg_match("/(LearningRecord::|table\\('LearningRecord'\\))[\\s\\S]*->(force)?[dD]elete\\(\\)\\s*$/", $statement)
                    && !preg_match('/->each->delete\(\)\s*$/', $statement)) {
                    $found[] = $rel . ': ' . trim(preg_replace('/\s+/', ' ', $statement));
                }
            }
        }

        $this->assertSame([], $found, 'Delete LearningRecord rows through the model: ->get()->each->delete().');
    }
}
