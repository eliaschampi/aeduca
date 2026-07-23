<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Institution-wide student identity and its small, owned contact aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->string('dni', 8)->unique();
            $table->string('first_name', 50);
            $table->string('last_name', 80);
            $table->date('birth_date')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 150)->nullable();
            $table->text('observation')->nullable();
            $table->timestampsTz();

            $table->index(['created_at', 'code']);
            $table->index(['last_name', 'first_name', 'code']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE students
            ADD CONSTRAINT students_dni_format_check
            CHECK (dni ~ '^[0-9]{8}$'),
            ADD CONSTRAINT students_first_name_not_blank_check
            CHECK (btrim(first_name) <> ''),
            ADD CONSTRAINT students_last_name_not_blank_check
            CHECK (btrim(last_name) <> '')
            SQL);

        Schema::create('student_contacts', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('student_code');
            $table->smallInteger('position');
            $table->string('name', 150);
            $table->string('phone', 50)->nullable();
            $table->text('note')->nullable();

            $table->unique(['student_code', 'position']);
            $table->foreign('student_code')
                ->references('code')
                ->on('students')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE student_contacts
            ADD CONSTRAINT student_contacts_position_range_check
            CHECK (position BETWEEN 1 AND 2),
            ADD CONSTRAINT student_contacts_name_not_blank_check
            CHECK (btrim(name) <> '')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('student_contacts');
        Schema::dropIfExists('students');
    }
};
