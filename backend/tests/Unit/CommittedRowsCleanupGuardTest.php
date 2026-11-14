<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * A test that sets `$connectionsToTransact = []` commits its rows (RefreshDatabase wraps no transaction), so it must use
 * Tests\Support\CleansCommittedRows or the nightly random-order run sees its leftovers in later tests (#5154).
 */
class CommittedRowsCleanupGuardTest extends TestCase
{
    public function test_every_test_that_commits_rows_uses_the_cleanup_trait(): void
    {
        $offenders = [];
        $found = 0;
        $root = dirname(__DIR__);
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!str_ends_with($file->getFilename(), 'Test.php') || $file->getRealPath() === __FILE__) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // Property form (`$connectionsToTransact = []`) and method form (`function connectionsToTransact() { return []; }`).
            $commits = preg_match('/\$connectionsToTransact\s*=\s*\[\s*\]/', $code) === 1
                || preg_match('/function\s+connectionsToTransact\s*\(/', $code) === 1;
            if (!$commits) {
                continue;
            }
            $found++;
            if (!str_contains($code, 'CleansCommittedRows')) {
                $offenders[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }
        $this->assertGreaterThan(0, $found, 'scanner found no committing test: the pattern or path is wrong');
        $this->assertSame([], $offenders, 'add `use \Tests\Support\CleansCommittedRows;` to these tests');
    }

    public function test_the_trait_follows_the_laravel_hook_naming_rule(): void
    {
        $trait = new \ReflectionClass(\Tests\Support\CleansCommittedRows::class);
        $this->assertTrue($trait->hasMethod('setUp' . $trait->getShortName()));
    }
}
