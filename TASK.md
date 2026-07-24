# TASK — Registro, acceso y matrícula de alumnos

Implementar el primer recorrido estudiantil completo de Aeduca v8:

```text
buscar → crear → perfilar → habilitar acceso → matricular → listar por sección → autoservicio
```

Las ideas de UI de este task orientan el resultado, pero no obligan a copiar una pantalla. `docs/SPEC.md` conserva la verdad permanente; este archivo define aceptación observable y límites temporales.

## 1. Objetivo y reglas de oro

El objetivo es mejorar Aeduca v7 sin perder sus funciones probadas, usando la arquitectura limpia y eficiente de v8 e incorporando de Coedula sólo las decisiones que resuelven mejor el mismo recorrido.

1. Preservar comportamiento útil de v7; no copiar sus códigos concatenados, año global, SQL inseguro, repositorios gigantes ni contraseñas reversibles.
2. Extender los propietarios actuales de v8 —`AuthAccount`, permisos, `BranchContext`, Actions, FormRequests, Laravel/Inertia, Svelte y Lumi— antes de crear otra capa.
3. Entregar recorridos utilizables, no CRUD aislado. Ningún botón, pestaña, ruta o permiso puede quedar vacío.
4. La identidad del alumno es institucional; la matrícula pertenece a una sede mediante su grupo académico.
5. Alumno, cuenta y matrícula tienen estados diferentes. Ningún estado se infiere o modifica silenciosamente desde otro.
6. PostgreSQL protege estructura; Laravel protege autorización, reglas y transacciones; Svelte presenta e interactúa.
7. Una contraseña temporal se muestra una sola vez. Sólo su hash se persiste.
8. Listados paginados, consultas acotadas, columnas explícitas e índices reales. Evitar N+1, filtros de colecciones completas y caché prematuro.
9. DRY/KISS: un propietario por responsabilidad, sin service god, repositorio genérico, DTO ceremonial, segunda UI o abstracción futura.
10. “Copiar v7” significa conservar el resultado que el usuario reconoce y reimplementarlo coherentemente en v8.

El task no se mide por cantidad de archivos o líneas. Se completa cuando el recorrido funciona de extremo a extremo.

## 2. Evidencia obligatoria

Antes de programar, inspeccionar el código/pruebas actuales y los contratos Lumi relevantes. Contrastar además:

| Fuente    | Conservar                                                                 | Descartar                                                                   |
| --------- | ------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Aeduca v7 | Finder global, perfil con foto, acceso, carnet e inscritos por sección    | DNI como PK, año/códigos con significado, plaintext password y acoplamiento |
| Coedula   | Directorio/roster separados, vistas SQL, cuenta explícita y clave efímera | Grupo/turno rígidos, `student_number` innecesario y repositorios gigantes   |
| Aeduca v8 | Auth, permisos, sede, ciclo/grado/sección/turno y patrones Laravel/Lumi   | Ninguna segunda arquitectura para alumnos                                   |

Revisar como mínimo:

- v7: `Finder.vue`, `Student/Main.vue`, `Student/History.vue`, `Student/Family.vue`, `MainWrapper.vue`, `Aeduca.vue`, `Section/Student.vue` y carnet PDF;
- Coedula: `/students/search`, `/students`, perfil, cuenta estudiantil, reset temporal, matrícula y carnet;
- v8: `AuthAccount`, login/middleware, props compartidos, navegación, permisos, `BranchContext`, estructura académica y pruebas.

La primera respuesta antes de modificar código debe ser un plan conciso con: evidencia conservada/descartada, esquema, propietarios de escritura, vistas y funciones SQL, rutas, permisos, consultas, índices, foto, flujo de acceso, matrícula, carnet y dudas materiales. Si una duda cambia identidad, seguridad, matrícula o dependencia, detenerse antes de programar.

## 3. Resultado observable

Al finalizar:

1. **Alumnos** abre un directorio institucional paginado y permite buscar por DNI, nombre o `roll_code`, sin limitar identidad por año o sede.
2. **Nuevo alumno** abre un formulario preciso con foto opcional y redirige al perfil creado.
3. El perfil muestra identidad, foto, estado, acceso, contactos y matrículas sin cargar información ajena.
4. Personal autorizado puede editar/desactivar al alumno, administrar contactos y habilitar/deshabilitar/restablecer acceso.
5. Habilitar o restablecer muestra login y clave temporal una sola vez; la base contiene sólo el hash.
6. Personal autorizado puede crear, editar, activar y desactivar una matrícula con grupo y turnos válidos.
7. `/students` lista matriculados de la sede actual y filtra por ciclo, grado, sección, turno, estado y texto.
8. Ambos listados conducen al mismo perfil institucional.
9. Un alumno habilitado usa el mismo login y sólo consulta su propio perfil básico y matrícula visible.
10. El carnet individual sólo aparece cuando existe una salida imprimible real y autorizada.

## 4. Modelo mínimo y propietarios

### Identidad

`students` usa UUID `code`, DNI obligatorio/único de ocho dígitos, nombres, fecha de nacimiento, teléfono, dirección, observación, `photo_path`, estado y timestamps.

- Sin `branch_code`, `student_number`, año, nivel ni datos de matrícula repetidos.
- DNI es atributo, nunca PK.
- La operación inicial es activar/desactivar; no borrar historia físicamente.
- Foto en Laravel Storage mediante un único propietario, con validación y reemplazo seguro.

`student_contacts` contiene sólo UUID `code`, `student_code`, nombre, teléfono nullable, nota nullable y timestamps. No crear todavía personas/apoderados, parentescos, DNI, cuentas o campos familiares adicionales.

### Credencial

Extender `auth_accounts`:

```text
user_code FK nullable
student_code FK nullable
CHECK(exactamente un propietario)
UNIQUE(user_code) donde exista
UNIQUE(student_code) donde exista
```

No crear otro sistema de autenticación ni un `account_type` redundante si el propietario explícito basta.

### Matrícula

```text
enrollments
  code UUID PK
  student_code FK
  academic_group_code FK
  roll_code
  is_active, observation, timestamps

enrollment_shifts
  enrollment_code FK
  cycle_shift_code FK
  PRIMARY KEY(enrollment_code, cycle_shift_code)
```

Invariantes:

- una matrícula activa por alumno en toda la institución;
- `roll_code` activo único y sin sede/año/grado/grupo codificados;
- el grupo determina grado, ciclo y sede por FK;
- uno o dos turnos del mismo ciclo que el grupo;
- activar una nueva matrícula resuelve explícita y transaccionalmente la anterior;
- desactivar conserva historia;
- este task no crea Pagos.

Confirmar el formato de `roll_code` con v7 y su uso OMR antes del esquema final. Una vez confirmado, PostgreSQL lo genera/reserva mediante una función enfocada y segura ante concurrencia; Laravel no calcula el siguiente código ni codifica sede/año/grado para avanzar.

## 5. Entradas y experiencia

### Directorio institucional — `/students/search`

- Es la entrada de navegación **Alumnos**.
- Sin consulta muestra recientes; con consulta busca DNI, nombre y `roll_code` activo.
- Ordena DNI exacto, luego `roll_code` exacto y después nombre aproximado.
- Muestra foto, nombre, DNI, estado y resumen de matrícula actual o más reciente.
- Incluye **Nuevo alumno** y un acceso a **Ver matrículas** según permisos.
- `q` y `page` viven en la URL; paginación y búsqueda son de servidor.
- No depende de sede/año para localizar identidad.

El directorio se apoya en una vista PostgreSQL estable que compone identidad y resumen académico actual/reciente una sola vez. Laravel autoriza, ordena, filtra y pagina esa vista. El resumen sirve para distinguir resultados; no expone pagos, asistencia ni historia completa.

### Alta y edición

Un formulario único, no wizard, contiene sólo DNI, nombres, apellidos, nacimiento, teléfono, dirección, observación, foto y estado en edición. Contactos, cuenta y matrícula se administran desde el perfil para mantenerlo claro.

### Perfil — `/students/{student}`

Composición recomendada, ajustable al patrón Lumi más limpio:

```text
foto + identidad + estado
acciones compactas bajo/junto a foto
resumen de matrícula y acceso

Resumen | Contactos | Matrículas
```

- Foto/identidad son el ancla visual.
- Contactos muestra nombre, teléfono y nota; formularios breves pueden usar Dialog.
- Matrículas muestra resúmenes e historia acotada; detalles secundarios pueden usar Dialog o vista enfocada.
- La edición compleja de matrícula no convierte el perfil en un formulario gigante.
- No crear pestañas vacías de Pagos, Drive, asistencia o evaluaciones.
- La carga inicial obtiene identidad, cuenta y resumen actual; relaciones crecientes se acotan/paginan.
- Sólo componentes, clases y tokens públicos de Lumi; sin sistema CSS local.

### Listado académico — `/students`

Usa la sede actual validada por `BranchContext`. Una vista PostgreSQL de matrícula compone alumno, grupo, grado, ciclo, sede y turnos; Laravel le aplica alcance y filtros de ciclo, grado, sección, turno, estado, texto y página. Los filtros viven en la URL y nunca se reconstruyen desde códigos.

Cada fila necesita sólo foto, alumno/DNI, `roll_code`, ciclo/grado/sección, turnos, estado y acciones de perfil/matrícula. La UI puede usar PageSidebar/drawer o Dialog de filtros: importa la eficacia y adaptación móvil, no copiar literalmente v7 o Coedula.

### Carnet

El botón compacto se ubica cerca o debajo de la foto sólo con DNI y matrícula activa. La salida debe contener foto, nombre, DNI, `roll_code`, contexto académico y QR con DNI, con autorización de servidor y respuesta privada.

V8 no tiene aún una solución PDF/QR aprobada. El plan inicial debe comparar una vista imprimible y una dependencia enfocada usando v7/Coedula como evidencia. Una dependencia runtime nueva requiere aprobación explícita. Sin decisión no se crea botón, ruta o servicio ficticio.

## 6. Acceso y autoservicio

El login continúa siendo único para empleados y alumnos.

Flujo administrativo:

1. **Habilitar acceso** crea/reactiva la cuenta con DNI como login.
2. El servidor genera una clave temporal criptográficamente segura.
3. `AuthAccount` persiste únicamente su hash.
4. Un Dialog muestra login/clave una sola vez y permite copiarlos.
5. Cerrar/recargar elimina el texto; **Restablecer acceso** genera otro.
6. **Deshabilitar acceso** cambia la cuenta, no identidad ni matrícula.

Nunca guardar contraseña original/reversible ni colocarla en logs, sesión, URL, auditoría, flash persistente o props compartidos. No usar DNI como contraseña ni exigir matrícula activa para iniciar sesión.

La autenticación/middleware deben ser actor-aware:

- empleado: conserva usuario, rol, ramas y permisos actuales;
- alumno: requiere cuenta y estudiante activos, sin ramas/permisos administrativos;
- alumno autenticado: sólo `student_code` propio y redirección a su perfil;
- ambos: mismo throttling, error no enumerable, rehash, regeneración de sesión y logout.

Reusar shell, login y notificaciones; adaptar el contrato Inertia mínimo sin duplicarlos.

## 7. Autorización y alcance

Agregar únicamente:

```text
students.view / students.manage
enrollments.view / enrollments.manage
```

Cada `manage` incluye `view`. Foto, contacto, carnet, acceso, pestañas y botones no crean permisos separados.

- Identidad/directorio: institucional para personal con `students.view`.
- Identidad, foto, contactos, estado y cuenta: `students.manage`.
- Matrícula/listado: sede actual autorizada.
- Historia del perfil: sólo detalle académico de sedes autorizadas al empleado.
- Autoservicio: propiedad del `student_code`, no permiso administrativo.

El backend autoriza siempre; `can()` sólo controla presentación.

## 8. Rendimiento y simplicidad

- Vistas PostgreSQL nombradas para cada read model compuesto y estable: directorio, roster y los resúmenes de perfil que se reutilicen.
- Funciones SQL enfocadas para generación/reserva atómica o cálculos realmente database-owned; `roll_code` es obligatorio.
- Laravel conserva autorización, alcance, filtros, orden, paginación y orquestación transaccional sobre esos contratos.
- CRUD validado de una fila permanece en Eloquent; no convertir cada insert/update en función SQL.
- Columnas explícitas, paginación de servidor y resúmenes acotados.
- Índices en DNI, estado, FK, matrícula activa, `roll_code` activo y estrategia de nombre.
- Consultas parametrizadas; `pg_trgm` sólo con migración/índice comprobables.
- Sin filtros académicos en memoria, props con colecciones completas o N+1.
- Sin eager loading de historia o dominios no mostrados.
- Sin caché persistente sin medición.
- Toda vista/función tiene migración, fuentes indexadas y pruebas directas de su contrato y concurrencia relevante.
- Abstracción compartida sólo después de usos reales.

Probar con suficientes registros para revelar crecimiento, no únicamente con una fila.

## 9. Orden de implementación

Trabajar en cortes verticales y verificar cada uno antes de ampliar:

1. identidad, foto, contactos, directorio, formulario y perfil;
2. cuenta, autenticación actor-aware y autoservicio;
3. matrícula, historia y listado académico filtrado;
4. carnet después de resolver su contrato/dependencia.

No crear scaffolding vacío para cortes posteriores. El task completo no se declara terminado hasta integrar todos los cortes aceptados.

## 10. Pruebas que protegen riesgos reales

Cubrir como mínimo:

- DNI normalizado/único y constraints de propietario de cuenta;
- autorización institucional vs sede y propiedad del alumno;
- alta, edición, estado, foto segura y contactos mínimos;
- búsqueda sin sede/año por DNI, nombre y `roll_code`, orden exacto y paginación;
- ausencia de N+1/historias completas en listados y perfil;
- clave temporal sólo comprobable contra hash, nunca persistida;
- login de alumno sin matrícula activa y sin ramas/permisos administrativos;
- regresión completa del login/branch de empleados;
- una matrícula activa, `roll_code` activo único, grupo/turnos del mismo ciclo;
- rechazo de escritura en otra sede, rollback y conservación de historia;
- filtros de roster y persistencia en URL;
- carnet autorizado, privado, con DNI en QR, si su implementación fue aprobada.

No probar comportamiento trivial del framework. Preservar toda la suite actual.

## 11. Fuera de alcance y condiciones de parada

Fuera de este task:

- Pagos/caja/obligaciones;
- asistencia, evaluaciones, OMR e incidencias;
- Drive/adjuntos salvo foto;
- apoderados como personas/cuentas y parentescos estructurados;
- búsqueda de docentes/personal;
- migración legacy, eliminación física, transferencias formales;
- recovery/remember-me/MFA;
- procesos masivos, Excel, dashboards o pestañas futuras;
- editor/impresión masiva de carnets.

Detener antes de inventar si no se confirma `roll_code`, aparece una segunda propiedad de storage, el carnet requiere dependencia no aprobada, la visibilidad contradice evidencia real, grupo/ciclo/turno no puede protegerse claramente, se duplicaría auth/shell o falta un contrato Lumi público.

Una ambigüedad menor y reversible de layout no bloquea: usar el patrón Lumi existente más simple.

## 12. Verificación y cierre

Después de esquema/seed:

```bash
php artisan migrate:fresh --seed --env=testing
```

Antes de completar:

```bash
composer run format
composer run check
pnpm run build
```

También verificar funcionalmente directorio, alta, perfil, acceso, matrícula, filtros, móvil y carnet aprobado; revisar queries, payloads, requests repetidos y ausencia de contraseña temporal persistida.

No migrar `aeduca` para validar una dirección incompleta ni tocar cambios ajenos. Al cerrar, consolidar decisiones en `SPEC`, hechos en `STATUS` y eliminar/reemplazar este task.

La vertical no está completa si sólo existen CRUD, migraciones o tests: debe funcionar el recorrido buscar → crear → perfilar → habilitar → matricular → listar → consultar como alumno.
