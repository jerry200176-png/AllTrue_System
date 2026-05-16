<?php

namespace Tests\Unit;

use App\Support\EngagementRankProgression;
use PHPUnit\Framework\TestCase;

class EngagementRankProgressionTest extends TestCase
{
    /**
     * 取出 private const（PHP 8.1+ ReflectionClassConstant）
     */
    private function getConst(string $name): array
    {
        $rc = new \ReflectionClassConstant(EngagementRankProgression::class, $name);
        return $rc->getValue();
    }

    /**
     * XP 門檻必須嚴格遞增（不能有相等或逆序）
     */
    public function test_teacher_xp_thresholds_are_strictly_ascending(): void
    {
        $table = $this->getConst('TEACHER_MIN_XP');
        $prev = -1;
        foreach ($table as $key => $xp) {
            $this->assertGreaterThan(
                $prev,
                $xp,
                "TEACHER_MIN_XP[$key]=$xp 不大於前一個值 $prev，門檻順序錯誤"
            );
            $prev = $xp;
        }
    }

    public function test_staff_xp_thresholds_are_strictly_ascending(): void
    {
        $table = $this->getConst('STAFF_MIN_XP');
        $prev = -1;
        foreach ($table as $key => $xp) {
            $this->assertGreaterThan(
                $prev,
                $xp,
                "STAFF_MIN_XP[$key]=$xp 不大於前一個值 $prev，門檻順序錯誤"
            );
            $prev = $xp;
        }
    }

    /**
     * 兩個門檻表必須有完全相同的 rank key 順序（前後端同步保證）
     */
    public function test_teacher_and_staff_tables_have_identical_rank_keys(): void
    {
        $teacherKeys = array_keys($this->getConst('TEACHER_MIN_XP'));
        $staffKeys   = array_keys($this->getConst('STAFF_MIN_XP'));
        $this->assertSame($teacherKeys, $staffKeys, 'TEACHER_MIN_XP 與 STAFF_MIN_XP 的 key 順序不一致');
    }

    /**
     * 三個士官長階必須都存在（本次新增，防回歸）
     */
    public function test_all_three_master_sergeant_ranks_exist(): void
    {
        $keys = array_keys($this->getConst('TEACHER_MIN_XP'));
        $this->assertContains('master_sergeant_third',  $keys);
        $this->assertContains('master_sergeant_second', $keys);
        $this->assertContains('master_sergeant_first',  $keys);
    }

    /**
     * XP=0 → 二等兵（預設值）
     */
    public function test_xp_zero_returns_private_second(): void
    {
        $this->assertSame('private_second', EngagementRankProgression::rankKeyForXp(0, 'teacher'));
        $this->assertSame('private_second', EngagementRankProgression::rankKeyForXp(0, 'staff'));
    }

    /**
     * 士官長 XP 邊界（teacher track）
     */
    public function test_master_sergeant_xp_boundaries_teacher(): void
    {
        $this->assertSame('master_sergeant_third',  EngagementRankProgression::rankKeyForXp(275, 'teacher'));
        $this->assertSame('master_sergeant_second', EngagementRankProgression::rankKeyForXp(355, 'teacher'));
        $this->assertSame('master_sergeant_first',  EngagementRankProgression::rankKeyForXp(445, 'teacher'));
        $this->assertSame('second_lieutenant',      EngagementRankProgression::rankKeyForXp(545, 'teacher'));
    }

    /**
     * 未知 roleTrack 退回 teacher 表（不能 crash）
     */
    public function test_unknown_role_track_falls_back_to_teacher(): void
    {
        $result = EngagementRankProgression::rankKeyForXp(100, 'unknown_role');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * 超大 XP → 一級上將（最高可解鎖階）
     */
    public function test_very_large_xp_returns_general_first_class(): void
    {
        $this->assertSame('general_first_class', EngagementRankProgression::rankKeyForXp(99999, 'teacher'));
        $this->assertSame('general_first_class', EngagementRankProgression::rankKeyForXp(99999, 'staff'));
    }
}
