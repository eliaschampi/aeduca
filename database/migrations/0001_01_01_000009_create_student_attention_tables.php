<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_attentions', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('student_code');
            $table->uuid('branch_code');
            $table->string('type', 32);
            $table->string('reason', 100);
            $table->text('development');
            $table->text('conclusion');
            $table->timestampTz('occurred_at');
            $table->uuid('created_by_user_code');
            $table->uuid('updated_by_user_code')->nullable();
            $table->timestampsTz();

            $table->index(
                ['student_code', 'branch_code', 'occurred_at', 'code'],
                'student_attentions_history_index',
            );
            $table->index(
                ['branch_code', 'occurred_at', 'code'],
                'student_attentions_branch_history_index',
            );
            $table->foreign('student_code')->references('code')->on('students')->restrictOnDelete();
            $table->foreign('branch_code')->references('code')->on('branches')->restrictOnDelete();
            $table->foreign('created_by_user_code')->references('code')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_code')->references('code')->on('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE student_attentions
            ADD CONSTRAINT student_attentions_type_check
            CHECK (type IN ('medical', 'conduct', 'attention', 'search', 'attendance_permission', 'other')),
            ADD CONSTRAINT student_attentions_reason_not_blank_check
            CHECK (btrim(reason) <> ''),
            ADD CONSTRAINT student_attentions_development_not_blank_check
            CHECK (btrim(development) <> ''),
            ADD CONSTRAINT student_attentions_conclusion_not_blank_check
            CHECK (btrim(conclusion) <> '')
            SQL);

        Schema::create('student_attention_files', function (Blueprint $table) {
            $table->uuid('student_attention_code');
            $table->uuid('drive_file_code');
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['student_attention_code', 'drive_file_code']);
            $table->index('drive_file_code');
            $table->foreign('student_attention_code')
                ->references('code')
                ->on('student_attentions')
                ->cascadeOnDelete();
            $table->foreign('drive_file_code')
                ->references('code')
                ->on('drive_files')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_attention_files');
        Schema::dropIfExists('student_attentions');
    }
};
