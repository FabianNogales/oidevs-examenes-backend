# HU15/HU16 — Contrato de colaboradores

Implementado en Dev_Hector. Base: /api/v1.

## Actualización del backend

Después de git pull --ff-only origin Dev_Hector:

    php artisan migrate
    php artisan optimize:clear

La migración conserva ID, examen, asignador, fechas y estado de las colaboraciones anteriores, convirtiendo student_id en el user_id asociado. No ejecutar migrate:fresh: elimina datos.
La reversión se impide si existen colaboradores sin perfil de estudiante, para evitar pérdida de información.

## Autenticación y permisos

Todos los endpoints requieren autenticación Sanctum (sesión SPA o Bearer) y haber completado el cambio de contraseña cuando corresponda.
POST, GET y DELETE de colaboradores exigen rol DOCENTE activo y ser el docente responsable del examen.
GET /users exige exam_id, rol DOCENTE activo, cuenta activa y ser responsable del examen; este buscador sirve al modal de HU15, no reemplaza la administración de usuarios de HU21.
GET /me/collaborations está disponible para cualquier cuenta activa autenticada, sin exigir un rol específico.
Ninguna operación modifica roles globales.

## Endpoints

### POST /exams/{exam_id}/collaborators

Body: {"user_id":5}

201:
{
  "message": "Colaborador asignado exitosamente.",
  "data": {
    "id": 12,
    "exam_id": 1,
    "user_id": 5,
    "assigned_at": "2026-10-08T21:30:00.000000Z",
    "assigned_by": 3,
    "assigned_by_name": "Héctor Pérez"
  }
}

assigned_by lo determina el backend con el usuario autenticado.
Una asignación activa duplicada devuelve 422 con errors.user_id.
Puede recibir autorización cualquier usuario con cuenta ACTIVE, independientemente de su rol. Si tiene perfil de estudiante, no puede tener inscripción ACTIVE en la oferta académica del examen. La validación se repite en el POST aunque el usuario haya aparecido antes en el buscador.
Una autorización revocada puede reasignarse: se reutiliza su registro y se actualizan asignador y fecha.

### GET /exams/{exam_id}/collaborators

200:
{
  "data": [{
    "id": 12,
    "exam_id": 1,
    "user_id": 5,
    "display_name": "Juan Pérez",
    "email": "juan.perez@univalle.edu",
    "identity_number": "44556677",
    "profile_photo_url": null,
    "assigned_at": "2026-10-08T21:30:00.000000Z",
    "assigned_by": 3,
    "assigned_by_name": "Héctor Pérez"
  }]
}

Devuelve autorizaciones vigentes. Los datos del listado permiten mostrar el detalle sin otra petición.
El frontend puede filtrar el listado por nombre, CI o correo.
profile_photo_url es string|null y usa users.profile_photo para cualquier rol:
- URL http/https existente: se devuelve directamente.
- Ruta de archivo público: se devuelve asset('storage/' + ruta), igual que en HU10/HU12.
- Sin fotografía: null; frontend usa avatar fallback.
No se necesita otro endpoint para el detalle. El entorno debe tener APP_URL y el enlace público de storage configurados.

### DELETE /exams/{exam_id}/collaborators/{user_id}

El último parámetro es el ID de users, no el ID de exam_collaborators ni de students.
200: {"message":"Autorización de colaborador revocada exitosamente."}
Revoca la autorización únicamente para ese examen y conserva el registro.
404 si no existe una asignación activa de ese usuario al examen.

### GET /me/collaborations

200:
{
  "data": [{
    "exam_id": 1,
    "exam_name": "Primer Examen Parcial",
    "subject_name": "Programación I",
    "exam_date": "2026-10-15",
    "start_time": "08:00:00",
    "duration_minutes": 90,
    "room": "Aula 101"
  }]
}

Solo muestra colaboraciones vigentes del usuario autenticado. Sin colaboraciones: {"data":[]}.
room es el nombre del ambiente, o null si no hay ambiente asociado.

### GET /users?exam_id={exam_id}&search={query}

Busca por nombre, apellidos, nombre completo, CI o correo entre usuarios activos de cualquier rol. exam_id es obligatorio; su ausencia devuelve 422. Excluye cuentas inactivas y usuarios con inscripción estudiantil ACTIVE en la oferta del examen. Los usuarios activos sin perfil estudiantil también pueden ser candidatos.
200:
{
  "data": [{
    "id": 5,
    "display_name": "Carlos Mamani",
    "email": "carlos.m@univalle.edu",
    "identity_number": "12345678"
  }]
}

Sin coincidencias: {"data":[]}. Sin search se devuelve el listado de candidatos del examen.
Los id devueltos siguen siendo IDs de users y se envían como user_id al POST. Usuarios sin perfil docente/estudiante usan su correo como display_name y identity_number:null.
Los datos administrativos y las contraseñas no se exponen.
La respuesta no está paginada, conforme al contrato acordado.

## Vigencia y control de acceso

La autorización deja de permitir acceso cuando se revoca, cuando el examen sale de SCHEDULED/IN_PROGRESS o cuando llega la fecha/hora de inicio más duration_minutes.
Las colaboraciones antiguas que ya no cumplen la nueva regla no habilitan acceso al control ni aparecen en /me/collaborations; sus registros se conservan para poder revocarlos.
Las fechas del examen se interpretan con APP_TIMEZONE del backend; assigned_at se serializa en UTC.
El middleware verify.exam.access valida usuario, examen y autorización vigente; la comparación del docente responsable usa teachers.user_id.
Este cambio prepara la autorización para las herramientas de HU13/HU14. No incorpora nuevos endpoints de verificación o escaneo QR.

Las rutas anteriores /student/collaborations y la semántica DELETE por ID de colaboración se sustituyen por este contrato.

## Errores

- 401: sin autenticación.
- 403: permisos insuficientes, cuenta inactiva o cambio de contraseña pendiente.
- 404: examen o autorización activa inexistente.
- 422: payload inválido, usuario inexistente/inactivo, duplicado o examen no disponible.

## Verificación

    php artisan test
    php artisan route:list --path=api/v1

Las pruebas de colaboradores mantienen habilitados los middleware y cubren asignación de usuarios activos de distintos roles, docentes que colaboran en otro examen, rechazo de cuentas inactivas y estudiantes inscritos, datos del contrato, búsqueda, permisos del docente responsable, aislamiento entre usuarios/exámenes, duplicados, reasignación, revocación, vencimiento y migración de registros anteriores.

## Regla C vigente de HU15/HU16

Cualquier usuario registrado con cuenta ACTIVE puede colaborar, sin cambiar su rol permanente.
La restricción estudiantil se verifica contra enrollments de la course_offering_id del examen y el perfil de estudiante asociado al usuario. Si tiene inscripción ACTIVE, se excluye incluso si además tiene otro rol; poseer otro rol no elimina su condición de estudiante inscrito.
Para usuarios sin perfil de estudiante no aplica esta restricción. No se exige un rol global específico para ser candidato.
Una inscripción INACTIVE o una inscripción en otra oferta no impide ser candidato.
En esta versión no existe un registro de notas/aprobación: no se exige una aprobación para colaborar ni se infiere una nota desde el estado INACTIVE. Un estudiante que aprobó y ya no tiene inscripción activa puede ser candidato.
Se mantiene user_id en el contrato y /me/collaborations accesible sin restricción de rol global. Un docente puede conservar sus exámenes y consultar adicionalmente sus colaboraciones en exámenes ajenos. La colaboración no le permite administrar colaboradores de esos exámenes.
Este ajuste no modifica inscripciones, roles, estados de estudiantes ni otras HUs, y no requiere una migración adicional.

## FIX-010: trazabilidad de períodos administrativos

Se conserva una única fila operativa por `(exam_id, user_id)`, la reutilización al reasignar y los contratos HTTP anteriores. No hay migraciones, tablas nuevas ni historial visual. Los permisos siguen dependiendo de la asociación operativa ACTIVE, la Regla C y la disponibilidad compartida vigente (incluye ACTIVE legado, America/La_Paz y fin excluido). La auditoría no concede acceso.

Cada transición confirmada genera un evento mediante `AuditLogService`:

| Acción | Identificador del período | Efecto operativo |
| --- | --- | --- |
| EXAM_COLLABORATOR_ASSIGNED | ID persistido de este evento de apertura | Primera asignación |
| EXAM_COLLABORATOR_REASSIGNED | ID persistido de esta nueva apertura | Reutilización de fila no activa |
| EXAM_COLLABORATOR_REVOKED | `new_values.opening_event_id` | Cierre de la asociación activa |

`user_id` del evento identifica al actor autenticado; `entity_type` es `ExamCollaborator` y `entity_id` es el ID de la fila operativa. Los snapshots explícitos contienen únicamente `exam_id`, `collaborator_user_id`, `status`, `assigned_by`, `assigned_at` y `revoked_at`. La fecha de transición es `audit_logs.created_at`, generada por el servidor; se conservan IP y agente de usuario habituales. El cierre añade `opening_event_id` e `history_complete`, relativos a ese período. No se almacena access_code_hash, contraseñas, sesiones, JWT, CI, SIS, nombres ni correo en el payload.

La apertura es el propio evento: no se actualiza un registro de auditoría para referenciarse a sí mismo. El cierre utiliza el ID de la última transición compatible, únicamente si es una apertura cuyo snapshot coincide con la fila actual. La comparación no depende del orden de claves de JSONB ni relaciona períodos solo por timestamps. Dos períodos en el mismo segundo mantienen IDs de apertura diferentes.

Una fila previa sin apertura comprobable se revoca registrando actor y fecha actuales, `opening_event_id: null` e `history_complete: false`. Se preservan las fechas preexistentes observadas, sin inventar responsables o episodios. En una reasignación, `old_values` representa solamente el estado previo observado, no una reconstrucción completa de su historia. No hay inicialización automática, backfill ni eventos por lecturas, duplicados rechazados, cancelaciones o cambios ajenos a este servicio.

### Atomicidad y errores

La mutación y su evento se confirman en la misma transacción. Ante un fallo técnico se revierte todo antes de devolver HTTP 500 con `code: INTERNAL_ERROR` y este mensaje seguro:

> No se pudo confirmar la operación de colaboración. No se aplicaron cambios; la autorización conserva su estado anterior. Intenta nuevamente.

La alerta técnica contiene solo el tipo de excepción, sin SQL, bindings ni mensajes internos. Una revocación fallida puede dejar vigente el acceso: no se informa éxito y se permite reintentar. No se aplica la política best-effort de FIX-009. El frontend actual mantiene el modal abierto y muestra su mensaje de fallo; no debe emitir la notificación de revocación exitosa.

### Bloqueos y solicitudes concurrentes

Asignación y revocación bloquean primero el examen y después la asociación, con revalidación del actor y del estado definitivo. Se conserva 201 al asignar, 422 por duplicado, 200 al revocar y 404 cuando no existe la asociación activa correspondiente. Revocar sigue siendo posible si el examen ya no está disponible.

Antes de esperar el bloqueo, la revocación toma una lectura sin bloqueo del período observado. Bajo bloqueo compara su ID y el hash rotativo existente, exclusivamente en memoria, para rechazar con 404 una solicitud que observó un período sustituido. El hash no se añade al historial ni al contrato HTTP. Esto protege solicitudes concurrentes que ya observaron una asociación; el endpoint por user_id no contiene una versión del modal para distinguir intenciones antiguas que lleguen al servidor después de una reasignación.

QR y manual conservan su implementación y orden de bloqueos. Una operación que termina antes de la revocación puede conservar su asistencia válida; después de la revocación confirmada, las nuevas operaciones del colaborador reciben 403. Una colaboración distinta y los roles base permanecen intactos. No hay bloqueos globales nuevos.

### Verificación focalizada

`CollaborationEpisodesTest` cubre ciclos en el mismo segundo, actores, correlación, legado incompleto, privacidad, fallos SQL y rollback, roles, dos exámenes, permisos e intercalado simulado. La simulación SQLite no acredita bloqueos PostgreSQL.

`CollaborationConcurrencyTest` y su worker están protegidos por opt-in `EIDA_FIX010_PG_CONCURRENCY=1`, APP_ENV testing, PDO PostgreSQL, nombre de base `fix010_test_` seguido de 12 caracteres hexadecimales, ausencia de DB_URL, caché/sesiones array, configuración sin cachear y almacenamiento temporal separado. Solo ese entorno aislado puede refrescarse por la prueba. Cubren dos asignaciones/revocaciones, revocación y reasignación, QR/manual en ambos órdenes, cancelación, inactivación e independencia de exámenes. Sin un PostgreSQL aislado verificado se omiten y quedan pendientes; no deben apuntarse a desarrollo o producción.

El historial permanece destinado a consultas internas futuras con permisos específicos. No se añade API de lectura, retención automática, columnas de interfaz ni modificaciones al historial académico de asistencias.
