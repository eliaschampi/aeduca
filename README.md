# Aeduca

Education management system for **Carrión** (sedes, personal, caja, asistencia,
evaluación, atenciones, …). This repository is **Aeduca v8**: a clean rebuild,
not a mechanical clone of legacy Admin or Coedula.

**Stack:** Laravel 13 · PHP 8.5 · Inertia · Svelte 5 · TypeScript 7 · Lumi UI · PostgreSQL · pnpm

---

## Documentation (read in this order)

| Need                                               | File                               |
| -------------------------------------------------- | ---------------------------------- |
| Domain, architecture, principles (source of truth) | [`docs/SPEC.md`](docs/SPEC.md)     |
| What is implemented, gaps, next work               | [`docs/STATUS.md`](docs/STATUS.md) |
| Lumi install / layout / components                 | `../lumi-ui/docs/`                 |

Historical task notes were consolidated into those two files. Do not reintroduce
parallel roadmap/task documents.

---

## Project rules (mandatory)

1. **One way** — reuse existing patterns; domain in Laravel; UI presentation-only.
2. **Clean** — prefer delete over accumulate; Lumi classes only (no local styles / raw colors).
3. **Small** — no repositories, service gods, module frameworks, or extra UI kits.
4. **Semantic permissions** — never authorize by role name; one frontend `can()`.
5. **Not a Coedula clone** — take inspiration; protect Carrión rules; avoid over-engineering.

Full rules: [`docs/SPEC.md`](docs/SPEC.md).

---

## Architecture snapshot

| Layer                   | Owns                                                                 |
| ----------------------- | -------------------------------------------------------------------- |
| `app/`                  | Domain: models, HTTP, actions, support (permissions, branch context) |
| `routes/`               | HTTP entry points + semantic authorization middleware                |
| `resources/js/Pages/`   | Thin Inertia pages                                                   |
| `resources/js/Layouts/` | Dashboard shell                                                      |
| `resources/js/lib/`     | `can()`, navigation, color-scheme                                    |
| `@lumi-ui/svelte`       | Domain-neutral UI primitives                                         |

**Access + admin vertical (done):** credentials, session sedes, roles, usuarios,
semantic permissions. See [`docs/STATUS.md`](docs/STATUS.md). Students / academic
modules are not started.

---

## Setup

```bash
# PostgreSQL (once)
createdb -h 127.0.0.1 -U postgres aeduca
createdb -h 127.0.0.1 -U postgres aeduca_test

# PHP
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed # idempotent permission catalog

cp .env.testing.example .env.testing
php artisan key:generate --env=testing

# Optional dev administrator (safe to run again)
AEDUCA_SEED_ADMIN_LOGIN=admin \
AEDUCA_SEED_ADMIN_PASSWORD='use-a-local-secret' \
php artisan db:seed

# Local Lumi (once after clean clone)
cd ../lumi-ui
pnpm install --frozen-lockfile
pnpm run package

# Frontend
cd ../aeduca
pnpm install --frozen-lockfile
pnpm run build
# or full stack with HMR:
composer run dev
```

### URLs

| URL                       | What it is                         |
| ------------------------- | ---------------------------------- |
| **http://127.0.0.1:8000** | **Aeduca** (use this)              |
| http://127.0.0.1:8001     | Alternate if 8000 is taken         |
| http://127.0.0.1:5173     | Vite assets only — **not** the app |

After changing Lumi public exports:

```bash
cd ../lumi-ui && pnpm run package
cd ../aeduca && pnpm install
```

---

## Scripts

| Command               | Purpose                                     |
| --------------------- | ------------------------------------------- |
| `pnpm run dev`        | Vite HMR                                    |
| `pnpm run build`      | Production assets                           |
| `pnpm run typecheck`  | Native TypeScript 7 diagnostics             |
| `pnpm run lint`       | Fast Oxlint checks for JS, TS, and Svelte   |
| `pnpm run format`     | Prettier for frontend, config, and docs     |
| `pnpm run check`      | TypeScript + Oxlint + Prettier verification |
| `composer run format` | Laravel Pint + Prettier                     |
| `composer run check`  | Pint + PHPUnit + complete frontend checks   |
| `php artisan test`    | PHPUnit (uses `aeduca_test`)                |
| `composer run dev`    | Full local stack                            |

Credentials live only in `.env` / `.env.testing` (gitignored).  
Never run `migrate:fresh` against `aeduca`.

---

## License

Proprietary / project-owned unless stated otherwise.
