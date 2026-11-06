<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $campus_id
 * @property int $teacher_id
 * @property string $month
 * @property int $amount
 * @property string $reason
 * @property int|null $created_by
 * @property int|null $updated_by
 */
class ParttimePayrollAdminBonus extends Model
{
    protected $table = 'parttime_payroll_admin_bonuses';

    protected $fillable = ['campus_id', 'teacher_id', 'month', 'amount', 'reason', 'created_by', 'updated_by'];

    protected $casts = ['amount' => 'integer'];
}
