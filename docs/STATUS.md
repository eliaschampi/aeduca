# Aeduca v8 — Verified Status

> Current implementation facts only. Permanent decisions: [`SPEC.md`](SPEC.md). Temporary execution: root `TASK.md`, when present.

**Verified working tree:** July 23, 2026.

## 1. Completed verticals

| Vertical           | Implemented                                                                                                    |
| ------------------ | -------------------------------------------------------------------------------------------------------------- |
| Access             | `AuthAccount`, employee identity, login/logout, active-state revalidation, session branch, shared Inertia auth |
| Branches           | Unified branch selection and minimal branch catalog                                                            |
| Employees          | List/create/profile, role and branch assignment, credentials/password, direct permissions                      |
| Roles              | Role CRUD and assignable permission scope                                                                      |
| Authorization      | Direct grants intersected with role scope, superadministrator, manage→view dependency                          |
| Academic structure | Branch-scoped cycle aggregate with degrees, groups, shifts, transactional save                                 |
| Students           | Institution-wide identity, profile-managed contacts, directory, create/edit, and canonical profile             |
| Enrollment         | Academic assignment, shifts, active replacement, initial obligations, profile history, and edit                |
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
- Permissions now include `students.*` and `enrollments.*`; enrollment view also expands to student view, while role scope remains explicit and never depends on a role name.

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
- Removing a group or shift with enrollment history deactivates it; only unreferenced removed structure is deleted.
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

## 4. Student implementation

```text
students
  code UUID PK, unique eight-digit DNI, identity/contact fields, timestamps

student_contacts
  code UUID PK, student_code FK, positive position, name, phone, note
  UNIQUE(student_code, position), cascade with student
```

- `Student` owns ordered contacts; neither model owns a branch or academic relation.
- `StudentRequest` and `SaveStudent` own student fields only; concurrent DNI conflicts map to field validation.
- `StudentContactRequest` normalizes one contact. `CreateStudentContact` serializes append position assignment; nested update/delete routes own the existing row.
- PostgreSQL protects DNI shape/uniqueness, nonblank names, positive contact positions, ownership, and per-student position uniqueness without an arbitrary contact count.
- Permissions are `students.view` / `students.manage` with the standard manage→view dependency.
- Directory queries are institution-wide, select displayed fields only, show recent students or ranked search results, and paginate 10 rows without contacts.
- Profile queries load ordered contacts and, only with `enrollments.view`, the student's enrollment aggregate with section, cycle, branch, shifts, and obligation summaries. Student edit still loads identity fields only.

## 5. Enrollment implementation

```text
enrollments
  student_code FK, academic_group_code FK, four-digit roll_code,
  is_active, observation, timestamps
  one active row per student and active roll code through partial UNIQUE indexes

enrollment_shifts
  enrollment_code FK, cycle_shift_code FK, composite PK

payment_obligations
  enrollment_code FK, concept, NUMERIC(12,2) amount, due_date, timestamps
```

- `EnrollmentRequest` normalizes the assignment and obligation collection; relational branch/cycle/shift ownership is verified by `SaveEnrollment`.
- `SaveEnrollment` locks the student, validates authorized branch and active academic structure, deactivates a previous active enrollment, reserves an available roll code, syncs one or two shifts, and creates/updates stable obligation identities in one transaction.
- PostgreSQL independently protects UUID relations, four-digit roll format, positive/nonblank obligations, one active enrollment per student, and active roll-code uniqueness.
- Create/edit routes require `enrollments.manage`; institution-wide history is shown only with `enrollments.view`.
- The form offers eligible cycles across authorized branches, preferring the current branch, and never reconstructs branch, grade, section, or shift from codes.
- Enrollment has no physical delete route. Payments, applications, and cash movements are not implemented.

## 6. Application UI

- One authenticated dashboard shell.
- One navigation source and global Inertia flash owner.
- Unified branch picker/catalog.
- Cycle and catalog indexes load summaries.
- Student directory uses server search/pagination and distinct empty/no-match states.
- Student create/edit share one identity form. The responsive 30/70 profile uses Matrículas and Contactos tabs; contacts remain dialog CRUD without redirects or count limits.
- Enrollment create/edit is a dedicated Lumi form organized into Asignación académica and Obligaciones, with validation marking the affected tab.
- Employee creation is one form; employee profile panels are General, Access, Permissions.
- Role scope editor represents assignable permissions, not grants.
- No physical employee deletion or empty future tabs.

## 7. Not implemented

- attendance;
- payments, payment applications, and cashbox;
- evaluations/OMR;
- attentions;
- student portal.

## 8. Next vertical

**Attendance.**

Attendance must reference enrollment and a selected enrollment shift, use cycle entry time/tolerance, and preserve the no-mass-absence rule in `SPEC.md`.

## 9. Verification

- `composer run format`: passed.
- `composer run check`: passed, including 114 PHPUnit tests / 627 assertions, strict TypeScript, Oxlint, and Prettier.
- `pnpm run build`: passed production build without warnings.
- `php artisan migrate:fresh --seed --env=testing`: passed against `aeduca_test` through enrollment migration `000006`.

Never run `migrate:fresh` against `aeduca`; use `--env=testing` only when schema or seeds change.
