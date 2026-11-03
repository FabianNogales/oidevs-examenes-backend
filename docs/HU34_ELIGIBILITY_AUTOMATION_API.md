# HU34 — Habilitación académica automática

Base `/api/v1`. Propuesta funcional del equipo para revisión de la ingeniera. No existe circuito de aprobación externa ni referencia institucional obligatoria. La migración instala una política inicial ACTIVE; no requiere cargar datos de demostración ni ejecutar un seeder.

## Permisos y compatibilidad

- Administración de políticas: ADMINISTRADOR activo.
- Configuración, datos académicos, previews, confirmaciones e historial: DOCENTE responsable de la oferta/examen, activo. Un colaborador temporal no adquiere estos permisos.
- Sanctum, sesión vigente y cambio inicial de contraseña siguen siendo obligatorios.
- HU12 conserva sus respuestas, estados ELIGIBLE/INELIGIBLE, reason, revision y 409. No se añade PENDING persistente.
- Las nuevas decisiones de HU12, individuales o CSV, se marcan MANUAL. Las inicializaciones futuras se marcan INITIALIZED. Los registros existentes se conservan como LEGACY, sin adivinar su origen desde auditoría. MANUAL y LEGACY se preservan en evaluaciones automáticas.
- La automatización no registra ingresos ni reemplaza las validaciones de HU13/MANUAL/QR.
- `makeup` es segunda instancia, según el enum existente. No inferirlo del nombre del examen.
- No se agrega un cierre nuevo de configuración al inicio del examen: no fue acordado. La disponibilidad de ingreso sigue siendo independiente.

## Catálogo y configuración

GET `/exams/{exam_id}/eligibility-automation/options`

GET `/exams/{exam_id}/eligibility-automation/configuration`

PUT `/exams/{exam_id}/eligibility-automation/configuration`

Respuesta de configuración bajo `data`:

```json
{
  "exam_id": 26,
  "evaluation_type": "makeup",
  "revision": 1,
  "combination": "ALL",
  "policy": {"id": 1, "version": 1, "origin": "TEAM_PROPOSAL"},
  "selected_criteria": [],
  "mandatory_criteria": [
    {"code": "SECOND_INSTANCE_PARTIAL_AVERAGE", "parameters": {}, "required": true, "locked": true}
  ],
  "criteria": [
    {"code": "SECOND_INSTANCE_PARTIAL_AVERAGE", "parameters": {}, "required": true, "locked": true}
  ]
}
```

En options, `criteria` describe el catálogo disponible con `label`, `required`, `locked` y esquemas `parameters`; incluye además `assessments` con FIRST_PARTIAL/Primer parcial y SECOND_PARTIAL/Segundo parcial. El criterio de segunda instancia incluye `operator: GT` y `threshold: 25` en la política inicial. Frontend consume estos datos, no replica fórmulas.

| code | Parámetros |
| --- | --- |
| PREVIOUS_ASSESSMENTS_TAKEN | assessment_keys: lista no vacía de evaluaciones |
| MIN_ATTENDANCE_PERCENTAGE | minimum_percentage: > 0 y <= 100 |
| MIN_ASSESSMENT_SCORE | assessment_key, minimum_score: 0–100 |
| MIN_ACCUMULATED_SCORE | assessment_keys, minimum_total: 0–100 × cantidad de evaluaciones |
| MIN_ASSESSMENT_AVERAGE | assessment_keys, minimum_average: 0–100 |
| SECOND_INSTANCE_PARTIAL_AVERAGE | sin parámetros editables por docente; solo makeup |

La primera versión admite dos claves académicas lógicas: FIRST_PARTIAL y SECOND_PARTIAL. No son IDs de exámenes ni lecturas automáticas de asistencia a exámenes. Las notas y asistencia a clases se importan explícitamente para la oferta. No hay fórmulas libres ni ponderaciones arbitrarias.

PUT ejemplo:

```json
{
  "revision": 0,
  "criteria": [
    {"code": "MIN_ATTENDANCE_PERCENTAGE", "parameters": {"minimum_percentage": 90}},
    {"code": "PREVIOUS_ASSESSMENTS_TAKEN", "parameters": {"assessment_keys": ["FIRST_PARTIAL", "SECOND_PARTIAL"]}}
  ]
}
```

`revision: 0` representa configuración todavía no guardada. `criteria: []` es válido. No enviar el criterio obligatorio: backend lo incorpora siempre para makeup. `policy_id` es opcional: permite seleccionar explícitamente otra versión ACTIVE; una configuración guardada queda vinculada a esa versión aunque se active otra posteriormente.

Todos los criterios se combinan con AND. Los mínimos opcionales usan >=; segunda instancia usa promedio simple > 25, sin redondear antes de comparar. Valores numéricos: hasta dos decimales. La suma acumulada no tiene ponderaciones.

Códigos duplicados, parámetros extra, rangos imposibles o criterios obligatorios enviados como opcionales: 422. Promedio opcional sobre los dos parciales en makeup: 422 `CRITERIA_CONFLICT`, pues ya lo define el obligatorio. Asistencia, nota individual y otros requisitos adicionales sí pueden coexistir. No se considera contradictorio que un criterio adicional sea más exigente.

Guardar incrementa revision y audita; no cambia habilitaciones. La respuesta incluye configured_evaluation_type. Si el examen cambia de tipo posteriormente, todavía puede consultarse/corregirse la configuración; preview vuelve a validar su compatibilidad y exige volver a guardarla (409 si el tipo configurado difiere del vigente).

## CSV — preview y confirmación

GET `/academic-offerings/{offering_id}/academic-records/import-schema`

POST `/academic-offerings/{offering_id}/academic-records/imports/preview`

Multipart: `file`, extensión CSV, UTF-8 (BOM permitido), separador coma, hasta 5 MiB y 1000 filas.

```csv
sis,assessment_key,was_taken,score,attended_classes,total_classes
202600001,FIRST_PARTIAL,true,60,18,20
202600001,SECOND_PARTIAL,true,70,,
```

- Encabezados en ese orden. `was_taken`: texto true/false. Notas 0–100 con hasta dos decimales; vacío significa dato ausente, nunca cero.
- Si no rindió, no puede tener nota. Si rindió pero falta nota, puede evaluarse asistencia a evaluación; los criterios de nota quedarán PENDING.
- Ambas cantidades de asistencia deben venir juntas, con total positivo y asistencias <= total. Si se repiten para el mismo SIS deben coincidir. Una fila puede omitir ambas si otra ya las proporciona.
- SIS debe pertenecer a una inscripción ACTIVE de la oferta. Duplicar SIS + evaluación genera error.
- Una importación inicial no reemplaza datos existentes; la corrección se hace por record_id.
- Preview persiste solo un borrador temporal/auditoría; no modifica academic_records ni habilitaciones.

Respuesta:

```json
{
  "data": {
    "preview_id": "uuid",
    "expires_at": "2026-11-02T20:30:00+00:00",
    "can_confirm": true,
    "total_rows": 2,
    "valid_records": [
      {"student_id": 20, "sis": "202600001", "assessments": {"FIRST_PARTIAL": {"was_taken": true, "score": 60}, "SECOND_PARTIAL": {"was_taken": true, "score": 70}}, "attended_classes": 18, "total_classes": 20}
    ],
    "errors": []
  }
}
```

Cada error contiene `row` (registro lógico, encabezado = 1), `sis` y `messages`. Si hay errores, can_confirm=false y la confirmación devuelve 422; no se guardan parcialmente las filas válidas.

POST `/academic-offerings/{offering_id}/academic-records/imports/{preview_id}/confirm`

Sin payload. Solo creador y docente responsable actual. Vigencia 30 minutos. Confirma transaccionalmente, revalida inscripción y ausencia de registros previos. Un reintento confirmado devuelve exactamente el resultado anterior, sin duplicar:

```json
{"data":{"preview_id":"uuid","created_records":1,"record_ids":[12]}}
```

GET `/academic-offerings/{offering_id}/academic-records?search=...&page=1&per_page=10`

PATCH `/academic-offerings/{offering_id}/academic-records/{record_id}`

La corrección reemplaza explícitamente los datos del registro identificado; enviar todos los assessments que se deseen conservar:

```json
{
  "revision": 1,
  "reason": "Corrección de notas",
  "assessments": {
    "FIRST_PARTIAL": {"was_taken": true, "score": 60},
    "SECOND_PARTIAL": {"was_taken": true, "score": 75}
  },
  "attended_classes": 19,
  "total_classes": 20
}
```

reason obligatorio hasta 500 caracteres; revision se incrementa. Corrección no recalcula habilitaciones: ejecutar un nuevo preview/confirm.

GET `/academic-offerings/{offering_id}/academic-records/history` conserva importaciones, correcciones, valores previos/nuevos, actor y fecha. Paginado.

## Evaluación de habilitaciones

POST `/exams/{exam_id}/eligibility-automation/evaluations/preview`

```json
{"configuration_revision":1}
```

Requiere configuración guardada. Evalúa estudiantes con inscripción ACTIVE. Guarda un snapshot histórico y devuelve `data` con id, preview_id, expires_at, created_by, created_at, configuration, policy_definition, results, meta y summary. `results` se pagina con page/per_page (también en este POST por query string); summary es global al preview.

Ejemplo de una fila:

```json
{
  "student":{"id":20,"display_name":"Ana Pérez","sis":"202600001"},
  "computed_status":"INELIGIBLE",
  "current_status":"ELIGIBLE",
  "effective_status":"ELIGIBLE",
  "manual_override_preserved":true,
  "will_update":false,
  "reason":"Porcentaje mínimo de asistencia: no cumple.",
  "missing_fields":[],
  "eligibility_revision":3,
  "policy":{"id":1,"version":1},
  "criteria_results":[{"code":"MIN_ATTENDANCE_PERCENTAGE","status":"FAILED","actual_value":80,"required_value":90,"operator":"GTE","reason":"Porcentaje mínimo de asistencia: no cumple.","missing_fields":[]}]
}
```

- Criterios: PASSED / FAILED / PENDING.
- Algún FAILED => INELIGIBLE; de otro modo algún dato faltante => PENDING; todos PASSED => ELIGIBLE.
- Sin criterios: computed_status=PENDING con motivo explícito y will_update=false. No modifica nada.
- PENDING no reemplaza lo persistido; effective_status conserva current_status o null si no existe.
- Decisiones MANUAL/LEGACY mantienen effective_status=current_status, sin importar lo calculado.
- `will_update` indica que la confirmación guardará una decisión automática y su trazabilidad. También puede actualizar revision si el estado coincide con el anterior.
- Abandonar el preview no cambia habilitaciones.

POST `/exams/{exam_id}/eligibility-automation/evaluations/{preview_id}/confirm`

Sin payload. Solo el creador y responsable actual. Vigencia 30 minutos. Revalida snapshot completo bajo bloqueos, incluyendo examen, política/configuración, inscripciones, registros académicos y revisiones de habilitación. Si algo cambió: 409, generar otro preview.

```json
{"data":{"evaluation_id":15,"updated":18,"unchanged":4,"manual_preserved":2,"pending":1}}
```

manual_preserved y pending son contadores descriptivos y pueden solaparse; updated + unchanged = total. Reintentar una confirmación completada devuelve el mismo resultado. No recalcula silenciosamente. Fallo de auditoría/guardado revierte toda la operación.

GET `/exams/{exam_id}/eligibility-automation/evaluations`

GET `/exams/{exam_id}/eligibility-automation/evaluations/{evaluation_id}?page=1&per_page=10`

Listado paginado de previews/confirmaciones y detalle del snapshot original. Conserva resultados aunque cambien notas/políticas posteriormente. El detalle utiliza el mismo formato de results/meta/summary que preview.

## Políticas versionadas

- GET/POST `/admin/eligibility-policies`
- GET/PATCH `/admin/eligibility-policies/{policy_id}`
- POST `/admin/eligibility-policies/{policy_id}/versions`
- POST `/admin/eligibility-policies/{policy_id}/activate`

Crear/clonar: `name` obligatorio, definition opcional (omitida utiliza catálogo inicial). PATCH de borrador exige revision y permite name/definition. Activar exige revision. DRAFT es editable; ACTIVE es inmutable. Cambiar una versión activa requiere crear otra. No hay aprobación externa.

Definición configurable acotada, sin fórmulas ejecutables:

```json
{
  "origin":"TEAM_PROPOSAL",
  "combination":"ALL",
  "allowed_criteria":["PREVIOUS_ASSESSMENTS_TAKEN","MIN_ATTENDANCE_PERCENTAGE","MIN_ASSESSMENT_SCORE","MIN_ACCUMULATED_SCORE","MIN_ASSESSMENT_AVERAGE","SECOND_INSTANCE_PARTIAL_AVERAGE"],
  "second_instance":{"evaluation_type":"makeup","operator":"GT","threshold":25}
}
```

El administrador puede versionar el umbral de segunda instancia y las opciones admitidas para incorporar correcciones de la ingeniera. El docente no puede cambiar el umbral obligatorio. La política siempre debe admitir el criterio obligatorio.

## Errores, fechas y despliegue

- 401 sin sesión; 403 sin permisos; 404 recurso inexistente o preview ajeno.
- 422 validación; CRITERIA_CONFLICT para combinación de promedios incompatible.
- 409 AUTOMATION_CONFLICT para revision desactualizada, preview vencido o datos cambiados. No confirmar localmente tras 409.
- Fechas ISO 8601 como instantes; presentar en America/La_Paz. Los vencimientos no dependen del timezone de PostgreSQL.
- Listados: data + meta.current_page/per_page/total/last_page. Default 10, máximo 100.
- Ejecutar `php artisan migrate` antes de integrar. Dos migraciones nuevas aditivas; no migrate:fresh.
- Nuevas tablas: eligibility_policies, exam_eligibility_configurations, academic_records, academic_imports, eligibility_evaluations. Se reutilizan eligibility_criteria, exam_eligibility_criteria y student_criterion_results. Auditoría reutiliza audit_logs.
- students, usuarios, inscripciones, entradas y tablas del portal no se reescriben.
