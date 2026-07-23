<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enrollment assigns a student to one concrete academic group and selected
 * shifts. Initial obligations are financial facts created with that assignment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('student_code');
            $table->uuid('academic_group_code');
            $table->string('roll_code', 4);
            $table->boolean('is_active')->default(true);
            $table->text('observation')->nullable();
            $table->timestampsTz();

            $table->index('student_code');
            $table->index('academic_group_code');
            $table->index(['created_at', 'code']);
            $table->foreign('student_code')
                ->references('code')
                ->on('students')
                ->restrictOnDelete();
            $table->foreign('academic_group_code')
                ->references('code')
                ->on('academic_groups')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE enrollments
            ADD CONSTRAINT enrollments_roll_code_format_check
            CHECK (roll_code ~ '^[0-9]{4}$')
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX enrollments_one_active_per_student_unique
            ON enrollments (student_code)
            WHERE is_active
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX enrollments_active_roll_code_unique
            ON enrollments (roll_code)
            WHERE is_active
            SQL);

        Schema::create('enrollment_shifts', function (Blueprint $table) {
            $table->uuid('enrollment_code');
            $table->uuid('cycle_shift_code');

            $table->primary(['enrollment_code', 'cycle_shift_code']);
            $table->index('cycle_shift_code');
            $table->foreign('enrollment_code')
                ->references('code')
                ->on('enrollments')
                ->cascadeOnDelete();
            $table->foreign('cycle_shift_code')
                ->references('code')
                ->on('cycle_shifts')
                ->restrictOnDelete();
        });

        Schema::create('payment_obligations', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('enrollment_code');
            $table->string('concept', 150);
            $table->decimal('amount', 12, 2);
            $table->date('due_date');
            $table->timestampsTz();

            $table->index(['enrollment_code', 'due_date']);
            $table->foreign('enrollment_code')
                ->references('code')
                ->on('enrollments')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE payment_obligations
            ADD CONSTRAINT payment_obligations_concept_not_blank_check
            CHECK (btrim(concept) <> ''),
            ADD CONSTRAINT payment_obligations_amount_positive_check
            CHECK (amount > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_obligations');
        Schema::dropIfExists('enrollment_shifts');
        Schema::dropIfExists('enrollments');
    }
};
