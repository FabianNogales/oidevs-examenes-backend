# HU07/HU08 — Contexto de los eventos existentes (FIX-025A)

Los eventos aplicativos mantienen `action: WRITE` y los tipos `Enrollment` / `Exam`.
Se reutiliza `AuditLogService`: actor autenticado, IP, agente y fecha se obtienen del servidor/request.
No se añaden eventos por consultas, errores, previsualizaciones o lotes sin altas.

## HU07: WRITE/Enrollment

| Modalidad | entity_id | Alcance |
| --- | --- | --- |
| MANUAL | ID real de la inscripción creada, no del estudiante ni de la oferta | ENROLLMENT |
| CSV | null: el lote no tiene una única inscripción | COURSE_OFFERING |

`new_values` contiene:

- `source: APPLICATION` y `outcome: CONFIRMED`.
- `operation: ENROLLMENT_CREATED` o `ENROLLMENTS_IMPORTED`.
- `scope`, `mode`, `course_offering_id` y `student_ids` de las altas efectuadas.
- `summary`: `totalProcessed`, `successfulRecords`, `duplicateRecords`, `failedCount`.

En manual el resumen es 1/1/0/0. En CSV reproduce los contadores reales de HU07, incluidas las filas vacías de FIX-013:
`totalProcessed = successfulRecords + duplicateRecords + failedCount`.
No se confunde `failedCount` con el arreglo `failedRecords`, que también contiene duplicados.

Un CSV parcialmente válido genera un solo evento si existen altas; los duplicados/fallidos se describen por cantidades, sin copiar sus filas.
La oferta y los IDs internos delimitan el lote; no se inventa un ID de inscripción para representarlo.
Las decisiones e inicialización de habilitaciones existentes se conservan.

## HU08: WRITE/Exam y CREATE/Exam

El evento aplicativo `WRITE/Exam` identifica el examen real en `entity_id`.
Su contexto incluye `source: APPLICATION`, `operation: EXAM_SCHEDULED`, `outcome: CONFIRMED`,
`course_offering_id`, `room_id`, `exam_date`, `start_time`, `duration_minutes`, `evaluation_type` y `status`.
Se omiten nombre y reglas (texto libre) del nuevo contexto aplicativo.

El trigger versionado `CREATE/Exam` permanece intacto: describe la inserción de base de datos,
con el ID del examen y actor nulo en `user_id`; PostgreSQL conserva su snapshot y SQLite su representación simplificada.
`WRITE` describe la programación confirmada por la aplicación y `CREATE` la inserción subyacente:
son dos registros del mismo hecho, no dos programaciones. Se relacionan mediante `entity_type: Exam` y el mismo `entity_id`.
No se crea un tercer evento ni un identificador artificial de solicitud/lote/correlación.

## Atomicidad, privacidad y compatibilidad

Las altas y sus eventos aplicativos se escriben en la transacción existente, junto a las habilitaciones.
El trigger participa en esa misma transacción. `CONFIRMED` solo queda persistido si el commit exterior tiene éxito;
un fallo de persistencia o auditoría revierte las escrituras y sus eventos. No se publican eventos fuera de la transacción.

Los nuevos eventos HU07 usan IDs internos en lugar de SIS. No se copian nombres, CI, correos, contraseñas, tokens ni el CSV.
Esto no anonimiza los IDs ni modifica los snapshots previos del trigger o las auditorías históricas.
Los consumidores futuros deben admitir que los eventos anteriores carezcan de los campos nuevos.
No cambian respuestas HTTP/JSON, permisos, migraciones, frontend ni los eventos FIX-009/FIX-010.
No se implementan FIX-025B ni interfaces HU26.

La coexistencia y rollback del trigger se comprueban con su equivalente SQLite en pruebas aisladas.
La comprobación del trigger PostgreSQL real queda pendiente de un entorno aislado autorizado.
