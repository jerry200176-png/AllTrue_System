<?php

namespace Tests\Unit;

use App\Enums\ParentBindingOutcome;
use App\Enums\ParentBindingReasonCode;
use App\Support\ParentBinding\ParentBindingCorrelationId;
use App\Support\ParentBinding\ParentBindingPhonePrivacy;
use Tests\TestCase;

class ParentBindingReasonCodeContractTest extends TestCase
{
    public function test_adr_pb00_reason_codes_are_stable_strings(): void
    {
        $expected = [
            'STUDENT_NOT_FOUND',
            'CONTACT_PHONE_MISSING',
            'PHONE_MISMATCH',
            'AMBIGUOUS_MATCH',
            'CAMPUS_MISMATCH',
            'ALREADY_BOUND',
            'INVALID_INPUT',
            'AUTHORIZATION_DENIED',
            'INTERNAL_ERROR',
        ];

        $actual = array_map(
            fn (ParentBindingReasonCode $c) => $c->value,
            ParentBindingReasonCode::pb00Legacy()
        );

        $this->assertSame($expected, $actual);
    }

    public function test_outcomes_are_stable(): void
    {
        $this->assertSame('success', ParentBindingOutcome::Success->value);
        $this->assertSame('failure', ParentBindingOutcome::Failure->value);
        $this->assertSame('noop', ParentBindingOutcome::Noop->value);
    }

    public function test_correlation_id_rejects_untrusted_inbound(): void
    {
        $generated = ParentBindingCorrelationId::fromRequest('not-a-uuid');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $generated
        );

        $accepted = ParentBindingCorrelationId::fromRequest('550e8400-e29b-41d4-a716-446655440000');
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', $accepted);
    }

    public function test_phone_fingerprint_is_keyed_hmac_not_raw_sha256(): void
    {
        config([
            'parent_binding.phone_fingerprint_key' => 'unit-test-hmac-key',
        ]);

        $fp = ParentBindingPhonePrivacy::fingerprint('0912345678');
        $this->assertNotNull($fp);
        $this->assertSame(64, strlen($fp));
        $this->assertNotSame(hash('sha256', '0912345678'), $fp);
        $this->assertSame(
            hash_hmac('sha256', 'parent-binding-phone-v1|0912345678', 'unit-test-hmac-key'),
            $fp
        );
    }

    public function test_phone_privacy_helpers_do_not_echo_raw_values(): void
    {
        $mask = ParentBindingPhonePrivacy::mask('0912-345-678');
        $this->assertNotNull($mask);
        $this->assertStringNotContainsString('0912345678', $mask);
        $this->assertStringNotContainsString('0912-345-678', $mask);
    }
}
