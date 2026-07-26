<?php

namespace Tests\Unit;

use App\Enums\ParentBindingOutcome;
use App\Enums\ParentBindingReasonCode;
use App\Models\Campus;
use App\Models\Student;
use App\Services\ParentBinding\ParentBindingClassifier;
use App\Support\ParentBinding\ParentBindingCorrelationId;
use App\Support\ParentBinding\ParentBindingPhonePrivacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PB-00 (#1436): reason contract + classifier + privacy helpers. */
class ParentBindingObservabilityUnitTest extends TestCase
{
    use RefreshDatabase;

    private function student(Campus $c, string $name, ?string $phone, ?string $parent = null): Student
    {
        return Student::create([
            'name' => $name, 'CampusID' => $c->id, 'ClassID' => 1, 'enable' => 1,
            'MDT' => now(), 'Notify_Token' => '', 'Phone' => $phone, 'parent_phone' => $parent,
        ]);
    }

    public function test_reason_codes_and_outcomes_stable(): void
    {
        $this->assertSame([
            'STUDENT_NOT_FOUND', 'CONTACT_PHONE_MISSING', 'PHONE_MISMATCH', 'AMBIGUOUS_MATCH',
            'CAMPUS_MISMATCH', 'ALREADY_BOUND', 'INVALID_INPUT', 'AUTHORIZATION_DENIED', 'INTERNAL_ERROR',
        ], array_map(fn ($c) => $c->value, ParentBindingReasonCode::pb00Legacy()));
        $this->assertSame(['success', 'failure', 'noop'], array_map(fn ($o) => $o->value, ParentBindingOutcome::cases()));
    }

    public function test_correlation_and_fingerprint(): void
    {
        $this->assertNotSame('bad', ParentBindingCorrelationId::fromRequest('bad'));
        $this->assertSame('550e8400-e29b-41d4-a716-446655440000', ParentBindingCorrelationId::fromRequest('550e8400-e29b-41d4-a716-446655440000'));
        config(['parent_binding.phone_fingerprint_key' => 'k']);
        $fp = ParentBindingPhonePrivacy::fingerprint('0912345678');
        $this->assertSame(hash_hmac('sha256', 'parent-binding-phone-v1|0912345678', 'k'), $fp);
        $this->assertNotSame(hash('sha256', '0912345678'), $fp);
        $mask = ParentBindingPhonePrivacy::mask('0912-345-678');
        $this->assertStringNotContainsString('0912345678', (string) $mask);
    }

    public function test_classifier_line_and_portal_branches(): void
    {
        $c = Campus::factory()->create();
        $clf = new ParentBindingClassifier();
        $this->assertSame(ParentBindingReasonCode::InvalidInput, $clf->classifyLineNameCandidates(collect(), '', (int) $c->id)->reasonCode);
        $this->assertSame(ParentBindingReasonCode::StudentNotFound, $clf->classifyLineNameCandidates(collect(), '0912345678', (int) $c->id)->reasonCode);
        $empty = $this->student($c, '空', '', '');
        $this->assertSame(ParentBindingReasonCode::ContactPhoneMissing, $clf->classifyLineNameCandidates(collect([$empty]), '0912345678', (int) $c->id)->reasonCode);
        $wrong = $this->student($c, '錯', null, '0987654321');
        $this->assertSame(ParentBindingReasonCode::PhoneMismatch, $clf->classifyLineNameCandidates(collect([$wrong]), '0912345678', (int) $c->id)->reasonCode);
        $ok = $this->student($c, '好', null, '0912345678');
        $noop = $clf->classifyLineNameCandidates(collect([$ok]), '0912345678', (int) $c->id, fn () => true);
        $this->assertSame(ParentBindingOutcome::Noop, $noop->outcome);
        $this->assertSame(ParentBindingReasonCode::AlreadyBound, $noop->reasonCode);
        $a = $this->student($c, '同', null, '0912345678');
        $b = $this->student($c, '同', null, '0912345678');
        $multi = $clf->classifyLineNameCandidates(collect([$a, $b]), '0912345678', (int) $c->id, fn () => false);
        $this->assertSame(ParentBindingOutcome::Success, $multi->outcome);
        $this->assertSame(2, $multi->phoneMatchCount);
        $this->assertSame(ParentBindingReasonCode::ContactPhoneMissing, $clf->classifyLineStudentId($empty, '0912345678', (int) $c->id)->reasonCode);
        $this->assertSame(ParentBindingReasonCode::PhoneMismatch, $clf->classifyLineStudentId($wrong, '0912345678', (int) $c->id)->reasonCode);
        $this->assertSame(ParentBindingReasonCode::StudentNotFound, $clf->classifyLineStudentId(null, '0912345678', (int) $c->id)->reasonCode);
        $this->assertSame(ParentBindingReasonCode::AmbiguousMatch, $clf->classifyPortalName(collect([$a, $b]), '0912345678')->reasonCode);
        $this->assertSame(ParentBindingReasonCode::ContactPhoneMissing, $clf->classifyPortalName(collect([$empty]), '0912345678')->reasonCode);
        $this->assertSame(ParentBindingOutcome::Success, $clf->classifyPortalStudentId($ok, '0912345678', '')->outcome);
        $this->assertSame(ParentBindingReasonCode::PhoneMismatch, $clf->classifyPortalStudentId($ok, '0987654321', '')->reasonCode);
    }
}
