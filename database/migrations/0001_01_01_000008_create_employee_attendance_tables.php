<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee schedules and entry facts — Coedula-style simple slots.
 *
 * One schedule row = one weekday window (entry→to) for a user in a branch.
 * A person may have many rows. Attendance attaches to one schedule + date.
 * Pending/falta are derived; never mass-inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $timezone = DB::connection()->getPdo()->quote(
            (string) config('aeduca.business_timezone', 'America/Lima'),
        );

        Schema::table('users', function (Blueprint $table) {
            $table->char('dni', 8)->nullable()->after('phone');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE users
            ADD CONSTRAINT users_dni_format_check
            CHECK (dni IS NULL OR dni ~ '^[0-9]{8}$')
            SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX users_dni_unique
            ON users (dni)
            WHERE dni IS NOT NULL
            SQL);

        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('user_code');
            $table->uuid('branch_code');
            $table->unsignedTinyInteger('weekday');
            $table->time('entry_time');
            $table->time('to_time');
            $table->uuid('created_by_user_code');
            $table->timestampsTz();

            $table->index(
                ['branch_code', 'weekday'],
                'employee_schedules_branch_weekday_index',
            );
            $table->index(
                ['user_code', 'branch_code'],
                'employee_schedules_user_branch_index',
            );
            // Same person + branch + weekday + window cannot be registered twice.
            $table->unique(
                ['user_code', 'branch_code', 'weekday', 'entry_time', 'to_time'],
                'employee_schedules_slot_unique',
            );
            $table->foreign('user_code')->references('code')->on('users')->restrictOnDelete();
            $table->foreign('branch_code')->references('code')->on('branches')->restrictOnDelete();
            $table->foreign('created_by_user_code')->references('code')->on('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employee_schedules
            ADD CONSTRAINT employee_schedules_weekday_check
            CHECK (weekday BETWEEN 1 AND 7),
            ADD CONSTRAINT employee_schedules_window_check
            CHECK (to_time > entry_time)
            SQL);

        Schema::create('employee_attendances', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('user_code');
            $table->uuid('branch_code');
            $table->uuid('schedule_code');
            $table->date('attendance_date');
            $table->string('state', 20);
            $table->time('entry_time');
            $table->text('observation')->nullable();
            $table->string('recording_method', 20);
            $table->uuid('created_by_user_code');
            $table->uuid('updated_by_user_code')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['schedule_code', 'attendance_date'],
                'employee_attendances_schedule_date_unique',
            );
            $table->index(
                ['branch_code', 'attendance_date'],
                'employee_attendances_daily_index',
            );
            $table->index(
                ['user_code', 'attendance_date'],
                'employee_attendances_history_index',
            );
            $table->foreign('user_code')->references('code')->on('users')->restrictOnDelete();
            $table->foreign('branch_code')->references('code')->on('branches')->restrictOnDelete();
            $table->foreign('schedule_code')
                ->references('code')
                ->on('employee_schedules')
                ->restrictOnDelete();
            $table->foreign('created_by_user_code')->references('code')->on('users')->restrictOnDelete();
            $table->foreign('updated_by_user_code')->references('code')->on('users')->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE employee_attendances
            ADD CONSTRAINT employee_attendances_state_check
            CHECK (state IN ('present', 'late', 'permission', 'justified')),
            ADD CONSTRAINT employee_attendances_method_check
            CHECK (recording_method IN ('scan', 'manual')),
            ADD CONSTRAINT employee_attendances_observation_check
            CHECK (observation IS NULL OR btrim(observation) <> '')
            SQL);

        // pending before window end on the business day; falta after to_time or past days.
        DB::statement(str_replace('__TZ__', $timezone, <<<'SQL'
            CREATE OR REPLACE FUNCTION employee_attendance_effective_state(
                stored_state text,
                attendance_date date,
                schedule_to_time time without time zone,
                reference_now timestamp with time zone
            ) RETURNS text
            LANGUAGE SQL
            IMMUTABLE
            PARALLEL SAFE
            AS $$
                SELECT CASE
                    WHEN stored_state IS NOT NULL THEN stored_state
                    WHEN (reference_now AT TIME ZONE __TZ__)::date > attendance_date THEN 'absent'
                    WHEN reference_now > (
                        (attendance_date + schedule_to_time) AT TIME ZONE __TZ__
                    ) THEN 'absent'
                    ELSE 'pending'
                END
            $$
            SQL));
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS employee_attendance_effective_state(text, date, time without time zone, timestamp with time zone)');
        Schema::dropIfExists('employee_attendances');
        Schema::dropIfExists('employee_schedules');
        DB::statement('DROP INDEX IF EXISTS users_dni_unique');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_dni_format_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dni');
        });
    }
};
