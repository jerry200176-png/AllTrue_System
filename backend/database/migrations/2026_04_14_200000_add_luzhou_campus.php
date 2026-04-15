<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const NEW_BRANCHES = [
        ['name' => '蘆洲分校', 'code' => 'luzhou'],
    ];

    private function defaultCampusRow(string $name, string $code): array
    {
        $row = [
            'name' => $name,
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

        if (Schema::hasColumn('Campus', 'code')) {
            $row['code'] = $code;
        }
        if (Schema::hasColumn('Campus', 'Token')) {
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

        return $row;
    }

    public function up(): void
    {
        if (!Schema::hasTable('Campus')) {
            return;
        }

        $hasCode = Schema::hasColumn('Campus', 'code');
        foreach (self::NEW_BRANCHES as $branch) {
            $query = DB::table('Campus')->where('name', $branch['name']);
            if ($hasCode) {
                $query->orWhere('code', $branch['code']);
            }
            $existing = $query->orderBy('id', 'asc')->first();
            if ($existing) {
                $updates = ['name' => $branch['name']];
                if ($hasCode) {
                    $updates['code'] = $branch['code'];
                }
                DB::table('Campus')->where('id', $existing->id)->update($updates);
                continue;
            }

            DB::table('Campus')->insert($this->defaultCampusRow($branch['name'], $branch['code']));
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('Campus')) {
            return;
        }

        foreach (self::NEW_BRANCHES as $branch) {
            DB::table('Campus')->where('name', $branch['name'])->delete();
        }
    }
};
