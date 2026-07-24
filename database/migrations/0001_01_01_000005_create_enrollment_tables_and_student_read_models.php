<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('student_code');
            $table->uuid('cycle_code');
            $table->uuid('academic_group_code');
            $table->char('roll_code', 4);
            $table->boolean('is_active')->default(true);
            $table->text('observation')->nullable();
            $table->timestampsTz();

            $table->index(['student_code', 'created_at']);
            $table->index(['academic_group_code', 'is_active']);
            $table->unique(['student_code', 'cycle_code']);
            $table->unique(['cycle_code', 'roll_code']);
            $table->foreign('student_code')
                ->references('code')
                ->on('students')
                ->restrictOnDelete();
            $table->foreign('cycle_code')
                ->references('code')
                ->on('academic_cycles')
                ->restrictOnDelete();
            $table->foreign('academic_group_code')
                ->references('code')
                ->on('academic_groups')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            CREATE SEQUENCE IF NOT EXISTS enrollment_roll_code_seq
            MINVALUE 1
            MAXVALUE 9999
            START WITH 1
            INCREMENT BY 1
            CYCLE
            CACHE 1
            OWNED BY enrollments.roll_code
            SQL);
        DB::statement('ALTER SEQUENCE enrollment_roll_code_seq OWNED BY enrollments.roll_code');
        DB::statement('ALTER SEQUENCE enrollment_roll_code_seq RESTART WITH 1');

        DB::statement(<<<'SQL'
            ALTER TABLE enrollments
            ADD CONSTRAINT enrollments_roll_code_format_check
            CHECK (roll_code ~ '^[0-9]{4}$')
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

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION reserve_enrollment_roll_code(target_cycle_code uuid)
            RETURNS char(4)
            LANGUAGE plpgsql
            AS $$
            DECLARE
                candidate char(4);
                attempts integer := 0;
            BEGIN
                PERFORM pg_advisory_xact_lock(
                    hashtext('aeduca.enrollment_roll_code.' || target_cycle_code::text)
                );

                WHILE attempts < 9999 LOOP
                    candidate := lpad(nextval('enrollment_roll_code_seq')::text, 4, '0');

                    IF NOT EXISTS (
                        SELECT 1
                        FROM enrollments
                        WHERE cycle_code = target_cycle_code
                          AND roll_code = candidate
                    ) THEN
                        RETURN candidate;
                    END IF;

                    attempts := attempts + 1;
                END LOOP;

                RAISE EXCEPTION 'No hay códigos de matrícula disponibles'
                    USING ERRCODE = 'P0001';
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW student_enrollment_overview AS
            SELECT
                e.code AS enrollment_code,
                e.student_code,
                e.cycle_code,
                e.roll_code,
                e.is_active,
                CASE
                    WHEN c.end_date < (CURRENT_TIMESTAMP AT TIME ZONE 'America/Lima')::date
                        THEN 'finalized'
                    WHEN e.is_active = false THEN 'inactive'
                    ELSE 'active'
                END AS enrollment_status,
                e.observation,
                e.created_at,
                g.code AS academic_group_code,
                g.name AS group_name,
                d.number AS degree_number,
                c.name AS cycle_name,
                c.branch_code,
                b.name AS branch_name,
                COALESCE(shifts.names, '') AS shift_names
            FROM enrollments e
            INNER JOIN academic_groups g ON g.code = e.academic_group_code
            INNER JOIN cycle_degrees d ON d.code = g.cycle_degree_code
            INNER JOIN academic_cycles c
                ON c.code = e.cycle_code
                AND c.code = d.cycle_code
            INNER JOIN branches b ON b.code = c.branch_code
            LEFT JOIN LATERAL (
                SELECT string_agg(cs.name, ' · ' ORDER BY cs.sort_order, cs.name) AS names
                FROM enrollment_shifts es
                INNER JOIN cycle_shifts cs ON cs.code = es.cycle_shift_code
                WHERE es.enrollment_code = e.code
            ) shifts ON true
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW student_directory AS
            SELECT
                s.code AS student_code,
                s.dni,
                s.first_name,
                s.last_name,
                btrim(s.first_name || ' ' || s.last_name) AS full_name,
                s.photo_path,
                s.is_active AS student_is_active,
                s.created_at AS student_created_at,
                latest.roll_code,
                latest.is_active AS enrollment_is_active,
                latest.enrollment_status,
                latest.group_name,
                latest.degree_number,
                latest.cycle_name,
                latest.branch_name
            FROM students s
            LEFT JOIN LATERAL (
                SELECT
                    seo.roll_code,
                    seo.is_active,
                    seo.enrollment_status,
                    seo.group_name,
                    seo.degree_number,
                    seo.cycle_name,
                    seo.branch_name,
                    seo.created_at
                FROM student_enrollment_overview seo
                WHERE seo.student_code = s.code
                ORDER BY
                    CASE seo.enrollment_status
                        WHEN 'active' THEN 0
                        WHEN 'inactive' THEN 1
                        ELSE 2
                    END,
                    seo.created_at DESC
                LIMIT 1
            ) latest ON true
            SQL);

        DB::statement(<<<'SQL'
            CREATE VIEW student_roster AS
            SELECT
                seo.enrollment_code,
                seo.student_code,
                s.dni,
                s.first_name,
                s.last_name,
                btrim(s.first_name || ' ' || s.last_name) AS full_name,
                s.photo_path,
                s.is_active AS student_is_active,
                seo.roll_code,
                seo.enrollment_status,
                seo.academic_group_code,
                seo.group_name,
                seo.degree_number,
                seo.cycle_code,
                seo.cycle_name,
                seo.branch_code,
                seo.shift_names
            FROM student_enrollment_overview seo
            INNER JOIN students s ON s.code = seo.student_code
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS student_roster');
        DB::statement('DROP VIEW IF EXISTS student_directory');
        DB::statement('DROP VIEW IF EXISTS student_enrollment_overview');
        DB::statement('DROP FUNCTION IF EXISTS reserve_enrollment_roll_code(uuid)');
        Schema::dropIfExists('enrollment_shifts');
        Schema::dropIfExists('enrollments');
        DB::statement('DROP SEQUENCE IF EXISTS enrollment_roll_code_seq');
    }
};
