# Aeduca v8 — Verified Status

> Current implementation facts only. Permanent decisions: [`SPEC.md`](SPEC.md). Temporary execution: root `TASK.md`, when present.

**Verified working tree:** July 21, 2026.

## 1. Completed verticals

| Vertical           | Implemented                                                                                                    |
| ------------------ | -------------------------------------------------------------------------------------------------------------- |
| Access             | `AuthAccount`, employee identity, login/logout, active-state revalidation, session branch, shared Inertia auth |
| Branches           | Unified branch selection and minimal branch catalog                                                            |
| Employees          | List/create/profile, role and branch assignment, credentials/password, direct permissions                      |
| Roles              | Role CRUD and assignable permission scope                                                                      |
| Authorization      | Direct grants intersected with role scope, superadministrator, manage→view dependency                          |
| Academic structure | Branch-scoped cycle aggregate with degrees, groups, shifts, transactional save                                 |
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

- Employee administration exclusively writes `user_branches`.
- Branch administration writes branch attributes only.
- Authorization is semantic and enforced before form handling.
- Shared Inertia auth exposes effective permission names; the frontend has one presentation-only `can()`.
- `AuthAccount` is Laravel's authenticated actor; `User` remains the employee profile.
- Login normalizes the identifier, throttles by identifier plus IP, checks account/employee/role activity with a non-enumerating failure, rehashes when needed, and records `last_login_at`.
- Zero active branches blocks login; one is selected automatically; multiple branches use the authenticated shell selector.
- Authenticated requests revalidate identity and branch state; logout invalidates the session and regenerates the CSRF token.
- `BranchContext` memoizes authorized branches only inside the current request; no persistent branch or permission cache exists.

## 3. Academic implementation

```text
academic_cycles
  branch_code FK, name, level, modality, start_date, end_date, is_active

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
- `AcademicLevel`: primary 1–6, secondary 1–5.
- `CycleModality`: regular, verano, intensivo, reforzamiento, virtual.
- Permissions: `cycles.view` / `cycles.manage`, scoped through `BranchContext`.

### Cycle queries and UI

- Index: one branch-scoped query with degree/group counts; no nested eager loading.
- Each card shows identity, state, level, modality, dates, counts, and derived timeline progress.
- Timeline status/percentage/label is computed in Laravel from loaded dates using `America/Lima`; it adds no database query and is not persisted.
- Detail: one cycle with ordered degrees/groups/shifts.
- One Lumi-tab form: General, Turnos, Grados y secciones.
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

## 5. Not implemented

- students and contacts;
- enrollment and obligations;
- attendance;
- finance/cashbox;
- evaluations/OMR;
- attentions;
- student portal.

## 6. Next vertical

**Students and minimal contacts**, then enrollment.

Enrollment completion requires:

- one active enrollment per student system-wide;
- one `academic_group_code`;
- one or both shifts through `enrollment_shifts`;
- obligations generated in the enrollment transaction;
- no reconstructed meaning from codes or repeated group strings.

## 7. Verification

- `composer run format`: passed.
- `composer run check`: passed, including 96 PHPUnit tests / 418 assertions, strict TypeScript, Oxlint, and Prettier.
- `pnpm run build`: passed production build.

Never run `migrate:fresh` against `aeduca`; use `--env=testing` only when schema or seeds change.
