# Aeduca v8 — Product and Engineering Specification

> Permanent source of truth for product, domain, data, and architecture decisions. Current implementation belongs in [`STATUS.md`](STATUS.md); temporary execution belongs in root `TASK.md`.

## 1. Product

Aeduca v8 is Carrión's unified education platform. It preserves proven workflows while replacing structural debt; it is not a clone of a previous system.

| Evidence     | Purpose                                                                     |
| ------------ | --------------------------------------------------------------------------- |
| Aeduca Admin | Real Carrión workflows and primary migration evidence                       |
| Aeduca Aula  | Student portal history                                                      |
| Coedula      | Modern PostgreSQL, Svelte/Lumi, attendance, finance, Drive, and OMR lessons |
| Nextya       | OMR processing and specialized evaluation reports                           |

For each capability: inspect v8, Aeduca Admin, and Coedula; preserve useful behavior; remove historical structure; implement the smallest coherent result.

### Boundary

- One institution: Carrión.
- Multiple branches; no SaaS tenants, memberships, or company isolation.
- One application and operational model.
- Spanish end-user interface.
- Migratable from Aeduca v7 and Nextya.

### Delivery sequence

```text
Access and administration
→ Academic structure
→ Students and minimal contacts
→ Enrollment and payment obligations
→ Attendance
→ Evaluations and OMR
→ Cashbox and attentions
→ Lean student portal
```

The initial portal exposes attendance, evaluations/scores, payments, basic information, and current shared files. Historical files are not migrated. Chat, posts, likes, comments, task submissions, complete virtual-classroom sessions, and web-form examinations are not current priorities.

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
- Keep the architecture understandable by one developer and consistent for coding agents: explicit ownership and visible rules over implicit convention.
- Prefer deletion, reuse, and cohesive files over speculative layers; never split a file only to reduce its line count.
- An Action is justified by a transaction, aggregate write, or real invariant; a simple row update remains direct.
- Use a DTO only at a genuinely complex boundary or integration such as OMR; never wrap a few fields without adding meaning.
- Call mandatory consequences directly. Events require independent consumers; observers never hide critical academic or financial work.
- Never create future infrastructure without a current consumer.

### Data

- Main entities use UUID primary key `code`; FKs use `<entity>_code`.
- Technical identifiers never encode year, branch, level, degree, group, modality, or status.
- DNI is an attribute, never a primary key.
- Human numbers exist only for confirmed external use.
- Many-to-many relations use explicit intermediate tables; relationships never use JSON or arrays; declare every possible FK.
- Redundant FKs require a documented query/history reason and an integrity guarantee.
- Use explicit, readable states; never hide meaning in single-letter role or status codes. A finite state uses a backed PHP enum, a string column, and an equivalent PostgreSQL `CHECK`; native PostgreSQL enums require demonstrated value.
- Avoid physical deletion where operational history may reference a row. Add soft delete only for a confirmed restore/trash workflow; active/inactive is enough for catalogs otherwise.
- Use `date`, `time`, and `timestamptz` by business meaning.
- Server time never chooses the current academic cycle implicitly.
- Money uses `NUMERIC`, never float.
- Confirmed finance is reversed/voided, never hard-deleted.
- Business-critical multi-table writes are transactional.
- Never recalculate money silently from ambiguous legacy data.
- JSON is only for genuinely unstructured external evidence.
- Do not hide authorization, academic, attendance, or finance behavior in triggers.
- A SQL function is acceptable only when it materially clarifies a query or invariant and remains explainable and tested.

### Query policy

- Prevent N+1 deliberately; index real FK/query paths.
- Index pages load fields plus summaries/counts.
- Detail/edit pages load one aggregate.
- Do not eager-load nested history on catalogs.
- Cache only after measurement.
- Prefer bounded, readable queries over clever optimization.

## 3. Access and administration — closed

### Schema and meaning

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

Stable vocabulary:

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

New domains normally use `domain.view` and `domain.manage`.
Permission names use lowercase dot notation and PostgreSQL validates that shape.

### Branch context

- Employee administration is the only writer of `user_branches`.
- Branch administration edits branch attributes only.
- Session key `current_branch_code` is validated against active membership.
- Stale or unauthorized session selection is cleared.
- Branch selection is membership-based, not a permission.
- Deactivation preserves membership but blocks operational selection.

### Identity

- `User` is the employee profile; `AuthAccount` is the credential.
- Passwords are irreversible hashes.
- Employee number has no confirmed use; email remains optional and non-unique until evidence changes that rule.
- Inactive account, employee, or role blocks access.
- Teachers are employees; academic assignments come later.
- Students use a separate identity domain.

### Authentication behavior

- Worker login is normalized and throttled by login plus IP; invalid credentials or inactive identity return a non-enumerating error.
- Zero active branches blocks operational login; one is selected automatically; several leave branch selection to the authenticated shell.
- Every authenticated request revalidates account, employee, role, branch membership, and active branch state.
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
- No physical deletion until attendance, finance, and history consequences are defined.
- UI exposes only implemented sections.

**Roles**

- Role is category plus assignable permission boundary.
- Role UI edits scope, not automatic grants.
- Superadministrator represents full access.

## 4. Academic structure — closed

```text
academic cycle
├── cycle degrees
│   └── academic groups / sections
└── cycle shifts
```

Vocabulary:

- **Level:** Primaria or Secundaria.
- **Modality:** `regular`, `verano`, `intensivo`, `reforzamiento`, or `virtual`.
- **Cycle degree:** fixed grade offered in one cycle.
- **Academic group:** configurable section inside a cycle degree; UI label `Sección`.
- **Cycle shift:** entry time and tolerance enabled by a cycle.

Level and modality are distinct.

### Relational model

```text
academic_cycles
  code UUID PK
  branch_code FK
  name, level, modality, start_date, end_date, is_active, timestamps
  CHECK(nonblank name, end_date >= start_date, valid level/modality)

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
- No global `year` or `current_year`.
- No finance fields until their meanings are confirmed.
- No modality table unless independent administration is required.
- `cycles.view` may inspect the aggregate; write controls require `cycles.manage`.
- One form page uses freely navigable General, Turnos, and Grados y secciones tabs; it is not a wizard.
- Index cards derive temporal progress at read time using Carrión's `America/Lima` business date; progress is not persisted and adds no query.

### Degree

- No global degree catalog/CRUD in v1.
- Primary accepts 1–6; secondary accepts 1–5.
- Labels come from one domain helper/enum, not persisted display columns.
- Do not add name, abbreviation, active state, or ordering without evidence.

### Group

- Names are configurable and not limited to A–D or one character.
- Valid examples: `A`, `A2`, `P`, `Grupo 1`, `Único`.
- Name is case-insensitively unique within its cycle degree.
- Future modules reference `academic_group_code`, never repeated group strings.

### Shift

- A cycle has one or two active shifts.
- Tolerance is non-negative.
- Future enrollment selects one or both shifts through an explicit intermediate relation.
- Never model `turn_1`, `turn_2`, arrays, JSON, or a permanent `both` enum.

### Referential direction

```text
enrollment.academic_group_code
→ academic group
→ cycle degree
→ cycle
→ branch + level + modality
```

- Do not duplicate those keys in enrollment without a confirmed historical snapshot requirement.
- Future `enrollment_shifts` owns selected shifts.
- Attendance references enrollment and selected shift.
- Class evaluations reference academic group.
- Payment obligations reference enrollment.

## 5. Confirmed future verticals

### Students and contacts

- DNI is mandatory for the current Carrión flow but remains an attribute.
- Student access uses DNI for compatibility.
- Student passwords remain irreversible hashes. If `AuthAccount` later supports students, use an explicit student FK and a database constraint guaranteeing exactly one account owner; never manual polymorphism.
- Minimal contact: name, phone, and a free note describing the relationship.
- Do not recreate the full legacy apoderado model initially.

### Enrollment

- One active enrollment per student system-wide, protected by PostgreSQL.
- Enrollment references one academic group and one or both cycle shifts.
- Branch/group may change directly in v1; transfer history is deferred.
- Obligations are generated during enrollment.
- `roll_code` is a human identifier for OMR, search, and card display.
- Card QR contains DNI for compatibility.

### Attendance

- Monday through Saturday in v1; no initial holiday calendar.
- Present through entry time; late after entry and through tolerance.
- After tolerance, automatic reading does not create normal attendance; absence is derived.
- Authorized manual correction remains possible.
- No cron mass-inserts absence rows.

### Finance

```text
obligation ≠ payment ≠ payment application ≠ cash movement
```

- Each obligation has a concept, amount, and due date.
- Partial payments exist; one payment may apply to multiple obligations and one obligation may receive multiple payments through applications.
- Cashier owns the cash line, not the branch; ownership does not change when the cashier works in another branch, while the movement may retain the operation branch as context.
- Multiple cashiers may work simultaneously.
- No formal cash opening/closing in v1.
- Cash received and change may be recorded; the net movement is the amount paid, not the cash tendered.
- Confirmed operations are reversed/voided, never hard-deleted.

### Evaluations and OMR

- Nextya is the functional source for OMR behavior and reports.
- Laravel owns students, evaluations, answers, and results.
- The isolated OMR processor does not own academic data.
- Scan images are discarded after processing.
- Manual final-score correction records actor and timestamp.
- Do not rewrite the OMR engine during initial integration.

## 6. Migration

- Imports are repeatable and idempotent.
- Legacy semantic identifiers map to UUIDs in a technical migration map, not scattered `legacy_id` columns.
- Never reinterpret legacy data silently.
- Confirmed cutover scope includes branches, employees/accounts, students, cycles, degrees, groups, enrollments, obligations/payments, cash movements, attentions, and evaluations/results that can be associated reliably.
- Rehearse the complete migration and reconcile counts/totals before cutover.
- Staff will not operate v7/v8 in parallel; v7 remains technically recoverable during the cutover window.

## 7. UI contract

- Spanish, calm administration UI.
- One dashboard shell, navigation source, notification owner, and frontend `can()` helper.
- Lumi public components/classes only; no local visual system.
- Indexes show summaries; details show one aggregate.
- Add filters, tabs, wizards, bulk actions, or dashboards only for a demonstrated workflow.
- Never create empty future UI.

## 8. Quality boundaries

Prefer Feature tests for critical flows and Unit tests only for isolated rules that merit them. Cover authorization, transactions, active/inactive access, branch isolation, database invariants, rollback, permission scope/grants, and academic date/shift/degree/group rules. Do not test framework behavior.

Stop instead of inventing when terminology conflicts, ownership is unknown, legacy evidence disagrees materially, finance is ambiguous, an invariant cannot be protected, an owner would be duplicated, another module is needed only for appearance, Lumi lacks a public contract, or required checks fail.
