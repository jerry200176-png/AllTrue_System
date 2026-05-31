<?php

namespace Tests\Feature;

use App\Models\SessionDeductionLedger;
use App\Models\StudentClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #613 A1 PR1（地基）：分鐘制欄位/輔助方法存在且不改變既有行為。
 * 此 PR 僅新增 additive nullable 欄位，引擎尚未讀取，故無扣堂行為斷言。
 */
class MinutesBalanceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_minutes_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('StudentClass', 'PurchasedMinutes'));
        $this->assertTrue(Schema::hasColumn('StudentClass', 'RemainingMinutes'));
        $this->assertTrue(Schema::hasColumn('session_deduction_ledger', 'minutes'));
    }

    public function test_per_session_minutes_uses_session_duration(): void
    {
        $sc = new StudentClass(['SessionDuration' => 120]);
        $this->assertSame(120, $sc->perSessionMinutes());
    }

    public function test_per_session_minutes_falls_back_to_default(): void
    {
        $this->assertSame(
            StudentClass::DEFAULT_SESSION_MINUTES,
            (new StudentClass(['SessionDuration' => 0]))->perSessionMinutes()
        );
        $this->assertSame(60, (new StudentClass([]))->perSessionMinutes());
    }

    public function test_ledger_accepts_minutes_field(): void
    {
        $row = SessionDeductionLedger::create([
            'student_class_id' => 999001,
            'class_session_id' => null,
            'event_type'       => 'deduct',
            'source'           => 'attendance',
            'minutes'          => 60,
        ]);
        $this->assertSame(60, (int) $row->fresh()->minutes);
    }
}
