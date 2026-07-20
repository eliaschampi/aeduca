# TASK — Academic Structure Vertical

Act as a senior software engineer and pragmatic architecture designer inside the local Aeduca v8 repository.

Your objective is to implement the smallest coherent academic foundation that can later support enrollment, attendance, evaluations, and payment obligations.

Do not build isolated CRUD screens without understanding their downstream purpose.

## 1. Required reading

Read before coding:

1. `README.md`
2. `AGENTS.md`
3. `docs/SPEC.md`
4. `docs/STATUS.md`
5. Current migrations, models, Actions, controllers, routes, pages, and tests
6. Relevant Lumi documentation

Then inspect the local legacy evidence:

### Aeduca Admin

Locate and inspect:

- `cycle`;
- `degree`;
- `section`;
- `register`;
- cycle-management backend and Vue screens;
- current-year switching;
- attendance configuration;
- enrollment filters;
- reports by cycle, degree, and section.

Extract operational behavior, not concatenated identifiers or substring logic.

### Coedula

Locate and inspect:

- academic cycles;
- cycle/grade structure;
- enrollment `group_code`;
- attendance shifts and tolerance;
- evaluation targeting;
- cycle-management UI.

Extract useful normalization and interaction patterns, but do not copy repeated group strings, arrays, or permanent `turn_1` / `turn_2` / `both` modeling.

## 2. First output: concise plan

Before modifying code, present a concise plan containing:

- evidence found in Aeduca Admin;
- evidence found in Coedula;
- relevant current v8 patterns to reuse;
- proposed tables and ownership;
- database invariants;
- UI workflow;
- material unresolved questions.

Do not produce another large architecture document.

If local evidence materially contradicts this task, report the contradiction before coding.

## 3. Business purpose

The target path is:

```text
student
→ enrollment
→ academic group
→ cycle + grade + branch
→ selected shifts
→ attendance
→ evaluations
→ payment obligations
```

This task implements only the academic structure required by that path.

## 4. Confirmed domain direction

### Cycle

A cycle:

- belongs to the current authorized branch;
- has a level;
- has a modality;
- has a name;
- has start and end dates;
- may cross calendar years;
- may be active or inactive;
- offers selected grade numbers;
- owns one or two shifts;
- owns groups through its cycle degrees.

Do not add a global year or `current_year`.

### Level

Level and modality are different.

The initial level domain is:

```text
primary
secondary
```

Use the project's established enum/string pattern. Do not create an `academic_levels` table in v1.

### Modality

Confirmed examples include:

```text
Verano
Intensivo
Reforzamiento
Virtual
```

Inspect real local data before fixing the final allowed values.

Do not create a modality table unless administrators genuinely need independent modality management.

### Grade

Do not create a global `academic_degrees` CRUD or reference catalog in v1.

The meaningful entity is a grade offered in one specific cycle:

```text
cycle_degrees
```

Minimum direction:

```text
code UUID PK
cycle_code FK
number SMALLINT
timestamps
UNIQUE (cycle_code, number)
CHECK (number BETWEEN 1 AND 6)
```

The cycle level determines the valid range.

Use one domain helper/enum for display labels and validation.

Do not add grade name, abbreviation, active state, or order unless local evidence proves a real need.

### Group / Section

A group belongs to one cycle degree.

Minimum direction:

```text
code UUID PK
cycle_degree_code FK
name
sort_order
is_active
timestamps
```

Rules:

- configurable name;
- not limited to A–D;
- not limited to one character;
- case-insensitively unique within the cycle degree;
- future modules reference `academic_group_code`.

### Shift

A shift belongs to one cycle.

Minimum direction:

```text
code UUID PK
cycle_code FK
name
entry_time
tolerance_minutes
sort_order
is_active
timestamps
```

Rules:

- one or two active shifts per cycle;
- tolerance is non-negative;
- future enrollment may select one or both shifts through an explicit relation;
- no `turn_1`, `turn_2`, arrays, JSON, or `both` enum.

## 5. Required scope

Implement:

1. cycle list scoped to the current authorized branch;
2. cycle creation;
3. cycle update;
4. activate/deactivate cycle;
5. one or two shifts;
6. offered cycle grades;
7. groups under each cycle grade;
8. semantic backend authorization;
9. focused database and behavior tests;
10. Inertia + Svelte + Lumi UI;
11. navigation entry;
12. update `docs/SPEC.md` and `docs/STATUS.md` after completion.

## 6. Explicitly out of scope

Do not implement:

- global degree CRUD;
- academic level CRUD;
- students;
- contacts;
- enrollment;
- attendance records;
- payment plans;
- cashbox;
- evaluations;
- OMR;
- courses;
- teacher assignments;
- capacity;
- tutors;
- classrooms;
- holidays;
- transfer history;
- cycle duplication;
- import/export;
- dashboard metrics;
- future empty tabs.

Do not add fields for future modules merely to make the schema look complete.

## 7. Authorization and branch isolation

Use one permission pair unless current conventions prove a better existing name:

```text
cycles.view
cycles.manage
```

`cycles.manage` requires `cycles.view` through the existing permission dependency mechanism.

This pair covers:

- cycles;
- cycle grades;
- groups;
- shifts.

Do not create one permission per nested entity.

All cycle reads and writes are scoped to `BranchContext`.

Do not trust a branch code provided by the browser.

A user must not read or modify a cycle outside the current authorized branch.

## 8. Aggregate ownership

A cycle is the aggregate owner of:

- cycle grades;
- cycle shifts;
- groups managed inside those cycle grades.

Use a focused transactional Action when creating or updating the aggregate.

A partial failure must not leave:

- a cycle without its expected structure;
- duplicate grade offerings;
- half-synchronized shifts;
- orphaned group changes.

Do not hide synchronization in observers or triggers.

Do not create a generic `AcademicService`.

## 9. UI direction

### Cycle index

Show current-branch cycles with only useful summary data:

- name;
- level;
- modality;
- date range;
- active state;
- grade count;
- group count.

Do not load full nested groups and shifts on the index.

Historical cycles remain directly visible or simply filterable; do not force a global year switch.

### Create/edit

Prefer one calm form with two semantic sections:

```text
General
Academic structure
```

General:

- name;
- level;
- modality;
- dates;
- active state;
- one or two shifts.

Academic structure:

- offered grade numbers;
- groups under each offered grade.

Avoid a wizard unless local evidence proves it improves the actual workflow.

### Lumi

Reuse existing Aeduca and Lumi patterns.

Do not add local CSS, raw colors, another notification owner, or a frontend state library.

## 10. Query and performance rules

- Index query: cycle fields plus aggregate counts.
- Edit/detail query: one cycle with its grades, groups, and shifts.
- Avoid per-row queries.
- Do not eager-load all nested structure on the list page.
- Do not add persistent cache.
- Keep synchronization bounded to one cycle.

Review Svelte effects for accidental request or state loops.

## 11. Database invariants

Protect at least:

- explicit UUID FKs;
- `end_date >= start_date`;
- nonblank cycle and group names;
- non-negative shift tolerance;
- unique grade number within a cycle;
- unique normalized group name within a cycle degree;
- one or two active shifts per cycle through the aggregate write;
- inactive or unauthorized branch cannot receive a cycle through the application workflow.

Do not use arrays or JSON for grades, groups, or shifts.

## 12. Focused tests

Add only tests that protect the important risks:

1. unauthorized user cannot view or manage cycles;
2. authorized user sees cycles only for the current branch;
3. browser cannot inject another branch;
4. a cycle may cross calendar years;
5. invalid date order is rejected;
6. invalid grade for the selected level is rejected;
7. duplicate grade in the same cycle is rejected;
8. duplicate group in the same cycle degree is rejected;
9. the same group name may exist in another cycle degree;
10. cycle has only one or two active shifts;
11. aggregate write rolls back on failure;
12. cycle index has no obvious N+1 behavior;
13. `cycles.manage` includes `cycles.view`.

Preserve existing access and administration tests.

Do not generate tests that only restate Laravel or Eloquent.

## 13. Verification

Run:

```bash
composer run format
composer run check
pnpm run build
php artisan migrate:fresh --seed --env=testing
```

Review the final diff for:

```text
current_year
year embedded in code
substring-based identity
academic_degrees global CRUD
group_code free text outside academic_groups
turn_1
turn_2
both
JSON/array relationships
branch_code trusted from browser
role-name authorization
generic service/repository layers
unnecessary eager loading
local style blocks
```

Do not claim completion if a required check fails.

## 14. Documentation closure

After implementation:

1. Merge only permanent confirmed decisions into `docs/SPEC.md`.
2. Replace the academic section of `docs/STATUS.md` with the actual implementation.
3. Remove or replace this `TASK.md`.
4. Do not create another permanent academic roadmap.

## 15. Final report

Report concisely:

1. evidence found in Aeduca Admin;
2. evidence found in Coedula;
3. final implemented model;
4. deliberately omitted fields and features;
5. files and constraints added;
6. query/eager-loading strategy;
7. tests and commands executed;
8. remaining uncertainty for enrollment.

The vertical is complete only when future enrollment can reference one unambiguous academic group and one or both valid cycle shifts without reconstructing meaning from concatenated codes, global years, or repeated strings.
