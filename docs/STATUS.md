# Aeduca v8 — Implementation Status

> **Role:** verified current implementation state.
>
> Permanent rules: [`SPEC.md`](SPEC.md).
> Active execution: root [`TASK.md`](../TASK.md).

**Reviewed against public `main`: July 20, 2026.**  
Local unpushed changes may differ and must be inspected by the executing agent.

## 1. Closed verticals

| Vertical | Implemented scope | State |
| --- | --- | --- |
| Access foundation | `AuthAccount`, employee profile, login/logout, active-state revalidation, session branch, shared Inertia auth props | Done |
| Branches | Unified branch selection and branch catalog attributes | Done |
| Employees / Usuarios | List, transactional create, profile panels, role/branch assignment, credential and password management, direct permissions | Done |
| Roles | Role CRUD and assignable permission scope | Done |
| Permission model | Direct grants intersected with role scope; superadministrator; manage→view dependency | Done |
| Quality baseline | Pint, PHPUnit, TypeScript, Oxlint, Prettier, production build commands | Done |

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

## 3. Live application shape

```text
app/
├── Actions/
│   ├── AuthenticateEmployee
│   ├── LogoutEmployee
│   ├── SelectBranch
│   ├── SaveBranch
│   ├── CreateEmployee
│   ├── UpdateEmployee
│   ├── SaveRole
│   └── SyncUserPermissions
├── Support/
│   ├── Authorization/
│   │   ├── PermissionResolver
│   │   └── PermissionDependency
│   └── Branches/
│       └── BranchContext
└── Http/
    └── administrative controllers and requests

resources/js/
├── Pages/Branches/
├── Pages/Admin/Employees/
├── Pages/Admin/Roles/
├── Layouts/
└── lib/
```

Authorization is semantic and enforced before protected form handling.

The frontend uses one permission helper for presentation only.

## 4. Current UI contract

- One dashboard shell.
- One navigation source.
- Unified branch picker/catalog.
- Employee creation is one coherent form.
- Employee profile panels: General, Access, Permissions.
- Role scope editor represents assignable permissions, not automatic grants.
- Global Inertia flash notifications are owned by the dashboard layout.
- No physical employee deletion.
- No empty academic, attendance, card, or finance tabs.

## 5. Not implemented

No academic structure is implemented yet:

- cycles;
- cycle degrees;
- groups/sections;
- shifts.

Also not implemented:

- students and contacts;
- enrollment;
- attendance;
- finance;
- evaluations/OMR;
- attentions;
- student portal.

## 6. Active next vertical

**Academic structure**

Target:

```text
academic_cycles
cycle_degrees
academic_groups
cycle_shifts
```

Purpose:

```text
future student
→ enrollment
→ academic group
→ cycle + grade + branch
→ selected shifts
→ attendance / evaluations / obligations
```

Decisions:

- no global `academic_degrees` CRUD or catalog in v1;
- grade is represented by the cycle-specific `cycle_degrees` entity;
- no `academic_levels` table in v1;
- cycle stores level and modality separately;
- groups are configurable;
- shifts are rows, not `turn_1` / `turn_2` columns.

The active execution guide lives in `TASK.md`.

## 7. Completion condition for the next vertical

The academic foundation is complete when:

- an authorized user manages cycles in the current branch;
- a cycle may cross calendar years;
- a cycle offers valid grade numbers for its level;
- each cycle degree owns configurable groups;
- a cycle owns one or two attendance shifts;
- no meaning is reconstructed from concatenated codes or repeated group strings;
- future enrollment can reference one academic group and one or both valid shifts;
- project checks pass;
- this document is updated with the real implementation.

## 8. Verification position

This document does not claim a new local test run.

Before implementation and after every schema change, run:

```bash
composer run format
composer run check
pnpm run build
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against `aeduca`.
