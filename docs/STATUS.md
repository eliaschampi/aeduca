# Aeduca v8 — Verified Status

> Current implementation facts only. Permanent decisions: [`SPEC.md`](SPEC.md). Temporary execution: root `TASK.md`, when present.

**Implementation inventory reviewed:** August 4, 2026.

**Last complete verification:** August 4, 2026.

## 1. Completed implementation

| Vertical           | Implemented                                                                                                          |
| ------------------ | -------------------------------------------------------------------------------------------------------------------- |
| Access             | One `AuthAccount` owner for employees/students, actor-aware login/logout and request revalidation                    |
| Branches           | Unified branch selection and minimal branch catalog                                                                  |
| Employees          | List/create/admin profile, personal `/profile` self-photo, versioned private photo (shared helper + cropper), access |
| Roles              | Role CRUD and assignable permission scope                                                                            |
| Authorization      | Direct grants intersected with role scope, superadministrator, manage/delete→view dependencies, self ownership       |
| Academic structure | Branch-scoped cycle aggregate with degrees, groups, shifts, and transactional save                                   |
| Students           | Institutional/shell search, composed profile, versioned private photo via shared helper + cropper, contacts          |
| Enrollment         | One row per student/cycle, atomic per-cycle roll reservation, derived history and active section roster              |
| Attendance         | DNI scan/list/manual/history/PDF, business-date enrollment start default, cycle integrity freezes after facts        |
| Quality            | Pint, PHPUnit, TypeScript, Oxlint, Prettier y build verificados                                                      |

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
user preferred_branch_code → future-login preference only
```

- `AuthAccount` authenticates exactly one employee or student owner.
- `User` owns the employee profile; teachers currently use this employee identity model.
- `Student` owns student identity; DNI is the student account login.
- Employee administration exclusively writes `user_branches`.
- Branch administration writes branch attributes only.
- Authorization is semantic and enforced before form handling.
- Each permission stores its required Spanish group label and description; the role editor consumes that database contract without frontend translation maps or labels derived from technical names.
- Shared Inertia auth is actor-discriminated. Employees receive branches and effective permissions; students receive neither.
- Login normalizes the identifier, throttles by identifier plus IP, checks the relevant account/identity activity with a non-enumerating failure, rehashes when needed, and records `last_login_at`.
- Zero active branches blocks login. A valid explicit preference is restored; otherwise one active branch is selected automatically, while multiple branches use the authenticated shell selector.
- Explicit branch selection updates the persistent preference and only the current session. Invalid preferences are cleared at login or when employee administration revokes that active membership.
- Employee requests revalidate identity, role, and branch state. Student requests revalidate account/student state and are limited to their own profile.
- Student login does not require an active enrollment.
- Enabling/re-enabling and resetting student access are distinct: reset requires an active account, while disabled access must be enabled explicitly. Both credential operations return a cryptographically random temporary password only in the immediate no-store response; only its hash is persisted.
- Logout invalidates the session and regenerates the CSRF token.
- `BranchContext` memoizes authorized branches only inside the current request, validates the session context without restoring the preference, and creates no persistent branch or permission cache.

## 3. Academic implementation

```text
academic_cycles
  branch_code FK, name, modality, start_date, end_date,
  attendance_includes_saturday, is_active

cycle_degrees
  cycle_code FK, number 1–6, UNIQUE(cycle_code, number)

academic_groups
  cycle_degree_code FK, name, sort_order, is_active
  unique lower(btrim(name)) per degree

cycle_shifts
  cycle_code FK, name, entry_time, tolerance_minutes >= 0, sort_order, is_active
```

- `AcademicCycle` owns degrees → groups and shifts.
- Each cycle explicitly owns a Monday–Friday (default) or Monday–Saturday attendance week; Sunday remains excluded.
- `SaveCycle` writes the aggregate transactionally and rejects a cycle from another branch.
- `DegreeNumber` owns the supported 1–6 range and its presentation labels.
- Current `CycleModality`: regular, verano, intensivo, reforzamiento, virtual.
- Permissions are `cycles.view` / `cycles.manage`, scoped through `BranchContext`.
- Cycle identity comes only from `name`; offered degrees are explicit and independent of name and modality.
- The base migration, payloads, validation, tests, and UI contain no duplicate cycle `level` field.

### Queries and UI

- Index uses one branch-scoped query with degree/group counts and no nested eager loading.
- Each card shows identity, state, modality, dates, counts, and derived timeline progress.
- Timeline status/percentage/label is computed in Laravel from loaded dates using `aeduca.business_timezone`; it adds no query and is not persisted.
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
- `students.delete` is independent from management. `DeleteStudent` locks the identity, rejects any enrollment in any branch, deletes database-owned contacts/account/sessions through FKs, and removes the private photo after commit.
- `SaveEnrollment` owns the aggregate transaction, locks the student, validates current branch and group/shift cycle, prevents detaching a shift with recorded attendance, and never deactivates or replaces another enrollment.
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
- `enrollments.delete` is independent from management and removes active, inactive, or finalized current-branch enrollment rows only when they have no attendance facts; PostgreSQL protects the attendance relation and otherwise cascades `enrollment_shifts` while preserving the student.
- Profile enrollment rows retain authorized cross-branch visibility but expose write/delete actions only for the current branch.
- Payments remains unimplemented; no provisional payment table, relation, or runtime check exists.

### Student attendance

```text
student_attendances
  FK(enrollment_code, cycle_shift_code) -> enrollment_shifts
  UNIQUE(enrollment_code, cycle_shift_code, attendance_date)
  states: present | late | permission | justified
  pending/absent derived only
```

- `SaveStudentAttendance` is the sole fact write owner (scan + manual ops). An operational expectation requires active enrollment, cycle, section, and shift; there is no slot/SCD table. `SaveEnrollment` only protects a referenced shift from detachment.
- `student_attendance_is_expected_day(date, includes_saturday)` is the immutable shared SQL predicate for the daily list, scan resolution, manual expectation validation, and generated history.
- Scan is camera-first (`qr-scanner`, continuous decode; no primary submit button on the hot path) plus optional plain eight-digit DNI keyboard/wedge input that auto-registers at 8 digits. Server resolves branch and exactly one entry−tolerance through entry+tolerance window. Outside or across dual windows it rejects without inserting. Repeat scans return _Ya registrada_ without `UPDATE`.
- Daily list mirrors Matriculados (Lumi `Table` server pagination, `UserInfo`, sidebar filters): date/cycle/degree/section/shift, expected rows with LEFT JOIN facts, and all derived/stored state summary counts. It retains an inactive identity when its enrollment remains active; DNI scan does not.
- Manual dialog: arrival, permission (before entry), justify (after close), or correction of an existing fact (reason + corrector).
- Focused history at `/students/{student}/attendance` selects one visible enrollment and exactly one assigned shift, normalizes an inclusive maximum 93-date range, derives expected rows with bounded PostgreSQL `generate_series`, and left-joins stored facts. Staff are current-branch scoped; student self-service may select owned contexts across branches.
- History keeps academic context once outside the row payload, retains inactive/historical current relations, and displays derived pending/falta rows without storing absences. Holidays, suspensions, and temporal reconstruction of past section/shift assignments remain unavailable.
- History uses one compact context/filter surface, keeps the table to date/state/arrival/reason, and exposes a lazily generated multipage A4 **operational attendance report** (Spanish title/filename aligned; holiday limitation in the footer). Bounded single-shift Inertia rows feed Lumi pagination and the browser PDF without a second response mode or stored artifact.
- `enrollments.attendance_starts_on` gates expected days on roster, scan, manual expectation, and history `generate_series`.
- `SaveCycle` preflight-rejects deleting referenced shifts/groups/degrees with ValidationException; freezes attendance-sensitive cycle/shift clocks after facts exist while allowing name edits and end-date extension.
- Cross-cycle enrollment corruption is rejected by `SaveEnrollment` and detectable via reconciliation SQL (integrity tests cover both).
- Permissions: `attendance.view` / `attendance.manage`.

## 5. Application UI

- One authenticated dashboard shell.
- One navigation source and global Inertia flash owner.
- Branch-dependent navigation is hidden without an effective session branch and reappears when login restores one; direct Laravel guards remain authoritative.
- **Asistencia** navigation opens the daily list; **Escanear** is a separate camera/DNI page with no academic selectors.
- Unified branch picker/catalog.
- Cycle and catalog indexes load summaries.
- Employee creation is one form; administrative employee profile panels are General, Access, Permissions.
- The user menu opens **Mi perfil** (`/profile`): identity summary plus self photo change via the shared cropper; shell avatars use the shared versioned `photo_url` and refresh after replacement.
- Role scope editor represents assignable permissions, not grants, and renders its Spanish groups from the database catalog.
- Student navigation opens the institutional directory; the shell also exposes student lookup globally to authorized employees.
- Student create/edit uses placeholders and cohesive fieldsets; photo management exists only in the profile.
- The profile uses a non-stretching cover/identity card, compact data, identity state beside the student, one action menu, and focused access/contact/enrollment panels.
- The current-branch active roster uses a responsive Lumi sidebar, requires cycle/degree/section, and paginates on the server.
- Student access credentials exist only in the one-time browser dialog and are cleared when it closes.
- From the employee profile's existing action menu, an active student with a private photo and active enrollment can generate an individual CR80 (85.6 × 53.98 mm) card in a new browser tab. The client loads the PDF/QR code only after the click, embeds the institutional template and authorized photo, and encodes the plain DNI in the QR; no server PDF, route, or stored artifact exists.
- Student self-service reuses the authenticated shell with only **Mi perfil** navigation.
- No physical employee deletion, fake card action, or empty future tabs.
- Student and enrollment destructive actions use compact Lumi dropdowns and explicit confirmation dialogs; management permissions do not expose them.

## 6. Not implemented

- payments, cashbox, or payment reporting;
- employee/teacher attendance;
- evaluations, OMR, or score reports;
- attentions;
- shared-file access;
- v7 attendance data import runner (schema is migration-ready).

## 7. Verification record

Current implementation verification:

- `php artisan migrate:fresh --seed --env=testing`: passed against `aeduca_test`.
- `composer run format`: passed.
- `composer run check`: passed, including Pint, 202 PHPUnit tests / 1307 assertions, TypeScript, Oxlint and Prettier.
- `pnpm run build`: passed.
- A representative 93-date, one-shift attendance-history plan against 5,000 fact rows returned 80 rows in 0.240 ms through `student_attendances_history_index` with no attendance full scan. The existing indexes were sufficient; no index was added.

The local `aeduca` database was rebuilt and seeded on July 25, 2026, after explicit project-owner approval.
