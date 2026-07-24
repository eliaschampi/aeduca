> Registro de investigación conservado por solicitud del propietario. No es una especificación activa; las decisiones confirmadas fueron consolidadas en `docs/SPEC.md`.

## Dictamen

Sí: el problema actual no es la disciplina arquitectónica, sino que los MD describen “Estudiantes” como una entidad mínima y no como un recorrido operativo completo. Un agente obediente termina construyendo CRUD, aunque técnicamente esté bien hecho.

La abstracción correcta para Aeduca v8 debería tener cuatro capacidades conectadas:

```text
Buscador global
      ↓
Perfil del estudiante
      ↓
Matrícula actual + historial
      ↓
Acceso, asistencia, evaluaciones, finanzas y archivos
```

### Qué rescatar de Coedula

| Área       | Rescatar                                                                                 | No copiar                                                                           |
| ---------- | ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| Estudiante | Identidad global, DNI, foto, historial y cuenta separada                                 | `photo_url` sin un propietario claro de archivos                                    |
| Matrícula  | FK explícitas, transacción, una matrícula activa y pagos iniciales en la misma operación | `group_code` A–D, `turn_1/turn_2/both` y un repositorio educativo sobredimensionado |
| Listado    | Contexto por sede/ciclo/grado/grupo y búsqueda dentro del grupo                          | Métodos y endpoints de búsqueda duplicados                                          |
| Búsqueda   | Directorio independiente, recientes, paginación y matrícula vigente resumida             | `ILIKE %texto%` sin índice apropiado                                                |
| Perfil     | Hub compuesto por consultas acotadas                                                     | Un repositorio educativo gigantesco o cargar toda la historia                       |
| Acceso     | Una entrada de autenticación y propietario explícito de la cuenta                        | Bloquear automáticamente el login por no tener matrícula activa                     |

La base de Coedula demuestra una separación valiosa entre estudiante, credencial y matrícula en [01-tables.sql](/Users/shaun/Documents/coedula/database/init/01-tables.sql:258), además de restricciones para una sola matrícula activa en [02-indexes.sql](/Users/shaun/Documents/coedula/database/init/02-indexes.sql:65).

Su escritura de matrícula también protege ciclo, sede, turnos y pagos iniciales dentro de una transacción en [education.repository.ts](/Users/shaun/Documents/coedula/src/lib/server/repositories/education.repository.ts:1595). Pero tiene deuda que Aeduca ya decidió corregir: grupo como texto fijo, turnos como enum y demasiadas responsabilidades dentro de un único repositorio.

### Listado y búsqueda

Coedula realmente posee dos herramientas diferentes:

1. `/students` es un listado operativo de matrículas, limitado por la sede activa y filtrado por ciclo, grado, grupo y texto. Está en [+page.server.ts](</Users/shaun/Documents/coedula/src/routes/(dashboard)/students/+page.server.ts:7>).

2. `/students/search` es un directorio global: búsqueda paginada, alumnos recientes y resumen de su última matrícula. Su forma de lectura está centralizada en [student_registro_lookup](/Users/shaun/Documents/coedula/database/init/05-views.sql:243).

Esa separación es correcta y Aeduca debería conservarla:

- Listado de matrículas: operativo, por sede y estructura académica.
- Buscador global: institucional, independiente de la sede seleccionada.
- Perfil: destino común de ambos.

Aeduca v7 aporta algo que Coedula no resuelve tan bien: un buscador disponible globalmente para estudiantes, docentes y apoderados, con filtro de sede y “solo activos” en [Finder.vue](/Users/shaun/Documents/v7-aeduca/aeduca-main/resources/js/Components/Views/Finder.vue:24). También utiliza similitud de PostgreSQL para ordenar nombres aproximados en [StudentRepository.php](/Users/shaun/Documents/v7-aeduca/aeduca-main/app/Repositories/StudentRepository.php:23).

Debemos rescatar el concepto, no la implementación: v7 interpola texto directamente en SQL, lo cual no debe repetirse.

El buscador objetivo debería:

- Buscar estudiantes por DNI, nombre y `roll_code`.
- Buscar docentes/personal por DNI y nombre.
- Priorizar coincidencias exactas y luego similitud.
- Permitir sede y estado activo como filtros, no como limitaciones estructurales.
- Mostrar resultados recientes cuando todavía no hay consulta.
- Usar paginación y `pg_trgm` con consultas parametrizadas.
- No exigir una tabla genérica `people` ni polimorfismo solamente para compartir la búsqueda.

### Perfil objetivo del estudiante

El perfil no debe ser un formulario CRUD ampliado. Debe ser el hub institucional del alumno:

- Identidad, foto, contacto y observaciones.
- Matrícula vigente y matrícula histórica.
- Estado de acceso y restablecimiento de credenciales.
- Resumen financiero.
- Archivos directos y archivos compartidos con su grupo.
- Accesos especializados a asistencia y evaluaciones.

Coedula ya compone su perfil mediante consultas separadas para matrícula, archivos y pagos en [+page.server.ts](</Users/shaun/Documents/coedula/src/routes/(dashboard)/students/[studentCode]/+page.server.ts:13>). Esa composición es correcta: el perfil muestra resúmenes y deriva hacia vistas especializadas, sin convertirse en una consulta monstruosa.

### Modelo conceptual recomendado

```text
AuthAccount ── exactamente un propietario ── User
                                           └─ Student

Student ──< Enrollment ──> AcademicGroup ──> CycleDegree ──> Cycle ──> Branch
                │
                ├──< EnrollmentShift
                └──< Payment (semántica pendiente/pagado/caja por cerrar)

Student ──< Contact
Student ── foto de perfil
Student ──< archivos directos
AcademicGroup ──< archivos compartidos
```

Docentes y administrativos deben compartir el mismo sistema de autenticación como usuarios internos; no necesitan otro login aislado. Eso no obliga a mezclar la asistencia laboral con la asistencia académica: pueden compartir plataforma y reportes, pero conservar modelos de dominio diferentes.

También recomiendo separar:

- Cuenta habilitada: controla si la persona puede autenticarse.
- Matrícula activa: controla operaciones académicas actuales.
- Autorización del portal: controla qué información puede consultar.

Coedula exige matrícula activa para iniciar sesión en [session.ts](/Users/shaun/Documents/coedula/src/lib/auth/session.ts:134). No lo copiaría automáticamente: un egresado o alumno temporalmente inactivo podría necesitar consultar historial, notas o deudas.

## Qué cambiaría posteriormente en los MD

Mantendría las reglas de FK explícitas, transacciones, propietario único, DRY/KISS, ausencia de UI vacía y prohibición de repositorios genéricos.

Modificaría el enfoque de alcance:

1. Reemplazar “vertical mínima” por “recorrido operativo mínimo completo”.

2. Redefinir “Estudiantes y contactos mínimos” como:

    > Registro estudiantil: identidad, foto, contactos, búsqueda, perfil, credencial y conexión con matrícula.

3. Establecer que una capacidad adyacente necesaria para completar el recorrido —foto, búsqueda, acceso o archivos— no es _scope creep_.

4. Exigir antes de cada `TASK.md` una matriz corta:

    - comportamiento existente;
    - comportamiento que se conserva;
    - deuda que se descarta;
    - aceptación operativa esperada.

5. Aclarar que un `TASK.md` no puede reducir silenciosamente un flujo confirmado. Si durante la investigación aparece una carencia, se corrige el task antes de desarrollar.

6. Cambiar “Lean student portal” por “Portal estudiantil operativo”, manteniendo fuera chat, LMS y funcionalidades sociales.

El conflicto actual está entre el objetivo de preservar flujos reales de [SPEC.md](/Users/shaun/Documents/aeduca/docs/SPEC.md:7) y una descripción futura demasiado pequeña en [SPEC.md](/Users/shaun/Documents/aeduca/docs/SPEC.md:304), amplificada por el alcance estricto de [AGENTS.md](/Users/shaun/Documents/aeduca/AGENTS.md:90). No hay que eliminar las reglas de oro; hay que darles una definición de producto suficientemente completa.

Quedan cuatro decisiones por confirmar antes del esquema: acceso de alumnos sin matrícula activa, necesidad real de `student_number` además de DNI, alcance/unicidad de `roll_code`, y propietario definitivo del almacenamiento de fotos y archivos.

No modifiqué archivos ni ejecuté base de datos, pruebas o builds durante esta revisión.

---

## Auditoría de `origin/codex/wrong1`

### Dictamen

La rama no fracasó principalmente por la interfaz ni porque Laravel obligue a crear demasiados archivos. Fracasó porque el alcance técnico no representó el resultado operativo esperado.

El commit agrega 54 archivos, 4,557 líneas y mezcla en una sola entrega:

- estudiantes y contactos;
- matrícula;
- un modelo financiero todavía no confirmado;
- cambios de integridad en ciclos;
- una generalización del grafo de permisos;
- documentación y pruebas.

Una parte importante del volumen es normal para una vertical Laravel/Inertia: migraciones, modelos, validación, rutas, pantallas y pruebas. El problema es la prioridad de esas líneas. Solamente el formulario de matrícula y el panel CRUD de contactos suman 705 líneas de Svelte, mientras quedaron fuera capacidades centrales del producto.

### Defectos concretos

1. **No existe acceso del estudiante.** `AuthAccount` continúa requiriendo exclusivamente `user_code`; la autenticación y el middleware siguen siendo de empleados. La rama no agrega contraseña, creación/restablecimiento de cuenta ni sesión del alumno.

2. **No existe foto del estudiante.** No hay campo, archivo, carga, servicio ni representación real de foto; únicamente avatares genéricos de Lumi.

3. **No existe estado activo/inactivo del estudiante.** La tabla `students` no tiene estado y el perfil no ofrece una acción para desactivar al alumno. El `is_active` implementado pertenece a la matrícula, no al estudiante ni a su cuenta.

4. **No existe el listado académico operativo.** `/students` es sólo un directorio institucional por DNI o nombre. No filtra por sede, ciclo, grado, sección, turno ni estado de matrícula. Tampoco existe un índice de matrículas; las matrículas sólo se encuentran entrando al perfil de cada alumno.

5. **No existe búsqueda global integrada.** No se recuperó el buscador global de v7 ni el hub `/students/search` de Coedula. La consulta del directorio usa `ILIKE '%texto%'` sin índice trigram y no busca por `roll_code`.

6. **Se implementó el dominio financiero equivocado.** La rama crea `payment_obligations`, obliga a registrar al menos una al matricular y permite reemplazarlas/eliminarlas al editar. No existen pagos, cobros, estados pendiente/pagado, caja ni reversos. Esto consumió código para una semántica que el dominio operativo llama simplemente **Pagos**.

7. **Hay aislamiento de sede incompleto al leer.** La escritura de matrícula valida sedes autorizadas, pero el perfil carga todo el historial de matrículas del alumno sin filtrar por las sedes del usuario. Los códigos autorizados sólo deciden si aparece la edición. Un usuario con `enrollments.view` puede recibir historia académica de otras sedes, salvo que se confirme expresamente que ese permiso es institucional.

8. **La rama conserva la dimensión redundante `academic_cycles.level`.** Matrícula vuelve a depender de ella para etiquetas de grado. Esto propaga la decisión incorrecta hacia controlador, UI y pruebas.

9. **El cierre documental fue prematuro.** `STATUS.md` declara Estudiantes y Matrícula como verticales terminadas y propone Asistencia como siguiente paso, aunque el recorrido solicitado de estudiante todavía no funciona de extremo a extremo.

### Complejidad necesaria y complejidad evitable

Era razonable crear:

- `Student`, `Enrollment` y sus relaciones reales;
- validaciones de entrada;
- una Action transaccional para matrícula;
- índices parciales para una matrícula activa;
- pantallas y pruebas enfocadas.

Fue evitable o prematuro:

- `SaveStudent` para un simple create/update sin agregado transaccional;
- una segunda migración sólo para retirar el límite de dos contactos antes de integrar la primera;
- generalizar el grafo completo de dependencias de permisos por `enrollments.view → students.view`;
- construir “obligaciones” y su editor antes de confirmar Pagos/Caja;
- modificar y sembrar la base `aeduca` para una dirección aún no aceptada;
- priorizar un CRUD completo de contactos antes de foto, acceso, estado, filtros y búsqueda.

La arquitectura base no exige 54 archivos para cada función. El número aumentó porque el task reunió demasiados dominios y porque cada incertidumbre se resolvió agregando código en lugar de detener y corregir el alcance.

### Permisos

No corresponde crear un permiso por cada botón o pequeña funcionalidad. Los permisos deben representar capacidades de negocio estables. Como punto de partida:

- `students.view` / `students.manage`;
- `enrollments.view` / `enrollments.manage`;
- `payments.view` / `payments.manage`;
- permisos posteriores para asistencia, evaluaciones y caja cuando exista cada recorrido.

Subir foto, editar datos o desactivar un estudiante pueden pertenecer a `students.manage`. Restablecer acceso sólo merece un permiso separado si Carrión confirma que lo realiza un cargo diferente. El alumno que consulta su propia información no debe recibir `students.view`; accede por identidad propia y políticas de autoservicio.

### Bases de datos y pruebas

Usar `aeduca_test` con `RefreshDatabase` y ejecutar la migración completa es correcto. Lo innecesario fue repetir migraciones y seed sobre la base operativa local `aeduca` antes de aprobar el modelo y la experiencia.

La regla recomendada es:

1. durante el desarrollo, migraciones y pruebas solamente en `aeduca_test`;
2. revisión funcional temprana con datos de prueba reproducibles;
3. migrar `aeduca` únicamente cuando la vertical haya sido aceptada o cuando el usuario solicite expresamente una prueba integrada;
4. nunca usar la base original para conservar iteraciones fallidas de una migración aún no integrada.

Según el propio informe de la rama, `aeduca` recibió las migraciones descartadas. Eso deja posible deriva entre el código de `main` y la base local; debe revisarse por separado antes de continuar, sin borrar nada automáticamente.

### Dirección recomendada para Aeduca v8

> Aeduca v8 mejora Aeduca v7 conservando sus recorridos operativos probados, corrige su deuda estructural e incorpora las mejores ideas de Coedula dentro de una sola plataforma para administrativos, docentes y alumnos.

El recorrido mínimo completo de Estudiantes debe incluir:

- listado académico por sede, ciclo, grado y sección;
- búsqueda dentro del listado y búsqueda global de personas;
- alta y edición del estudiante con foto y estado activo;
- perfil como hub de matrícula, pagos, contactos, archivos y acceso;
- cuenta del alumno con DNI y contraseña dentro del mismo sistema de autenticación;
- creación/edición/desactivación de matrícula;
- establecimiento de pagos pendientes usando el vocabulario **Pagos**;
- permisos semánticos para personal y autorización por identidad para el alumno.

La estructura de navegación puede conservar la separación útil de Coedula:

```text
/students                 listado académico con filtros
/students/search          directorio global y recientes
/students/{student}       perfil institucional
/students/{student}/...   matrícula, acceso, pagos y archivos
```

### Cambio documental posterior

La reescritura debería mantener una sola autoridad por tipo de información:

- `README.md`: propósito y entrada rápida, sin duplicar una especificación detallada;
- `AGENTS.md`: protocolo de ejecución, incluyendo que una vertical se completa por recorrido operativo y no por número de tablas;
- `docs/SPEC.md`: objetivo del producto, actores, vocabulario y criterios funcionales permanentes;
- `docs/STATUS.md`: únicamente hechos comprobados del código actual;
- `TASK.md`: un solo incremento ejecutable con aceptación observable.

Debe eliminarse de la especificación la doctrina de “obligaciones” y cualquier afirmación de que esa separación es una verdad confirmada. Para v8, el término operativo será **Pagos**; el esquema exacto de estados, cobro y caja se cerrará observando v7 y Coedula antes de implementarlo.

También debe redefinirse “vertical mínima” como **el incremento más pequeño que completa un resultado utilizable**. Las reglas arquitectónicas continúan vigentes, pero ninguna puede utilizarse como excusa para omitir foto, acceso, filtros, búsqueda o pagos cuando son parte del recorrido aceptado.
