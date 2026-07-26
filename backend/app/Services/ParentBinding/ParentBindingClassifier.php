<?php

namespace App\Services\ParentBinding;

use App\Enums\ParentBindingReasonCode;
use App\Models\Student;
use App\Support\StudentContactPhone;
use Illuminate\Support\Collection;

/**
 * Shared LINE + Parent Portal match → reason_code rules (PB-00).
 * Controllers must not branch on Chinese copy for internal outcomes.
 */
final class ParentBindingClassifier
{
    /**
     * LINE bind-by-name failure/success classification from campus-scoped name candidates.
     *
     * @param  Collection<int, Student>  $nameCandidates
     */
    public function classifyLineNameCandidates(
        Collection $nameCandidates,
        string $normalizedPhone,
        int $campusId,
        ?callable $isAlreadyBound = null,
    ): ParentBindingClassification {
        if ($normalizedPhone === '') {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::InvalidInput,
                $campusId,
                null,
                $nameCandidates->count(),
                0,
            );
        }

        $count = $nameCandidates->count();
        if ($count === 0) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::StudentNotFound,
                $campusId,
                null,
                0,
                0,
            );
        }

        $withContact = $nameCandidates->filter(
            fn (Student $s) => StudentContactPhone::normalizedDigits($s) !== ''
        );
        if ($withContact->isEmpty()) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::ContactPhoneMissing,
                $campusId,
                null,
                $count,
                0,
            );
        }

        $phoneMatches = $nameCandidates->filter(
            fn (Student $s) => StudentContactPhone::matchesNormalizedInput($s, $normalizedPhone)
        )->values();
        $matchCount = $phoneMatches->count();

        if ($matchCount === 0) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::PhoneMismatch,
                $campusId,
                null,
                $count,
                0,
            );
        }

        /** @var Student $student */
        $student = $phoneMatches->first();
        if ($isAlreadyBound && $isAlreadyBound((int) $student->id)) {
            return ParentBindingClassification::noop(
                ParentBindingReasonCode::AlreadyBound,
                $campusId,
                (int) $student->id,
                $count,
                $matchCount,
            );
        }

        // Faithful observation: first-match bind may have matchCount > 1.
        return ParentBindingClassification::success(
            $campusId,
            (int) $student->id,
            $count,
            $matchCount,
        );
    }

    public function classifyLineStudentId(
        ?Student $student,
        string $normalizedPhone,
        int $campusId,
        ?callable $isAlreadyBound = null,
    ): ParentBindingClassification {
        if (!$student) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::StudentNotFound,
                $campusId,
                null,
                0,
                0,
            );
        }

        // Campus filter is applied by caller; defensive campus mismatch.
        if ((int) $student->CampusID !== $campusId) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::CampusMismatch,
                $campusId,
                (int) $student->id,
                1,
                0,
            );
        }

        if ($normalizedPhone === '') {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::InvalidInput,
                $campusId,
                (int) $student->id,
                1,
                0,
            );
        }

        $stored = StudentContactPhone::normalizedDigits($student);
        if ($stored === '') {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::ContactPhoneMissing,
                $campusId,
                (int) $student->id,
                1,
                0,
            );
        }

        if ($stored !== $normalizedPhone) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::PhoneMismatch,
                $campusId,
                (int) $student->id,
                1,
                0,
            );
        }

        if ($isAlreadyBound && $isAlreadyBound((int) $student->id)) {
            return ParentBindingClassification::noop(
                ParentBindingReasonCode::AlreadyBound,
                $campusId,
                (int) $student->id,
                1,
                1,
            );
        }

        return ParentBindingClassification::success(
            $campusId,
            (int) $student->id,
            1,
            1,
        );
    }

    /**
     * Parent Portal StudentID + Phone branch (after validation / phone normalize).
     */
    public function classifyPortalStudentId(
        ?Student $candidate,
        string $normalizedPhone,
        string $rawName,
    ): ParentBindingClassification {
        if (!$candidate) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::StudentNotFound,
                null,
                null,
                0,
                0,
            );
        }

        $campusId = (int) ($candidate->CampusID ?? 0) ?: null;
        $contact = trim(StudentContactPhone::forStudent($candidate));
        if ($contact === '') {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::ContactPhoneMissing,
                $campusId,
                (int) $candidate->id,
                1,
                0,
            );
        }

        $stored = StudentContactPhone::normalizedDigits($candidate);
        if ($stored !== $normalizedPhone) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::PhoneMismatch,
                $campusId,
                (int) $candidate->id,
                1,
                0,
            );
        }

        if ($rawName !== '' && trim((string) $candidate->name) !== $rawName) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::StudentNotFound,
                $campusId,
                (int) $candidate->id,
                1,
                1,
            );
        }

        return ParentBindingClassification::success(
            $campusId,
            (int) $candidate->id,
            1,
            1,
        );
    }

    /**
     * Parent Portal Name + Phone branch.
     *
     * @param  Collection<int, Student>  $allByName
     */
    public function classifyPortalName(
        Collection $allByName,
        string $normalizedPhone,
    ): ParentBindingClassification {
        if ($allByName->isEmpty()) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::StudentNotFound,
                null,
                null,
                0,
                0,
            );
        }

        $phoneMatches = $allByName->filter(function (Student $s) use ($normalizedPhone) {
            $contact = trim(StudentContactPhone::forStudent($s));
            if ($contact === '') {
                return false;
            }

            return StudentContactPhone::normalizedDigits($s) === $normalizedPhone;
        })->values();

        if ($phoneMatches->count() > 1) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::AmbiguousMatch,
                (int) ($phoneMatches->first()->CampusID ?? 0) ?: null,
                null,
                $allByName->count(),
                $phoneMatches->count(),
            );
        }

        if ($phoneMatches->count() === 1) {
            $student = $phoneMatches->first();

            return ParentBindingClassification::success(
                (int) ($student->CampusID ?? 0) ?: null,
                (int) $student->id,
                $allByName->count(),
                1,
            );
        }

        // Zero phone matches: empty contact among name hits vs mismatch.
        $hasEmptyContact = $allByName->contains(
            fn (Student $s) => trim(StudentContactPhone::forStudent($s)) === ''
        );
        if ($hasEmptyContact) {
            return ParentBindingClassification::failure(
                ParentBindingReasonCode::ContactPhoneMissing,
                (int) ($allByName->first()->CampusID ?? 0) ?: null,
                null,
                $allByName->count(),
                0,
            );
        }

        return ParentBindingClassification::failure(
            ParentBindingReasonCode::PhoneMismatch,
            (int) ($allByName->first()->CampusID ?? 0) ?: null,
            null,
            $allByName->count(),
            0,
        );
    }

    public function invalidInput(?int $campusId = null): ParentBindingClassification
    {
        return ParentBindingClassification::failure(
            ParentBindingReasonCode::InvalidInput,
            $campusId,
        );
    }

    public function internalError(?int $campusId = null): ParentBindingClassification
    {
        return ParentBindingClassification::failure(
            ParentBindingReasonCode::InternalError,
            $campusId,
        );
    }
}
