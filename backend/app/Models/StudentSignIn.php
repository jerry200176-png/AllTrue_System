<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int|numeric-string|null $CampusID Nullable persisted campus ID added by attendance schema migrations.
 * @property int $id
 * @property int|null $StudentClassID
 * @property int $StudentID
 * @property int|null $TeacherID
 * @property int|null $RecordedByUserID
 * @property int|null $GradeID
 * @property int|null $SubjectID
 * @property int|null $Get1byID
 * @property int|null $Hours
 * @property string|null $Memo
 * @property string $SignInDT
 * @property string|null $SignOutDT
 * @property string $MDT
 * @property int|null $ClassSessionID
 * @property string $Status
 * @property bool $SessionDeducted
 * @property \Carbon\CarbonInterface|null $VoidedAt
 * @property int|null $VoidedByUserID
 * @property string|null $VoidReason
 * @property string $PersonType
 */
class StudentSignIn extends Model
{
    protected $table = 'StudentSingIn';
    public $timestamps = false;

    protected $fillable = [
        'StudentClassID',
        'StudentID',
        'TeacherID',
        'RecordedByUserID',
        'GradeID',
        'SubjectID',
        'Get1byID',
        'Hours',
        'Memo',
        'SignInDT',
        'SignOutDT',
        'MDT',
        'ClassSessionID',
        'Status',
        'PersonType',
        'CampusID',
        'SessionDeducted',
        'VoidedAt',
        'VoidedByUserID',
        'VoidReason',
    ];

    protected $casts = [
        'SessionDeducted' => 'boolean',
        'VoidedAt'        => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (StudentSignIn $signIn): void {
            $isActiveLeave = strtolower(trim((string) $signIn->getAttribute('Status'))) === 'leave'
                && $signIn->getAttribute('VoidedAt') === null;

            if ($isActiveLeave && $signIn->getAttribute('SignOutDT') === null) {
                throw new LogicException('Active leave attendance rows require SignOutDT.');
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->whereNull('VoidedAt');
    }

    public function scopeVoided($query)
    {
        return $query->whereNotNull('VoidedAt');
    }

    public function isVoided(): bool
    {
        return $this->VoidedAt !== null;
    }
}
