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
     * @var array<string, array<string, string>>
     */
    private const CATALOG = [
        'Alumnos' => [
            'students.view' => 'Ver el directorio y los perfiles de alumnos.',
            'students.manage' => 'Registrar y editar alumnos, contactos y acceso.',
            'students.delete' => 'Eliminar alumnos sin historial de matrículas.',
            'student_attentions.view' => 'Ver el historial de atenciones de alumnos.',
            'student_attentions.manage' => 'Registrar y editar atenciones y sus archivos vinculados.',
        ],
        'Asistencia' => [
            'attendance.view' => 'Ver la lista diaria y el historial de asistencia.',
            'attendance.manage' => 'Escanear, registrar y corregir asistencia estudiantil.',
            'employee_attendance.view' => 'Ver el control horario y el historial del personal.',
            'employee_attendance.manage' => 'Registrar, editar y configurar horarios del personal.',
        ],
        'Ciclos' => [
            'cycles.view' => 'Ver los ciclos académicos de la sede actual.',
            'cycles.manage' => 'Crear y editar ciclos, grados, secciones y turnos.',
        ],
        'Inicio' => [
            'dashboard.view' => 'Acceder a la página de inicio.',
        ],
        'Drive' => [
            'drive.manage' => 'Usar el Drive personal y compartir archivos con otros usuarios.',
        ],
        'Matrículas' => [
            'enrollments.view' => 'Ver matrículas e inscritos de la sede actual.',
            'enrollments.manage' => 'Crear y editar matrículas vigentes.',
            'enrollments.delete' => 'Eliminar matrículas sin pagos ni asistencia registrada de la sede actual.',
        ],
        'Roles' => [
            'roles.view' => 'Ver los roles y el alcance de permisos.',
            'roles.manage' => 'Crear y editar roles y su alcance de permisos.',
        ],
        'Sedes' => [
            'branches.view' => 'Ver las sedes.',
            'branches.manage' => 'Crear, editar y activar sedes.',
        ],
        'Usuarios' => [
            'employees.view' => 'Ver los usuarios del personal.',
            'employees.manage' => 'Crear, editar y activar usuarios del personal.',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::CATALOG as $groupLabel => $permissions) {
                foreach ($permissions as $name => $description) {
                    Permission::query()->updateOrCreate(
                        ['name' => $name],
                        [
                            'group_label' => $groupLabel,
                            'description' => $description,
                        ],
                    );
                }
            }
        });
    }
}
