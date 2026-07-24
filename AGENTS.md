# Aeduca v8 — Mandatory Agent Protocol

This file governs execution. Product and domain truth belongs in `docs/SPEC.md`.

## 1. Authority and required reading

Before changing code, read:

1. `README.md`;
2. `AGENTS.md`;
3. `docs/SPEC.md`;
4. `docs/STATUS.md`;
5. the single root `TASK.md`, when present;
6. relevant current implementation and tests;
7. the relevant Aeduca v7, Coedula, Nextya, and Lumi evidence identified by the specification.

Authority is exclusive:

| Source           | Owns                                                        |
| ---------------- | ----------------------------------------------------------- |
| `docs/SPEC.md`   | Permanent product, domain, data, and architecture decisions |
| `docs/STATUS.md` | Verified current implementation facts                       |
| `TASK.md`        | One temporary observable result                             |
| Existing code    | Accepted implementation baseline and current patterns       |

`analisis.md` is retained investigation evidence, not an active authority. A task cannot silently override the specification, and documentation cannot declare behavior implemented when the code does not contain it.

Preserve the current architecture unless a confirmed product rule or verified defect requires a correction. When sources conflict, inspect code and operational evidence, report the conflict, and make the smallest coherent correction.

A documentation refactor must audit coverage. Compact by merging or relocating a confirmed rule into its owner; never erase valid context merely to reduce line count.

## 2. Product investigation before implementation

For every capability:

1. Inspect current Aeduca v8 code and tests first.
2. Inspect the real workflow in Aeduca v7/Aeduca Admin.
3. Inspect the relevant modern behavior in Coedula.
4. Use Nextya only for evaluations, OMR, and specialized reports.
5. Separate the behavior worth preserving from historical structure and coupling.
6. Define the observable result and acceptance before broad implementation.
7. Present a concise execution plan before coding.

Evidence is not a template, but neither is it optional context. Do not invent domain semantics for convenience, and do not use “minimal scope” to omit confirmed workflow essentials.

For a product workflow, the investigation must identify as applicable:

- entry point and navigation;
- list, search, and operational filters;
- profile/detail and primary actions;
- active/inactive and historical behavior;
- authorization and self-service boundaries;
- transactional and database invariants;
- empty, validation, and failure states.

## 3. Existing architecture is the baseline

Extend the owners already present in `main`:

- `AuthAccount` owns authentication credentials;
- `User` owns employee identity, including teachers;
- roles, direct grants, permission scope, and `PermissionDependency` own staff authorization;
- `BranchContext` owns the current authorized branch;
- focused Actions own real aggregate writes and transactions;
- FormRequests own input normalization and validation shape;
- controllers compose queries/actions and Inertia responses;
- Svelte owns interaction and presentation;
- Lumi supplies domain-neutral UI primitives.

Do not add a second authentication system, authorization package, client branch/permission store, repository layer, module framework, service container abstraction, or UI system when these owners can be extended cleanly.

### Ownership and code shape

- One owner and write path per responsibility.
- Implement the smallest result that is operationally complete; reuse current patterns first.
- Use a focused Action for an aggregate write, transaction, or real invariant.
- Keep a simple validated row create/update direct.
- Do not create generic repositories, service gods, base Actions, DTO libraries that rename arrays, Query wrappers for simple queries, CQRS, or event sourcing.
- Create subfolders or shared abstractions only when current content proves the need.
- Keep files cohesive; do not split them only to reduce line count.
- Prefer direct calls for mandatory consequences. Events require independent consumers; observers never hide critical workflows.

### Data

- Main entities use UUID primary key `code`; FKs use `<entity>_code`.
- Never encode year, branch, level, degree, group, modality, or status in technical identifiers.
- Relationships use explicit FKs/intermediate tables, never arrays or JSON.
- Do not use manual polymorphic type/code relations when explicit FKs work.
- PostgreSQL owns structural invariants through FK, UNIQUE, and CHECK.
- Stable composed read models reused by lists, search, profiles, or reports belong in named PostgreSQL views; Laravel still applies authorization, scope, filters, and pagination.
- Database-owned atomic generation or reservation, such as `roll_code`, belongs in a focused, explainable, and tested SQL function.
- Keep simple validated row CRUD in Eloquent; do not wrap every insert or update in a view/function or hide workflows in SQL.
- Business-critical multi-table writes are transactional.
- Do not add soft delete by default.
- Never authorize by role name or role code.
- Do not hide authorization, academic, attendance, payments, or cashbox behavior in triggers.
- Legacy semantic codes map to UUIDs in migration tooling; never reinterpret them silently.
- Rehearse and reconcile migrations before production cutover.

### Permissions

- Add a permission only for a stable staff capability that needs distinguishable authority.
- Prefer `domain.view` and `domain.manage`; `manage` includes `view` through the existing dependency owner.
- Do not add permissions per button, field, tab, or routine CRUD action.
- Student self-service is authorized by authenticated identity and ownership, not by granting administrative `students.view`.
- Add a more specific permission only when operational evidence shows that a different employee must perform that responsibility.

### Performance

- Prevent N+1 queries and unnecessary eager loading.
- Index/list pages load displayed fields plus bounded summaries.
- Detail pages compose one subject profile from focused queries; they do not load every downstream history row by default.
- Index the actual search/filter paths; use PostgreSQL text search/trigram support when measured or expected volume requires it.
- Do not add persistent permission, branch, or catalog caches without measurement.

### Frontend

- Svelte 5 runes and strict TypeScript only.
- Spanish end-user copy.
- One navigation source, authenticated shell, frontend `can()` helper, and global notification owner.
- Use Lumi public components, classes, and tokens.
- Do not add a second UI/CSS system, raw colors, inline styles, or local style blocks when Lumi has a public solution.
- Do not modify Lumi internals for an Aeduca-only requirement.
- Do not create empty future tabs, routes, tables, permissions, or actions.

## 4. Scope and completeness

Implement only the active `TASK.md`, but define its boundary by an observable result rather than by one table or controller.

A required adjacent capability is not scope creep when the accepted workflow cannot function without it. Decorative future modules remain out of scope. If investigation proves that the task omitted a required capability, revise the task before continuing instead of delivering a knowingly incomplete result.

Do not claim a vertical complete merely because migrations, CRUD, or tests exist. Completion requires its stated entry point, primary workflow, server authorization, persistence, list/detail feedback, and acceptance behavior to work together.

Stop and investigate when:

- a domain term has multiple operational meanings;
- a field or state has no confirmed owner;
- local evidence materially contradicts the task;
- a workflow would expose another branch or actor without confirmed authorization;
- an invariant cannot be protected clearly;
- an existing owner or pattern would be duplicated;
- a new runtime dependency appears necessary;
- Lumi lacks the required public contract;
- required checks fail.

For minor reversible UI ambiguity, use the simplest existing pattern. Never guess about identity, authorization, payments, cashbox, or migration semantics.

## 5. Database workflow and verification

Feature development and automated tests use `aeduca_test`. Do not migrate or seed the local `aeduca` database to validate an unfinished direction. Use `aeduca` only when the project owner explicitly requests an integrated review or after the vertical is accepted.

After schema or seed changes:

```bash
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against `aeduca`.

For code changes, required closure checks are:

```bash
composer run format
composer run check
pnpm run build
```

For documentation-only changes, run targeted Markdown/format checks and `git diff --check`; a production build is unnecessary unless code or generated contracts changed. Do not claim completion while a required relevant check fails.

## 6. Documentation closure

After a vertical:

1. Merge durable confirmed rules into `docs/SPEC.md`.
2. Replace current facts in `docs/STATUS.md`.
3. Remove or replace `TASK.md`.
4. Do not preserve obsolete decisions as a competing active roadmap/specification.

## 7. Final report

Report only:

- evidence inspected;
- decisions made;
- implementation completed;
- deliberately omitted work;
- tests and checks executed;
- remaining domain uncertainty.
