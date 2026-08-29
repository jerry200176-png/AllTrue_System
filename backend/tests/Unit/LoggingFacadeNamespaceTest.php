<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class LoggingFacadeNamespaceTest extends TestCase
{
    public function test_application_code_does_not_call_the_global_log_class(): void
    {
        $root = dirname(__DIR__, 2);
        $paths = [$root . '/routes/api.php'];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root . '/app', \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        $offenders = [];
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            if ($contents !== false && $this->containsGlobalLogCall($contents)) {
                $offenders[] = str_replace($root . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders, 'Use the Laravel logging facade, not the global Log class.');
    }

    private function containsGlobalLogCall(string $contents): bool
    {
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $offset = 0;
            while (($match = strpos($line, '\\Log::', $offset)) !== false) {
                $previous = $match > 0 ? $line[$match - 1] : '';
                if ($previous !== '\\' && !ctype_alnum($previous) && $previous !== '_') {
                    return true;
                }
                $offset = $match + 1;
            }
        }

        return false;
    }
}
