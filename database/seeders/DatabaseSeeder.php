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
    public function run(): void
    {
        $this->call(PermissionSeeder::class);

        $login = Str::lower(trim((string) config('aeduca.seed_admin.login')));
        $password = (string) config('aeduca.seed_admin.password');

        if ($login === '' && $password === '') {
            return;
        }

        if ($login === '' || $password === '') {
            throw new RuntimeException(
                'Define juntos AEDUCA_SEED_ADMIN_LOGIN y AEDUCA_SEED_ADMIN_PASSWORD.',
            );
        }

        DB::transaction(function () use ($login, $password): void {
            $existingAccount = AuthAccount::query()
                ->with('user')
                ->where('login', $login)
                ->first();

            if ($existingAccount) {
                if (! $existingAccount->user?->is_super_admin) {
                    throw new RuntimeException(
                        "El login de bootstrap «{$login}» ya pertenece a otro usuario.",
                    );
                }

                $existingAccount->user->employeeRole->permissionScopes()->syncWithoutDetaching(
                    Permission::query()->pluck('code'),
                );

                return;
            }

            $branch = Branch::query()->firstOrCreate(
                ['name' => 'Sede principal'],
                ['is_active' => true],
            );

            $role = EmployeeRole::query()->firstOrCreate(
                ['name' => 'Administración'],
                [
                    'description' => 'Categoría administrativa. El alcance define permisos asignables, no grants automáticos.',
                    'is_active' => true,
                ],
            );

            $role->permissionScopes()->syncWithoutDetaching(
                Permission::query()->pluck('code'),
            );

            $employee = User::query()->create([
                'first_name' => 'Administrador',
                'last_name' => 'Aeduca',
                'employee_role_code' => $role->code,
                'is_active' => true,
                'is_super_admin' => true,
            ]);

            $employee->branches()->attach($branch);
            // Superadministrator: no user_permissions rows required.

            AuthAccount::query()->create([
                'login' => $login,
                'password' => $password,
                'user_code' => $employee->code,
                'is_active' => true,
            ]);
        });
    }
}
