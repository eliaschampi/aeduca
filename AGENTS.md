# Aeduca v8 — Mandatory Agent Protocol

This file governs execution. Product and domain truth belongs in `docs/SPEC.md`.

## 1. Authority and required reading

Before changing code, read:

1. `README.md`
2. `AGENTS.md`
3. `docs/SPEC.md`
4. `docs/STATUS.md`
5. the single root `TASK.md`, when present
6. relevant implementation and tests
7. relevant Lumi public documentation

Authority is exclusive:

| Source           | Owns                                                        |
| ---------------- | ----------------------------------------------------------- |
| `docs/SPEC.md`   | Permanent product, domain, data, and architecture decisions |
| `docs/STATUS.md` | Verified current implementation facts                       |
| `TASK.md`        | Temporary active vertical only                              |
| Existing code    | Implementation evidence, not automatic domain truth         |

A task cannot silently override the specification. When sources materially conflict, inspect current code and legacy evidence, report the conflict, and make the smallest coherent correction.

A documentation refactor must audit coverage against its sources. Compact by merging or relocating a confirmed rule into its owner; never erase valid context merely to reduce line count. Superseded decisions remain discoverable in Git history, not as competing active guidance.

## 2. Investigation before implementation

For every capability:

1. Inspect current Aeduca v8 code and tests.
2. Inspect the real workflow in local Aeduca Admin.
3. Inspect the modern approach in local Coedula.
4. Use Nextya only for evaluation or OMR work.
5. Separate useful behavior from historical structure.
6. Present a concise execution plan before coding.

Aeduca Admin and Coedula are evidence, not templates. Never invent a table, field, permission, package, UI pattern, or abstraction for convenience.

## 3. Engineering contract

### Ownership and architecture

- One owner and write path per responsibility.
- Implement the smallest complete vertical; reuse current patterns first.
- Laravel owns authorization, validation, domain rules, and transactions.
- Svelte owns interaction and presentation.
- Lumi components remain domain-neutral.
- Use a focused Action for aggregate writes, transactions, or real invariants.
- Keep a simple validated row update direct.
- Do not create generic repositories, service gods, module frameworks, base Actions, DTO libraries, Query wrappers for simple queries, CQRS, or event sourcing.
- Create domain subfolders only when current content justifies them; create no base class before two real uses require shared behavior.
- Keep files cohesive; do not split them only to reduce line count.
- Prefer direct calls for mandatory consequences. Use events only for real independent consumers, and never hide critical workflows in observers.

### Data

- Main entities use UUID primary key `code`; FKs use `<entity>_code`.
- Never encode year, branch, level, degree, group, modality, or status in technical identifiers.
- Relationships use explicit FKs/intermediate tables, never arrays or JSON.
- Do not use manual polymorphic type/code relations when explicit FKs are possible.
- PostgreSQL owns structural invariants through FK, UNIQUE, and CHECK.
- Business-critical multi-table writes are transactional.
- Do not add soft delete by default.
- Never authorize by role name or role code.
- Do not hide authorization, academic, attendance, or finance workflows in triggers.
- Legacy semantic codes map to UUIDs in migration tooling, not domain columns; never reinterpret legacy data silently.
- Rehearse and reconcile migrations before production cutover.

### Performance

- Prevent N+1 queries and unnecessary eager loading.
- Index pages load summaries; detail pages load one aggregate.
- Do not add persistent permission, branch, or catalog caches without measurement.
- Prefer bounded, readable work over clever optimization.

### Frontend

- Svelte 5 runes and strict TypeScript only.
- Spanish end-user copy.
- One navigation source, authenticated shell, frontend `can()` helper, and global notification owner.
- Use Lumi public components, classes, and tokens.
- Do not add a second UI/CSS system, raw colors, inline styles, or local style blocks when Lumi has a public solution.
- Do not modify Lumi internals for an Aeduca-only requirement.
- Do not create empty future tabs, routes, tables, permissions, or UI.

## 4. Scope and stop conditions

Implement only the active `TASK.md`. Stop and investigate when:

- a domain term has multiple meanings;
- a field has no confirmed operational owner;
- local evidence materially contradicts the task;
- another module is required only to make this one appear complete;
- an invariant cannot be protected clearly;
- an existing owner or pattern would be duplicated;
- a new runtime dependency appears necessary;
- Lumi lacks the required public contract;
- required checks fail.

For minor reversible UI ambiguity, use the simplest existing pattern. Never guess about domain, security, finance, or migration semantics.

## 5. Verification

Required:

```bash
composer run format
composer run check
pnpm run build
```

After schema or seed changes:

```bash
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against `aeduca`. Do not claim completion while a required check fails.

## 6. Documentation closure

After a vertical:

1. Merge durable confirmed rules into `docs/SPEC.md`.
2. Replace current facts in `docs/STATUS.md`.
3. Remove or replace `TASK.md`.
4. Do not preserve obsolete reasoning in another roadmap/specification.

## 7. Final report

Report only:

- evidence inspected;
- decisions made;
- implementation completed;
- deliberately omitted work;
- tests and checks executed;
- remaining domain uncertainty.
