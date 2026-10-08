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
    "assigned_at": "2026-10-08T21:30:00.000000Z",
    "assigned_by": 3,
    "assigned_by_name": "Héctor Pérez"
  }]
}

Devuelve autorizaciones vigentes. Los datos del listado permiten mostrar el detalle sin otra petición.
El frontend puede filtrar el listado por nombre, CI o correo.

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