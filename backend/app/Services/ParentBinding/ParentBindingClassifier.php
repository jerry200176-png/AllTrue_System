<?php

namespace App\Services\ParentBinding;

use App\Models\Student;
use App\Support\ParentBinding\ParentBindingCodes as C;
use App\Support\StudentContactPhone;
use Illuminate\Support\Collection;

/** Shared LINE + Portal match → reason_code (PB-00). */
final class ParentBindingClassifier
{
    private function sid(Student $s): int { return (int) $s->getAttribute('id'); }

    private function cid(Student $s): ?int
    {
        $c = (int) ($s->getAttribute('CampusID') ?? 0); return $c > 0 ? $c : null;
    }

    private function asStudent(mixed $s): Student
    {
        assert($s instanceof Student); return $s;
    }

    private function pack(string $outcome, ?string $reason, ?int $campusId, ?int $studentId, ?int $candidates = null, ?int $matches = null): array
    {
        return compact('outcome', 'campusId', 'studentId') + ['reasonCode' => $reason, 'candidateCount' => $candidates, 'phoneMatchCount' => $matches];
    }

    /** @param Collection<int, Student> $nameCandidates */
    public function classifyLineNameCandidates(Collection $nameCandidates, string $normalizedPhone, int $campusId, ?callable $isAlreadyBound = null): array
    {
        $n = $nameCandidates->count();
        if ($normalizedPhone === '') {
            return $this->pack(C::OUTCOME_FAILURE, C::INVALID_INPUT, $campusId, null, $n, 0);
        }
        if ($n === 0) {
            return $this->pack(C::OUTCOME_FAILURE, C::STUDENT_NOT_FOUND, $campusId, null, 0, 0);
        }
        if ($nameCandidates->every(fn (Student $s) => StudentContactPhone::normalizedDigits($s) === '')) {
            return $this->pack(C::OUTCOME_FAILURE, C::CONTACT_PHONE_MISSING, $campusId, null, $n, 0);
        }
        $matches = $nameCandidates->filter(fn (Student $s) => StudentContactPhone::matchesNormalizedInput($s, $normalizedPhone))->values();
        if ($matches->isEmpty()) {
            return $this->pack(C::OUTCOME_FAILURE, C::PHONE_MISMATCH, $campusId, null, $n, 0);
        }
        $student = $this->asStudent($matches->first());
        if ($isAlreadyBound && $isAlreadyBound($this->sid($student))) {
            return $this->pack(C::OUTCOME_NOOP, C::ALREADY_BOUND, $campusId, $this->sid($student), $n, $matches->count());
        }
        return $this->pack(C::OUTCOME_SUCCESS, null, $campusId, $this->sid($student), $n, $matches->count());
    }

    public function classifyLineStudentId(?Student $student, string $normalizedPhone, int $campusId, ?callable $isAlreadyBound = null): array
    {
        if (!$student) {
            return $this->pack(C::OUTCOME_FAILURE, C::STUDENT_NOT_FOUND, $campusId, null);
        }
        if ((int) ($student->getAttribute('CampusID') ?? 0) !== $campusId) {
            return $this->pack(C::OUTCOME_FAILURE, C::CAMPUS_MISMATCH, $campusId, $this->sid($student), 1, 0);
        }
        if ($normalizedPhone === '') {
            return $this->pack(C::OUTCOME_FAILURE, C::INVALID_INPUT, $campusId, $this->sid($student), 1, 0);
        }
        $stored = StudentContactPhone::normalizedDigits($student);
        if ($stored === '') {
            return $this->pack(C::OUTCOME_FAILURE, C::CONTACT_PHONE_MISSING, $campusId, $this->sid($student), 1, 0);
        }
        if ($stored !== $normalizedPhone) {
            return $this->pack(C::OUTCOME_FAILURE, C::PHONE_MISMATCH, $campusId, $this->sid($student), 1, 0);
        }
        if ($isAlreadyBound && $isAlreadyBound($this->sid($student))) {
            return $this->pack(C::OUTCOME_NOOP, C::ALREADY_BOUND, $campusId, $this->sid($student), 1, 1);
        }
        return $this->pack(C::OUTCOME_SUCCESS, null, $campusId, $this->sid($student), 1, 1);
    }

    public function classifyPortalStudentId(?Student $candidate, string $normalizedPhone, string $rawName): array
    {
        if (!$candidate) {
            return $this->pack(C::OUTCOME_FAILURE, C::STUDENT_NOT_FOUND, null, null);
        }
        $campusId = $this->cid($candidate);
        if (trim(StudentContactPhone::forStudent($candidate)) === '') {
            return $this->pack(C::OUTCOME_FAILURE, C::CONTACT_PHONE_MISSING, $campusId, $this->sid($candidate), 1, 0);
        }
        if (StudentContactPhone::normalizedDigits($candidate) !== $normalizedPhone) {
            return $this->pack(C::OUTCOME_FAILURE, C::PHONE_MISMATCH, $campusId, $this->sid($candidate), 1, 0);
        }
        if ($rawName !== '' && trim((string) ($candidate->getAttribute('name') ?? '')) !== $rawName) {
            return $this->pack(C::OUTCOME_FAILURE, C::STUDENT_NOT_FOUND, $campusId, $this->sid($candidate), 1, 1);
        }
        return $this->pack(C::OUTCOME_SUCCESS, null, $campusId, $this->sid($candidate), 1, 1);
    }

    /** @param Collection<int, Student> $allByName */
    public function classifyPortalName(Collection $allByName, string $normalizedPhone): array
    {
        if ($allByName->isEmpty()) {
            return $this->pack(C::OUTCOME_FAILURE, C::STUDENT_NOT_FOUND, null, null);
        }
        $matches = $allByName->filter(fn (Student $s) => trim(StudentContactPhone::forStudent($s)) !== ''
            && StudentContactPhone::normalizedDigits($s) === $normalizedPhone)->values();
        $hint = $this->cid($this->asStudent($allByName->first()));
        if ($matches->count() > 1) {
            return $this->pack(C::OUTCOME_FAILURE, C::AMBIGUOUS_MATCH, $hint, null, $allByName->count(), $matches->count());
        }
        if ($matches->count() === 1) {
            $s = $this->asStudent($matches->first());
            return $this->pack(C::OUTCOME_SUCCESS, null, $this->cid($s), $this->sid($s), $allByName->count(), 1);
        }
        if ($allByName->contains(fn (Student $s) => trim(StudentContactPhone::forStudent($s)) === '')) {
            return $this->pack(C::OUTCOME_FAILURE, C::CONTACT_PHONE_MISSING, $hint, null, $allByName->count(), 0);
        }
        return $this->pack(C::OUTCOME_FAILURE, C::PHONE_MISMATCH, $hint, null, $allByName->count(), 0);
    }

    public function invalidInput(?int $campusId = null): array
    {
        return $this->pack(C::OUTCOME_FAILURE, C::INVALID_INPUT, $campusId, null);
    }
}
