<?php

namespace Tests\Unit;

use App\Support\Utf8mb3SearchSanitizer;
use PHPUnit\Framework\TestCase;

class Utf8mb3SearchSanitizerTest extends TestCase
{
    public function test_removes_four_byte_unicode_chars_for_utf8mb3_like_queries(): void
    {
        $this->assertSame('王小明', Utf8mb3SearchSanitizer::forLike('王🀄️小明'));
    }

    public function test_returns_empty_string_when_term_is_only_emoji(): void
    {
        $this->assertSame('', Utf8mb3SearchSanitizer::forLike('🀄️'));
    }
}
