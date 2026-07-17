# STATUS — Vertical 01 · Acceso de trabajador autorizado

> Documento técnico previo a la implementación de la primera vertical de Aeduca v8.
> Consolida `README.md`, `TASK.md`, `docs/FOUNDATION.md` y el estado inspeccionado
> del repositorio. Su propósito es cerrar decisiones suficientes para implementar
> sin ampliar el alcance ni introducir arquitectura preventiva.

---

## 0. Estado de decisión

**Estado:** APROBADO PARA IMPLEMENTAR, sujeto estrictamente a este documento.

La primera vertical será pequeña y completa:

```text
credenciales
→ trabajador activo
→ permisos semánticos
→ sedes autorizadas
→ sede actual en sesión
→ Home protegido
```

No se implementará ningún dominio académico, financiero o estudiantil en esta
vertical.

---

## 1. Fuentes y precedencia

El agente debe leer, en este orden:

1. `README.md`: reglas generales del proyecto y frontend.
2. `docs/FOUNDATION.md`: decisiones de dominio y arquitectura aprobadas.
3. `TASK.md`: alcance obligatorio de la vertical.
4. Este `STATUS.md`: resolución técnica puntual para ejecutar la vertical.
5. Documentación pública de Lumi UI indicada por el repositorio.
6. Código existente del repositorio antes de crear archivos nuevos.

En caso de contradicción:

```text
regla de dominio confirmada
> TASK.md
> STATUS.md
> patrón existente válido
> preferencia del agente
```

`FUNDATION.md` debe moverse íntegramente a `docs/FOUNDATION.md`. No debe quedar
una segunda copia, porque la especificación necesita un solo propietario.

---

## 2. Estado inicial relevante del repositorio

El repositorio es todavía un bootstrap de Laravel + Inertia + Svelte + Lumi UI.
No existe dominio implementado que deba preservarse.

Cambios esperados sobre el esqueleto:

- Sustituir el `User` autenticable predeterminado por dos responsabilidades:
  - `User`: perfil laboral.
  - `AuthAccount`: credenciales y actor autenticado de Laravel.
- Sustituir la migración inicial de `users` por las entidades de esta vertical.
- Conservar migraciones de caché y colas.
- Mantener la tabla `sessions`, pero adaptar `user_id` al UUID del modelo
  autenticado.
- Reemplazar factory y seeder predeterminados.
- Apuntar el provider de autenticación a `AuthAccount`.
- Proteger Home y agregar login, logout y selección de sede.
- Compartir trabajador, sedes y permisos mediante Inertia.
- Mantener una sola importación de Lumi y el shell visual existente.

Correcciones de bootstrap autorizadas:

- Usar `pnpm` en scripts de Composer.
- Cambiar metadatos del esqueleto por los de Aeduca.
- Cambiar licencia a `proprietary`.
- No realizar limpiezas no relacionadas.

---

## 3. Alcance cerrado

### Se implementa

1. `branches`.
2. `employee_roles`.
3. `permissions`.
4. `role_permissions`.
5. `users`.
6. `user_branches`.
7. `user_permissions`.
8. `auth_accounts`.
9. Sesiones Laravel compatibles con UUID.
10. Login de trabajador.
11. Logout seguro.
12. Rechazo de cuenta, trabajador o rol inactivo.
13. Resolución de permisos semánticos.
14. Sedes autorizadas y sede actual en sesión.
15. Selector único de sede en el layout autenticado.
16. Home protegido por `dashboard.view`.
17. Props Inertia y helper único `can()`.
18. Seeder de desarrollo mediante variables de entorno.
19. Pruebas Feature sobre PostgreSQL.

### No se implementa

- CRUD de trabajadores.
- Estudiantes o cuentas estudiantiles.
- Ciclos, modalidades, grados, grupos o matrículas.
- Asistencia.
- Atenciones.
- Obligaciones, pagos, caja o movimientos.
- Evaluaciones, OMR o reportes.
- Drive o Aula.
- Policies de recursos futuros.
- Paquetes de autorización.
- Repositorios, módulos, CQRS o servicios genéricos.
- Caché persistente de permisos.
- Recuperación de contraseña o “recordarme”.
- Auditoría general.

---

## 4. Modelo de datos aprobado

Todas las entidades principales usan UUID en `code`. Las foreign keys usan
`<entidad>_code`.

### 4.1 `branches`

```text
code          uuid primary key
name          string not null
is_active     boolean not null default true
created_at    timestamptz
updated_at    timestamptz
```

Una sede inactiva:

- No se comparte con el frontend.
- No puede seleccionarse.
- Invalida una selección de sesión previa.

### 4.2 `employee_roles`

```text
code          uuid primary key
name          string not null
description   string nullable
is_active     boolean not null default true
created_at    timestamptz
updated_at    timestamptz
```

`is_active=false` impide el acceso operativo del trabajador que utiliza ese rol.
El nombre del rol nunca participa en autorización.

### 4.3 `permissions`

```text
code          uuid primary key
name          string not null unique
description   string nullable
created_at    timestamptz
updated_at    timestamptz
```

Los nombres usan minúsculas y notación punto:

```text
dashboard.view
users.view
```

La vertical necesita obligatoriamente `dashboard.view`. No deben sembrarse
permisos de módulos todavía inexistentes.

### 4.4 `role_permissions`

```text
employee_role_code   uuid foreign key → employee_roles.code
permission_code      uuid foreign key → permissions.code
primary key (employee_role_code, permission_code)
```

Sin ID artificial, timestamps ni modelo Eloquent propio.

### 4.5 `users`

Representa el perfil del trabajador, no las credenciales.

```text
code                  uuid primary key
first_name            string not null
last_name             string not null
email                  string nullable
phone                  string nullable
employee_role_code     uuid not null foreign key → employee_roles.code
is_active              boolean not null default true
is_super_admin         boolean not null default false
created_at             timestamptz
updated_at             timestamptz
```

No agregar:

- `password`.
- `current_branch_code`.
- Array de sedes.
- `employee_number`.
- Unicidad de correo no confirmada.

### 4.6 `user_branches`

```text
user_code      uuid foreign key → users.code
branch_code    uuid foreign key → branches.code
primary key (user_code, branch_code)
```

Sin ID artificial, timestamps ni modelo Eloquent propio.

### 4.7 `user_permissions`

Representa una excepción individual al permiso heredado del rol.

```text
user_code        uuid foreign key → users.code
permission_code  uuid foreign key → permissions.code
is_allowed       boolean not null
primary key (user_code, permission_code)
```

Sin ID artificial, timestamps ni modelo Eloquent propio.

### 4.8 `auth_accounts`

Es el modelo autenticable de Laravel.

```text
code            uuid primary key
login           string not null unique
password        string not null
user_code       uuid not null unique foreign key → users.code
is_active       boolean not null default true
last_login_at   timestamptz nullable
created_at      timestamptz
updated_at      timestamptz
```

No agregar:

- Contraseña original o reversible.
- Polimorfismo.
- Cuenta de estudiante todavía.
- `remember_token` mientras no exista “recordarme”.

### 4.9 `sessions`

La tabla de sesiones conserva la estructura esperada por Laravel, pero:

```text
user_id uuid nullable index
```

`user_id` almacena `auth_accounts.code`; no puede permanecer como bigint.

---

## 5. Modelos Eloquent

Crear únicamente cinco modelos principales:

```text
AuthAccount
User
Branch
EmployeeRole
Permission
```

No crear modelos para:

```text
RolePermission
UserBranch
UserPermission
```

Eloquent no debe administrar esos pivotes como entidades con primary keys
compuestas. Se usarán relaciones `belongsToMany`, `attach`, `sync`,
`updateExistingPivot` o Query Builder.

### Relaciones principales

```text
AuthAccount belongsTo User
User belongsTo EmployeeRole
User belongsToMany Branch through user_branches
User belongsToMany Permission through user_permissions, withPivot(is_allowed)
EmployeeRole belongsToMany Permission through role_permissions
```

`AuthAccount` es el actor que devuelve:

```php
Auth::user()
$request->user()
Gate
```

`User` es el perfil operativo accesible mediante:

```php
$authAccount->user
```

No se deben mezclar ambos tipos.

---

## 6. Autenticación

### 6.1 Actor autenticado

El provider de `config/auth.php` apunta a `AuthAccount::class`.

Todo Gate y middleware recibe inicialmente un `AuthAccount`. Cuando necesita
información laboral obtiene su relación `user`.

### 6.2 Login

`AuthenticateEmployee` es la única Action del proceso de login.

Responsabilidades:

1. Aplicar throttling por login normalizado e IP.
2. Buscar `AuthAccount` por `login`.
3. Verificar el hash mediante `Hash::check`.
4. Verificar:
   - cuenta activa;
   - trabajador activo;
   - rol activo.
5. Iniciar sesión mediante `Auth::login($account)`.
6. Regenerar la sesión.
7. Actualizar `last_login_at`.
8. Obtener sedes activas autorizadas.
9. Si existe exactamente una, guardar su código en sesión.
10. Si existen cero o varias, dejar la sede actual en `null`.

Todos los errores de credenciales o estado devuelven un mensaje genérico. No se
revela si el login existe.

### 6.3 Logout

El logout debe ejecutar:

```php
Auth::logout();
$request->session()->invalidate();
$request->session()->regenerateToken();
```

### 6.4 Cuenta activa durante la sesión

`EnsureActiveAccount` se ejecuta en las rutas autenticadas y comprueba:

- `AuthAccount::is_active`.
- `User::is_active`.
- `EmployeeRole::is_active`.

Si alguna condición deja de cumplirse:

- Cierra la sesión.
- Invalida la sesión.
- Regenera el token CSRF.
- Redirige al login con mensaje genérico.

No crear un `AuthService` genérico.

---

## 7. Autorización

### 7.1 Regla obligatoria

Está prohibido autorizar mediante el código o nombre del rol.

```php
// Prohibido
$user->role->name === 'ADMIN'
$user->employee_role_code === 'A'
```

Toda autorización se expresa mediante permisos semánticos.

### 7.2 `PermissionResolver`

Única responsabilidad: resolver permisos efectivos del actor autenticado.

Interfaz conceptual:

```php
can(AuthAccount $account, string $permissionName): bool
effectiveNames(AuthAccount $account): array
```

Algoritmo:

```text
1. Obtener el perfil User del AuthAccount.
2. Si no existe o está inactivo → denegar.
3. Si el rol está inactivo → denegar.
4. Buscar una excepción individual para el permiso.
5. Si existe, devolver is_allowed.
6. Si no existe y User.is_super_admin=true:
   conceder solo si el permiso existe en permissions.
7. Si el rol contiene el permiso, conceder.
8. En otro caso, denegar.
```

La excepción individual prevalece incluso para un superadministrador. Esta
regla respeta el orden aprobado en Foundation: excepción explícita antes del
permiso heredado o global.

No usar:

- Redis.
- `Cache::remember`.
- Caché persistente.
- Invalidación de permisos.

Las consultas se mantienen claras. La optimización se decide después de medir.

### 7.3 Gates

Para esta vertical se define únicamente el Gate real:

```text
dashboard.view
```

Home utiliza ese Gate en backend. La entrada de navegación y el contenido
correspondiente utilizan `can('dashboard.view')` en frontend.

No crear todavía:

- `BranchPolicy`.
- `UserPolicy`.
- Ruta ficticia `users.create`.
- Botones de una operación que no existe.

Las Policies se incorporarán cuando existan recursos reales cuyo acceso dependa
tanto del permiso como de una instancia concreta.

---

## 8. Contexto de sede

### 8.1 Fuente de verdad

Las sedes autorizadas provienen exclusivamente de `user_branches`, filtrando
`branches.is_active=true`.

La sede actual vive exclusivamente en sesión:

```text
current_branch_code
```

No usar variantes como `auth.branch.code` o `branch.code`.

### 8.2 Selección

`SelectBranch`:

1. Recibe `AuthAccount` y `branch_code`.
2. Obtiene el perfil laboral.
3. Comprueba que la sede esté activa.
4. Comprueba que exista la relación en `user_branches`.
5. Guarda `current_branch_code` en sesión.
6. No exige un permiso adicional como `branches.select`.

La capacidad de elegir una sede deriva de la membresía real, no de un permiso
paralelo.

### 8.3 Comportamientos

- Una sede activa: se selecciona automáticamente al iniciar sesión.
- Varias sedes: Home abre con sede actual `null` y el Navbar muestra el selector.
- Ninguna sede: Home muestra un aviso de que no existe sede asignada; no se permite
  acceder a futuros módulos operativos.
- Sede desactivada o relación eliminada: se limpia `current_branch_code`.

### 8.4 Middleware de sede

No crear todavía `EnsureBranchSelected` para Home.

Home debe poder abrirse para que un trabajador con varias sedes realice la
selección. El middleware `branch.selected` se añadirá con el primer módulo que
realmente necesite contexto obligatorio de sede.

---

## 9. Props Inertia

`HandleInertiaRequests::share()` entrega:

```text
auth.account
  code
  login

auth.employee
  code
  first_name
  last_name
  role_name

auth.branches
  code
  name

auth.current_branch
  code
  name

auth.permissions
  string[]
```

Consideraciones:

- Para invitados, `auth` puede ser `null` o contener valores vacíos según el tipo
  elegido, pero debe existir una sola forma tipada.
- Las sedes compartidas son únicamente activas y autorizadas.
- `auth.permissions` contiene nombres efectivos.
- La propiedad se llama `permissions`; `can()` es el comportamiento del cliente.
- El frontend nunca recibe hashes, estados internos innecesarios ni IDs de pivote.

---

## 10. Frontend

### 10.1 Helper único

Crear un solo helper:

```text
resources/js/lib/permissions.ts
```

Interfaz:

```ts
can(permission: string): boolean
```

Lee `page.props.auth.permissions` y controla únicamente presentación. El backend
sigue siendo la autoridad.

No crear:

- Store paralelo de permisos.
- Resolver de roles.
- Permisos dentro de componentes Lumi.

### 10.2 Login

`Pages/Auth/Login.svelte`:

- `layout = false`.
- Lumi UI y clases públicas.
- Campos `login` y `password`.
- Error genérico.
- Estado de procesamiento.
- Sin estilos locales, inline styles ni colores crudos.
- Sin opción “recordarme”.

### 10.3 Layout autenticado

Conservar el shell existente.

```text
Sidebar → identidad Aeduca y navegación
Navbar  → sede actual, selector de sede y trabajador
```

El selector existe una sola vez, en el layout. Home no duplica el selector.

### 10.4 Home

Home demuestra un flujo real, no una operación ficticia:

- Muestra trabajador autenticado.
- Muestra sede actual o estado “sin sede seleccionada”.
- Muestra aviso si el trabajador no tiene sedes.
- Está protegido por `dashboard.view`.
- La navegación al Home se presenta mediante `can('dashboard.view')`.

No crear botón `users.create` ni una ruta de demostración sin dominio real.

---

## 11. Estructura mínima de aplicación

```text
app/
├── Actions/
│   ├── AuthenticateEmployee.php
│   └── SelectBranch.php
├── Http/
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── BranchController.php
│   │   └── HomeController.php
│   ├── Middleware/
│   │   └── EnsureActiveAccount.php
│   └── Requests/
│       ├── LoginRequest.php
│       └── SelectBranchRequest.php
├── Models/
│   ├── AuthAccount.php
│   ├── User.php
│   ├── Branch.php
│   ├── EmployeeRole.php
│   └── Permission.php
└── Support/
    └── Authorization/
        └── PermissionResolver.php
```

No crear:

```text
app/Modules
app/Repositories
app/Contracts/Repositories
app/Services/UserService.php
app/Policies/BranchPolicy.php
app/Policies/UserPolicy.php
```

No crear clases base sin dos usos reales.

---

## 12. Seeder de desarrollo

Debe crear:

- Una sede activa.
- Un rol activo.
- El permiso `dashboard.view`.
- Asociación rol-permiso.
- Un trabajador activo y superadministrador.
- Asociación trabajador-sede.
- Una cuenta activa.

Credenciales:

```text
AEDUCA_SEED_ADMIN_LOGIN
AEDUCA_SEED_ADMIN_PASSWORD
```

- Se documentan vacías en `.env.example`.
- El seeder falla con un mensaje claro cuando faltan en un entorno donde se
  solicita sembrar el administrador.
- La contraseña se procesa con `Hash::make`.
- No existe contraseña predeterminada comprometida en el repositorio.

---

## 13. Pruebas

### 13.1 Motor de base de datos

Las pruebas Feature usan PostgreSQL, no SQLite.

Configurar una base aislada, por ejemplo:

```text
aeduca_test
```

Razón: la producción y las migraciones dependen de PostgreSQL. El suite no debe
aprobar comportamientos que difieran en foreign keys, UUID, timestamps o
restricciones.

Usar `RefreshDatabase`. No combinarlo indiscriminadamente con
`DatabaseTransactions`.

### 13.2 Casos mínimos obligatorios

1. Login correcto.
2. Credenciales incorrectas.
3. Throttling después de intentos fallidos.
4. Cuenta inactiva.
5. Trabajador inactivo.
6. Rol inactivo.
7. Sesión regenerada tras login.
8. Logout invalida sesión y regenera token.
9. Permiso concedido por rol.
10. Excepción individual concede.
11. Excepción individual deniega y prevalece.
12. Superadministrador obtiene permisos existentes.
13. Home rechaza actor sin `dashboard.view`.
14. Home permite actor con `dashboard.view`.
15. Una única sede se selecciona al iniciar sesión.
16. Varias sedes dejan `current_branch_code` vacío.
17. Selección de sede autorizada y activa.
18. Rechazo de sede no autorizada.
19. Rechazo de sede inactiva.
20. Sede de sesión eliminada o desactivada se limpia.
21. Props Inertia contienen permisos efectivos.
22. Props Inertia contienen únicamente sedes activas autorizadas.

Eliminar los tests de ejemplo del esqueleto.

No crear Unit tests que solo repliquen Eloquent o Laravel.

---

## 14. Plan de implementación

| Paso | Trabajo | Verificación |
|---|---|---|
| 1 | Mover `FUNDATION.md` a `docs/FOUNDATION.md` | Existe una sola copia |
| 2 | Corregir Composer: pnpm, metadata, licencia | `composer validate --strict` |
| 3 | Crear migraciones de las 8 tablas y adaptar `sessions.user_id` a UUID | `php artisan migrate:fresh` en PostgreSQL |
| 4 | Crear los 5 modelos y relaciones | Pruebas de relaciones mediante Feature tests |
| 5 | Configurar `AuthAccount` como modelo autenticable | Login básico pasa |
| 6 | Implementar `AuthenticateEmployee`, throttling y logout seguro | Casos 1–8 pasan |
| 7 | Implementar `PermissionResolver` y Gate `dashboard.view` | Casos 9–14 pasan |
| 8 | Implementar `SelectBranch` y sesión `current_branch_code` | Casos 15–20 pasan |
| 9 | Compartir props Inertia y crear `can()` | Casos 21–22 + `pnpm run check` |
| 10 | Implementar Login, Layout y Home con Lumi | Verificación manual + check |
| 11 | Implementar seeder por entorno | `php artisan migrate:fresh --seed` |
| 12 | Ejecutar suite y build | Todos los comandos finales pasan |
| 13 | Revisar diff contra prohibiciones | Revisión manual final |

---

## 15. Verificación final

La vertical se considera terminada únicamente cuando pasan:

```bash
composer validate --strict
php artisan migrate:fresh --seed
php artisan test
pnpm run check
pnpm run build
```

La migración y el suite Feature deben ejecutarse sobre PostgreSQL aislado según
su entorno correspondiente.

Revisar que el diff no contenga:

- Comparaciones por rol.
- Contraseñas en claro.
- Relaciones en arrays o JSON.
- Modelos Eloquent de pivotes con PK compuesta.
- Policies sin recurso real.
- Ruta o botón ficticio para demostrar permisos.
- `Cache::remember` para permisos.
- Doble selector de sede.
- Dos nombres para la clave de sesión.
- Middleware de sede bloqueando Home.
- Email único sin requerimiento.
- Nuevos paquetes.
- Estilos locales o colores crudos.
- Funcionalidad fuera del alcance.

---

## 16. Condiciones de detención

El agente debe detenerse y explicar antes de implementar cuando:

- Una regla de `FOUNDATION.md` contradice este documento.
- Lumi no expone públicamente un componente necesario.
- Una invariante no puede protegerse con la estructura aprobada.
- Surge la necesidad de una dependencia nueva.
- La solución exige implementar otro dominio.
- Se pretende agregar una abstracción preventiva.
- Se descubre que el actor autenticado esperado por Laravel no coincide con
  `AuthAccount`.
- El entorno PostgreSQL de pruebas no puede aislarse de desarrollo.

No debe detenerse por decisiones menores de nombre o composición ya resueltas
aquí. En esos casos debe seguir el patrón más simple existente.

---

## 17. Resultado esperado

Al finalizar esta vertical debe existir una base pequeña y confiable:

```text
AuthAccount autentica
→ User define trabajador y rol
→ PermissionResolver resuelve capacidades
→ user_branches limita sedes reales
→ current_branch_code conserva contexto
→ Inertia comparte datos mínimos
→ Svelte presenta mediante can()
→ Laravel autoriza siempre
```

Esta vertical será el patrón para los siguientes módulos. Por eso debe ser
simple, explícita y comprobable; no extensible “por si acaso”.
