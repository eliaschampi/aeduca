# Mandatory agent task — Aeduca v8 Foundation 01

You are working inside the repository `eliaschampi/aeduca`.

Your task is to implement only the first vertical foundation:

> Employee authentication, branches, roles, semantic permissions, individual permission overrides, and safe branch selection.

Do not implement students, academic cycles, enrollments, attendance, payments, cashbox, evaluations, OMR, Drive, attentions, reports, or dashboard metrics.

## Required reading

Before changing code, read:

1. `README.md`
2. `docs/FOUNDATION.md`
3. Lumi UI documentation referenced by the repository
4. Existing source files and configuration

Treat `docs/FOUNDATION.md` as the source of truth for domain and architecture decisions.

If `docs/FOUNDATION.md` does not exist, create it using the approved foundation specification provided with this task. Do not reinterpret or expand it.

## Current project constraints

* Laravel 13
* PHP 8.5
* Inertia
* Svelte 5
* TypeScript strict
* Lumi UI
* PostgreSQL
* pnpm only
* Spanish end-user interface

Do not change the selected stack.

Do not install an authorization package, authentication starter kit, module framework, repository abstraction, DTO library, service container abstraction, state library, CSS framework, or UI library.

Use Laravel authentication, Gates, Policies, middleware, Eloquent and database constraints directly.

## Mandatory architectural rules

### Data

* Main entity primary keys are UUID columns named `code`.
* Foreign keys use `<entity>_code`.
* Do not embed business information inside identifiers.
* Do not use DNI as a primary key.
* Do not store relationships in arrays or JSON.
* Use explicit foreign keys.
* Use PostgreSQL constraints for known invariants.
* Do not add soft deletes by default.
* Do not create human numbers without a confirmed functional use.
* Do not add `employee_number`.

### Laravel

* Models own relationships, casts and small scopes.
* Form Requests validate request shape.
* Policies or Gates authorize operations.
* Actions own multitable writes and business processes.
* Controllers remain thin.
* Use direct Eloquent; do not create repositories.
* Do not create generic `UserService`, `AuthService` or `PermissionService` classes with unrelated methods.
* A focused `PermissionResolver` is allowed because permission resolution has one explicit responsibility.
* Use transactions for multitable writes.
* Do not hide critical behavior in observers or database triggers.

### Authorization

A worker has one primary role.

A role is only a default permission bundle.

Never authorize by comparing a role code or name.

Forbidden examples:

```php
$user->role === 'ADMIN'
$user->employee_role_code === 'A'
```

```ts
if (user.role === 'SECRETARIA') { ... }
```

All capabilities use semantic permission names such as:

```text
dashboard.view
users.view
users.create
branches.select
```

Permission resolution order:

1. `users.is_super_admin` grants all known permissions.
2. An explicit `user_permissions` record wins.
3. Otherwise, a `role_permissions` grant applies.
4. Otherwise, permission is denied.

The backend is always authoritative.

The frontend receives effective permission names through shared Inertia props and uses exactly one helper such as:

```ts
can('users.create')
```

This helper controls presentation only.

Lumi components must not contain permission or role logic.

### Branch context

A worker may belong to multiple branches through `user_branches`.

Do not store branch membership in an array.

Do not add `current_branch_code` to `users`.

The current branch belongs to the authenticated session.

A selected branch must always be validated against `user_branches`.

When a worker has one branch, it may be selected automatically.

When a worker has multiple branches, provide a minimal Lumi UI branch selector.

## Required database entities

Implement only:

### `branches`

* `code` UUID primary key
* `name`
* `is_active`
* timestamps with timezone

### `employee_roles`

* `code` UUID primary key
* `name`
* `description` nullable
* `is_active`
* timestamps with timezone

### `permissions`

* `code` UUID primary key
* `name` unique
* `description` nullable
* timestamps with timezone

Permission names use lowercase dot notation.

### `role_permissions`

* `employee_role_code`
* `permission_code`
* composite unique
* foreign keys
* no unnecessary surrogate ID

### `users`

This is an employee domain profile, not the authenticatable credential record.

* `code` UUID primary key
* `first_name`
* `last_name`
* `email` nullable
* `phone` nullable
* `employee_role_code`
* `is_active`
* `is_super_admin`
* timestamps with timezone

Do not add fields that are not required by this task.

### `user_branches`

* `user_code`
* `branch_code`
* composite unique
* foreign keys
* no array column
* no unnecessary surrogate ID

### `user_permissions`

* `user_code`
* `permission_code`
* `is_allowed`
* composite unique
* foreign keys
* no unnecessary surrogate ID

### `auth_accounts`

This is the Laravel Authenticatable model for this slice.

* `code` UUID primary key
* `login` unique
* `password`
* `user_code` unique foreign key
* `is_active`
* `last_login_at` nullable
* timestamps with timezone

Do not store original or reversible passwords.

This slice supports employee accounts only.

Do not add a polymorphic account owner. Student authentication will be added later through an explicit migration and foreign key.

## Required application behavior

Implement:

1. Employee login page using Lumi UI.
2. Login action.
3. Logout action.
4. Authentication middleware.
5. Inactive account and inactive user rejection.
6. Branch membership loading.
7. Branch selection stored in session.
8. Branch selection authorization.
9. Effective permission resolution.
10. Shared Inertia props:

    * authenticated employee
    * authorized branches
    * current branch
    * effective permission names
11. One frontend `can()` helper.
12. A minimal authenticated Home page proving:

    * current user renders
    * current branch renders
    * branch selector works
    * permission-controlled sample action works
13. A development seeder for:

    * one active branch
    * one administrator role
    * initial permissions
    * one superadministrator employee
    * one auth account

Seeder credentials must not introduce a plaintext production password into committed source. Use an environment value or clearly development-only mechanism.

Do not build employee CRUD in this task.

## Repository cleanup required in this task

Correct only these confirmed bootstrap inconsistencies:

1. Use `pnpm` consistently in Composer scripts; remove `npm` usage from project scripts.
2. Align Composer project metadata with Aeduca instead of the untouched Laravel skeleton.
3. Align the Composer license with the proprietary project declaration.
4. Keep Lumi styles imported exactly once.
5. Do not redesign the current dashboard shell or Lumi theme.

Do not perform unrelated cleanup.

## Folder guidance

Use the existing Laravel structure and add only folders justified by implementation:

```text
app/
├── Actions/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
├── Models/
├── Policies/
└── Support/
```

A focused location such as this is acceptable:

```text
app/Support/Authorization/PermissionResolver.php
```

Do not create:

```text
app/Modules/
app/Repositories/
app/Contracts/Repositories/
app/Services/UserService.php
```

Do not create base classes unless at least two real implementations require shared behavior.

## Testing requirements

Create Feature tests covering:

1. Correct employee login.
2. Invalid credentials.
3. Inactive auth account.
4. Inactive employee.
5. Role permission granted.
6. User override allowed.
7. User override denied.
8. Superadministrator access.
9. Authorized branch selection.
10. Unauthorized branch rejection.
11. Protected route rejection without permission.
12. Shared Inertia permissions contain effective permission names.

Use factories where they reduce repetition.

Do not create tests that only confirm Laravel itself works.

## Required verification

Run and fix all failures:

```bash
AEDUCA_SEED_ADMIN_LOGIN=verification-admin \
AEDUCA_SEED_ADMIN_PASSWORD='<local-secret>' \
php artisan migrate:fresh --seed --env=testing
php artisan test
pnpm run check
pnpm run build
```

Review the final diff for:

* Role comparisons.
* Duplicate permission helpers.
* Unnecessary abstractions.
* Missing foreign keys.
* Missing unique constraints.
* Plaintext passwords.
* Arrays or JSON relationships.
* Local style blocks.
* Raw colors.
* Unrequested functionality.

## Required final report

At completion, report:

1. Files created.
2. Files modified.
3. Database entities added.
4. Permission resolution algorithm.
5. Session branch behavior.
6. Tests added.
7. Commands executed and their results.
8. Any unresolved risk.

Do not claim success unless all required verification commands pass.

## Stop conditions

Stop and explain instead of inventing when:

* A required rule conflicts with the existing code.
* A dependency appears necessary.
* A database invariant cannot be protected.
* Lumi lacks a required public component.
* The task would require implementing another domain module.
* You are uncertain whether behavior belongs to Laravel, Svelte or Lumi.

Do not expand scope to “prepare for future modules.”

Implement the smallest complete vertical slice described above.
