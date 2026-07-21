# Aeduca v8 — Product and Engineering Specification

> **Role:** permanent source of truth for product direction, domain rules, data rules, and architecture.
>
> Current implementation facts belong in [`STATUS.md`](STATUS.md).
> Active execution scope belongs in the root [`TASK.md`](../TASK.md).

## 1. Product identity

Aeduca v8 is a clean rebuild of Carrión's education management platform.

It unifies the useful operational knowledge of:

| System       | Value for Aeduca v8                                                                    |
| ------------ | -------------------------------------------------------------------------------------- |
| Aeduca Admin | Real workflows used by Carrión and primary migration evidence                          |
| Aeduca Aula  | Student-access and portal history                                                      |
| Coedula      | Newer PostgreSQL, Svelte/Lumi, attendance, finance, Drive, and OMR integration lessons |
| Nextya       | OMR evaluation processing and specialized reports                                      |

Aeduca v8 is not a clone of any previous system.

For every capability:

1. Identify the real Carrión workflow.
2. Inspect how Aeduca Admin handled it.
3. Inspect how Coedula handled it.
4. Preserve proven behavior.
5. Remove structural debt.
6. Implement the smallest coherent solution.

### Product boundary

- One institution: Carrión.
- Multiple branches.
- No SaaS tenants, memberships, or company isolation.
- One application and one operational model.
- Spanish end-user interface.
- Migratable from Aeduca v7 and Nextya.

### Development line

```text
Access, branches, users, roles, permissions
→ Academic structure
→ Students and minimal contacts
→ Enrollment and payment obligations
→ Attendance
→ Evaluations and OMR
→ Cashbox and attentions
→ Lean student portal
```

The initial student portal exposes:

- attendance;
- evaluations and scores;
- payments;
- basic information;
- current shared files.

Chat, social posts, likes, comments, complex virtual-classroom submissions, and web-form examinations are not current priorities.

## 2. Fixed stack

| Layer     | Choice                      |
| --------- | --------------------------- |
| Backend   | Laravel 13, PHP 8.5         |
| Transport | Inertia                     |
| Frontend  | Svelte 5, TypeScript strict |
| UI        | `@lumi-ui/svelte`           |
| Database  | PostgreSQL                  |
| Packages  | Composer and pnpm           |
| Locale    | Spanish                     |

Do not introduce:

- authorization packages;
- auth starter kits;
- module frameworks;
- generic repositories;
- generic service classes;
- DTO libraries that only rename arrays;
- a second CSS framework or UI library;
- a client state library for permissions or branch context;
- soft-delete-by-default;
- persistent caches without measurement;
- manual polymorphic relations when explicit FKs are possible.

## 3. Documentation ownership

| File             | Owns                                                        |
| ---------------- | ----------------------------------------------------------- |
| `README.md`      | Entry point, setup, commands, short rules                   |
| `AGENTS.md`      | Mandatory agent protocol                                    |
| `docs/SPEC.md`   | Permanent product, domain, data, and architecture decisions |
| `docs/STATUS.md` | Verified implementation state                               |
| `TASK.md`        | One temporary active vertical                               |

Do not create parallel Foundation, Roadmap, Development Line, or alternative specification files.

## 4. Engineering philosophy

### One owner

- Reuse existing patterns before inventing new ones.
- One owner for every concern and write path.
- Do not keep parallel helpers, layouts, caches, stores, or documentation truth.

### Lightweight architecture

- Prefer deletion and simplification over accumulation.
- Abstraction follows a visible responsibility.
- No speculative layers or future-proofing without a current use.
- Files remain cohesive; do not split only to reduce line count.

### Layer ownership

| Layer       | Owns                                               | Must not own              |
| ----------- | -------------------------------------------------- | ------------------------- |
| PostgreSQL  | FK, UNIQUE, CHECK, structural truth                | Hidden business workflows |
| Route       | HTTP entry and semantic authorization              | Domain processing         |
| FormRequest | Input shape, normalization, messages               | Full domain policy        |
| Model       | Relations, casts, UUID configuration, small scopes | Multi-step processes      |
| Action      | Transactional aggregate writes and invariants      | HTTP presentation         |
| Controller  | Query/action orchestration and response            | Large business logic      |
| Svelte page | Interaction and composition                        | Security authority        |
| Lumi        | Domain-neutral UI primitives                       | Education rules           |

Create an Action when a write touches multiple tables or protects a real invariant.

A simple validated single-row update may remain direct and clear.

### Performance

- Prevent N+1 deliberately.
- Index foreign keys and real query paths.
- Index pages load summaries and counts.
- Detail/edit pages load one complete aggregate.
- Do not eager-load nested history on catalog lists.
- Do not cache before measurement.
- Prefer bounded, readable work over clever optimization.

## 5. Data rules

### Identifiers

- Main entities use UUID primary key `code`.
- Foreign keys use `<entity>_code`.
- Technical identifiers never embed year, branch, level, degree, group, modality, or status.
- DNI is an attribute, never a primary key.
- Human numbers exist only when they have a confirmed external use.

### Relations

- Many-to-many relations use explicit intermediate tables.
- Relationships never live in JSON or PostgreSQL arrays.
- Explicit FKs are mandatory when possible.
- Redundant foreign keys require a documented historical or query reason and an integrity guarantee.

### State and deletion

- Use explicit and understandable states.
- Do not use hidden single-letter role or status rules.
- Avoid physical deletion when operational history may reference the record.
- Soft delete is introduced only when restore/trash is a confirmed workflow.
- Active/inactive is sufficient for catalogs unless restoration semantics are required.

### Dates and money

- Use `date`, `time`, and `timestamptz` according to business meaning.
- A cycle may cross calendar years.
- Money uses `NUMERIC`, never float.
- Confirmed financial operations are never hard-deleted.
- Multi-table finance writes are transactional.

### JSON and triggers

JSON is allowed only for genuinely unstructured external evidence.

Do not use JSON for permissions, branches, shifts, enrollments, groups, participants, or foreign-key relationships.

Do not hide authorization, academic, attendance, or finance behavior in triggers.

## 6. Access domain — closed model

### Entities

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

### Meaning

```text
role = employee category
role permission scope = permissions assignable to that role
user permission = actual direct grant
effective permission = direct grant ∩ role scope
superadministrator = all known permissions
```

Rules:

- Role scope does not grant access automatically.
- A direct user grant outside the role scope is not effective.
- Changing role or reducing scope prunes incompatible grants transactionally.
- `*.manage` requires the matching `*.view` and is normalized when persisted.
- Never authorize by role name or role code.
- `is_super_admin` is a controlled technical escalation, not a normal form field.

### Branch ownership

- `user_branches` is written only by employee administration.
- Branch administration edits branch attributes only.
- Current branch lives in session key `current_branch_code`.
- Current branch is always validated against active membership.
- Selecting a branch is based on membership, not a fake permission.
- Deactivating a branch preserves membership but prevents operational selection.

### Identity and credentials

- `User` is the employee profile.
- `AuthAccount` is the Laravel authenticatable credential.
- Passwords are irreversible hashes only.
- Inactive account, employee, or role means no operational access.
- Teachers are employees; academic assignments are added later.
- Students are a separate identity domain.

### Stable permission vocabulary

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

## 7. Administrative domain — closed behavior

### Branches

- One branch page handles session selection and branch catalog.
- Branch fields remain minimal: name and active state unless real evidence requires more.
- A branch may exist without employees.
- No physical delete, settings JSON, statistics, bulk import, or membership editing from branch forms.

### Employees

- Create profile, role, branches, and credentials transactionally.
- Profile and credential remain separate.
- Employee administration owns branch assignments.
- Employee profile contains only implemented sections.
- No physical employee deletion until attendance, finance, and history consequences are defined.
- Direct permissions are assigned within role scope.

### Roles

- Role is category and assignable permission boundary.
- Role UI edits available permissions, not automatic grants.
- Full access is represented by superadministrator, not by selecting every permission.

## 8. Academic foundation — confirmed direction

The academic structure exists to support enrollment, attendance, evaluations, and payment obligations.

```text
academic cycle
├── cycle degrees
│   └── academic groups / sections
└── cycle shifts
```

### Vocabulary

- **Level:** Primaria or Secundaria.
- **Modality:** program type. Confirmed closed set: `regular`, `verano`, `intensivo`, `reforzamiento`, `virtual`.
- **Cycle degree:** one fixed grade offered in one specific cycle.
- **Academic group:** a concrete section inside a cycle degree. UI label: `Sección`.
- **Cycle shift:** one entry-time and tolerance configuration enabled by a cycle.

Level and modality are different concepts.

### Degree decision

Do not create a global `academic_degrees` CRUD or catalog in v1.

Peruvian school grades are a small fixed domain, while the meaningful operational entity is the grade offered inside a particular cycle.

Use `cycle_degrees` as the concrete academic context.

Minimum:

```text
code UUID PK
cycle_code FK
number SMALLINT
timestamps
UNIQUE (cycle_code, number)
CHECK (number BETWEEN 1 AND 6)
```

Rules:

- the cycle's level determines which grade numbers are valid;
- primary uses 1–6;
- secondary uses 1–5;
- grade labels are derived consistently in one domain helper/enum, not repeated in tables or UI;
- do not build a degree-management screen;
- do not add name, short name, active state, or ordering columns unless real local evidence proves a need.

This removes a global catalog without losing the stable cycle-specific FK needed by groups, enrollment, evaluation, and reporting.

### Academic cycle

Minimum direction:

```text
code UUID PK
branch_code FK
name
level
modality
start_date
end_date
is_active
timestamps
```

Rules:

- belongs to the current authorized branch;
- may cross calendar years;
- no `year` or global `current_year`;
- level and modality are separate;
- no finance fields until their exact meaning is confirmed;
- no modality table unless administrators need independent modality CRUD.

### Academic group / section

Minimum:

```text
code UUID PK
cycle_degree_code FK
name
sort_order
is_active
timestamps
```

Rules:

- names are configurable;
- not limited to A–D or one character;
- examples may include `A`, `A2`, `P`, `Grupo 1`, or `Único`;
- name is case-insensitively unique within one cycle degree;
- future modules reference `academic_group_code`, not repeated group strings.

### Cycle shift

Minimum:

```text
code UUID PK
cycle_code FK
name
entry_time
tolerance_minutes
sort_order
is_active
timestamps
```

Rules:

- one cycle enables one or two active shifts;
- tolerance is non-negative;
- future enrollment may select one or both shifts through an explicit relation;
- do not use `turn_1`, `turn_2`, arrays, JSON, or a permanent `both` enum.

### Future referential direction

Future enrollment references:

```text
student_code
academic_group_code
```

The group determines:

```text
group
→ cycle degree
→ cycle
→ branch + level + modality
```

Do not duplicate all of those keys in enrollment without an explicit historical snapshot requirement.

Future shift selection uses an intermediate relation such as `enrollment_shifts`.

Attendance references enrollment and a selected shift.

Evaluations targeting a class reference the academic group.

Payment obligations reference enrollment, not the cycle directly.

## 9. Confirmed future domain rules

### Students and contacts

- DNI is mandatory for the current Carrión student flow but remains an attribute.
- Student access uses DNI for compatibility.
- Minimal contact: name, phone, and free note describing who the person is.
- Do not reconstruct the old full apoderado model initially.

### Enrollment

- One active enrollment per student system-wide.
- Enrollment may change branch/group directly in v1.
- Transfer history is deferred.
- `roll_code` is a human code for OMR, search, and card display.
- Card QR contains DNI for compatibility with existing cards.

### Attendance

- Monday through Saturday in v1.
- No holiday calendar in the first delivery.
- Present until entry time.
- Late after entry time and through tolerance.
- After tolerance, automatic reading does not create a normal attendance; absence is derived.
- Authorized manual correction remains possible.
- No cron mass-inserts absence rows.

### Finance

Keep four meanings separate:

```text
obligation
payment
payment application
cash movement
```

Rules:

- obligations are generated during enrollment;
- partial payments exist;
- one payment may apply to multiple obligations;
- cashier owns the cash line, not the branch;
- multiple cashiers may operate simultaneously;
- no formal cash opening/closing in v1;
- cash received and change may be recorded;
- confirmed finance is reversed or voided, never hard-deleted.

### Evaluations and OMR

- Nextya is the functional source for OMR behavior and reports.
- Laravel owns students, evaluations, answers, and results.
- The OMR processor is isolated and does not own academic data.
- Scan images are discarded after processing.
- Final score may be corrected manually with actor and timestamp.
- Do not rewrite the OMR engine during initial integration.

### Migration

- Use repeatable, idempotent import commands.
- Map legacy semantic identifiers to new UUIDs in a technical migration map.
- Do not scatter `legacy_id` through domain tables.
- Rehearse the full migration and reconcile counts and totals before cutover.
- Staff will not operate v7 and v8 in parallel, but v7 remains technically recoverable during the cutover window.

## 10. UI principles

- Spanish copy.
- Calm and consistent administration UI.
- One dashboard shell.
- One navigation source.
- One global notification owner.
- One frontend `can()` helper.
- Lumi public components and classes only.
- No local visual system inside Aeduca.
- Index pages show summaries; detail pages show the aggregate.
- Do not add filters, wizards, bulk actions, tabs, or dashboards until the workflow needs them.
- Never create empty future UI.

## 11. Testing and verification

Prefer focused Feature tests at critical boundaries:

- authorization;
- transactions;
- active/inactive access;
- current branch isolation;
- database uniqueness and FKs;
- aggregate rollback;
- permission scope/direct-grant behavior;
- academic date, shift, degree, and group invariants.

Do not generate tests that only restate Laravel or Eloquent.

Mandatory checks:

```bash
composer run format
composer run check
pnpm run build
```

When schema or seeds change:

```bash
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against `aeduca`.

## 12. Stop conditions

Stop and investigate instead of inventing when:

- a domain term has conflicting meanings;
- a field has no confirmed operational owner;
- Aeduca Admin and Coedula materially disagree;
- a financial field cannot be named unambiguously;
- a database invariant cannot be protected cleanly;
- a proposed solution duplicates an existing owner;
- another module is required merely to make the current module look complete;
- Lumi lacks a public solution;
- required checks fail.

For minor reversible UI ambiguity, choose the simplest existing pattern.

For domain, security, finance, and migration ambiguity, investigate and document before implementation.
