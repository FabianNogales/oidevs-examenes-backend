# HU13 — Consulta de control de ingreso

Actualización de integración HU18: ver [disponibilidad compartida](EXAM_ENTRY_AVAILABILITY.md). Se agregan exam.entry_available, exam.entry_unavailable_reason y EXAM_UNAVAILABLE a blockers; la disponibilidad de ingreso se evalúa con estados acordados y America/La_Paz, con registro desde el inicio programado. Se conservan campos y permisos históricos; el significado oficial de Pendiente se detalla más abajo.

Base: /api/v1. Contraste oficial y cambios de contrato: [alineación Sprint 2](SPRINT2_OFFICIAL_QA_ALIGNMENT.md).
No registra ingresos, modifica habilitaciones ni evalúa un QR; esas operaciones corresponden a HU14.

## Actualización

    git pull --ff-only origin Dev_Hector
    php artisan optimize:clear

HU13 no agrega migraciones. Si el entorno no tiene las migraciones anteriores, ejecutar php artisan migrate.
Main incorpora HU17 y otras HUs del equipo. Sigue pendiente en la migración de HU17 la normalización de AVAILABLE antes del CHECK PostgreSQL; este cambio no modifica esa migración ni normaliza datos de ambientes.

## Permisos

Autenticación Sanctum, sesión vigente y cambio inicial de contraseña completado.
Solo docente responsable con rol DOCENTE activo o colaborador vigente para el examen.
Se conserva la regla C: cuenta activa de cualquier rol puede colaborar salvo inscripción estudiantil activa en esa oferta.
Al revocar, cancelar/finalizar el examen o vencer su horario, el colaborador pierde acceso.
El docente responsable puede consultar sus exámenes anteriores; la consulta no autoriza ingresar fuera de horario.
La autorización se aplica a ambas rutas antes de validar sus parámetros; cambiar exam_id no permite consultar exámenes ajenos.

## Listado

GET /exams/{exam_id}/entry-control/students

Parámetros opcionales:
- search: nombre, apellido, nombre completo, SIS o CI; máximo 255 caracteres.
- page: entero desde 1, por defecto 1.
- per_page: entero entre 1 y 100, por defecto 10.
- room_id: ID de ambiente existente. Si se omite, se usa exams.room_id.

200:
{
  "exam":{
    "id":10,
    "name":"Primer Examen Parcial",
    "subject_name":"Programación I",
    "exam_date":"2026-10-15",
    "start_time":"08:00:00",
    "duration_minutes":90,
    "evaluation_type":"partial",
    "status":"SCHEDULED",
    "room":{"id":2,"code":"A-101","name":"Aula 101","location":"Edificio Central"}
  },
  "summary":{"total_students":30,"registered":10,"pending":20,"not_applicable":0},
  "data":[{
    "student_id":5,
    "first_names":"Carlos",
    "last_names":"Pérez",
    "sis_code":"202300001",
    "identity_number":"12345678",
    "profile_photo_url":null,
    "student_status":"ACTIVE",
    "enrollment_status":"ACTIVE",
    "eligibility_status":"ELIGIBLE",
    "reason_code":null,
    "reason":null,
    "observations":null,
    "assigned_room_id":2,
    "controlled_room_id":2,
    "room_status":"MATCH",
    "entry_status":"PENDING",
    "entry":null,
    "meets_entry_conditions":true,
    "blockers":[]
  }],
  "meta":{"current_page":1,"per_page":10,"total":30,"last_page":3}
}

El listado incluye únicamente estudiantes con inscripción ACTIVE en la oferta del examen. Una habilitación aislada no basta. Cada estudiante aparece una vez.
Orden estable por student_id. La búsqueda ignora mayúsculas y contempla palabras del nombre completo, SIS y CI.
SIS y CI se devuelven como texto, conservando ceros iniciales.
summary se calcula sobre todos los estudiantes con inscripción ACTIVE del listado, sin búsqueda ni página. No depende del ambiente seleccionado.
meta.total cuenta resultados filtrados. Una búsqueda sin resultados o página fuera de rango devuelve data:[] con los contadores globales.
La columna # puede calcularse como (current_page - 1) * per_page + índice + 1.

## Detalle

GET /exams/{exam_id}/entry-control/students/{student_id}?room_id={room_id}

student_id es el ID de students.
200: {"exam":{...},"data":{...}}. data tiene los mismos campos que cada registro del listado.
Un estudiante sin asociación al examen devuelve 404 igual que uno inexistente. El detalle asociado puede explicar una inscripción histórica inactiva, sin permitir registro.
Un estudiante inexistente devuelve 404.

## Interpretación de estados

enrollment_status: ACTIVE / INACTIVE / null.
eligibility_status: ELIGIBLE / INELIGIBLE / null. null significa Sin evaluar, nunca habilitado.
room_status: MATCH / MISMATCH / NOT_APPLICABLE. Compara el ambiente enviado con el asignado al examen; no determina la ubicación física del dispositivo.
El ambiente del estudiante es el del examen si tiene asociación por inscripción o habilitación. No se crea una asignación de ambiente por estudiante.

entry_status:
- REGISTERED: existe un exam_entries con status VALID. Tiene prioridad aunque cambie posteriormente la habilitación o inscripción.
- PENDING: no existe ingreso VALID, independientemente de habilitación. No significa autorizado para ingresar.
El listado oficial solo incluye inscripción ACTIVE; summary.pending cuenta todos los no registrados y summary.not_applicable se conserva con 0.

Los ingresos anulados no cuentan como vigentes. No se mezclan ingresos de otros exámenes.
La comparación de ambiente afecta meets_entry_conditions/blockers, pero no cambia los contadores ni el hecho de que el estudiante tenga pendiente el registro.

entry es null o:
{
  "id":25,
  "entered_at":"2026-10-15T12:05:00.000000Z",
  "verification_method":"QR",
  "verified_by":3,
  "verified_by_name":"Héctor Pérez"
}

meets_entry_conditions es true cuando el examen admite ingresos, el estudiante está activo, inscrito, habilitado, tiene ambiente correcto y no tiene ingreso VALID.
Valida disponibilidad del examen: inicio programado <= ahora del servidor < fin, en America/La_Paz. QR y MANUAL revalidan al guardar; una consulta no reserva ni confirma ingreso.

blockers es una lista de {"code":"...","message":"..."}:
- STUDENT_INACTIVE
- NOT_ENROLLED
- ELIGIBILITY_PENDING
- INELIGIBLE
- ROOM_MISMATCH
- ALREADY_REGISTERED
- EXAM_UNAVAILABLE (incluye EXAM_NOT_STARTED en exam.entry_unavailable_reason)

Pueden aparecer varias condiciones a la vez.
reason_code, reason y observations conservan la información de HU12, incluidos motivos antiguos sin código.

profile_photo_url utiliza users.profile_photo: URL existente, URL pública desde ruta o null para avatar fallback.

## Errores

401: sin autenticación o cuenta inactiva según el middleware general vigente de main.
403: sin autorización para ese examen, colaboración revocada/vencida, o cambio de contraseña pendiente.
404: examen o estudiante inexistente.
422: parámetros de búsqueda/paginación/ambiente inválidos.

## Verificación

    php artisan test --filter=EntryControlTest
    php artisan test

Pruebas con middleware activo: contrato, búsqueda, paginación, resumen independiente, estados faltantes, ambientes, ingreso válido/anulado, fotografía, ausencia de escrituras, aislamiento por examen, permisos y revocación/vencimiento.

## Actualización oficial

El listado requiere inscripción ACTIVE en la oferta; una habilitación aislada no incorpora al estudiante. exam incluye entry_opens_at, entry_closes_at y server_time ISO 8601 UTC. Antes del inicio mantiene consulta y preparación autorizada, con entry_available false y EXAM_NOT_STARTED. Ver la matriz completa en SPRINT2_OFFICIAL_QA_ALIGNMENT.md.
