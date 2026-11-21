# HU34 — Catálogo global y habilitación académica automática

Base `/api/v1`. Contrato definitivo acordado con frontend: ADMIN controla seis criterios globales; DOCENTE configura parámetros por examen. Propuesta funcional del equipo para revisión de la ingeniera, sin aprobaciones externas.

Respuestas HTTP reales de integración local: [catálogo ADMIN, options y configuration para final y segunda instancia](examples/hu34/global_catalog_responses.json). Son ejemplos de datos locales; consumir siempre los IDs/revisiones actuales de la API.

## Permisos y compatibilidad

- ADMINISTRADOR activo: catálogo global, nombres/descripciones y ACTIVE/INACTIVE. No crea tipos de cálculo ni elimina criterios.
- DOCENTE activo responsable: configuración, datos académicos, evaluación e historial. Un colaborador temporal no adquiere estos permisos.
- Sanctum, sesión vigente y cambio inicial de contraseña siguen vigentes.
- HU12 conserva data como array, estados ELIGIBLE/INELIGIBLE, reason, revision y conflictos. Se agrega `exam: {id, manual_review_conditions}` junto a data.
- MANUAL/LEGACY tienen prioridad. AUTOMATIC/INITIALIZED permiten actualización automática. PENDING nunca se persiste en HU12 ni autoriza ingreso.
- No registra ingresos ni cambia las validaciones de HU13/MANUAL/QR.
- `makeup` identifica segunda instancia, nunca inferir del título.
- Las tablas de políticas y snapshots históricos se conservan. Los endpoints `/admin/eligibility-policies` se retiran (404); sus definiciones no gobiernan nuevos cálculos. Los IDs internos se conservan como referencias históricas/FK, no requieren interacción administrativa.

## Catálogo ADMIN

- GET `/admin/eligibility-criteria`: search (vacío sin filtro), status, page/per_page; data + meta y catalog_revision.
- GET `/admin/eligibility-criteria/{code}`.
- PATCH `/admin/eligibility-criteria/{code}`: `{revision, name?, description?}`. Nombre obligatorio si se envía, máximo 200; descripción nullable máximo 1000. Texto plano.
- PATCH `/admin/eligibility-criteria/{code}/status`: `{revision, status: ACTIVE|INACTIVE}`.
- GET `/admin/eligibility-criteria/{code}/history`: data + meta, auditoría con responsable/fecha/antes/después.
- No POST/DELETE. Rechaza campos adicionales y cambios de code/fórmula/umbrales globales.
- Un cambio real incrementa revision y catalog_revision; repetir el valor vigente no genera una revisión ficticia.

Un elemento de data:

```json
{
  "code": "MIN_ATTENDANCE_PERCENTAGE",
  "name": "Porcentaje mínimo de asistencia",
  "description": null,
  "status": "ACTIVE",
  "revision": 1,
  "parameters": [{"key":"minimum_percentage","required":true,"type":"number","minimum_exclusive":0,"maximum":100}],
  "mandatory_for_exam_types": [],
  "fixed_parameters": null
}
```

Seis códigos fijos:

| code | Parámetros por examen |
| --- | --- |
| PREVIOUS_ASSESSMENTS_TAKEN | assessment_keys: lista no vacía de FIRST_PARTIAL/SECOND_PARTIAL |
| MIN_ATTENDANCE_PERCENTAGE | minimum_percentage: >0 y <=100 |
| MIN_ASSESSMENT_SCORE | assessment_key, minimum_score: 0–100 |
| MIN_ACCUMULATED_SCORE | assessment_keys, minimum_total: 0–100 × cantidad |
| MIN_ASSESSMENT_AVERAGE | assessment_keys, minimum_average: 0–100 |
| SECOND_INSTANCE_PARTIAL_AVERAGE | Fijos: assessment_keys [FIRST_PARTIAL, SECOND_PARTIAL], operator GT, threshold 25 |

Hasta dos decimales; sin fórmulas libres. Evaluaciones académicas y asistencia a clases se importan explícitamente por oferta, no se deducen de ingresos a exámenes.

## Configuración DOCENTE

- GET `/exams/{exam_id}/eligibility-automation/options`.
- GET `/exams/{exam_id}/eligibility-automation/configuration`.
- PUT `/exams/{exam_id}/eligibility-automation/configuration`.

Options entrega configuration_revision como `revision`, catalog_revision, assessments y criteria activos aplicables. Cada opción incluye los campos del catálogo ADMIN más label/required/locked. `parameters` es una lista de esquemas (key/type/required/minimum/maximum/minimum_exclusive/options/min_items). `fixed_parameters` es null o los parámetros de segunda instancia. Frontend usa code, nunca name/label, como identidad.

Configuration bajo data:

```json
{
  "exam_id":26,
  "evaluation_type":"final",
  "configured_evaluation_type":"final",
  "revision":1,
  "catalog_revision":"<identificador opaco de 64 caracteres>",
  "combination":"ALL",
  "selected_criteria":[{"code":"MIN_ATTENDANCE_PERCENTAGE","name":"Asistencia mínima","description":null,"status":"ACTIVE","criterion_revision":1,"parameters":{"minimum_percentage":90},"required":false,"locked":false}],
  "mandatory_criteria":[],
  "inactive_criteria":[],
  "criteria":[{"code":"MIN_ATTENDANCE_PERCENTAGE","name":"Asistencia mínima","description":null,"status":"ACTIVE","criterion_revision":1,"parameters":{"minimum_percentage":90},"required":false,"locked":false}],
  "manual_review_conditions":"Verificar entrega del proyecto."
}
```

PUT:

```json
{"revision":1,"catalog_revision":"<valor recibido>","criteria":[{"code":"MIN_ATTENDANCE_PERCENTAGE","parameters":{"minimum_percentage":80}}],"manual_review_conditions":"Verificar entrega del proyecto."}
```

- revision=0 antes del primer guardado. criteria es la selección opcional completa; [] es válido. No enviar policy_id.
- Backend añade obligatorios. Rechaza códigos desconocidos/duplicados/inactivos, parámetros extra o fuera de rango.
- Configuración y umbrales independientes por examen. Guardar no modifica habilitaciones.
- makeup + SECOND activo: obligatorio bloqueado, promedio estricto >25, parámetros fijos de solo lectura. ADMIN sí puede desactivarlo.
- Criterio opcional desactivado: conserva selección en inactive_criteria, queda fuera de criteria efectivos. Reactivarlo recupera la selección si todavía estaba guardada; guardar una selección nueva sin él lo retira de la configuración.
- Segunda instancia desactivada permite un promedio opcional de ambos parciales. Activa + promedio opcional de ambos: 422 CRITERIA_CONFLICT hasta corregir configuración.
- manual_review_conditions: opcional nullable, texto plano máximo 500. Omitido conserva, null/vacío limpia. Misma revision del examen. Visible en HU12, snapshots y auditoría; no interviene en cálculo ni condiciones de ingreso.
- Ayuda: «Estas condiciones son recordatorios. EIDA no las evalúa automáticamente. El docente debe verificarlas y gestionar la habilitación manualmente.»

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
- El modo predeterminado es `CREATE_ONLY`. El modo explícito `MERGE` permite cargas parciales según el apartado siguiente.
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
{"configuration_revision":1,"catalog_revision":"<valor opaco de options/configuration>"}
```

Requiere configuración guardada. Evalúa estudiantes con inscripción ACTIVE. Guarda un snapshot histórico y devuelve `data` con id, preview_id, expires_at, created_by, created_at, configuration, catalog, results y summary. `results` contiene `data` y `meta` (current_page/per_page/total/last_page); se pagina con page/per_page (también en este POST por query string); summary es global al preview.

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
  "criteria_results":[{"code":"MIN_ATTENDANCE_PERCENTAGE","status":"FAILED","actual_value":80,"required_value":90,"operator":"GTE","reason":"Porcentaje mínimo de asistencia: no cumple.","missing_fields":[]}]
}
```

- Criterios: PASSED / FAILED / PENDING.
- Algún FAILED => INELIGIBLE; de otro modo algún dato faltante => PENDING; todos PASSED => ELIGIBLE.
- Sin criterios aplicables: computed_status=ELIGIBLE para inscripciones ACTIVE. Guardar configuración y generar preview no cambian habilitaciones. La confirmación aplica el resultado respetando decisiones MANUAL/LEGACY protegidas.
- PENDING no reemplaza lo persistido; effective_status conserva current_status o null si no existe.
- Decisiones MANUAL/LEGACY mantienen effective_status=current_status, sin importar lo calculado.
- `will_update` indica que la confirmación guardará una decisión automática y su trazabilidad. También puede actualizar revision si el estado coincide con el anterior.
- Abandonar el preview no cambia habilitaciones.

POST `/exams/{exam_id}/eligibility-automation/evaluations/{preview_id}/confirm`

Sin payload. Solo el creador y responsable actual. Vigencia 30 minutos. Revalida snapshot completo bajo bloqueos, incluyendo examen, catálogo global/configuración, inscripciones, registros académicos y revisiones de habilitación. Si algo cambió: 409, generar otro preview.

```json
{"data":{"evaluation_id":15,"updated":18,"unchanged":4,"manual_preserved":2,"pending":1}}
```

manual_preserved y pending son contadores descriptivos y pueden solaparse; updated + unchanged = total. Reintentar una confirmación completada devuelve el mismo resultado. No recalcula silenciosamente. Fallo de auditoría/guardado revierte toda la operación.

GET `/exams/{exam_id}/eligibility-automation/evaluations`

GET `/exams/{exam_id}/eligibility-automation/evaluations/{evaluation_id}?page=1&per_page=10`

Listado paginado de previews/confirmaciones y detalle del snapshot original. Conserva resultados aunque cambien notas/criterios posteriormente. El detalle utiliza el mismo formato de results/meta/summary que preview.

### Revisar y aplicar

El POST de preview y el GET de detalle aceptan `search`, `filter`, `page` y `per_page`. `search` busca por nombre completo o SIS sin distinguir mayúsculas. Vacío equivale a consulta sin búsqueda.

`filter` admite `ALL` (valor predeterminado), `CHANGES`, `ELIGIBLE`, `INELIGIBLE`, `PENDING` y `PROTECTED`.

- `CHANGES` utiliza `state_will_change`. Frontend puede seleccionarlo inicialmente.
- `ELIGIBLE` / `INELIGIBLE` utilizan el estado persistido propuesto `effective_status`.
- `PENDING` utiliza `computed_status`; puede conservar una habilitación persistida.
- `PROTECTED` utiliza `manual_override_preserved`.

Se combinan búsqueda y filtro antes de paginar. `results.meta.total` cuenta las coincidencias. Un filtro desconocido o un parámetro mal formado produce 422.

Cada resultado nuevo incorpora `state_will_change`, `traceability_only` y `protected_source` (`MANUAL`, `LEGACY` o null). `will_update` conserva su significado de actualización de decisión automática, incluida la trazabilidad sin cambio de estado. También devuelve `current_reason` y `effective_reason` para mostrar el motivo que quedará guardado; `reason` continúa explicando el cálculo automático.

`summary` siempre abarca todo el snapshot. Conserva `total`, `eligible`, `ineligible` y `pending` como contadores del cálculo. Agrega `effective_eligible`, `effective_ineligible` y `effective_unset` para los estados persistidos propuestos, además de `state_changes`, `traceability_only` y `protected`. Para la tabla final, utilizar los contadores `effective_*`. Los grupos pendientes/protegidos pueden solaparse con estados efectivos.

La confirmación aplica todo el snapshot, independientemente de filtros, búsqueda o página. Los previews del contrato anterior deben regenerarse para confirmar (409 AUTOMATION_CONFLICT); los confirmados conservan su resultado idempotente. Los históricos siguen consultables sin recalcular resultados; `protected_source` es null si el snapshot antiguo no registró su origen.

### CSV parcial MERGE

Se mantienen los endpoints de importación por oferta. Enviar multipart con `file`, `mode=MERGE` y `reason` cuando se cambien datos existentes. La justificación tiene hasta 500 caracteres y queda guardada en el preview para la confirmación sin payload.

`import-schema` agrega `modes`, `default_mode` y el objeto `merge` con columnas permitidas, plantillas de asistencia/evaluación y reglas de conservación. `CREATE_ONLY` mantiene las seis columnas originales en su orden y rechaza estudiantes con datos previos.

En MERGE las columnas pueden ser un subconjunto de las originales, en cualquier orden, con `sis` obligatorio. Se rechazan encabezados duplicados o desconocidos, incluido `nombre_completo`. Ejemplos:

```csv
sis,attended_classes,total_classes
202600001,18,20
```

```csv
sis,assessment_key,was_taken,score
202600001,FIRST_PARTIAL,true,65
```

- Campos y evaluaciones omitidos o vacíos conservan el valor existente. En registros nuevos quedan ausentes. No se borran datos mediante MERGE.
- Las dos cantidades de asistencia se proporcionan juntas y deben ser válidas. Se admite un registro de asistencia sin evaluación ficticia. La respuesta serializa `assessments` vacío como `{}` para conservar su tipo de mapa.
- Una evaluación nueva exige `assessment_key` y `was_taken`. Se admite nota ausente; los criterios de nota darán PENDING.
- Para una evaluación existente puede enviarse únicamente una nota, conservando `was_taken`. La nota exige una evaluación rendida. Cambiar a no rendida conservando una nota se rechaza y requiere corrección explícita.
- Un CSV puede contener distintas evaluaciones y asistencia para un SIS. Se rechaza más de una fila que actualice la misma evaluación o la misma asistencia, incluso con valores iguales.
- Toda fila debe aportar algún dato. Un error impide confirmar el archivo completo.
- No hay cambios automáticos de habilitación por importar. Se requiere una nueva evaluación y confirmación.

Preview conserva sus campos actuales y agrega `mode`, `reason`, `summary` (`created_records`, `updated_records`, `unchanged_records`). Cada `valid_records` incluye `record_id`, `revision`, `before`, `proposed` y `action` (`CREATE`, `UPDATE`, `UNCHANGED`), además de los campos de datos académicos existentes.

Al confirmar se revalidan inscripción, identidad y revisión de cada registro, incluso los que no cambian. Si otro proceso creó o modificó un registro, se devuelve 409 `AUTOMATION_CONFLICT` y se debe regenerar el preview. La transacción y auditoría abarcan todo el archivo. Un registro sin cambios conserva su revisión. La respuesta incluye `created_records`, `updated_records`, `unchanged_records`, `record_ids` y `preview_id`. Los reintentos de una confirmación completada devuelven exactamente el resultado anterior.

La corrección por `record_id` conserva su contrato de reemplazo completo y exige todos los datos que deban mantenerse. Se admite corregir registros exclusivos de asistencia mediante `assessments: {}` (también se acepta `[]`) y las dos cantidades.

## Revisiones, errores y despliegue

- `revision` del criterio protege edición ADMIN; `revision` de configuración protege el examen.
- `catalog_revision` es opaco, no se calcula en frontend. Se exige al guardar configuración y generar preview.
- Todo cambio global de nombre, descripción o estado invalida previews pendientes, incluso de criterios no seleccionados. Se comprueba de nuevo dentro de la transacción de confirmación.
- Bloqueos en orden catálogo → examen → oferta → habilitaciones. ADMIN y evaluación comparten el bloqueo de catálogo para serializar cambios concurrentes.
- Evaluaciones confirmadas son históricas: repetir confirmación devuelve su resultado original aun si el catálogo cambió después.
- Previews anteriores al cambio de modelo siguen consultables pero deben regenerarse para confirmar (409).
- 401 sin sesión; 403 sin permisos; 404 recurso inexistente/preview ajeno.
- 409 `AUTOMATION_CONFLICT`: revisión/catálogo/datos cambiados o preview vencido. Recargar y regenerar, nunca confirmar localmente.
- 422 `CRITERIA_CONFLICT`: combinación incompatible. Otras validaciones 422 conservan el formato Laravel message + errors cuando corresponde; no inventar un código adicional obligatorio.
- Resultados: results.data + results.meta; summary global. Listados: data + meta. Default 10, máximo 100.
- Fechas ISO 8601; presentación America/La_Paz. Vencimiento 30 minutos.
- Ejecutar `php artisan migrate` antes de integrar, sin migrate:fresh. Nueva migración 2026_11_04_000001 añade revision al catálogo y manual_review_conditions a configuración; created_by nullable para los criterios de sistema. Inicializa los seis códigos faltantes sin reemplazar filas existentes.
- No elimina políticas/evaluaciones, no copia decisiones ni reescribe usuarios, inscripciones, entradas o tablas del portal.
