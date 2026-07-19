# Aeduca v8 — Implementation status

> **Role.** What exists in code **now**. Domain rules: [`SPEC.md`](SPEC.md).
> If this disagrees with code, **code wins**.
>
> **Module status:** Access foundation + administration vertical — **corrected**
> (permission scope model, single branch ownership, manage→view).

---

## 1. Closed modules

| Module                   | Scope                                                                  | Status          |
| ------------------------ | ---------------------------------------------------------------------- | --------------- |
| Access foundation        | Auth, session branch, PermissionResolver, shared Inertia props         | **Done**        |
| Sedes                    | Unified `/branches` picker + catalog attributes (no membership writes) | **Done**        |
| Usuarios                 | List/create/profile panels, password, access, **direct** permissions   | **Done**        |
| Roles                    | List/create/edit, **permission scope** (assignable boundary)           | **Done**        |
| Academic / finance / OMR | Students, cycles, payments, …                                          | **Not started** |

---

## 2. Architecture (live)

```text
app/
  Actions/     AuthenticateEmployee, LogoutEmployee, SelectBranch,
               SaveBranch, CreateEmployee, UpdateEmployee,
               SaveRole, SyncUserPermissions
  Support/     Authorization/{PermissionResolver, PermissionDependency}
               Branches/BranchContext
  Routes/      semantic can:* authorization before validation

database/seeders/
  PermissionSeeder          idempotent permission catalog
  DatabaseSeeder            optional idempotent administrator bootstrap

resources/js/
  Pages/Branches/Index
  Pages/Admin/Employees/{Index,Create,Show + panels/*}
  Pages/Admin/Roles/{Index,Form}

quality/
  Laravel Pint             PHP formatting
  Prettier + Svelte plugin frontend/config/docs formatting
  Oxlint                   fast zero-config frontend linting
  TypeScript 7             frontend type checking
```

---

## 3. Data model (access)

```text
branches, employee_roles, permissions
employee_role_permission_scopes   role → assignable permissions
users                             profile + role + is_super_admin
user_branches                     membership (employee admin owns writes)
user_permissions                  direct grants (presence = allowed)
auth_accounts                     credentials
```

**Effective permission:** super_admin → all; else `user_permissions ∩ role scope`.

**manage → view** expanded on persist (role scope + user grants).

---

## 4. HTTP surface

| Method   | URI                                              | Purpose                                                        |
| -------- | ------------------------------------------------ | -------------------------------------------------------------- |
| *        | login / logout / `/` / branches / current-branch | Foundation                                                     |
| POST/PUT | `/admin/branches`                                | Create/update sede **attributes only**                         |
| *        | `/admin/employees/*`                             | Usuarios CRUD + password + explicit access state + permissions |
| PUT      | `/admin/employees/{id}/permissions`              | Direct grants                                                  |
| *        | `/admin/roles/*`                                 | Roles + permission scope                                       |

---

## 5. UX contract

| Screen      | Pattern                                                                     |
| ----------- | --------------------------------------------------------------------------- |
| Branches    | Session pick + catalog; dialog name/active only; employee count read-only   |
| User create | One form: basic / role+sedes / credentials; all fields with placeholders    |
| User show   | Panels: General, Access, Permissions (+ password dialog)                    |
| Role form   | Assignable scope only — search + domain rail + list rows; **no select-all** |
| Superadmin  | Full access without permission matrix editing                               |

---

## 6. Permission UI notes

- Full catalog access is **`users.is_super_admin`**, not “check every permission on a role”.
- Role scope picker is intentionally explicit (performance + intent).
- Component: `resources/js/Pages/Admin/Roles/RolePermissionScope.svelte`.

---

## 7. Not done

Academic cycles, students, finance, OMR, student portal.
