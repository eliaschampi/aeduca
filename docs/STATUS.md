# Aeduca v8 — Verified Status

> Current implementation facts only. Permanent decisions: [`SPEC.md`](SPEC.md). Temporary execution: root `TASK.md`, when present.

**Implementation inventory reviewed:** July 23, 2026.

**Last full automated verification:** July 23, 2026.

## 1. Completed implementation

| Vertical           | Implemented                                                                                                    |
| ------------------ | -------------------------------------------------------------------------------------------------------------- |
| Access             | `AuthAccount`, employee identity, login/logout, active-state revalidation, session branch, shared Inertia auth |
| Branches           | Unified branch selection and minimal branch catalog                                                            |
| Employees          | List/create/profile, role and branch assignment, credentials/password, direct permissions                      |
| Roles              | Role CRUD and assignable permission scope                                                                      |
| Authorization      | Direct grants intersected with role scope, superadministrator, manage→view dependency                          |
| Academic structure | Branch-scoped cycle aggregate with degrees, groups, shifts, and transactional save                             |
| Quality            | Pint, PHPUnit, strict TypeScript, Oxlint, Prettier, production build                                           |

## 2. Access implementation

```text
branches
employee_roles
permissions
employee_role_permission_scopes
users
user_branches
user_permissions
auth_accounts
```

```text
effective permission = user grant ∩ role scope
superadministrator = all known permissions
session current_branch_code → validated active membership
```

- `AuthAccount` currently authenticates employees through a required unique `user_code`.
- `User` owns the employee profile; teachers currently use this employee identity model.
- Employee administration exclusively writes `user_branches`.
- Branch administration writes branch attributes only.
- Authorization is semantic and enforced before form handling.
- Shared Inertia auth exposes effective permission names; the frontend has one presentation-only `can()`.
- Login normalizes the identifier, throttles by identifier plus IP, checks account/employee/role activity with a non-enumerating failure, rehashes when needed, and records `last_login_at`.
- Zero active branches blocks login; one is selected automatically; multiple branches use the authenticated shell selector.
- Authenticated requests revalidate identity and branch state; logout invalidates the session and regenerates the CSRF token.
- `BranchContext` memoizes authorized branches only inside the current request; no persistent branch or permission cache exists.
- Student accounts, student login, and student self-service are not implemented.

## 3. Academic implementation

```text
academic_cycles
  branch_code FK, name, modality, start_date, end_date, is_active

cycle_degrees
  cycle_code FK, number 1–6, UNIQUE(cycle_code, number)

academic_groups
  cycle_degree_code FK, name, sort_order, is_active
  unique lower(btrim(name)) per degree

cycle_shifts
  cycle_code FK, name, entry_time, tolerance_minutes >= 0, sort_order, is_active
```

- `AcademicCycle` owns degrees → groups and shifts.
- `SaveCycle` writes the aggregate transactionally and rejects a cycle from another branch.
- `DegreeNumber` owns the supported 1–6 range and its presentation labels.
- Current `CycleModality`: regular, verano, intensivo, reforzamiento, virtual.
- Permissions are `cycles.view` / `cycles.manage`, scoped through `BranchContext`.
- Cycle identity comes only from `name`; offered degrees are explicit and independent of name and modality.
- The base migration, payloads, validation, tests, and UI contain no duplicate cycle `level` field.

### Queries and UI

- Index uses one branch-scoped query with degree/group counts and no nested eager loading.
- Each card shows identity, state, modality, dates, counts, and derived timeline progress.
- Timeline status/percentage/label is computed in Laravel from loaded dates using `America/Lima`; it adds no query and is not persisted.
- Detail loads one cycle with ordered degrees/groups/shifts.
- One Lumi-tab form owns General, Turnos, and Grados y secciones.
- Form state survives tab changes; validation reveals and marks affected tabs.
- Viewers get a read-only aggregate; create/edit/add/remove/save require `cycles.manage`.

## 4. Application UI

- One authenticated dashboard shell.
- One navigation source and global Inertia flash owner.
- Unified branch picker/catalog.
- Cycle and catalog indexes load summaries.
- Employee creation is one form; employee profile panels are General, Access, Permissions.
- Role scope editor represents assignable permissions, not grants.
- No physical employee deletion or empty future tabs.

## 5. Not implemented in `main`

- student identity, photo, state, contacts, account, login, directory, global search, or profile;
- enrollment, academic roster, cycle/degree/group filters, history, card, or QR;
- payments, cashbox, or payment reporting;
- student or employee attendance;
- evaluations, OMR, or score reports;
- attentions;
- student portal or shared-file access.

No `students.*`, `enrollments.*`, or `payments.*` permissions exist in the current catalog.

## 6. Next implementation: student registry and access

The next product task must define an observable result containing:

- student identity with DNI, photo, and active/inactive state;
- extension of the existing `AuthAccount` owner for student credentials;
- shared login with student self authorization;
- institution-wide `/students/search` directory and profile entry;
- profile hub with access state and bounded domain summaries;
- `students.view` / `students.manage` without per-button permissions.

Enrollment then owns the branch-aware `/students` academic roster, its cycle/degree/group filters, history, and Payments. It must not be declared complete without those list/filter/profile flows.

## 7. Verification record

Current implementation verification:

- `php artisan migrate:fresh --seed --env=testing`: passed against `aeduca_test`.
- `composer run format`: passed.
- `composer run check`: passed, including 98 PHPUnit tests / 422 assertions, strict TypeScript, Oxlint, and Prettier.
- `pnpm run build`: passed production build.

The local `aeduca` database was not migrated or seeded.
