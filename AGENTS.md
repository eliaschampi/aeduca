# Aeduca v8 — Mandatory Agent Rules

This file defines how a coding agent must work. It does not duplicate the domain specification.

## Authority order

Before changing code, read:

1. `README.md`
2. `AGENTS.md`
3. `docs/SPEC.md`
4. `docs/STATUS.md`
5. `TASK.md`
6. Relevant existing code and tests
7. Relevant Lumi documentation

Authority:

- `docs/SPEC.md` owns permanent product, domain, data, and architecture decisions.
- `docs/STATUS.md` owns verified facts about the current code.
- `TASK.md` owns only the active vertical.
- Existing code is implementation evidence, not automatic proof of correct domain meaning.
- A temporary task may not silently override the permanent specification.

When sources materially conflict, inspect the implementation and legacy evidence, report the conflict, and make the smallest coherent correction.

## Investigation protocol

Before implementing a capability:

1. Inspect the current Aeduca v8 code and tests.
2. Inspect the corresponding real workflow in local Aeduca Admin.
3. Inspect the corresponding modern approach in local Coedula.
4. Use Nextya only for evaluation or OMR work.
5. Separate useful behavior from historical structure.
6. Produce a concise execution plan before coding.

Aeduca Admin and Coedula are evidence, not templates.

Do not invent a table, field, permission, package, UI pattern, or abstraction because it appears convenient.

## Engineering rules

- One owner for every responsibility and write path.
- Use the smallest complete vertical.
- Reuse current patterns before creating new ones.
- Domain rules, authorization, validation, and transactions stay in Laravel.
- Svelte handles interaction and presentation.
- Lumi components never contain school, role, payment, or attendance rules.
- Use UUID primary keys named `code`.
- Use explicit foreign keys named `<entity>_code`.
- Do not embed business meaning in technical identifiers.
- Do not store relationships in arrays or JSON.
- Do not use manual polymorphic type/code relations when explicit FKs are possible.
- Do not add soft delete by default.
- Do not authorize by role name or role code.
- Do not add persistent permission, branch, or catalog caches without measurement.
- Do not create generic repositories, service gods, module frameworks, base Actions, DTO libraries, CQRS, or event sourcing.
- A simple query does not need a Query class.
- A simple single-row update does not automatically need an Action.
- A focused Action is appropriate for a transaction, aggregate write, or real invariant.
- Avoid N+1 queries and unnecessary eager loading.
- Index pages load summaries; detail pages load one aggregate.
- Do not create empty future tabs, routes, tables, permissions, or UI.

## Frontend rules

- Svelte 5 runes only.
- TypeScript strict.
- Spanish end-user copy.
- One navigation source.
- One frontend `can()` helper.
- One authenticated layout shell.
- One global notification owner.
- Use Lumi public components, classes, and tokens.
- No second CSS framework or UI library.
- No raw colors, inline styles, or local style blocks when Lumi has a public solution.
- Do not modify Lumi internals for an Aeduca-only requirement.

## Data and migration rules

- PostgreSQL owns structural invariants through FK, UNIQUE, and CHECK.
- Business-critical multi-table writes are transactional.
- Do not hide authorization, academic, attendance, or finance behavior in triggers.
- Legacy semantic codes map to UUIDs through migration tooling, not domain columns.
- Never reinterpret legacy data silently.
- Rehearse and reconcile migrations before production cutover.

## Scope discipline

Implement only the active `TASK.md`.

Stop and investigate when:

- a domain term has two possible meanings;
- a field has no confirmed operational owner;
- local evidence materially contradicts the task;
- another module is required only to make the current module appear complete;
- a database invariant cannot be protected clearly;
- an existing owner or pattern would be duplicated;
- Lumi lacks a required public contract;
- project checks fail.

For a minor reversible UI ambiguity, choose the simplest existing pattern.

For domain, security, finance, or migration ambiguity, do not guess.

## Verification

Use repository-owned commands:

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

Do not claim completion while a required check fails.

## Documentation closure

After completing the active vertical:

1. Merge permanent confirmed rules into `docs/SPEC.md`.
2. Replace current-state facts in `docs/STATUS.md`.
3. Remove or replace `TASK.md`.
4. Do not preserve obsolete task reasoning as another source of truth.

## Final report

Report only:

- evidence inspected;
- decisions made;
- implementation completed;
- deliberately omitted work;
- tests and checks executed;
- remaining domain uncertainty.
