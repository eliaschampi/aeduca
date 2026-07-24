# Aeduca

Aeduca v8 is Carrión's unified education platform. It improves the proven workflows of Aeduca v7, removes their structural debt, and incorporates useful ideas from Coedula and Nextya without copying their architecture.

The product brings administrators, teachers, and students into one system for academic management, access, attendance, evaluations, payments, cashbox, reports, photos, and shared files.

**Stack:** Laravel 13 · PHP 8.5 · Inertia · Svelte 5 · TypeScript strict · Lumi UI · PostgreSQL · pnpm

## Direction

- Aeduca v7 and Aeduca Admin provide the operational baseline that must not be lost.
- Coedula provides modern product, PostgreSQL, attendance, payments, Drive, and portal lessons.
- Nextya is used for OMR and specialized evaluation behavior.
- The current v8 architecture is the implementation baseline. Extend its owners and patterns before creating another layer.
- A small delivery is successful only when it completes a usable result; technical minimalism must not reduce a real workflow to isolated CRUD.

The permanent product and domain contract is in [`docs/SPEC.md`](docs/SPEC.md).

## Documentation

Read in order:

| Source             | Authority                                                   |
| ------------------ | ----------------------------------------------------------- |
| `AGENTS.md`        | Mandatory execution protocol                                |
| `docs/SPEC.md`     | Permanent product, domain, data, and architecture decisions |
| `docs/STATUS.md`   | Verified implementation state and next vertical             |
| `TASK.md`          | Single temporary active result, when present                |
| `../lumi-ui/docs/` | Lumi public UI contracts                                    |

[`analisis.md`](analisis.md) preserves investigation evidence requested by the project owner. It is not an active specification; confirmed decisions must live in `docs/SPEC.md`.

After a vertical, merge durable rules into `SPEC.md`, replace current facts in `STATUS.md`, and remove or replace `TASK.md`. Do not create competing roadmap or specification files.

## Engineering baseline

- Reuse the existing `AuthAccount`, employee access, permissions, `BranchContext`, Laravel/Inertia, and Lumi patterns.
- Give every responsibility and write path one explicit owner.
- Laravel owns domain rules, authorization, validation, and transactions.
- Svelte owns interaction and presentation; Lumi remains domain-neutral.
- PostgreSQL protects structural truth with FK, UNIQUE, and CHECK constraints.
- Main keys are UUID `code`; foreign keys are `<entity>_code`; neither embeds business meaning.
- Add permissions for stable business capabilities, not for every field or button.
- Prevent N+1 queries and oversized Inertia payloads; cache only after measurement.
- Preserve useful legacy behavior while replacing legacy identifiers and coupling.

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

During feature development, schema verification belongs in `aeduca_test`:

```bash
php artisan migrate:fresh --seed --env=testing
```

Never run `migrate:fresh` against `aeduca`. Do not apply feature migrations or seeds to `aeduca` merely to validate an unfinished direction; use it only for an accepted integrated review or when the project owner explicitly requests it.

## Local URLs

- Application: `http://127.0.0.1:8000` (alternate `:8001`)
- Vite assets: `http://127.0.0.1:5173`

## License

Proprietary / project-owned unless stated otherwise.
