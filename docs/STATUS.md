# Aeduca v8 — Implementation status

> **Role.** What exists in code **now**. Domain rules: [`SPEC.md`](SPEC.md).
> If this disagrees with code, **code wins**.
>
> **Module status:** Access foundation + administration vertical — **closed**.

---

## 1. Closed modules

| Module | Scope | Status |
| ------ | ----- | ------ |
| Access foundation | Auth, session branch, PermissionResolver, shared Inertia props | **Done** |
| Sedes | Unified `/branches` picker + catalog + members (`user_branches`) | **Done** |
| Usuarios | List/create/profile, password, access, sparse exceptions | **Done** |
| Roles | List/create/edit, permission groups, `role_permissions` | **Done** |
| Academic / finance / OMR | Students, cycles, payments, … | **Not started** |

---

## 2. Architecture (live)

```text
app/
  Actions/     AuthenticateEmployee, LogoutEmployee, SelectBranch,
               SaveBranch, CreateEmployee, UpdateEmployee,
               SaveRole, SyncUserPermissionOverrides
  Http/Controllers/
               Auth, Home, Branch (session+catalog)
               Admin/{Branch,Employee,Role}Controller
  Support/     Authorization/PermissionResolver, Branches/BranchContext

resources/js/
  Pages/Auth/Login
  Pages/Home
  Pages/Branches/Index          session + admin catalog
  Pages/Admin/Employees/{Index,Create,Show}
  Pages/Admin/Roles/{Index,Form}
  lib/navigation.ts             Inicio · Sedes · Usuarios · Roles
```

**No dead parallels:** no `Admin/Branches` page, no dual “gestión de sedes” nav, no
user-level full permission matrix UI.

---

## 3. Data model (access)

```text
branches, employee_roles, permissions
role_permissions          role → default grants
users                     profile + employee_role_code + is_super_admin
user_branches             membership
user_permissions          sparse allow/deny exceptions
auth_accounts             credentials
```

**Effective permission:** super_admin → all; else role grants ± `user_permissions`.

---

## 4. HTTP surface (admin + access)

| Method | URI | Purpose |
| ------ | --- | ------- |
| * | login / logout / `/` / branches / current-branch | Foundation |
| POST/PUT | `/admin/branches` | Create/update sede + members |
| * | `/admin/employees/*` | Usuarios CRUD + password + access + overrides |
| * | `/admin/roles/*` | Roles + permission package |

All admin writes gated by semantic permissions (`employees.*`, `branches.*`, `roles.*`).

---

## 5. UX contract (finished)

| Screen | Pattern |
| ------ | ------- |
| User show | Hero + **2 tabs** (Perfil \| Seguridad). Password/access **only** in header ⋮ |
| User create | Stepped tabs: Persona → Puesto → Acceso |
| Roles form | Tabs: Identidad \| Permisos (collapsible groups) |
| Sedes | One page: cards + dialog with members |
| Home | Quick access: Sedes, Usuarios, Roles (no duplicate sede cards) |

---

## 6. Tests

Feature coverage: auth, branch selection, branch management, employee management,
role management, permission overrides, integrity, Inertia shared props.

```bash
php artisan test
pnpm run check
pnpm run build
```

---

## 7. Next product work (outside this module)

1. Academic groundwork (niveles, modalidades, ciclos, grados, grupos)
2. Students / enrollments (when product prioritizes)

Do not reopen access/admin for speculative layers.
