<?php

namespace App\Services\Billing;

use App\Models\CoursePackage;
use App\Models\StudentClass;

/**
 * The Paid marks a shared package keeps on its owner's courses, set when its bill is paid (confirm) and dropped when that
 * money is taken back (void, legacy-flag clear, and the receipt move of #5113). One place, so every money path marks and
 * clears the same rows. Callers hold the course and package locks.
 */
final class PackagePaidMarks
{
    /**
     * The course plus its same-student package siblings, ascending (the rows {@see mark()} flags).
     *
     * @return list<int>
     */
    public static function courseIds(StudentClass $course, bool $lock = false): array
    {
        if (!$course->isPartOfPackage()) {
            return [(int) $course->getKey()];
        }

        return StudentClass::query()->where('PackageID', $course->PackageID)->where('StudentID', $course->StudentID)->orderBy('ID')
            ->when($lock, fn ($q) => $q->lockForUpdate())->pluck('ID')->map(fn ($id) => (int) $id)->all();
    }

    /** The legacy mark off (void()/撤銷收款 semantics): the course and, for a package, its paid mark and every member's. @return int courses changed */
    public static function drop(StudentClass $course, ?CoursePackage $package): int
    {
        $members = StudentClass::query()->whereIn('ID', self::courseIds($course))->get();
        foreach ($members as $member) {
            $member->update(['Paid' => 0, 'Pay' => 0, 'PayDate' => null]
                + ($member->getAttribute('closed_reason') === 'settled' ? ['closed_reason' => 'settled_pending'] : []));
        }
        $package?->refresh()->forceFill(['paid' => false, 'paid_at' => null])->save();

        return $members->count();
    }

    public static function mark(CoursePackage $package, string $paidAt): void
    {
        $package->paid = true;
        $package->paid_at = $paidAt;
        $package->save();

        // Only the package owner's own courses: a stale PackageID on another student's course is not this payment's to mark.
        StudentClass::where('PackageID', $package->id)->where('StudentID', $package->student_id)->update([
            'Paid' => 1,
            'PayDate' => $paidAt,
        ]);
    }
}
