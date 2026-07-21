# Aeduca v8 — Implementation Status

> **Role:** verified current implementation state.
>
> Permanent rules: [`SPEC.md`](SPEC.md).
> Active execution: root [`TASK.md`](../TASK.md).

**Reviewed against public `main`: July 20, 2026.**  
Local unpushed changes may differ and must be inspected by the executing agent.

## 1. Closed verticals

| Vertical             | Implemented scope                                                                                                          | State |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------- | ----- |
| Access foundation    | `AuthAccount`, employee profile, login/logout, active-state revalidation, session branch, shared Inertia auth props        | Done  |
| Branches             | Unified branch selection and branch catalog attributes                                                                     | Done  |
| Employees / Usuarios | List, transactional create, profile panels, role/branch assignment, credential and password management, direct permissions | Done  |
| Roles                | Role CRUD and assignable permission scope                                                                                  | Done  |
| Permission model     | Direct grants intersected with role scope; superadministrator; manage→view dependency                                      | Done  |
| Academic structure   | Cycle CRUD scoped to current branch, cycle degrees, academic groups, cycle shifts, transactional aggregate write           | Done  |
| Quality baseline     | Pint, PHPUnit, TypeScript, Oxlint, Prettier, production build commands                                                     | Done  |

## 2. Live access model

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
role = category
role scope = assignable permissions
user permissions = actual grants
effective = grants ∩ scope
superadministrator = all known permissions
```

Single ownership:

```text
employee administration owns user_branches writes
branch administration owns branch attributes only
```

Current branch:

```text
session current_branch_code
→ validated active membership
```

## 3. Live academic structure

```text
academic_cycles (branch_code FK, name, level, modality, start_date, end_date, is_active)
cycle_degrees (cycle_code FK, number 1–6, UNIQUE(cycle_code, number))
academic_groups (cycle_degree_code FK, name, sort_order, is_active, unique lower(btrim(name)) per degree)
cycle_shifts (cycle_code FK, name, entry_time, tolerance_minutes >= 0, sort_order, is_active)
```

```text
AcademicCycle is the aggregate owner
SaveCycle = one transactional write (attributes + shifts + degrees + groups)
level: AcademicLevel enum (primary 1–6, secondary 1–5)
modality: CycleModality enum (regular, verano, intensivo, reforzamiento, virtual)
authorization: cycles.view / cycles.manage, scoped to BranchContext
```

## 4. Live application shape

```text
app/
├── Actions/
│   ├── AuthenticateEmployee
│   ├── LogoutEmployee
│   ├── SelectBranch
│   ├── SaveBranch
│   ├── SaveCycle
│   ├── CreateEmployee
│   ├── UpdateEmployee
│   ├── SaveRole
│   └── SyncUserPermissions
├── Support/
│   ├── Authorization/
│   │   ├── PermissionResolver
│   │   └── PermissionDependency
│   ├── Academic/
│   │   ├── AcademicLevel
│   │   └── CycleModality
│   └── Branches/
│       └── BranchContext
└── Http/
    └── administrative controllers and requests

resources/js/
├── Pages/Branches/
├── Pages/Cycles/
├── Pages/Admin/Employees/
├── Pages/Admin/Roles/
├── Layouts/
└── lib/
```

Authorization is semantic and enforced before protected form handling.

The frontend uses one permission helper for presentation only.

## 5. Current UI contract

- One dashboard shell.
- One navigation source.
- Unified branch picker/catalog.
- Cycle index: card grid with summary counts (degrees, groups), no nested eager loads.
- Cycle form: one calm page with General (identity, dates, shifts) and Academic structure (grade tags, sections per grade).
- Employee creation is one coherent form.
- Employee profile panels: General, Access, Permissions.
- Role scope editor represents assignable permissions, not automatic grants.
- Global Inertia flash notifications are owned by the dashboard layout.
- No physical employee deletion.
- No empty attendance, card, or finance tabs.

## 6. Not implemented

No student-facing domain is implemented yet:

- students and contacts;
- enrollment;
- attendance;
- finance;
- evaluations/OMR;
- attentions;
- student portal.

## 7. Active next vertical

**Students and minimal contacts**, then **enrollment** referencing `academic_group_code` and cycle shifts through an explicit `enrollment_shifts` relation.

## 8. Completion condition for the next vertical

Enrollment is complete when:

- one active enrollment per student system-wide;
- enrollment references one academic group and one or both cycle shifts;
- obligations are generated during enrollment;
- no meaning is reconstructed from concatenated codes or repeated group strings.

## 9. Verification position

This document reflects the state after the academic structure vertical passed:

```bash
composer run format
composer run check
pnpm run build
php artisan migrate:fresh --seed --env=testing
php artisan test   # 92 passed
```

Never run `migrate:fresh` against `aeduca`.
