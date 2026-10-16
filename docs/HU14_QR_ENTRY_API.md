# HU14 — Registro de ingreso mediante QR

Actualización de integración HU18: [disponibilidad compartida](EXAM_ENTRY_AVAILABILITY.md) reemplaza la restricción previa a SCHEDULED/IN_PROGRESS. Se admite también ACTIVE legado y se usa America/La_Paz con fin excluido, conservando revocación y bloqueo transaccional de HU18.

Contrato aprobado por frontend. Base: `/api/v1`. Implementación en `Dev_Hector`.

## Actualización

```sh
git pull --ff-only origin Dev_Hector
php artisan optimize:clear
```

HU14 no agrega migraciones. Requiere las migraciones existentes, incluido `exam_id` en `student_qr_tokens` y el índice único parcial de ingresos válidos por examen/estudiante.

## Permisos

Ambas rutas requieren Sanctum, sesión vigente, contraseña inicial cambiada y `verify.exam.access`: docente responsable con rol activo o colaborador vigente del examen según regla C. La revocación y modificación manual de exam_id no permiten acceso.

## Procesar QR

`POST /exams/{exam_id}/entry-control/qr`

```json
{"qr_token":"JWT completo leído del QR","room_id":2}
```

Ambos campos obligatorios; token string máximo 4096 caracteres y room_id entero de ambiente existente. No enviar imagen, base64, student_id, verificador, fecha ni mecanismo.

Se verifica el JWT HS256 con la misma clave y formato del generador existente: std, exm, exp y jti. Se exige token persistido ACTIVE, sin revocación y asociado a los mismos estudiante y examen. Tokens expirados, desconocidos, alterados o con claims inválidos son rechazados.

Se vuelven a comprobar condiciones de HU13: estudiante ACTIVE, inscripción ACTIVE, habilitación ELIGIBLE (null bloquea), ambiente coincidente y ausencia de ingreso VALID. También disponibilidad del examen conforme a la regla vigente de autorización (SCHEDULED/IN_PROGRESS y antes de su finalización). No se agrega hora mínima de ingreso; la emisión del QR conserva su ventana actual de 24 horas.

La transacción bloquea el examen para serializar registros del mismo examen, relee permisos y bloquea token, estudiante, inscripción y habilitación. El índice único parcial mantiene una sola entrada VALID por examen/estudiante. Inscripción y habilitación no se modifican.

### Respuesta autorizada: 201

```json
{
  "result":"AUTHORIZED",
  "message":"Ingreso autorizado.",
  "processed_at":"2026-10-15T12:05:00Z",
  "data":{
    "student":{"student_id":5,"first_names":"Juan","last_names":"Pérez","sis_code":"20260001","identity_number":"44556677","profile_photo_url":null},
    "student_status":"ACTIVE",
    "enrollment_status":"ACTIVE",
    "eligibility_status":"ELIGIBLE",
    "room_status":"MATCH",
    "reason_code":null,"reason":null,"observations":null,
    "entry_status":"REGISTERED",
    "blockers":[],
    "entry":{"id":12,"exam_id":10,"student_id":5,"room_id":2,"entered_at":"2026-10-15T12:05:00Z","verification_method":"QR","verified_by":3,"verified_by_name":"Héctor","status":"VALID"}
  }
}
```

Fechas en ISO 8601 UTC. profile_photo_url es null si falta foto. `student_id` identifica students.id, no users.id.

### Rechazos

Mismo envoltorio result/message/processed_at/data:

| HTTP | result | data |
| --- | --- | --- |
| 422 | INVALID_QR | null |
| 422 | WRONG_EXAM | null; sin datos de estudiantes del otro examen |
| 422 | DENIED | estudiante identificado, estados, reason/observations, blockers y entry null |
| 409 | ALREADY_REGISTERED | estudiante y registro válido existente, ALREADY_REGISTERED en blockers |

Se priorizan validación del token, correspondencia de examen, duplicado y demás condiciones. Bloqueos de HU13: STUDENT_INACTIVE, NOT_ENROLLED, ELIGIBILITY_PENDING, INELIGIBLE, ROOM_MISMATCH y ALREADY_REGISTERED. EXAM_UNAVAILABLE indica que el examen no admite nuevos ingresos. Para colaboradores sin autorización vigente se devuelve 403 antes de procesar el token.

Los errores de parámetros son 422 con `errors`, sin `result`. Autenticación 401 (incluye ACCOUNT_INACTIVE del middleware compartido), permisos 403, examen inexistente 404. No guardar el JWT en logs ni en la lista de escaneos.

## Historial persistente

`GET /exams/{exam_id}/entry-control/entries`

search opcional máximo 255 caracteres: nombres, apellidos, nombre completo, SIS o CI. page desde 1; per_page de 1 a 100, por defecto 10. Orden entered_at descendente, id descendente para desempatar. Incluye VALID y ANNULLED.

```json
{"data":[{"student":{"student_id":5,"first_names":"Juan","last_names":"Pérez","sis_code":"20260001","identity_number":"44556677","profile_photo_url":null},"entry":{"id":12,"exam_id":10,"student_id":5,"room_id":2,"entered_at":"2026-10-15T12:05:00Z","verification_method":"QR","verified_by":3,"verified_by_name":"Héctor","status":"VALID"}}],"meta":{"current_page":1,"per_page":10,"total":1,"last_page":1}}
```

meta.total representa el historial filtrado. Historial vacío devuelve data []. Un ingreso ANNULLED no bloquea un nuevo ingreso válido.

## Integración frontend

La cámara y lector pertenecen a frontend. Enviar una solicitud por lectura y evitar solicitudes repetidas mientras se procesa la anterior. Después de cada respuesta mantener disponible la cámara.

Escaneos recientes es una lista local de la sesión, formada desde las respuestas: estudiante/SIS cuando exista, result, message/blockers y processed_at. No persiste rechazos ni tokens. El historial completo corresponde a ingresos persistidos.

Tras AUTHORIZED refrescar los GET de HU13 (listado, detalle y summary) para actualizar ingreso y progreso; no calcular permisos ni sustituir validaciones en frontend.
