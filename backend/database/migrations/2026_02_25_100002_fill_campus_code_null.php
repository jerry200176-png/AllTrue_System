<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fill Campus.code where it is null, so API responses and frontend have consistent codes.
 * 依現有名稱為 code 為 null 的分校補上 code。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'code')) {
            return;
        }

        $nameToCode = [
            '內湖校區' => 'neihu',
            '東湖校區' => 'donghu',
            '大直校區' => 'dazhi',
            '汐止校區' => 'xizhi',
            '新店校區' => 'xindian_qu',
            '麗山校區' => 'lishan',
            '蘆洲分校' => 'luzhou',
            '敦南分校' => 'dunnan',
            '新店分校' => 'xindian',
            '桃園分校' => 'taoyuan',
            '新莊分校' => 'xinzhuang',
            '石牌校區' => 'shipai',
            '新莊中平分校' => 'xinzhuang_zhongping',
            '三重分校' => 'sanchong',
            '大安分校' => 'daan',
            '木柵分校' => 'muzha',
            '興隆分校' => 'xinglong',
        ];

        foreach ($nameToCode as $name => $code) {
            DB::table('Campus')
                ->where('name', $name)
                ->whereNull('code')
                ->update(['code' => $code]);
        }

        // 其餘仍為 null 的用 id 當後備，避免唯一約束問題
        $nullRows = DB::table('Campus')->whereNull('code')->get(['id', 'name']);
        foreach ($nullRows as $row) {
            $slug = 'campus_' . $row->id;
            DB::table('Campus')->where('id', $row->id)->update(['code' => $slug]);
        }
    }

    public function down(): void
    {
        // 不自動還原，避免覆寫手動設定
    }
};
