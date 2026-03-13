<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * 為每個分校隨機產生 Token，供 swipe-rfid API 的 Authorization Bearer 驗證使用
     */
    public function up(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('Campus') || !\Illuminate\Support\Facades\Schema::hasColumn('Campus', 'Token')) {
            return;
        }

        $campuses = DB::table('Campus')->get(['id']);
        foreach ($campuses as $c) {
            DB::table('Campus')->where('id', $c->id)->update(['Token' => Str::random(64)]);
        }
    }

    public function down(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('Campus')) {
            return;
        }
        DB::table('Campus')->update(['Token' => null]);
    }
};
