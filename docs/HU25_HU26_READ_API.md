# HU25/HU26 — Consulta administrativa (lote 3)

Rutas GET bajo `/api/v1/admin`, con autenticación Sanctum, cuenta activa, sesión vigente,
contraseña inicial cambiada y rol/asignación ADMINISTRADOR activos. No existen mutaciones.
Las consultas no emiten eventos de acceso, evitando que leer auditorías cambie su listado.
Esto no modifica la cobertura de FIX-025B ni los middleware de otros módulos.

## Auditorías

`GET /audit-logs?page=1&per_page=10` (máximo 100), orden descendente por fecha e ID.
`GET /audit-logs/{id}` devuelve el detalle del mismo evento; inexistente: 404.
Respuesta Laravel `data` y `meta`; detalle: `data`.

Campos: `id`, `actor_id` (nullable), `action`, `entity_type`, `entity_id` (nullable),
`created_at` ISO y `old_context`/`new_context` saneados por lista permitida.
El actor se identifica por ID: no se publica correo, CI, nombre ni perfil.
Se muestran referencias internas, estados conocidos, fechas y cantidades verificables
de FIX-009, FIX-010 y FIX-025A. No se copian snapshots completos, textos libres,
IP, user-agent, credenciales, tokens, fotos ni listas de estudiantes.
Los contextos históricos desconocidos se presentan vacíos; no se reconstruyen datos ausentes.

El total cuenta **eventos**, no operaciones: WRITE/Exam y CREATE/Exam son dos registros
del mismo examen. No se deduplican ni se les inventa una correlación de solicitud.
Filtros avanzados, búsqueda libre y ampliación de datos privados quedan pendientes
de ratificar el contrato refinado HU26, no disponible en estos repositorios.

## Carreras

`GET /careers?page=1&per_page=10&search=texto`: búsqueda por código/nombre, orden nombre/ID.
`GET /careers/{id}`: detalle. Campos existentes `id`, `code`, `name`, `status`.
Incluye ACTIVE e INACTIVE, conservando la política vigente HU05/HU20 y FIX-023 pendiente.
El alta manual HU20 utiliza el catálogo existente `/admin/subjects/careers`, que también
incluye ambos estados y no requiere recorrer todas las páginas del nuevo listado.

Creación, edición, estado, eliminación y traslado no se incorporan: falta ratificar
las reglas institucionales HU25. HU21/HU22 carecen de API central de cuentas/roles;
su vista de demostración no ofrece mutaciones ni enlaces de perfiles ficticios.

No se crean migraciones. El trigger HU07 no está versionado; no se añade sin ratificación
del criterio y sin PostgreSQL aislado para validar actor, granularidad y coexistencia
con WRITE/Enrollment. No se alteran datos ni eventos históricos.
