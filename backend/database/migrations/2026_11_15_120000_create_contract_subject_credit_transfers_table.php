<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** #535: append-only financial operation evidence, not another balance authority. New empty table only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_subject_credit_transfers', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('operation_key', 64)->unique();
            $t->string('request_fingerprint', 64);
            $t->unsignedInteger('campus_id');
            foreach (['student_id', 'source_student_class_id', 'template_student_class_id', 'target_student_class_id',
                'source_invoice_id', 'target_invoice_id', 'transfer_out_payment_id', 'transfer_in_payment_id', 'created_by'] as $column) {
                $t->unsignedBigInteger($column);
            }
            $t->unsignedInteger('sessions');
            $t->unsignedInteger('amount');
            $t->string('unit_price', 64);
            $t->unsignedInteger('lesson_minutes');
            $t->string('reason', 100);
            $t->json('source_before');
            $t->json('source_after');
            $t->json('target_after');
            $t->timestamp('reversed_at')->nullable();
            $t->unsignedBigInteger('reversed_by')->nullable();
            $t->string('reverse_reason', 100)->nullable();
            $t->unsignedBigInteger('reverse_out_payment_id')->nullable();
            $t->unsignedBigInteger('reverse_in_payment_id')->nullable();
            $t->timestamps();
            $t->index('source_student_class_id', 'subject_credit_source_idx');
            $t->index('target_student_class_id', 'subject_credit_target_idx');
            $t->index('template_student_class_id', 'subject_credit_template_idx');
            $t->index(['campus_id', 'created_at'], 'subject_credit_campus_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_subject_credit_transfers');
    }
};
