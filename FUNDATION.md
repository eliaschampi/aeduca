# Aeduca v8 — Fundación de arquitectura y primer caso de uso

## 1. Propósito

Aeduca v8 reemplazará e integrará los procesos necesarios de:

* Aeduca Admin.
* Aeduca Aula.
* NextYa.
* Las mejoras comprobadas de Coedula.

Aeduca Admin es el proyecto antiguo, solido para su tiempo, pero necesita una seria y extremadamente limpia, desde cero restructuracion, pero con algo muy importante capacidad de migrar algunos datos, como estudiantes, ciclos, matriculas, caja, pagos y evaluaciones. 

Actualmente se encuentra en /home/qorilux/Documents/v7 aeduca main

Aeduca Aula es una aula virtual que compartia informacion de aeduca admin, mismo alumno registrado puede acceder con su dni y password. 
Actualmente se encuentra en /home/qorilux/Documents/v7 aeduca aula

Nextya es un sistema de evaluaciones con Optical mark recognition, hecho en svelte moderno, el problema es que a diferencia de aeduca aula, este no comparte la base de datos, lo que hace muy dificil migrarlo aun asi se prevee lograrlo sin parches inconsistentes sino de manera inteligente y perfecto
Actualmente se encuentra en /home/qorilux/Documents/nextya

Coedula es un sistema que concreta en un sistema avanzado todos los aprendizajes a lo largo de muchos años creando, haciendo funcionar, manteniendo de aeduca admin y aula.
Actualmente se encuentra en /home/qorilux/Documents/coedula

El sistema pertenece exclusivamente a la institución Carrión y sus sedes. No es una plataforma SaaS, no manejará empresas independientes, membresías ni aislamiento multiempresa.

El objetivo es construir un sistema v8 o version 8 juntando todas las ideas, sobre todo de aeduca admin, ideas de coedula, mejoras significativas que se mencionan en TASK.md:

* Comprensible por un único desarrollador.
* Consistente para agentes LLM.
* Migrable desde Aeduca y NextYa.
* Sin códigos de negocio usados como claves técnicas.
* Sin abstracciones preventivas.
* Sin reglas escondidas en interfaz, triggers o convenciones implícitas.

---

## 2. Principio rector

Aeduca v8 no copiará automáticamente la estructura de Aeduca ni la de Coedula.

Para cada proceso:

1. Se identifica el comportamiento real requerido por Carrión.
2. Se estudia cómo fue resuelto en Aeduca.
3. Se estudia cómo fue resuelto en Coedula.
4. Se conserva lo que demostró valor.
5. Se elimina la deuda estructural.
6. Se implementa la solución mínima que protege las reglas confirmadas.

No se agrega una entidad, columna, abstracción o automatización sin una necesidad funcional explícita.

---

## 3. Decisiones de dominio confirmadas

### Institución y sedes

* Existe una sola institución: Carrión.
* La institución puede tener varias sedes.
* No se requiere una tabla de empresas o tenants.
* Un trabajador puede acceder a una o más sedes.
* La sede actual es contexto de sesión, no un atributo permanente duplicado en el trabajador.
* La sede seleccionada siempre debe pertenecer a las sedes autorizadas del trabajador.

### Niveles, modalidades, ciclos, grados y grupos

* Primaria y Secundaria son niveles educativos.
* Verano, Intensivo, Reforzamiento y Virtual son modalidades.
* Nivel y modalidad son conceptos diferentes.
* Un ciclo puede comenzar en un año y terminar en otro.
* Está prohibido validar que las fechas del ciclo pertenezcan al mismo año calendario.
* Los grados serán entidades con nombre y orden configurables.
* Los grupos o secciones serán entidades con nombres configurables.
* Está prohibido limitar grupos a A, B, C o D.
* Está prohibido incluir año, nivel, grado, grupo o sede dentro de una clave técnica.

### Matrículas

* Un estudiante solo puede tener una matrícula activa en todo el sistema.
* La base de datos debe proteger esta regla.
* La matrícula puede cambiar de sede o grupo directamente.
* No se construirá historial de traslados en la primera versión.
* Un ciclo puede habilitar uno o dos turnos.
* Una matrícula puede pertenecer a uno o ambos turnos.
* El turno pertenece a la selección de la matrícula, no al grupo.
* `roll_code` es un número humano utilizado para OMR y búsqueda.
* `roll_code` se muestra en el carnet para que el estudiante recuerde su código.
* El QR del carnet contiene únicamente el DNI por compatibilidad con carnets existentes.
* El DNI es obligatorio para estudiantes, pero sigue siendo un atributo único, no una clave primaria.

### Asistencia estudiantil

* La asistencia esperada es de lunes a sábado.
* En la primera versión no se implementará un calendario configurable de feriados o suspensiones.
* Hasta la hora de ingreso, el estado es `presente`.
* Después de la hora de ingreso y hasta terminar la tolerancia, el estado es `tarde`.
* Después de la tolerancia, la lectura automática del carnet no registra una asistencia normal y el estado derivado es `falta`.
* Un trabajador autorizado puede registrar o corregir manualmente una asistencia fuera de la ventana.
* La falta se deriva por ausencia de registro; no se insertarán filas masivas mediante cron.
* La asistencia debe considerar los turnos seleccionados en la matrícula.
* No debe existir un cron encargado de insertar faltas.

### Contacto del estudiante

No se implementará un modelo completo de apoderados.

Cada estudiante podrá tener información mínima de contacto:

* Nombre.
* Celular.
* Nota libre que indique quién es o cualquier contexto necesario.

No se exigirá DNI, dirección, profesión, correo ni una cuenta propia para el contacto.

### Acceso estudiantil

* El estudiante inicia sesión con su DNI.
* Solo se almacena un hash irreversible de la contraseña.
* Está prohibido almacenar una contraseña original o recuperable.
* El portal estudiantil inicial mostrará:

  * Asistencia.
  * Evaluaciones y notas.
  * Pagos.
  * Información básica.
  * Archivos actuales cuando corresponda.

El portal inicial no reconstruirá el componente social completo de Aeduca Aula.

### Finanzas

* Las obligaciones o cuotas se generan al matricular al estudiante.
* Cada obligación tiene concepto, importe y fecha límite.
* Existen pagos parciales.
* Un pago puede aplicarse a una o varias obligaciones.
* Una obligación puede recibir varios pagos.
* Deben distinguirse:

  * Obligación.
  * Pago.
  * Aplicación del pago.
  * Movimiento de caja.
* No existe apertura ni cierre formal de caja.
* Cada cajero posee su propia línea de movimientos.
* El dinero del cajero no cambia de propietario cuando trabaja en otra sede.
* Dos o más cajeros pueden operar simultáneamente.
* El movimiento puede conservar la sede donde ocurrió la operación como contexto, pero el saldo pertenece al cajero.
* El efectivo recibido y el vuelto pueden registrarse.
* El movimiento neto corresponde al importe realmente pagado, no al efectivo entregado por el cliente.
* Las anulaciones se realizan mediante estados o movimientos reversos; no se eliminan operaciones financieras históricas.

### Evaluaciones y OMR

* NextYa es el origen funcional del módulo de evaluaciones OMR.
* `roll_code` se utiliza para reconocer o localizar al estudiante.
* Las imágenes escaneadas no se conservan después de procesarse.
* El resultado puede ser corregido manualmente únicamente en su nota final.
* Se debe registrar al menos quién realizó la corrección y cuándo, sin construir un historial complejo.
* El motor OMR es un componente técnico separado.
* Laravel es propietario de estudiantes, evaluaciones, respuestas y resultados.
* El procesador OMR no administra directamente datos académicos.

### Drive y Aula

* No se migrarán archivos históricos.
* En la primera entrega no se incluyen:

  * Chat.
  * Likes.
  * Comentarios sociales.
  * Publicaciones complejas.
  * Entregas de tareas.
  * Sesiones virtuales completas.
  * Exámenes web tipo formulario.

### Migración y corte

Se migrarán como mínimo:

* Estudiantes.
* Usuarios.
* Sedes.
* Ciclos.
* Grados.
* Grupos.
* Matrículas.
* Pagos y obligaciones.
* Caja y movimientos.
* Atenciones.
* Evaluaciones y resultados que puedan asociarse confiablemente.

No habrá operación funcional paralela para el personal. Antes del corte se realizará una migración de ensayo y una conciliación de datos.

---

## 4. Reglas obligatorias para entidades y base de datos

### Identificadores

* Todas las entidades principales usan UUID en una columna `code`.
* Las foreign keys usan el formato `<entidad>_code`.
* El UUID nunca se muestra como identificador cotidiano salvo necesidad técnica.
* El DNI es un atributo único, no una primary key.
* Ningún código técnico contiene año, sede, nivel, grado, grupo o modalidad.
* Los números humanos se crean únicamente cuando existe un uso confirmado.
* Cada `*_number` o código visible debe tener una justificación escrita.

### Relaciones

* Las relaciones muchos-a-muchos usan tablas intermedias.
* Está prohibido almacenar relaciones mediante arrays PostgreSQL o listas JSON.
* No se utiliza polimorfismo manual con `entity_type` y `entity_code` cuando existen relaciones explícitas posibles.
* Toda foreign key posible debe ser declarada.
* Una columna redundante solo se conserva cuando:

  1. Existe una razón de consulta o auditoría.
  2. La base de datos garantiza que no contradiga la relación original.

### Estados

* Los estados usan nombres completos y comprensibles.
* Está prohibido utilizar letras o abreviaciones ocultas como `A`, `P`, `sts` o `mode` sin semántica explícita.
* Los estados finitos se representan con:

  * Un enum respaldado de PHP.
  * Una columna string.
  * Una restricción `CHECK` equivalente en PostgreSQL.
* No se utilizarán enums nativos de PostgreSQL sin una necesidad comprobada.

### Dinero

* Los importes utilizan `NUMERIC`, nunca `float` o `double`.
* Las operaciones financieras multitabla siempre se ejecutan dentro de una transacción.
* Los importes no se recalculan silenciosamente desde datos ambiguos.
* Ninguna operación financiera se elimina físicamente después de ser confirmada.

### Fechas

* Las fechas de negocio se guardan según su significado:

  * `date` para fecha sin hora.
  * `time` para horario.
  * timestamp con zona horaria para eventos reales.
* Un ciclo puede atravesar años calendario.
* La fecha del servidor no define automáticamente el ciclo actual.

### JSON

JSON se utiliza únicamente para datos sin estructura relacional estable o respuestas externas conservadas como evidencia.

JSON no se utiliza para:

* Foreign keys.
* Permisos.
* Turnos.
* Sedes autorizadas.
* Archivos relacionados.
* Estados principales.
* Participantes.
* Matrículas.
* Relaciones académicas.

### Triggers y funciones

* Las reglas críticas del negocio permanecen visibles en Laravel.
* PostgreSQL protege invariantes mediante foreign keys, unique constraints y checks.
* No se crearán triggers que oculten cobros, pagos, cambios de estado o reglas académicas.
* Una función SQL se admite cuando mejora claramente una consulta o integridad y puede explicarse y probarse.
* No se replica automáticamente el conjunto de funciones y triggers de Coedula.

### Eliminación

* Soft delete no se añade por defecto a todas las tablas.
* Se utiliza solo cuando el negocio necesita papelera, restauración o conservación lógica.
* Los datos financieros confirmados no usan eliminación física.
* Los catálogos no utilizados pueden desactivarse mediante un estado explícito.

---

## 5. Reglas obligatorias de construcción en Laravel

### Model

Un modelo es responsable de:

* Relaciones Eloquent.
* Casts.
* Scopes pequeños y propios de la entidad.
* Configuración de UUID.
* Estado persistido de la entidad.

Un modelo no debe convertirse en un servicio con múltiples procesos de negocio.

### Controller

Un controller:

* Recibe la solicitud.
* Autoriza la operación.
* Entrega datos validados a una Action.
* Devuelve una respuesta Inertia, redirect o JSON.

Un controller no contiene:

* Transacciones extensas.
* Cálculos financieros.
* Resolución manual de permisos.
* Reglas académicas.
* Consultas de cientos de líneas.

### Form Request

Un Form Request valida:

* Forma.
* Tipos.
* Campos obligatorios.
* Límites básicos.
* Formatos.

La validación de formulario no sustituye una regla de dominio ni una restricción de base de datos.

### Action

Una Action representa una escritura o proceso de negocio concreto:

* `CreateUser`
* `EnrollStudent`
* `RegisterAttendance`
* `RecordPayment`
* `VoidPayment`

La Action:

* Tiene una entrada comprensible.
* Autoriza o recibe un actor ya autorizado.
* Maneja la transacción cuando modifica varias tablas.
* Protege invariantes.
* Devuelve una entidad o resultado explícito.

Está prohibido crear un servicio genérico como `StudentService` con decenas de métodos no relacionados.

### Query

Se crea una Query dedicada únicamente cuando una consulta:

* Es compleja.
* Se reutiliza.
* Tiene filtros y agregaciones relevantes.
* Perjudicaría la claridad del controller.

No se crea una Query class para cada llamada simple a Eloquent.

### DTO

Un DTO se utiliza:

* En límites complejos.
* Para entradas de Actions con múltiples valores.
* En integración OMR.
* Cuando evita arrays ambiguos.

No se crea un DTO para envolver dos campos sin aportar claridad.

### Repository

No se utilizarán repositorios genéricos encima de Eloquent.

Eloquent puede utilizarse directamente dentro de Actions, Queries y controllers simples.

### Events y observers

* Se utiliza una llamada directa cuando la consecuencia es obligatoria e inmediata.
* Un Event se utiliza cuando existen consumidores independientes reales.
* No se ocultan procesos financieros o académicos críticos dentro de observers.
* No se agregan eventos “por si acaso”.

### Organización

La estructura inicial será ligera:

```text
app/
├── Actions/
├── Enums/
├── Http/
│   ├── Controllers/
│   ├── Middleware/
│   └── Requests/
├── Models/
├── Policies/
├── Queries/
└── Support/
```

Se crean subcarpetas por dominio solo cuando exista suficiente contenido para justificarlo.

No se instalará un framework de módulos.

### Pruebas

Cada flujo crítico debe probar:

* Camino correcto.
* Falta de autorización.
* Validación.
* Invariante principal.
* Transacción o reversión cuando corresponda.

La prioridad son pruebas Feature. Las Unit se utilizan para reglas aisladas que realmente lo ameriten.

---

## 6. Modelo obligatorio de autorización

### Principios

* Cada trabajador tiene un solo rol principal.
* El rol es un conjunto predeterminado de permisos.
* Las reglas del sistema nunca comparan el código o nombre del rol.
* Está prohibido escribir:

  * `role === 'A'`
  * `role === 'ADMIN'`
  * `employee_role_code === ...`
* Toda autorización se expresa mediante permisos semánticos.

Ejemplos:

```text
users.view
users.create
students.view
students.create
enrollments.create
attendance.register
payments.record
payments.void
```

### Resolución

El permiso efectivo se resuelve así:

1. Un superadministrador técnico tiene acceso total.
2. Una excepción explícita del usuario prevalece.
3. Si no existe excepción, se consulta el permiso concedido al rol.
4. Si no existe concesión, se deniega.

Las excepciones pueden permitir o denegar.

### Backend

* Laravel es la única autoridad de seguridad.
* Controllers y Actions autorizan mediante Gates o Policies.
* Ocultar un botón no protege una operación.
* Las rutas no confían en permisos enviados por el navegador.

### Frontend

* Inertia comparte la lista de permisos efectivos.
* Svelte utiliza un único helper, por ejemplo `can('students.create')`.
* El helper solo controla presentación y navegación.
* Los componentes Lumi nunca conocen roles ni reglas educativas.
* No existen varios stores o resolutores paralelos de permisos.

---

## 7. Primer caso de uso: UC-001 Acceso de trabajador autorizado

### Objetivo

Permitir que un trabajador inicie sesión, acceda únicamente a sedes autorizadas y reciba permisos efectivos sin comparar roles en backend o frontend.

### Actor principal

Trabajador de Carrión.

### Precondiciones

* El trabajador existe y está activo.
* Tiene una cuenta de autenticación activa.
* Tiene un único rol principal.
* Está relacionado con al menos una sede.
* Su contraseña está almacenada únicamente como hash irreversible.

### Flujo principal

1. El trabajador ingresa login y contraseña.
2. Laravel valida las credenciales.
3. Laravel confirma que la cuenta y el trabajador estén activos.
4. Laravel obtiene las sedes autorizadas mediante `user_branches`.
5. Si tiene una sola sede, se selecciona en sesión.
6. Si tiene varias, se utiliza la última sede válida o se solicita seleccionar una.
7. Laravel resuelve los permisos efectivos:

   * Superadministrador.
   * Excepción del usuario.
   * Permisos del rol.
8. Inertia comparte:

   * Datos mínimos del trabajador.
   * Sedes autorizadas.
   * Sede actual.
   * Permisos efectivos.
9. Svelte construye navegación y acciones visibles mediante el helper único `can()`.
10. Cada endpoint vuelve a autorizar la operación en Laravel.

### Flujos alternativos

#### Credenciales incorrectas

* No se inicia sesión.
* Se devuelve un mensaje genérico.
* No se revela si el login existe.

#### Cuenta inactiva

* No se inicia sesión.
* No se modifica información.

#### Trabajador sin sede

* Se inicia sesión únicamente si existe una decisión administrativa explícita.
* Por defecto se bloquea el acceso operativo y se informa que no tiene sede asignada.

#### Sede no autorizada

* No se cambia la sede de sesión.
* Se responde con estado de autorización denegada.

#### Permiso inexistente

* Backend deniega la operación.
* La interfaz tampoco muestra la acción cuando reciba correctamente los permisos.

### Invariantes

* Una cuenta de trabajador pertenece a un solo trabajador.
* Un trabajador tiene un solo rol.
* Un trabajador puede tener varias sedes.
* La sede actual debe estar en `user_branches`.
* La sede actual vive en sesión, no duplicada en `users`.
* No existe comparación por código de rol.
* No existe contraseña recuperable.
* La excepción individual prevalece sobre el rol.
* Backend sigue siendo la autoridad.

---

## 8. Entidades de la primera vertical

### `branches`

Representa una sede de Carrión.

Campos mínimos:

* `code` UUID primary key.
* `name`.
* `is_active`.
* Timestamps.

### `employee_roles`

Representa el rol principal del trabajador.

Campos mínimos:

* `code` UUID primary key.
* `name`.
* `description` nullable.
* `is_active`.
* Timestamps.

El nombre o código del rol nunca se utiliza para autorizar.

### `permissions`

Catálogo de capacidades semánticas.

Campos mínimos:

* `code` UUID primary key.
* `name` único, por ejemplo `users.view`.
* `description`.
* Timestamps.

### `role_permissions`

Concede permisos predeterminados a un rol.

Campos:

* `employee_role_code`.
* `permission_code`.
* Unique compuesto.
* Foreign keys.

### `users`

Representa a un trabajador, no a sus credenciales.

Campos mínimos:

* `code` UUID primary key.
* Nombre.
* Apellidos.
* Correo nullable.
* Celular nullable.
* `employee_role_code`.
* `is_active`.
* `is_super_admin`.
* Timestamps.

No se crea `employee_number` hasta demostrar su uso funcional.

### `user_branches`

Relaciona trabajadores con sedes.

Campos:

* `user_code`.
* `branch_code`.
* Unique compuesto.
* Foreign keys.

No se almacena `branches.users UUID[]`.

### `user_permissions`

Excepción individual sobre un permiso.

Campos:

* `user_code`.
* `permission_code`.
* `is_allowed`.
* Unique compuesto.
* Foreign keys.

### `auth_accounts`

Contiene credenciales.

Primera vertical:

* `code` UUID primary key.
* `login` único.
* `password`.
* `user_code` único.
* `is_active`.
* `last_login_at` nullable.
* Timestamps.

En esta primera vertical solo autentica trabajadores.

Cuando se implemente el módulo de estudiantes, una migración posterior incorporará una relación explícita con estudiantes y una restricción que garantice que una cuenta pertenece exactamente a un trabajador o a un estudiante.

Está prohibido reemplazarlo por un polimorfismo sin foreign keys.

---

## 9. Criterios de aceptación de la primera vertical

La tarea está terminada únicamente cuando:

* Las migraciones ejecutan desde una base vacía.
* Los modelos utilizan UUID.
* Existen foreign keys y restricciones únicas.
* No existe relación guardada en arrays.
* Existe autenticación de trabajador.
* Existe cierre de sesión.
* Existe selección segura de sede.
* Existe resolución única de permisos.
* Existe un helper Svelte único para presentación.
* Ningún archivo compara códigos de rol.
* Todas las rutas protegidas autorizan en Laravel.
* Las contraseñas solo se almacenan mediante hash.
* Existe un seeder de desarrollo para el administrador inicial.
* Pasan:

  * `php artisan test`
  * `pnpm run check`
  * `pnpm run build`
* Existen pruebas para:

  * Login correcto.
  * Login incorrecto.
  * Cuenta inactiva.
  * Permiso concedido por rol.
  * Permiso permitido por excepción.
  * Permiso denegado por excepción.
  * Cambio a sede autorizada.
  * Rechazo de sede no autorizada.
* No se implementan estudiantes, ciclos, caja, asistencia u OMR en esta tarea.

---

## 10. Regla de detención para agentes

Un agente debe detenerse y explicar antes de implementar cuando:

* Falta una regla del negocio.
* Encuentra dos posibles fuentes de verdad.
* Necesita crear una relación polimórfica manual.
* Considera almacenar relaciones en JSON o arrays.
* Quiere agregar una dependencia.
* Quiere introducir un patrón paralelo.
* Quiere construir funcionalidad fuera del alcance.
* Una solución requiere comparar un rol.
* Una migración no puede proteger una invariante conocida.
* No puede explicar tablas leídas, tablas modificadas, transacción, autorización y pruebas.

Ante una ambigüedad menor, debe aplicar la solución más simple y documentarla. Ante una ambigüedad que modifica datos o reglas del negocio, no debe inventar.
