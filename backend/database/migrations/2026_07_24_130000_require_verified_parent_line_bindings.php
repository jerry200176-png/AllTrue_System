<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0 privacy containment.
 *
 * Historical LINE bindings did not record whether the student relationship was
 * proven with a contact phone. They must remain untrusted until the parent
 * re-binds through the verified flow. Existing parent sessions are expired so
 * a previously mis-bound account cannot continue using an issued token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_line_bindings', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('bound_at');
            $table->string('verification_method', 32)->nullable()->after('verified_at');
            $table->index('verified_at', 'slb_verified_at_idx');
        });

        if (Schema::hasTable('ParentSession')) {
            DB::table('ParentSession')
                ->where('ExpiresAt', '>', now())
                ->update(['ExpiresAt' => now()]);
        }
    }

    public function down(): void
    {
        Schema::table('student_line_bindings', function (Blueprint $table) {
            $table->dropIndex('slb_verified_at_idx');
            $table->dropColumn(['verified_at', 'verification_method']);
        });
    }
};
