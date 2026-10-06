<?php

namespace App\Models;

use App\Services\Scheduling\DeductionBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property int|null $standard_lesson_minutes Minutes representing one standard billing
 *           unit for THIS course (RFC non-standard duration D1). Null for legacy /
 *           fixed-session courses; never falls back to a company-wide default.
 * @property string $deduction_basis One of DeductionBasis::all(); always reads as
 *           `fixed_session` when the column is null (see the accessor below).
 * @property array<string, mixed>|null $pricing_snapshot Immutable transaction pricing snapshot.
 * @property \App\Models\Student|null $student
 * @property \App\Models\CoursePackage|null $coursePackage
 * @property int|null $ID
 * @property int $StudentID
 * @property int $GradeID
 * @property int $SubjectID
 * @property int $TeacherID
 * @property int $by1
 * @property int $Period
 * @property string $StartDate
 * @property string|null $EndDate
 * @property int|null $week
 * @property string|null $time
 * @property int|null $week1
 * @property string|null $time1
 * @property int|null $week2
 * @property string|null $time2
 * @property int|null $week3
 * @property string|null $time3
 * @property int|null $week4
 * @property string|null $time4
 * @property int|null $week5
 * @property string|null $time5
 * @property int|null $week6
 * @property string|null $time6
 * @property int $TotalHours
 * @property string|null $Memo
 * @property int|null $Charge
 * @property int|null $Pay
 * @property string|null $PayDate
 * @property int $Paid
 * @property int|null $Disconunt
 * @property float|null $Rate
 * @property string $rate_unit
 * @property int|null $PackageID
 * @property int|null $PackageTotalSessions
 * @property string|null $PackageName
 * @property int|null $LearnTimeID
 * @property int|null $room_id
 * @property int|null $settlement_day
 * @property int|null $monthly_sessions
 * @property string $MDate
 * @property int $Stop
 * @property string|null $closed_reason
 * @property string $ScheduleMode
 * @property string $scheduling_policy
 * @property int|null $SessionCount
 * @property int|null $SessionDuration
 * @property int|null $PurchasedMinutes
 * @property int|null $RemainingMinutes
 * @property int|null $duration1
 * @property int|null $duration2
 * @property int|null $duration3
 * @property int|null $duration4
 * @property int|null $duration5
 * @property int|null $duration6
 * @property int|null $RemainingSessions
 * @property string $ClassType
 * @property int $UsedSessions
 * @property \Carbon\CarbonInterface|null $settlement_locked_at
 * @property string|null $settlement_snapshot
 * @property int|null $trial_converted_to_id
 * @property \App\Models\Subject|null $subjectRecord
 */
class StudentClass extends Model
{
    protected $table = 'StudentClass';
    protected $primaryKey = 'ID';
    public $timestamps = false;

    protected $hidden = ['pricing_snapshot'];

    private bool $allowPricingSnapshotInitialization = false;

    /** Course memo is TEXT; keep a hard cap so paste cannot unbounded-grow the row. */
    public const MEMO_MAX_LENGTH = 8000;

    protected $fillable = [
        'StudentID', 'GradeID', 'SubjectID', 'TeacherID', 'by1',
        'Period', 'StartDate', 'EndDate',
        'week', 'time', 'week1', 'time1', 'week2', 'time2',
        'week3', 'time3', 'week4', 'time4', 'week5', 'time5', 'week6', 'time6',
        'TotalHours', 'Memo', 'Charge', 'Pay', 'PayDate', 'Paid', 'Disconunt',
        'Rate', 'LearnTimeID', 'room_id', 'settlement_day', 'monthly_sessions', 'MDate', 'Stop', 'closed_reason',
        'settlement_locked_at', 'settlement_snapshot',
        'ScheduleMode', 'scheduling_policy', 'SessionCount', 'RemainingSessions',
        'ClassType', 'UsedSessions', 'SessionDuration',
        'PurchasedMinutes', 'RemainingMinutes',
        'standard_lesson_minutes', 'deduction_basis',
        'duration1', 'duration2', 'duration3', 'duration4', 'duration5', 'duration6',
        'rate_unit',
        'PackageID', 'PackageTotalSessions', 'PackageName',
        'trial_converted_to_id',
    ];

    protected $casts = [
        'settlement_locked_at' => 'datetime',
        'pricing_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (StudentClass $course): void {
            if ($course->exists && $course->isDirty('pricing_snapshot') && !$course->allowPricingSnapshotInitialization) {
                throw new \LogicException('pricing_snapshot is immutable');
            }
        });
        // 確認不收 is terminal: no writer (update, renewal, pause, payment, delete) may reopen or settle it.
        // Checked against the committed row so a model loaded before the waiver cannot slip through.
        static::saving(function (StudentClass $course): void {
            if ($course->exists && $course->isDirty(['Paid', 'Pay', 'PayDate', 'Stop', 'closed_reason', 'Charge'])
                && self::isWaivedInDb((int) $course->getKey())) {
                abort(422, '此合約已確認不收，不能再變更繳費或結案狀態');
            }
        });
        static::deleting(function (StudentClass $course): void {
            if (self::isWaivedInDb((int) $course->getKey())) {
                abort(422, '此合約已確認不收，不能刪除');
            }
        });
        static::saved(function (StudentClass $course): void {
            if ($course->wasChanged(['settlement_locked_at', 'closed_reason'])) {
                ClassSession::resetSettlementLockCache();
            }
        });
    }

    /**
     * Contracts whose history other ledgers point at (attendance, deductions, entitlement transfers, pricing
     * amendments) must never be hard-deleted; close them instead.
     */
    public static function hasOperationalHistory(array $ids): bool
    {
        if ($ids === []) {
            return false;
        }
        $sessionIds = DB::table('ClassSession')->whereIn('StudentClassID', $ids)->pluck('ID')->all();

        // Every append-only table that keeps a contract id. Add new ones here.
        $refs = [
            'session_deduction_ledger' => ['student_class_id'],
            'session_entitlement_transfers' => ['source_student_class_id', 'target_student_class_id'],
            'class_session_reassignments' => ['old_student_class_id', 'new_student_class_id'],
            'student_class_pricing_amendments' => ['student_class_id'],
            'dunning_events' => ['student_class_id'],
            'course_contract_group_members' => ['student_class_id'],
        ];
        foreach ($refs as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->whereIn($column, $ids)->exists()) {
                    return true;
                }
            }
        }

        return ($sessionIds !== [] && DB::table('StudentSingIn')->whereIn('ClassSessionID', $sessionIds)->exists())
            || DB::table('ClassSession')->whereIn('StudentClassID', $ids)
                ->whereRaw("LOWER(COALESCE(Status, '')) IN ('attended', 'completed', 'late', 'absent', 'leave')")->exists();
    }

    public static function isWaivedInDb(int $id): bool
    {
        return $id > 0 && DB::table('StudentClass')->where('ID', $id)->lockForUpdate()->value('closed_reason') === 'waived';
    }

    /** Initialize the immutable snapshot exactly once, immediately after creation. */
    public function initializePricingSnapshot(array $snapshot): void
    {
        if (!$this->exists || !$this->wasRecentlyCreated || $this->getAttribute('pricing_snapshot') !== null) {
            throw new \LogicException('pricing_snapshot may only be initialized at creation time');
        }

        $this->pricing_snapshot = $snapshot;
        $this->allowPricingSnapshotInitialization = true;
        try {
            $this->saveQuietly();
        } finally {
            $this->allowPricingSnapshotInitialization = false;
        }
    }

    public function student()
    {
        return $this->belongsTo(Student::class, 'StudentID', 'id');
    }

    public function isUsageSettlementLocked(): bool
    {
        return $this->getAttribute('settlement_locked_at') !== null
            || in_array((string) $this->getAttribute('closed_reason'), ['usage_settled', 'contract_amended', 'waived'], true);
    }

    public function subjectRecord()
    {
        return $this->belongsTo(Subject::class, 'SubjectID', 'id');
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'TeacherID', 'id');
    }

    public function classSessions()
    {
        return $this->hasMany(ClassSession::class, 'StudentClassID', 'ID');
    }

    public function learningRecords()
    {
        return $this->hasMany(LearningRecord::class, 'StudentClassID', 'ID');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'StudentClassID', 'ID');
    }

    public function pricingAmendments()
    {
        return $this->hasMany(StudentClassPricingAmendment::class, 'student_class_id', 'ID');
    }

    public function paymentReports()
    {
        return $this->hasMany(PaymentReport::class, 'StudentClassID', 'ID');
    }

    public function room()
    {
        return $this->belongsTo(Room::class, 'room_id', 'id');
    }

    public function coursePackage()
    {
        return $this->belongsTo(CoursePackage::class, 'PackageID', 'id');
    }

    public function isPartOfPackage(): bool
    {
        $packageId = (int) ($this->getAttribute('PackageID') ?? 0);
        return $packageId > 0;
    }

    /**
     * Non-financial effective-paid predicate for display, alerts, dunning, and notifications.
     *
     * A course is effectively paid if:
     * 1. StudentClass.Paid == 1 (explicit course-level paid flag)
     * 2. OR it belongs to a CoursePackage whose billing is settled (CoursePackage.paid == true).
     *
     * @param CoursePackage|null $package Optional preloaded or caller-supplied package instance
     * @param bool|null $rawPaidFlag Optional override for raw Paid flag
     * @return bool
     */
    public function isEffectivelyPaid(?CoursePackage $package = null, ?bool $rawPaidFlag = null): bool
    {
        $paid = $rawPaidFlag !== null ? $rawPaidFlag : ((int) ($this->Paid ?? 0) === 1);
        if ($paid) {
            return true;
        }

        if (!$this->isPartOfPackage()) {
            return false;
        }

        if ($package !== null) {
            return (bool) ($package->paid ?? false);
        }

        if ($this->relationLoaded('coursePackage')) {
            return (bool) ($this->coursePackage->paid ?? false);
        }

        $packageId = (int) ($this->getAttribute('PackageID') ?? 0);
        if ($packageId <= 0) {
            return false;
        }

        return CoursePackage::query()->where('id', $packageId)->where('paid', true)->exists();
    }

    /**
     * F8 authority: a course holds its WEEKLY TEMPLATE seat iff Stop=0 and it is not a
     * used-up count course. Concrete ClassSession rows are NOT governed by this rule.
     */
    public function scopeHoldsTemplateSeat($query)
    {
        return self::applyHoldsTemplateSeat($query, $this->getTable());
    }

    /** Same rule for DB::table('StudentClass as sc') builders; pass the alias. */
    public static function applyHoldsTemplateSeat($query, string $alias)
    {
        return $query->where("$alias.Stop", 0)->where(function ($q) use ($alias) {
            $q->whereNull("$alias.ScheduleMode")
                ->orWhere("$alias.ScheduleMode", '!=', 'count')
                ->orWhereNull("$alias.RemainingSessions")
                ->orWhere("$alias.RemainingSessions", '>', 0);
        });
    }

    /**
     * Scope a query to only include courses that are effectively paid.
     * Paid=1 OR belongs to a settled CoursePackage (paid=1).
     */
    public function scopeEffectivelyPaid($query)
    {
        return $query->where(function ($q) {
            $q->where($this->qualifyColumn('Paid'), 1)
              ->orWhere(function ($q2) {
                  $q2->whereNotNull($this->qualifyColumn('PackageID'))
                     ->where($this->qualifyColumn('PackageID'), '>', 0)
                     ->whereExists(function ($sub) {
                         $sub->select(DB::raw(1))
                             ->from('course_packages')
                             ->whereColumn('course_packages.id', $this->qualifyColumn('PackageID'))
                             ->where('course_packages.paid', 1);
                     });
              });
        });
    }

    /**
     * Scope a query to only include courses that are effectively unpaid for dunning/reminders.
     * Paid!=1 AND does NOT belong to a settled CoursePackage (paid=1).
     */
    public function scopeEffectivelyUnpaid($query)
    {
        return $query->where(function ($q) {
            $q->where($this->qualifyColumn('Paid'), 0)
              ->orWhereNull($this->qualifyColumn('Paid'));
        })->where(function ($q) {
            $q->whereNull($this->qualifyColumn('PackageID'))
              ->orWhere($this->qualifyColumn('PackageID'), '<=', 0)
              ->orWhereNotExists(function ($sub) {
                  $sub->select(DB::raw(1))
                      ->from('course_packages')
                      ->whereColumn('course_packages.id', $this->qualifyColumn('PackageID'))
                      ->where('course_packages.paid', 1);
              });
        });
    }

    /**
     * Display-path "fully paid" predicate (R94 / DIRECTOR_PAYMENT_ALERT_RULES).
     *
     * Paid flag OR invoice amounts covering charge. Deliberately amount-based —
     * never treat "any payment exists" as paid (that would collapse partial).
     * Not the dunning *inclusion* rule (which may still key off Paid==1 only).
     */
    public static function isFullyPaid(bool $rawPaidFlag, int $paidAmount, int $charge): bool
    {
        return $rawPaidFlag || ($charge > 0 && $paidAmount >= $charge);
    }

    /**
     * Instance wrapper: use this course's Paid flag with a caller-supplied invoice sum.
     */
    public function isFullyPaidWithInvoiceAmount(int $paidAmount, ?int $charge = null): bool
    {
        $charge ??= (int) ($this->Charge ?? 0);

        return self::isFullyPaid($this->isEffectivelyPaid(), $paidAmount, $charge);
    }

    /**
     * 取得「某堂課當日」的標準時長（分鐘）。
     * 先查 duration1~duration6 對應 ISO weekday；否則 fallback 到 SessionDuration。
     * 供單堂時間調整時的費率換算使用（per-day > contract default）。
     *
     * @param  int  $isoWeekday 1=Mon ... 7=Sun
     */
    public function resolveSessionDurationForWeekday(int $isoWeekday): int
    {
        $map = [
            ['week1', 'duration1'], ['week2', 'duration2'], ['week3', 'duration3'],
            ['week4', 'duration4'], ['week5', 'duration5'], ['week6', 'duration6'],
        ];
        foreach ($map as [$wf, $df]) {
            if ((int) ($this->{$wf} ?? 0) === $isoWeekday) {
                $dur = (int) ($this->{$df} ?? 0);
                if ($dur >= 30) {
                    return $dur;
                }
            }
        }
        $fallback = (int) ($this->SessionDuration ?? 0);
        return $fallback > 0 ? $fallback : 0;
    }

    /**
     * #613 A1：契約「每堂標準分鐘」。分鐘制扣堂以此把分鐘換算為堂數顯示值。
     * 來源優先序：SessionDuration（契約預設）→ 60（無資料時的安全 fallback）。
     * 變動時長課（duration1..6 不一）仍以契約 SessionDuration 為準，差異由人工複核。
     */
    public const DEFAULT_SESSION_MINUTES = 60;

    public function perSessionMinutes(): int
    {
        $dur = (int) ($this->SessionDuration ?? 0);
        return $dur >= 1 ? $dur : self::DEFAULT_SESSION_MINUTES;
    }

    /**
     * Always read a concrete basis, never null.
     *
     * The column has a DB-level default, but a null can still be observed: on a
     * model instance that has not been refreshed since insert, and on any row
     * written during a rolling deploy before the migration landed. Both must read
     * as fixed_session — the fail-safe direction, since fixed_session is exactly
     * today's behaviour.
     */
    public function getDeductionBasisAttribute(mixed $value): string
    {
        return ($value === null || $value === '') ? DeductionBasis::FIXED_SESSION : (string) $value;
    }

    /**
     * RFC_NONSTANDARD_SESSION_DURATION_BILLING D2 — is this course opted in to
     * consuming entitlement by actual clock duration?
     */
    public function isActualDurationBasis(): bool
    {
        return $this->deduction_basis === DeductionBasis::ACTUAL_DURATION;
    }

    /**
     * This course's persisted standard billing unit, in minutes. Returns null when
     * unset — deliberately WITHOUT falling back to SessionDuration or any house
     * default (RFC §10 A1): the value must have been resolved and persisted at
     * opt-in time, so a missing one means "this course was never set up for
     * actual-duration billing" and callers must fail closed rather than guess.
     */
    public function resolvedStandardLessonMinutes(): ?int
    {
        $minutes = $this->standard_lesson_minutes;

        return ($minutes !== null && (int) $minutes >= 1) ? (int) $minutes : null;
    }

    /**
     * Has any entitlement already been consumed on this course?
     *
     * Once true, the billing contract (standard_lesson_minutes / deduction_basis /
     * purchased units) is frozen: entitlement is still derived as
     * SessionCount x standard_lesson_minutes, so changing any of them would
     * retroactively reinterpret every deduction already recorded.
     */
    public function hasDeductionHistory(): bool
    {
        return SessionDeductionLedger::query()
            ->where('student_class_id', $this->getKey())
            ->exists();
    }

    /** Human-readable subject for UI / slips (Subject 欄位或 SubjectID 對照). */
    /** @param array<int, string|null>|null $subjectNames Optional request-scoped lookup, including legacy BaseData fallback. */
    public function displaySubjectName(?array $subjectNames = null): string
    {
        $subject = $this->getAttribute('Subject');
        if ($subject !== null && $subject !== '') {
            return (string) $subject;
        }
        $id = (int) ($this->SubjectID ?? 0);
        if ($id <= 0) {
            return '課程';
        }

        if ($subjectNames !== null) {
            return (string) ($subjectNames[$id] ?? '課程');
        }

        return (string) (DB::table('Subject')->where('id', $id)->value('Subject_Name')
            ?? DB::table('BaseData')->where('Name', '課程')->where('id', $id)->value('Val')
            ?? '課程');
    }
}
