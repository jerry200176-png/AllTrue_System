<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Empty audit storage for atomic existing-course allocations; balances stay in Invoice/Payment. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('existing_course_credit_allocation_groups', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('operation_key', 64)->unique();
            $t->string('request_fingerprint', 64);
            $t->unsignedInteger('campus_id');
            foreach (['student_id', 'source_student_class_id', 'source_invoice_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column);
            }
            $t->unsignedInteger('sessions');
            $t->unsignedInteger('amount');
            $t->string('reason', 100);
            foreach (['source_before', 'source_after', 'targets_before', 'targets_after', 'released_sessions'] as $column) {
                $t->json($column);
            }
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->string('reverse_reason', 100)->nullable();
            $t->timestamps();
            $t->index('source_student_class_id', 'existing_credit_source_idx');
            $t->index(['campus_id', 'created_at'], 'existing_credit_campus_created_idx');
        });
        Schema::table('contract_subject_credit_transfers', function (Blueprint $t) {
            $t->unsignedBigInteger('allocation_group_id')->nullable();
            $t->foreign('allocation_group_id', 'subject_credit_allocation_group_fk')
                ->references('id')->on('existing_course_credit_allocation_groups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_subject_credit_transfers', function (Blueprint $t) {
            $t->dropForeign('subject_credit_allocation_group_fk');
            $t->dropColumn('allocation_group_id');
        });
        Schema::dropIfExists('existing_course_credit_allocation_groups');
    }
};
