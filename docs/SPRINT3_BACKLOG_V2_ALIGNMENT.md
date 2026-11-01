# Portal — contraste con Sprint 3 Backlog V.2

Revisión de los 119 CA de HU38–HU45, tomando las fichas refinadas `ID: 38` a
`ID: 45` del archivo V.2. El documento también conserva al principio el alcance
beta; las fichas con sus CA son la referencia de esta revisión. HU34 sigue fuera
de implementación por decisión del equipo.

## Resultado por HU

| HU | CA | Resultado backend / integración |
| --- | --- | --- |
| 38 | 1–13 | Fechas, categorías, detalle y filtros disponibles. CA12: se excluyen eventos PRIVATE y no publicados. Vista mensual, navegación y responsive pertenecen a frontend. |
| 39 | 1–15 | CRUD permitido, estados, filtros, permisos y trazabilidad disponibles. Se agrega consulta del historial por evento para CA13/14. Formulario pendiente de integración frontend. |
| 40 | 1–12 | Listado, detalle, imágenes, búsqueda, orden y exclusión de borradores/archivados disponibles. Home, Ver todas y responsive requieren frontend. |
| 41 | 1–16 | Mutaciones, imágenes, estados, fecha/responsable y auditoría disponibles. Se agrega historial por noticia para CA10/15. |
| 42 | 1–14 | Secciones y FAQ públicas desde snapshots publicados disponibles. Navegación, desplegables, responsive y teclado (CA14) deben verificarse en frontend. |
| 43 | 1–16 | Borrador/publicación, orden, validación, enlaces y permisos disponibles. Se agrega consulta de historial de secciones y FAQ para CA14. |
| 44 | 1–14 | Catálogo activo, destinos, flags de login y permisos existentes cubiertos. Tarjetas, redirección al login, errores visuales y responsive requieren integración frontend. |
| 45 | 1–19 | Validaciones, estado, orden, búsqueda y permisos disponibles. Se agrega historial por servicio para CA16 y prueba explícita de rollback de modificación para CA19. |

El primer ajuste de historial no cambió contratos públicos/escritura ni creó
migraciones. La resolución posterior de CA12 agrega visibility al contrato
administrativo del calendario y una migración de columna, descrita más abajo.
No se modifican roles, HU12–HU16 o ingreso.
Las categorías/contenidos/rutas iniciales siguen pendientes de aprobación y carga;
no se consideran cubiertos por tener únicamente el esquema/API implementados.

## Ajuste necesario: historial administrativo consultable

Los snapshots ya se conservaban en audit_logs, dentro de la transacción de cada
mutación. Sin embargo, el recurso de auditoría general utiliza una lista estricta
de campos que no muestra los textos/snapshots específicos del portal. Persistir
los cambios no bastaba para que el administrador pudiera consultar sus versiones.

Se agregan consultas específicas, sin relajar la protección del visor general:

```text
GET /api/v1/admin/portal/events/{id}/history
GET /api/v1/admin/portal/news/{id}/history
GET /api/v1/admin/portal/information/sections/{id}/history
GET /api/v1/admin/portal/information/faqs/{id}/history
GET /api/v1/admin/portal/services/{id}/history
```

Solo administrador activo, con Sanctum, sesión vigente y primer acceso resuelto.
404 si el recurso no existe o se usa el ID de una FAQ como sección y viceversa.
No hay rutas públicas equivalentes ni operaciones de escritura del historial.

Paginación Laravel: data/links/meta; page >= 1; per_page 15 por defecto, máximo 100.
Orden por created_at descendente e ID descendente. Filtrado obligatorio por tipo
e ID del recurso; los parámetros del cliente no permiten consultar otras entidades.

Cada elemento contiene:

```json
{
  "id": 35,
  "entity_type": "PortalEvent",
  "entity_id": 1,
  "action": "PORTAL_EVENT_UPDATED",
  "actor_id": 10,
  "actor_name": "Nombre del responsable",
  "created_at": "2026-11-01T15:00:00.000000Z",
  "old_values": { "title": "Título anterior", "revision": 1 },
  "new_values": { "title": "Título corregido", "revision": 2 }
}
```

El ejemplo abrevia los snapshots: se incluyen los campos propios del recurso,
como textos, horarios, estado, orden, categoría y revisión. Los extremos del
calendario se presentan en America/La_Paz. Información/FAQ devuelve draft y
published como objetos JSON, no cadenas escapadas. old_values es null al crear.
Una sección estructural inicial puede tener historial vacío hasta su primera
modificación; no se inventa un autor administrativo para la migración.

La respuesta usa listas de campos permitidos para cada tipo de contenido y no
expone credenciales, tokens, direcciones IP ni campos desconocidos. Las noticias
incluyen has_image, sin image_path ni enlaces históricos a archivos privados.
La operación no recupera archivos antiguos eliminados por el flujo acordado de
reemplazo. Los textos y URLs históricos se presentan como texto escapado; no deben
interpretarse como HTML ni como destinos actualmente aprobados.

El endpoint general `/admin/audit-logs` conserva exactamente su contrato y máscara
de datos anterior. Consultar cualquiera de los historiales no genera otra auditoría.

## Resolución de HU38 CA12: eventos privados

QA delegó la decisión al encargado de la HU. Tras revertir la propuesta inicial
para coordinarla, frontend aceptó explícitamente este contrato y el usuario
autorizó su implementación coordinada en Dev_Hector.
Se adopta el alcance mínimo coherente con HU39: un evento privado es una actividad
reservada al módulo administrativo, consultable por administradores activos.

- visibility PUBLIC/PRIVATE es independiente de DRAFT/PUBLISHED/CANCELLED.
- Crear sin visibility conserva PUBLIC como valor inicial y compatibilidad.
- PATCH sin visibility conserva el valor actual, incluso para privados.
- El público exige simultáneamente PUBLISHED y PUBLIC; un ID conocido o filtro
  manipulado no permite leer privados. También se excluyen de los totales.
- Administración devuelve visibility, permite filtrarlo y registra los cambios
  en su historial. Se mantienen revision y 409.
- Pasar un evento PUBLISHED de PRIVATE a PUBLIC lo hace visible inmediatamente;
  frontend debe advertir ese efecto. Un DRAFT no se publica por cambiar visibilidad.
- Pasar un evento PUBLISHED de PUBLIC a PRIVATE lo retira inmediatamente de
  listado, detalle y totales públicos, sin necesidad de cambiar su estado.
- No se agregan agendas personales ni acceso de docentes/colaboradores por grupos.
- Los exámenes privados siguen separados de los eventos institucionales.

Migración nueva: `2026_11_01_000001_add_visibility_to_portal_events`. Agrega una
columna con default PUBLIC e índice; conserva los datos y estados anteriores.
No reescribe las auditorías históricas que todavía no incluían visibility.

## Coordinación con frontend

Los endpoints de historial son una ampliación aditiva. Erick debe incorporarlos
en las vistas administrativas de consulta de historial para satisfacer los CA
indicados. Para Calendario también debe incorporar el selector PUBLIC/PRIVATE,
mostrar la visibilidad en administración y admitir su filtro. Debe aplicar la
migración antes de probar. El resto del contrato aprobado permanece vigente. QA/frontend deben
validar los criterios visuales y de teclado contra la implementación real, no
solo mediante mocks. Esta revisión backend no certifica esos comportamientos UI.
