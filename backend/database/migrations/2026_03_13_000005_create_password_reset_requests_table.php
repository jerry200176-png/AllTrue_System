<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_requests')) {
            return;
        }

        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('account_input', 128);
            $table->string('role_input', 32)->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->string('requested_ip', 64)->nullable();
            $table->string('request_note', 255)->nullable();
            $table->unsignedInteger('handled_by')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['account_input', 'role_input', 'status'], 'pwd_reset_req_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
