<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * #4100: super-admin link from ONE open bug report to ONE course contract (triage billing snapshot).
 *
 * @property int $id
 * @property int $bug_report_id
 * @property int $student_class_id
 * @property int $created_by
 * @property \Carbon\CarbonInterface|null $created_at
 * @property \Carbon\CarbonInterface|null $revoked_at
 * @property int|null $revoked_by
 * @property bool|null $active 1 while live, NULL once revoked (unique per report)
 */
class BugReportContractLink extends Model
{
    protected $table = 'bug_report_contract_links';

    public $timestamps = false;

    protected $fillable = ['bug_report_id', 'student_class_id', 'created_by', 'created_at', 'revoked_at', 'revoked_by', 'active'];

    protected $casts = ['created_at' => 'datetime', 'revoked_at' => 'datetime'];

    public const OPEN_REPORT_STATUSES = ['new', 'triaged', 'in_progress'];

    /** Campus of the contract's student; null when the contract or its Student row is missing or the campus is unset (fail closed). */
    public static function contractCampusId(int $studentClassId): ?int
    {
        $campusId = \Illuminate\Support\Facades\DB::table('StudentClass')
            ->join('Student', 'Student.id', '=', 'StudentClass.StudentID')
            ->where('StudentClass.ID', $studentClassId)
            ->value('Student.CampusID');

        return $campusId !== null && (int) $campusId > 0 ? (int) $campusId : null;
    }

    /** @param Builder<BugReportContractLink> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('active', true);
    }
}
