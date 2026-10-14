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

    /**
     * Conditional soft revoke: only a still-live row is touched, so two concurrent revokes cannot both
     * succeed and the loser writes no audit row. Returns true when THIS call revoked it.
     */
    public static function revoke(int $linkId, ?int $actorId, string $reason): bool
    {
        $link = self::query()->whereKey($linkId)->first(['id', 'bug_report_id', 'student_class_id']);
        $affected = $link === null ? 0 : self::query()->whereKey($linkId)->whereNull('revoked_at')->where('active', true)
            ->update(['revoked_at' => now(), 'revoked_by' => $actorId, 'active' => null]);
        if ($affected !== 1 || $link === null) {
            return false;
        }
        SecurityAuditEvent::append('triage_contract_link.revoked', 'success', [
            'actor_type' => $actorId === null ? 'system' : 'user',
            'actor_id' => $actorId,
            'subject_type' => 'student_class',
            'subject_id' => (int) $link->student_class_id,
        ], [
            'bug_report_ref' => SecurityAuditEvent::ref('bug_report', (int) $link->bug_report_id),
            'reason_code' => $reason,
            'client_ip_ref' => SecurityAuditEvent::ref('ip', app()->bound('request') ? request()->ip() : null),
        ]);

        return true;
    }

    /** #4100: a resolved/closed report loses its links for good; reopening does NOT restore access (re-link needed). */
    public static function revokeAllForReport(int $bugReportId, ?int $actorId): void
    {
        // Rolling deploy: before the migration runs there can be no links, and closing a report must not 500.
        if (!\Illuminate\Support\Facades\Schema::hasTable('bug_report_contract_links')) {
            return;
        }
        foreach (self::query()->active()->where('bug_report_id', $bugReportId)->pluck('id') as $id) {
            self::revoke((int) $id, $actorId, 'report_closed');
        }
    }

    /** @param Builder<BugReportContractLink> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('active', true);
    }
}
