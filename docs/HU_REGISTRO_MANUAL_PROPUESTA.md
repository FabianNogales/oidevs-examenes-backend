# HU27 — Registrar manualmente el ingreso de estudiantes a un examen

Actualización de integración HU18: aplicar la [disponibilidad compartida](EXAM_ENTRY_AVAILABILITY.md), incluida ACTIVE legado, America/La_Paz y fin excluido. El contrato MANUAL y el historial compartido se conservan.

Estado: contrato aceptado explícitamente por frontend e implementado en Dev_Hector. El backlog oficial asigna ID 27; HU26 corresponde a auditoría. La estimación corresponde al equipo.

Actualización: `git pull --ff-only origin Dev_Hector` y `php artisan optimize:clear`. Este cambio no agrega migraciones. La confirmación de identidad y el botón corresponden a frontend; backend agrega el POST y comparte la transacción de registro con QR.

- ID: 27 (numeración oficial).
- Título: Registrar manualmente el ingreso de estudiantes a un examen.
- Estimación: pendiente de estimación por el equipo.
- Versión propuesta: 1.0.
- Actor: docente responsable o colaborador temporal vigente del examen.
- Dependencias: HU13 (búsqueda y verificación), HU14 (ingresos e historial), HU15/HU16 (autorización de colaboradores).

## Descripción

Como docente responsable o colaborador temporal autorizado de un examen, quiero buscar a un estudiante por nombre, apellido, nombre completo, SIS o CI, comprobar su identidad y registrar manualmente su ingreso, para permitir el control de entrada cuando no se utilice código QR.

El sistema reutilizará la búsqueda y el detalle de HU13. Después de verificar presencialmente la identidad del estudiante, el usuario podrá confirmar el ingreso si el estudiante está activo, tiene inscripción activa en la oferta del examen, está habilitado, corresponde al ambiente controlado y no posee un ingreso válido previo. El examen deberá estar disponible y el usuario conservar autorización vigente al guardar.

Backend volverá a validar las condiciones y registrará estudiante, examen, ambiente, fecha y hora del servidor, usuario verificador y mecanismo MANUAL. El registro compartirá el historial y la protección contra duplicados con QR. No modificará inscripción, habilitación ni roles. La confirmación manual no constituye una excepción a las condiciones de ingreso ni exige disponer de un token QR.

## Criterios de aceptación

1. Dado que el docente responsable o colaborador vigente está en Control de ingreso, cuando busca por nombre, apellido, nombre completo, SIS o CI, entonces se reutilizarán los resultados, búsqueda y paginación de HU13, sin ampliar su universo de estudiantes asociados al examen. Sin coincidencias se mostrará un estado vacío, sin crear estudiantes ni ingresos.
2. Dado que selecciona un estudiante, cuando abre su detalle, entonces se mostrarán nombre, apellidos, SIS, CI, fotografía o avatar, examen, ambiente y estados de estudiante, inscripción, habilitación e ingreso, para verificar su identidad antes de registrar.
3. Dado que el estudiante cumple las condiciones consultadas y no posee ingreso válido previo, cuando se muestra el detalle, entonces estará disponible la acción Registrar ingreso manual. Si existen bloqueos se explicarán y no se ofrecerá una confirmación habilitada; backend conservará la validación definitiva.
4. Dado que el usuario selecciona la acción, cuando continúa, entonces deberá confirmar explícitamente que verificó la identidad, visualizando nombre, SIS o CI y examen. Buscar, abrir, cerrar o cancelar no registrará ingresos.
5. Dado que el usuario confirma, cuando backend procesa la solicitud, entonces volverá a verificar autorización vigente para ese examen, estudiante activo, inscripción activa en su oferta, habilitación ELIGIBLE, ambiente coincidente, examen disponible y ausencia de ingreso válido previo. Una habilitación sin evaluar bloquea y nunca se interpreta como habilitado.
6. Dado que el ambiente controlado se selecciona en la interfaz, cuando se solicita el ingreso, entonces su ID será obligatorio y se comparará con el ambiente del examen; no se inferirá físicamente la ubicación del dispositivo.
7. Dado que todas las condiciones se cumplen, cuando se confirma el registro, entonces se guardarán estudiante, examen, ambiente, fecha/hora del servidor, usuario autenticado como verificador, método MANUAL y estado VALID. La interfaz no determinará fecha, verificador, método ni estado persistidos.
8. Dado que una condición no se cumple o cambió desde la consulta, cuando se confirma el registro, entonces backend rechazará la operación y devolverá los bloqueos actuales. Si está inhabilitado se incluirán motivo y observaciones disponibles; no habrá cambios parciales.
9. Dado que existe un ingreso VALID del estudiante para ese examen, cuando se intenta registrar por manual o QR, entonces se rechazará el duplicado y se devolverá el ingreso existente, con fecha, hora y método original. No se reemplazará ni convertirá un ingreso QR en MANUAL.
10. Dado que llegan solicitudes simultáneas manuales, QR o combinadas para el mismo estudiante/examen, cuando se procesan, entonces se permitirá como máximo un ingreso válido y las restantes informarán el registro existente.
11. Dado que se guarda el ingreso, cuando la interfaz recibe éxito, entonces mostrará confirmación y actualizará detalle, listado, contadores y progreso desde backend, conservando búsqueda y página cuando sea posible. Tras un duplicado refrescará el estado sin mostrarlo como nuevo éxito.
12. Dado que se consulta el historial compartido de HU14, cuando existe un ingreso manual, entonces se mostrará con sus datos, método MANUAL y estado, usando búsqueda, orden y paginación existentes.
13. Dado que un ingreso anterior está ANNULLED, cuando se solicita un nuevo registro, entonces no se considerará duplicado válido y podrá crearse uno nuevo solo si se cumplen todas las condiciones, conservando el anterior en el historial.
14. Dado que el usuario carece de permiso, tiene autorización revocada/vencida o modifica identificadores para operar sobre otro examen, cuando intenta registrar, entonces backend denegará el acceso sin crear ni modificar ingresos.
15. Dado que se acepta o rechaza la operación, cuando finaliza, entonces se conservarán inscripción, habilitación y roles. La funcionalidad no exigirá cámara ni token QR, no dará de alta estudiantes y no permitirá eludir las reglas de disponibilidad del examen ni anular ingresos.

## Relación con las otras HUs

HU13 conserva su contrato GET; esta nueva HU agrega la acción al detalle existente. HU14 conserva su contrato QR, compartiendo reglas, almacenamiento e historial con MANUAL. HU16 debe referenciar esta HU en descripción y CA4/CA8 para incluir el registro manual entre las herramientas del colaborador. No se agregan permisos globales ni se amplía su acceso a otros exámenes.

## Contrato aceptado con frontend

### Consulta existente

- GET /api/v1/exams/{exam_id}/entry-control/students?search={query}&page=1&per_page=10&room_id={room_id}
- GET /api/v1/exams/{exam_id}/entry-control/students/{student_id}?room_id={room_id}

Se conservan búsqueda, campos, alcance, paginación y summary de HU13. student_id corresponde a students.id, nunca users.id. meets_entry_conditions y blockers son la referencia de consulta. Disponibilidad y permisos se vuelven a comprobar al guardar; un GET favorable no garantiza que el POST vaya a aceptarse.

### Registrar manualmente

POST /api/v1/exams/{exam_id}/entry-control/students/{student_id}/entries

```json
{"room_id":2}
```

room_id obligatorio, entero positivo y existente. No se necesita qr_token. Frontend confirma identidad antes del POST y no enviará verificador, fecha, método o estado. Buscar nunca registra automáticamente.

### Respuesta 201

```json
{
  "result":"AUTHORIZED",
  "message":"Ingreso manual registrado exitosamente.",
  "processed_at":"2026-10-16T14:05:00Z",
  "data":{
    "student":{"student_id":5,"first_names":"Juan","last_names":"Pérez","sis_code":"20260001","identity_number":"44556677","profile_photo_url":null},
    "student_status":"ACTIVE","enrollment_status":"ACTIVE","eligibility_status":"ELIGIBLE","room_status":"MATCH",
    "reason_code":null,"reason":null,"observations":null,
    "entry_status":"REGISTERED","blockers":[],
    "entry":{"id":12,"exam_id":10,"student_id":5,"room_id":2,"entered_at":"2026-10-16T14:05:00Z","verification_method":"MANUAL","verified_by":3,"verified_by_name":"Héctor","status":"VALID"}
  }
}
```

Envoltorio y campos alineados con HU14; timestamps ISO 8601 UTC. profile_photo_url puede ser null. AUTHORIZED confirma que la escritura terminó. No agregar meets_entry_conditions=true después de registrar: el estudiante ya tiene ingreso y una nueva consulta lo mostrará bloqueado por ALREADY_REGISTERED.

### Rechazos y errores

- 409 / ALREADY_REGISTERED: mismo envoltorio, data con estudiante, estados actuales, blockers que incluyan ALREADY_REGISTERED y entry existente. Se preservará su verification_method original QR o MANUAL. No se crea un segundo registro.
- 422 / DENIED: mismo envoltorio con processed_at y data del estudiante, estados, reason_code/reason/observations, blockers y entry null. No se guarda ingreso.
- 422 de parámetros: errors por campo; no se exige result. Ejemplo: room_id inexistente o ausente.
- 401: no autenticado o cuenta inactiva conforme al middleware vigente.
- 403: no autorizado para el examen, contraseña inicial pendiente o colaboración sin vigencia. Los permisos se comprueban antes de revelar datos del estudiante.
- 404: examen o estudiante inexistente, una vez aplicada la autorización correspondiente.

Bloqueos: STUDENT_INACTIVE, NOT_ENROLLED, ELIGIBILITY_PENDING, INELIGIBLE, ROOM_MISMATCH, ALREADY_REGISTERED y EXAM_UNAVAILABLE. Un examen cancelado/finalizado/vencido no admite ingresos; para el docente se informa DENIED/EXAM_UNAVAILABLE y para un colaborador cuya autorización ya no sea vigente se deniega acceso con 403.

Prioridad: autorización y parámetros; existencia del estudiante; ingreso válido previo; restantes condiciones de negocio. No hay resultados INVALID_QR/WRONG_EXAM porque este flujo no usa token.

### Disponibilidad y concurrencia

Se mantiene la regla vigente del registro QR: examen SCHEDULED/IN_PROGRESS y anterior a su finalización según la configuración temporal del backend. El registro ahora exige el inicio programado; no basta con que el examen no haya finalizado. La ventana de emisión del QR no se exige en MANUAL, dado que no requiere token.

Se reutilizarán reglas y protección transaccional con QR: un único ingreso VALID por estudiante/examen, incluida concurrencia entre mecanismos. No se agrega un segundo registro de asistencia separado ni otro estado académico.

### Refresco e historial

Después de AUTHORIZED y ALREADY_REGISTERED refrescar consultas de HU13 y summary global. Evitar envíos repetidos mientras una solicitud está pendiente. Ante error de red no asumir que no se guardó: consultar el estado; un reintento debe responder el ingreso existente si ya se confirmó.

GET /api/v1/exams/{exam_id}/entry-control/entries

Se conserva el historial de HU14: search/page/per_page, orden por entered_at e id descendentes, objetos student/entry y estados VALID/ANNULLED. Incluirá QR y MANUAL, sin cambiar el contrato.

## Aceptación

Frontend confirmó explícitamente reutilizar búsqueda y detalle de HU13, consumir el POST con room_id, manejar AUTHORIZED/DENIED/ALREADY_REGISTERED y refrescar detalle, listado y contadores. El historial permanece compartido entre MANUAL y QR.

Actualización de apertura, listado y pendiente: [alineación oficial](SPRINT2_OFFICIAL_QA_ALIGNMENT.md). La consulta sigue disponible antes del inicio; registrar aún no.
