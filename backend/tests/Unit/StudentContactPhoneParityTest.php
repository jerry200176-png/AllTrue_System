<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Support\StudentContactPhone;
use Tests\TestCase;

/**
 * §R10 parity SSOT lock for the parent-facing contact phone resolver.
 *
 * Background (issue #1044): parent-facing flows must honor the UI「家長手機」
 * (`parent_phone`) BEFORE the legacy `Phone` column. The two highest-traffic
 * match paths already enforce this and have integration coverage:
 *   - Parent portal login  → ParentPortalLoginIsolationTest
 *   - LINE OA text bind     → LineWebhookBindingTest (PR #1037)
 *
 * Both delegate to {@see StudentContactPhone}. This test locks the resolver
 * itself so any future caller inherits correct behavior — preventing the
 * "bind/login succeeds for some channels but no data on others" class of
 * support escalations seen with ~6/22 大安 students before #1037.
 *
 * Pure attribute access only — no DB connection required.
 */
class StudentContactPhoneParityTest extends TestCase
{
    private function student(?string $parentPhone, ?string $phone): Student
    {
        $s = new Student();
        $s->parent_phone = $parentPhone;
        $s->Phone = $phone;

        return $s;
    }

    public function test_prefers_parent_phone_when_both_present(): void
    {
        $s = $this->student('0911222333', '0999888777');
        $this->assertSame('0911222333', StudentContactPhone::forStudent($s));
    }

    public function test_falls_back_to_legacy_phone_when_parent_phone_empty(): void
    {
        $this->assertSame('0999888777', StudentContactPhone::forStudent($this->student('', '0999888777')));
        $this->assertSame('0999888777', StudentContactPhone::forStudent($this->student(null, '0999888777')));
        $this->assertSame('0999888777', StudentContactPhone::forStudent($this->student('   ', '0999888777')));
    }

    public function test_returns_empty_when_no_contact_phone_at_all(): void
    {
        $this->assertSame('', StudentContactPhone::forStudent($this->student(null, null)));
        $this->assertSame('', StudentContactPhone::forStudent($this->student('', '')));
    }

    public function test_normalized_digits_strips_formatting(): void
    {
        $s = $this->student('0912-345-678', null);
        $this->assertSame('0912345678', StudentContactPhone::normalizedDigits($s));

        $spaced = $this->student('+886 912 345 678', null);
        $this->assertSame('886912345678', StudentContactPhone::normalizedDigits($spaced));
    }

    public function test_match_is_format_insensitive_on_parent_phone(): void
    {
        $s = $this->student('0912-345-678', '');
        $this->assertTrue(StudentContactPhone::matchesNormalizedInput($s, '0912345678'));
    }

    public function test_match_uses_parent_phone_not_legacy_phone_when_both_set(): void
    {
        // parent_phone is the truth; the legacy Phone value must NOT match.
        $s = $this->student('0911222333', '0999888777');
        $this->assertTrue(StudentContactPhone::matchesNormalizedInput($s, '0911222333'));
        $this->assertFalse(
            StudentContactPhone::matchesNormalizedInput($s, '0999888777'),
            'Legacy Phone must not match once parent_phone overrides it (§R10).'
        );
    }

    public function test_match_returns_false_for_empty_stored_or_empty_input(): void
    {
        $this->assertFalse(StudentContactPhone::matchesNormalizedInput($this->student(null, null), '0912345678'));
        $this->assertFalse(StudentContactPhone::matchesNormalizedInput($this->student('0912345678', null), ''));
    }
}
