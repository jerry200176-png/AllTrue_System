<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('student_identity_groups')) {
            Schema::create('student_identity_groups', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('display_name', 128)->nullable();
                $table->string('status', 16)->default('active')->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('student_identity_members')) {
            Schema::create('student_identity_members', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('identity_group_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('campus_id');
                $table->string('status', 16)->default('active')->index();
                $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
                $table->timestamp('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by_user_id')->nullable()->index();
                $table->string('revoked_reason', 500)->nullable();
                $table->timestamps();

                $table->unique('student_id', 'student_identity_members_student_unique');
                $table->unique(
                    ['identity_group_id', 'student_id'],
                    'student_identity_members_group_student_unique'
                );
                $table->index(['identity_group_id', 'campus_id'], 'student_identity_members_group_campus_index');
                $table->index(['student_id', 'campus_id'], 'student_identity_members_student_campus_index');
            });
        }

        if (!Schema::hasTable('parent_cross_campus_access')) {
            Schema::create('parent_cross_campus_access', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('identity_group_id');
                $table->string('mode', 16)->default('off')->index();
                $table->unsignedBigInteger('updated_by_user_id')->nullable()->index();
                $table->timestamps();

                $table->unique('identity_group_id', 'parent_cross_campus_access_group_unique');
            });
        }

        if (!Schema::hasTable('student_identity_audit_logs')) {
            Schema::create('student_identity_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('identity_group_id')->nullable()->index();
                $table->unsignedBigInteger('student_id')->nullable()->index();
                $table->unsignedBigInteger('campus_id')->nullable()->index();
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->string('action', 32)->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('ParentSession') && !Schema::hasColumn('ParentSession', 'identity_group_id')) {
            Schema::table('ParentSession', function (Blueprint $table) {
                $table->unsignedBigInteger('identity_group_id')->nullable()->after('StudentID')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ParentSession') && Schema::hasColumn('ParentSession', 'identity_group_id')) {
            Schema::table('ParentSession', function (Blueprint $table) {
                $table->dropColumn('identity_group_id');
            });
        }

        Schema::dropIfExists('student_identity_audit_logs');
        Schema::dropIfExists('parent_cross_campus_access');
        Schema::dropIfExists('student_identity_members');
        Schema::dropIfExists('student_identity_groups');
    }
};
