<?php

namespace Tests\Unit;

use App\Enums\ParentBindingOutcome;
use App\Enums\ParentBindingReasonCode;
use App\Models\Campus;
use App\Models\Student;
use App\Services\ParentBinding\ParentBindingClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentBindingClassifierTest extends TestCase
{
    use RefreshDatabase;

    private ParentBindingClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new ParentBindingClassifier();
    }

    private function makeStudent(Campus $campus, string $name, ?string $phone, ?string $parentPhone = null): Student
    {
        return Student::create([
            'name' => $name,
            'CampusID' => $campus->id,
            'ClassID' => 1,
            'enable' => 1,
            'MDT' => now(),
            'Notify_Token' => '',
            'Phone' => $phone,
            'parent_phone' => $parentPhone,
        ]);
    }

    public function test_line_name_empty_phone_is_invalid_input(): void
    {
        $campus = Campus::factory()->create();
        $result = $this->classifier->classifyLineNameCandidates(collect(), '', (int) $campus->id);
        $this->assertSame(ParentBindingOutcome::Failure, $result->outcome);
        $this->assertSame(ParentBindingReasonCode::InvalidInput, $result->reasonCode);
    }

    public function test_line_name_zero_candidates_is_student_not_found(): void
    {
        $campus = Campus::factory()->create();
        $result = $this->classifier->classifyLineNameCandidates(collect(), '0912345678', (int) $campus->id);
        $this->assertSame(ParentBindingReasonCode::StudentNotFound, $result->reasonCode);
    }

    public function test_line_name_all_empty_contact_is_contact_phone_missing(): void
    {
        $campus = Campus::factory()->create();
        $s = $this->makeStudent($campus, '無名手機', '', '');
        $result = $this->classifier->classifyLineNameCandidates(collect([$s]), '0912345678', (int) $campus->id);
        $this->assertSame(ParentBindingReasonCode::ContactPhoneMissing, $result->reasonCode);
    }

    public function test_line_name_phone_mismatch(): void
    {
        $campus = Campus::factory()->create();
        $s = $this->makeStudent($campus, '手機不符', null, '0987654321');
        $result = $this->classifier->classifyLineNameCandidates(collect([$s]), '0912345678', (int) $campus->id);
        $this->assertSame(ParentBindingReasonCode::PhoneMismatch, $result->reasonCode);
    }

    public function test_line_name_already_bound_is_noop(): void
    {
        $campus = Campus::factory()->create();
        $s = $this->makeStudent($campus, '已綁定', null, '0912345678');
        $result = $this->classifier->classifyLineNameCandidates(
            collect([$s]),
            '0912345678',
            (int) $campus->id,
            fn () => true,
        );
        $this->assertSame(ParentBindingOutcome::Noop, $result->outcome);
        $this->assertSame(ParentBindingReasonCode::AlreadyBound, $result->reasonCode);
    }

    public function test_line_name_multiple_phone_matches_still_success_but_reports_count(): void
    {
        $campus = Campus::factory()->create();
        $a = $this->makeStudent($campus, '同名甲', null, '0912345678');
        $b = $this->makeStudent($campus, '同名甲', null, '0912345678');
        // Candidates queried by name in controller; here both share phone.
        $a->name = '同名';
        $b->name = '同名';
        $result = $this->classifier->classifyLineNameCandidates(
            collect([$a, $b]),
            '0912345678',
            (int) $campus->id,
            fn () => false,
        );
        $this->assertSame(ParentBindingOutcome::Success, $result->outcome);
        $this->assertSame(2, $result->phoneMatchCount);
        $this->assertSame((int) $a->id, $result->studentId);
    }

    public function test_line_id_missing_contact_vs_mismatch(): void
    {
        $campus = Campus::factory()->create();
        $empty = $this->makeStudent($campus, '空手機', '', '');
        $wrong = $this->makeStudent($campus, '錯手機', null, '0987654321');

        $r1 = $this->classifier->classifyLineStudentId($empty, '0912345678', (int) $campus->id);
        $this->assertSame(ParentBindingReasonCode::ContactPhoneMissing, $r1->reasonCode);

        $r2 = $this->classifier->classifyLineStudentId($wrong, '0912345678', (int) $campus->id);
        $this->assertSame(ParentBindingReasonCode::PhoneMismatch, $r2->reasonCode);

        $r3 = $this->classifier->classifyLineStudentId(null, '0912345678', (int) $campus->id);
        $this->assertSame(ParentBindingReasonCode::StudentNotFound, $r3->reasonCode);
    }

    public function test_portal_name_ambiguous_and_missing_contact(): void
    {
        $campus = Campus::factory()->create();
        $a = $this->makeStudent($campus, '同名生', null, '0912345678');
        $b = $this->makeStudent($campus, '同名生', null, '0912345678');
        $ambiguous = $this->classifier->classifyPortalName(collect([$a, $b]), '0912345678');
        $this->assertSame(ParentBindingReasonCode::AmbiguousMatch, $ambiguous->reasonCode);

        $empty = $this->makeStudent($campus, '缺手機', '', '');
        $missing = $this->classifier->classifyPortalName(collect([$empty]), '0912345678');
        $this->assertSame(ParentBindingReasonCode::ContactPhoneMissing, $missing->reasonCode);
    }

    public function test_portal_student_id_branches(): void
    {
        $campus = Campus::factory()->create();
        $s = $this->makeStudent($campus, '代號生', null, '0912345678');

        $ok = $this->classifier->classifyPortalStudentId($s, '0912345678', '');
        $this->assertSame(ParentBindingOutcome::Success, $ok->outcome);

        $mismatch = $this->classifier->classifyPortalStudentId($s, '0987654321', '');
        $this->assertSame(ParentBindingReasonCode::PhoneMismatch, $mismatch->reasonCode);

        $notFound = $this->classifier->classifyPortalStudentId(null, '0912345678', '');
        $this->assertSame(ParentBindingReasonCode::StudentNotFound, $notFound->reasonCode);
    }
}
