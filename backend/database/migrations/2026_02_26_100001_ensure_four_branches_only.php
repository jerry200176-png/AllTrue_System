<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 僅保留四所分校：興隆、新店、大安、木柵。
 * 確保此四筆存在於 Campus 表，並設好 code。
 */
return new class extends Migration
{
    private const BRANCHES = [
        ['name' => '興隆分校', 'code' => 'xinglong'],
        ['name' => '新店分校', 'code' => 'xindian'],
        ['name' => '大安分校', 'code' => 'daan'],
        ['name' => '木柵分校', 'code' => 'muzha'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('Campus')) {
            return;
        }

        $hasCode = Schema::hasColumn('Campus', 'code');
        $hasToken = Schema::hasColumn('Campus', 'Token');

        foreach (self::BRANCHES as $c) {
            $exists = DB::table('Campus')->where('name', $c['name'])->first();
            if ($exists) {
                if ($hasCode) {
                    DB::table('Campus')->where('id', $exists->id)->update(['code' => $c['code']]);
                }
            } else {
                $row = [
                    'name' => $c['name'],
                    'Current' => 0,
                    'LineNotifyID' => '',
                    'Client_ID' => '',
                    'Client_Secret' => '',
                    'LIFFID' => '',
                    'LIFF_URL' => '',
                    'URL' => '',
                    'TelegramURL' => '',
                    'TeachLIFFID' => '',
                    'TeachLIFF_URL' => '',
                ];
                if ($hasCode) {
                    $row['code'] = $c['code'];
                }
                if ($hasToken) {
                    $row['Token'] = Str::random(64);
                }
                if (Schema::hasColumn('Campus', 'SwipeWindowMinutes')) {
                    $row['SwipeWindowMinutes'] = 30;
                }
                if (Schema::hasColumn('Campus', 'TelegramToken')) {
                    $row['TelegramToken'] = null;
                }
                if (Schema::hasColumn('Campus', 'TelegramChatID')) {
                    $row['TelegramChatID'] = null;
                }
                DB::table('Campus')->insert($row);
            }
        }
    }

    public function down(): void
    {
        // 不刪除資料，僅還原需手動處理
    }
};
