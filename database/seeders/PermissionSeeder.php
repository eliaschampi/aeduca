<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PermissionSeeder extends Seeder
{
    /**
     * Semantic permission catalog for the administrative foundation.
     *
     * @var array<string, string>
     */
    private const CATALOG = [
        'dashboard.view' => 'Acceder a la página de inicio.',
        'branches.view' => 'Ver las sedes.',
        'branches.manage' => 'Crear, editar y activar sedes.',
        'employees.view' => 'Ver los usuarios del personal.',
        'employees.manage' => 'Crear, editar y activar usuarios del personal.',
        'roles.view' => 'Ver los roles y el alcance de permisos.',
        'roles.manage' => 'Crear y editar roles y su alcance de permisos.',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::CATALOG as $name => $description) {
                Permission::query()->updateOrCreate(
                    ['name' => $name],
                    ['description' => $description],
                );
            }
        });
    }
}
