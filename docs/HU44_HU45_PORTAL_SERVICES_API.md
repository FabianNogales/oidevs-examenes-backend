# HU44 / HU45 — Catálogo de servicios institucionales

Cuarta épica del portal del Sprint 3. Base `/api/v1`. Organiza enlaces a recursos
existentes; no implementa nuevos servicios académicos ni otorga permisos de módulo.

## Endpoints

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/portal/service-categories` | Público |
| GET | `/portal/services` | Público; servicios activos y visibles |
| GET | `/portal/services/{id}` | Público; servicio activo y visible |
| GET | `/admin/portal/service-categories` | Administrador activo |
| GET | `/admin/portal/service-options` | Administrador activo |
| GET | `/admin/portal/services` | Administrador activo |
| GET | `/admin/portal/services/{id}` | Administrador activo |
| POST | `/admin/portal/services` | Administrador activo |
| PATCH | `/admin/portal/services/{id}` | Administrador activo |
| PATCH | `/admin/portal/services/{id}/status` | Administrador activo |

Administración reutiliza Sanctum, sesión vigente, cambio de contraseña de primer
acceso y ADMINISTRADOR activo (cuenta, catálogo de rol y asignación). La autorización
temporal de colaborador no concede privilegios para gestionar el catálogo.

## Categorías y opciones

Ambos endpoints de categorías devuelven categorías activas, ordenadas por nombre
e ID: `{ "data": [{ "id": 1, "name": "Categoría aprobada" }] }`. Los IDs no son fijos.

GET `/admin/portal/service-options`:

```json
{
  "data": {
    "icon_keys": ["calendar", "book", "info", "link", "users"],
    "destination_types": ["INTERNAL", "EXTERNAL"],
    "internal_destinations": []
  }
}
```

Estas opciones se centralizan en config/portal_services.php. Frontend obtiene las
claves permitidas desde backend y renderiza los iconos correspondientes; no envía
HTML/SVG ni mantiene otra lista de destinos válidos.

**Pendiente de coordinación:** las rutas reales de Dev_Erick todavía no fueron
confirmadas. internal_destinations empieza vacío y backend rechaza la creación
de destinos internos mientras no estén registrados. No se usa como ruta definitiva
el ejemplo anterior /estudiante/examenes ni se deducen rutas de otra rama.

Después del acuerdo, cada opción interna tendrá este formato (ejemplo de esquema,
no una ruta ya cargada):

```json
{
  "path": "/ruta-confirmada",
  "label": "Nombre aprobado",
  "requires_auth": true
}
```

Las rutas internas de catálogo son rutas concretas sin parámetros, query ni fragmento.
Se aceptan solamente coincidencias exactas con esas opciones. Las entradas de
configuración con destinos inseguros no se ofrecen como opciones válidas.

Las categorías iniciales y los servicios institucionales también deben acordarse
antes de cargarlos. Esta migración no inventa categorías, URLs ni servicios.
No se incorpora CRUD de categorías, que no estaba en el contrato aprobado.

## Crear y editar

POST `/admin/portal/services`:

```json
{
  "name": "Sitio institucional",
  "description": "Descripción aprobada del recurso.",
  "icon_key": "link",
  "category_id": 1,
  "destination_type": "EXTERNAL",
  "destination": "https://www.umss.edu.bo/",
  "requires_auth": false,
  "sort_order": 1
}
```

201 con el recurso administrativo, status INACTIVE y revision 1. Crear no publica
automáticamente. La activación se realiza con el endpoint de estado.

name: obligatorio, máximo 200; description: obligatoria, máximo 2000; category_id:
categoría activa existente; icon_key: clave de service-options; destination_type:
INTERNAL o EXTERNAL; destination: máximo 2048. sort_order es entero entre 0 y 100000,
opcional con valor inicial 0. requires_auth es booleano opcional: por defecto false
para externos y el requisito de la opción elegida para internos.

PATCH admite cambios parciales y exige revision:

```json
{ "name": "Nombre corregido", "sort_order": 2, "revision": 1 }
```

Se valida el recurso completo después de combinar los campos guardados con los
nuevos; cambiar solo destination_type no permite eludir la validación de la URL.
status, created_by y updated_by no se pueden imponer mediante creación/edición.
Los textos son planos y frontend debe renderizarlos escapados.

## Destinos y autorización

EXTERNAL exige HTTPS válido, sin credenciales incrustadas. Se rechazan HTTP,
javascript, data, mailto, URLs relativas al protocolo, barras invertidas y caracteres
de control, incluidas representaciones codificadas. Backend valida la URL pero
no la descarga ni actúa como proxy; frontend abre el destino según su interfaz.

INTERNAL exige una opción backend exacta. No acepta rutas inventadas, destinos
externos ni variaciones mediante query/fragmento. Si la opción exige login, no se
permite guardar requires_auth=false. Se puede exigir login adicional para una ruta
pública. El campo orienta a frontend para llevar al login cuando corresponda.

**requires_auth no es una autorización.** Los roles y permisos siguen comprobándose
en cada módulo. Consultar la tarjeta de un servicio no cambia el rol ni concede
acceso a funciones protegidas. Frontend conserva las protecciones de sus rutas.

Si una opción interna pasa posteriormente a exigir login, la respuesta pública
refleja ese requisito aunque el registro anterior todavía tuviera false. El panel
administrativo conserva el valor guardado para que pueda corregirse expresamente.

## Consulta pública y recurso

```json
{
  "data": {
    "id": 1,
    "name": "Sitio institucional",
    "description": "Descripción aprobada del recurso.",
    "icon_key": "link",
    "category": { "id": 1, "name": "Categoría aprobada" },
    "destination_type": "EXTERNAL",
    "destination": "https://www.umss.edu.bo/",
    "requires_auth": false,
    "sort_order": 1
  }
}
```

Administración agrega status, revision, created_by y updated_by. El público solo
ve servicios ACTIVE con categoría activa; para internos, la ruta debe seguir en
las opciones permitidas. Un destino retirado de la configuración deja de aparecer
en listado y detalle públicos, pero se conserva en administración para corregirlo
o desactivarlo. Los totales de paginación reflejan únicamente el conjunto visible.

Listados: search (máximo 200; nombre/descripción como texto literal), category_id,
page y per_page. Administración agrega status=ACTIVE/INACTIVE. En público enviar
status no revela servicios inactivos. Orden numérico por sort_order y luego ID.
Paginación Laravel data/links/meta con current_page, last_page, per_page y total;
15 por defecto, máximo 100. Vacío: 200 con data [].

## Activar y desactivar

PATCH `/admin/portal/services/{id}/status`:

```json
{ "status": "ACTIVE", "revision": 2 }
```

Estados: ACTIVE e INACTIVE. Activar revalida categoría, icono, destino y requisito
de login. Desactivar sigue permitido aunque el destino haya sido retirado de
las opciones, para no impedir que el administrador oculte un registro anterior.
No hay eliminación física. Editar un servicio que continúa ACTIVE modifica la
tarjeta pública al guardar; desactivarlo primero si se quiere prepararlo fuera
del portal. No existe el versionado draft/published de HU42/HU43 en esta épica.

Cada modificación incrementa revision y registra una auditoría en la misma
transacción. Se bloquea la fila y se vuelve a comprobar la revisión antes de guardar.
Si falla la auditoría, se revierte la operación.

409 sin sobrescritura:

```json
{
  "code": "REVISION_CONFLICT",
  "message": "El servicio fue modificado. Recarga su información antes de continuar.",
  "current_revision": 3
}
```

Frontend debe recargar y presentar los cambios antes de volver a guardar.
401/403 mantienen el contrato existente de autenticación, cuenta y permisos.
404 para servicio inexistente o no visible públicamente. 422 con message/errors
por campos inválidos, opción no admitida, categoría inactiva o revisión ausente.

## Persistencia y despliegue

Migración: `2026_10_31_000004_create_portal_service_tables`. Aplicar `php artisan migrate`
en el backend antes de integrar. Crea dos tablas:

- portal_service_categories: id, name, is_active y timestamps.
- portal_services: id, category_id, name, description, icon_key, destination_type,
  destination, requires_auth, sort_order, status, revision, created_by, updated_by
  y timestamps.

No cambia tablas previas ni añade roles o privilegios. Reutiliza audit_logs.
Cuando se coordinen rutas, actualizar la configuración backend y regenerar su
caché si el despliegue utiliza config:cache. Frontend seguirá consumiendo options.

Pruebas: `php vendor/phpunit/phpunit/phpunit tests/Feature/Portal/PortalServiceTest.php`.
Cubren permisos, activación, opciones, URLs, iconos, categorías, revisión, orden,
paginación, retiro de rutas y ausencia de concesión de privilegios.

Con esta épica queda implementada la API base de HU38–HU45. Siguen pendientes el
contenido institucional, categorías/rutas acordadas e integración frontend.
HU34 continúa pendiente de definición funcional; no se cambia HU12 ni el ingreso.
