<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddNextWeekTestScopeToLearningRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->text('NextWeekTestScope')->nullable()->after('NextHomework');
        });
    }

    public function down()
    {
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->dropColumn('NextWeekTestScope');
        });
    }
}
