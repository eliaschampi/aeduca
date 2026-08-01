<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Student attendance facts only.
 * Expected students and pending/absent are derived from enrollment + shift clocks.
 */
return new class extends Migration
{
    public function up(): void
    {
        $timezone = DB::connection()->getPdo()->quote(
            (string) config('aeduca.business_timezone', 'America/Lima'),
        );

        Schema::create('student_attendances', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('enrollment_code');
            $table->uuid('cycle_shift_code');
            $table->date('attendance_date');
            $table->string('state', 20);
            $table->timestampTz('arrival_at')->nullable();
            $table->string('recording_method', 20);
            $table->uuid('created_by_user_code');
            $table->text('reason')->nullable();
            $table->uuid('corrected_by_user_code')->nullable();
            $table->timestampTz('corrected_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['enrollment_code', 'cycle_shift_code', 'attendance_date'],
                'student_attendances_enrollment_shift_date_unique',
            );
            $table->index(
                ['attendance_date', 'cycle_shift_code'],
                'student_attendances_daily_shift_index',
            );
            $table->index(
                ['enrollment_code', 'attendance_date'],
                'student_attendances_history_index',
            );
            $table->foreign(['enrollment_code', 'cycle_shift_code'])
                ->references(['enrollment_code', 'cycle_shift_code'])
                ->on('enrollment_shifts')
                ->restrictOnDelete();
            $table->foreign('created_by_user_code')
                ->references('code')
                ->on('users')
                ->restrictOnDelete();
            $table->foreign('corrected_by_user_code')
                ->references('code')
                ->on('users')
                ->restrictOnDelete();
        });

        DB::statement(str_replace('__TZ__', $timezone, <<<'SQL'
            ALTER TABLE student_attendances
            ADD CONSTRAINT student_attendances_state_check
            CHECK (state IN ('present', 'late', 'permission', 'justified')),
            ADD CONSTRAINT student_attendances_method_check
            CHECK (recording_method IN ('scan', 'manual')),
            ADD CONSTRAINT student_attendances_instructional_day_check
            CHECK (EXTRACT(ISODOW FROM attendance_date) BETWEEN 1 AND 6),
            ADD CONSTRAINT student_attendances_scan_state_check
            CHECK (recording_method <> 'scan' OR state IN ('present', 'late')),
            ADD CONSTRAINT student_attendances_scan_metadata_check
            CHECK (
                recording_method <> 'scan'
                OR (reason IS NULL AND corrected_by_user_code IS NULL AND corrected_at IS NULL)
            ),
            ADD CONSTRAINT student_attendances_arrival_check
            CHECK (
                (state IN ('present', 'late') AND arrival_at IS NOT NULL)
                OR (state IN ('permission', 'justified') AND arrival_at IS NULL)
            ),
            ADD CONSTRAINT student_attendances_arrival_date_check
            CHECK (
                arrival_at IS NULL
                OR (arrival_at AT TIME ZONE __TZ__)::date = attendance_date
            ),
            ADD CONSTRAINT student_attendances_reason_not_blank_check
            CHECK (reason IS NULL OR btrim(reason) <> ''),
            ADD CONSTRAINT student_attendances_reason_context_check
            CHECK (
                reason IS NULL
                OR state IN ('permission', 'justified')
                OR corrected_by_user_code IS NOT NULL
            ),
            ADD CONSTRAINT student_attendances_reason_required_check
            CHECK (
                (state IN ('present', 'late') AND corrected_by_user_code IS NULL AND reason IS NULL)
                OR reason IS NOT NULL
            ),
            ADD CONSTRAINT student_attendances_correction_pair_check
            CHECK (
                (corrected_by_user_code IS NULL AND corrected_at IS NULL)
                OR (corrected_by_user_code IS NOT NULL AND corrected_at IS NOT NULL)
            )
            SQL));

        DB::statement(str_replace('__TZ__', $timezone, <<<'SQL'
            CREATE OR REPLACE FUNCTION student_attendance_effective_state(
                stored_state text,
                attendance_date date,
                entry_time time without time zone,
                tolerance_minutes integer,
                reference_now timestamp with time zone
            ) RETURNS text
            LANGUAGE SQL
            IMMUTABLE
            PARALLEL SAFE
            AS $$
                SELECT CASE
                    WHEN stored_state IS NOT NULL THEN stored_state
                    WHEN reference_now > (
                        attendance_date + entry_time
                        + make_interval(mins => GREATEST(COALESCE(tolerance_minutes, 0), 0))
                    ) AT TIME ZONE __TZ__
                        THEN 'absent'
                    ELSE 'pending'
                END
            $$
            SQL));
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS student_attendance_effective_state(text, date, time without time zone, integer, timestamp with time zone)');
        Schema::dropIfExists('student_attendances');
    }
};
