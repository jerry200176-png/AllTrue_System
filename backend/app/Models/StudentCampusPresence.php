<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
/**
 * @property int $id
 * @property int $CampusID
 * @property int $StudentID
 * @property string $Source
 * @property string|null $DeviceID
 * @property string|null $ArrivedAt
 * @property string|null $DepartedAt
 * @property string $Status
 * @property string|null $CloseReason
 * @property string $IdempotencyKey
 * @property string|null $VoidedAt
 * @method static StudentCampusPresence create(array $attributes = [])
 */
class StudentCampusPresence extends Model
{
    protected $table = 'StudentCampusPresence';
    public const STATUS_OPEN = 'open', STATUS_CLOSED = 'closed', STATUS_ORPHAN_CLOSED = 'orphan_closed', STATUS_VOIDED = 'voided';
    public const SOURCE_RFID = 'rfid', SOURCE_MANUAL = 'manual', SOURCE_SYSTEM = 'system';
    public const CLOSE_SWIPE_OUT = 'swipe_out';
    public const CLOSE_ORPHAN_JOB = 'orphan_job';
    public const CLOSE_MANUAL = 'manual';
    public const CLOSE_VOID = 'void';
    protected $fillable = ['CampusID', 'StudentID', 'Source', 'DeviceID', 'RfidUidHash', 'ArrivedAt', 'DepartedAt', 'Status', 'CloseReason', 'IdempotencyKey', 'VoidedAt', 'VoidedByUserID', 'VoidReason'];
    protected $casts = ['ArrivedAt' => 'datetime', 'DepartedAt' => 'datetime', 'VoidedAt' => 'datetime'];
    public function student(): BelongsTo { return $this->belongsTo(Student::class, 'StudentID'); }
    public function campus(): BelongsTo { return $this->belongsTo(Campus::class, 'CampusID'); }
    public function isOpen(): bool { return $this->Status === self::STATUS_OPEN && $this->DepartedAt === null && $this->VoidedAt === null; }
}
