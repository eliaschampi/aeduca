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
        'cycles.view' => 'Ver los ciclos académicos de la sede actual.',
        'cycles.manage' => 'Crear y editar ciclos, grados, secciones y turnos.',
        'employees.view' => 'Ver los usuarios del personal.',
        'employees.manage' => 'Crear, editar y activar usuarios del personal.',
        'roles.view' => 'Ver los roles y el alcance de permisos.',
        'roles.manage' => 'Crear y editar roles y su alcance de permisos.',
        'students.view' => 'Ver el directorio y las fichas de estudiantes.',
        'students.manage' => 'Crear y editar estudiantes y sus contactos.',
        'enrollments.view' => 'Ver las matrículas y obligaciones de estudiantes.',
        'enrollments.manage' => 'Crear y editar matrículas y sus obligaciones.',
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
