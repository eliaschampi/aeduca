# STATUS — Vertical 01 · Acceso de trabajador

**Estado:** implementado y verificado.
**Fecha:** 2026-07-17.

Este archivo registra lo construido. Las reglas siguen viviendo únicamente en
[`docs/FOUNDATION.md`](docs/FOUNDATION.md) y [`TASK.md`](TASK.md).

## 1. Decisiones cerradas

- Laravel no obliga a usar `users.id`.
- Las entidades principales usan UUID en `code`; las foreign keys usan
  `<entidad>_code`.
- `AuthAccount` posee login, password y estado de acceso.
- `User` representa al trabajador. Un docente es el mismo trabajador y recibirá
  asignaciones académicas cuando exista ese módulo.
- Los estudiantes no se mezclan todavía con trabajadores ni cuentas.
- Los códigos semánticos de v7 no se reutilizan como primary keys. Durante la
  migración se conservarán en un mapa técnico de origen a UUID, no en columnas
  `legacy_id` dispersas.

## 2. Alcance implementado

Tablas de acceso:

```text
branches
employee_roles
permissions
role_permissions
users
user_branches
user_permissions
auth_accounts
sessions
```

Invariantes principales:

- UUID `code` en entidades principales.
- Pivotes con primary key compuesta, sin modelo ni ID artificial.
- Foreign keys, `CASCADE`/`RESTRICT` e índices PostgreSQL explícitos.
- Permisos en minúsculas y notación punto.
- Login normalizado y único.
- `sessions.user_id` referencia `auth_accounts.code`.
- `current_branch_code` vive exclusivamente en sesión.

## 3. Flujo real

### Autenticación

```text
login normalizado + IP
→ throttling
→ password
→ cuenta, trabajador y rol activos
→ al menos una sede activa asignada
→ sesión regenerada
→ última sede válida en sesión
```

Protecciones:

- Mensaje genérico para credenciales o estados inválidos.
- Mensaje específico de falta de sede solo después de validar credenciales.
- Hash ficticio precomputado para login inexistente.
- Clave de throttling SHA-256 acotada para no desbordar el cache PostgreSQL.
- Cast Eloquent `hashed`.
- `Hash::needsRehash` actualiza hashes antiguos al iniciar sesión.
- Logout invalida sesión y regenera CSRF.
- Cada petición vuelve a validar cuenta, trabajador, rol y sedes.

### Autorización

Orden efectivo:

```text
actor activo
→ superadministrador: todo permiso conocido
→ override individual allow/deny
→ permiso del rol
→ denegar
```

Laravel protege Home mediante el Gate `dashboard.view`. Inertia comparte solo
trabajador, sedes, sede actual y nombres de permisos. Svelte usa un único
`can()` para presentación.

La memoización de sedes y permisos existe solo durante la petición. No hay Redis,
caché persistente ni invalidación adicional.

### Sedes

- Una sede: se selecciona automáticamente.
- Varias sedes: la sesión queda sin sede hasta elegir.
- Sede revocada: se limpia y, si queda una sola, se selecciona.
- Sin sedes: no existe acceso operativo y la sesión se cierra.
- Seleccionar sede depende de membresía real, no de un permiso ficticio.

## 4. Interfaz

- Login Lumi, sin layout autenticado.
- Dashboard Lumi sin CSS local.
- Navbar con chip informativo de sede; no contiene selector.
- Vista `/branches` con tarjetas `Usar sede / Sede activa`, inspirada en el patrón
  visual útil de Coedula, sin copiar su CRUD, filtros, miembros o modales.
- Sidebar filtrado por permisos semánticos.
- Home muestra trabajador, rol y sede actual.

## 5. Estructura deliberadamente pequeña

```text
app/Actions
  AuthenticateEmployee
  LogoutEmployee
  SelectBranch

app/Support
  Authorization/PermissionResolver
  Branches/BranchContext
```

No se agregaron repositories, DTOs, services genéricos, CQRS, eventos, paquetes
de autorización, roles hardcodeados ni clases base preventivas.

Inventario de implementación:

```text
Nuevos
  .env.testing.example
  config/aeduca.php
  database/migrations/0001_01_01_000000_create_access_foundation_tables.php
  app/Actions/{AuthenticateEmployee,LogoutEmployee,SelectBranch}.php
  app/Http/Controllers/{AuthController,BranchController,HomeController}.php
  app/Http/Middleware/EnsureActiveAccount.php
  app/Http/Requests/{LoginRequest,SelectBranchRequest}.php
  app/Models/{AuthAccount,Branch,EmployeeRole,Permission}.php
  app/Support/Authorization/PermissionResolver.php
  app/Support/Branches/BranchContext.php
  database/factories/{AuthAccountFactory,BranchFactory,EmployeeRoleFactory,
    PermissionFactory}.php
  resources/js/Pages/Auth/Login.svelte
  resources/js/Pages/Branches/Index.svelte
  resources/js/lib/permissions.ts
  resources/js/types/auth.ts
  tests/Feature/{BranchSelectionTest,DatabaseIntegrityTest,
    EmployeeAuthenticationTest,InertiaSharedDataTest,
    PermissionAuthorizationTest}.php

Modificados
  .env.example, .gitignore, README.md, STATUS.md
  composer.json, composer.lock, phpunit.xml
  bootstrap/app.php, config/auth.php, routes/web.php
  app/Models/User.php, app/Providers/AppServiceProvider.php
  app/Http/Middleware/HandleInertiaRequests.php
  database/factories/UserFactory.php, database/seeders/DatabaseSeeder.php
  resources/js/{app.d.ts,app.ts}
  resources/js/Layouts/DashboardLayout.svelte
  resources/js/Pages/Home.svelte
  resources/js/lib/navigation.ts
  tests/TestCase.php

Reemplazados o eliminados
  FUNDATION.md → docs/FOUNDATION.md
  migración users inicial → migración integral de acceso
  tests Example de Laravel
```

## 6. Configuración local

- `.env` conecta la aplicación normal con `aeduca`.
- `.env.testing` conecta PHPUnit y `--env=testing` con `aeduca_test`.
- Ambos archivos son locales y están ignorados por Git.
- `.env.example` y `.env.testing.example` son las plantillas versionadas.
- `phpunit.xml` define comportamiento de pruebas, no credenciales de conexión.
- Las migraciones crean tablas; no crean la base PostgreSQL.

Estado local observado el 2026-07-17:

- `aeduca`: base creada, migración todavía pendiente.
- `aeduca_test`: 15 tablas y las tres migraciones aplicadas.

Para preparar desarrollo sin eliminar información:

```bash
php artisan migrate
```

## 7. Pruebas

Las pruebas usan PostgreSQL y `SESSION_DRIVER=database`. Su conexión vive en el
archivo local `.env.testing`, no en `phpunit.xml`. Cubren únicamente fronteras
críticas:

- autenticación, throttling, sesión y rehash;
- estados inactivos durante login y sesión;
- autorización por rol, override, superadministrador y Gate;
- selección, revocación y aislamiento de sedes;
- payload Inertia mínimo;
- `UNIQUE`, `CHECK`, foreign keys, `CASCADE` y `RESTRICT`.

No hay tests de snapshots, componentes visuales triviales ni Unit tests que
repitan Eloquent.

## 8. Verificación

Comandos obligatorios:

```bash
composer validate --strict
vendor/bin/pint --test
AEDUCA_SEED_ADMIN_LOGIN=verification-admin \
AEDUCA_SEED_ADMIN_PASSWORD='<secreto-local>' \
php artisan migrate:fresh --seed --env=testing
php artisan test
pnpm run check
pnpm run build
git diff --check
```

PostgreSQL 18 de Homebrew está activo en `127.0.0.1:5432`. La base aislada de
pruebas es `aeduca_test`.

Resultado actual:

- Migración limpia y seeder: correcto.
- Suite: 38 pruebas, 168 aserciones, todas correctas.
- Composer, Pint, TypeScript, build de producción y `git diff --check`: correctos.

Lumi es una dependencia local `file:../lumi-ui`; después de un clon limpio debe
empaquetarse antes de instalar Aeduca. El procedimiento está documentado en
[`README.md`](README.md).

## 9. Consideración de rendimiento pendiente

La arquitectura actual no presenta un cuello de botella demostrado.
`BranchContext` y `PermissionResolver` conservan sus resultados solo durante la
petición, evitando consultas duplicadas sin introducir caché persistente.
El costo de instanciar las Actions es despreciable frente a PostgreSQL y bcrypt.

Por lectura estática, una respuesta Inertia autenticada normal puede realizar
aproximadamente siete lecturas y una escritura de sesión. Esta cifra es una
estimación, no un benchmark. El costo responde principalmente a sesiones en base
de datos y a la revalidación inmediata de cuenta, trabajador, rol, sedes y
permisos.

Cuando exista volumen real se deberán medir:

- cantidad y tiempo SQL total por petición;
- número de sedes y permisos por trabajador;
- concurrencia y contención de la tabla `sessions`;
- costo de compartir sedes y permisos en cada respuesta Inertia.

No se introducirá Redis, caché persistente, denormalización ni consultas más
complejas antes de demostrar la necesidad con mediciones.

## 10. Fuera de alcance

No se implementaron CRUD de sedes o trabajadores, estudiantes, matrículas,
ciclos, pagos, caja, asistencia, evaluaciones, OMR, Drive ni Aula.

El siguiente trabajo debe comenzar con un contrato explícito de migración
`origen + clave antigua → entidad + UUID`, seguido por una sola vertical de
dominio. No se añadirán campos preventivos antes de conocer esa vertical.
