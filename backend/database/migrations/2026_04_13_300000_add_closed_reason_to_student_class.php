<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddClosedReasonToStudentClass extends Migration
{
    public function up()
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->string('closed_reason', 20)->nullable()->after('Stop');
        });
    }

    public function down()
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropColumn('closed_reason');
        });
    }
}
