# Aeduca v8 — Product and Engineering Specification

> Permanent source of truth for product, domain, data, and architecture decisions. Current implementation belongs in [`STATUS.md`](STATUS.md); temporary execution belongs in root `TASK.md`.

## 1. Product direction

Aeduca v8 improves Aeduca v7 for Carrión. It preserves proven operational workflows, replaces legacy structural debt, and incorporates useful ideas from Coedula and Nextya in one platform for administrators, teachers, and students.

It is neither a structural clone nor a deliberately reduced replacement. Modernization may simplify the implementation, but it must not remove a confirmed operational result.

| Evidence     | Purpose                                                                        |
| ------------ | ------------------------------------------------------------------------------ |
| Aeduca v8    | Accepted architecture, owners, conventions, and implemented behavior           |
| Aeduca Admin | Operational baseline, migration evidence, and workflows that staff already use |
| Aeduca Aula  | Student portal history                                                         |
| Coedula      | Modern product, PostgreSQL, attendance, payments, Drive, and portal lessons    |
| Nextya       | OMR processing and specialized evaluation reports                              |

For each capability, inspect current v8 first, then the relevant operational and modern evidence. Preserve useful behavior, remove historical coupling, and implement the smallest result that remains usable end to end.

### Boundary

- One institution: Carrión.
- Multiple branches; no SaaS tenants, memberships, or company isolation.
- One application, authentication entry, navigation system, and operational model.
- Administrators and teachers are employees; students are a separate identity domain.
- Spanish end-user interface.
- Migratable from Aeduca v7 and Nextya.

### Product outcomes

The platform must support:

- staff and teacher administration with branch-aware permissions;
- student identity, photo, status, contacts, search, profile, and system access;
- enrollment, academic rosters, filters, history, cards, and payments;
- student and employee attendance with integrated reporting;
- evaluations, scores, OMR, and specialized reports;
- cashbox, attentions, and payment reporting;
- student self-service for profile, attendance, scores, payments, and shared files.

### Delivery direction

```text
Existing access/administration foundation
→ Correct cycle semantics
→ Student registry, search, profile, photo, status, and access
→ Enrollment, filtered academic roster, and payments
→ Student and employee attendance with reports
→ Evaluations and OMR
→ Cashbox and attentions
→ Incrementally complete the student portal from finished domains
```

The portal is not postponed as a separate application. Student authentication and the basic self profile arrive with the student vertical; attendance, evaluations, payments, and files appear as their owning domains become complete.

Chat, posts, likes, comments, task submissions, complete virtual-classroom sessions, and web-form examinations are not current priorities.

### Meaning of complete

A vertical is complete when its stated user can enter the workflow, find the subject, perform the main operation, see the persisted result, and receive the correct authorization and failure behavior.

Tables, CRUD, tests, or a polished form alone do not establish product completion. When the workflow requires a list, search, filters, profile, state transition, account, photo, or related summary, those elements are part of the result rather than optional decoration.

## 2. Technical contract

### Fixed stack

| Layer     | Choice                             |
| --------- | ---------------------------------- |
| Backend   | Laravel 13 · PHP 8.5               |
| Transport | Inertia                            |
| Frontend  | Svelte 5 runes · TypeScript strict |
| UI        | `@lumi-ui/svelte`                  |
| Database  | PostgreSQL                         |
| Packages  | Composer · pnpm                    |
| Locale    | Spanish                            |

The current v8 architecture is the baseline. Extend its `AuthAccount`, permission, branch, Action, FormRequest, controller/Inertia, and Lumi owners before introducing a new abstraction.

Do not introduce authorization packages, auth starter kits, module frameworks, generic repositories/services, DTO libraries that rename arrays, client permission/branch stores, a second UI/CSS system, soft-delete-by-default, persistent caches without measurement, or manual polymorphic relations where explicit FKs work.

### Ownership

| Layer       | Owns                                        | Must not own         |
| ----------- | ------------------------------------------- | -------------------- |
| PostgreSQL  | FK, UNIQUE, CHECK, structural truth         | Hidden workflows     |
| Route       | HTTP entry, semantic authorization          | Domain processing    |
| FormRequest | Input shape, normalization, messages        | Full domain policy   |
| Model       | Relations, casts, UUID config, small scopes | Multi-step processes |
| Action      | Aggregate transactions and invariants       | HTTP presentation    |
| Controller  | Queries/actions and response mapping        | Large domain logic   |
| Svelte      | Interaction and composition                 | Security authority   |
| Lumi        | Domain-neutral primitives                   | Education rules      |

Rules:

- One owner and write path per responsibility.
- Keep the architecture understandable by one developer and coding agents.
- Prefer reuse and cohesive files over speculative layers; never split a file only to reduce line count.
- An Action is justified by a transaction, aggregate write, or real invariant; a simple row update remains direct.
- Use a DTO only at a genuinely complex boundary such as OMR.
- Call mandatory consequences directly. Events require independent consumers; observers never hide critical academic or financial work.
- Never create future infrastructure without a current workflow consumer.

### Data

- Main entities use UUID primary key `code`; FKs use `<entity>_code`.
- Technical identifiers never encode year, branch, level, degree, group, modality, or status.
- DNI is an attribute, never a primary key.
- Human numbers exist only for confirmed external use.
- Many-to-many relations use explicit intermediate tables; relationships never use JSON or arrays.
- Redundant FKs require a documented query/history reason and an integrity guarantee.
- Use explicit readable states, backed PHP enums when useful, string columns, and equivalent PostgreSQL `CHECK` constraints.
- Avoid physical deletion where operational history references a row. Add soft delete only for a confirmed restore/trash workflow.
- Use `date`, `time`, and `timestamptz` by business meaning.
- Server time never chooses the current academic cycle implicitly.
- Money uses `NUMERIC`, never float.
- Confirmed payments/cash movements are voided or reversed, never hard-deleted.
- Business-critical multi-table writes are transactional.
- Never recalculate money silently from ambiguous legacy data.
- JSON is only for genuinely unstructured external evidence.
- Do not hide authorization, academic, attendance, payments, or cashbox behavior in triggers.
- Stable composed read models reused by lists, global search, profiles, or reports use named PostgreSQL views as one database contract.
- Database-owned atomic generation/reservation and cross-query calculations use focused SQL functions; `roll_code` generation is the initial confirmed example.
- Views and functions remain explainable, indexed through their source tables, migration-owned, and tested. They do not authorize requests or hide business workflows.
- Simple validated row CRUD remains direct Eloquent; the database contract is not a mandate to wrap every insert/update in SQL.

### Query policy

- Prevent N+1 deliberately; index real FK, search, and filter paths.
- Index/list pages load displayed fields plus bounded summaries/counts.
- Detail pages compose one subject profile from focused queries.
- Laravel scopes, filters, orders, and paginates the relevant database view; it does not reproduce the same composed join in multiple controllers.
- Do not eager-load nested history on catalogs.
- Exact identifiers rank before fuzzy name matches. PostgreSQL trigram/search support is preferred over unindexed `%term%` scans at operational volume.
- Cache only after measurement.
- Prefer bounded readable queries over clever optimization.

## 3. Access and administration — implemented foundation

### Current schema and meaning

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
role = employee category
role scope = permissions assignable to that role
user permission = direct grant
effective = direct grant ∩ role scope
superadministrator = all known permissions
```

- Each employee has one primary role.
- Role scope does not grant access.
- Grants outside role scope are ineffective.
- Changing role or reducing scope prunes incompatible grants transactionally.
- `*.manage` and confirmed `*.delete` capabilities require matching `*.view` and are normalized on persistence. `manage` never grants `delete`.
- Never authorize by role name/code.
- `is_super_admin` is controlled technical escalation, not a normal form field.
- Every permission stores its mandatory Spanish `group_label` and description. The role editor groups and presents that database data directly; the UI never derives user-facing labels from technical permission names.

Stable implemented vocabulary:

```text
dashboard.view
branches.view
branches.manage
cycles.view
cycles.manage
employees.view
employees.manage
roles.view
roles.manage
students.view
students.manage
students.delete
enrollments.view
enrollments.manage
enrollments.delete
attendance.view
attendance.manage
```

New staff domains normally use `domain.view` and `domain.manage`. A permission represents a stable capability, not a field, button, tab, or routine action. Add a narrower permission only when a distinct employee responsibility is confirmed.

### Branch context

- Employee administration is the only writer of `user_branches`.
- Branch administration edits branch attributes only.
- `users.preferred_branch_code` remembers the employee's last explicit valid branch choice for future logins; it is not the effective context of an open session.
- Session key `current_branch_code` is validated against active membership.
- Login restores a valid preferred branch, clears an invalid preference, and otherwise selects only a sole active branch; an automatic sole-branch selection does not become a preference.
- Stale or unauthorized session selection is cleared.
- `BranchContext` never restores the persistent preference during ordinary requests, so simultaneous sessions may retain different valid branch contexts.
- Branch selection is membership-based, not a permission.
- Deactivation preserves membership but blocks operational selection.
- Global identity search does not imply global access to academic, attendance, or payment detail; each read enforces its confirmed branch scope.

### Identity

- `User` is the employee profile; `AuthAccount` is the credential.
- Teachers are employees/users with the same account, role, branch, and permission system as other staff.
- Students use a separate `Student` profile and the same authentication entry.
- Passwords are irreversible hashes.
- Employee number has no confirmed use; email remains optional and non-unique until evidence changes that rule.
- Inactive account, employee, or role blocks employee access.
- Employee profile photo uses the same managed private-asset model as student photo: entity `photo_path` on private disk, square browser crop, and an authorized private URL with an opaque path-derived cache version. Write under `employees.manage` or self; read under `employees.view` or self. Employee self-service lives on a simple personal profile page reachable from the user menu; administrative employee Show retains manage-side photo edits. Photo is not on create, not Drive, and not a separate media library.

Student support extends the existing `auth_accounts` owner instead of adding another auth system:

```text
auth_account
├── user_code FK nullable
└── student_code FK nullable

CHECK(exactly one owner)
UNIQUE(user_code) where present
UNIQUE(student_code) where present
```

- Student login uses DNI for compatibility and has a managed password.
- Enabling or re-enabling access issues a new temporary credential. Password reset requires an active account and never silently re-enables disabled access.
- Account state controls authentication; student state controls institutional availability; enrollment state controls current academic placement.
- Do not deny authentication solely because the student has no active enrollment. Authorization may expose historical information while excluding current operations.
- Student self-service is authorized by account ownership, not administrative `students.view`.

### Authentication behavior

- Login is normalized and throttled by identifier plus IP; invalid credentials or inactive identity return a non-enumerating error.
- Employee sessions validate role and branch context. Student sessions do not invent employee branches or permissions.
- Every authenticated request revalidates the relevant account and identity state.
- Logout invalidates the session and regenerates the CSRF token.
- Password recovery and remember-me remain absent until a real workflow requires them.

### Administrative behavior

**Branches**

- One page owns session selection and the branch catalog.
- Fields remain name and active state until evidence requires more.
- A branch may exist without employees.
- No delete, settings JSON, statistics, bulk import, or membership editing in branch forms.

**Employees**

- Create profile, role, branches, and credentials transactionally.
- Profile and credential remain separate.
- Employee administration owns branch assignments and direct grants within role scope.
- No physical deletion until attendance, payments, and history consequences are defined.
- UI exposes only implemented sections.

**Roles**

- Role is category plus assignable permission boundary.
- Role UI edits scope, not automatic grants.
- Superadministrator represents full access.

## 4. Academic structure — confirmed direction

```text
academic cycle
├── cycle degrees
│   └── academic groups / sections
└── cycle shifts
```

Vocabulary:

- **Cycle name:** operational identity such as Primaria, Secundaria, Ordinario, or Primera Opción.
- **Modality:** `regular`, `verano`, `intensivo`, `reforzamiento`, or `virtual`.
- **Cycle degree:** fixed grade offered in one cycle.
- **Academic group:** configurable section inside a cycle degree; UI label `Sección`.
- **Cycle shift:** entry time and tolerance enabled by a cycle.

Cycle name and modality are distinct. The name owns the cycle's operational identity; do not duplicate it in `level` or `cycle_type` without a confirmed independent rule or query.

### Relational model

```text
academic_cycles
  code UUID PK
  branch_code FK
  name, modality, start_date, end_date, attendance_includes_saturday, is_active, timestamps
  CHECK(nonblank name, end_date >= start_date, valid modality)

cycle_degrees
  code UUID PK
  cycle_code FK
  number SMALLINT
  UNIQUE(cycle_code, number)
  CHECK(number BETWEEN 1 AND 6)

academic_groups
  code UUID PK
  cycle_degree_code FK
  name, sort_order, is_active, timestamps
  CHECK(nonblank name)
  UNIQUE(cycle_degree_code, lower(btrim(name)))

cycle_shifts
  code UUID PK
  cycle_code FK
  name, entry_time, tolerance_minutes, sort_order, is_active, timestamps
  CHECK(nonblank name, tolerance_minutes >= 0)
```

### Cycle

- Belongs to the current authorized branch and may cross calendar years.
- Its name identifies the operational cycle; offered degrees are explicit and never inferred from name or modality.
- No global `year` or `current_year`.
- Each cycle owns its expected attendance week: Monday–Friday by default, or Monday–Saturday when `attendance_includes_saturday` is enabled. Sunday is never expected.
- No payment fields until their meanings are confirmed in the payments workflow.
- No modality table unless independent administration is required.
- `cycles.view` may inspect the aggregate; write controls require `cycles.manage`.
- One form page uses freely navigable General, Turnos, and Grados y secciones tabs; it is not a wizard.
- Index cards derive temporal progress at read time using Carrión's `America/Lima` business date; progress is not persisted.

### Degree

- No global degree catalog/CRUD in v1.
- A cycle explicitly selects the grade numbers it offers within the supported 1–6 range.
- Valid offerings are not derived from a separate cycle level.
- Labels come from one domain helper, not persisted display columns.
- Do not add name, abbreviation, active state, or ordering without evidence.

### Group

- Names are configurable and not limited to A–D or one character.
- Valid examples: `A`, `A2`, `P`, `Grupo 1`, `Único`.
- Name is case-insensitively unique within its cycle degree.
- Downstream modules reference `academic_group_code`, never repeated group strings.

### Shift

- A cycle has one or two active shifts.
- Tolerance is non-negative.
- Enrollment selects one or both shifts through an explicit intermediate relation.
- Never model `turn_1`, `turn_2`, arrays, JSON, or a permanent `both` enum.

### Referential direction

```text
enrollment.academic_group_code
→ academic group
→ cycle degree
→ cycle
→ branch + name + modality
```

- Do not duplicate those keys in enrollment without a confirmed historical snapshot requirement.
- `enrollment_shifts` owns selected shifts.
- Attendance references enrollment and selected shift.
- Class evaluations reference academic group.
- Payments may reference enrollment.

## 5. Student registry, search, and profile

### Identity and state

- Student identity is institution-wide and does not belong directly to one branch.
- UUID `code` is the PK; DNI is mandatory, unique, searchable, and remains an attribute.
- Core profile includes names, birth date, phone, address, observation, photo, and active/inactive state.
- Student photo is a real managed profile asset, not a generic placeholder. Reuse the application file/storage owner established by the vertical; do not create a second media system.
- Create/edit establishes identity first; photo selection and replacement belong to the existing student's profile, not the registration form.
- Photo interaction uses a quiet square browser crop, produces one bounded optimized image, and sends only the processed result. It does not require a second client media library.
- Student photo reads follow registry/self-ownership authorization and use private URLs with opaque path-derived cache versions; replacement removes the prior managed asset only after the profile write succeeds.
- Contacts are owned rows, initially name, phone, and a free relationship/note. Do not recreate the full legacy guardian domain before its workflows require it.
- Student, account, and enrollment states remain separate and visible.

`students.view` reads the staff registry/profile. `students.manage` creates and edits identity, photo, contacts, and student state. Access reset belongs to this capability unless a distinct operational role is confirmed. `students.delete` is a separate destructive capability; it does not belong to `students.manage`.

- An authorized employee may permanently delete a student only when no enrollment exists in any branch. Enrollment history is never cascaded merely to remove the identity.
- Student deletion removes owned contacts, authentication account, active sessions, and the managed private photo. PostgreSQL cascades database-owned dependents; the student deletion Action owns the transactional history check and storage cleanup.
- Future attendance, payments, evaluations, or files must preserve this boundary through explicit references and deletion rules; they must not be bypassed by application-only checks.

### Operational list and global search

Student identity and the enrollment read model jointly provide two complementary entry points:

```text
/students           branch-aware academic roster with enrollment filters
/students/search    institution-wide directory, recent students, and global lookup
```

The enrollment vertical owns the active academic roster. It requires a valid current-branch cycle, degree, and section/group before querying; text then searches only inside that selected section. It does not offer “all”, shift, or state filters, and inactive enrollment history belongs to a separate future entry point. Filter state belongs in the URL and results are paginated or otherwise explicitly bounded. The last complete academic context may be remembered in the authenticated server session per branch only to restore navigation; it must be revalidated against the active catalog and redirected to a canonical URL before use. Search text and page are not persistent preferences.

The global directory searches identity independently of an active enrollment and returns enough latest/current academic context to distinguish results. Exact DNI or human-code matches rank before fuzzy names.

Authorized staff can open the bounded student lookup from the authenticated shell; it reuses the institutional directory contract instead of inventing a second search meaning.

Global people search may group students, teachers, and staff behind one interaction, but it does not require a generic `people` table or manual polymorphism. Each domain keeps its explicit identity owner and authorization.

### Student profile

The profile is the institutional hub, not an enlarged CRUD form. It composes bounded summaries for:

- identity, photo, contact details, and active state;
- credential/access state and password reset for authorized staff;
- current enrollment and enrollment history;
- payments and cashbox history summary;
- contacts;
- direct/shared files;
- links to attendance, evaluations, reports, and individual card generation when implemented.

Specialized history pages load their own data. The profile must not become a service god or one unbounded aggregate query.

The staff profile uses one compact identity card with cover, photo, personal details, and observations. That card keeps its own height beside the content column; primary record actions use one compact header menu.

## 6. Enrollment, academic roster, and payments

### Enrollment model and workflow

```text
enrollments
  code UUID PK
  student_code FK
  cycle_code FK
  academic_group_code FK
  roll_code human identifier
  is_active, observation, timestamps

enrollment_shifts
  enrollment_code FK
  cycle_shift_code FK
  PRIMARY KEY(enrollment_code, cycle_shift_code)
```

- One enrollment row exists per student and cycle, protected by `UNIQUE(student_code, cycle_code)`. A retry in that cycle edits the existing row; it never creates another identity or `roll_code`.
- `cycle_code` is derived from the selected group on the server. An enrollment references one academic group and one or both shifts belonging to that same cycle.
- While an existing enrollment's cycle has not ended, the student cannot be enrolled in another cycle. `SaveEnrollment` locks the student and enforces this in the aggregate transaction.
- `roll_code` is exactly four numeric digits (`0001`–`9999`), as required by the confirmed OMR contract. It is used for active search and card display; uniqueness and atomic reservation are protected per cycle by PostgreSQL.
- An authorized employee generates an individual card on demand from the existing student profile. The browser composes it with the private profile photo, the active enrollment, and the institutional template; it creates no server PDF, storage record, route, or new authorization surface.
- The card uses the CR80 physical size (85.6 × 53.98 mm) for Evolis printing. Its QR contains the plain eight-digit DNI for compatibility.
- Enrollment and student activity remain explicit booleans. Cycle finalization is derived at read time from `academic_cycles.end_date`; it is not a persisted enrollment status, scheduled transition, or automatic rewrite.
- A newly created enrollment is active by definition. Deactivation and reactivation are explicit later edits; the create contract does not accept an activity state from the client.
- Creating or activating an enrollment never deactivates or replaces another row. Editing section, shifts, observation, or activity inside the same cycle preserves enrollment `code` and `roll_code`.
- Create, edit, activate/deactivate, derived finalized history, and read-only finished-cycle fields are visible operational behavior.
- `enrollments.delete` is independent from `enrollments.manage`. It permanently removes a selected current-branch enrollment as a corrective action; active, inactive, or derived finalized presentation does not independently block deletion.
- An enrollment with recorded student attendance cannot be deleted or lose the enrolled shift that owns a fact. PostgreSQL owns that referential backstop and Laravel returns the clear operational failure; deletion otherwise preserves the student and lets the existing FK cascade remove `enrollment_shifts`.
- Once Payments exists, any associated payment, including a pending one, also blocks enrollment deletion. The workflow never removes dependent history to make deletion possible.
- An enrollment cannot change cycle. Transfers and their formal history are deferred.
- Enrollment never reconstructs branch, cycle, degree, section, or shift from encoded identifiers.
- Reads enforce confirmed branch visibility; a global student identity does not automatically expose all academic history.

`enrollments.view` reads academic assignment and history. `enrollments.manage` changes enrollment state and assignment. `enrollments.delete` authorizes the distinct destructive correction and requires `enrollments.view`, not `enrollments.manage`. These are staff permissions; student history is authorized by self ownership.

### Payments vocabulary and behavior

The Carrión product vocabulary is **Payments / Pagos**. Do not introduce `PaymentObligation`, “obligations”, or payment-application infrastructure as a presumed domain.

- Enrollment may establish zero or more pending payments with concept, amount, and due date.
- A payment may be pending, paid/posted, or voided according to the confirmed cashbox workflow.
- Collection records the responsible cashier, payment date, and cash context.
- Pending rows may be corrected while they have no confirmed cash consequence.
- A paid/posted operation is never silently overwritten or hard-deleted; correction uses the confirmed void/reversal workflow.
- Do not require at least one payment merely to make enrollment valid unless the operational flow confirms that invariant.
- Partial-payment behavior is not inferred through extra tables. Confirm the actual v7/Coedula workflow before adding structure.
- `payments.view` and `payments.manage` are the initial capability pair. Add a narrower cashbox/collection permission only if distinct staff responsibility requires it.

### Cashbox

- Cashier owns the cash line, not the branch; the operation may retain branch context.
- Multiple cashiers may work simultaneously.
- No formal cash opening/closing in the initial version unless the real workflow requires it.
- Cash received and change may be recorded; the net cash movement is the amount paid.
- Confirmed operations are reversed/voided, never hard-deleted.

## 7. Attendance and integrated reporting

Student attendance and employee attendance live in the same platform and reporting experience but keep different domain records because their schedules and consequences differ.

### Students — implemented fact model

```text
student_attendances
  (enrollment_code, cycle_shift_code) FK -> enrollment_shifts
  attendance_date
  state: present | late | permission | justified
  arrival_at, recording_method scan|manual, reason, actors
  UNIQUE(enrollment_code, cycle_shift_code, attendance_date)
```

- Attendance references one selected enrollment shift through the composite `(enrollment_code, cycle_shift_code)` relation. There is **no** temporal slot/SCD table; expected students are derived from active enrollments + `enrollment_shifts` + cycle/shift clocks.
- Stored states only: `present`, `late`, `permission`, `justified`. **`pending` and `absent`/`falta` are never stored**; they are derived at read time from entry time + tolerance via `student_attendance_effective_state`.
- The cycle explicitly selects Monday–Friday or Monday–Saturday attendance; Sunday is never expected. `student_attendance_is_expected_day` is the shared PostgreSQL predicate used by roster, scan, manual writes, and history.
- Each enrollment owns `attendance_starts_on` (within the cycle window). Expected attendance never begins before that date. When a new form selects a group, the server supplies `max(cycle start, min(business date, cycle end))`; edit preserves the stored value. The Action still falls back to cycle start for non-HTTP callers, and HTTP writes require an explicit date. Moving the start after existing facts is rejected.
- After the first attendance fact exists for a cycle, `SaveCycle` freezes attendance-sensitive values: cycle `start_date`, Saturday rule, end-date contraction, and entry time/tolerance of shifts that already have facts. Name, modality, activity, end-date extension, and unused structure remain editable. Referenced shifts/groups/degrees cannot be deleted; the Action returns field-level validation errors instead of raw FK exceptions.
- Student history selects one authorized enrollment, derives its bounded expected dates set-wise with PostgreSQL `generate_series` from `GREATEST(range_start, cycle.start, attendance_starts_on)`, left-joins facts, and classifies missing rows as pending or absent. It never materializes absences.
- History uses the enrollment's current explicit section and shift relations. There is no temporal reconstruction of past academic assignments yet.
- There is no holiday, suspension, or vacation exception calendar yet, so derived absence history is an **operational report**, not an official cumulative certificate. Business timezone: `America/Lima` (`config/aeduca.business_timezone`).
- Scan resolves only from entry−tolerance through entry+tolerance, inclusive. Inside that window it records present through entry time and late afterwards.
- After tolerance, automatic scan does not create a fact; absence is derived on the daily list.
- Continuous **scan is DNI-only** (QR/keyboard). Server resolves branch, active enrollment, and open shift window. No cycle/shift selectors on the scan page.
- Dual open windows for the same student reject with a clear message; use the daily list or manual registration.
- Manual ops are semantic: arrival, permission (before entry), justify (after window), correct (existing fact + reason).
- An operational attendance context requires an active cycle, group, shift, and enrollment. The daily roster may still identify an inactive student when that active enrollment remains; physical DNI scan rejects that identity. Historical facts remain readable under their own branch/ownership scope.
- Daily list uses the same roster layout pattern: date + cycle + degree + section + shift filters, expected rows with LEFT JOIN facts, server pagination and summary counts.
- Student history is a specialized page (`/students/{student}/attendance`), not an unbounded profile payload. Staff are limited to the current branch; self-service sees own enrollment contexts across branches. A history query is scoped to one enrollment and exactly one assigned shift; an omitted shift resolves to the first assigned shift and never means “all”.
- The history page returns the complete bounded range for one enrollment and shift. Lumi paginates those rows locally, and the browser reuses the same authorized payload to lazily create an A4 **operational attendance report** (not titled as an official certificate) without another endpoint or stored artifact. The document states that derived absences do not yet account for holidays or suspensions.
- Application writes reject cross-cycle group/shift assignment. PostgreSQL FKs enforce existence independently; migration tooling must detect cross-cycle enrollment structures with explicit SQL reconciliation before cutover.
- Permissions: `attendance.view`, `attendance.manage` (manage expands view). No per-button attendance permissions.
- No cron mass-inserts absence rows. No employee attendance in the student vertical.
- v7 migration maps stored rows into facts; derived faltas do not require importing absence rows.

### Employees and teachers — control horario (Coedula-style)

```text
users.dni                     nullable unique 8-digit when present
employee_schedules            one row = one weekday window (entry_time → to_time)
  per user + branch; starts_on, nullable ends_on; many non-overlapping rows allowed
employee_attendances          UNIQUE(schedule_code, attendance_date)
  states: present | late | permission | justified
  pending/absent derived after schedule to_time
```

- Teachers use this ownership; there is no separate teacher attendance domain.
- **One employee profile surface** (Coedula): `/profile` (self) and `/admin/employees/{id}` (admin) render the same page. Tabs: Asistencia · Horarios · General · Acceso (including permissions). Self always reads own attendance and schedules; staff needs domain permissions. `employee_attendance.view` is sufficient for the minimum identity plus current-branch attendance/schedule tabs; `employees.view` still controls general administration. The loader queries only the active tab's relationships and catalogs.
- Schedule edit requires `employee_attendance.manage`. The form remains Día/Desde/Hasta; validity is server-owned infrastructure. New rows start on the Aeduca business date. A historically relevant edit closes the old row and creates a prospective replacement; removal closes historical rows and physically deletes only rows that never contributed expectations or facts. User and branch ownership are immutable.
- Employee scan admits one institutional early-arrival margin of 60 minutes: the acceptance window is `entry_time − 60 minutes` through `to_time`, bounded at 00:00. `entry_time` remains the expected arrival and alone classifies an accepted scan as present (at or before it) or late (after it); `to_time` remains the closing boundary, not a duration or radius.
- Simultaneously valid acceptance windows for one employee, branch, and weekday cannot overlap. The schedule Action serializes writes by employee, and the scanner independently rejects ambiguous legacy windows.
- Scan resolves the valid acceptance window that contains now (entrance-operated, DNI). Outside all windows is rejected with the nearest acceptance and work ranges. Repeat scan is idempotent per schedule+date. The concrete schedule row lock coordinates scan/manual facts with schedule lifecycle changes; the unique fact key remains the database duplicate barrier.
- Daily **Control horario** lists expected slots for the weekday of the selected date; history expands expected slots across a bounded date range with `DateRangeFilter`. Both reads use schedule validity for the requested date, not the employee's later activity state; physical scan continues to require an active employee.
- Attendance facts reference only their schedule for employee/branch context. Present/late require an entry time; permission/justified require no entry time, enforced by PostgreSQL. Manual create/update/delete always uses the server business date and cannot mutate a historical fact through client input.
- Removing an employee's branch membership is rejected while a schedule is still valid there. Deactivation is rejected while a schedule remains valid through the business date (`ends_on >= today`); employee administration never closes attendance schedules implicitly.
- Permissions: `employee_attendance.view` / `employee_attendance.manage` (manage expands view; manage owns schedules + scan + manual create/update/delete).
- Employee CR80 card shares `cr80-card-core` with student cards; QR is plain DNI; requires DNI + photo.
- Out of scope: leave workflows, non-working calendar, payroll, multi-exit.

## 8. Evaluations and OMR

- Nextya is the functional source for OMR behavior and specialized reports.
- Laravel owns students, evaluations, answers, and results.
- The isolated OMR processor does not own academic data.
- Class evaluations reference the academic group; results reference the enrolled student as confirmed by the evaluation model.
- Scan images are discarded after processing.
- Manual final-score correction records actor and timestamp.
- Do not rewrite the OMR engine during initial integration.

## 9. Student portal, photos, and shared files

- Students use the same login entry and access only their own authorized information.
- The initial self profile exposes basic information and access state.
- Attendance, evaluations/scores, payments, and current shared files appear when their owning domains are complete.
- Files may be linked directly to a student or shared through the student's current academic group using explicit relations.
- Profile photo and Drive/shared documents may reuse one storage foundation but remain different product responsibilities.
- Historical files are not migrated initially.
- The portal is operational self-service, not a full LMS or social network.

### Drive — private space and explicit sharing

- Drive is not institutional and not shared by default. Every `drive_files` row has exactly one owner (`user_code`), and nobody else sees it.
- One permission, `drive.manage`, gates the whole module in one place. Inside it authorization is ownership: there is no view-only Drive employee, so the domain has no `drive.view` and the `manage`→`view` dependency simply does not apply to it.
- The owner has full control of their own graph: folders, upload, rename, move, trash, restore, permanent delete, preview, download.
- Sharing is one explicit grant to one other employee (`drive_shares`), and it is read-only: browse, preview and download, never write, never re-share.
- Exactly two share lists exist — what the actor shared with others, and what others shared with the actor. There is no third space and no global list.
- A share covers the node it points at and everything below it, so sharing a folder grants its whole subtree with one row. A recipient browsing a received folder never sees, and is never told the name of, anything above the shared root.
- Trash carries the whole subtree: trashing a folder trashes its descendants, restoring restores them. Because that invariant holds, a restore is blocked exactly when the immediate parent is trashed.
- A trashed file leaves both share lists and cannot be shared.
- Sibling names are unique per folder among live rows; trashed rows never reserve a name.
- Recursion over the folder graph belongs to PostgreSQL functions (`drive_file_subtree`, `drive_file_contains`, `drive_folder_path`, `drive_folder_options`, `drive_file_shared_with`); Laravel still owns ownership, filters, and the response shape.
- A readable folder makes its children readable, so a listing authorizes the parent once instead of filtering every row.
- Storage quota is per owner and counts trashed files until they are permanently deleted.
- Blobs reuse the same private disk as profile photos under their own directory; Drive keeps the file graph, sharing, and quota that a profile photo does not have.
- Drive serves stored originals. Derived image variants are out of scope while they would require a new runtime dependency.
- Student recipients are out of scope until the student portal owns a surface where a received file is reachable.

## 10. Migration

- Imports are repeatable and idempotent.
- Legacy semantic identifiers map to UUIDs in a technical migration map, not scattered `legacy_id` columns.
- Never reinterpret legacy data silently.
- Confirmed cutover scope includes branches, employees/accounts, students, cycles, degrees, groups, enrollments, payments, cash movements, attentions, and evaluations/results that can be associated reliably.
- Rehearse the complete migration and reconcile counts and monetary totals before cutover.
- Staff will not operate v7/v8 in parallel; v7 remains technically recoverable during the cutover window.

## 11. UI contract

- Spanish, calm administration UI with the operational density needed by staff.
- One dashboard shell, navigation source, notification owner, and frontend `can()` helper.
- Lumi public components/classes only; no local visual system.
- Data-entry forms use meaningful placeholders and `Fieldset` grouping when they contain more than one conceptual section.
- Indexes show useful summaries and real filters; details show one subject/profile with bounded domain summaries.
- Add tabs, wizards, bulk actions, or dashboards only for demonstrated workflows.
- Do not let visual minimalism remove primary actions or operational context.
- Never create empty future UI.

## 12. Quality and acceptance boundaries

Prefer Feature tests for critical flows and Unit tests only for isolated rules that merit them. Cover authorization, self ownership, transactions, active/inactive access, branch isolation, database invariants, rollback, permission scope/grants, search/filter behavior, and academic date/shift/degree/group rules. Do not test framework behavior.

Use `aeduca_test` during feature development. Do not apply unfinished feature migrations or seeds to the local `aeduca` database merely to preview a rejected direction.

Before declaring a product vertical complete, verify its stated list/search/filter or entry point, detail/profile, primary write, state transition, authorization, failure behavior, and persisted result. Compilation success does not substitute for functional or visual acceptance.

Stop instead of inventing when terminology conflicts, ownership is unknown, legacy evidence materially disagrees, payments/cashbox semantics are ambiguous, an invariant cannot be protected, an owner would be duplicated, Lumi lacks a public contract, or required checks fail. Correct the task or specification rather than using the stop condition as justification for knowingly incomplete output.
