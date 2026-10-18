# Disponibilidad compartida de ingreso — acuerdo HU13/HU14/HU16/HU18 y MANUAL

Acuerdo confirmado por frontend y equipo HU18. Implementado en Dev_Hector conservando los contratos existentes, revocaciones, transacciones, auditoría y migración de precisión de HU18. Esta regla no cambia can_edit/can_cancel.

## Matriz

| Estado | Generación QR | Registro QR/MANUAL | Colaboración |
| --- | --- | --- | --- |
| SCHEDULED, ACTIVE legado, IN_PROGRESS | Sí, dentro de ventana QR | Sí, antes del fin | Sí, antes del fin y con autorización vigente |
| CANCELLED, COMPLETED, FINISHED, otros | No | No | No |

Se interpreta fecha/hora del examen en America/La_Paz, independientemente de app.timezone. El fin es inicio + duración, incluso cruzando medianoche. La generación permite [inicio - 24 horas, fin); registro y colaboración no incorporan hora mínima nueva. Todos mantienen sus validaciones adicionales. No se renombra ningún estado persistido.

La consulta histórica sigue disponible para el docente responsable autorizado. La finalización del acceso del colaborador no le concede permisos históricos adicionales.

## HU13: campos agregados en exam (listado y detalle)

```json
{"entry_available":false,"entry_unavailable_reason":"EXAM_CANCELLED"}
```

Prioridad exacta:

1. CANCELLED: EXAM_CANCELLED.
2. COMPLETED/FINISHED: EXAM_FINISHED.
3. Otro estado fuera de la matriz: EXAM_STATE_NOT_ALLOWED.
4. Estado admitido y ahora >= fin: EXAM_ENDED.
5. Estado admitido y ahora < fin: entry_available true y reason null.

Si no está disponible, cada estudiante incorpora una única ocurrencia de EXAM_UNAVAILABLE en blockers y meets_entry_conditions false. Conserva los otros bloqueos, entry y entry_status (un registro existente sigue REGISTERED). summary no cambia de significado: no se transforma pending en not_applicable por indisponibilidad del examen.

Backend comprueba nuevamente la disponibilidad dentro de la transacción de registro, con el examen bloqueado. Los contratos POST y la prioridad de duplicado existente se conservan; no se agrega EXAM_UNAVAILABLE dos veces.

## QR, reprogramación y aula

HU18 revoca tokens al cancelar y al cambiar fecha, hora o duración. Generación conserva el bloqueo del examen agregado por HU18. Los QR anteriores quedan inválidos; el nuevo token solo se entrega dentro de su ventana y exp corresponde al nuevo fin.

Cambiar solo aula conserva token. Registro relee el aula actual y rechaza el room_id anterior con ROOM_MISMATCH. Frontend debe refrescar el contexto del examen para enviar el nuevo ambiente; nunca inferir físicamente la ubicación.

Se conserva ELIGIBLE/INELIGIBLE como contrato persistido; el panel QR reconoce INELIGIBLE y mantiene qr_status NOT_ELIGIBLE para compatibilidad. También interpreta el valor legado NOT_ELIGIBLE como negativo al leer, sin escribirlo ni modificar información histórica. Ninguno permite generar un token: se exige ELIGIBLE.

BUG-02: HU13 también normaliza NOT_ELIGIBLE a eligibility_status INELIGIBLE al leer, conservando reason y observations. Aplica al listado, detalle y validación compartida de MANUAL/QR. Una habilitación ausente o desconocida sigue siendo null/ELIGIBILITY_PENDING. Si el examen no está disponible, se mantienen ambos bloqueos INELIGIBLE y EXAM_UNAVAILABLE.

## Actualización e integración frontend

```sh
git pull --ff-only origin Dev_Hector
php artisan migrate
php artisan optimize:clear
```

La migración de precisión proviene de main/HU18; no se duplica. Ejecutar sobre el ambiente correspondiente al despliegue y sus migraciones pendientes, no durante las pruebas de interfaz.

Frontend debe consumir exam.entry_available/entry_unavailable_reason, mantener los campos actuales y los formatos de errores, usar blockers para explicar el rechazo y refrescar listado/detalle/contexto tras cambios de HU18. Una consulta favorable no garantiza que un POST posterior sea aceptado. No modificar el significado de can_edit/can_cancel con estos campos.

## Verificación

SharedExamAvailabilityTest verifica matriz/prioridad, campos y contadores, generación y ambos mecanismos de ingreso, zona horaria con app.timezone UTC, ventana de 24h, fin exacto, cruce de medianoche, cancelación y auditoría, reprogramación y nuevo exp/token, aula actual sin revocación, etiqueta INELIGIBLE y colaboración ACTIVE hasta el fin.

Las pruebas PostgreSQL de HU18 requieren una base aislada hu18_test_* y su activación explícita. No confundir pruebas SQLite ni pruebas omitidas con validación de concurrencia real. La cámara y mensajes finales requieren la prueba conjunta de frontend.
