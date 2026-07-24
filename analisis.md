# Evidencia consolidada — recorrido estudiantil

> Registro de investigación solicitado por el propietario. No es una especificación activa
> ni un roadmap. Las decisiones permanentes viven en `docs/SPEC.md`; los hechos vigentes,
> en `docs/STATUS.md`. Un trabajo futuro debe crear un `TASK.md` pequeño con un resultado
> observable y volver a verificar esta evidencia contra el código.

## 1. Lenguaje común del producto

Aeduca v8 debe mejorar los recorridos probados de Aeduca v7 sin copiar su acoplamiento, e
incorporar los patrones útiles de Coedula sin copiar su repositorio educativo
sobredimensionado.

El recorrido estudiantil confirmado es:

```text
búsqueda institucional
        ↓
identidad y perfil
        ↓
matrícula actual + historia
        ↓
acceso, pagos/caja, asistencia, evaluaciones y archivos
```

Las capacidades se integran desde el perfil, pero cada dominio conserva su propietario,
autorización, consultas y páginas especializadas. “Vertical pequeña” significa el incremento
más pequeño que deja un resultado utilizable; no significa entregar una tabla o CRUD
aislado.

Las entradas tienen significados distintos:

```text
/students/search          directorio institucional y recientes
/students                 padrón activo de la sede por ciclo/grado/sección
/students/{student}       ficha institucional y destino común
/students/{student}/...   escrituras o historias de dominios especializados
```

## 2. Evidencia inspeccionada

### Aeduca v8

Se revisaron el contrato completo de `README.md`, `AGENTS.md`, `docs/SPEC.md`,
`docs/STATUS.md`, los documentos temporales consolidados aquí, y la implementación de:

- autenticación actor-aware y `AuthAccount`;
- permisos, `PermissionDependency` y `BranchContext`;
- estructura académica;
- migraciones, vistas `student_directory`, `student_roster` y
  `student_enrollment_overview`;
- registro, perfil, contactos, acceso, matrícula, padrón y sus pruebas;
- shell Inertia/Svelte y contratos públicos utilizados de Lumi.

La arquitectura v8 ya aceptada es la línea base. No se justifica otro sistema de
autenticación, repositorio, store de sede/permisos, media library ni framework de módulos.

### Aeduca v7

Evidencia relevante en `/home/qorilux/Documents/v7 aeduca main`:

- `resources/js/layout/partial/Header.vue` mantiene Finder disponible desde la barra
  superior para estudiantes, docentes y apoderados.
- `resources/js/Components/Views/Finder.vue` busca personas y permite alcance por sede y
  alumnos activos. El concepto es valioso; su interpolación SQL histórica no debe copiarse.
- `resources/js/Components/Views/MainWrapper.vue` compone una tarjeta lateral con portada,
  foto, identidad y contenido contiguo de altura independiente.
- `resources/js/Components/Views/AvatarManager.vue` selecciona, mueve y recorta una foto
  cuadrada desde la ficha, no durante el alta.
- `resources/js/Views/Student/History.vue` deriva hacia historia de matrícula, contactos,
  documentos, asistencia e incidencias en lugar de cargar todo en un solo formulario.
- `pinia/cycle.js` y `pinia/section.js` conservan ciclo y sección mientras vive la SPA. Es
  memoria de navegación, no persistencia durable en `localStorage`; el comportamiento es
  valioso, no su dependencia de un store global.
- Los flujos financieros usan el vocabulario Pagos/Caja; no existe evidencia para renombrar
  el dominio como “obligaciones”.

Se conserva el recorrido y se descartan las claves semánticas, SQL inseguro, stores
acoplados, Bootstrap específico y contraseñas o archivos sin propietario claro.

### Coedula

Evidencia relevante en `/home/qorilux/Documents/coedula`:

- `src/routes/(dashboard)/students/+page.svelte` usa `PageSidebar`, contexto académico,
  búsqueda dentro del grupo, tabla con persona y menú de acciones.
- `src/routes/(dashboard)/students/[studentCode]/+page.svelte` usa tarjeta lateral con
  `Card.image`, datos compactos y un único dropdown en el título.
- `src/lib/components/StudentSearchDialog/StudentSearchDialog.svelte` y
  `StudentSelect.svelte` ofrecen búsqueda desde el shell mediante resultados remotos
  acotados.
- `src/lib/components/StudentPhotoUploader/StudentPhotoUploader.svelte` demuestra recorte
  y optimización en navegador. Su comportamiento es útil; su UI y CSS no se copiaron.
- El perfil compone matrícula, pagos y archivos mediante lecturas separadas.
- Matrícula y pagos pueden escribirse en una operación coordinada, mientras Caja conserva
  la responsabilidad del cobro. No se copia `group_code` fijo, `turn_1/turn_2/both`, ni el
  gran `EducationRepository`.

### Lumi

Se verificaron `docs/GUIDE.md`, `src/lib/styles/core.css`,
`src/lib/styles/patterns.css` y los componentes públicos usados.

Contratos aplicados:

- `Card.image`, `UserInfo`, `InfoItem`, `Divider`, `Tabs` y `Dropdown`;
- `.lumi-layout--two-columns` con wrapper lateral para impedir que la tarjeta interna se
  estire;
- `PageSidebar` y `.lumi-page-sidebar__header-actions`;
- `Fieldset`, `Input`, `Select`, `Dialog`, `RemoteSelect`, `Slider` y `Table`;
- `.lumi-search-panel`, `.lumi-filter-summary` y celdas/personas públicas.

Lumi no publica un recortador de imágenes. Un componente cohesivo de Aeduca, limitado a
canvas y tokens Lumi, es una excepción justificada; no constituye un segundo sistema UI.

### Nextya

Nextya sólo aporta evidencia para evaluaciones, OMR y reportes especializados. No es fuente
para el perfil o el padrón. El contrato OMR ya confirmó `roll_code` de cuatro dígitos; no se
extendió su alcance en este corte.

## 3. Resultado implementado

### Identidad y foto

- `Student` es identidad institucional con UUID `code`; DNI normalizado de ocho dígitos es
  único y no es PK.
- Alta/edición escribe identidad, contacto, observación y estado. No recibe foto.
- La foto se gestiona desde el perfil mediante un diálogo sobrio:
  selección JPG/PNG/WebP → encuadre cuadrado → paneo/zoom → canvas 640×640 → WebP 0.86.
- El viewport visible y el diálogo son compactos; la resolución de salida no determina el
  tamaño de la interfaz ni provoca scroll vertical innecesario.
- El navegador envía sólo el resultado optimizado; el servidor vuelve a validar tipo y
  tamaño.
- `UpdateStudentPhoto` es el único propietario del reemplazo. Guarda el archivo nuevo,
  persiste su ruta y elimina el anterior sólo después del éxito.
- La lectura permanece privada y autorizada; no se expone una URL pública de Storage.

### Búsqueda

- `/students/search` conserva directorio, recientes, paginación y contexto académico.
- El formulario alinea campo y acciones con el patrón público de filtros en línea de Lumi;
  en viewport estrecho vuelve naturalmente a una columna.
- `student_directory` centraliza el read model.
- DNI exacto y `roll_code` activo exacto preceden a similitud/nombre.
- El shell autorizado abre un diálogo desde cualquier página.
- `/students/lookup` exige dos caracteres, limita consulta y respuesta, y reutiliza la misma
  semántica del directorio; no es otro buscador de dominio.
- La expansión a docentes/apoderados se hará por propietarios explícitos cuando existan,
  no mediante una tabla polimórfica `people`.

### Perfil

- La tarjeta lateral reúne portada, foto, identidad, DNI, nacimiento, teléfono, dirección y
  observaciones.
- La tarjeta está envuelta en el sidebar del layout; por ello su altura no depende de la
  columna contigua.
- El encabezado identifica la página sin repetir el nombre. El nombre, DNI y estado viven
  juntos en la tarjeta de identidad, mientras el encabezado conserva un único menú de
  gestión. No existen botones falsos de carnet, pagos, asistencia o archivos.
- La columna principal muestra matrícula activa y paneles reales de acceso, contactos e
  historia de matrículas.
- El perfil carga hasta diez matrículas autorizadas; las historias futuras tendrán su propia
  consulta/página.

### Padrón activo

- `/students` pertenece a la sede actual de `BranchContext`.
- Ciclo, grado y sección son obligatorios y se validan entre sí contra el catálogo activo de
  esa sede antes de consultar `student_roster`.
- Sin contexto completo no se ejecuta consulta al read model de matrículas.
- El último ciclo/grado/sección válido se recuerda en la sesión autenticada por sede. Un
  retorno sin selección lo revalida contra el catálogo activo y redirige a la URL canónica;
  texto y página no se guardan como preferencias.
- La consulta siempre fija `enrollment_is_active = true`.
- La búsqueda por nombre, DNI o `roll_code` se aplica únicamente dentro de la sección
  seleccionada.
- No hay “Todos los ciclos/grados/secciones”, filtro de turno ni filtro de estado.
- La tabla evita repetir ciclo/grado/sección ya visibles en el encabezado; muestra alumno,
  código, turnos y acciones.
- Una futura lista de inactivos será otra entrada y otro resultado observable; no un modo
  escondido de este padrón.

### Formularios

- Alta/edición usa una ficha centrada con fieldsets Identidad, Contacto e Información
  adicional.
- Los campos textuales tienen placeholders útiles y errores junto al control.
- La foto no compite con la captura de datos ni obliga a multipart durante el registro.

## 4. Propietarios e invariantes que no deben romperse

```text
AuthAccount ── exactamente un propietario ── User | Student

Student ──< Enrollment ──> AcademicGroup ──> CycleDegree ──> AcademicCycle ──> Branch
                └──< EnrollmentShift >── CycleShift

Student ──< StudentContact
Student ── foto privada
Enrollment ──< Payment                  (futuro)
Payment ── cobro/reverso de Cashbox     (futuro)
```

- Estudiante, cuenta y matrícula tienen estados separados.
- El alumno se autentica por identidad propia, sin permisos administrativos ni sede
  inventada, y no requiere matrícula activa sólo para iniciar sesión.
- Existe una sola fila de matrícula por alumno y ciclo; PostgreSQL protege
  `UNIQUE(student_code, cycle_code)`.
- `roll_code` es único y se reserva atómicamente dentro del ciclo.
- Grupo y uno/dos turnos deben pertenecer al mismo ciclo y a la sede actual.
- Reintentar el mismo ciclo dirige a editar la matrícula existente y conserva `code` y
  `roll_code`; nunca desactiva una fila para fabricar otra.
- Mientras el ciclo de una matrícula no haya terminado, el alumno no puede abrir otra en
  un ciclo distinto. La transacción bloquea la identidad del alumno para serializar esa
  decisión.
- “Finalizada” se deriva en la lectura desde `AcademicCycle.end_date`; no se persiste ni
  requiere enum, Action, cron o actualización masiva.
- El historial académico de empleados se limita a sedes autorizadas.
- Permisos iniciales: `students.view/manage`, `enrollments.view/manage`; no hay permisos por
  tab, foto o botón.

## 5. Plano para Pagos y Caja

Pagos es una vertical posterior, no una columna decorativa del padrón ni una parte implícita
del formulario actual.

Responsabilidad confirmada:

```text
Matrícula
  └── puede establecer 0..n Payments pendientes
        ├── concepto
        ├── importe
        └── vencimiento

Cashbox
  └── cobra/publica/anula el Payment
        ├── cajero responsable
        ├── fecha y contexto de caja
        └── movimiento neto de efectivo
```

Antes de implementar se debe volver a inspeccionar el recorrido real v7/Coedula y cerrar:

- catálogo o libertad del concepto;
- generación manual/por lote/al matricular;
- periodicidad y mensualidad;
- pago parcial;
- descuentos/moras;
- efectivo recibido y vuelto;
- anulación/reverso y trazabilidad;
- visibilidad entre sedes y autoservicio.

Reglas ya confirmadas:

- una matrícula es válida aunque cree cero pagos;
- “Pagos” es el vocabulario, no `PaymentObligation`;
- un pendiente sin consecuencia de caja puede corregirse;
- un pago cobrado/publicado nunca se sobrescribe ni elimina: se anula o revierte;
- `payments.view/manage` son la pareja inicial; separar permiso de cobro sólo si existe un
  cargo operativo diferente.

Aceptación mínima futura: creación de pendientes autorizada, resumen acotado en perfil,
vista operativa de cobro, movimiento de caja, reverso, aislamiento de sede, precisión
decimal y pruebas transaccionales.

## 6. Decisión para PDFs: carnet, ticket y reportes A4

Evidencia:

- Aeduca v7 genera PDF de servidor con Dompdf y Simple QrCode.
- Coedula genera PDF con `pdf-lib` y QR con `qrcode`.
- `pdf-lib` es la biblioteca JavaScript de Coedula; no es el producto comercial PDFlib ni
  una integración propia de Laravel.
- [Laravel entrega archivos y streams](https://laravel.com/docs/13.x/responses), pero no
  incluye un motor de creación PDF.
- [Dompdf](https://github.com/dompdf/dompdf) convierte HTML/CSS a PDF, admite A4 y tamaños
  personalizados y se instala directamente con Composer. No implementa Flexbox ni Grid.
- [pdf-lib](https://pdf-lib.js.org/) crea o modifica PDFs en navegador o Node mediante una
  API de dibujo por coordenadas; es especialmente útil para editar, completar o combinar
  documentos existentes.
- QR contiene el DNI.
- El carnet necesita foto, nombre, DNI, `roll_code`, ciclo, grado, sección y sede, y sólo
  aplica con matrícula activa.

Decisión técnica para Aeduca v8:

- Laravel será propietario de autorización, consulta, composición y generación.
- La opción inicial común para carnet, ticket de Caja y reportes A4 será
  `dompdf/dompdf:^3.1`, usado directamente. Un wrapper Laravel no aporta valor probado para
  este alcance.
- El QR se generará en servidor con `endroid/qr-code:^6.1`.
- No se añadirá `pdf-lib`, runtime Node ni generación crítica en el navegador. En Coedula
  encaja con SvelteKit/Node; aquí duplicaría la composición que ya pertenece a Laravel y
  haría el resultado dependiente del dispositivo del usuario.

Razones prácticas:

- Para documentos estructurados, Dompdf permite una plantilla HTML mantenible y una sola
  ruta de datos autorizada. Carnet y ticket usan dimensiones explícitas; los reportes usan
  A4 y reglas de salto de página.
- Las plantillas de impresión usarán CSS deliberadamente simple —bloques, tablas y medidas
  físicas—, fuentes y assets locales. No dependerán de Flexbox, Grid o recursos remotos.
- Cada respuesta construirá una instancia nueva del renderer. Un carnet, ticket o reporte
  pequeño puede generarse sincrónicamente; lotes o reportes grandes deberán medirse y pasar
  a cola o a una exportación tabular, no cargar miles de filas en una petición web.
- Los documentos serán privados, con autorización de servidor y `no-store`; el QR y los
  campos saldrán de una lectura acotada, no de datos enviados por el cliente.
- Cada tipo de documento tendrá un compositor enfocado y una plantilla propia, compartiendo
  sólo tokens/partials de impresión demostrados. No se creará un servicio PDF genérico.

`pdf-lib` sólo se reconsiderará si aparece un requisito real de rellenar, modificar o unir
un PDF existente con control vectorial por coordenadas. Un diseño HTML moderno que exceda
las capacidades medidas de Dompdf justificaría evaluar un renderer Chromium de servidor,
no trasladar por defecto el documento al navegador.

Las dependencias siguen sin instalarse: requieren un `TASK.md` propio que implemente y
pruebe al menos un documento real. No se crea botón, ruta o HTML que finja carnet, ticket o
reporte antes de ese corte.

## 7. Capacidades futuras y límite

- Inactivos: página independiente, no filtro del padrón activo.
- Asistencia: referencia matrícula y turno seleccionado; página/consultas propias.
- Evaluaciones/OMR: usa Nextya sólo como evidencia especializada.
- Archivos: vínculos directos al alumno o compartidos por grupo mediante FK explícitas.
- Contactos pueden evolucionar a un dominio de apoderados sólo cuando sus workflows lo
  demuestren.

No crear tabs vacíos, permisos, tablas, acciones o navegación anticipada para estas
capacidades.

## 8. Incertidumbre restante

- Dependencias PDF/QR: dirección técnica definida; instalación e implementación pendientes
  de un entregable propio.
- Semántica exacta de Pagos/Caja: pendiente de investigación operacional.
- Alcance de futuros apoderados/archivos: no confirmado.

La estructura actual de identidad, búsqueda, foto, acceso, matrícula y padrón no depende de
resolver esas incertidumbres.

## 9. Verificación del corte consolidado

- `php artisan migrate:fresh --seed --env=testing`: aprobado contra `aeduca_test`.
- `composer run format`: aprobado.
- `composer run check`: 135 pruebas, 661 aserciones, TypeScript estricto, Oxlint y
  Prettier aprobados.
- `pnpm run build`: build de producción aprobado sin advertencias de Svelte.
- La base local `aeduca` no fue migrada ni sembrada.
