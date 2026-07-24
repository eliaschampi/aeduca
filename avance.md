# AVANCE — Investigación para la vertical de Alumnos (TASK.md)

> Estado: **investigación**, sin cambios de código. Este archivo recoge lo leído y lo
> concluido hasta ahora para retomar sin repetir trabajo. La verdad permanente sigue en
> `docs/SPEC.md`; la aceptación en `TASK.md`.

## 1. Objetivo de la tarea (resumen)

Implementar el recorrido estudiantil completo de Aeduca v8:

```
buscar → crear → perfilar → habilitar acceso → matricular → listar por sección → autoservicio
```

Upgrade de v7, no downgrade. Extender los propietarios de v8 (AuthAccount, permisos,
BranchContext, Actions, FormRequests, Laravel/Inertia, Svelte, Lumi). Nada de segunda
arquitectura, código concatenado, año global ni contraseñas reversibles.

## 2. Documentación ya leída

- `TASK.md` — completo. Define esquema mínimo, propietarios, entradas, permisos, acceso,
  matrícula, carnet, pruebas y condiciones de parada.
- `AGENTS.md` — completo. Protocolo de ejecución y baseline de arquitectura.
- `docs/SPEC.md` — completo. Verdad permanente de producto/dominio/datos.
- `docs/STATUS.md` — completo. Estado verificado actual (23 jul 2026).

**Pendiente de leer (evidencia externa, aún NO inspeccionada):**

- Coedula: `/students/search`, `/students`, perfil, cuenta estudiantil, reset temporal,
  matrícula, carnet. Ruta: `/home/qorilux/Documents/coedula`.
- v7: `Finder.vue`, `Student/Main.vue`, `Student/History.vue`, `Student/Family.vue`,
  `MainWrapper.vue`, `Aeduca.vue`, `Section/Student.vue`, carnet PDF.
  Rutas candidatas: `/home/qorilux/Documents/v7 aeduca main`, `/home/qorilux/Documents/v7core`.
- Lumi: docs en `/home/qorilux/Documents/lumi-ui/docs` (AGENT_GUIDE, COMPONENTS,
  COMPONENT_AUTHORING, CONSUMER_REFERENCE, GUIDE, README). Falta identificar los
  componentes públicos concretos (Dialog, Tabs, Table/DataTable, PageSidebar/drawer,
  Avatar, campos de formulario) que se usarán en perfil y roster.
- Nextya: solo relevante para OMR/evaluaciones — fuera de alcance de esta tarea.

## 3. Arquitectura v8 existente (verificada leyendo el código)

### Estructura de carpetas

```
app/Actions/            AuthenticateEmployee, CreateEmployee, LogoutEmployee, SaveBranch,
                        SaveCycle, SaveRole, SelectBranch, SyncUserPermissions, UpdateEmployee
app/Http/Controllers/   Admin/{Branch,Cycle,Employee,Role}Controller, AuthController,
                        BranchController, HomeController
app/Http/Middleware/    EnsureActiveAccount, HandleInertiaRequests
app/Http/Requests/      Branch, ChangeEmployeePassword, Cycle, Login, Role,
                        SelectBranch, StoreEmployee, SyncUserPermissions,
                        UpdateEmployeeAccess, UpdateEmployee
app/Models/             AcademicCycle, AcademicGroup, AuthAccount, Branch, CycleDegree,
                        CycleShift, EmployeeRole, Permission, User
app/Support/Academic/       CycleModality, DegreeNumber
app/Support/Authorization/  PermissionDependency, PermissionResolver
app/Support/Branches/       BranchContext
resources/js/Pages/     Admin/Employees(+panels), Admin/Roles, Auth, Branches, Cycles, Home
resources/js/lib/       color-scheme, inertia-links, navigation, permissions
resources/js/Layouts/   DashboardLayout.svelte
database/migrations/    000000 access foundation, 000003 academic structure (+cache, jobs)
database/seeders/       DatabaseSeeder, PermissionSeeder
```

### Convenciones confirmadas en código

- **PK/FK:** UUID `code` como PK; FK `<entidad>_code`. `HasUuids` en modelos.
- **Migraciones:** una sola baseline limpia (datos dev desechables). CHECK/UNIQUE vía
  `DB::statement` con SQL crudo (p.ej. `permissions_name_format_check`,
  `academic_groups_degree_name_unique` como índice único funcional sobre
  `lower(btrim(name))`). `timestampsTz()`, FK con `cascadeOnDelete`/`restrictOnDelete`.
- **Controladores:** delgados. Resuelven sede vía `BranchContext->currentBranch()`,
  autorizan con `Gate::check()`, mapean columnas explícitas a arrays, `Inertia::render`.
  Flash con `Inertia::flash('success', ...)` + `to_route(...)`.
- **Actions:** solo para escrituras agregadas/transaccionales con invariantes reales
  (`SaveCycle` usa `DB::transaction`, sincroniza shifts/degrees/groups con patrón
  keep-codes + `whereNotIn(...)->delete()`). CRUD simple de una fila queda directo.
- **FormRequests:** `authorize(): true` (la ruta ya autoriza con `can:`), `rules()`,
  `after()` para invariantes cross-field, `messages()` en español. Normalización de
  input (trim, lower) se hace en el controlador/action, no solo en el request.
- **Permisos:** catálogo semántico en `PermissionSeeder::CATALOG` (`dominio.view` /
  `dominio.manage`). `PermissionDependency::expandNames` fuerza que `manage` incluya
  `view`. `PermissionResolver` = grants directos ∩ scope del rol; super_admin = todos.
  Cache solo por-request (`request->attributes`), sin cache persistente.
- **Frontend:** Svelte 5 runes, TS estricto. `can(permission)` lee
  `page.props.auth.permissions`. Navegación única en `lib/navigation.ts` (item con
  `label`, `href`, `icon: IconName`, `permission?`). Copy en español. Solo Lumi
  (`@lumi-ui/svelte`), sin CSS local.
- **Inertia share:** `HandleInertiaRequests` expone `auth = { employee, branches,
  current_branch, permissions[] }`. Tipado en `resources/js/types/auth.ts`.

### Autenticación actual (empleados) — a extender

- `AuthAccount extends Authenticatable`, PK `code`, `login` único normalizado
  (CHECK `login = lower(btrim(login)) AND login <> ''`), `password` cast `hashed`,
  `user_code` **único y NOT NULL** hoy (solo empleados). Relación `user()`.
- `AuthenticateEmployee`: normaliza login, throttle por `login|ip` (5 intentos / 60s),
  `Hash::check` con dummy hash contra enumeración, valida cuenta+usuario+rol activos,
  exige sede activa, rehash si hace falta, `Auth::login`, `session()->regenerate()`,
  fija `current_branch_code` si hay una sola sede.
- `EnsureActiveAccount` (middleware `employee.active`): revalida cuenta/usuario/rol
  activos y sede en cada request; si no, logout + redirect a login.
- `BranchContext`: memoiza sedes autorizadas por-request; valida
  `session('current_branch_code')` contra membresía activa; autoselecciona si hay una.

## 4. Cambios necesarios sobre `auth_accounts` (clave para la tarea)

Hoy `user_code` es **único y obligatorio**. TASK/SPEC piden hacerlo dual:

```
user_code    FK nullable
student_code FK nullable
CHECK(exactamente un propietario)
UNIQUE(user_code)    where present
UNIQUE(student_code) where present
```

Implica: migración que altere `auth_accounts` (o rehacer baseline, ya que es dev limpio),
`AuthAccount` con relación `student()` y fillable + relación, y una autenticación
**actor-aware**: empleado (rol/ramas/permisos) vs alumno (solo `student_code` propio, sin
ramas ni permisos administrativos, redirige a su perfil). Mismo throttling/rehash/regen.
No denegar login por falta de matrícula activa. Login de alumno = DNI.

## 5. Modelo de datos a crear (según TASK §4 y SPEC §5–6)

```
students
  code UUID PK
  dni CHAR(8) NOT NULL UNIQUE (solo dígitos)   -- atributo, nunca PK
  first_name / last_name (revisar nombres/apellidos según v7)
  birth_date DATE nullable
  phone / address / observation nullable
  photo_path nullable                          -- Laravel Storage, un solo propietario
  is_active
  timestampsTz
  (sin branch_code, student_number, año, nivel ni datos de matrícula)

student_contacts
  code UUID PK
  student_code FK
  name
  phone nullable
  note nullable
  timestampsTz
  (nada de apoderados/parentescos/DNI/cuentas todavía)

enrollments
  code UUID PK
  student_code FK
  academic_group_code FK        -- determina grado→ciclo→sede
  roll_code                      -- humano; formato PENDIENTE de confirmar con v7/OMR
  is_active, observation, timestampsTz
  INVARIANTES (PostgreSQL): una matrícula activa por alumno en toda la institución;
  roll_code activo único.

enrollment_shifts
  enrollment_code FK
  cycle_shift_code FK
  PK(enrollment_code, cycle_shift_code)
  -- uno o dos turnos, del MISMO ciclo que el grupo
```

**Vistas PostgreSQL** (read models compuestos, TASK §8):
- directorio institucional (identidad + resumen matrícula actual/reciente) → `/students/search`.
- roster de matrícula (alumno+grupo+grado+ciclo+sede+turnos) → `/students`.
- Laravel autoriza, filtra, ordena y pagina sobre ellas; no reconstruye joins en cada
  controlador.

**Función SQL** para generación/reserva atómica de `roll_code` (concurrencia). **BLOQUEO:**
confirmar formato/uso OMR de `roll_code` con v7 antes de fijar esquema.

**Índices:** DNI, estado, FKs, matrícula activa (índice único parcial
`WHERE is_active`), `roll_code` activo, y estrategia de nombre (posible `pg_trgm` solo si
se añade migración/índice medible).

## 6. Permisos nuevos (TASK §7)

Añadir al `PermissionSeeder` únicamente:

```
students.view / students.manage
enrollments.view / enrollments.manage
```

`manage` incluye `view` (ya lo maneja `PermissionDependency`). Sin permisos por
botón/tab/foto/contacto/carnet/acceso. Autoservicio del alumno = propiedad del
`student_code`, no permiso administrativo.

## 7. Entradas/UI objetivo (TASK §5)

- **`/students/search`** — entrada de nav "Alumnos". Sin query: recientes. Con query:
  DNI, nombre, `roll_code` activo. Orden: DNI exacto → roll_code exacto → nombre
  aproximado. Muestra foto, nombre, DNI, estado, resumen de matrícula. `q` y `page` en
  URL, búsqueda/paginación de servidor. Botón "Nuevo alumno" y acceso a "Ver matrículas".
- **Alta/edición** — formulario único (no wizard): DNI, nombres, apellidos, nacimiento,
  teléfono, dirección, observación, foto, estado (en edición). Redirige al perfil.
- **Perfil `/students/{student}`** — ancla foto+identidad+estado; acciones compactas;
  resumen matrícula+acceso; tabs **Resumen | Contactos | Matrículas**. Contactos con
  Dialog (nombre, teléfono, nota). Matrículas: resumen/historia acotada + acciones
  (desactivar/modificar/eliminar). Sin tabs vacías de Pagos/Drive/asistencia.
- **`/students`** — roster de la sede (`BranchContext`), filtros persistentes en URL:
  ciclo, grado, sección/grupo, turno, estado, texto. Fila: foto, alumno/DNI, `roll_code`,
  ciclo/grado/sección, turnos, estado, acciones (perfil/matrícula). Click en nombre → perfil.
- **Carnet** — solo si hay salida imprimible real y autorizada; **BLOQUEO:** v8 no tiene
  solución PDF/QR aprobada. Comparar vista imprimible vs dependencia; dependencia runtime
  nueva requiere aprobación explícita. Sin botón/ruta ficticia mientras tanto.

## 8. Flujo de acceso del alumno (TASK §6)

1. "Habilitar acceso" crea/reactiva cuenta con DNI como login.
2. Servidor genera clave temporal criptográficamente segura.
3. Solo se persiste el hash.
4. Dialog muestra login/clave **una sola vez** con copiar.
5. Cerrar/recargar elimina el texto; "Restablecer" genera otra.
6. "Deshabilitar" cambia la cuenta, no identidad ni matrícula.

Nunca guardar contraseña reversible ni en logs/sesión/URL/auditoría/flash/props. DNI no
es contraseña. No exigir matrícula activa para login.

## 9. Orden de implementación propuesto (cortes verticales — TASK §9)

1. **Identidad + directorio + perfil:** migración `students`+`student_contacts`, modelos,
   vista SQL de directorio, `students.*` permisos, `StudentController`
   (search/create/store/show/edit/update), FormRequest, foto en Storage, Svelte
   `Students/Search`, `Students/Form`, `Students/Show` (tabs Resumen/Contactos/Matrículas),
   nav "Alumnos". Verificar.
2. **Cuenta + auth actor-aware + autoservicio:** alterar `auth_accounts` (dual owner +
   CHECK), `AuthAccount.student()`, Action habilitar/restablecer/deshabilitar acceso con
   clave temporal, adaptar `AuthenticateEmployee` → login unificado actor-aware,
   middleware/redirect de alumno, perfil básico self-service. Verificar.
3. **Matrícula + roster:** migración `enrollments`+`enrollment_shifts` (índice único
   parcial activo), función SQL `roll_code`, vista SQL roster, `enrollments.*`,
   `EnrollmentController` + Action transaccional (resolver matrícula previa), Svelte
   formulario matrícula + `/students` con filtros en URL. Verificar.
4. **Carnet:** solo tras resolver contrato/dependencia PDF/QR aprobada.

No crear scaffolding vacío de cortes posteriores.

## 10. Bloqueos / dudas materiales (parar antes de programar)

1. **`roll_code`:** formato exacto y uso OMR en v7 — determina esquema y función SQL.
2. **Carnet PDF/QR:** no hay solución aprobada en v8; requiere decisión (vista imprimible
   vs dependencia runtime nueva → aprobación explícita).
3. **Nombres del alumno:** ¿`first_name`/`last_name` o nombres+apellidos separados? Revisar v7.
4. **`auth_accounts` dual:** confirmar si se rehace la baseline (dev limpio) o migración
   `ALTER` — el proyecto usa baseline única desechable, probablemente rehacer.
5. **Contratos Lumi:** confirmar componentes públicos para Table/DataTable, Tabs, Dialog,
   Avatar/foto, filtros (PageSidebar/drawer) antes de la UI.

## 11. Verificación y cierre (TASK §12)

- Tras esquema/seed: `php artisan migrate:fresh --seed --env=testing` (base `aeduca_test`,
  **nunca** `aeduca`). Nota de memoria: usar `.env.testing` + limpiar config cache +
  ruta de Pages de Inertia correcta para que pasen los tests.
- Antes de completar: `composer run format`, `composer run check`, `pnpm run build`.
- Pruebas mínimas (sin sobre-testear framework): DNI único/normalizado, CHECK propietario
  de cuenta, autorización institucional vs sede vs propiedad del alumno, búsqueda sin
  sede/año con orden exacto + paginación, ausencia de N+1, clave temporal solo contra
  hash, login de alumno sin matrícula activa, regresión login/branch de empleados, una
  matrícula activa + roll_code único, rechazo escritura en otra sede + rollback,
  persistencia de filtros en URL.
- Al cerrar: consolidar decisiones en `SPEC`, hechos en `STATUS`, y eliminar/reemplazar
  `TASK.md`.

## 12. Próximo paso concreto al retomar

1. Inspeccionar Coedula (`/students/search`, `/students`, perfil, cuenta, reset, matrícula)
   y v7 (`Finder.vue`, `Student/*`, `Section/Student.vue`, carnet) para el formato de
   `roll_code`, layout del perfil y campos del alumno.
2. Revisar `lumi-ui/docs` + componentes públicos para fijar la UI.
3. Resolver los bloqueos de §10 (especialmente `roll_code` y carnet).
4. Presentar plan conciso y empezar por el corte vertical 1.
