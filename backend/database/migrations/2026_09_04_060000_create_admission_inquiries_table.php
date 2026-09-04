<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admission_inquiries')) {
            return;
        }

        Schema::create('admission_inquiries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->index();
            $table->string('status', 32)->index();
            $table->text('parent_name');
            $table->text('parent_phone');
            $table->char('parent_phone_hash', 64);
            $table->text('student_name');
            $table->char('student_name_hash', 64);
            $table->string('grade', 16);
            $table->text('school_name')->nullable();
            $table->string('subject', 64);
            $table->json('preferred_slots')->nullable();
            $table->text('public_notes')->nullable();
            $table->text('staff_notes')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('trial_scheduled_at')->nullable();
            $table->timestamp('trial_completed_at')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->string('trial_result', 32)->nullable();
            $table->unsignedBigInteger('student_id')->nullable()->index();
            $table->unsignedBigInteger('trial_student_class_id')->nullable()->index();
            $table->unsignedBigInteger('enrolled_student_class_id')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['campus_id', 'parent_phone_hash', 'student_name_hash'],
                'admission_inquiries_identity_unique'
            );
            $table->index(['campus_id', 'status', 'created_at'], 'admission_inquiries_queue_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_inquiries');
    }
};
