# Aeduca v8 — Agent & developer specification

> **Role of this file.** Single source of truth for domain rules, architecture,
> and engineering principles. Prefer this document over historical task notes.
> Claims about *what the code does today* live in [`STATUS.md`](STATUS.md).
>
> **Audience.** Humans and LLMs implementing or reviewing Aeduca v8.
>
> **Rule.** Do not invent tables, fields, permissions, packages, or UI patterns
> that are not justified here or already present in the repository.

---

## 1. Project identity

### What Aeduca v8 is

Aeduca v8 is a **clean rebuild** of Carrión’s school-management platform.
It unifies the lessons of prior systems into one application:

| System | Role for v8 | Local path (this machine) |
| ------ | ----------- | ------------------------- |
| **Aeduca Admin (v7)** | Evidence of workflows that were *actually used*; source of data to migrate | `../v7 aeduca main` |
| **Aeduca Aula** | Student portal patterns (login with DNI + password, shared DB with Admin) | historically under v7 tree |
| **Coedula** | Evidence of *more recent* technical and UI solutions | `../coedula` |
| **Nextya** | OMR evaluations (separate DB; migrate carefully later) | `../nextya` |

**Owner institution:** Carrión and its branches (sedes).  
**Not a SaaS:** no multi-tenant companies, memberships, or enterprise isolation.

### Governing principle (non-negotiable)

**Aeduca v8 is not a clone of Coedula and not a rewrite of Admin as-is.**

For every capability:

1. Identify the real operational need for Carrión.
2. Study how Admin solved it (workflow evidence).
3. Study how Coedula solves it (modern technique / UX).
4. Keep what proved valuable.
5. Drop structural debt.
6. Implement the **smallest** solution that protects confirmed rules.

Inspiration ≠ copy. Prefer **functionality, clarity, and migration safety** over
architectural ceremony or visual cloning.

### Product goal (long term)

One system for workers and (later) students covering: sedes, personal,
permisos, ciclos, matrículas, asistencia, caja/pagos, evaluaciones/OMR,
atenciones, and a lean student portal — migratable from v7 data.

**Current delivery focus:** administrative foundation (access + sedes +
usuarios del personal + roles/permisos). Academic and finance modules come later.

---

## 2. Stack (fixed)

| Layer | Choice |
| ----- | ------ |
| Backend | Laravel 13, PHP 8.5 |
| Transport | Inertia.js |
| UI | Svelte 5 (runes only) + TypeScript strict |
| Components | `@lumi-ui/svelte` (local package `file:../lumi-ui`) |
| Database | PostgreSQL |
| Package managers | Composer (PHP), **pnpm only** (JS) |
| End-user locale | Spanish (`es`) |

### Forbidden dependencies / layers

Do **not** install or invent:

- Authorization packages (Spatie, Bouncer, …)
- Auth starter kits
- Module frameworks
- Generic repositories / repository interfaces
- Generic `*Service` gods (`UserService`, `PermissionService`, …)
- DTO libraries used only to rename arrays
- Extra CSS frameworks or component libraries
- Client state libraries for permissions or session branch
- Soft-delete-by-default patterns
- Polymorphic `entity_type` + `entity_code` when an explicit FK is possible

Use Laravel Auth, Gates, Policies, middleware, Eloquent, and PostgreSQL
constraints directly.

---

## 3. Engineering principles

### 3.1 Consistency — one way

- Reuse existing patterns before inventing new ones.
- Match naming, folders, and composition already in the tree.
- Domain rules live in Laravel (models, actions, policies/gates).
- UI is semantic and presentation-only — never encode business rules in Lumi.

### 3.2 Clean and organized

- Prefer delete and simplify over accumulate.
- One owner per concern (no parallel helpers or dual CSS systems).
- Small files, clear folders, no dead or speculative layers.
- Frontend layout: public Lumi classes (`.lumi-stack`, `.lumi-grid`, dashboard shell).
- **No** local `<style>` blocks, raw colors, or inline styles unless Lumi has no public alternative.
- Brand seeds only: `resources/js/styles/lumi-theme.css`.

### 3.3 Performance without premature cleverness

- Smallest change that solves the problem.
- No N+1 by accident; no eager loads “just in case”.
- Request-scoped memoization is fine; persistent permission/branch caches are not
  until measurements demand them.
- One color-scheme controller, one styles import, one layout shell.

### 3.4 Modern UI quality

- Svelte 5 runes: `$state`, `$derived`, `$props`, `$effect`.
- Types at boundaries (Inertia props, forms).
- Calm, structured admin UI: clear hierarchy (header → filters/actions → content cards/tables → dialogs).
- Spanish copy for end users.

### 3.5 Decision filter (before writing code)

1. Does something already solve this?
2. Is the API free of domain leakage into Lumi?
3. Can layout use public Lumi classes instead of custom CSS?
4. Will this stay easy to delete later?
5. Is there only one owner for this behavior?

If any answer is wrong, stop and redesign.

---

## 4. Clean architecture (current, intentional)

Deliberately **light**. Not hexagonal ceremony. Clear owners only.

```text
app/
├── Actions/                 # Multitable writes / real domain processes
├── Http/
│   ├── Controllers/         # Thin: authorize → validate → action → redirect/Inertia
│   │   └── Admin/           # Administrative CRUD (sedes, usuarios, …)
│   ├── Middleware/          # Auth revalidation, Inertia shared props
│   └── Requests/            # Form shape, normalization, Spanish messages
├── Models/                  # Relations, casts, small scopes, UUID keys
├── Providers/               # Gate::before → PermissionResolver
└── Support/
    ├── Authorization/       # PermissionResolver (single responsibility)
    └── Branches/            # BranchContext (session branch)

routes/web.php               # HTTP entry points only

resources/js/
├── app.ts                   # Inertia bootstrap + Lumi styles (once)
├── styles/lumi-theme.css    # Brand seeds only
├── lib/                     # nav, can(), color-scheme
├── Layouts/                 # Dashboard shell
├── Pages/                   # Thin Inertia pages
│   ├── Auth/
│   ├── Branches/            # Worker branch picker (session)
│   ├── Admin/               # Administrative screens
│   └── Home.svelte
└── types/

database/
├── migrations/              # Explicit FKs, CHECKs, composite PKs
├── factories/
└── seeders/
```

### Layer ownership

| Layer | Owns | Must not own |
| ----- | ---- | ------------ |
| **PostgreSQL** | Structural invariants (FK, UNIQUE, CHECK) | Hidden business logic via triggers |
| **Model** | Relations, casts, UUID PK, small scopes | Multi-step business processes |
| **FormRequest** | Shape, types, required, formats, Spanish errors | Full domain policy |
| **Action** | Transactional multitable writes, invariants | HTTP / presentation |
| **Controller** | Authorize, call request/action, Inertia/redirect | Fat queries, finance math, permission resolution |
| **PermissionResolver** | Effective permission names for an account | Role-name checks |
| **BranchContext** | Session `current_branch_code` + membership | Storing current branch on `users` |
| **Svelte page** | Interaction, layout composition, `can()` for UI | Security authority |
| **Lumi** | Domain-neutral primitives | Roles, permissions, school rules |

### When to create an Action

Create an Action when the write:

- touches more than one table, or
- protects a real invariant / process.

A simple single-row update may stay in the controller after FormRequest validation.

### When **not** to create

```text
app/Modules/
app/Repositories/
app/Contracts/Repositories/
app/Services/UserService.php
app/Services/PermissionService.php
```

No base classes until two real implementations share real behavior.

---

## 5. Data rules

### Identifiers

- Main entities: UUID primary key column named **`code`**.
- Foreign keys: **`<entity>_code`** (e.g. `user_code`, `branch_code`).
- Never embed year, sede, nivel, grado, grupo, or modality inside technical keys.
- DNI is a **unique attribute**, never a primary key.
- No `employee_number` until a confirmed functional use exists.
- No scattered `legacy_id` columns; migration maps live in a technical map artifact.

### Relations

- Many-to-many → intermediate tables with composite primary keys.
- **Forbidden:** arrays or JSON for relationships (e.g. no `branches.users uuid[]`).
- Explicit foreign keys always.
- Soft deletes only when business needs restore/trash — not by default.

### Money / dates (future modules)

- Money: `NUMERIC`, never float; multitable finance always transactional.
- Business dates: `date` / `time` / timestamptz by meaning.
- A **cycle may span two calendar years** — never validate “same year only”.

### JSON

Allowed only for unstructured external evidence.  
Forbidden for FKs, permissions, authorized sedes, turnos, enrollments, participants.

---

## 6. Access domain (implemented foundation)

### Conceptual model

| Concept | Model / table | Meaning |
| ------- | ------------- | ------- |
| Sede | `branches` | Physical/operational branch of Carrión |
| Rol | `employee_roles` | Default **permission bundle** only — never authorize by role name |
| Permiso | `permissions` | Semantic capability `domain.action` |
| Rol→permiso | `role_permissions` | Default grants |
| Usuario (personal) | `users` | Employee **profile** (not credentials). UI label: **Usuarios** |
| Usuario↔sede | `user_branches` | Membership; required for session branch |
| Override | `user_permissions` | Per-worker allow/deny (`is_allowed`) |
| Credencial | `auth_accounts` | Laravel `Authenticatable` (login/password) |

Teachers are the same worker entity; academic assignments come later.  
Students are a **separate** future identity — do not mix into `users`/`auth_accounts` now.

### Table shapes (minimum)

**`branches`:** `code`, `name`, `is_active`, timestamps  
**`employee_roles`:** `code`, `name`, `description?`, `is_active`, timestamps  
**`permissions`:** `code`, `name` unique (lowercase dot notation), `description?`, timestamps  
**`role_permissions`:** (`employee_role_code`, `permission_code`) composite PK  
**`users`:** `code`, `first_name`, `last_name`, `email?`, `phone?`, `employee_role_code`, `is_active`, `is_super_admin`, timestamps  
**`user_branches`:** (`user_code`, `branch_code`) composite PK  
**`user_permissions`:** (`user_code`, `permission_code`, `is_allowed`) composite PK  
**`auth_accounts`:** `code`, `login` unique (normalized lower), `password` hashed, `user_code` unique, `is_active`, `last_login_at?`, timestamps  

### Permission catalog (current names — code is authority)

```text
dashboard.view
branches.view
branches.manage
employees.view
employees.manage
roles.view
roles.manage
```

Historical docs sometimes wrote `users.view` / `users.create`. **Do not reintroduce those names.** The implemented vocabulary is `employees.*` and `branches.*`.

### Permission resolution (single algorithm)

Implemented in `app/Support/Authorization/PermissionResolver.php`:

1. Inactive account, worker, or role → no permissions.
2. `users.is_super_admin` → every known permission name.
3. Start from role grants (`role_permissions`).
4. Apply individual overrides (`user_permissions`): allow adds, deny removes.
5. Otherwise denied.

**Backend is always authoritative.** Controllers use `Gate::authorize('employees.manage')` etc.  
`Gate::before` returns `true` when the resolver allows, else `null` (Laravel denies).

Frontend: **one** helper `can('employees.create')` style — presentation only.  
Lumi components must never contain role/permission logic.

`is_super_admin` is **seed/escalation only** — never expose it in admin forms.

### Branch / session context

- Membership: only via `user_branches` (not arrays, not JSON).
- **Current branch lives only in session** key `current_branch_code`.
- Never store `current_branch_code` on `users`.
- Selection always validated against membership.
- One authorized branch → may auto-select.
- Multiple → user must choose (worker picker at `/branches`).
- Zero active memberships → no operational access (session closed by middleware).
- Selecting a branch depends on **membership**, not on a fake permission.

### Login / security expectations

- Generic invalid-credential message (no user enumeration).
- Reject inactive account or inactive worker.
- Passwords: irreversible hash only (`hashed` cast); never reversible storage.
- Session regenerate on login; invalidate + CSRF regenerate on logout.
- Revalidate account, worker, role, and branches on authenticated requests (`employee.active`).

### Shared Inertia auth payload

```text
auth: {
  employee: { first_name, last_name, role_name },
  branches: [{ code, name }, ...],   // authorized only
  current_branch: { code, name } | null,
  permissions: string[]              // effective names
}
```

---

## 7. Administrative workflows (intended behavior)

### 7.1 Sede management (unified `/branches`)

One page serves **session selection** and **catalog administration** (Coedula-style UX):

- Everyone with membership: pick current session sede.
- `branches.view`: see full catalog with members.
- `branches.manage`: create/update name, active flag, and members.
- Writes: `POST/PUT /admin/branches` (authorization on manage).
- No physical delete, no stats, no settings JSON, no bulk import.
- No second sidebar item for “gestión de sedes”.

**Membership (required, pivot model):**

- Creating or updating a sede **must** assign usuarios through `user_branches`.
- Coedula stores members as an array on the branch row — **forbidden in v8**.
- Require ≥1 member on create/update.
- UI shows assigned people (`AvatarGroup` / list), not only a bare name table.
- Session “Sede activa” = **selected card + button only** (no duplicate chip).
- Catalog state chips: **Habilitada / Deshabilitada** (`is_active`).

### 7.2 User management (`/admin/employees`) — UI: **Usuarios**

Minimum:

- List, create (profile + role + sedes + credentials in one transaction),
  show/edit profile, reassign role/sedes, change password, toggle access.
- Permission keys stay `employees.view` / `employees.manage` (stable).
- End-user Spanish copy says **Usuarios**, never “Trabajadores”.
- Preserve profile (`User`) vs credential (`AuthAccount`) separation.
- Do not implement physical employee deletion until impact on attendance/payments/history is defined.
- Do not add empty future tabs (attendance, cards, reports) on the profile.

### 7.3 Roles & permissions (closed)

**Philosophy**

- **Role = default package** for a job. Day-to-day: assign role, not tick boxes per person.
- **User exceptions = sparse** (add/remove force allow|deny) for special cases only.
- Not Coedula’s user-only ACL; not dual “effective chip walls” on the user page.

**UI**

- Roles: only place that edits the full permission set (tabs + collapsible groups).
- User: hero + tabs **Perfil | Seguridad**; password/access only in header ⋮.
- Payload: `permission_overrides` (with labels) always; `permission_catalog` only if
  `employees.manage` (for the add-exception select).

**Gates / actions:** `roles.*`, `SaveRole`, `SyncUserPermissionOverrides`.  
Never authorize by role name.

### 7.4 Out of scope until foundation is solid

Students, cycles, enrollments, attendance, payments, cashbox, evaluations, OMR,
Drive, attentions, dashboard metrics, full Aula social features.

---

## 8. UI composition principles

### Shell

- Authenticated pages: `DashboardLayout` (`.lumi-dashboard-layout` + Sidebar + Navbar).
- Login: no dashboard shell (`layout = false` pattern if needed).
- Sidebar items: single source `resources/js/lib/navigation.ts`, filtered by `can()`.
  Current set: **Inicio · Sedes · Usuarios**.
- Navbar shows current sede chip once; sidebar header shows role (not a second sede dump).
- Unified `/branches` for session + catalog (no parallel admin list page).

### Forms

- **Every `Input` must have a `placeholder`** (Spanish, short, realistic examples).
- `Select` always has a meaningful `placeholder`.
- Group fields in `Card` / `Fieldset`; use `lumi-grid--responsive` — no inline styles.

### Page structure (admin screens)

1. `PageHeader` (title, subtitle, primary action).
2. Optional toolbar / filters (search) when lists grow.
3. Content in `Card` / `Table` with clear density.
4. Destructive or secondary flows in `Dialog`.
5. Feedback via Lumi `Alert` / validation `dangerText` — Spanish messages.

### Inspiration from Coedula (allowed)

- Branch cards with member preview + clear “use this sede / active” states.
- Create/edit sede dialog that includes member assignment.
- User list with role, access chips, and focused create/edit dialogs.

### Must not copy from Coedula

- Array columns for membership.
- Storing current branch on the user row.
- Permission string format `resource:action` if v8 already uses `resource.action`.
- Mechanical CRUD of every Coedula field (photo, slug roles, etc.) without need.

### Lumi reference

- Install / layout / theming: `../lumi-ui/docs/GUIDE.md`
- Components: `../lumi-ui/docs/COMPONENTS.md`
- Agent routing: `../lumi-ui/docs/AGENT_GUIDE.md`
- Runnable shell: `../lumi-ui/examples/dashboard`
- Verify props against `node_modules/@lumi-ui/svelte/dist/**/*.d.ts`

---

## 9. Domain decisions for later modules (confirmed, not implemented)

These are **business rules** already decided for Carrión; implement only when that vertical starts.

- One institution, multiple sedes; no tenants table.
- Nivel ≠ modalidad; grades and groups are configurable entities (not hard-coded A/B/C/D).
- One **active enrollment per student system-wide** (DB-enforced).
- Enrollment may change sede/group directly; no transfer history in first version.
- Cycle enables one or two shifts; shift belongs to enrollment selection, not to group.
- `roll_code` is a human number for OMR/search/card; card QR carries DNI only.
- Attendance Mon–Sat; no holiday calendar v1; present/late/absence rules as previously defined; no cron mass-inserting absences.
- Student contact is minimal (name, phone, free note) — not full apoderado model.
- Finance: obligation vs payment vs application vs cash movement; partial payments; cashier owns cash line (not sede); no formal open/close caja; voids via reverse/state, never hard-delete confirmed finance.
- OMR: Nextya origin; images discarded after process; Laravel owns academic data; processor does not own students.
- Migration: map legacy semantic codes → UUIDs in a technical map; migrate students, users, sedes, cycles, grades, groups, enrollments, payments/obligations, cash, attentions, evaluations when reliable.
- No parallel dual-run for staff at cutover; rehearsal migration + reconciliation first.

---

## 10. Testing & verification

### Prefer Feature tests at critical borders

- Auth success/failure, inactive states, throttling where relevant.
- Permission grant / override allow / override deny / super admin.
- Branch membership selection and rejection.
- Admin authorization and multitable creates (employee, branch+members).
- DB integrity: UNIQUE, CHECK, FK cascade/restrict.

Avoid snapshot spam and unit tests that only restate Eloquent.

### Mandatory checks before claiming done

```bash
php artisan test
pnpm run check
pnpm run build
# when DB or seed changed (testing env only):
# php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against the development database `aeduca`.

---

## 11. Stop conditions for agents

Stop and explain instead of inventing when:

- A required rule conflicts with existing code and the fix is ambiguous.
- A new dependency seems necessary.
- A DB invariant cannot be protected with FK/UNIQUE/CHECK.
- Lumi lacks a required **public** component (do not fork Lumi internals in Aeduca).
- The task would pull in another domain module.
- You are unsure whether behavior belongs in Laravel, Svelte, or Lumi.
- Two sources of truth disagree and code has not been inspected.

Minor ambiguity → simplest reversible solution.  
Domain/security ambiguity → investigate; do not guess.

---

## 12. Document map

| File | Purpose |
| ---- | ------- |
| [`README.md`](../README.md) | Human setup, scripts, short rules |
| **`docs/SPEC.md`** (this file) | Domain + architecture + principles |
| [`docs/STATUS.md`](STATUS.md) | Verified implementation state, gaps, next work |
| `../lumi-ui/docs/*` | Lumi contracts |

Historical task files (`TASK.md`, `Task2.md`, root roadmaps) are superseded by this pair.
Do not reintroduce parallel “truth” documents.
