<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Path;

/** GHSA-6prc-h956-hxjq: a local path containing :// is not necessarily a stream URI. */
class FilesystemPathBoundaryTest extends TestCase
{
    public function test_a_fake_scheme_inside_a_local_path_cannot_escape_the_base_check(): void
    {
        $base = '/srv/uploads';
        $outside = '/srv/uploads/../../private/x://../document.txt';
        $this->assertFalse(Path::isBasePath($base, $outside));
        $this->assertSame('/private/document.txt', Path::canonicalize($outside));
        $this->assertSame('/private/document.txt', Path::makeAbsolute('../../private/x://../document.txt', $base));
        $this->assertSame('file:///', Path::getRoot('file:///srv/uploads/document.txt'));
    }
}
