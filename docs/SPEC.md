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
- `*.manage` requires matching `*.view` and is normalized on persistence.
- Never authorize by role name/code.
- `is_super_admin` is controlled technical escalation, not a normal form field.

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
```

New staff domains normally use `domain.view` and `domain.manage`. A permission represents a stable capability, not a field, button, tab, or routine action. Add a narrower permission only when a distinct employee responsibility is confirmed.

### Branch context

- Employee administration is the only writer of `user_branches`.
- Branch administration edits branch attributes only.
- Session key `current_branch_code` is validated against active membership.
- Stale or unauthorized session selection is cleared.
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
  name, modality, start_date, end_date, is_active, timestamps
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
- Contacts are owned rows, initially name, phone, and a free relationship/note. Do not recreate the full legacy guardian domain before its workflows require it.
- Student, account, and enrollment states remain separate and visible.

`students.view` reads the staff registry/profile. `students.manage` creates and edits identity, photo, contacts, and student state. Access reset belongs to this capability unless a distinct operational role is confirmed.

### Operational list and global search

Student identity and the enrollment read model jointly provide two complementary entry points:

```text
/students           branch-aware academic roster with enrollment filters
/students/search    institution-wide directory, recent students, and global lookup
```

The enrollment vertical owns the academic roster and its current-branch filters: cycle, degree, section/group, shift, enrollment state, and text. Search covers DNI, name, and active `roll_code`. Filter state belongs in the URL and results are paginated or otherwise explicitly bounded.

The global directory searches identity independently of an active enrollment and returns enough latest/current academic context to distinguish results. Exact DNI or human-code matches rank before fuzzy names.

Global people search may group students, teachers, and staff behind one interaction, but it does not require a generic `people` table or manual polymorphism. Each domain keeps its explicit identity owner and authorization.

### Student profile

The profile is the institutional hub, not an enlarged CRUD form. It composes bounded summaries for:

- identity, photo, contact details, and active state;
- credential/access state and password reset for authorized staff;
- current enrollment and enrollment history;
- payments and cashbox history summary;
- contacts;
- direct/shared files;
- links to attendance, evaluations, reports, and card/QR behavior when implemented.

Specialized history pages load their own data. The profile must not become a service god or one unbounded aggregate query.

## 6. Enrollment, academic roster, and payments

### Enrollment model and workflow

```text
enrollments
  code UUID PK
  student_code FK
  academic_group_code FK
  roll_code human identifier
  is_active, observation, timestamps

enrollment_shifts
  enrollment_code FK
  cycle_shift_code FK
  PRIMARY KEY(enrollment_code, cycle_shift_code)
```

- One active enrollment per student system-wide, protected by PostgreSQL.
- An enrollment references one academic group and one or both shifts belonging to that cycle.
- `roll_code` is a human identifier used for active search, OMR, and card display; active-code uniqueness is database-protected.
- Card QR contains DNI for compatibility.
- Creating or activating an enrollment handles the previous active enrollment explicitly in the same transaction; it is never a hidden observer consequence.
- Create, edit, activate/deactivate, and history are visible operational actions.
- Branch/group may change directly in the initial version; a separate transfer-history subsystem is deferred.
- Enrollment never reconstructs branch, cycle, degree, section, or shift from encoded identifiers.
- Reads enforce confirmed branch visibility; a global student identity does not automatically expose all academic history.

`enrollments.view` reads academic assignment and history. `enrollments.manage` changes enrollment state and assignment. These are staff permissions; student history is authorized by self ownership.

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

### Students

- Attendance references enrollment and one selected enrollment shift.
- Monday through Saturday in the initial version; no initial holiday calendar.
- Present through entry time; late after entry and through tolerance.
- After tolerance, automatic reading does not create normal attendance; absence is derived.
- Authorized manual correction remains possible.
- No cron mass-inserts absence rows.

### Employees and teachers

- Teachers are users/employees and use employee schedules and attendance ownership.
- Employee attendance remains branch- and schedule-aware.
- Reporting can present student, teacher, and staff attendance coherently without merging them into one ambiguous table.

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
- Indexes show useful summaries and real filters; details show one subject/profile with bounded domain summaries.
- Add tabs, wizards, bulk actions, or dashboards only for demonstrated workflows.
- Do not let visual minimalism remove primary actions or operational context.
- Never create empty future UI.

## 12. Quality and acceptance boundaries

Prefer Feature tests for critical flows and Unit tests only for isolated rules that merit them. Cover authorization, self ownership, transactions, active/inactive access, branch isolation, database invariants, rollback, permission scope/grants, search/filter behavior, and academic date/shift/degree/group rules. Do not test framework behavior.

Use `aeduca_test` during feature development. Do not apply unfinished feature migrations or seeds to the local `aeduca` database merely to preview a rejected direction.

Before declaring a product vertical complete, verify its stated list/search/filter or entry point, detail/profile, primary write, state transition, authorization, failure behavior, and persisted result. Compilation success does not substitute for functional or visual acceptance.

Stop instead of inventing when terminology conflicts, ownership is unknown, legacy evidence materially disagrees, payments/cashbox semantics are ambiguous, an invariant cannot be protected, an owner would be duplicated, Lumi lacks a public contract, or required checks fail. Correct the task or specification rather than using the stop condition as justification for knowingly incomplete output.
