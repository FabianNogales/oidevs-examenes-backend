# HU44/HU45 — Catálogo de enlaces externos oficiales UMSS

Este contrato reemplaza el enfoque anterior de accesos internos. Base `/api/v1`.
Servicios solo administra enlaces externos; no integra plataformas ni transfiere sesiones EIDA.

## Endpoints finales

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/portal/services` | Público, sin login |
| GET | `/portal/services/{id}` | Público, solo enlace activo y autorizado |
| GET | `/admin/portal/services` | ADMINISTRADOR activo |
| POST | `/admin/portal/services` | ADMINISTRADOR activo |
| GET | `/admin/portal/services/{id}` | ADMINISTRADOR activo |
| PATCH | `/admin/portal/services/{id}` | ADMINISTRADOR activo |
| PATCH | `/admin/portal/services/{id}/status` | ADMINISTRADOR activo |
| GET | `/admin/portal/services/{id}/history` | ADMINISTRADOR activo |

Se mantiene PATCH de estado como equivalente a activate/deactivate. No se agregan otros endpoints de estado. Autenticación Sanctum, sesión vigente y cambio inicial de contraseña en administración.

Se retiran `/admin/portal/service-options`, `/admin/portal/service-categories` y `/portal/service-categories`: devuelven 404. No solicitar esas rutas desde frontend. Las categorías ya no forman parte del formulario ni del contrato.

## Crear

POST `/admin/portal/services`

```json
{
  "name": "WebSISS",
  "description": "Acceso al sistema de información estudiantil de la UMSS.",
  "icon": "academic",
  "url": "https://websiss.umss.edu.bo/",
  "display_order": 1
}
```

Responde 201 y crea INACTIVE, revision=1. No publica automáticamente.

- name obligatorio: 1–200 caracteres.
- description obligatoria: 1–2000 caracteres, texto plano.
- icon obligatorio: academic, calendar, book, info, link o users. Claves semánticas; frontend las asocia a sus componentes. Sin HTML/SVG arbitrario.
- url obligatoria: máximo 2048 caracteres y validación institucional.
- display_order opcional: entero 0–100000, por defecto 0.
- No enviar revision ni status en creación.
- category_id, icon_key, destination_type, destination, requires_auth y sort_order quedan fuera del contrato y se rechazan si se envían con contenido.

No se exige autenticación EIDA para abrir el enlace externo. La plataforma destino administra su propio login.

## Modificar

PATCH `/admin/portal/services/{id}`

```json
{
  "revision": 1,
  "name": "Información estudiantil",
  "description": "Consulta de información académica.",
  "icon": "academic",
  "url": "https://websiss.umss.edu.bo/",
  "display_order": 2
}
```

Los campos editables son parciales; revision es obligatoria. Se revalida el registro completo antes de guardar. Incrementa revision. Editar un enlace que sigue ACTIVE actualiza inmediatamente su tarjeta pública.

## Activar/desactivar

PATCH `/admin/portal/services/{id}/status`

```json
{"status":"ACTIVE","revision":2}
```

Desactivar con status=INACTIVE y revisión actual. No se elimina el registro. Activar revalida URL y contenido; desactivar permite retirar incluso un registro legado inválido. ACTIVE/INACTIVE son los únicos estados, sin publicación adicional.

## Respuesta pública

```json
{
  "data": [
    {
      "id": 12,
      "name": "WebSISS",
      "description": "Acceso al sistema de información estudiantil de la UMSS.",
      "icon": "academic",
      "url": "https://websiss.umss.edu.bo/",
      "display_order": 1
    }
  ],
  "links": {},
  "meta": {"current_page":1,"per_page":15,"total":1,"last_page":1}
}
```

links/meta contienen la paginación Laravel habitual; se muestran abreviados en el ejemplo. Público no devuelve status, revision, responsables, categorías ni requires_auth. Solo ACTIVE con destino externo y validación vigente. Orden display_order ascendente, luego id ascendente. Sin resultados: data=[] y meta.total=0.

EIDA entrega la URL registrada sin agregar JWT, cookies, tokens ni parámetros de sesión. Frontend abrirá mediante enlace externo, preferentemente target="_blank" y rel="noopener noreferrer", sin anexar credenciales. Accesibilidad, mensajes vacíos/error y responsive corresponden a frontend. No usar innerHTML para los textos.

## Respuesta administrativa

Creación, detalle y edición devuelven `data` con:

```json
{
  "id":12,
  "name":"WebSISS",
  "description":"Acceso al sistema de información estudiantil de la UMSS.",
  "icon":"academic",
  "url":"https://websiss.umss.edu.bo/",
  "display_order":1,
  "status":"INACTIVE",
  "revision":1,
  "created_by":1,
  "updated_by":1,
  "created_at":"2026-11-03T20:00:00.000000Z",
  "updated_at":"2026-11-03T20:00:00.000000Z",
  "needs_review":false,
  "validation_issues":[]
}
```

Administración lista ACTIVE e INACTIVE y conserva visibles los registros anteriores que requieran corrección. needs_review=true y validation_issues identifica campos inválidos, por ejemplo ["url"]. Un antiguo destino interno puede aparecer como url relativa solo en administración para permitir sustituirlo por una URL institucional; nunca se sirve públicamente.

## Búsqueda y paginación

GET administrativo: `?search=&status=&page=1&per_page=15`.

- search: búsqueda literal por nombre, sin distinguir mayúsculas. Vacío/omitido = sin filtro.
- status: ACTIVE o INACTIVE. Vacío/omitido = ambos en administración.
- page >=1; per_page 1–100, por defecto 15.
- Público admite búsqueda/paginación, pero no permite solicitar inactivos mediante status.
- Filtrado y validación se aplican antes de paginar: meta.total es el total filtrado.
- category_id ya no es un filtro válido.

## Dominios autorizados y seguridad

Configuración central `config/portal_services.php`:

```dotenv
EIDA_SERVICE_ALLOWED_DOMAINS=umss.edu.bo
EIDA_SERVICE_ALLOW_SUBDOMAINS=true
```

Por defecto permite el dominio exacto umss.edu.bo y subdominios con separación por punto, por ejemplo websiss.umss.edu.bo. Comparación sin distinguir mayúsculas. `umss.edu.bo.evil.com` y `notumss.edu.bo` se rechazan. Pueden configurarse varios dominios separados por comas; no utilizar comodines. allow_subdomains=false limita a coincidencia exacta.

Solo HTTPS, puerto omitido o 443, sin usuario/contraseña embebidos. Se rechazan rutas internas, javascript:, data:, URLs relativas, controles, barras inversas, etiquetas y parámetros de autenticación como token, access_token, jwt, password, session_id, authorization, api_key y code, también codificados y en fragmentos. No se contacta el sitio externo ni se integran sus bases de datos.

Nombre/descripción son texto plano y se rechazan etiquetas HTML y controles ejecutables. Backend no interpreta contenido; frontend debe renderizarlo como texto.

Cambiar la lista autorizada afecta también a registros anteriores: los que dejen de cumplir se ocultan inmediatamente del público y quedan administrables con needs_review. No se borran ni se altera el historial.

## Auditoría y errores

Se mantienen PORTAL_SERVICE_CREATED, PORTAL_SERVICE_UPDATED y PORTAL_SERVICE_STATUS_CHANGED con responsable, fecha y valores previos/nuevos. Historial paginado por fecha/id descendentes. Sus valores usan icon/url/display_order, traduciendo los nombres históricos de almacenamiento sin reescribir auditorías anteriores.

- 401: falta de sesión.
- 403: sin permisos administrativos o requisito de contraseña pendiente.
- 404: recurso inexistente, enlace no disponible públicamente o endpoint retirado.
- 422: campos obligatorios, icono, URL/dominio, contenido, estado o parámetros inválidos; errors identifica el campo.
- 409: REVISION_CONFLICT, con current_revision. Actualizar datos antes de reintentar.
- 500: INTERNAL_ERROR con mensaje controlado; no expone trazas ni detalles internos, incluso si falla la auditoría. La transacción revierte cambios parciales.

## Migración y transición

Actualizar Dev_Hector y ejecutar:

```bash
php artisan migrate
php artisan config:clear
```

Migración `2026_11_03_000001_adapt_portal_services_to_external_links`:

- category_id pasa a nullable; no se exige al crear.
- Los antiguos INTERNAL activos pasan a INACTIVE, incrementan revision y generan auditoría de sistema PORTAL_SERVICE_INTERNAL_RETIRED, con actor null.
- Se conservan servicios, categorías anteriores y auditorías. Los antiguos externos inválidos quedan fuera del público y disponibles para corrección.
- No se eliminan columnas históricas: icon/url/display_order se mapean a icon_key/destination/sort_order internamente. Nuevos registros siempre EXTERNAL y requires_auth=false. Esos campos internos no se aceptan ni exponen en el contrato.
- Se retiran el seeder de categorías de Servicios y la configuración /teacher/exams; ya no deben ejecutarse ni consumirse para HU44/HU45.
- El rollback no reactiva enlaces internos ni elimina registros sin categoría; revertir el enfoque requiere una decisión explícita sobre los datos.

No se cargan ni publican automáticamente enlaces de ejemplo. Los administradores registran los enlaces elegidos para la demostración. No cambia HU34, calendario, noticias, información institucional ni los permisos de los módulos internos de EIDA.
