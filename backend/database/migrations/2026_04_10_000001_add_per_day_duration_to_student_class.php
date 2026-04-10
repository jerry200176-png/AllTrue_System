<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->integer('duration1')->nullable()->after('SessionDuration');
            $table->integer('duration2')->nullable()->after('duration1');
            $table->integer('duration3')->nullable()->after('duration2');
            $table->integer('duration4')->nullable()->after('duration3');
            $table->integer('duration5')->nullable()->after('duration4');
            $table->integer('duration6')->nullable()->after('duration5');
            $table->string('rate_unit', 16)->default('session')->after('Rate');
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropColumn([
                'duration1', 'duration2', 'duration3',
                'duration4', 'duration5', 'duration6',
                'rate_unit',
            ]);
        });
    }
};
