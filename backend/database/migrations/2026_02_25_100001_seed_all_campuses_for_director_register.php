<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Ensure all campuses used in director registration exist in Campus table.
 * 主任申請頁要顯示：大安、新竹、天母、中壢、桃園
 */
return new class extends Migration
{
    private function defaultCampusRow(string $name, string $code, bool $withToken = true): array
    {
        $defaults = [
            'name' => $name,
            'Current' => 0,
            'LineNotifyID' => '',
            'Client_ID' => '',
            'Client_Secret' => '',
            'LIFFID' => '',
            'LIFF_URL' => '',
            'URL' => '',
            'Token' => $withToken ? Str::random(64) : null,
            'TelegramToken' => null,
            'TelegramChatID' => null,
            'TelegramURL' => '',
            'TeachLIFFID' => '',
            'TeachLIFF_URL' => '',
        ];
        if (Schema::hasColumn('Campus', 'code')) {
            $defaults['code'] = $code;
        }
        if (Schema::hasColumn('Campus', 'SwipeWindowMinutes')) {
            $defaults['SwipeWindowMinutes'] = 30;
        }
        return $defaults;
    }

    public function up(): void
    {
        if (!Schema::hasTable('Campus')) {
            return;
        }

        $campuses = [
            ['name' => '大安分校', 'code' => 'daan'],
            ['name' => '新竹分校', 'code' => 'hsinchu'],
            ['name' => '天母分校', 'code' => 'tianmu'],
            ['name' => '中壢分校', 'code' => 'zhongli'],
            ['name' => '桃園分校', 'code' => 'taoyuan'],
        ];

        foreach ($campuses as $c) {
            $exists = DB::table('Campus')->where('name', $c['name'])->exists();
            if (!$exists) {
                DB::table('Campus')->insert($this->defaultCampusRow($c['name'], $c['code']));
            } else {
                if (Schema::hasColumn('Campus', 'code')) {
                    DB::table('Campus')->where('name', $c['name'])->update(['code' => $c['code']]);
                }
            }
        }
    }

    public function down(): void
    {
        // Optional: remove only the campuses we might have added (by name)
        $names = ['新竹分校', '天母分校', '中壢分校', '桃園分校'];
        foreach ($names as $name) {
            DB::table('Campus')->where('name', $name)->delete();
        }
    }
};
