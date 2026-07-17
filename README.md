# Aeduca

Education management system: students, teachers, cashbox, attendance, evaluation, attentions, and more.

**Stack:** Laravel · Inertia · Svelte 5 · TypeScript 7 · Lumi UI

---

## Project rules (mandatory)

These rules are non-negotiable. Every task, PR, and agent change must respect them.

### 1. Consistency and patterns — 100%

- One way to do each thing. Reuse existing patterns before inventing new ones.
- Match naming, folder structure, and component composition already in the codebase.
- Domain logic stays in Laravel (models, actions, policies). UI stays semantic and domain-mapped—never hardcode business rules into Lumi components.
- Follow Lumi UI contracts: public components + public CSS classes + tokens. Source of truth: `~/Documents/lumi-ui/docs`.

### 2. Clean and organized — 100%

- Clean is priority. Prefer delete and simplify over accumulate.
- No redundancy: one owner for each concern (no duplicated helpers, styles, or parallel abstractions).
- Lightweight structure: small files, clear folders, no dead code, no “just in case” layers.
- Frontend composition uses Lumi classes (`.lumi-stack`, `.lumi-grid`, dashboard shell). Do **not** add local `<style>` blocks, inline styles, raw colors, or a second CSS framework unless there is no public Lumi alternative.
- Brand identity = CSS seeds only (`resources/js/styles/lumi-theme.css`). Theme preference = optional color-scheme controller.

### 3. Performance and efficiency — 100%

- Prefer the smallest change that solves the problem.
- Avoid unnecessary queries, N+1, eager loads without need, and client bundle bloat.
- Lazy-load only when it pays off; keep eager page maps intentional.
- No duplicate work: one color-scheme controller, one styles import, one layout shell.
- Measure before optimizing; never trade clarity for premature micro-optimization.

### 4. Modern and beautiful

- Svelte 5 runes only (`$state`, `$derived`, `$props`, `$effect`).
- TypeScript strict; types at boundaries (Inertia props, API payloads, domain DTOs).
- Calm, premium UI via Lumi: semantic colors, tokens, accessibility, keyboard-first overlays.
- Spanish UI copy for end users (project locale is `es`).

### 5. Decision filter (before writing code)

1. Does something already solve this?
2. Is the API semantic and free of domain leakage into Lumi?
3. Can layout be composed with public Lumi classes instead of custom CSS?
4. Will this stay easy to delete later?
5. Is there only one owner for this behavior?

If any answer is wrong, stop and redesign.

---

## Architecture snapshot

| Layer | Owns |
| ----- | ---- |
| `app/` | Domain: models, HTTP, policies, actions |
| `routes/` | HTTP entry points only |
| `resources/js/Pages/` | Inertia pages (thin, composable) |
| `resources/js/Layouts/` | App shells (dashboard, auth later) |
| `resources/js/lib/` | Shared client utilities (nav, color-scheme) |
| `@lumi-ui/svelte` | Domain-neutral UI primitives and tokens |

**Implemented foundation:** employee credentials, workers, branches, semantic
permissions, individual overrides, and session branch context.

`AuthAccount` owns credentials and is Laravel's authenticated actor. `User` owns
the employee profile; teachers remain employees and will receive academic
assignments when that domain is implemented. Students are intentionally not part
of this first vertical.

---

## Frontend conventions

```text
resources/js/
├── app.ts                 # Inertia bootstrap + Lumi styles
├── app.d.ts               # Shared ambient types
├── styles/lumi-theme.css  # Brand seeds only
├── lib/                   # Client utilities (nav, color-scheme)
├── Layouts/               # Persistent shells
└── Pages/                 # Inertia pages (start: Home)
```

- Import Lumi styles **once** in `app.ts`.
- Import brand CSS after Lumi styles.
- Use `@lumi-ui/svelte` components; optional `@lumi-ui/svelte/color-scheme` for light/dark/system.
- Dashboard shell: `.lumi-dashboard-layout` + `Sidebar` + `Navbar` (see Lumi Guide §4).
- Reference implementation adapted from `lumi-ui/examples/dashboard`.

---

## Setup

```bash
# PostgreSQL databases (create once; skip each command if it already exists)
createdb -h 127.0.0.1 -U postgres aeduca
createdb -h 127.0.0.1 -U postgres aeduca_test

# PHP
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate

# Isolated PostgreSQL environment for the critical test suite
cp .env.testing.example .env.testing
php artisan key:generate --env=testing

# Optional development administrator
AEDUCA_SEED_ADMIN_LOGIN=admin \
AEDUCA_SEED_ADMIN_PASSWORD='use-a-local-secret' \
php artisan db:seed

# Local Lumi package (required once after a clean clone)
cd ../lumi-ui
pnpm install --frozen-lockfile
pnpm run package

# Frontend (pnpm)
cd ../aeduca
pnpm install --frozen-lockfile
pnpm run build         # production assets (works without Vite running)
# or for HMR:
composer run dev       # Laravel + queue + logs + Vite
```

### Open the app (important)

| URL | What it is |
| --- | ---------- |
| **http://127.0.0.1:8000** | **Aeduca (Laravel + Inertia)** — use this |
| http://127.0.0.1:8001 | Same app if port 8000 is already taken |
| http://127.0.0.1:5173 | Vite asset server only — **not** the app |

Do **not** open port `5173` expecting the dashboard. That is only HMR/assets.


Lumi UI is linked locally via `file:../lumi-ui`. After changing Lumi public exports:

```bash
cd ../lumi-ui && pnpm run package
cd ../aeduca && pnpm install
```

---

## Scripts

| Command | Purpose |
| ------- | ------- |
| `pnpm run dev` | Vite HMR |
| `pnpm run build` | Production assets |
| `pnpm run check` | TypeScript 7 (`tsc --noEmit`) |
| `php artisan test` | PHPUnit |
| `composer run dev` | Full local stack |

Local and test database credentials live only in `.env` and `.env.testing`.
Both files are ignored by Git; their committed `*.example` files document the
required variables. `phpunit.xml` contains test behavior only, never connection
credentials.

Normal commands use `aeduca`. PHPUnit and commands with `--env=testing` use
`aeduca_test`. Never run `migrate:fresh` against `aeduca`: that command deletes
all tables before recreating them.

---

## Docs for agents and humans

| Need | Read |
| ---- | ---- |
| These rules | This README |
| Domain source of truth | `docs/FOUNDATION.md` |
| Current vertical scope | `TASK.md` |
| Implementation status | `STATUS.md` |
| Lumi install / layout / theming | `~/Documents/lumi-ui/docs/GUIDE.md` |
| Component selection | `~/Documents/lumi-ui/docs/COMPONENTS.md` |
| Lumi agent routing | `~/Documents/lumi-ui/docs/AGENT_GUIDE.md` |
| Runnable UI reference | `~/Documents/lumi-ui/examples/dashboard` |

---

## License

Proprietary / project-owned unless stated otherwise.
