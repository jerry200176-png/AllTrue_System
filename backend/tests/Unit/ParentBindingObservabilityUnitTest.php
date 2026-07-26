<?php

namespace Tests\Unit;

use App\Models\Campus;
use App\Models\Student;
use App\Services\ParentBinding\ParentBindingClassifier;
use App\Support\ParentBinding\ParentBindingCodes;
use App\Support\ParentBinding\ParentBindingCorrelationId;
use App\Support\ParentBinding\ParentBindingPhonePrivacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

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

    public function test_contract_classifier_privacy(): void
    {
        $this->assertSame([
            'STUDENT_NOT_FOUND', 'CONTACT_PHONE_MISSING', 'PHONE_MISMATCH', 'AMBIGUOUS_MATCH',
            'CAMPUS_MISMATCH', 'ALREADY_BOUND', 'INVALID_INPUT', 'AUTHORIZATION_DENIED', 'INTERNAL_ERROR',
        ], ParentBindingCodes::pb00Reasons());
        $this->assertNotSame('bad', ParentBindingCorrelationId::fromRequest('bad'));
        config(['parent_binding.phone_fingerprint_key' => 'k']);
        $this->assertSame(hash_hmac('sha256', 'parent-binding-phone-v1|0912345678', 'k'), ParentBindingPhonePrivacy::fingerprint('0912345678'));

        $c = Campus::factory()->create();
        $clf = new ParentBindingClassifier();
        $this->assertSame(ParentBindingCodes::INVALID_INPUT, $clf->classifyLineNameCandidates(collect(), '', (int) $c->id)->reasonCode);
        $this->assertSame(ParentBindingCodes::STUDENT_NOT_FOUND, $clf->classifyLineNameCandidates(collect(), '0912345678', (int) $c->id)->reasonCode);
        $empty = $this->student($c, '空', '', '');
        $this->assertSame(ParentBindingCodes::CONTACT_PHONE_MISSING, $clf->classifyLineNameCandidates(collect([$empty]), '0912345678', (int) $c->id)->reasonCode);
        $wrong = $this->student($c, '錯', null, '0987654321');
        $this->assertSame(ParentBindingCodes::PHONE_MISMATCH, $clf->classifyLineNameCandidates(collect([$wrong]), '0912345678', (int) $c->id)->reasonCode);
        $ok = $this->student($c, '好', null, '0912345678');
        $noop = $clf->classifyLineNameCandidates(collect([$ok]), '0912345678', (int) $c->id, fn () => true);
        $this->assertSame([ParentBindingCodes::OUTCOME_NOOP, ParentBindingCodes::ALREADY_BOUND], [$noop->outcome, $noop->reasonCode]);
        $a = $this->student($c, '同', null, '0912345678');
        $b = $this->student($c, '同', null, '0912345678');
        $multi = $clf->classifyLineNameCandidates(collect([$a, $b]), '0912345678', (int) $c->id, fn () => false);
        $this->assertSame([ParentBindingCodes::OUTCOME_SUCCESS, 2], [$multi->outcome, $multi->phoneMatchCount]);
        $this->assertSame(ParentBindingCodes::CONTACT_PHONE_MISSING, $clf->classifyLineStudentId($empty, '0912345678', (int) $c->id)->reasonCode);
        $this->assertSame(ParentBindingCodes::AMBIGUOUS_MATCH, $clf->classifyPortalName(collect([$a, $b]), '0912345678')->reasonCode);
        $this->assertSame(ParentBindingCodes::OUTCOME_SUCCESS, $clf->classifyPortalStudentId($ok, '0912345678', '')->outcome);
    }
}
