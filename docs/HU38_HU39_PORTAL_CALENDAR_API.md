# HU38 / HU39 — Calendario institucional

Actualización V.2: historial administrativo `GET /admin/portal/events/{id}/history`.
Ver `SPRINT3_BACKLOG_V2_ALIGNMENT.md` para contrato y resolución de HU38 CA12.

Actualización de privacidad: visibility PUBLIC/PRIVATE es independiente del estado.
Contrato aceptado explícitamente por frontend tras la coordinación de HU38 CA12.
Los eventos privados se consultan exclusivamente en administración. Aplicar también
la migración `2026_11_01_000001_add_visibility_to_portal_events` antes de integrar.

Contrato implementado para la primera épica del portal del Sprint 3. Los eventos
son recursos independientes: no se leen ni publican exámenes privados.

## Rutas

Base `/api/v1`.

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/portal/event-categories` | Público |
| GET | `/portal/events` | Público; solo PUBLISHED y PUBLIC |
| GET | `/portal/events/{id}` | Público; 404 si es privado o no está publicado |
| GET | `/admin/portal/event-categories` | Administrador activo |
| GET | `/admin/portal/events` | Administrador activo |
| GET | `/admin/portal/events/{id}` | Administrador activo |
| POST | `/admin/portal/events` | Administrador activo |
| PATCH | `/admin/portal/events/{id}` | Administrador activo |
| PATCH | `/admin/portal/events/{id}/status` | Administrador activo |

La administración reutiliza autenticación Sanctum, sesión vigente, cambio de
contraseña de primer acceso y rol ADMINISTRADOR activo (catálogo y asignación).
Un DOCENTE o colaborador temporal no obtiene estos permisos.

## Crear un evento

```json
{
  "title": "Título acordado por la institución",
  "description": "Texto plano de la actividad.",
  "starts_at": "2026-11-05T09:00:00-04:00",
  "ends_at": "2026-11-05T11:00:00-04:00",
  "all_day": false,
  "visibility": "PUBLIC",
  "location": "Auditorio",
  "category_id": 1
}
```

201 con `{ "data": { ... } }`. Siempre se crea como DRAFT con revision 1.
El ID de categoría del ejemplo es ilustrativo: consumir el catálogo.

Obligatorios: title (máximo 200), description (máximo 10000), category_id activo,
starts_at y ends_at. all_day es opcional y por defecto false; location es opcional,
nullable y de máximo 255 caracteres. Contenido de texto plano: frontend debe
renderizarlo como texto, no como HTML.

visibility es opcional al crear, con valor inicial PUBLIC por compatibilidad.
Admite únicamente PUBLIC y PRIVATE. En PATCH, omitirlo conserva el valor actual.
La respuesta administrativa agrega visibility; la respuesta pública mantiene sus
campos anteriores, ya que nunca contiene eventos privados.

Las fechas requieren segundos y offset: `YYYY-MM-DDTHH:mm:ss±HH:mm`.
Se almacenan instantes UTC y se devuelven en America/La_Paz.
El fin debe ser estrictamente posterior al inicio. Si all_day es true, ambos
extremos deben equivaler a medianoche en Bolivia; el final es excluido. Un evento
de todo el 5 de noviembre comienza el 5 a las 00:00 y termina el 6 a las 00:00.

## Recurso devuelto

```json
{
  "data": {
    "id": 1,
    "title": "Título acordado por la institución",
    "description": "Texto plano de la actividad.",
    "starts_at": "2026-11-05T09:00:00-04:00",
    "ends_at": "2026-11-05T11:00:00-04:00",
    "all_day": false,
    "location": "Auditorio",
    "category": { "id": 1, "name": "Categoría aprobada" },
    "status": "PUBLISHED",
    "visibility": "PUBLIC",
    "published_at": "2026-11-01T10:00:00-04:00",
    "revision": 2,
    "created_by": 10,
    "updated_by": 10
  }
}
```

revision, visibility, created_by y updated_by se devuelven exclusivamente en administración.
published_at es null antes de la primera publicación y conserva esa primera fecha
al despublicar, cancelar y volver a publicar. Las modificaciones posteriores quedan
registradas en auditoría. No se exponen responsables internos al visitante.

## Editar y cambiar estado

PATCH del evento admite campos parciales y requiere revision. Se revalida el
intervalo completo, incluso cuando solo se envía uno de sus extremos.

```json
{ "title": "Nuevo título", "revision": 2 }
```

PATCH `/status`:

```json
{ "status": "PUBLISHED", "revision": 3 }
```

Estados permitidos: DRAFT, PUBLISHED, CANCELLED. DRAFT despublica; CANCELLED retira
el evento del público conservando el registro. Un administrador puede cambiarlo
de nuevo mediante este endpoint. No hay eliminación física. Editar un evento que
sigue PUBLISHED actualiza su contenido público al guardar; para preparar cambios
fuera del público se debe despublicar primero. Esta épica no tiene la separación
draft/published de versiones prevista para HU42/HU43.

Un PRIVATE puede estar DRAFT, PUBLISHED o CANCELLED y siempre queda fuera del portal
público. Publicar un PRIVATE no cambia su visibilidad. Todos los administradores
activos pueden consultarlo mediante listado/detalle administrativos e historial;
no se crean agendas personales, propietarios exclusivos ni permisos por grupos.

Cambiar la visibilidad usa el PATCH habitual y revision:

```json
{ "visibility": "PRIVATE", "revision": 2 }
```

El cambio afecta la consulta pública inmediatamente. Si se cambia de PRIVATE a
PUBLIC un evento ya PUBLISHED, empieza a aparecer sin otra transición de estado;
frontend debe mostrar claramente ese efecto antes de confirmar. Si sigue DRAFT
o CANCELLED, pasar a PUBLIC no lo publica. Cada cambio se registra en el historial.
En el sentido inverso, PUBLISHED/PUBLIC pasa a quedar oculto inmediatamente al
cambiar a PRIVATE, tanto en listado como en detalle y totales públicos.

Cada modificación exitosa incrementa revision. El estado, published_at y los
responsables no pueden alterarse por el endpoint de edición o creación.

Una revisión antigua produce 409 sin modificaciones ni auditorías adicionales:

```json
{
  "code": "REVISION_CONFLICT",
  "message": "El evento fue modificado. Recarga su información antes de continuar.",
  "current_revision": 4
}
```

Frontend debe recuperar el evento y presentar los datos nuevos, sin reintentar
automáticamente una sobrescritura con la revisión actualizada.

Las mutaciones y su auditoría son una única transacción; una falla de auditoría
revierte el evento. Las modificaciones concurrentes bloquean la fila y comprueban
revision dentro de esa operación.

## Listados y categorías

Filtros: search (máximo 200), category_id, from, to, page y per_page. Solo en
administración status filtra por estado. En público nunca permite ver borradores
ni cancelados, aunque se envíe status.

Administración admite también visibility=PUBLIC o PRIVATE, combinable con los
demás filtros. En la ruta pública ese filtro no amplía acceso: incluso solicitando
PRIVATE solo se devuelven PUBLISHED/PUBLIC y los totales excluyen los privados.

from y to son opcionales, pero deben enviarse juntos en formato YYYY-MM-DD, con
to >= from y máximo 366 días calendario inclusivos. Sin ellos se pagina todo el
conjunto visible. Se incluyen eventos que se superpongan con el intervalo, no
solo aquellos cuyo inicio caiga dentro. El intervalo se calcula con los días
completos de America/La_Paz; un evento que termina justo al comenzar from no se
incluye. search consulta título, descripción y ubicación como texto literal.

Orden: starts_at ascendente, luego ID ascendente. Paginación Laravel con data,
links y meta (current_page, last_page, per_page, total); por defecto 15 y máximo
100. Para completar un calendario mensual hay que consumir las páginas necesarias.

Los catálogos devuelven `{ "data": [{ "id": 1, "name": "..." }] }`, con categorías
activas ordenadas por nombre e ID. Vacío: 200 con data []. No hay endpoints de
escritura de categorías porque no forman parte del alcance aprobado.

**Pendiente de contenido:** el equipo todavía debe acordar las categorías iniciales.
La migración crea la estructura sin inventar ni sembrar un catálogo definitivo.
Sin categorías aprobadas cargadas, se puede consultar el vacío, pero no crear
eventos desde la interfaz. La carga institucional del catálogo debe coordinarse
antes de la prueba de integración de creación.

## Despliegue y verificación

Migración nueva: `2026_10_31_000001_create_portal_calendar_tables`.
Crea portal_event_categories y portal_events; no modifica tablas de Sprint 2.
La migración posterior `2026_11_01_000001_add_visibility_to_portal_events` agrega
visibility con default PUBLIC e índice para el calendario público. Conserva los
eventos anteriores y no modifica su estado, revisión, fechas ni responsables.
Aplicar con `php artisan migrate` en el ambiente correspondiente antes de consumir
los endpoints. No ejecutar migrate:fresh sobre datos existentes.

Prueba automatizada: `php vendor/phpunit/phpunit/phpunit tests/Feature/Portal/PortalCalendarTest.php`.
Incluye permisos, visibilidad, validación, revisión obsoleta, auditoría atómica,
rangos, paginación, medianoche y zonas horarias.

HU40–HU45 no están implementadas en esta entrega. HU34 permanece pendiente de
reglas académicas y no cambia HU12 ni introduce PENDING como estado persistente.

## Inicialización automática de categorías — corrección HU39

Ejecutar `php artisan migrate --force` en el despliegue aplica
`2026_11_04_000002_initialize_portal_calendar_category` y crea
`Actividades académicas` con `is_active=true` únicamente si no existe.
No requiere ejecutar un seeder manual adicional. La restricción UNIQUE(name)
y la inserción insertOrIgnore impiden duplicados. El seeder específico
`PortalCalendarDemoCategorySeeder` sigue disponible y es repetible.
Ni migración ni seeder reactivan una categoría existente desactivada deliberadamente.
El rollback no elimina categorías ni modifica eventos históricos.

Contrato vigente, sin cambios:
- GET /api/v1/portal/event-categories: público.
- GET /api/v1/admin/portal/event-categories: administrador activo autenticado.
- Respuesta 200: {"data":[{"id":1,"name":"Actividades académicas"}]}.
- Sin paginación. Solo activas, ordenadas por name e id. is_active no se serializa.
- IDs de ejemplo: usar siempre el ID real devuelto por la API.
- Sin categorías activas: 200 con data: [].
- Administración: 401 sin sesión válida, 403 sin permisos o cambio inicial pendiente.
- POST/PATCH de eventos recibe category_id; respuesta conserva category.id/category.name.
- Crear o enviar una categoría inactiva/inexistente: 422 con errors.category_id.
- Publicar con categoría inactiva: 422. Revisión obsoleta: 409 REVISION_CONFLICT.
- Desactivar categoría no oculta eventos ya publicados ni borra su relación;
  la visibilidad pública sigue dependiendo de status=PUBLISHED y visibility=PUBLIC.
- PATCH de un evento histórico omitiendo category_id conserva su categoría, aunque esté
  inactiva; enviarla expresamente exige que esté activa.
- No existe CRUD administrativo de categorías en el contrato vigente.
