<?php

namespace Tests\Unit;

use App\Services\BugFamily;
use PHPUnit\Framework\TestCase;

class BugFamilyTest extends TestCase
{
    private const RULES = [
        'billing' => ['pages' => ['tuition'], 'keywords' => ['繳費']],
        'calendar' => ['pages' => ['calendar'], 'keywords' => ['排課']],
        'ui' => ['pages' => [], 'keywords' => ['按鈕']],
    ];

    public function test_page_key_beats_a_single_keyword(): void
    {
        $this->assertSame('billing', BugFamily::classify('tuition-collect', '排課怪怪的', self::RULES));
    }

    public function test_keyword_only_and_case_insensitive(): void
    {
        $this->assertSame('ui', BugFamily::classify(null, '按鈕點不了', self::RULES));
        $this->assertSame('calendar', BugFamily::classify('CALENDAR', '', self::RULES));
    }

    public function test_no_match_and_ties_are_deterministic(): void
    {
        $this->assertNull(BugFamily::classify('x', '完全無關', self::RULES));
        $this->assertSame('billing', BugFamily::classify(null, '繳費 排課', self::RULES), 'tie goes to the first family');
    }

    public function test_shipped_config_maps_real_reports_and_names_are_label_safe(): void
    {
        $families = require __DIR__ . '/../../config/bug_families.php';
        $this->assertSame('billing', BugFamily::classify('tuition-collect', '', $families['families']));
        $this->assertSame('calendar', BugFamily::classify(null, '代課老師沒出現在行事曆', $families['families']));
        foreach (array_keys($families['families']) as $name) {
            $this->assertMatchesRegularExpression('/^[a-z]+(-[a-z]+)*$/', $name);
        }
    }
}
