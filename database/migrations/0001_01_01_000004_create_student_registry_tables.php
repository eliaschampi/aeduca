<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('students', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->char('dni', 8)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->date('birth_date')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 250)->nullable();
            $table->text('observation')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['is_active', 'created_at']);
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

        DB::statement(<<<'SQL'
            CREATE INDEX students_full_name_trgm_idx
            ON students
            USING gin (lower(first_name || ' ' || last_name) gin_trgm_ops)
            SQL);

        Schema::create('student_contacts', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->uuid('student_code');
            $table->string('name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('note', 250)->nullable();
            $table->timestampsTz();

            $table->index(['student_code', 'created_at']);
            $table->foreign('student_code')
                ->references('code')
                ->on('students')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE student_contacts
            ADD CONSTRAINT student_contacts_name_not_blank_check
            CHECK (btrim(name) <> '')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE auth_accounts
            ALTER COLUMN user_code DROP NOT NULL
            SQL);

        Schema::table('auth_accounts', function (Blueprint $table) {
            $table->uuid('student_code')->nullable()->after('user_code');
            $table->foreign('student_code')
                ->references('code')
                ->on('students')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auth_accounts
            DROP CONSTRAINT auth_accounts_user_code_unique
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX auth_accounts_user_owner_unique
            ON auth_accounts (user_code)
            WHERE user_code IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX auth_accounts_student_owner_unique
            ON auth_accounts (student_code)
            WHERE student_code IS NOT NULL
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE auth_accounts
            ADD CONSTRAINT auth_accounts_exactly_one_owner_check
            CHECK ((user_code IS NOT NULL)::integer + (student_code IS NOT NULL)::integer = 1)
            SQL);
    }

    public function down(): void
    {
        DB::table('auth_accounts')->whereNotNull('student_code')->delete();
        DB::statement(<<<'SQL'
            ALTER TABLE auth_accounts
            DROP CONSTRAINT IF EXISTS auth_accounts_exactly_one_owner_check
            SQL);
        DB::statement('DROP INDEX IF EXISTS auth_accounts_student_owner_unique');
        DB::statement('DROP INDEX IF EXISTS auth_accounts_user_owner_unique');

        Schema::table('auth_accounts', function (Blueprint $table) {
            $table->dropForeign(['student_code']);
            $table->dropColumn('student_code');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auth_accounts
            ALTER COLUMN user_code SET NOT NULL,
            ADD CONSTRAINT auth_accounts_user_code_unique UNIQUE (user_code)
            SQL);

        Schema::dropIfExists('student_contacts');
        Schema::dropIfExists('students');
    }
};
