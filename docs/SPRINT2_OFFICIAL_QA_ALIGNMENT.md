# Sprint 2 — Alineación del backend con el backlog oficial

Referencia funcional: `Sprint 2 - backlog (refinado) QA.docx.pdf` (63 páginas), confirmado por Héctor como oficial. La solicitud posterior de la ingeniera limita el registro al intervalo del examen. Las definiciones anteriores que se contradigan quedan sustituidas por lo indicado aquí.

## Numeración y alcance

| HU | Responsabilidad backend | Documentación de contrato |
| --- | --- | --- |
| HU12 | Habilitación por examen, inicialización, motivo libre, revisiones y auditoría | HU12_ELIGIBILITY_API.md |
| HU13 | Identificación, búsqueda de inscritos, detalle y condiciones actuales | HU13_ENTRY_CONTROL_API.md |
| HU14 | Procesar JWT v2 y registrar ingreso QR, historial y rechazo | HU14_QR_ENTRY_API.md |
| HU15 | Usuarios candidatos según regla C, asignación, listado y revocación | HU15_HU16_COLLABORATORS_API.md |
| HU16 | Colaboraciones vigentes, rol habitual intacto y acceso por examen | HU15_HU16_COLLABORATORS_API.md |
| HU27 | Registro MANUAL con las mismas condiciones que QR | HU_REGISTRO_MANUAL_PROPUESTA.md |

HU26 es auditoría en el backlog oficial. Las menciones previas de HU26 como ingreso manual son una numeración anterior; no se modifica el historial Git ni se duplican endpoints. HU13 CA16–20/31–33 y HU16 CA9 remiten al flujo manual implementado por HU27; una sola operación satisface esas referencias.

## HU12 — Motivo y concurrencia

La programación genera habilitaciones ELIGIBLE para inscritos activos. El inicializador incorporado desde main crea las faltantes para nuevas inscripciones en exámenes disponibles para preparación, incluyendo futuros, sin sobrescribir decisiones existentes.

El contrato a usar por el frontend oficial al inhabilitar es:

```json
{"status":"INELIGIBLE","reason":"No presentó el requisito institucional solicitado","revision":3,"observations":"Texto adicional opcional"}
```

reason es la justificación escrita por el docente, máximo 500 caracteres. El formulario oficial debe exigirla; un motivo genérico seleccionado del catálogo no la reemplaza. reason_code y observations siguen existiendo para compatibilidad; no son campos nuevos obligatorios del backlog. Si se envían reason y reason_code juntos, el texto explícito prevalece y el código solo clasifica. Código desconocido y texto de longitud inválida se rechazan. Para ELIGIBLE se limpian motivo, código y observaciones.

Compatibilidad transitoria: el backend conserva las solicitudes anteriores que envían solo reason_code y el CSV de cuatro columnas para no romper consumidores vigentes. Eliminar esa compatibilidad y exigir reason a todo PATCH requiere confirmar antes la actualización de frontend; no se debe presentar esa retirada como ya implementada. El contrato nuevo usa reason libre sin código. CSV/catálogo se conservan como extensiones existentes, no como obligaciones nuevas de QA.

revision es el entero de GET que PATCH debe reenviar; 422 si falta/es inválido, 409 ELIGIBILITY_CONFLICT si quedó obsoleto. Cada guardado aceptado incrementa la revisión con auditoría; CSV mantiene su contrato y también incrementa revisiones. No hay backfill histórico ni conversión de habilitaciones ausentes a ELIGIBLE durante una consulta.

## HU13 — Listado, pendientes y protección de datos

GET /api/v1/exams/{exam_id}/entry-control/students conserva búsqueda, paginación y campos. Lista únicamente estudiantes con inscripción ACTIVE en la oferta del examen. No basta una habilitación aislada, ni una inscripción INACTIVE. La oferta es el vínculo académico con el examen en el modelo actual; no se inventa una segunda tabla de inscripción al examen.

summary se calcula sobre ese mismo universo, independiente de búsqueda/página; meta.total es el resultado filtrado. Un estudiante sin ingreso VALID es PENDING aunque esté inhabilitado o sin evaluar (CA6). Registrado sigue REGISTERED aunque cambie después la habilitación. summary.pending cuenta los no registrados, summary.not_applicable se conserva por compatibilidad y vale 0. PENDING no significa autorización para ingresar: frontend debe usar meets_entry_conditions y blockers.

El GET de detalle mantiene el límite de asociación implementado en main: 404 idéntico para ajeno e inexistente. Puede explicar bloqueos de una asociación histórica conocida, incluso inscripción inactiva; esto no permite incorporarla al listado ni registrar. Las consultas de historial conservan los ingresos previos aunque el estudiante ya no esté en el listado activo.

El valor histórico NOT_ELIGIBLE se normaliza a INELIGIBLE al leer, conservando motivo y observaciones. Una habilitación ausente/desconocida es null y ELIGIBILITY_PENDING; no se interpreta como habilitada ni inhabilitada.

## Apertura de registro — solicitud de la ingeniera

Interpretación implementada: inicio programado `exam_date + start_time`, no un botón nuevo ni depender exclusivamente de cambiar status a IN_PROGRESS.

- Estados admitidos: SCHEDULED, ACTIVE legado e IN_PROGRESS.
- Zona horaria para interpretar horario: America/La_Paz.
- Registro online permitido: inicio <= ahora del servidor < fin, además de las condiciones de identidad, inscripción, habilitación, ambiente y autorización.
- Preparación antes del inicio: consulta de los datos autorizados y del historial, asignación de colaboradores, inicialización de habilitaciones y emisión QR en su ventana actual de 24 horas.
- CANCELLED, COMPLETED, FINISHED y estados desconocidos bloquean operaciones según la política de disponibilidad existente; el docente responsable mantiene consulta histórica autorizada.

Se separa preparationReason de reason para no impedir que futuros exámenes se preparen ni cambiar can_edit/can_cancel de HU18. El estado IN_PROGRESS no permite saltarse un inicio programado futuro.

En exam, se conservan entry_available y entry_unavailable_reason y se agregan entry_opens_at, entry_closes_at y server_time (ISO 8601 UTC). Prioridad de motivos: EXAM_CANCELLED, EXAM_FINISHED, EXAM_STATE_NOT_ALLOWED, EXAM_ENDED, EXAM_NOT_STARTED, null. Si no está disponible, se agrega una sola ocurrencia de EXAM_UNAVAILABLE en blockers y meets_entry_conditions es false. No se registra ingreso ni se modifica habilitación/inscripción por consultar.

Los POST QR y MANUAL conservan sus rutas, payloads y envoltorio. Antes del inicio: 422 DENIED con EXAM_UNAVAILABLE. Frontend puede explicar EXAM_NOT_STARTED desde exam y refrescar al llegar a entry_opens_at; no puede habilitar ingreso basándose solo en el reloj local o en PENDING. Los GET nunca sustituyen la revalidación transaccional del POST.

## HU14/HU27 — Preservación de contratos

POST /api/v1/exams/{exam_id}/entry-control/qr recibe qr_token completo y room_id. JWT v2 solo contiene v=2, jti y exp; la identidad se resuelve en el token activo de la BD. JWT v1 es rechazado: se debe actualizar la emisión/precarga, no transformar tokens viejos en frontend.

POST /api/v1/exams/{exam_id}/entry-control/students/{student_id}/entries recibe room_id. student_id es students.id. Método MANUAL y verificador/fecha/estado se determinan en servidor. La confirmación humana de identidad corresponde a frontend.

Se conservan 201 AUTHORIZED, 409 ALREADY_REGISTERED con ingreso existente, 422 DENIED, errores propios de QR, autorización 401/403 y 404 de recursos/estudiantes ajenos. Se comparten la transacción, bloqueo del examen, bloqueo del verificador FOR NO KEY UPDATE, índice único de ingreso VALID, historial y protección de duplicados entre mecanismos. No se toca auditoría de WRONG_EXAM ni las revocaciones de HU18.

Examen con una única exam.room: mostrar ambiente fijo y enviar su ID. Releerlo tras una modificación HU18. Backend sigue validándolo para rechazar contexto viejo o manipulado. El catálogo global de aulas no es un selector de puntos de control del examen.

## HU15/HU16 — Colaboración

Regla C y contratos vigentes se conservan. Asignación/revocación no alteran roles permanentes. Usuario con perfil estudiantil e inscripción ACTIVE en la oferta no puede colaborar en ese examen, incluso con varios roles. Buscador y POST lo comprueban.

Colaboraciones futuras pueden verse y prepararse antes del inicio. El registro de cualquier operador, incluido el docente, queda sujeto a la apertura. Revocación y fin mantienen el cierre inmediato del acceso del colaborador, sin tocar sus otros exámenes ni permisos habituales. Búsqueda del listado asignado y confirmaciones/estados vacíos son responsabilidades de interfaz sobre los datos autorizados, no nuevas autorizaciones.

## Futuro offline — análisis, no implementación

IndexedDB puede almacenar previamente el examen, horario, aula, padrón, habilitaciones, revisiones y autorización. La nueva apertura no impide esa precarga, porque las consultas autorizadas siguen disponibles antes del inicio.

Un snapshot con entry_available=false no debe bloquear para siempre: frontend necesita los límites de tiempo y una referencia de hora del servidor para decidir cuándo volver a consultar/abrir el flujo. Sin conexión, una estimación desde server_time y un contador monotónico puede reducir errores casuales del reloj, pero no prueba la hora real de una captura frente a manipulación o reinicio del dispositivo.

El futuro protocolo requiere idempotencia por operación, versión del examen/padrón/autorización, identificación del dispositivo y hora de captura diferenciada de la recepción. Las capturas locales serán pendientes de sincronización, no asistencias VALID confirmadas. Reprogramación, revocación, inhabilitación o cancelación posteriores a la precarga y duplicados entre dispositivos deben resolver conflictos explícitamente; no sobrescribir datos en silencio.

Los POST online actuales usan ahora del servidor y no aceptan entered_at del cliente. No sirven para sincronizar después del fin una captura anterior: se necesita otro contrato de sincronización con validación del instante de captura y una política de aceptación o revisión de eventos tardíos. La validez del JWT al sincronizar también requiere definición: no se debe desactivar la expiración del escáner online para resolverlo.

Offline estricto sin ninguna posibilidad de registrar antes de tiempo no puede garantizarse solo con IndexedDB y el reloj controlado por el cliente. Debe elegirse con la ingeniera entre autorización online al abrir, evidencia de tiempo de un dispositivo confiable o aceptación provisional con revisión. Ninguna de esas políticas se implementa ni se promete en este ajuste.

## Despliegue y aceptación frontend

Aplicar migraciones pendientes de main (incluida revision) y limpiar caché. No hay migración nueva en este ajuste. Revisar en conjunto: motivo libre, revisión/conflictos 409, nuevos motivos/horarios, Pendiente independiente de habilitación, listado de inscritos, JWT v2, precarga y refresco tras HU18.

El cambio de interfaz/contrato debe ser aceptado por Erick antes del despliegue conjunto. La corrección backend no acredita prueba física de cámara/iPhone ni concurrencia real en PostgreSQL. El resultado ejecutado de pruebas se informa con el commit, distinguiendo las omitidas.
