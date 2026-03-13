<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('Campus', 'code')) {
            return;
        }
        // Add 'code' column to Campus table for frontend-friendly string identifiers
        Schema::table('Campus', function (Blueprint $table) {
            $table->string('code', 32)->nullable()->unique();
        });

        // Seed the 4 pilot campuses with their codes (update existing rows)
        $campuses = [
            ['name' => '大安分校', 'code' => 'daan'],
            ['name' => '木柵分校', 'code' => 'muzha'],
            ['name' => '興隆分校', 'code' => 'xinglong'],
            ['name' => '新店分校', 'code' => 'xindian'],
        ];

        foreach ($campuses as $campus) {
            $existing = DB::table('Campus')->where('name', $campus['name'])->first();
            if ($existing) {
                DB::table('Campus')->where('id', $existing->id)->update(['code' => $campus['code']]);
            } else {
                // Insert if not exists (fill required columns with sensible defaults)
                DB::table('Campus')->insert([
                    'name'          => $campus['name'],
                    'code'          => $campus['code'],
                    'Current'       => 0,
                    'LineNotifyID'  => '',
                    'Client_ID'     => '',
                    'Client_Secret' => '',
                    'LIFFID'        => '',
                    'LIFF_URL'      => '',
                    'URL'           => '',
                    'TelegramURL'   => '',
                    'TeachLIFFID'   => '',
                    'TeachLIFF_URL' => '',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
