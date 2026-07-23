# Informe de cierre — `codex/wrong1`

Fecha: 23 de julio de 2026

## 1. Resultado

La implementación realizada no alcanza el nivel visual ni de organización esperado para Aeduca v8. Aunque el vertical quedó técnicamente funcional y verificado, el resultado de interfaz se percibe como un downgrade: usa una composición genérica de Lumi, no reproduce con suficiente fidelidad la densidad, jerarquía, navegación y sensación de producto terminado de Coedula o Aeduca Admin.

Este trabajo se conserva como una dirección descartada en la rama `codex/wrong1`. No debe considerarse la referencia visual para continuar v8.

## 2. Evidencia inspeccionada

### Aeduca v8

- `README.md`, `AGENTS.md`, `docs/SPEC.md`, `docs/STATUS.md`.
- Autorización efectiva, scopes de roles, grants directos y superadministrador.
- Contexto de sede y navegación autenticada.
- Ciclos, grados, secciones y turnos.
- Formularios Lumi existentes de ciclos, roles, usuarios y estudiantes.
- Código, migraciones y pruebas del vertical de estudiantes.

### Aeduca Admin

Se revisó el flujo real legado de:

- ficha del estudiante;
- registro de matrícula desde el estudiante;
- selección de sección y turno;
- historial de matrículas;
- monto mensual, conceptos y vencimientos;
- códigos humanos de matrícula.

También se identificó deuda que no debía copiarse automáticamente:

- identificadores semánticos heredados;
- estados y prioridades poco explícitos;
- repositorios/cache innecesarios;
- relaciones reconstruidas desde códigos;
- estructura financiera acoplada al formulario legado.

### Coedula

Se revisó:

- perfil 30/70 con identidad lateral y contenido principal;
- pestaña principal de matrículas;
- tabla compacta de historial;
- workspace dedicado para crear/editar matrícula;
- separación visual entre asignación académica y pagos;
- selección de ciclo, grado, grupo y turno;
- desactivación de matrículas activas anteriores;
- creación atómica de matrícula y plan de pagos;
- formato monetario PEN;
- generación de código humano de cuatro dígitos.

No se copió literalmente:

- su arquitectura SvelteKit/repositorios, porque v8 usa Laravel + Inertia;
- sus grupos fijos A–D;
- sus enums de turno;
- su modelo completo de plan de pagos;
- componentes o estilos internos no públicos.

### Lumi

Se revisaron los contratos públicos y clases de:

- `PageHeader`, `Card`, `Tabs`, `Table`, `Chip`, `Dialog`;
- `Input`, `Select`, `Checkbox`, `Switch`, `Textarea`, `Fieldset`;
- `EmptyState`, `List`, `UserInfo`, `InfoItem`;
- layout público 30/70 y comportamiento responsive;
- utilidades públicas de grid, stack y flex.

## 3. Implementación realizada

### Estudiantes y contactos

- Directorio institucional con búsqueda y paginación.
- Crear/editar identidad del estudiante.
- Perfil canónico del estudiante.
- Contactos retirados del formulario principal.
- CRUD de contactos mediante diálogos dentro del perfil.
- Contactos ilimitados con posición asignada transaccionalmente.
- Limpieza del límite anterior de dos contactos.

Archivos principales:

- `app/Models/Student.php`
- `app/Models/StudentContact.php`
- `app/Actions/SaveStudent.php`
- `app/Actions/CreateStudentContact.php`
- `app/Http/Controllers/StudentController.php`
- `app/Http/Controllers/StudentContactController.php`
- `resources/js/Pages/Students/*`

### Matrículas y obligaciones iniciales

- Nuevas tablas `enrollments`, `enrollment_shifts` y `payment_obligations`.
- UUID como identidad técnica.
- Una matrícula activa por estudiante mediante índice parcial de PostgreSQL.
- Un código humano activo de cuatro dígitos.
- Una sección real mediante `academic_group_code`.
- Uno o dos turnos reales mediante tabla intermedia.
- Obligaciones con concepto, monto PEN y vencimiento.
- Creación/actualización transaccional.
- Desactivación de la matrícula activa anterior.
- Validación de sede autorizada y pertenencia de grupo/turnos al mismo ciclo.
- Historial de matrículas en el perfil.
- Formulario dedicado con pestañas Asignación académica / Obligaciones.
- Edición sin ruta de eliminación física.

Archivos principales:

- `database/migrations/0001_01_01_000006_create_enrollment_tables.php`
- `app/Models/Enrollment.php`
- `app/Models/PaymentObligation.php`
- `app/Actions/SaveEnrollment.php`
- `app/Http/Requests/EnrollmentRequest.php`
- `app/Http/Controllers/EnrollmentController.php`
- `resources/js/Pages/Enrollments/Form.svelte`
- `resources/js/Pages/Students/panels/StudentEnrollmentsPanel.svelte`

### Ciclos e historial

- Un grupo o turno ya usado por una matrícula se desactiva cuando es retirado del formulario del ciclo.
- Solo la estructura no referenciada se elimina.
- Esto evita borrar historial académico mediante una edición posterior del ciclo.

### Permisos

- Se agregaron `enrollments.view` y `enrollments.manage`.
- `enrollments.manage` incluye `enrollments.view`.
- `enrollments.view` incluye `students.view`, porque el punto de entrada es el perfil.
- Las dependencias se calculan en Laravel y se envían a las interfaces de roles y grants directos.
- Se retiró la duplicación de reglas de dependencia en Svelte.
- Se agregaron etiquetas españolas para Ciclos, Estudiantes y Matrículas.
- El catálogo local quedó con 13 permisos.
- El rol local Administración quedó confirmado con los 13 permisos en su scope.

### Limpieza técnica

- No se agregó otro sistema CSS.
- No se agregaron colores raw, estilos inline ni estilos locales de Aeduca.
- Se separaron los paneles de contactos y matrículas del `Show.svelte`.
- Se centralizó la fecha operativa de Lima en `BusinessCalendar`.
- El perfil calcula cantidad/suma de obligaciones en SQL, sin cargar todas las filas.
- Se conservaron identidades UUID de obligaciones existentes durante una edición.
- Se eliminaron payloads no usados del perfil.
- `TASK.md` quedó ausente conforme al cierre previo.

## 4. Por qué no se clonó Coedula o Aeduca Admin

### Restricciones obligatorias del repositorio

`AGENTS.md` ordena explícitamente:

- usar Aeduca Admin y Coedula como evidencia, no como plantillas;
- no inventar tablas, campos, permisos, paquetes, abstracciones o patrones UI;
- implementar solo el vertical activo;
- mantener Lumi neutral y usar únicamente su contrato público;
- no agregar un segundo sistema CSS;
- no modificar internals de Lumi por una necesidad de Aeduca;
- respetar `docs/SPEC.md` como autoridad de dominio.

Por tanto, una copia literal entraba en conflicto con el protocolo vigente.

### Diferencias de dominio y arquitectura

- Coedula usa SvelteKit y una capa de repositorios; v8 exige Laravel + Inertia con reglas y transacciones en Laravel.
- Coedula contiene decisiones de grupo/turno que contradicen el modelo relacional cerrado de v8.
- Aeduca Admin contiene códigos y estructura histórica que v8 prohíbe reconstruir.
- Coedula presenta un plan de pagos más completo; v8 todavía no tiene pagos, aplicaciones, caja ni reversos.
- Reproducir exactamente sus pantallas sin esos módulos crea botones, estados o paneles falsos, también prohibidos por el protocolo.

### Limitación visual real de esta ejecución

La mayor falla no fue técnica sino de dirección:

- se priorizaron invariantes, permisos, migraciones y pruebas antes de validar visualmente la composición;
- no se hizo una comparación side-by-side en navegador con Coedula;
- se interpretó “inspiración” de forma demasiado conservadora;
- el resultado usa componentes correctos, pero su composición sigue siendo genérica;
- la tabla de historial y el formulario no alcanzan la densidad, ritmo, jerarquía ni acabado de Coedula;
- una compilación exitosa no valida calidad visual;
- no hubo una ronda humana de aceptación antes de consolidar schema, UI y documentación.

## 5. Deficiencias y riesgos de esta rama

- El aspecto visual fue rechazado y no debe promoverse a `main`.
- La UX de matrículas no fue validada con operadores reales.
- No se ejecutó una inspección visual automatizada o captura responsive.
- El formulario permite editar obligaciones, pero aún no existe el ciclo completo de pagos/aplicaciones; esa semántica debe reconfirmarse antes de producción.
- La historia muestra sumas y cantidad de obligaciones, no saldos pagados porque pagos todavía no existen.
- El cambio directo de sede/grupo se permite entre sedes autorizadas; debe validarse contra el flujo operativo real.
- El código de matrícula es único entre matrículas activas, no globalmente en toda la historia.
- No existe tarjeta/QR, asistencia, caja, pagos, aplicaciones ni reversos.
- La última consulta local para contar ciclos/grupos/turnos fue interrumpida y no produjo resultado; no se confirmó que la base local tenga una oferta académica vigente utilizable.

## 6. Cambios realizados en bases locales

- `aeduca_test` fue reconstruida únicamente con:
  - `php artisan migrate:fresh --seed --env=testing`
- `aeduca` no fue destruida.
- En `aeduca` se aplicaron migraciones normales:
  - `000005_expand_student_contact_positions`
  - `000006_create_enrollment_tables`
- Se ejecutó `php artisan db:seed --force`.
- Se confirmó que el rol Administración tiene 13 permisos en su scope.

## 7. Verificación ejecutada

- `composer run format`: correcto.
- `composer run check`: 114 pruebas, 627 aserciones, Pint, TypeScript estricto, Oxlint y Prettier correctos.
- `pnpm run build`: build de producción correcto y sin warnings en la ejecución final.
- `php artisan migrate:fresh --seed --env=testing`: correcto.
- Pruebas enfocadas de matrícula, aislamiento de sede, reemplazo de matrícula activa, obligaciones, ownership anidado e historial: correctas.

Estas verificaciones prueban consistencia técnica; no corrigen el rechazo visual.

## 8. Qué tendría que cambiar antes de reintentar

Para buscar fidelidad real con Coedula/Aeduca Admin se necesita una decisión de producto explícita que:

1. indique cuál pantalla exacta es la referencia visual;
2. priorice fidelidad visual sobre la regla actual de “evidencia, no plantilla”;
3. autorice los cambios necesarios en Lumi o un contrato público adicional;
4. cierre primero la semántica de plan de pagos, pagos y saldos;
5. defina qué información debe aparecer en cada fila, resumen y acción;
6. exija comparación side-by-side en desktop, tablet y móvil antes de tocar schema adicional;
7. haga una aprobación visual temprana antes de continuar con backend y documentación.

## 9. Estado de Git

- Rama de descarte: `codex/wrong1`.
- El cambio preexistente en `resources/js/styles/lumi-theme.css` no pertenece a esta implementación y debe preservarse fuera de cualquier commit de cierre de Codex.
- Este informe documenta el trabajo para que no se repita la misma dirección sin revisar primero las decisiones anteriores.
