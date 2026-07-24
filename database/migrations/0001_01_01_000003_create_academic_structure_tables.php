<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Academic structure — cycles, degrees, groups, shifts.
 *
 * Aggregate ownership: academic_cycles owns cycle_degrees → academic_groups
 * and cycle_shifts. Future enrollment references academic_group_code and
 * cycle shifts through explicit relations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_cycles', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('branch_code');
            $table->string('name', 120);
            $table->string('modality', 30);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('branch_code');
            $table->foreign('branch_code')
                ->references('code')
                ->on('branches')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE academic_cycles
            ADD CONSTRAINT academic_cycles_name_not_blank_check
            CHECK (btrim(name) <> ''),
            ADD CONSTRAINT academic_cycles_dates_order_check
            CHECK (end_date >= start_date),
            ADD CONSTRAINT academic_cycles_modality_check
            CHECK (modality IN ('regular', 'verano', 'intensivo', 'reforzamiento', 'virtual'))
            SQL);

        Schema::create('cycle_degrees', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('cycle_code');
            $table->smallInteger('number');
            $table->timestampsTz();

            $table->unique(['cycle_code', 'number']);
            $table->foreign('cycle_code')
                ->references('code')
                ->on('academic_cycles')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cycle_degrees
            ADD CONSTRAINT cycle_degrees_number_range_check
            CHECK (number BETWEEN 1 AND 6)
            SQL);

        Schema::create('academic_groups', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('cycle_degree_code');
            $table->string('name', 60);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('cycle_degree_code')
                ->references('code')
                ->on('cycle_degrees')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE academic_groups
            ADD CONSTRAINT academic_groups_name_not_blank_check
            CHECK (btrim(name) <> '')
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX academic_groups_degree_name_unique
            ON academic_groups (cycle_degree_code, lower(btrim(name)))
            SQL);

        Schema::create('cycle_shifts', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('cycle_code');
            $table->string('name', 60);
            $table->time('entry_time');
            $table->smallInteger('tolerance_minutes')->default(0);
            $table->smallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->foreign('cycle_code')
                ->references('code')
                ->on('academic_cycles')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cycle_shifts
            ADD CONSTRAINT cycle_shifts_name_not_blank_check
            CHECK (btrim(name) <> ''),
            ADD CONSTRAINT cycle_shifts_tolerance_not_negative_check
            CHECK (tolerance_minutes >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('cycle_shifts');
        Schema::dropIfExists('academic_groups');
        Schema::dropIfExists('cycle_degrees');
        Schema::dropIfExists('academic_cycles');
    }
};
