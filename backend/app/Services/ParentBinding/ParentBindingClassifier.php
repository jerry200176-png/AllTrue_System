<?php

namespace App\Services\ParentBinding;

use App\Models\Student;
use App\Support\ParentBinding\ParentBindingCodes;
use App\Support\StudentContactPhone;
use Illuminate\Support\Collection;

/** Shared LINE + Portal match → reason_code rules (PB-00). */
final class ParentBindingClassifier
{
    /** @param Collection<int, Student> $nameCandidates */
    public function classifyLineNameCandidates(Collection $nameCandidates, string $normalizedPhone, int $campusId, ?callable $isAlreadyBound = null): ParentBindingClassification
    {
        $n = $nameCandidates->count();
        if ($normalizedPhone === '') {
            return ParentBindingClassification::failure(ParentBindingCodes::INVALID_INPUT, $campusId, null, $n, 0);
        }
        if ($n === 0) {
            return ParentBindingClassification::failure(ParentBindingCodes::STUDENT_NOT_FOUND, $campusId, null, 0, 0);
        }
        if ($nameCandidates->every(fn (Student $s) => StudentContactPhone::normalizedDigits($s) === '')) {
            return ParentBindingClassification::failure(ParentBindingCodes::CONTACT_PHONE_MISSING, $campusId, null, $n, 0);
        }
        $matches = $nameCandidates->filter(fn (Student $s) => StudentContactPhone::matchesNormalizedInput($s, $normalizedPhone))->values();
        if ($matches->isEmpty()) {
            return ParentBindingClassification::failure(ParentBindingCodes::PHONE_MISMATCH, $campusId, null, $n, 0);
        }
        $student = $matches->first();
        if ($isAlreadyBound && $isAlreadyBound((int) $student->id)) {
            return ParentBindingClassification::noop(ParentBindingCodes::ALREADY_BOUND, $campusId, (int) $student->id, $n, $matches->count());
        }

        return ParentBindingClassification::success($campusId, (int) $student->id, $n, $matches->count());
    }

    public function classifyLineStudentId(?Student $student, string $normalizedPhone, int $campusId, ?callable $isAlreadyBound = null): ParentBindingClassification
    {
        if (!$student) {
            return ParentBindingClassification::failure(ParentBindingCodes::STUDENT_NOT_FOUND, $campusId);
        }
        if ((int) $student->CampusID !== $campusId) {
            return ParentBindingClassification::failure(ParentBindingCodes::CAMPUS_MISMATCH, $campusId, (int) $student->id, 1, 0);
        }
        if ($normalizedPhone === '') {
            return ParentBindingClassification::failure(ParentBindingCodes::INVALID_INPUT, $campusId, (int) $student->id, 1, 0);
        }
        $stored = StudentContactPhone::normalizedDigits($student);
        if ($stored === '') {
            return ParentBindingClassification::failure(ParentBindingCodes::CONTACT_PHONE_MISSING, $campusId, (int) $student->id, 1, 0);
        }
        if ($stored !== $normalizedPhone) {
            return ParentBindingClassification::failure(ParentBindingCodes::PHONE_MISMATCH, $campusId, (int) $student->id, 1, 0);
        }
        if ($isAlreadyBound && $isAlreadyBound((int) $student->id)) {
            return ParentBindingClassification::noop(ParentBindingCodes::ALREADY_BOUND, $campusId, (int) $student->id, 1, 1);
        }

        return ParentBindingClassification::success($campusId, (int) $student->id, 1, 1);
    }

    public function classifyPortalStudentId(?Student $candidate, string $normalizedPhone, string $rawName): ParentBindingClassification
    {
        if (!$candidate) {
            return ParentBindingClassification::failure(ParentBindingCodes::STUDENT_NOT_FOUND);
        }
        $campusId = (int) ($candidate->CampusID ?? 0) ?: null;
        if (trim(StudentContactPhone::forStudent($candidate)) === '') {
            return ParentBindingClassification::failure(ParentBindingCodes::CONTACT_PHONE_MISSING, $campusId, (int) $candidate->id, 1, 0);
        }
        if (StudentContactPhone::normalizedDigits($candidate) !== $normalizedPhone) {
            return ParentBindingClassification::failure(ParentBindingCodes::PHONE_MISMATCH, $campusId, (int) $candidate->id, 1, 0);
        }
        if ($rawName !== '' && trim((string) $candidate->name) !== $rawName) {
            return ParentBindingClassification::failure(ParentBindingCodes::STUDENT_NOT_FOUND, $campusId, (int) $candidate->id, 1, 1);
        }

        return ParentBindingClassification::success($campusId, (int) $candidate->id, 1, 1);
    }

    /** @param Collection<int, Student> $allByName */
    public function classifyPortalName(Collection $allByName, string $normalizedPhone): ParentBindingClassification
    {
        if ($allByName->isEmpty()) {
            return ParentBindingClassification::failure(ParentBindingCodes::STUDENT_NOT_FOUND);
        }
        $matches = $allByName->filter(fn (Student $s) => trim(StudentContactPhone::forStudent($s)) !== ''
            && StudentContactPhone::normalizedDigits($s) === $normalizedPhone)->values();
        $hint = (int) ($allByName->first()->CampusID ?? 0) ?: null;
        if ($matches->count() > 1) {
            return ParentBindingClassification::failure(ParentBindingCodes::AMBIGUOUS_MATCH, $hint, null, $allByName->count(), $matches->count());
        }
        if ($matches->count() === 1) {
            $s = $matches->first();

            return ParentBindingClassification::success((int) ($s->CampusID ?? 0) ?: null, (int) $s->id, $allByName->count(), 1);
        }
        if ($allByName->contains(fn (Student $s) => trim(StudentContactPhone::forStudent($s)) === '')) {
            return ParentBindingClassification::failure(ParentBindingCodes::CONTACT_PHONE_MISSING, $hint, null, $allByName->count(), 0);
        }

        return ParentBindingClassification::failure(ParentBindingCodes::PHONE_MISMATCH, $hint, null, $allByName->count(), 0);
    }

    public function invalidInput(?int $campusId = null): ParentBindingClassification
    {
        return ParentBindingClassification::failure(ParentBindingCodes::INVALID_INPUT, $campusId);
    }
}
