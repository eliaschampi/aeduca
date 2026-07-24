# Aeduca v8 — Verified Status

> Current implementation facts only. Permanent decisions: [`SPEC.md`](SPEC.md). Temporary execution: root `TASK.md`, when present.

**Implementation inventory reviewed:** July 24, 2026.

**Last full automated verification:** July 24, 2026.

## 1. Completed implementation

| Vertical           | Implemented                                                                                                   |
| ------------------ | ------------------------------------------------------------------------------------------------------------- |
| Access             | One `AuthAccount` owner for employees/students, actor-aware login/logout and request revalidation             |
| Branches           | Unified branch selection and minimal branch catalog                                                           |
| Employees          | List/create/profile, role and branch assignment, credentials/password, direct permissions                     |
| Roles              | Role CRUD and assignable permission scope                                                                     |
| Authorization      | Direct grants intersected with role scope, superadministrator, manage→view dependency, student self ownership |
| Academic structure | Branch-scoped cycle aggregate with degrees, groups, shifts, and transactional save                            |
| Students           | Institutional/shell search, composed profile, cropped private photo, contacts, access, authorized history     |
| Enrollment         | One row per student/cycle, atomic per-cycle roll reservation, derived history and active section roster       |
| Quality            | Pint, PHPUnit, strict TypeScript, Oxlint, Prettier, production build                                          |

## 2. Access implementation

```text
auth_accounts
├── user_code nullable FK
└── student_code nullable FK

CHECK(exactly one owner)
partial UNIQUE per owner
```

```text
effective permission = user grant ∩ role scope
superadministrator = all known permissions
session current_branch_code → validated active membership
```

- `AuthAccount` authenticates exactly one employee or student owner.
- `User` owns the employee profile; teachers currently use this employee identity model.
- `Student` owns student identity; DNI is the student account login.
- Employee administration exclusively writes `user_branches`.
- Branch administration writes branch attributes only.
- Authorization is semantic and enforced before form handling.
- Shared Inertia auth is actor-discriminated. Employees receive branches and effective permissions; students receive neither.
- Login normalizes the identifier, throttles by identifier plus IP, checks the relevant account/identity activity with a non-enumerating failure, rehashes when needed, and records `last_login_at`.
- Zero active branches blocks login; one is selected automatically; multiple branches use the authenticated shell selector.
- Employee requests revalidate identity, role, and branch state. Student requests revalidate account/student state and are limited to their own profile.
- Student login does not require an active enrollment.
- Enabling or resetting student access returns a cryptographically random temporary password only in the immediate no-store response; only its hash is persisted.
- Logout invalidates the session and regenerates the CSRF token.
- `BranchContext` memoizes authorized branches only inside the current request; no persistent branch or permission cache exists.

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

## 4. Student registry and enrollment

```text
students
student_contacts
enrollments
enrollment_shifts
```

- Student identity is institution-wide, uses UUID `code`, and has a normalized unique eight-digit DNI.
- Photos are selected and square-cropped from the existing profile, optimized to WebP in the browser, stored on the private local disk, and served by an authorized private response. Replacement deletes the prior asset only after a successful write.
- `/students/search` is a paginated institutional directory backed by `student_directory`; it ranks exact DNI, active `roll_code`, then approximate name.
- The authenticated employee shell exposes a debounced, ten-result student lookup that reuses `student_directory`.
- The profile composes identity, access, contacts, and at most ten authorized enrollment summaries. Employee history is restricted to authorized branches; student self-service sees only its owner.
- `students.view` / `students.manage` own staff registry access. Contacts and credentials do not create button-level permissions.
- `SaveEnrollment` owns the aggregate transaction, locks the student, validates current branch and group/shift cycle, and never deactivates or replaces another enrollment.
- PostgreSQL protects one enrollment per `(student_code, cycle_code)` and one `roll_code` per cycle. `cycle_code` is derived from the server-validated group.
- `reserve_enrollment_roll_code(cycle_code)` serializes reservation per cycle and returns a four-digit code from `0001` to `9999`.
- Retrying enrollment in the same cycle redirects to editing the existing row; another unfinished cycle is rejected. Editing preserves enrollment and roll identities and cannot move the row to another cycle.
- New enrollments are always created active; only the edit workflow accepts an explicit activity change.
- `student_enrollment_overview` derives active, inactive, or finalized presentation from the enrollment boolean and cycle end date. No finalized state is persisted or scheduled, and finished rows are read-only.
- Current active and inactive enrollments precede finalized history in directory and profile reads, so an editable current record is not hidden by the ten-row history bound.
- `student_enrollment_overview`, `student_directory`, and `student_roster` are the named composed read models.
- `/students` reads only active rows from `student_roster` after cycle, degree, and section are complete and valid for the current branch. Text and pagination stay within that section; no broad, shift, state, or “all” roster mode is exposed.
- The last valid cycle/degree/section is remembered in the authenticated session per branch. A bare return to `/students` revalidates it and redirects to the complete canonical URL; stale contexts are discarded.
- Unfinished inactive enrollments retain their group/shift context for editing; ended cycle history is read-only.
- Physical enrollment deletion and Payments were deliberately omitted.

## 5. Application UI

- One authenticated dashboard shell.
- One navigation source and global Inertia flash owner.
- Unified branch picker/catalog.
- Cycle and catalog indexes load summaries.
- Employee creation is one form; employee profile panels are General, Access, Permissions.
- Role scope editor represents assignable permissions, not grants.
- Student navigation opens the institutional directory; the shell also exposes student lookup globally to authorized employees.
- Student create/edit uses placeholders and cohesive fieldsets; photo management exists only in the profile.
- The profile uses a non-stretching cover/identity card, compact data, identity state beside the student, one action menu, and focused access/contact/enrollment panels.
- The current-branch active roster uses a responsive Lumi sidebar, requires cycle/degree/section, and paginates on the server.
- Student access credentials exist only in the one-time browser dialog and are cleared when it closes.
- Student self-service reuses the authenticated shell with only **Mi perfil** navigation.
- No physical employee/enrollment deletion, fake card action, or empty future tabs.

## 6. Not implemented

- individual student card/PDF and QR;
- payments, cashbox, or payment reporting;
- student or employee attendance;
- evaluations, OMR, or score reports;
- attentions;
- shared-file access.

## 7. Verification record

Current implementation verification:

- `php artisan migrate:fresh --seed --env=testing`: passed against `aeduca_test`.
- `composer run format`: passed.
- `composer run check`: passed, including 137 PHPUnit tests / 680 assertions, strict TypeScript, Oxlint, and Prettier.
- `pnpm run build`: passed production build.

The local `aeduca` database was not migrated or seeded.
