# Categorías de Calendario y Noticias

Base `/api/v1`. Catálogos independientes. Gestión: ADMIN activo, Sanctum,
sesión vigente y contraseña inicial cambiada. Sin eliminación física.

## Selectores (sin cambios)

GET `/portal/event-categories` y `/portal/news-categories`: públicos.
GET `/admin/portal/event-categories` y `/admin/portal/news-categories`: ADMIN.

200: `{"data":[{"id":1,"name":"Académico"}]}`. Solo activas, sin paginación,
ordenadas por nombre e ID. Vacío: `{"data":[]}`. IDs dinámicos.

## Gestión

Sustituir `{catalog}` por `event` o `news`:

| Método | Ruta | Entrada |
| --- | --- | --- |
| GET | `/admin/portal/{catalog}-categories/manage` | search, is_active=true/false, page, per_page |
| POST | `/admin/portal/{catalog}-categories/manage` | name |
| PATCH | `/admin/portal/{catalog}-categories/manage/{id}` | name, revision |
| PATCH | `/admin/portal/{catalog}-categories/manage/{id}/status` | is_active boolean, revision |
| GET | `/admin/portal/{catalog}-categories/manage/{id}/history` | page, per_page |

Crear: 201 con `{"data":{"id":5,"name":"Investigación","is_active":true,"revision":1}}`.
Editar/estado: 200 con la misma estructura actualizada. Cambio real incrementa
revision; no-op conserva revisión y no genera auditoría ficticia.

Nombre: obligatorio, texto plano hasta 100 caracteres, trim, único sin distinguir
mayúsculas dentro de su propio catálogo. Duplicados concurrentes se rechazan en BD.
No se aceptan campos extra en mutaciones. No POST con estado arbitrario.

Listado: data con id/name/is_active/revision, meta current_page/per_page/total/last_page.
Default 15, máximo 100. Search vacío sin filtro. Sin filtro de estado incluye ambas.

Historial: data con id/entity_type/entity_id/action/actor_id/actor_name/created_at/
old_values/new_values, más meta. Auditoría transaccional. Fechas ISO 8601.

## Noticias

POST `/admin/portal/news`: title, summary, content y **category_id obligatorio**.
PATCH `/admin/portal/news/{id}`: revision obligatoria, category_id opcional.
Si se envía, debe existir en Noticias y estar activa. Null no permitido.
Omitirlo conserva la asociación actual incluso si está inactiva o es null histórica.
No se asignan categorías ficticias a noticias anteriores.

Listado/detalle agrega `category: {id,name} | null`. Listados público y ADMIN
aceptan category_id positivo, aplicado antes de paginar. Un ID sin coincidencias
devuelve listado vacío. Se conserva el resto del recurso y permisos existentes.

Desactivar categoría no oculta noticias publicadas ni impide consultar su categoría.
La publicación de noticias conserva su comportamiento: no se introduce un requisito
nuevo que bloquee la publicación de noticias históricas con categoría null/inactiva.

## Calendario

Se conserva category_id obligatorio al crear, opcional en PATCH y category {id,name}
en el recurso. Al enviar category_id debe ser activa. PATCH sin categoría conserva
la asociación histórica. Publicar con categoría inactiva: 422. Desactivarla después
no oculta eventos PUBLISHED+PUBLIC. Categorías se revalidan bajo bloqueo al guardar.

## Errores

- 401 sin sesión; 403 sin ADMIN activo o contraseña inicial pendiente.
- 404 categoría inexistente en edición/estado/historial.
- 422 validación Laravel message+errors: name duplicado/inválido, category_id
  inexistente/inactivo al asignar, null, revisión ausente o filtros inválidos.
- 409 `REVISION_CONFLICT`, message y current_revision para edición concurrente.
- DELETE no disponible (405 para el recurso administrativo).

## Migración y despliegue

`php artisan migrate --force` aplica `2026_11_09_000001_manage_portal_categories`.
Añade revision a categorías de eventos, tabla portal_news_categories y FK nullable
portal_news.category_id. Inicializa Académico, Institucional, Tecnología,
Convocatorias y asegura Actividades académicas si falta. No requiere seeder manual.
No reactiva categorías existentes inactivas ni cambia eventos/noticias históricos.
Migraciones registradas una vez y claves únicas evitan duplicados en redespliegues.
Rollback de esta migración conserva datos y asociaciones intencionalmente.

Si existían nombres de categorías de eventos que solo difieren en mayúsculas o
espacios, el índice único normalizado rechaza la migración en vez de fusionar IDs
históricos silenciosamente. Revisar esos duplicados antes del despliegue.

Frontend debe desplegarse coordinadamente: crear noticias ahora requiere category_id.
No usar nombres ni IDs fijos para identificar categorías.

Ejemplos HTTP obtenidos en local: [respuestas de selectores y gestión](examples/portal_categories_responses.json).
