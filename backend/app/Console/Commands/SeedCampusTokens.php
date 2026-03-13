<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SeedCampusTokens extends Command
{
    protected $signature = 'campus:seed-tokens';
    protected $description = '為所有分校隨機產生 Token（供 swipe-rfid API 驗證）';

    public function handle(): int
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'Token')) {
            $this->error('Campus 表或 Token 欄位不存在');
            return 1;
        }

        $campuses = DB::table('Campus')->get(['id', 'name', 'code']);
        if ($campuses->isEmpty()) {
            $this->warn('尚無分校資料');
            return 0;
        }

        foreach ($campuses as $c) {
            $token = Str::random(64);
            DB::table('Campus')->where('id', $c->id)->update(['Token' => $token]);
            $label = ($c->name ?? '') . ($c->code ? " ({$c->code})" : '');
            $this->line("已更新: {$label} => " . substr($token, 0, 16) . '...');
        }

        $this->info('完成！共 ' . $campuses->count() . ' 個分校已產生 Token');
        return 0;
    }
}
