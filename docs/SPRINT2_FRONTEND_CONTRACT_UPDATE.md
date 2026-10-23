# Actualización de contrato para Erick — backlog oficial

Estado: backend preparado para revisión; solicitar aceptación explícita y coordinar frontend antes de desplegar. No cambia la política de edición/cancelación HU18. Detalle: [alineación oficial](SPRINT2_OFFICIAL_QA_ALIGNMENT.md).

Hola Erick. Confirmamos que el PDF de QA es el backlog oficial. El registro manual corresponde a HU27; HU26 es auditoría. Se conserva el endpoint manual existente, sin duplicarlo por las referencias de HU13.

## 1. Inicio del examen

Por solicitud de la ingeniera, MANUAL y QR solo registran cuando inicio programado <= ahora del servidor < fin, en America/La_Paz, para cualquier operador autorizado. Consultar, preparar el contexto y emitir QR desde 24h antes siguen siendo posibles antes del inicio.

En los GET de HU13, exam conserva los campos actuales y agrega:

```json
{
  "entry_available":false,
  "entry_unavailable_reason":"EXAM_NOT_STARTED",
  "entry_opens_at":"2026-10-22T19:00:00.000000Z",
  "entry_closes_at":"2026-10-22T20:30:00.000000Z",
  "server_time":"2026-10-22T18:55:00.000000Z"
}
```

Mantener consulta/historial y deshabilitar registro/cámara antes del inicio. Explicar EXAM_NOT_STARTED y refrescar el contexto al llegar al inicio; no asumir que un snapshot false o true sigue vigente indefinidamente. server_time es referencia para presentación, no permiso local definitivo. No exigir cambio manual a IN_PROGRESS si ya llegó el inicio programado y el estado es admisible.

El backend continúa devolviendo 422 DENIED con EXAM_UNAVAILABLE si intentan registrar antes del inicio. Se conservan resultados, payloads y demás motivos. Antes de guardar se vuelve a comprobar horario y autorización bajo la transacción.

## 2. HU13 y contadores

El listado/buscador se limita a inscripción ACTIVE en la oferta del examen; una habilitación aislada no incorpora al estudiante. meta.total es filtrado y summary global conserva independencia de búsqueda/página dentro de ese universo.

Según CA6 oficial, sin ingreso VALID se muestra PENDING aun si el estudiante está inhabilitado o sin evaluar. summary.pending cuenta esos pendientes y not_applicable se mantiene como 0 por compatibilidad. Esto no autoriza el ingreso: usar meets_entry_conditions y blockers; los estados de habilitación y mensajes siguen independientes.

El detalle conserva 404 para estudiantes ajenos y puede explicar una asociación histórica bloqueada, sin habilitar registro. El historial conserva ingresos anteriores aunque el estudiante ya no esté inscrito activamente.

## 3. HU12

El formulario debe pedir motivo justificativo libre, obligatorio al inhabilitar y máximo 500 caracteres. Usar PATCH /api/v1/exams/{exam_id}/eligibilities/{student_id}:

```json
{"status":"INELIGIBLE","reason":"Justificación escrita por el docente","revision":3}
```

observations opcional (máximo 500). reason_code no es obligatorio en el nuevo formulario. Si se envían texto y código, el texto prevalece. El backend mantiene temporalmente el código solo y CSV para no romper clientes anteriores; confirmemos la migración del formulario antes de retirar esa compatibilidad. No debe cerrarse ese endurecimiento como terminado mientras no se coordine.

revision obligatoria viene de GET; 409 ELIGIBILITY_CONFLICT implica conservar borrador/modal, consultar datos actuales y permitir recargarlos explícitamente. No reintentar en automático ni mostrar éxito. Esto ya se incorporó en main y requiere su migración.

## 4. Compatibilidad preservada

HU14 conserva JWT v2, firma/expiración, revocaciones y resolución de estudiante/examen por BD. Los JWT antiguos no se usan: frontend consume opacamente el nuevo token sin interpretar identidades. La lista reciente sigue local; historial MANUAL/QR compartido. La regla C de HU15/HU16 y roles permanentes siguen intactos.

Un solo ambiente por examen: mostrar exam.room como contexto fijo, enviar su ID y refrescarlo tras modificaciones HU18. La validación backend protege contexto desactualizado/manipulado.

## Aceptación y pruebas

Confirma explícitamente este contrato para coordinar adaptación y despliegue: motivo libre/revision, listado inscrito, Pendiente independiente de habilitación, apertura programada y campos de tiempo. Validaremos un segundo antes, inicio exacto, fin exacto, ambos roles y mecanismos, reprogramación/aula, revocación y duplicados. Concurrencia PostgreSQL y cámara real requieren pruebas en su ambiente correspondiente.

La nueva apertura permite precarga futura en IndexedDB, pero no implementa sincronización offline. Ese contrato deberá separar hora de captura y recepción, idempotencia, versiones y resolución de conflictos, sin confiar únicamente en el reloj del cliente.
