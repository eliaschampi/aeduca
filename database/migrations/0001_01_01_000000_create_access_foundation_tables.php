<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('employee_roles', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->string('name', 100);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->string('name', 100)->unique();
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE permissions
            ADD CONSTRAINT permissions_name_format_check
            CHECK (name ~ '^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$')
            SQL);

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('employee_role_code');
            $table->uuid('permission_code');

            $table->primary(['employee_role_code', 'permission_code']);
            $table->index('permission_code');
            $table->foreign('employee_role_code')
                ->references('code')
                ->on('employee_roles')
                ->cascadeOnDelete();
            $table->foreign('permission_code')
                ->references('code')
                ->on('permissions')
                ->cascadeOnDelete();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254)->nullable();
            $table->string('phone', 30)->nullable();
            $table->uuid('employee_role_code');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_super_admin')->default(false);
            $table->timestampsTz();

            $table->index('employee_role_code');
            $table->foreign('employee_role_code')
                ->references('code')
                ->on('employee_roles')
                ->restrictOnDelete();
        });

        Schema::create('user_branches', function (Blueprint $table) {
            $table->uuid('user_code');
            $table->uuid('branch_code');

            $table->primary(['user_code', 'branch_code']);
            $table->index('branch_code');
            $table->foreign('user_code')
                ->references('code')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('branch_code')
                ->references('code')
                ->on('branches')
                ->cascadeOnDelete();
        });

        Schema::create('user_permissions', function (Blueprint $table) {
            $table->uuid('user_code');
            $table->uuid('permission_code');
            $table->boolean('is_allowed');

            $table->primary(['user_code', 'permission_code']);
            $table->index('permission_code');
            $table->foreign('user_code')
                ->references('code')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('permission_code')
                ->references('code')
                ->on('permissions')
                ->cascadeOnDelete();
        });

        Schema::create('auth_accounts', function (Blueprint $table) {
            $table->uuid('code')->primary();
            $table->string('login', 100)->unique();
            $table->string('password');
            $table->uuid('user_code')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_code')
                ->references('code')
                ->on('users')
                ->cascadeOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE auth_accounts
            ADD CONSTRAINT auth_accounts_login_normalized_check
            CHECK (login = lower(btrim(login)) AND login <> '')
            SQL);

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();

            $table->foreign('user_id')
                ->references('code')
                ->on('auth_accounts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('auth_accounts');
        Schema::dropIfExists('user_permissions');
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('users');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('employee_roles');
        Schema::dropIfExists('branches');
    }
};
