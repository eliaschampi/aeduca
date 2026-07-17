<?php

namespace Database\Seeders;

use App\Models\AuthAccount;
use App\Models\Branch;
use App\Models\EmployeeRole;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Semantic permission catalog for the administrative foundation.
     *
     * @var array<string, string>
     */
    private const PERMISSION_CATALOG = [
        'dashboard.view' => 'Acceder a la página de inicio.',
        'branches.view' => 'Ver las sedes.',
        'branches.manage' => 'Crear, editar y activar sedes.',
        'employees.view' => 'Ver los usuarios del personal.',
        'employees.manage' => 'Crear, editar y activar usuarios del personal.',
        'roles.view' => 'Ver los roles y sus permisos.',
        'roles.manage' => 'Crear, editar roles y asignar permisos.',
    ];

    public function run(): void
    {
        $login = Str::lower(trim((string) config('aeduca.seed_admin.login')));
        $password = (string) config('aeduca.seed_admin.password');

        if ($login === '' || $password === '') {
            throw new RuntimeException(
                'Define AEDUCA_SEED_ADMIN_LOGIN y AEDUCA_SEED_ADMIN_PASSWORD antes de ejecutar el seeder.',
            );
        }

        DB::transaction(function () use ($login, $password): void {
            $branch = Branch::query()->create([
                'name' => 'Sede principal',
                'is_active' => true,
            ]);

            $role = EmployeeRole::query()->create([
                'name' => 'Administración',
                'description' => 'Acceso administrativo inicial.',
                'is_active' => true,
            ]);

            $permissions = collect(self::PERMISSION_CATALOG)->map(
                fn (string $description, string $name): Permission => Permission::query()->create([
                    'name' => $name,
                    'description' => $description,
                ]),
            );

            $role->permissions()->attach($permissions->pluck('code'));

            $employee = User::query()->create([
                'first_name' => 'Administrador',
                'last_name' => 'Aeduca',
                'employee_role_code' => $role->code,
                'is_active' => true,
                'is_super_admin' => true,
            ]);

            $employee->branches()->attach($branch);

            AuthAccount::query()->create([
                'login' => $login,
                'password' => $password,
                'user_code' => $employee->code,
                'is_active' => true,
            ]);
        });
    }
}
