# Sprint 3 — Guía de preparación y demostración de HU27–HU45

Fecha de revisión: 4 de noviembre de 2026. Zona institucional: **America/La_Paz (UTC−04:00)**.

## Alcance y resultado de la inspección

Se identificaron las **19 HU** en el frontend y backend locales. Referencias de lectura: backend `f69eb9d`, frontend `255a49d`. Esta revisión es estática: **no se abrió el deploy, no se consultó PostgreSQL, no se crearon datos y no se ejecutaron pruebas, migraciones, seeders ni builds**. Todas las filas QA al final comienzan en `NOT RUN`.

Las instrucciones siguientes son para una ejecución manual posterior, únicamente sobre registros demo autorizados. No se debe ejecutar completa esta guía sobre estudiantes o contenidos institucionales reales.

| HU | Implementación identificada | Evaluación estática |
| --- | --- | --- |
| 27 | Incidencia manual y registro automático del intento QR de otro examen | Completo para esos flujos; navegador pendiente |
| 28 | Anulación justificada del ingreso conservando evidencia | Completo; navegador pendiente |
| 29 | Consulta por examen, filtros, detalle y exportación de incidencias | Completo; navegador pendiente |
| 30 | Registro, consulta, estados e historial de infracciones | Parcial: actualización de contenido existe en API, sin editor conectado en la pantalla de detalle |
| 31 | Expulsión durante la ventana del examen y consulta de antecedentes | Completo para registrar/consultar; no aplica una sanción académica automática |
| 32 | Asistencia oficial, pendientes, porcentaje y actividad por verificador | Parcial: progreso por listas exclusivas de HU35 no integrado |
| 33 | Cinco reportes por examen, exportables a PDF y CSV | Completo; depende de caché de snapshots y descargas en deploy |
| 34 | Catálogo global, configuración por examen, CSV, correcciones y evaluación con prioridad manual | Completo para el flujo identificado; depende de criterios activos y datos |
| 35 | Distribución exclusiva y consulta de miembros/rangos | Completo en versión 1, sin regeneración o reasignación |
| 36 | Paquete, persistencia IndexedDB y captura manual/QR provisional | Completo dentro de límites de sesión local y PWA; dispositivo pendiente |
| 37 | Sincronización y clasificación de cada evento | Parcial: no hay resolución de conflictos desde la interfaz |
| 38–45 | Consulta pública y gestión administrativa de calendario, noticias, información y servicios | Las ocho HU identificadas y conectadas; dependen de contenidos/configuración |

**No encontrado:** no se encontró una HU entera ausente. Sí faltan acciones concretas: resolver conflictos offline, regenerar listas, crear categorías de calendario desde la web y crear secciones institucionales estructurales desde la web. No confundir estas ausencias con pruebas ejecutadas.

### Convenciones para seguir la guía

- `WEB` representa el origen real del frontend desplegado. `/ruta` significa `WEB/ruta`; no es una dirección de localhost.
- Los endpoints técnicos se indican relativos a **`/api/v1`**. Se documentan para contrastar la pestaña Network, no para ejecutarlos manualmente durante esta revisión.
- Sustituir `{O}`, `{E1}`, `{E2}`, `{estudiante}`, `{registro}` por los IDs observados en la URL o respuesta. Un SIS no es un ID interno.
- Registrar los IDs reales aquí antes de la demo: oferta `{O}=____`; E1 `____`; E2 `____`; aula `____`; docente `____`; colaboradores C1 `____`, C2 `____`.
- Los títulos de HU conservan los del encargo. Los nombres entre comillas dentro de los pasos son etiquetas observadas en componentes.
- En el listado **Mis exámenes**, las herramientas de la tarjeta del examen incluyen **Habilitaciones**, **Colaboradores** y **Control de ingreso** (`ExamTools`). Si el detalle que se tiene abierto no muestra ese enlace, volver a la tarjeta o abrir la ruta registrada indicada; no buscarlo en una sección inventada del detalle.
- **Completo** significa flujo conectado en código para el alcance descrito. No significa `PASS`. Toda HU queda pendiente de comprobación en navegador; la existencia actual de cuentas, categorías, archivos o datos depende del deploy.

## Escenario compartido mínimo

**Recorrido básico:** 1 administrador, 1 docente responsable, 2 cuentas colaboradoras y 3 estudiantes examinados: **7 cuentas distintas**, suponiendo una cuenta por estudiante. Los colaboradores usan cuentas existentes de estudiantes ajenos a la oferta o docentes; no existe un rol permanente nuevo llamado COLABORADOR. Un visitante no necesita cuenta.

**Recorrido ampliado de HU37:** agregar un cuarto estudiante ya existente permite conservar en una sola ronda los cuatro resultados `ACCEPTED`, `DUPLICATE`, `CONFLICT`, `REJECTED`. Son **8 cuentas** bajo la misma suposición. El cuarto no es obligatorio para abrir las 19 funcionalidades; sí tiene utilidad directa para esa cobertura de resultados. No crear una cuenta si hay un estudiante demo utilizable.

Se necesita **1 materia, 1 oferta activa, 1 periodo activo, 1 aula y 2 exámenes sin solapamiento**. No hacen falta cinco exámenes:

| Alias | Uso | Condición y reserva |
| --- | --- | --- |
| E1: `DEMO S3 — Académico y control` | HU34 antes de los ingresos; HU27–33 después | Tipo `makeup` / «2da instancia». Usar inicializaciones sin decisiones manuales para observar aplicación automática. Conservar Ana con ingreso válido; anular únicamente Bruno; infracción/expulsión únicamente Carla |
| E2: `DEMO S3 — Offline` | HU35–37; segunda lectura de HU32/33 | Tipo `partial`. Sin ingresos previos, con habilitaciones definitivas antes de descargar. No editar examen ni colaboradores después de preparar listas |

Se reutilizan estudiantes, oferta, aula y docente. Las habilitaciones e ingresos son por examen: lo escrito en E1 no equivale a un ingreso en E2. En el código actual la expulsión es informativa y no añade un bloqueo automático de ingreso; aun así, se reserva E1 para mantener clara la evidencia disciplinaria.

**Horario operativo:** crear E1 con inicio futuro, por ejemplo 30 minutos después de comenzar la preparación, y duración 90 minutos; E2 comienza al terminar E1 y dura 90 minutos. Son valores de planificación, no tiempos comprobados. Hacer HU34 y portal mientras se espera el inicio. La misma aula sirve porque los intervalos no se cruzan. Si se necesita ejecutar ambos exámenes simultáneamente, se necesitan 2 aulas disponibles; es una alternativa más costosa, no el mínimo. Nunca atrasar el reloj del teléfono ni del servidor.

### Candidatos conocidos que deben verificarse en la web

| Alias | Candidato documentado localmente | Verificación pendiente |
| --- | --- | --- |
| A | Ana Demo Estudiante — SIS `990260001` | Administrador → Usuarios → Estudiantes → buscar SIS; abrir perfil, comprobar identidad y estado |
| B | Bruno Demo Estudiante — SIS `990260002` | La misma verificación; reservar para anulación en E1 |
| C | Carla Demo Estudiante — SIS `990260003` | La misma verificación; reservar para infracción/expulsión en E1 |
| D, opcional | Diego Demo Estudiante — SIS `990260004`, o cualquier demo activo suficiente | Solo necesario para los cuatro resultados offline en una ronda |

El seeder y `docs/EIDA_FULL_DEMO_SEEDER.md` mencionan ese conjunto, además de Irene/Tomas Demo Docente, materias `FULLQA-*` y estudiantes hasta `990260012`. **Eso no acredita su existencia actual en la BD desplegada.** Tampoco acredita contraseñas actuales ni horarios vigentes. No ejecutar el seeder para comprobarlo. Los tres primeros tienen el mismo apellido «Demo Estudiante»: los rangos pueden verse idénticos aunque las listas sean disjuntas; comparar SIS/ID, no solo textos del rango. No renombrarlos para fabricar rangos distintos.

## Matriz de preparación

Las cantidades son registros utilizables, no registros nuevos a crear. Reutilizar primero.

| Recurso | Cantidad mínima | Estado necesario | Cómo prepararlo | HU relacionadas |
| --- | --- | --- | --- | --- |
| Cuenta administradora | 1 | ACTIVE, rol ADMINISTRADOR activo, primer acceso resuelto | Ingresar con cuenta existente; verificar acceso administrativo | 29, 33, 34, 39, 41, 43, 45 |
| Docente responsable | 1 | Cuenta/perfil activos, DOCENTE y titular de O | Usuarios → Docentes; comprobar asignación de materia | 27–37 |
| Colaboradores | 2 para comparar distribución | Cuentas activas, rol permanente reconocido de nivel no superior al docente, sin inscripción ACTIVE en O | Docente → examen E2 → Colaboradores → asignar | 35–37; actividad HU32 |
| Estudiantes examinados | 3 básico / 4 ampliado | ACTIVE; cuentas disponibles para mostrar QR | Verificar A/B/C y, solo si se hará la variante ampliada, D | 27–37 |
| Carrera | 1 existente | Activa si debe crearse materia | Consultar catálogo; no crear una carrera para esta demo | Preparación académica |
| Periodo | 1 | ACTIVE; reutilizar el vigente | Enlace de periodos del encabezado o `/admin/academic-terms` | Preparación, 33 |
| Materia | 1 | ACTIVE y relacionada con carrera | Académico → Materias | 27–37 |
| Oferta | 1 | ACTIVE, docente correcto, periodo activo | Usuarios → Asignación de materias | 27–37 |
| Inscripciones en O | 3 / 4 | ACTIVE | Docente → Mis materias → Estudiantes → agregar por SIS solo los faltantes | 27–37 |
| Aula | 1 | ACTIVE y libre en cada intervalo | Académico → Aulas; comprobar disponibilidad al programar | 27–37 |
| Exámenes | 2 | E1 makeup, E2 partial; ventana vigente al ingresar/sincronizar | Mis materias → Crear examen; horarios consecutivos | 27–37 |
| Habilitaciones | Una por estudiante y examen: 6 / 8 | Inicializadas para HU34; ELIGIBLE en E2 antes de descargar | Se inicializan al programar; verificar en Habilitaciones | 28, 31, 34–37 |
| Datos académicos | 2 registros de estudiante, con ambos parciales | A cumple; B no cumple; C sin datos requeridos | Importación o «Corregir» en HU34 | 34 |
| Criterio global de segunda instancia | 1 activo | `SECOND_INSTANCE_PARTIAL_AVERAGE` | Consultar catálogo global; no cambiar umbral ni reglas reales | 34 |
| Listas exclusivas | 2 en E2 | v1, responsables C1/C2, unión del padrón sin repetidos | HU35, después de terminar inscripciones/asignaciones | 35–37 |
| Paquetes locales | 1 por responsable/dispositivo | Guardados en IndexedDB y no vencidos | HU36; descargar con la cuenta propietaria | 36–37 |
| QR real de E2 | 1 mínimo | Estudiante ELIGIBLE, dentro de ventana QR | Mis exámenes del estudiante; conservar imagen en otro dispositivo | 36–37 |
| Ingresos E1 | 2 base; tercero opcional para Carla | VALID antes de anular B | Control de ingreso, registro manual | 28, 31–33 |
| Incidencias | 2 manuales | Una con estudiante y una ambiental sin estudiante | HU27 | 27, 29, 33 |
| Infracción | 1 | Carla/E1, REPORTED → CONFIRMED | HU30 | 30, 31, 33 |
| Expulsión | 1 | Carla/E1, dentro del horario | HU31, después de capturar asistencia inicial | 31, 33 |
| Categoría de evento | 1 existente activa | Disponible en selector | Verificar antes de HU39; no hay alta web encontrada | 38–39 |
| Evento | 1 reutilizable | PUBLIC/PUBLISHED al consultar | HU39, borrador → publicar | 38–39 |
| Noticia | 1 reutilizable | PUBLISHED al consultar | HU41; imagen opcional | 40–41 |
| Sección institucional | 1 existente para demostrar cambios | Borrador con descripción válida; publicada para consulta | HU43; no existe alta web de secciones | 42–43 |
| FAQ | 1 opcional útil | Publicada si se mostrará desplegable | HU43 → tipo Preguntas frecuentes → Crear | 42–43 |
| Servicio | 1 | URL HTTPS autorizada y ACTIVE | HU45 → crear inactivo → activar | 44–45 |

## PARTE A — PREPARACIÓN GENERAL

### A1. Entrar primero como Administrador y registrar los datos encontrados

1. En `/login`, ingresar con la cuenta administradora autorizada del deploy. Resolver cambio inicial de contraseña si la aplicación lo exige.
2. Abrir **Usuarios → Estudiantes** (`/admin/students/manage`) y buscar por separado los SIS A/B/C. Registrar ID, estado y nombre. Para variante ampliada, buscar D antes de crear nada.
3. Abrir **Usuarios → Docentes** (`/admin/teachers`) y localizar al docente que manejará la demo. Revisar **Usuarios → Asignación de materias** (`/admin/teachers/assignments`) para identificar una oferta demo propia activa.
4. En **Usuarios → Usuarios** (`/admin/users/manage`), verificar cuentas y roles de dos candidatos a colaboradores. Pueden ser Tomas y una cuenta estudiantil demo externa a O, si efectivamente existen y son elegibles. Un administrador no sirve como colaborador asignable por un docente por la jerarquía de roles.
5. Completar el registro de IDs de esta guía. No usar passwords del código como garantía de acceso actual.

**Rol:** Administrador. **Datos de entrada:** SIS conocidos y cuentas ya autorizadas. **Resultado:** inventario de registros reutilizables. **Sirve:** todas las HU. Si falta una cuenta administradora, no hay alta administrativa genérica en este recorrido que permita autootorgarse ese rol: registrar bloqueo de acceso, sin SQL.

### A2. Reutilizar periodo activo; crear solo si realmente falta

1. Abrir `/admin/academic-terms`, ruta registrada; el encabezado también incluye el acceso de periodos (`HeaderAcademicTermsLink`).
2. Comprobar el periodo activo y sus fechas. Reutilizarlo si corresponde al escenario autorizado.
3. Solo si no hay periodo utilizable, pulsar **«+ Registrar periodo»**, introducir nombre único, fecha inicial/final sin solaparse con ningún periodo existente, y el estado permitido por el formulario. Ejemplo de nombre: `DEMO S3 2026`; las fechas deben elegirse después de consultar el catálogo, no copiarse ciegamente.
4. Guardar con **«Registrar periodo»**. Si quedó inactivo y debe utilizarse, usar la acción **Activar** y su confirmación. No sustituir el periodo activo de un entorno institucional para obtener una demo.

**Rol:** Administrador. **Resultado:** un periodo ACTIVE reutilizable. **Sirve:** oferta y reportes HU33. Fechas de periodos incluso inactivos no pueden superponerse. Si el único periodo activo no es apto y activarlo alteraría datos reales, utilizar otra oferta demo autorizada o marcar preparación bloqueada.

### A3. Verificar o registrar materia y asignarla al docente

1. Abrir **Académico → Materias** (`/admin/subjects`). Reutilizar una materia demo activa, por ejemplo `FULLQA-TIS` solo si se encontró.
2. Si falta, **«+ Registrar materia»** → código `DEMO-S3` si está libre, nombre `Demostración Sprint 3`, seleccionar una carrera activa existente → **«Registrar materia»**.
3. Abrir **Usuarios → Asignación de materias** (`/admin/teachers/assignments`). Buscar la combinación docente/materia/periodo antes de agregarla.
4. Si falta, **«+ Asignar materias manualmente»** → seleccionar docente, materia y periodo activo del formulario → **«Guardar asignaciones»**. No hay necesidad de inventar grupos o paralelos: esta asignación usa docente, materia y periodo.
5. Comprobar la asignación ACTIVE. La unicidad impide duplicar la misma combinación.

**Rol:** Administrador. **Resultado:** O pertenece al docente responsable. **Sirve:** HU27–37. Si no existe una carrera utilizable, esta preparación no incluye alta web de carreras: el catálogo es de lectura; no proponer inserción en PostgreSQL.

### A4. Verificar estudiantes e inscripciones con el Docente

1. Entrar como el docente en otro perfil de navegador; conservar el perfil administrador para el portal.
2. Abrir **Mis materias** (`/teacher/subjects`) → acción **Estudiantes** de O, que lleva a `/teacher/students/{O}`.
3. Buscar A/B/C y D si corresponde. Contar **todo el padrón activo**, no solo los estudiantes elegidos: esa cantidad será `N` y aparecerá en HU32/HU35.
4. Si falta una inscripción de un estudiante existente: **«+ Agregar estudiante manualmente»** → SIS exacto → **«Agregar estudiante»**. Repetir solo para faltantes.
5. Si no existe el perfil estudiantil, volver al Administrador → Usuarios → Estudiantes → **«+ Registrar estudiante»**, únicamente si no hay otro demo suficiente. El alta de estudiante exige sus datos/CI/carrera del escenario demo autorizado y crea cuenta con primer acceso; no inventar identidades de personas reales ni crear estudiantes de más. La inscripción por SIS no reemplaza el alta de perfil.

**Rol:** Docente titular; Administrador solo para alta de perfil faltante. **Resultado:** 3 o 4 inscripciones ACTIVE en la misma O, o un padrón mayor conocido que se reutiliza. **Sirve:** HU27–37. Si O ya tiene nueve inscritos, **N=9**: no desinscribir seis para forzar los porcentajes de ejemplo.

### A5. Comprobar un aula disponible

1. Administrador → **Académico → Aulas** (`/admin/rooms`). Buscar una activa existente.
2. Solo si falta: **«+ Registrar aula»** → código único `DEMO-S3-A`, nombre `Aula demo Sprint 3`, ubicación `Espacio demo autorizado`, capacidad `20` si procede; los otros campos son opcionales → **«Registrar aula»**.
3. Confirmar que aparece activa y que el selector de ambientes la ofrece para cada horario al programar E1/E2. La existencia del aula no garantiza que esté libre.

**Rol:** Administrador. **Resultado:** un aula reservable para intervalos consecutivos. **Sirve:** exámenes, control online/offline. No crear dos aulas si se trabaja consecutivamente.

### A6. Programar únicamente los exámenes que falten

1. Docente → **Mis materias** → O → **Crear examen** (`/teacher/subjects/{O}/exams/create`). Verificar primero **Mis exámenes → Todos los exámenes** para reutilizar demos compatibles.
2. Crear E1 con nombre `DEMO S3 — Académico y control`, tipo **«2da instancia»**, fecha de ejecución, hora futura con margen de preparación, duración `90`, ambiente disponible y normas `Escenario demo autorizado del Sprint 3`.
3. Pulsar **Guardar examen** y comprobar la navegación al detalle `/teacher/exams/{E1}` y estado `SCHEDULED`.
4. Crear E2 con nombre `DEMO S3 — Offline`, tipo **Parcial**, misma fecha, inicio al finalizar E1, duración `90` y la misma aula libre. Si el intervalo cruza medianoche, seleccionar la fecha correspondiente al inicio de E2.
5. No buscar un botón «Iniciar examen»: no se encontró una transición web de inicio/fin. El estado SCHEDULED permite ingreso **cuando el reloj entra en la ventana**, sin forzar IN_PROGRESS.

**Rol:** Docente de O. **Resultado:** dos exámenes compatibles sin reservas cruzadas. **Sirve:** E1 HU27–34; E2 HU35–37 y lectura de HU32/33. La API exige fecha/hora futura al programar. Un examen viejo SCHEDULED con fin vencido no sirve. No modificar después de preparar listas.

### A7. Asignar colaboradores antes de distribuir E2

1. Docente → **Mis exámenes** → detalle de E2; abrir **Colaboradores**, disponible en herramientas del examen, o la ruta registrada `/teacher/exams/{E2}/collaborators` si la vista de detalle no lo enlaza.
2. Pulsar **«+ Asignar colaborador»**, escribir nombre, CI o correo de C1 → **Buscar** → marcar **Seleccionar** en su cuenta → **Confirmar asignación**. Repetir con C2.
3. Comprobar ambos activos. Ninguno puede tener inscripción estudiantil ACTIVE en O, ser el propio docente, carecer de rol permanente reconocido o tener nivel superior al docente.
4. Cada colaborador entra con **su propia cuenta** → encabezado **Colaborador** (`/collaborator`) y comprueba E2. Su autorización es temporal por examen.

**Rol:** Docente titular para asignar; C1/C2 para consultar. **Resultado:** dos responsables válidos para dos listas. **Sirve:** HU35–37, actividad HU32. Si ya hay otros colaboradores activos, la distribución los incluye: documentar el número real; no revocar a colaboradores reales para ajustar la demo.

### A8. Preparar habilitaciones y datos académicos sin bloquear HU34

1. Abrir `/teacher/exams/{E1}/eligibilities`. Las filas se inicializan normalmente `ELIGIBLE`, fuente `INITIALIZED`, al crear el examen con estudiantes activos. **No guardar decisiones manuales todavía en A/B/C.**
2. Administrador → **Académico → Criterios de habilitación** (`/admin/eligibility-policies`): comprobar que el criterio de segunda instancia está activo. El administrador edita nombre/descripción y activa/desactiva; no cambia aquí la fórmula fija ni crea códigos arbitrarios.
3. Docente → E1 → **Habilitaciones → Habilitación automática**. Ejecutar la preparación de notas/configuración y la demo HU34 descrita en Parte B, antes de los ingresos. Los datos académicos pertenecen a O y se comparten entre exámenes; las decisiones pertenecen a cada examen.
4. Tras terminar HU34, volver a Habilitaciones de E1 y dejar habilitados los estudiantes que se usarán para ingresar. A debe cumplir automáticamente; B puede quedar por decisión manual para la prueba de prioridad; habilitar C si hace falta. Guardar solo cambios necesarios.
5. Abrir Habilitaciones de E2 y comprobar A/B/C/D `ELIGIBLE`. No ejecutar HU34 en E2 ni cambiar estas filas después de descargar, salvo en los pasos deliberados de HU37 ampliada.

**Roles:** Administrador consulta catálogo; Docente importa/configura/evalúa/decide. **Datos:** CSV exacto en HU34. **Resultado:** HU34 demostrable sin decisiones manuales previas; E2 listo para paquetes. **Sirve:** HU28, HU31, HU34–37. Una evaluación PENDING no elimina una habilitación previa; verificar el estado efectivo.

### A9. Preparar QR, sesiones y dispositivo

1. En el navegador de cada estudiante necesario, abrir **Mis exámenes** (`/students/qr`) dentro de las 24 horas previas al inicio y antes del fin de E2. Confirmar habilitación y conservar el QR real de E2 para mostrarlo desde otra pantalla o una imagen demo resguardada.
2. C1/C2 usan perfiles de navegador distintos a Administrador, Docente y Estudiante. No iniciar la misma cuenta simultáneamente en varios dispositivos: el proyecto controla la sesión vigente.
3. Preparar cámara en HTTPS y un navegador con IndexedDB y Service Worker. La primera descarga se hace online. No limpiar datos del sitio.
4. No usar fotos como requisito inventado: el control manual admite identificación sin foto; el paquete offline no incorpora fotografías. Si se demuestra foto, utilizar solo imágenes demo autorizadas y comprobar su carga real en el servidor.

**Resultado:** QR auténtico y dispositivos preparados. **Sirve:** HU36–37; también QR equivocado opcional en HU27. Conservar los QR por el menor tiempo necesario; no adjuntar tokens a las evidencias públicas.

### A10. Verificar contenidos institucionales y crear solo los faltantes

1. Administrador → **Contenidos → Gestionar calendario**: comprobar al menos una categoría activa. Si no hay, HU39 no permite crear evento; no hay alta de categoría en la interfaz/API revisada. Registrar bloqueo sin ejecutar seeders.
2. Reutilizar un evento demo o crear uno en HU39; dejarlo PUBLIC/PUBLISHED para HU38.
3. Reutilizar una noticia demo o crearla en HU41; dejarla PUBLISHED para HU40. No hace falta imagen para el mínimo.
4. **Contenidos → Gestionar información**: verificar secciones existentes; editar una en HU43 y publicarla. Opcionalmente crear una FAQ para demostrar el desplegable.
5. **Contenidos → Gestionar servicios**: reutilizar o crear un servicio en HU45 con URL institucional admitida; activarlo para HU44.

**Rol:** solo Administrador. **Resultado:** un contenido por módulo reutilizable. **Sirve:** HU38–45. La publicación será pública en el deploy; usar textos identificados como demostración y no reemplazar contenido real.

## PARTE B — DEMOSTRACIÓN HU27 A HU45

### HU27 — Registrar situaciones excepcionales e intentos de ingreso irregulares

**Objetivo de la demostración:** registrar hechos excepcionales y reconocer el origen manual o automático.

**Rol necesario:** Docente responsable o colaborador autorizado del examen. Administrador puede consultar/exportar, pero no registrar por su solo rol.

**Datos previos:** E1 y A relacionado con O. Para variante automática: QR auténtico de **E2** de A, E1 dentro de su ventana y usuario autorizado a controlar E1.

**Preparación:** reservar dos descripciones únicas, sin crear infracciones. No hace falta un ingreso previo ni una expulsión.

**Ruta en la web:** `/exams/{E1}/entry-control?from=teacher` → **Registrar incidencia**; alternativamente detalle E1 → **Incidencias** (`/exams/{E1}/incidents?from=teacher`) → **Registrar incidencia**.

**Pasos de demostración:**

1. Iniciar como docente y abrir el control de E1.
2. Pulsar **Registrar incidencia**, seleccionar **Problema de identificación**, elegir **Seleccionar estudiante del examen**, buscar A por SIS y seleccionarlo.
3. Escribir `DEMO S3: se requiere una comprobación adicional del documento de identidad.` → **Registrar incidencia**.
4. Abrir otra incidencia de tipo **Problema del ambiente**, elegir **Sin estudiante identificable o no aplica**, escribir `DEMO S3: interrupción breve de iluminación en el ambiente.` y pulsar **Registrar incidencia**.
5. Consultar ambos registros en **Incidencias del examen** y abrir su detalle.
6. Variante automática opcional: en la herramienta QR online de E1, leer el QR real de E2. Comprobar rechazo por otro examen y luego una incidencia con origen automático. No registrar una copia manual del mismo hecho para simular automatización.

**Datos de entrada:** tipos API `IDENTIFICATION`, `SUSPECTED_IMPERSONATION`, `INELIGIBILITY`, `WRONG_EXAM_QR`, `ENVIRONMENT`; descripción no vacía; estudiante opcional. «Sospecha de suplantación» no confirma una infracción. La capa de consulta también usa el alias `WRONG_EXAM`.

**Resultado esperado:** incidencia manual persistida con autor/fecha del servidor y estado interno `PENDING`, presentado como **Reportada** en la consulta normalizada. Intento QR equivocado: rechazo de ingreso y antecedente originado en auditoría; el dataset interno lo clasifica `AUTOMATIC_QR` y el recurso de la API expone `origin=AUTOMATIC`.

**Cómo comprobarlo:** detalle con examen, descripción, estudiante si aplica, responsable, fecha, tipo y origen; reutilizar en HU29/HU33. Network: `POST /exams/{E1}/incidents`; automático desde `POST /exams/{E1}/entry-control/qr`.

**Advertencias:** estudiante debe tener relación con inscripción/habilitación/ingreso del examen. Colaborador requiere asignación y disponibilidad vigente. No todos los errores QR crean una incidencia automática: el dataset identificado incorpora específicamente `ENTRY_QR_WRONG_EXAM`.

**Estado de implementación:** Completo para registro manual y este origen automático; pendiente de validación en navegador.

### HU28 — Anular registros de ingreso con justificación

**Objetivo de la demostración:** quitar validez al ingreso de B manteniendo el registro original y los datos de anulación.

**Rol necesario:** únicamente Docente responsable de E1; no basta Administrador o colaborador.

**Datos previos:** E1 en ventana para preparar ingreso, B ACTIVE, inscripción ACTIVE, habilitación ELIGIBLE y aula coincidente. Ingreso B `VALID`.

**Preparación:** si B no ingresó, abrir control E1 → buscar B → abrir detalle → **Registrar ingreso manual** → **Confirmar ingreso**. Anotar ID y hora. Hacer primero la captura inicial de HU32/HU33 y conservar un ingreso de A independiente.

**Ruta en la web:** `/exams/{E1}/entry-control?from=teacher` → **Gestionar ingresos** → historial → fila de B → **Anular ingreso**.

**Pasos de demostración:**

1. Entrar como docente y pulsar **Gestionar ingresos**.
2. Localizar por SIS y verificar el ID exacto del ingreso válido de B.
3. Pulsar **Anular ingreso** y escribir `DEMO S3: registro de ingreso realizado por error durante la demostración.`.
4. Marcar **Confirmo que deseo anular este ingreso conservando su evidencia original.**
5. Pulsar **Confirmar anulación**.
6. Volver al historial y pulsar **Actualizar historial**; revisar también Asistencia.

**Datos de entrada:** motivo obligatorio, texto no vacío; confirmación explícita en frontend. El frontend envía solo `reason` al endpoint canónico `/annulment`.

**Resultado esperado:** el mismo ID pasa a `ANNULLED`; conserva hora, método y verificador originales y agrega autor, fecha y motivo de anulación. Deja de contar en asistencia oficial.

**Cómo comprobarlo:** historial completo y evidencia de anulación; HU32 disminuye en un válido si B está en el padrón activo. `POST /exams/{E1}/entry-control/entries/{registro}/annulment`; evento de auditoría `EXAM_ENTRY_ANNULLED`.

**Advertencias:** operación persistente, sin «desanular» encontrado. Repetir sobre el mismo registro retorna conflicto `ENTRY_NOT_VALID`. El servicio no añade una restricción horaria a anular, pero sí la hay para crear el ingreso previo. Puede registrarse un **nuevo** ingreso si siguen cumpliéndose las condiciones; no restaura el ID original.

**Estado de implementación:** Completo en código; pendiente de validación en navegador.

### HU29 — Consultar y reportar ingresos irregulares

**Objetivo de la demostración:** localizar las incidencias reales de HU27, combinar filtros y consultar/exportar el resultado permitido.

**Rol necesario:** Docente responsable o Administrador para consulta/exportación; colaborador vigente para consulta de su examen, sin exportación.

**Datos previos:** las dos incidencias de E1 de HU27; opcional automática QR.

**Preparación:** anotar sus IDs y fecha institucional; reutilizarlas, sin crear nuevas por cada filtro.

**Ruta en la web:** Docente → detalle E1 → **Incidencias**; Administrador → **Académico → Incidencias e ingresos irregulares** (`/admin/incidents`) → elegir E1.

**Pasos de demostración:**

1. Abrir la consulta de E1 y observar total y resumen por tipo.
2. En **Buscar incidencia o estudiante**, introducir SIS de A → **Buscar**; abrir el detalle de la incidencia de identificación.
3. **Limpiar filtros** → tipo **Problema del ambiente** → **Buscar**; comprobar la incidencia sin estudiante.
4. Seleccionar el estado ofrecido **Reportada**, fechas Desde/Hasta del día del registro y pulsar **Buscar**.
5. Abrir **Ver detalle** de un resultado y volver a la lista conservando los filtros.
6. Como docente/administrador, usar los botones de exportación CSV/PDF de esa consulta; abrir los archivos y contrastar examen y filtros. Como colaborador, comprobar que no se habilita esa exportación.

**Datos de entrada:** búsqueda por nombre/SIS/CI/número, tipo, estado existente en E1, Desde ≤ Hasta. El selector de estados se alimenta de los estados existentes, no de un listado de transiciones.

**Resultado esperado:** lista y contadores coherentes con filtros, detalle con origen, sin convertir sospechas en sanciones. Una búsqueda sin coincidencias muestra un estado vacío.

**Cómo comprobarlo:** `GET /exams/{E1}/incidents`, `GET /exams/{E1}/incidents/{incidencia}` y `/export`; el catálogo del administrador usa `GET /admin/incidents/exams`. Existe además la API general `/incident-reports`; no inventar una pantalla diferente para ella.

**Advertencias:** las fechas se interpretan en Bolivia. El estado visible **Registrada** de un rechazo QR normalizado no significa asistencia aceptada. No hay en este flujo un botón para resolver incidencias manuales ni es necesario inventarlo.

**Estado de implementación:** Completo para consulta/reporte identificado; pendiente de validación en navegador.

### HU30 — Registrar y gestionar infracciones de estudiantes

**Objetivo de la demostración:** registrar una infracción, confirmar la decisión y conservar justificación e historial.

**Rol necesario:** solo Docente responsable de E1, activo.

**Datos previos:** Carla C relacionada con E1 por inscripción/habilitación/ingreso. No requiere una incidencia previa ni ingreso válido para el alta de infracción en el backend.

**Preparación:** reservar C/E1 para disciplina. No resolver la infracción antes de demostrar sus estados y asociación a expulsión.

**Ruta en la web:** detalle E1 → **Infracciones** (`/teacher/exams/{E1}/infractions`) → **Registrar infracción**.

**Pasos de demostración:**

1. Seleccionar Carla por SIS y tipo **Copia**.
2. Descripción: `DEMO S3: se observó consulta de material no permitido en el examen.`; observaciones: `Registro ficticio autorizado para QA.`
3. Pulsar **Registrar infracción** y anotar ID; abrir **Ver detalle** si no se abrió automáticamente.
4. Pulsar **Actualizar estado** → **Confirmada**; justificación `DEMO S3: verificación del hecho por el docente responsable.`
5. Marcar la confirmación de decisión → **Confirmar cambio de estado**.
6. Revisar **Historial de seguimiento** e historial del estudiante. Después de HU31 y de capturar reportes, opcionalmente repetir el cambio a **Resuelta** con nueva justificación.

**Datos de entrada:** tipos `COPYING` (Copia), `CHEATING` (Trampa), `IMPERSONATION` (Suplantación); descripción obligatoria, observaciones opcionales. Estados `REPORTED`, `CONFIRMED`, `RESOLVED`.

**Resultado esperado:** registro inicial Reportada; transición a Confirmada con autor, hora y razón. Transiciones permitidas: REPORTED→CONFIRMED/RESOLVED, CONFIRMED→RESOLVED. RESOLVED es terminal y bloquea modificaciones.

**Cómo comprobarlo:** detalle/historial y filtro de estado en lista. `POST /exams/{E1}/infractions`; `PATCH /exams/{E1}/infractions/{id}/status`; `GET .../{id}/history` y `.../students/{estudiante}/history`.

**Advertencias:** la API también permite editar tipo/descripción/observaciones con control de versión (`PATCH .../{id}`), pero el detalle React revisado solo conecta el formulario de estado. **No indicar un botón «Editar infracción» que no existe.** Tampoco convierte una incidencia en infracción automáticamente ni expulsa al cambiar estado.

**Estado de implementación:** Parcial para una gestión que incluya edición de contenido desde web; registro, estados y seguimiento están conectados. Pendiente de validación en navegador.

### HU31 — Registrar expulsiones de estudiantes durante el examen

**Objetivo de la demostración:** documentar expulsión de Carla en E1 y asociar la infracción de HU30.

**Rol necesario:** únicamente Docente responsable.

**Datos previos:** C relacionada con E1; examen SCHEDULED/ACTIVE/IN_PROGRESS dentro de `[inicio, fin)`; infracción C/E1 si se usará asociación.

**Preparación:** capturar primero HU32/HU33 inicial. Para una demostración representativa, registrar ingreso manual de Carla en E1, aunque el servicio de expulsión no lo exige. Reservar los ingresos E2 sin tocar.

**Ruta en la web:** detalle E1 → **Expulsiones** (`/teacher/exams/{E1}/expulsions`) → **Registrar expulsión**.

**Pasos de demostración:**

1. Seleccionar Carla por SIS.
2. Escribir `DEMO S3: retiro del examen por uso de material no permitido, escenario ficticio de QA.`
3. En el selector de infracción relacionada, elegir la de Carla/E1 de HU30; también se permite dejarlo vacío.
4. Marcar **Confirmo la expulsión del estudiante seleccionado por el motivo indicado.**
5. Pulsar **Confirmar expulsión**, abrir detalle y el historial de expulsiones del estudiante.
6. Consultar asistencia y reporte disciplinario de E1 para comprobar que la expulsión aparece y el ingreso histórico se conserva.

**Datos de entrada:** estudiante relacionado, motivo obligatorio, infracción opcional del mismo estudiante y examen. No enviar un ID de otro examen.

**Resultado esperado:** una expulsión C/E1 con docente, fecha/hora del servidor, motivo y asociación elegida. No borra ni anula asistencias.

**Cómo comprobarlo:** lista/detalle/historial y HU33 DISCIPLINARY. `POST /exams/{E1}/expulsions`; consultas de detalle e historial bajo ese prefijo.

**Advertencias:** no se encontró acción para editar, revertir o borrar expulsión. Una segunda para C/E1 da `STUDENT_ALREADY_EXPELLED`; fuera de ventana, `EXPULSION_EXAM_NOT_IN_PROGRESS`. El control de ingreso expone `is_expelled` como dato informativo, sin añadirlo a sus blockers: no afirmar que HU31 bloquea futuros ingresos o cambia habilitación automáticamente.

**Estado de implementación:** Completo para registro y consulta descritos; pendiente de validación en navegador y de una política institucional si se requieren consecuencias adicionales.

### HU32 — Supervisar la asistencia y el progreso de ingreso por examen

**Objetivo de la demostración:** comprobar totales oficiales, pendientes, porcentaje y actualización por nuevos ingresos/anulaciones.

**Rol necesario:** Docente responsable; Administrador y colaborador no están autorizados por su solo rol.

**Datos previos:** N inscripciones ACTIVE en O; E1 con al menos A ingresado y otro pendiente. El padrón incluye inscripciones activas, no únicamente estudiantes ELIGIBLE.

**Preparación:** si el escenario está vacío, registrar A manualmente desde el control, mantener B pendiente al principio y abrir una segunda pestaña del **mismo perfil docente** para asistencia. No iniciar otra sesión del mismo docente en otro dispositivo.

**Ruta en la web:** detalle E1 → **Asistencia** (`/teacher/exams/{E1}/attendance`).

**Pasos de demostración:**

1. Abrir Asistencia y anotar N, ingresados R, pendientes N−R y porcentaje.
2. Seleccionar **Con ingreso válido** y buscar SIS de A → **Buscar**.
3. Cambiar a **Pendientes de ingreso** y buscar B.
4. En otra pestaña docente registrar el ingreso manual de B. Volver a asistencia; esperar la actualización o pulsar **Actualizar ahora**. Comprobar incremento de un válido.
5. Abrir **Colaboradores y listas** y revisar ingresos oficiales por verificador. No esperar progreso por lista asignada: aparece como no disponible.
6. Después de HU28, volver a **Actualizar ahora**: la anulación de B reduce en uno R. En E2 repetir antes/después de HU37 para comprobar que los pendientes locales no cuentan.

**Datos de entrada:** filtros Todos / Con ingreso válido / Pendientes de ingreso; búsqueda por nombre/SIS/CI. Fórmula `round(R*100/N, 2)`, con 0% si N=0. Ejemplo N=3: 1 válido=33,33%; 2=66,67%. N=4: 1=25%; 2=50%. Usar N real.

**Resultado esperado:** solo entradas `VALID` de inscritos activos cuentan. Actualización nominal cada 5 segundos, mientras la pestaña está visible y hay red; pausa al ocultarse/desconectarse y recupera consulta al regresar.

**Cómo comprobarlo:** `GET /exams/{E1}/attendance/students`, `/attendance/collaborators` y `/attendance` para resumen. Las respuestas identifican `source=SERVER`, `is_current=true`, `last_updated_at` y no incluyen pendientes IndexedDB en asistencia oficial.

**Advertencias:** backend fija `exclusive_lists.available=false` y `progress_by_assigned_list_available=false`, aun si HU35 preparó listas; rechaza `list_id` como filtro no disponible. No prometer porcentajes por lista. Al perder red no debe presentarse la cifra anterior como vigente.

**Estado de implementación:** Parcial por integración ausente con listas exclusivas; seguimiento global y actividad por verificador implementados. Navegador pendiente.

### HU33 — Generar y exportar reportes de exámenes

**Objetivo de la demostración:** generar cinco tipos de reporte y exportar el snapshot generado a PDF/CSV.

**Rol necesario:** Docente titular para sus exámenes; Administrador para el catálogo general. Colaborador sin acceso por su sola asignación.

**Datos previos:** O con inscritos; A con ingreso válido, al menos un pendiente; incidencias HU27, infracción HU30 y expulsión HU31 para evitar reportes disciplinarios vacíos.

**Preparación:** reutilizar E1; anotar los totales antes de anular B si se desea comparar snapshots. No necesita un examen COMPLETED ni cerrar E1.

**Ruta en la web:** detalle E1 → **Reportes** (`/teacher/exams/{E1}/reports`); o **Mis exámenes → Reportes de exámenes** (`/teacher/exam-reports`); Administrador → **Académico → Reportes de exámenes** (`/admin/exam-reports`).

**Pasos de demostración:**

1. En el catálogo, filtrar por materia/periodo o buscar el nombre de E1; seleccionarlo.
2. Elegir **Estudiantes inscritos** → **Generar reporte** y verificar padrón.
3. Elegir **Asistencia confirmada** → generar; contrastar A y ausencia de B si ya fue anulado.
4. Generar **Pendientes o ausentes**, **Incidencias e ingresos irregulares** e **Infracciones y expulsiones**. Revisar C/E1 y las descripciones demo.
5. En un reporte con datos, pulsar **Exportar PDF** y **Exportar CSV**; abrir ambos archivos y comparar ID, fecha de generación y cantidad de registros con pantalla.
6. Cambiar Desde/Hasta a un intervalo sin registros → generar y observar vacío; restablecer fechas. Después de cambios de datos, generar un reporte nuevo para verlos.

**Datos de entrada:** tipos API `ENROLLED`, `ATTENDANCE`, `PENDING_ABSENT`, `INCIDENTS`, `DISCIPLINARY`; intervalo opcional Desde≤Hasta. Catálogo: búsqueda, materia, periodo, examen; workspace: tipo/fechas.

**Resultado esperado:** snapshot consistente; formatos realmente implementados **PDF y CSV**, sin XLSX. La exportación incluye todos los registros del snapshot, no solo la página visible.

**Cómo comprobarlo:** `GET /exam-reports/catalog`; `GET /exams/{E1}/reports/{TIPO}`; `GET .../{TIPO}/export?report_id=...&format=pdf|csv`. Coinciden cabeceras `X-Report-Id`/`X-Report-Generated-At` y metadatos. PDF contiene datos del examen y registros; CSV incorpora metadatos y cabecera de columnas, no es una plantilla de importación.

**Advertencias:** snapshot dura 30 minutos por configuración local; al vencer, generar otro. Fechas filtran fecha del examen para inscritos/pendientes y fecha de registro para asistencia/incidencias/disciplinarios. Las anulaciones se verifican en historial HU28; el reporte de asistencia muestra válidos. Caché de archivos del servidor debe ser escribible/persistente durante el snapshot.

**Estado de implementación:** Completo para los cinco reportes y dos formatos; pendiente de validación en navegador/deploy.

### HU34 — Evaluar automáticamente habilitaciones mediante criterios institucionales

**Objetivo de la demostración:** evaluar cumple/no cumple/faltan datos y demostrar que una decisión manual prevalece sobre el cálculo automático.

**Rol necesario:** Administrador para catálogo global; Docente de O para datos, configuración y evaluación.

**Datos previos:** E1 tipo makeup; A/B/C inscritos activos; criterio de segunda instancia ACTIVE; habilitaciones A/B inicializadas o automáticas, sin decisión manual anterior. Para C, datos insuficientes.

**Preparación:** antes de ingresos y de guardar cambios manuales en E1, revisar catálogo en `/admin/eligibility-policies`. No desactivar reglas globales para facilitar la prueba. Si E1 ya tiene decisiones manuales, usar filas inicializadas disponibles o un nuevo examen demo compatible; no hay acción web identificada para borrar la fuente manual.

**Ruta en la web:** Administrador → **Académico → Criterios de habilitación**. Docente → E1 → **Habilitaciones → Habilitación automática** (`/teacher/exams/{E1}/eligibilities/automatic`). Pestañas **Configurar criterios**, **Datos académicos**, **Vista previa**, **Historial**.

**Pasos de demostración:**

1. Como administrador, consultar el criterio de segunda instancia y su estado. **Editar** solo permite nombre/descripción; **Activar/Desactivar** afecta el catálogo global, no solo E1. Para el mínimo basta consultar el criterio activo y su historial.
2. Como docente, abrir **Configurar criterios** de E1. Mantener el obligatorio de segunda instancia; para un resultado predecible en un examen demo nuevo, no seleccionar opcionales adicionales. Pulsar **Guardar configuración**. Si se reutiliza una configuración con otros criterios, anotar y satisfacer también esos parámetros.
3. En **Datos académicos**, revisar primero si A/B ya tienen registro. Si faltan, **Descargar plantilla CSV**, preparar el archivo de ejemplo siguiente, **Seleccionar archivo**, revisar filas y pulsar **Validar y generar vista previa**. Confirmar solo sin errores con **Confirmar importación**. Para registros existentes usar **Corregir** → cambiar parciales/asistencias → motivo obligatorio `DEMO S3: preparación de datos académicos para evaluación.` → **Guardar corrección**; no reimportarlos.
4. Dejar C sin registro académico. Si ya existe uno, **Corregir**: conservar primer parcial Rindió/40, segundo **Sin dato**, asistencia 8/10 y motivo `DEMO S3: caso de información académica incompleta.`. No confundir **Sin dato** con **No rindió**.
5. Abrir **Vista previa** → **Generar vista previa de habilitaciones**. Revisar A promedio 30→ELIGIBLE, B promedio 25→INELIGIBLE, C→PENDING por falta de dato.
6. Pulsar **Confirmar evaluación**. Abrir HU12/Habilitaciones y comprobar estados efectivos. En C, PENDING calculado no sustituye la decisión previa: puede seguir ELIGIBLE por inicialización.
7. Para prioridad manual: volver a Habilitaciones → fila B → **Gestionar**, seleccionar **Habilitado** → **Guardar**. Volver a automática, generar otra vista previa y confirmar: B sigue Habilitado aunque el cálculo sea Inhabilitado; se indica preservación manual.
8. Abrir **Historial** y detalle de evaluaciones. Al terminar, dejar E1 listo para ingresos sin modificar E2.

**Datos de entrada:** el archivo que recibe **la interfaz** es UTF-8, separado por comas, con estas columnas exactas y orden:

```csv
sis,nombre_completo,nota_pp,nota_sp,asistencias,total_clases
990260001,Ana Demo Estudiante,30,30,8,10
990260002,Bruno Demo Estudiante,25,25,8,10
```

Sustituir SIS/nombres si se usan otros candidatos. C se omite para el caso «faltan datos». El campo nombre es requerido en frontend, pero la relación backend se resuelve por SIS; no usarlo como validación institucional de identidad.

La interfaz convierte cada fila en dos filas del contrato **backend**, que no debe subirse como plantilla de la web:

```csv
sis,assessment_key,was_taken,score,attended_classes,total_classes
990260001,FIRST_PARTIAL,true,30,8,10
990260001,SECOND_PARTIAL,true,30,8,10
990260002,FIRST_PARTIAL,true,25,8,10
990260002,SECOND_PARTIAL,true,25,8,10
```

Notas 0–100, hasta 2 decimales con punto; asistencias enteras, 0≤asistidas≤total y total≥1. CSV API máximo 5 MiB/1000 filas: la conversión actual de dos filas limita a 500 estudiantes por archivo. Las columnas humanas exigen asistencias/total; una nota vacía se convierte en `was_taken=false`, no en dato desconocido. Para ausencia de información usar C sin importar o corrección explícita con **Sin dato**. No duplicar SIS+evaluación ni importar sobre registros existentes. Preview de importación y evaluación vence en 30 minutos.

**Resultado esperado:** combinación `ALL`; si cualquier criterio falla→INELIGIBLE; si faltan datos y ninguno falla→PENDING; sin criterios→PENDING. Solo resultados concluyentes sin decisión manual previa actualizan la habilitación. Se conserva la decisión manual y se registra evaluación/auditoría.

**Cómo comprobarlo:** detalle de vista previa con cálculo, estado efectivo, criterios y campos faltantes; Habilitaciones y Historial. API `/admin/eligibility-criteria`; `/exams/{E1}/eligibility-automation/{options,configuration,evaluations...}`; `/academic-offerings/{O}/academic-records` y sus importaciones/correcciones.

**Advertencias:** el catálogo real incluye evaluaciones previas rendidas, porcentaje de asistencia, nota mínima por parcial, puntaje acumulado, promedio mínimo y segunda instancia. Los opcionales se parametrizan por examen. **Para makeup y criterio activo, la regla fija es promedio de ambos parciales >25**, no ≥26 ni una norma general para todos los exámenes. 25,01 cumple; 25 no. No seleccionar otro promedio de ambos parciales en makeup: provoca `CRITERIA_CONFLICT`. El rótulo UI «institucional» no convierte la propuesta `TEAM_PROPOSAL` del código en una norma oficialmente aprobada. Si cambia catálogo, notas, inscripción o habilitación entre preview/confirmación, generar otro preview tras el 409.

**Estado de implementación:** Completo para el flujo actual; dependiente de catálogo/datos y pendiente de validación en navegador. La nota antigua que declara HU34 fuera de alcance está desactualizada frente a estos servicios y rutas.

### HU35 — Generar y asignar listas exclusivas de estudiantes por apellidos

**Objetivo de la demostración:** distribuir una sola vez el padrón activo entre responsables y comprobar exclusividad por identidad.

**Rol necesario:** Docente responsable para preparar/ver todas las listas; colaboradores para su paquete propio después.

**Datos previos:** E2 vigente o futuro sin fin vencido; O con N inscripciones ACTIVE; C1/C2 asignados y válidos. Mínimo útil 3 estudiantes/2 colaboradores (listas 2 y 1); con 4, listas 2 y 2. Con más inscritos, distribuirá todos.

**Preparación:** completar inscripciones, identidad, examen y colaboradores antes de la primera preparación. Verificar si ya existe una distribución; no modificar datos para forzar otra.

**Ruta en la web:** detalle E2 → **Distribución de listas** (`/teacher/exams/{E2}/offline/assignments`).

**Pasos de demostración:**

1. Observar métricas de estudiantes/colaboradores y ausencia o presencia de distribución.
2. Si no existe, pulsar **Preparar listas** → diálogo **Preparar listas exclusivas** → **Confirmar preparación**.
3. Si ya existe, pulsar **Actualizar consulta** y seguir directamente con sus listas; la web no ofrece regeneración.
4. En cada tarjeta revisar responsable, cantidad, versión y rango de apellidos; pulsar **Consultar estudiantes**.
5. Anotar SIS/ID de cada lista y compararlos: la suma debe ser N y ningún ID/SIS debe figurar dos veces entre listas.
6. Volver con el botón de la vista de miembros. No utilizar el breadcrumb defectuoso `/distribution` mencionado en problemas detectados.

**Datos de entrada:** no se escriben límites alfabéticos manuales. El servidor ordena por apellidos, nombres, SIS e ID; reparte bloques consecutivos balanceados, con un extra en las primeras listas si hay residuo.

**Resultado esperado:** distribución persistida v1; listas disjuntas por estudiante. Responsables ordenados por user_id. Con colaboradores válidos el docente supervisa pero no recibe lista propia; sin ellos, recibe una lista única como alternativa técnica.

**Cómo comprobarlo:** `POST /exams/{E2}/offline/prepare`, `GET /exams/{E2}/offline/assignments`; comparar `students`, `student_count`, `surname_range`, versión. La exclusividad no se demuestra comparando solo rangos textuales.

**Advertencias:** misma entrada en la API devuelve la distribución existente; si cambia la entrada, `DISTRIBUTION_FROZEN`. No hay regeneración/reasignación v1. Cambiar nombre/horario/aula del examen puede volver obsoletos los paquetes (`DISTRIBUTION_STALE`). Si las listas existentes son incompatibles, usar otro examen demo futuro desde la web, conservando la distribución histórica.

**Estado de implementación:** Completo dentro del contrato v1; pendiente de validación en navegador y dependiente de distribución existente.

### HU36 — Registrar ingresos sin conexión mediante QR y verificación manual

**Objetivo de la demostración:** preparar un dispositivo, capturar manual y QR sin red y demostrar persistencia local sin confundirla con asistencia oficial.

**Rol necesario:** responsable de una lista de E2, normalmente C1/C2. El docente solo descarga paquete propio si fue el responsable asignado por ausencia de colaboradores.

**Datos previos:** HU35; estudiantes de la lista ACTIVE, inscripción ACTIVE, habilitación ELIGIBLE y sin ingreso conocido; QR auténtico de E2; HTTPS/PWA/IndexedDB operativos.

**Preparación:** C1/C2 online con sus propias sesiones; abrir la ruta offline y verificar cuenta. QR mostrado en otro dispositivo antes de desconectar. Si hay dos capturas en una lista, reservar estudiantes distintos para manual y QR. Con listas 2/1, usar la lista de dos miembros; no asumir que pertenece a C1 hasta consultarlo.

**Ruta en la web:** **Colaborador** (`/collaborator`) → tarjeta E2 → **Preparar control sin conexión** (`/exams/{E2}/offline`). Desde el control online también existe **Control sin conexión**. Pestañas offline: **Preparación**, **Control local**, **Sincronización**. `/offline` es **Mis listas locales**.

**Pasos de demostración:**

1. Con red, pulsar **Descargar lista para uso sin conexión**. Comprobar «Lista disponible sin conexión», propietario, miembros, lista/versión y hora de preparación.
2. Revisar **Apertura offline**: debe confirmar recursos de aplicación almacenados. Si solo confirma datos pero no la caché, no prometer recarga sin conexión.
3. Abrir la pestaña **Control local** (`?view=control`) y mantenerla abierta. Desconectar Wi-Fi/datos del dispositivo o usar Network → Offline en DevTools, manteniendo permisos de cámara. No desactivar JavaScript ni borrar caché/almacenamiento.
4. Dentro de la ventana E2, elegir **Verificación manual**, buscar un miembro por SIS → **Verificar identidad**, marcar **Verifiqué presencialmente la identidad y el ambiente.** → **Guardar ingreso provisional**.
5. Para otro miembro, elegir **Captura QR** → **Activar cámara**, permitir cámara y leer el QR auténtico. Aparece **QR capturado, sin autenticar**. Buscar al estudiante correcto de la lista → **Verificar identidad**, confirmar identidad y guardar provisional.
6. Abrir la pestaña **Sincronización** (`?view=sync`) y verificar eventos **Pendiente de envío**, UUID y hora declarada. No debe poder capturar otra vez el mismo estudiante en ese dispositivo, aun si el resultado luego es terminal.
7. En DevTools → Application → IndexedDB → **`eida-offline-entry-v1`**, actualizar la vista y revisar almacenes `packages` y `events`; comparar UUID/ownerId/examId con pantalla. `locks` sirve a coordinación local. Esta inspección es de lectura; no editar valores.
8. Solo si Apertura offline está confirmada y hay margen, recargar **la misma pestaña y ruta offline** y comprobar que reaparecen los eventos. No cerrar el navegador para validar un comportamiento que requiere continuidad de sesión.

**Datos de entrada:** selección de estudiante de la lista, método MANUAL/QR y confirmación presencial. No hay campo para pegar un JWT arbitrario como método de generar conflictos. QR v2 transporta referencia opaca firmada; no identifica localmente al estudiante por sí solo.

**Resultado esperado:** evento PENDING duradero en IndexedDB con UUID, paquete, lista, versión, método y captura. Es **provisional**: el servidor todavía no validó autenticidad, habilitación actual, duplicados ni horario; no hay asistencia oficial nueva hasta sincronizar.

**Cómo comprobarlo:** lista de eventos local, referencia del evento y lectura IndexedDB; Docente → Asistencia de E2 sigue sin incluir esos pendientes. Paquete: `GET /exams/{E2}/offline/package`; captura no hace un POST de ingreso oficial.

**Advertencias:** paquete vence al fin del examen; no se captura antes del inicio ni después del vencimiento local. Continuidad local usa `sessionStorage` de esa pestaña, máximo 24 horas: IndexedDB puede conservarse al cerrar, pero al reabrir puede faltar identidad y requerirse conexión/login con el mismo propietario. Cerrar sesión, bloquear acceso local o cambiar de cuenta invalida continuidad. PWA cachea la aplicación, no APIs, fotos ni autenticación. No hay fotografía en el paquete. Instalar PWA no es condición suficiente ni obligatoria si el navegador ya cumple Service Worker.

**Estado de implementación:** Completo dentro de esos límites, dependiente de datos/dispositivo y pendiente de validación manual del deploy.

### HU37 — Sincronizar ingresos offline y resolver conflictos

**Objetivo de la demostración:** enviar pendientes, reconocer decisiones del servidor y verificar ingresos oficiales desde la web; identificar el límite de resolución de conflictos.

**Rol necesario:** el mismo propietario que descargó el paquete para sincronizar; Docente para consulta oficial y preparación deliberada de casos negativos.

**Datos previos:** eventos PENDING HU36, paquete original conservado, sesión vigente de su dueño, E2 dentro de horario y habilitaciones sin cambios para el camino positivo.

**Preparación:** para recorrido básico con tres estudiantes, dejar un ingreso manual y uno QR pendientes en la lista de dos miembros y no cambiar sus datos. Para cuatro resultados distintos en una ronda, usar cuatro estudiantes y preparar la variante ampliada a continuación **antes de sincronizar**.

**Ruta en la web:** `/exams/{E2}/offline` → pestaña **Sincronización** (`?view=sync`), que muestra **Sincronización de ingresos**. Comprobación oficial: Docente → E2 → **Asistencia**, control de ingreso y **Reportes**.

**Pasos de demostración:**

1. Restablecer la conexión del dispositivo sin cerrar la pestaña.
2. Abrir **Sincronización**. Si se solicita, pulsar **Verificar sesión con el servidor** con la misma cuenta; no entrar con el docente en el navegador del colaborador. «Red disponible» no garantiza que Laravel responda.
3. Pulsar **Sincronizar ahora (n)**. No hay envío automático en segundo plano.
4. Esperar resultado por evento; usar filtros **Aceptado**, **Duplicado**, **Necesita revisión**, **Rechazado**. En **Referencia del evento** revisar código e ID de ingreso oficial si existe.
5. Como docente en su perfil online, abrir Asistencia de E2 → **Actualizar ahora**. Buscar los estudiantes aceptados o duplicados, y verificar método/verificador en el control/historial. Generar reporte de asistencia nuevo.
6. Recargar la consulta con red: persistencia y respuesta de Laravel permiten comprobar registro oficial por la web que usa PostgreSQL, sin consultar SQL directamente. Un cambio solo en IndexedDB no demuestra esto.

**Datos de entrada:** el cliente agrupa solo PENDING por paquete/lista/versión/aula, máximo 100 eventos por lote. Conserva UUID y contenido originales. El usuario no selecciona el resultado.

Variante ampliada reproducible mediante la interfaz, **con cuatro estudiantes** y solo en E2 demo:

| Estudiante reservado | Preparación antes de sincronizar | Decisión esperada |
| --- | --- | --- |
| A | Capturar QR auténtico de A/E2, seleccionar A y confirmar identidad. No cambiar datos ni registrar online | `ACCEPTED / ENTRY_REGISTERED` |
| B | Captura MANUAL offline pendiente. Después, Docente → control **online** E2 → B → Registrar ingreso manual → Confirmar ingreso, mientras el dispositivo del colaborador sigue sin sincronizar | `DUPLICATE / ALREADY_REGISTERED` |
| C | Captura MANUAL offline pendiente. Después, Docente → Habilitaciones E2 → C: cambiar a Inhabilitado con motivo demo y Guardar; volver a abrir y cambiar a Habilitado y Guardar. No descargar otro paquete para reemplazar el evento original | `CONFLICT / STUDENT_DATA_CHANGED`: mismo estado final, pero cambió la revisión respecto del snapshot |
| D | Captura MANUAL offline pendiente. Después, Docente → Habilitaciones E2 → D: cambiar a Inhabilitado, motivo `DEMO S3: rechazo deliberado de sincronización por cambio de habilitación.` y Guardar; dejarlo así hasta sincronizar | `REJECTED / ENTRY_CONDITIONS_NOT_MET` |

Distribuir estas acciones según la **lista propietaria real**; cada colaborador captura únicamente sus miembros. Primero descargar ambos paquetes y capturar los cuatro eventos; después el docente provoca B/C/D; finalmente cada propietario sincroniza. Mantener examen, aula, colaboradores, identidad e inscripciones sin otros cambios. Las cuatro capturas son provisionales hasta el envío. Con N=4 y ningún otro ingreso, el estado oficial final tiene A y B válidos (50%), C y D pendientes de ingreso. El verificador de B es el docente del registro online, no el colaborador del evento duplicado.

**Resultado esperado:** camino básico, ACCEPTED por cada evento válido nuevo; variante ampliada, cuatro clasificaciones. La hora oficial de ingreso es la recepción del servidor; `captured_at` queda como evidencia declarada, no como hora oficial retroactiva.

**Cómo comprobarlo:** `POST /exams/{E2}/offline/sync`, `results[].status/code/entry_id`; historial oficial y asistencia web. UUID repetido con contenido idéntico devuelve el **resultado original**, no necesariamente DUPLICATE; este último representa otro evento ante un ingreso ya válido.

**Advertencias:** `CONFLICT` y `REJECTED` son terminales en esta UI: no hay botón para resolver, editar ni reenviar esos eventos; solo se conservan para seguimiento. Restituir D a Habilitado después de la evidencia no reabre el evento. Reutilización de UUID con otro contenido, versión/lista manipulada, ambiente incorrecto o estudiante fuera de lista requieren cliente API/pruebas de integración; la UI no permite producirlos legítimamente. No editar IndexedDB ni falsificar QR/horas para simularlos. Revocar colaborador o dejar vencer el examen también produce conflictos, pero consume más el escenario y no es necesario para esta demo. Se debe sincronizar **antes del fin de E2**, incluso si la captura ocurrió dentro de la ventana.

**Estado de implementación:** Parcial respecto de «resolver conflictos»: sincronización, clasificación y evidencia conectadas; resolución operativa no encontrada. Navegador y cuatro respuestas aún `NOT RUN`.

### HU38 — Consultar calendario institucional

**Objetivo de la demostración:** visualizar evento publicado/público y abrir detalle sin autenticación.

**Rol necesario:** Visitante; también cualquier usuario autenticado.

**Datos previos:** un evento demo PUBLIC/PUBLISHED de HU39, con intervalo dentro del mes mostrado y categoría activa.

**Preparación:** terminar creación/publicación HU39 antes de esta consulta; anotar fecha y título.

**Ruta en la web:** **Inicio** (`/`) → calendario → **Ver todos** (`/calendar`); también acceso directo a esa ruta registrada. No existe un enlace público «Calendario» en la configuración general del encabezado.

**Pasos de demostración:**

1. Abrir Inicio como visitante y localizar el calendario.
2. Pulsar **Ver todos**; navegar con Mes anterior/siguiente hasta el mes del evento si hace falta.
3. Elegir su **Categoría** y pulsar el día marcado con actividad.
4. Pulsar el título del evento en la agenda; comprobar el diálogo de detalle.
5. Cerrar el detalle y pulsar **Ver todo el mes**. Tras los cambios de estado/visibilidad de HU39, recargar y comprobar que un privado, borrador o cancelado desaparece.

**Datos de entrada:** mes/año, categoría y día existentes. No usa los exámenes privados como eventos institucionales.

**Resultado esperado:** título, descripción, fecha/hora de Bolivia, categoría y ubicación; solo `PUBLISHED AND PUBLIC`.

**Cómo comprobarlo:** calendario/diálogo; `GET /portal/events`, `/portal/events/{id}`, `/portal/event-categories`. ID conocido de un privado no permite consulta pública.

**Advertencias:** un evento publicado PRIVATE sigue oculto. El fin de evento de día completo es exclusivo: para un solo día, termina a medianoche del día siguiente.

**Estado de implementación:** Completo; dependiente de eventos y pendiente de validación en navegador.

### HU39 — Gestionar eventos del calendario

**Objetivo de la demostración:** crear, editar, publicar/despublicar/cancelar y consultar historial de un evento demo.

**Rol necesario:** solo Administrador activo.

**Datos previos:** categoría de evento activa. Sin categoría no hay alta disponible; no se encontró gestión de categorías que permita resolverlo desde la web.

**Preparación:** buscar antes un evento demo reutilizable; reservar una fecha visible, por ejemplo el día de la demo de 16:00 a 17:00 Bolivia. No ocupa un aula de exámenes.

**Ruta en la web:** **Contenidos → Gestionar calendario** (`/admin/portal/events`).

**Pasos de demostración:**

1. Pulsar **Crear evento** y completar título `DEMO S3 — Jornada informativa`, descripción `Actividad ficticia para demostrar el calendario.`, categoría existente, ubicación `Sala demo`, visibilidad **Público**, inicio/fin del día elegido 16:00/17:00.
2. Pulsar **Crear borrador**. Localizarlo y abrir **Gestionar evento**.
3. Cambiar ubicación a `Sala demo actualizada` → **Guardar cambios**; revisar historial.
4. Pulsar **Publicar** → **Confirmar cambio**. Ejecutar HU38 y capturar evidencia pública.
5. Volver al evento → **Despublicar** → **Confirmar cambio**; recargar calendario público y confirmar ausencia. Volver a publicar.
6. Cambiar visibilidad a Privado → guardar y **Confirmar cambio de visibilidad**: debe desaparecer aunque siga publicado. Restablecer Público de igual forma.
7. Tras evidencia HU38, **Cancelar evento** → **Confirmar cambio**; comprobar estado e historial. Para dejarlo público, **Volver a borrador**, revisar y publicar otra vez.

**Datos de entrada:** título ≤200, descripción ≤10000, ubicación ≤255; categoría activa; fin posterior al inicio; fechas del formulario son Bolivia. Día completo exige medianoches y fin exclusivo.

**Resultado esperado:** nuevo DRAFT; cambios incrementan revisión y quedan auditados. PUBLIC/PUBLISHED visible; DRAFT/CANCELLED/PRIVATE ocultos.

**Cómo comprobarlo:** tabla administrativa, historial del diálogo y calendario visitante. Endpoints `/admin/portal/events`: POST alta, PATCH `/{id}`, PATCH `/{id}/status`, GET `/{id}/history`.

**Advertencias:** editar un publicado público modifica inmediatamente lo visible. Con 409 recargar y revisar antes de guardar. No hay eliminación requerida ni botón para publicar exámenes como eventos. Probar gestión con cuenta docente mediante navegación directa debe denegarse; backend exige administrador además de la protección React.

**Estado de implementación:** Completo; dependiente de categoría activa y pendiente de validación en navegador.

### HU40 — Consultar noticias institucionales

**Objetivo de la demostración:** encontrar una noticia publicada desde Inicio/Noticias y consultar detalle.

**Rol necesario:** Visitante o usuario autenticado.

**Datos previos:** noticia PUBLISHED creada/reutilizada en HU41.

**Preparación:** anotar ID/título y asegurar publicación; imagen opcional.

**Ruta en la web:** encabezado **Noticias** (`/news`); Inicio también integra noticias. Detalle `/news/{id}`.

**Pasos de demostración:**

1. Como visitante abrir Noticias.
2. Localizar la noticia preparada en el listado ordenado **Más recientes primero**; usar la paginación si hace falta. La pantalla pública no ofrece buscador, aunque la API acepta búsqueda.
3. Pulsar el título/enlace de detalle de su tarjeta.
4. Comprobar título, fecha, resumen y contenido completo, e imagen si se cargó.
5. Pulsar **Volver a noticias**. Después de despublicar/archivar en HU41, recargar lista y comprobar ausencia del registro.

**Datos de entrada:** selección de noticia y página del listado. Con una noticia no hace falta crear otras para fabricar paginación. El frontend envía `search=''` al listado público.

**Resultado esperado:** solo publicadas; detalle accesible sin sesión. Noticias sin imagen siguen siendo consultables.

**Cómo comprobarlo:** `GET /portal/news`, `GET /portal/news/{id}` y `/image` si corresponde; comparar texto con el editor administrativo.

**Advertencias:** un ID conocido no vuelve pública una noticia DRAFT/ARCHIVED. Orden/fecha dependen de publicación del servidor, no de la posición de alta. Comprobar la descarga real de imagen en deploy si se incluye.

**Estado de implementación:** Completo; pendiente de validación en navegador y dependiente de publicación.

### HU41 — Gestionar noticias institucionales

**Objetivo de la demostración:** crear, editar, publicar y conservar historial; opcionalmente cargar una imagen demo.

**Rol necesario:** solo Administrador activo.

**Datos previos:** cuenta administradora; no requiere categoría ni examen.

**Preparación:** buscar noticia demo para reutilizar. Imagen opcional JPEG/PNG/WebP ≤5 MiB si se quiere demostrar subida.

**Ruta en la web:** **Contenidos → Noticias** (`/admin/portal/news`).

**Pasos de demostración:**

1. Pulsar **Crear**; título `DEMO S3 — Aviso de demostración`, resumen `Noticia ficticia para comprobar el portal.`, contenido `Este contenido corresponde a una demostración autorizada del Sprint 3.`
2. Pulsar **Crear borrador**; volver a abrir con **Gestionar** si es necesario.
3. Modificar resumen a `Noticia demo actualizada para QA.` → **Guardar noticia**.
4. Opcional: seleccionar imagen demo → **Subir imagen**; comprobar vista previa. Esta es una operación separada del guardado de texto.
5. **Publicar** → **Confirmar operación**; ejecutar HU40.
6. Volver → **Despublicar** → confirmar; comprobar ocultamiento. Publicar de nuevo y, tras guardar evidencia, demostrar **Archivar** si se desea. Restituir a publicada solo si se necesita seguir mostrándola.
7. Abrir historial del registro y revisar autor, fecha y cambios; no esperar recuperar archivos de imagen antiguos desde allí.

**Datos de entrada:** título ≤200, resumen ≤1000, contenido ≤50000. No se cargan rutas del disco del Administrador como image_url; se usa archivo en el control de subida.

**Resultado esperado:** alta DRAFT; estados DRAFT/PUBLISHED/ARCHIVED; autor y fecha asignados por servidor; solo PUBLISHED visible públicamente.

**Cómo comprobarlo:** editor/tabla, HU40 y `GET /admin/portal/news/{id}/history`; POST alta, PATCH contenido/estado y POST imagen bajo `/admin/portal/news`.

**Advertencias:** guardar texto de una noticia ya publicada cambia la versión pública inmediatamente. La subida/reemplazo/eliminación de imagen incrementa revisión; utilizar la versión actual. Docentes, estudiantes y colaboradores no tienen permiso de gestión.

**Estado de implementación:** Completo; pendiente de validación en navegador y del almacenamiento de imágenes si se usa.

### HU42 — Consultar información institucional

**Objetivo de la demostración:** consultar secciones publicadas, enlaces y respuestas de FAQ desde el encabezado.

**Rol necesario:** Visitante o usuario autenticado.

**Datos previos:** sección publicada de HU43; una FAQ publicada para mostrar acordeón.

**Preparación:** terminar HU43 y anotar el texto publicado antes de guardar un nuevo borrador.

**Ruta en la web:** encabezado **Información** (`/information`).

**Pasos de demostración:**

1. Abrir Información como visitante.
2. Localizar la sección demo publicada y verificar título, descripción y orden.
3. Abrir un enlace si la sección lo tiene; contrastar destino autorizado.
4. Desplegar la pregunta frecuente demo y leer respuesta; cerrarla y comprobar manejo con teclado.
5. Mientras el administrador guarda un borrador nuevo sin publicar en HU43, recargar: el visitante debe seguir viendo el snapshot publicado anterior. Tras **Publicar cambios**, recargar y comparar.

**Datos de entrada:** no requiere sesión ni datos de exámenes. Las secciones estructurales del código son Acerca de EIDA, Objetivos y beneficios, Orientaciones para estudiantes, Orientaciones para docentes, Orientaciones para colaboradores y Contacto autorizado; sus títulos son editables y no se garantiza que todas estén publicadas actualmente.

**Resultado esperado:** únicamente snapshots publicados de secciones/FAQ. Si no hay ninguno, mensaje `No hay información institucional publicada en este momento.`

**Cómo comprobarlo:** `/information`, `GET /portal/information`; comparar versión pública con `published`, no con `draft` del administrador.

**Advertencias:** no inventar contactos institucionales ni afirmar que todas las secciones existen en el deploy por tener migración local. Los enlaces son contenido; no son un módulo para subir documentos PDF.

**Estado de implementación:** Completo; dependiente de publicación y pendiente de validación en navegador/teclado.

### HU43 — Gestionar información institucional

**Objetivo de la demostración:** guardar borrador independiente, publicar cambios y gestionar una FAQ.

**Rol necesario:** solo Administrador activo.

**Datos previos:** al menos una sección existente; el código inicializa seis estructurales mediante migración, pero su existencia actual no fue consultada.

**Preparación:** elegir sección demo o contenido cuya modificación esté autorizada. Anotar versión pública previa. Si no hay sección demo modificable, limitar la escritura a una FAQ nueva y registrar el bloqueo de edición de sección; no reemplazar contenido real.

**Ruta en la web:** **Contenidos → Gestionar información** (`/admin/portal/information`).

**Pasos de demostración:**

1. Tipo **Secciones institucionales** → **Gestionar** en la sección autorizada.
2. Introducir título demo apropiado a esa sección y descripción `Contenido ficticio autorizado para demostrar información institucional.`, orden `10` si no interfiere con contenidos reales → **Guardar borrador**.
3. Antes de publicar, consultar HU42 y comprobar que no muestra los cambios nuevos.
4. Pulsar **Publicar** o **Publicar cambios** → **Confirmar operación**. Recargar HU42 y comprobar actualización.
5. Para FAQ: volver al listado, tipo **Preguntas frecuentes** → **Crear**; pregunta `¿Esta información corresponde a una demostración?`, respuesta `Sí. Es un contenido demo autorizado del Sprint 3.`, orden `10` → **Crear FAQ**.
6. Abrir la FAQ con **Gestionar**, publicar y confirmar. Consultar HU42 y desplegarla.
7. Modificar la respuesta → **Guardar borrador** → comprobar snapshot anterior → **Publicar cambios**. Tras evidencias, usar **Despublicar** y confirmar si no debe quedar pública.
8. Abrir historial para comprobar borrador/publicaciones y responsable.

**Datos de entrada:** sección: título ≤200, descripción ≤10000, hasta 20 enlaces con etiqueta ≤100 y URL válida; FAQ: pregunta ≤500, respuesta ≤10000; orden entero 0–100000. Se puede demostrar sin enlaces para evitar dependencias externas.

**Resultado esperado:** borrador separado de publicado; publicación explícita reemplaza snapshot visible; despublicar lo oculta sin borrar historial.

**Cómo comprobarlo:** indicadores Publicado/Cambios pendientes, historial y HU42. API `/admin/portal/information/sections|faqs`: PATCH contenido, POST `/{id}/publish`, POST `/{id}/unpublish`, GET `/{id}/history`; POST alta solo `/faqs`.

**Advertencias:** no existe botón/API de alta de sección estructural ni eliminación en este flujo. Los cambios de revisión protegen concurrencia; ante 409 recargar y revisar. Administración solo para ADMINISTRADOR en frontend y middleware backend.

**Estado de implementación:** Completo para edición/publicación de secciones existentes y gestión FAQ; dependiente de secciones y pendiente de validación en navegador.

### HU44 — Consultar servicios disponibles

**Objetivo de la demostración:** visualizar servicio activo, consultar detalle y abrir su URL institucional.

**Rol necesario:** Visitante o usuario autenticado; el servicio externo puede tener su propia autenticación.

**Datos previos:** servicio válido ACTIVE preparado en HU45.

**Preparación:** anotar nombre, ID y destino autorizado; mantener activo durante consulta.

**Ruta en la web:** encabezado **Servicios** (`/services`) → título de tarjeta (`/services/{id}`).

**Pasos de demostración:**

1. Como visitante abrir Servicios y localizar `DEMO S3 — Portal institucional`.
2. Verificar nombre, descripción y dominio mostrado.
3. Pulsar título para abrir detalle y después **Acceder al servicio**. En la lista la acción se llama **Abrir servicio**.
4. Comprobar nueva pestaña con el destino configurado; volver al catálogo.
5. Tras desactivar en HU45, recargar lista y detalle: deja de estar disponible públicamente.

**Datos de entrada:** selección de tarjeta; sin formularios de credenciales en EIDA para estos enlaces.

**Resultado esperado:** catálogo ordenado, solo servicios ACTIVE con URL y contenido válidos según configuración actual; abre enlace externo HTTPS en nueva pestaña.

**Cómo comprobarlo:** `GET /portal/services`, `/portal/services/{id}`; enlace con `target=_blank` y `rel=noopener noreferrer`. Comparar URL con la registrada, sin confundir carga del servicio externo con disponibilidad de EIDA.

**Advertencias:** contrato actual utiliza enlaces EXTERNAL y `requires_auth=false`; documentos viejos con destinos internos/login describen otro contrato. EIDA no comprueba mediante HTTP/DNS que el sitio externo esté disponible. Si el destino falla, registrar ese resultado sin afirmar que se validó desde la inspección estática.

**Estado de implementación:** Completo para catálogo de enlaces; dependiente de URL/configuración y pendiente de validación en navegador.

### HU45 — Gestionar catálogo de servicios

**Objetivo de la demostración:** crear inactivo, editar, activar/desactivar y consultar historial de un servicio.

**Rol necesario:** solo Administrador activo.

**Datos previos:** URL institucional HTTPS permitida en el servidor. Configuración local por defecto admite `umss.edu.bo` y subdominios, pero no se leyó `.env` ni se comprobó el valor efectivo del deploy.

**Preparación:** reutilizar un servicio demo válido o definir uno con URL `https://www.umss.edu.bo/` **solo si el deploy la admite**. Si devuelve validación por dominio, elegir un destino institucional ya admitido en el catálogo; no cambiar variables de entorno.

**Ruta en la web:** **Contenidos → Gestionar servicios** (`/admin/portal/services`).

**Pasos de demostración:**

1. Pulsar **Crear** y completar nombre `DEMO S3 — Portal institucional`, descripción `Enlace institucional usado en la demostración del catálogo.`, URL autorizada y orden `10`.
2. Pulsar **Crear servicio inactivo**; comprobar que aún no aparece al visitante.
3. Abrir **Gestionar**, cambiar descripción a `Enlace institucional demo actualizado.` → **Guardar servicio**.
4. Pulsar **Activar** → **Confirmar activación**. Ejecutar HU44.
5. Volver, **Desactivar** → **Confirmar desactivación**; comprobar que desaparece del público y permanece en administración.
6. Revisar historial; activar nuevamente solo si se necesita conservarlo visible para la demo.

**Datos de entrada:** nombre ≤200, descripción ≤2000, URL ≤2048 HTTPS en dominio autorizado, orden entero 0–100000. Texto plano, sin HTML. Frontend usa icono `link` automáticamente; no ofrece selector de iconos aunque la API admite claves semánticas.

**Resultado esperado:** alta INACTIVE; activación explícita tras validación; revisión e historial por cada modificación. Solo servicio activo y válido aparece públicamente.

**Cómo comprobarlo:** tabla/gestor, historial `GET /admin/portal/services/{id}/history`, catálogo HU44. POST alta, PATCH `/{id}` y PATCH `/{id}/status`.

**Advertencias:** servicios migrados pueden mostrar **necesita revisión** y quedar fuera del público hasta corregirse con datos autorizados; activar no omite la validación. No hay subida de archivos ni destinos arbitrarios/URLs locales en este módulo. Permisos de gestión restringidos a administrador en ambas capas.

**Estado de implementación:** Completo para el contrato actual de enlaces institucionales; dependiente de allowlist y pendiente de validación en navegador.

## PARTE C — ORDEN RECOMENDADO

Preparación A1–A10 antes de iniciar la ronda. Las duraciones son **estimaciones de interacción**, sin contar espera de horario, permisos, latencia, carga de archivos ni resolución de bloqueos. Los perfiles separados permiten mantener cuentas abiertas sin cambiar de cuenta en el navegador offline.

| Orden | HU | Rol | Acción principal | Datos reutilizados | Duración aproximada |
| --- | --- | --- | --- | --- | --- |
| 1 | 39 | Administrador | Crear/editar/publicar evento | Una categoría existente, un evento demo | 4–6 min |
| 2 | 41 | Administrador | Crear/editar/publicar noticia | Una noticia demo | 3–5 min |
| 3 | 43 | Administrador | Guardar/publicar sección y FAQ | Sección estructural y FAQ demo | 4–6 min |
| 4 | 45 | Administrador | Crear/editar/activar servicio | Un destino institucional | 3–5 min |
| 5 | 38 | Visitante | Consultar calendario y detalle | Evento de HU39 | 2 min |
| 6 | 40 | Visitante | Consultar noticia y detalle | Noticia de HU41 | 2 min |
| 7 | 42 | Visitante | Consultar sección/FAQ | Contenidos de HU43 | 2 min |
| 8 | 44 | Visitante | Abrir servicio | Servicio de HU45 | 2 min |
| 9 | 34 | Docente; catálogo ya revisado por Admin | Importar/corregir, evaluar y probar prioridad manual | A/B/C, O, E1 makeup | 10–15 min |
| 10 | 35 | Docente | Preparar/consultar distribución E2 | O, C1/C2, estudiantes con datos definitivos | 4–6 min |
| 11 | 27 | Docente | Crear dos incidencias en E1 | A y ambiente, sin consumir ingresos | 3–5 min |
| 12 | 29 | Docente | Consultar/filtrar/exportar incidencias | Registros HU27 | 3–5 min |
| 13 | 32, fase inicial | Docente | Registrar A/B manualmente y observar contadores | Preparación de ingreso HU14 y E1 | 4–6 min |
| 14 | 30 | Docente | Registrar/confirmar infracción C/E1 | Carla y E1 | 4–6 min |
| 15 | 31 | Docente | Expulsar C durante E1, opcional ingreso previo | Infracción HU30; sin tocar E2 | 3–5 min |
| 16 | 33, snapshot inicial | Docente | Generar reportes y exportar | Inscripciones, A/B válidos, HU27/30/31 | 5–8 min |
| 17 | 28; repetir 32/33 | Docente | Anular solo ingreso B y comparar consulta nueva | Ingreso B creado en fase inicial | 4–6 min |
| 18 | 36 | C1/C2; estudiante muestra QR | Descargar/capturar/persistir durante E2 | Listas HU35; E2 sin ingresos iniciales | 6–10 min |
| 19 | 37; repetir 32/33 | Propietarios; Docente prepara variante | Sincronizar y contrastar con consulta oficial | Eventos HU36; opcional cuatro resultados | 5–8 min básico / 10–15 ampliado |

Las comprobaciones de despublicar/cancelar/desactivar de HU39/41/43/45 se hacen **después** de su consulta pública correspondiente, reutilizando el mismo registro. No se necesita una segunda noticia o evento. Para reducir espera, se puede alternar HU34 con la preparación del portal antes del inicio de E1; E2 debe tener su propia ventana posterior suficiente. Capturar evidencias antes de cada acción que cambia estado.

## PARTE D — CONTROL DE INTERFERENCIAS

| Acción que altera el escenario | Reserva y efecto | Repetición segura desde la interfaz |
| --- | --- | --- |
| Inscribir/activar estudiantes | Cambia N, habilitaciones iniciales y entrada de distribución | Terminar antes de HU35; si se amplía después, no regenerar v1: otro examen demo |
| Guardar decisión manual HU12 | Cambia fuente a MANUAL; HU34 la preserva | Hacer cálculo automático primero. No hay «quitar decisión manual» encontrado; usar fila INITIALIZED/AUTOMATIC u otro examen |
| Corregir/importar notas | Datos compartidos por O; afecta próximas evaluaciones, no recalcula todas automáticamente | Solo datos demo, motivo explícito; para existentes «Corregir», no reimportar |
| Ingreso online E1 | Crea antecedente y aumenta asistencia | A queda válido de referencia; B reservado para anular; no intentar doble ingreso válido |
| Anular B/E1 | Cambia a ANNULLED y reduce asistencia, conserva historial | No anular el mismo ID otra vez; si procede, nuevo ingreso con nuevo ID y condiciones vigentes |
| Infracción C/E1 | Historial persistente; RESOLVED terminal | Consultar/confirmar antes de resolver. No fabricar registros repetidos para cada estado |
| Expulsión C/E1 | Única por examen/estudiante, sin reversión encontrada | Conservar; para nueva alta usar otro estudiante/examen autorizado. No eliminar historial |
| Preparar distribución E2 | Congela v1 con miembros/autorizaciones/examen | Si ya existe, consultar. Si incompatible, nuevo examen; no revocar/reasignar esperando regeneración |
| Descargar paquete | Crea snapshot y registro de preparación en backend/local | Nueva copia no reemplaza los paquetes usados por eventos previos; resolver cambios antes de descargar |
| Capturar offline | Un evento por propietario/examen/estudiante en ese almacenamiento, incluso terminal | Reservar miembros distintos para manual/QR. No borrar eventos pendientes para repetir |
| Sincronizar | Puede crear ingreso oficial o resultado terminal por UUID | Reintentar solo PENDING cuando falló transporte; mismo UUID devuelve resultado guardado |
| Provocar DUPLICATE B/E2 | Docente registra online después de captura local | No anular B/E2 antes de sincronizar: anulación cambiaría el caso esperado |
| Provocar CONFLICT C/E2 | Cambiar a Inhabilitado y volver Habilitado aumenta revisión | Solo después de captura. La revisión no retrocede al restaurar el estado; evento original queda en revisión |
| Provocar REJECTED D/E2 | Inhabilitar tras captura | Restituir Habilitado al finalizar si está autorizado. No convierte REJECTED en PENDING |
| Publicar/editar noticia o evento público | Puede hacerse visible inmediatamente | Consultar primero borrador; despublicar antes de preparar cambios fuera de público; reusar mismo contenido |
| Guardar información | Borrador independiente hasta publicar | Demostrar snapshot anterior; publicar explícitamente y conservar historial |
| Activar servicio | Lo publica si es válido | Desactivar al terminar si la demo no debe quedar pública; no borrar auditorías |

**Reservas:** A/E1 mantiene una asistencia de referencia; B/E1 se consume en HU28; C/E1 concentra disciplina; E2 queda sin ingresos oficiales hasta HU36/HU37. En HU37 ampliada B/E2 se registra online **después** de la captura; C/D solo cambian en E2. No tocar habilitaciones E2 mientras se prueba HU34 de E1.

**Volver a preparar sin borrar historia:** revisar qué pares examen/estudiante ya se consumieron. Si expiró examen/paquete o la distribución es incompatible, programar un nuevo examen demo futuro desde Mis materias, reutilizar O/aula/cuentas, verificar habilitaciones y colaboradores, preparar nueva distribución y descargar paquetes nuevos. Conservar los registros previos. Ese examen adicional es una necesidad de repetición, no parte del mínimo inicial. No hay una recuperación íntegra del escenario por un botón «reiniciar».

## Condiciones que deben comprobarse en el deploy

1. **Autenticación real:** confirmar sesión de cuenta ACTIVE y roles permanentes activos, primer acceso resuelto. Rutas privadas exigen Sanctum, sesión actual y contraseña inicial cambiada. El cliente usa cookies con credenciales y XSRF: login obtiene `/sanctum/csrf-cookie` y envía `/login` al backend. Comprobar que el origen desplegado admite cookies/CORS y no hay errores 419; no cambiar configuración de producción durante la demo. No usar modo mock para certificar funciones reales.
2. **Sesiones:** una cuenta tiene control de sesión vigente; usar cuentas distintas y perfiles separados para Admin, Docente, C1/C2 y estudiante. Dos pestañas de la misma sesión docente sirven para seguimiento; otro login de esa cuenta puede invalidar la anterior.
3. **Autorización de portal:** `ProtectedRoute allowedRoles=['ADMINISTRADOR']` en React y `EnsureActiveAdministrator` en `/admin/portal/*`. Docente, estudiante o colaborador por sí solos no pueden gestionar HU39/41/43/45. Las rutas públicas `/portal/*` son lectura. Para comprobarlo luego, navegar con cuenta no administradora y observar denegación; no otorgar roles para hacer pasar la prueba.
4. **Horario:** usar reloj real de Bolivia. Ingreso online, captura offline y sincronización deben ocurrir desde inicio inclusive hasta fin exclusivo; QR se obtiene desde 24 horas antes del inicio hasta fin. Preparar listas/paquetes se permite antes de iniciar, pero no con fin vencido. El estado SCHEDULED no evita el vencimiento por hora.
5. **HTTPS y cámara:** verificar contexto seguro, permiso del navegador/sistema, cámara disponible y sin otro proceso ocupándola. Usar la cámara tras activar el control y permitir acceso. Rechazo de permiso no demuestra fallo backend.
6. **PWA del deploy:** la caché `eida-offline-shell-*` y `offline-sw.js` se generan en el empaquetado existente. Este código solo admite despliegue en raíz `/`; no subruta. Confirmar en Application → Service Workers que el worker está activo y en pantalla Apertura offline. **No ejecutar un build ahora.** Vite de desarrollo no instala el worker de producción.
7. **Navegación offline:** fallback del worker solo para `/offline` y `/exams/{id}/offline`. No prometer abrir `/login`, `/teacher/exams` u otras páginas sin conexión. La caché guarda recursos públicos de aplicación; excluye respuestas API, autenticación y fotos. Deben existir rewrite/fallback del hosting para las rutas React online.
8. **Almacenamiento:** IndexedDB bajo el origen exacto WEB, `sessionStorage` para continuidad y caché de recursos son distintos. No navegar a otro dominio/puerto, limpiar datos, usar modo privado efímero ni cerrar la pestaña durante la captura. Almacenamiento denegado o sin espacio bloquea guardado. La identidad local no sustituye validación del servidor al sincronizar.
9. **Red:** DevTools Offline simula falta de red en ese navegador; la desconexión física comprueba el dispositivo. «Red disponible» solo refleja conectividad local. No hay background sync/reintentos automáticos en HU37. Si hay 401, recuperar sesión del mismo propietario con red; conservar eventos.
10. **CSV:** UTF-8, coma, punto decimal y encabezado exacto de la **interfaz** HU34; no exportar desde Excel con punto y coma. No confundir CSV académico humano, CSV API y CSV de habilitación manual HU12 (`sis_code,status,reason_code,observations`). La importación de notas es preview+confirmación y no actualiza datos existentes por sobrescritura.
11. **Fotos:** foto de perfil y foto histórica/institucional son flujos distintos. El Administrador dispone de perfil de estudiante → sección de foto histórica; las URLs autenticadas se sirven por endpoint. Una ruta `C:\...` o un archivo `public/demo-students/...` local no demuestra que el servidor lo tenga. El perfil usa también URLs `/storage/...`: comprobar respuesta y persistencia en hosting. Nada de eso se precarga en paquete offline.
12. **Noticias e imágenes:** subida JPEG/PNG/WebP ≤5 MiB por endpoint administrativo; comprobar visualización pública después de publicar y después de recarga. No asumir permanencia de archivos en disco efímero. Historial de noticia registra cambios, no restaura binarios eliminados. Información/servicios no tienen subida de documentos en este contrato.
13. **Exportaciones:** permitir descargas del navegador. HU33 necesita snapshot en caché de backend durante 30 minutos; verificar ID/fecha y formatos en la descarga. Configuración local usa caché `file` por defecto; no se inspeccionó su estado en producción. Un reporte ya generado conserva snapshot anterior aunque se anule un ingreso; generar otro para comparar situación nueva.
14. **Contenido externo:** URL de servicio debe pasar allowlist efectiva del servidor. No cambiar `.env` para permitir el ejemplo. La disponibilidad, permisos y sesiones de la web externa son independientes.
15. **Datos compartidos:** toda escritura de esta guía deja antecedentes. Confirmar internamente que los registros son demo autorizados antes de ejecutar. No desactivar periodo real, modificar criterios globales reales, cambiar estudiantes reales ni publicar textos de ensayo sobre contenido institucional importante.

## Evidencia técnica: trazabilidad de extremo a extremo

Los caminos frontend de esta tabla son relativos a `oidevs-examenes-frontend/`; los backend, a `oidevs-examenes-backend/`. Todos los endpoints están declarados en `routes/api/v1.php` o `routes/api/eligibility-automation.php`. Navegación y protección: `src/app/router/router.tsx`, `src/shared/components/Header/headerNavigation.config.ts`. La tabla documenta código inspeccionado, sin ejecutar endpoints.

| HU | Frontend principal | Endpoint(s) consumidos | Backend: controlador → servicios; modelos/tablas |
| --- | --- | --- | --- |
| 27 | `src/features/incidents/components/IncidentForm.tsx`, `IncidentControlDialog.tsx`, `pages/CreateIncidentPage.tsx`; integración `src/features/exams/pages/EntryControlPage.tsx` | POST `/exams/{id}/incidents`; POST `/exams/{id}/entry-control/qr` para origen automático | `Incidents/IncidentController`, `Entries/QrEntryController` → `IncidentRegistrationService`, `IncidentAccessService`, `QrEntryService`, `WrongExamAuditService`; `SpecialCase`, `Exam`, `Student`, `ExamCollaborator`, `AuditLog` |
| 28 | `src/features/entry-annulments/components/AnnulEntryConfirmation.tsx`, `api/entryAnnulmentApi.ts`; `src/features/exams/components/EntryHistoryModal.tsx` | POST `/exams/{id}/entry-control/entries/{entry}/annulment`; GET `/entry-control/entries` bajo examen | `Entries/EntryAnnulmentController` → `EntryAnnulmentService`, `EntryAnnulmentAccess`, `EntryHistoryPresenter`; `Exam`, `User`, tabla `exam_entries`, `AuditLog` (no se encontró modelo Eloquent ExamEntry) |
| 29 | `src/features/incidents/pages/AdminIncidentsPage.tsx`, `ExamIncidentConsultation.tsx`, componentes de filtros/detalle/exportación y APIs de consulta | GET `/admin/incidents/exams`; GET `/exams/{id}/incidents`, `/{incident}`, `/export` | `IncidentController`, `ExamIncidentReportController` → `IncidentQueryService`, `IncidentDataset`, `IncidentFilters`, `ExamIncidentExport`; `SpecialCase`/`special_cases` y `audit_logs` para QR, `Exam`, `Student` |
| 30 | `src/features/infractions/pages/ExamInfractionsPage.tsx`, `CreateInfractionPage.tsx`, `InfractionDetailPage.tsx`; `InfractionForm`, `InfractionStatusForm`, `InfractionAuditTrail` | GET `/exams/{id}/infractions/{options,students}`; POST lista; GET detalle/history; PATCH `/{infraction}/status` | `Infractions/InfractionController` → `InfractionService`, `InfractionAccess`, `InfractionRevision`; `Infraction`, `Student`, `Exam`, `AuditLog` |
| 31 | `src/features/expulsions/pages/*`, `components/ExpulsionForm.tsx`, `RelatedInfractionPicker.tsx`, `StudentExpulsionHistory.tsx` | `/exams/{id}/expulsions`: GET lista/students/eligible-infractions/historial/detalle; POST alta | `Expulsions/ExpulsionController` → `ExpulsionRegistrationService`, `ExpulsionQueryService`, `ExpulsionAccess`, `ExamEntryAvailability`; `Expulsion`, `Infraction`, `Student`, `Exam`, `AuditLog` |
| 32 | `src/features/attendance/pages/AttendancePage.tsx`, `components/AttendanceSummary.tsx`, `AttendanceActivity.tsx`, `hooks/useAttendanceFeed.ts`, `api/attendanceApi.ts` | GET `/exams/{id}/attendance/students`, `/attendance/collaborators` | `Attendance/AttendanceController` → `AttendanceService`, `AttendanceCollaboratorActivity`, `AttendanceAccess`; `Exam`, `ExamCollaborator`, `User`; tablas `enrollments`, `students`, `exam_entries` |
| 33 | `src/features/exam-reports/pages/ExamReportsPage.tsx`, `components/ReportWorkspace.tsx`, `ReportExamCatalog.tsx`, `ReportExportActions.tsx`, `api/examReportsApi.ts` | GET `/exam-reports/catalog`; GET `/exams/{id}/reports/{type}` y `/export` | `Reports/ExamReportController` → `ExamReportService`, `ExamReportAccess`, `ExamReportCatalog`, `ExamReportDataset`, `ExamReportSnapshots`, `ExamReportExport`, `TextReportPdf`; `Exam`, `CourseOffering`, `AcademicTerm`; snapshots en caché, datos de ingresos/incidencias/disciplinarios |
| 34 | `src/features/eligibility-automation/pages/AdminEligibilityPoliciesPage.tsx`, `EligibilityAutomationPage.tsx`, `components/AcademicCsvImportPanel.tsx`, `utils/academicCsv.ts`, API; `src/features/eligibilities` para prioridad manual | `/admin/eligibility-criteria`; `/exams/{id}/eligibility-automation/options`, `/configuration`, `/evaluations/preview`, `/evaluations/{preview}/confirm`, `/evaluations`; `/academic-offerings/{O}/academic-records`, `/import-schema`, `/imports/preview`, `/imports/{preview}/confirm`, `/{record}`, `/history` | Controladores `Eligibility/EligibilityCriterionController`, `EligibilityAutomationController`, `EligibilityEvaluationController`, `AcademicRecordController` → `GlobalCriteriaCatalog`, `CriteriaCatalog`, `ConfigurationService`, `AcademicDataService`, `CriteriaEvaluator`, `EvaluationService`, `AutomationSupport`, `ExamEligibilityService`; `AcademicRecord`, `AcademicImport`, `EligibilityPolicy`, `EligibilityEvaluation`, `ExamEligibility`, tablas `eligibility_criteria`, `exam_eligibility_configurations`, `exam_eligibility_criteria`, `student_criterion_results` |
| 35 | `src/features/offline-entry/pages/TeacherDistributionPage.tsx`, `OfflineAssignmentCards`, `AssignedListStudentsView`, `hooks/useOfflineAssignments.ts` | POST `/exams/{id}/offline/prepare`; GET `/offline/assignments` | `Entries/OfflineExamController` → `OfflineAssignmentService`, `OfflineExamAccess`; `ExamOfflineDistribution`, `ExamOfflineList`, `ExamCollaborator`, `Exam`, tabla `exam_offline_members` |
| 36 | `src/features/offline-entry/pages/OfflineEntryPage.tsx`, `OfflineHomePage.tsx`, `components/OfflinePreparationPanel.tsx`, `OfflineCapturePanel.tsx`, `OfflineQrScanner.tsx`, `storage/offlineDatabase.ts`, `offlineStore.ts`, `services/offlineIdentity.ts`, `offlinePwa.ts`; `build/offlinePwa.ts` | GET `/exams/{id}/offline/package`; captura local IndexedDB; QR real GET `/students/exams/{id}/qr` | `Entries/OfflineExamController` → `OfflinePackageService`, `OfflineExamAccess`, `EntryControlService`; `ExamOfflinePackage`, `ExamOfflineList`, `ExamOfflineDistribution`, `ExamEligibility`, tablas de inscripciones/ingresos; QR: `StudentQrService`, `StudentQrToken` |
| 37 | `src/features/offline-entry/components/OfflineSyncPanel.tsx`, `hooks/useOfflineSync.ts`, `services/offlineSync.ts`, `api/offlineApi.ts` | POST `/exams/{id}/offline/sync` | `Entries/OfflineExamController` → `OfflineSyncService`, `OfflineExamAccess`, `QrTokenValidator`, `EntryControlService`; modelos de paquetes/listas/distribución, `Student`, `StudentQrToken`, `Exam`, `User`, tablas `exam_offline_sync_events`, `exam_entries`, `AuditLog` |
| 38 | `src/features/portal-calendar/pages/CalendarPage.tsx`, `components/InstitutionalCalendar.tsx`, `PublicEventDialog.tsx`, `EventDetails.tsx` | GET `/portal/events`, `/{id}`, `/portal/event-categories` | `Portal/PortalCalendarController` → `PortalCalendarService`; `PortalEvent`, `PortalEventCategory` |
| 39 | `src/features/portal-calendar/pages/AdminCalendarPage.tsx`, `components/AdminEventDialog.tsx`, `EventForm.tsx`, API de calendario | `/admin/portal/events`: GET/POST, PATCH `/{id}`, PATCH `/{id}/status`; GET history/categories | `PortalCalendarController`, `PortalHistoryController` → `PortalCalendarService`, `AuditLogService`; `PortalEvent`, `PortalEventCategory`, `AuditLog` |
| 40 | `src/features/portal-news/NewsPage.tsx`, `NewsList.tsx`, `NewsImage.tsx`, `newsApi.ts` | GET `/portal/news`, `/{id}`, `/{id}/image` | `Portal/PortalNewsController` → `PortalNewsService`; `PortalNews` |
| 41 | `src/features/portal-news/AdminNewsPage.tsx`, `NewsEditor.tsx`, APIs/history | `/admin/portal/news`: POST, PATCH `/{id}`, PATCH `/{id}/status`, POST/DELETE `/{id}/image`, GET historial | `PortalNewsController`, `PortalHistoryController` → `PortalNewsService`, `AuditLogService`; `PortalNews`, `AuditLog`; filesystem de imágenes |
| 42 | `src/features/portal-information/InformationPage.tsx`, `informationApi.ts` | GET `/portal/information` | `Portal/PortalInformationController` → `PortalInformationService`; `PortalInformationContent` |
| 43 | `src/features/portal-information/AdminInformationPage.tsx`, `InformationEditor.tsx`, API/history | `/admin/portal/information/{sections|faqs}` y operaciones `/{id}`, `/publish`, `/unpublish`, `/history`; POST solo faqs | `PortalInformationController`, `PortalHistoryController` → `PortalInformationService`, `AuditLogService`; `PortalInformationContent`, `AuditLog` |
| 44 | `src/features/portal-services/ServicesPage.tsx`, `ServiceCard.tsx`, `servicesApi.ts` | GET `/portal/services`, `/{id}` | `Portal/PortalServiceController` → `PortalServiceCatalog`; `PortalService`; `InstitutionalServiceUrl`/config allowlist |
| 45 | `src/features/portal-services/AdminServicesPage.tsx`, `ServiceEditor.tsx`, API/history | `/admin/portal/services`: POST, PATCH `/{id}`, PATCH `/{id}/status`, GET historial | `PortalServiceController`, `PortalHistoryController` → `PortalServiceCatalog`, `AuditLogService`; `PortalService`, `AuditLog`; reglas `PortalServicePlainText`, `InstitutionalServiceUrl` |

Los controladores de la tabla están bajo `app/Http/Controllers/Api/V1/`. Los servicios bajo `app/Services/` en sus módulos correspondientes; los de automatización bajo `Eligibility/Automation/`; modelos bajo `app/Models/`. Las validaciones decisivas se leyeron en `app/Http/Requests/{Incidents,Entries,Infractions,Expulsions,Attendance,Exams,Api/V1/Portal}` y en los servicios de automatización. Los permisos no se infieren solamente por la presencia de enlaces.

## Checklist previo a la ejecución manual

- [ ] Frontend y backend del deploy responden; origen WEB anotado.
- [ ] Entorno compartido y registros demo autorizados identificados.
- [ ] Administrador, Docente, C1 y C2 con cuentas/roles activos y primer acceso resuelto.
- [ ] A/B/C verificados por SIS desde web; D verificado si se cubrirán cuatro resultados offline.
- [ ] Periodo, materia y O activos; docente titular correcto; N real anotado.
- [ ] Estudiantes con inscripción ACTIVE; colaboradores sin inscripción activa en O.
- [ ] Aula disponible e intervalos de E1/E2 sin solapamiento.
- [ ] E1 makeup y E2 partial con IDs y ventanas de Bolivia anotados.
- [ ] HU34 puede usar decisiones INITIALIZED/AUTOMATIC; notas y catálogo revisados.
- [ ] Formato CSV humano correcto y sin registros ya existentes para importar.
- [ ] Habilitaciones E2 ELIGIBLE definitivas antes de paquetes.
- [ ] C1/C2 asignados antes de preparar listas; distribución existente revisada o nueva v1 preparada.
- [ ] Se comprobaron miembros/SIS y suma N en listas; no solo rangos de apellidos.
- [ ] Cámara y HTTPS disponibles; QR auténtico de E2 accesible en otra pantalla.
- [ ] Paquetes en IndexedDB, propietarios correctos y PWA/Apertura offline confirmada.
- [ ] Navegadores separados por cuenta; pestañas offline se mantendrán abiertas.
- [ ] Tiempo suficiente para capturar y sincronizar antes del fin del examen.
- [ ] Categoría de evento activa y sección institucional autorizada disponibles.
- [ ] Evento, noticia, información y servicio demo preparados para publicación/consulta.
- [ ] Destino HTTPS institucional aceptado; imágenes/descargas funcionan si se incluyen.
- [ ] Evidencias iniciales de asistencia/reportes capturadas antes de anular/expulsar.
- [ ] HU27, HU28, HU29, HU30, HU31, HU32 y HU33 listas para comprobar con limitaciones anotadas.
- [ ] HU34, HU35, HU36 y HU37 listas para comprobar con datos/ventanas/paquetes correctos.
- [ ] HU38, HU39, HU40, HU41, HU42, HU43, HU44 y HU45 listas para comprobar con contenidos demo.

## Matriz para registrar resultados QA

Estados permitidos: **PASS, FAIL, BLOCKED, PARTIAL, NOT RUN**. Registrar qué subcasos se ejecutaron y qué límites siguen pendientes; nunca convertir inspección estática en PASS. Evidencia sugerida: captura sin credenciales/tokens, ID del registro y hora institucional; para exportación, nombre de archivo e ID de snapshot.

| HU | Estado QA | Observación | Evidencia |
| --- | --- | --- | --- |
| HU27 | NOT RUN | | |
| HU28 | NOT RUN | | |
| HU29 | NOT RUN | | |
| HU30 | NOT RUN | | |
| HU31 | NOT RUN | | |
| HU32 | NOT RUN | | |
| HU33 | NOT RUN | | |
| HU34 | NOT RUN | | |
| HU35 | NOT RUN | | |
| HU36 | NOT RUN | | |
| HU37 | NOT RUN | | |
| HU38 | NOT RUN | | |
| HU39 | NOT RUN | | |
| HU40 | NOT RUN | | |
| HU41 | NOT RUN | | |
| HU42 | NOT RUN | | |
| HU43 | NOT RUN | | |
| HU44 | NOT RUN | | |
| HU45 | NOT RUN | | |

## Problemas, límites y dudas detectados en el código

1. **HU32 no integra las listas HU35:** `AttendanceService` fija listas exclusivas no disponibles y `AttendanceCollaboratorActivity` no calcula denominadores/progreso por lista. `AttendanceRequest` no admite `list_id`. Se puede demostrar asistencia global y por verificador; no progreso oficial por lista asignada. No se corrigió.
2. **HU37 sin resolución web:** `OfflineSyncPanel` declara que no resuelve ni convierte conflictos/rechazos en aceptados; el envío solo incluye PENDING. La parte «resolver conflictos» no puede demostrarse todavía desde esta interfaz. Una nueva captura requiere un nuevo contexto permitido/escenario; no editar ni borrar el evento original como solución.
3. **HU30 edición de contenido sin editor conectado:** `InfractionService::update` soporta tipo/descripción/observaciones, pero `InfractionDetailPage` conecta solo actualización de estado. No hay pasos web válidos para editar esos campos tras el alta.
4. **Breadcrumb roto de HU35:** en `TeacherDistributionPage`, la vista de miembros enlaza `/teacher/exams/{id}/distribution`; el router registra `/teacher/exams/{id}/offline/assignments`. Usar el botón de retorno de la vista o la ruta correcta, sin afirmar que aquel enlace funciona.
5. **Listas v1 congeladas:** no hay regeneración ni reasignación. Una distribución previa incompatible bloquea esta repetición; un nuevo examen demo es la alternativa web. Cuando hay colaboradores, el docente no tiene lista propia y descargar paquete con su cuenta puede dar 403; debe supervisar assignments.
6. **Criterios y documentos antiguos:** `SPRINT3_BACKLOG_V2_ALIGNMENT.md` aún dice HU34 fuera de implementación; el código actual sí tiene catálogo, vistas y servicios. Documentación antigua de servicios describe rutas internas/login que ya no gobiernan el contrato actual EXTERNAL. Se priorizó código vigente.
7. **HU34 dato faltante no es decisión PENDING persistida:** `EvaluationService` conserva habilitación efectiva previa y solo aplica cálculos concluyentes sin fuente manual. La inicialización por defecto es ELIGIBLE; por eso C puede quedar habilitado aunque el cálculo indique PENDING. Esta diferencia debe mostrarse y ser aceptada por QA/negocio antes de considerar cubierto un criterio que esperara bloquear por falta de notas.
8. **HU34 fuente manual no se borra en el flujo web:** guardar decisiones de A/B/C antes de la automatización impide observar aplicación automática. La guía ejecuta primero HU34. No se encontró una acción «volver a automático» para restaurar esa fuente; no se propone SQL.
9. **CSV web y CSV API diferentes:** la web usa `sis,nombre_completo,nota_pp,nota_sp,asistencias,total_clases` y convierte al formato API. Campo de nota vacío significa No rindió, que falla criterios; no significa ausencia de información. Usar ausencia de registro/«Sin dato» para el caso pendiente.
10. **Expulsión sin consecuencia automática:** no elimina asistencia, no requiere ingreso previo en el servicio y `EntryControlService` no añade `is_expelled` a los blockers. Demostración cubre registro y trazabilidad; un bloqueo automático por expulsión necesitaría definición/implementación adicional.
11. **Categorías de evento:** API revisada solo consulta categorías y no ofrece alta administrativa. Si no hay categoría activa en el deploy, el alta de eventos queda BLOCKED; no resolver con seeder, migración o SQL en esta tarea.
12. **Secciones institucionales:** se inicializan estructuralmente en migración y solo se editan/publican en la web; no hay alta de secciones. Si faltan, registrar inconsistencia de datos; FAQ sí tiene alta independiente.
13. **Datos actuales desconocidos:** A/B/C y el resto son candidatos del código/documentación; no se comprobó su existencia actual ni credenciales. Reutilizar ofertas FULLQA solo si estado, titular, padrón y horario reales lo permiten. Más estudiantes existentes significan N mayor y distribución distinta.
14. **PWA y continuidad:** persistencia de IndexedDB no garantiza acceso offline después de cerrar navegador, perder sessionStorage o superar 24h. El fallback offline es limitado a sus dos rutas; no es una copia offline completa del portal. Producción/HTTPS/cache completa deben comprobarse en el navegador real.
15. **Horario de sincronización:** una captura válida localmente no garantiza aceptación tras el fin. El servidor vuelve a validar disponibilidad y vigencia al recibirla. No hay carga retroactiva aceptada solo por declarar una hora anterior.
16. **Almacenamiento en deploy no verificado:** fotos/noticias y caché de reportes dependen de filesystem/configuración efectivos; ningún archivo o ruta local certifica su disponibilidad remota. No se leyeron secretos ni se alteró configuración.
17. **Noticias públicas sin buscador:** `NewsList` consulta con `search=''`; la búsqueda existe en la API y en gestión administrativa, pero no se puede demostrar búsqueda pública mediante un control de esta pantalla. HU40 sí permite listado, paginación y detalle; si sus criterios de aceptación exigen buscador público, registrar esa parte como pendiente de implementación.

El documento es el único archivo producido por esta tarea. No se modificaron funcionalidades para resolver estas observaciones.
