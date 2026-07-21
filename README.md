# Aeduca

Aeduca v8 is Carrión's unified education management system.

It is a clean rebuild informed by Aeduca Admin, Aeduca Aula, Coedula, and Nextya. It is not a mechanical copy of any previous system.

**Stack:** Laravel 13 · PHP 8.5 · Inertia · Svelte 5 · TypeScript strict · Lumi UI · PostgreSQL · pnpm

## Documentation ownership

Read in this order:

| File               | Responsibility                                              |
| ------------------ | ----------------------------------------------------------- |
| `AGENTS.md`        | Mandatory working rules for coding agents                   |
| `docs/SPEC.md`     | Permanent product, domain, data, and architecture decisions |
| `docs/STATUS.md`   | Verified current implementation state                       |
| `TASK.md`          | Temporary scope and execution guide for the active vertical |
| `../lumi-ui/docs/` | Lumi public contracts, components, layout, and theming      |

There must be only one active `TASK.md`.

After completing a vertical:

1. Merge permanent confirmed decisions into `docs/SPEC.md`.
2. Record the real implementation in `docs/STATUS.md`.
3. Remove or replace `TASK.md`.
4. Do not create parallel Foundation, Roadmap, Development Line, or alternative specification files.

## Product line

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

## Essential rules

1. Investigate the current repository, Aeduca Admin, and Coedula before designing.
2. Preserve one owner for every responsibility and write path.
3. Implement the smallest coherent vertical; do not prepare speculative future layers.
4. Laravel owns domain rules, authorization, validation, and transactions.
5. Svelte owns interaction and presentation; Lumi remains domain-neutral.
6. PostgreSQL protects structural truth through explicit constraints.
7. Never authorize by role name or role code.
8. Use UUID `code` keys; never embed business meaning in identifiers.
9. Avoid N+1 queries and oversized Inertia payloads; do not cache before measurement.
10. Study legacy behavior for migration, but never copy legacy structure blindly.

Permanent rules live in [`docs/SPEC.md`](docs/SPEC.md).

## Setup

```bash
# PostgreSQL
createdb -h 127.0.0.1 -U postgres aeduca
createdb -h 127.0.0.1 -U postgres aeduca_test

# Backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed

# Testing environment
cp .env.testing.example .env.testing
php artisan key:generate --env=testing

# Lumi
cd ../lumi-ui
pnpm install --frozen-lockfile
pnpm run package

# Frontend
cd ../aeduca
pnpm install --frozen-lockfile
pnpm run build
```

Optional local administrator:

```bash
AEDUCA_SEED_ADMIN_LOGIN=admin \
AEDUCA_SEED_ADMIN_PASSWORD='use-a-local-secret' \
php artisan db:seed
```

Credentials belong only in `.env` or `.env.testing`.

## Commands

| Command               | Purpose                                                      |
| --------------------- | ------------------------------------------------------------ |
| `composer run dev`    | Laravel, queue, logs, and Vite                               |
| `composer run format` | PHP and frontend formatting                                  |
| `composer run check`  | PHP tests, formatting, TypeScript, lint, and frontend checks |
| `php artisan test`    | PHPUnit using `aeduca_test`                                  |
| `pnpm run dev`        | Vite HMR                                                     |
| `pnpm run build`      | Production frontend build                                    |
| `pnpm run check`      | TypeScript, lint, and formatting verification                |

When schema or seeds change, use:

```bash
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against the development database `aeduca`.

## URLs

| URL                     | Purpose                |
| ----------------------- | ---------------------- |
| `http://127.0.0.1:8000` | Aeduca application     |
| `http://127.0.0.1:8001` | Alternate Laravel port |
| `http://127.0.0.1:5173` | Vite assets only       |

## License

Proprietary / project-owned unless stated otherwise.
