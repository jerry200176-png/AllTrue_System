<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Services\ClassSessionMaterializationService;

/**
 * @property int $id
 * @property int $StudentClassID
 * @property int|null $SubjectID  Per-session subject override; null = inherit from StudentClass.
 * @property string $SessionDate
 * @property string $StartTime
 * @property string $EndTime
 * @property int|null $session_charge  Per-session charge override; null = use standard charge.
 */

class ClassSession extends Model
{
    protected $table = 'ClassSession';

    private static ?bool $settlementLockColumnExists = null;

    /**
     * Request-memo of usage-settled course IDs. Null means not loaded yet.
     * Loaded once per request so bulk ClassSession create/update does not
     * repeat `exists(select * from StudentClass …)` per row (Sentry #1731).
     *
     * @var array<int, true>|null
     */
    private static ?array $lockedCourseIdSet = null;

    private ?bool $preloadedCourseSettlementLocked = null;

    private ?StudentClass $preloadedStudentClass = null;

    /**
     * Explicitly authorised parallel-course write (EnrollmentService force flow).
     * This is transient model state; direct ClassSession::create() remains
     * protected by the student overlap guard by default.
     */
    private bool $allowStudentOverlap = false;

    protected $fillable = [
        'StudentClassID',
        'SubjectID',
        'SessionDate',
        'StartTime',
        'EndTime',
        'Status',
        'Note',
        'IsContractException',
        'session_charge',
    ];

    protected $casts = [
        'IsContractException' => 'boolean',
        'session_charge' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClassSession $session) {
            $session->assertCourseIsMutable();
            if (!$session->allowsStudentOverlap()) {
                app(ClassSessionMaterializationService::class)->assertStudentSlotAvailableForSession($session);
            }
        });
        static::updating(function (ClassSession $session) {
            if ($session->isDirty(['StudentClassID', 'SessionDate', 'StartTime', 'EndTime', 'Status'])) {
                $session->assertCourseIsMutable();
                if (!$session->allowsStudentOverlap()) {
                    app(ClassSessionMaterializationService::class)->assertStudentSlotAvailableForSession($session);
                }
            }
        });
    }

    public function setAllowStudentOverlap(bool $allow = true): self
    {
        $this->allowStudentOverlap = $allow;

        return $this;
    }

    public function allowsStudentOverlap(): bool
    {
        return $this->allowStudentOverlap;
    }

    public function setPreloadedStudentClass(StudentClass $studentClass): self
    {
        $this->preloadedStudentClass = $studentClass;

        return $this;
    }

    public function preloadedStudentClass(): ?StudentClass
    {
        return $this->preloadedStudentClass;
    }

    public function setPreloadedCourseSettlementLock(?bool $locked): self
    {
        $this->preloadedCourseSettlementLocked = $locked;

        return $this;
    }

    public static function resetSettlementLockCache(): void
    {
        self::$lockedCourseIdSet = null;
        self::$settlementLockColumnExists = null;
    }

    /**
     * @return array<int, true>
     */
    private static function lockedCourseIdSet(): array
    {
        if (self::$lockedCourseIdSet !== null) {
            return self::$lockedCourseIdSet;
        }

        self::$settlementLockColumnExists ??= Schema::hasColumn('StudentClass', 'settlement_locked_at');
        if (!self::$settlementLockColumnExists) {
            return self::$lockedCourseIdSet = [];
        }

        $ids = StudentClass::query()
            ->where(function ($query) {
                $query->whereNotNull('settlement_locked_at')
                    ->orWhereIn('closed_reason', ['usage_settled', 'contract_amended']);
            })
            ->pluck('ID')
            ->all();

        $set = [];
        foreach ($ids as $id) {
            $set[(int) $id] = true;
        }

        return self::$lockedCourseIdSet = $set;
    }

    private function assertCourseIsMutable(): void
    {
        $courseId = (int) $this->getAttribute('StudentClassID');
        if ($courseId <= 0) {
            return;
        }

        if ($this->preloadedCourseSettlementLocked !== null) {
            if ($this->preloadedCourseSettlementLocked) {
                throw ValidationException::withMessages([
                    'student_class_id' => 'Course sessions cannot change after usage settlement.',
                ]);
            }

            return;
        }

        if (isset(self::lockedCourseIdSet()[$courseId])) {
            throw ValidationException::withMessages([
                'student_class_id' => '此課程已提前結清，堂次與點名紀錄已鎖定。',
            ]);
        }
    }

    public function studentClass()
    {
        return $this->belongsTo(StudentClass::class, 'StudentClassID', 'ID');
    }

    public function subject()
    {
        return $this->belongsTo(\App\Models\Subject::class, 'SubjectID', 'id');
    }

    public function signIns()
    {
        return $this->hasMany(StudentSignIn::class, 'ClassSessionID', 'id');
    }

    /**
     * All session rows for payment-slip display (attended, scheduled, leave, etc.).
     * Cancelled sessions are excluded.
     *
     * @param  array<int>  $studentClassIds
     * @return list<array{student_class_id:int,date:string,start_time:?string,end_time:?string,status:string,subject:?string}>
     */
    public static function sessionsForPaymentSlip(
        array $studentClassIds,
        ?string $periodStart = null,
        ?string $periodEnd = null
    ): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $studentClassIds))));
        if ($ids === []) {
            return [];
        }

        $multiCourse = count($ids) > 1;

        return static::query()
            ->whereIn('StudentClassID', $ids)
            ->whereNotIn('Status', ['cancelled'])
            ->when($periodStart !== null, fn ($q) => $q->whereDate('SessionDate', '>=', $periodStart))
            ->when($periodEnd !== null, fn ($q) => $q->whereDate('SessionDate', '<=', $periodEnd))
            ->with(['studentClass'])
            ->orderBy('SessionDate')
            ->orderBy('StartTime')
            ->get()
            ->map(function (ClassSession $s) use ($multiCourse) {
                $date = $s->SessionDate ? substr((string) $s->SessionDate, 0, 10) : '';
                $st = $s->StartTime ? substr((string) $s->StartTime, 0, 5) : null;
                $et = $s->EndTime ? substr((string) $s->EndTime, 0, 5) : null;
                $row = [
                    'student_class_id' => (int) $s->StudentClassID,
                    'date'             => $date,
                    'start_time'       => $st,
                    'end_time'         => $et,
                    'status'           => (string) ($s->Status ?? ''),
                ];
                if ($multiCourse && $s->studentClass) {
                    $row['subject'] = $s->studentClass->displaySubjectName();
                }

                return $row;
            })
            ->values()
            ->all();
    }
}
