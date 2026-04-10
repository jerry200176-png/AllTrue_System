<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subject master: normalize 「自然」 -> 「理化」
        if (Schema::hasTable('Subject')) {
            DB::table('Subject')
                ->where('Subject_Name', '自然')
                ->update(['Subject_Name' => '理化']);
        }

        // Learning records keep subject text snapshot; normalize historical rows.
        if (Schema::hasTable('LearningRecord')) {
            DB::table('LearningRecord')
                ->where('Subject', '自然')
                ->update(['Subject' => '理化']);
        }

        // Schedule table stores subject as text; normalize for calendar/filters.
        if (Schema::hasTable('schedules')) {
            DB::table('schedules')
                ->where('subject', '自然')
                ->update(['subject' => '理化']);
        }

        // Legacy course dictionary (if present): normalize label.
        if (Schema::hasTable('BaseData')) {
            $hasScienceMix = DB::table('BaseData')
                ->where('Name', '課程')
                ->where('Val', '理化')
                ->exists();

            if ($hasScienceMix) {
                DB::table('BaseData')
                    ->where('Name', '課程')
                    ->where('Val', '自然')
                    ->delete();
            } else {
                DB::table('BaseData')
                    ->where('Name', '課程')
                    ->where('Val', '自然')
                    ->update(['Val' => '理化']);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Subject')) {
            DB::table('Subject')
                ->where('Subject_Name', '理化')
                ->update(['Subject_Name' => '自然']);
        }

        if (Schema::hasTable('LearningRecord')) {
            DB::table('LearningRecord')
                ->where('Subject', '理化')
                ->update(['Subject' => '自然']);
        }

        if (Schema::hasTable('schedules')) {
            DB::table('schedules')
                ->where('subject', '理化')
                ->update(['subject' => '自然']);
        }

        if (Schema::hasTable('BaseData')) {
            $hasNatural = DB::table('BaseData')
                ->where('Name', '課程')
                ->where('Val', '自然')
                ->exists();

            if (!$hasNatural) {
                DB::table('BaseData')
                    ->where('Name', '課程')
                    ->where('Val', '理化')
                    ->update(['Val' => '自然']);
            }
        }
    }
};

