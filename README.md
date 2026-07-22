# Aeduca

Aeduca v8 is Carrión's unified education management system: a clean rebuild informed by Aeduca Admin, Aeduca Aula, Coedula, and Nextya—not a structural copy of them.

**Stack:** Laravel 13 · PHP 8.5 · Inertia · Svelte 5 · TypeScript strict · Lumi UI · PostgreSQL · pnpm

## Documentation

Read in order:

| Source             | Authority                                                   |
| ------------------ | ----------------------------------------------------------- |
| `AGENTS.md`        | Mandatory execution protocol                                |
| `docs/SPEC.md`     | Permanent product, domain, data, and architecture decisions |
| `docs/STATUS.md`   | Verified implementation state and next vertical             |
| `TASK.md`          | Single temporary active scope, when present                 |
| `../lumi-ui/docs/` | Lumi public UI contracts                                    |

After a vertical: merge durable rules into `SPEC.md`, replace current facts in `STATUS.md`, and remove or replace `TASK.md`. Do not create competing roadmap or specification files. Consolidation may relocate or merge confirmed context; it must not silently delete it.

## Product sequence

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

## Engineering baseline

- Inspect v8, Aeduca Admin, and Coedula before designing a capability.
- Give every responsibility and write path one explicit owner.
- Implement the smallest coherent vertical; do not prepare speculative layers.
- Laravel owns domain rules, authorization, validation, and transactions.
- Svelte owns interaction and presentation; Lumi remains domain-neutral.
- PostgreSQL protects structural truth with FK, UNIQUE, and CHECK constraints.
- Main keys are UUID `code`; foreign keys are `<entity>_code`; neither embeds business meaning.
- Never authorize by role name or role code.
- Prevent N+1 queries and oversized Inertia payloads; cache only after measurement.
- Preserve proven legacy behavior for migration without copying legacy structure.

Full rules: [`AGENTS.md`](AGENTS.md) and [`docs/SPEC.md`](docs/SPEC.md).

## Setup

```bash
# Databases
createdb -h 127.0.0.1 -U postgres aeduca
createdb -h 127.0.0.1 -U postgres aeduca_test

# Backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed

# Test environment
cp .env.testing.example .env.testing
php artisan key:generate --env=testing

# Lumi package
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
| `php artisan test`    | PHPUnit against `aeduca_test`                                |
| `pnpm run dev`        | Vite HMR                                                     |
| `pnpm run build`      | Production frontend build                                    |
| `pnpm run check`      | TypeScript, lint, and formatting verification                |

After schema or seed changes:

```bash
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against `aeduca`.

## Local URLs

- Application: `http://127.0.0.1:8000` (alternate `:8001`)
- Vite assets: `http://127.0.0.1:5173`

## License

Proprietary / project-owned unless stated otherwise.
