# HU42 / HU43 — Información institucional y FAQ

Tercera épica del portal del Sprint 3. Base `/api/v1`. Los visitantes consultan
exclusivamente la última versión publicada; las modificaciones administrativas
se guardan primero como borrador. No se incorpora un editor visual ni HTML.

## Endpoints

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/portal/information` | Público |
| GET | `/admin/portal/information/sections` | Administrador activo |
| GET | `/admin/portal/information/sections/{id}` | Administrador activo |
| PATCH | `/admin/portal/information/sections/{id}` | Administrador activo |
| POST | `/admin/portal/information/sections/{id}/publish` | Administrador activo |
| POST | `/admin/portal/information/sections/{id}/unpublish` | Administrador activo |
| GET | `/admin/portal/information/faqs` | Administrador activo |
| GET | `/admin/portal/information/faqs/{id}` | Administrador activo |
| POST | `/admin/portal/information/faqs` | Administrador activo |
| PATCH | `/admin/portal/information/faqs/{id}` | Administrador activo |
| POST | `/admin/portal/information/faqs/{id}/publish` | Administrador activo |
| POST | `/admin/portal/information/faqs/{id}/unpublish` | Administrador activo |

La administración reutiliza Sanctum, sesión vigente, cambio de contraseña de
primer acceso y rol ADMINISTRADOR activo (catálogo y asignación). DOCENTE y
colaborador temporal no obtienen privilegios de edición.

## Estructura inicial

La migración crea seis secciones estables, todas sin publicar:

| key | Título inicial |
| --- | --- |
| about | Acerca de EIDA |
| objectives | Objetivos y beneficios |
| student_guide | Orientaciones para estudiantes |
| teacher_guide | Orientaciones para docentes |
| collaborator_guide | Orientaciones para colaboradores |
| contact | Contacto autorizado |

Es exclusivamente la estructura aceptada: las descripciones y enlaces empiezan
vacíos. No se inventan datos de contacto, orientaciones oficiales ni FAQ. El
administrador debe introducir la información aprobada antes de publicarla.
No se permite publicar una sección con descripción vacía. key es estable y no
editable; frontend debe consumir los IDs, sin asumir números fijos.

## Consulta pública

GET `/portal/information` devuelve el conjunto completo visible, sin paginación:

```json
{
  "data": {
    "sections": [
      {
        "id": 1,
        "key": "about",
        "title": "Acerca de EIDA",
        "description": "Texto institucional aprobado.",
        "links": [],
        "sort_order": 1
      }
    ],
    "faqs": [
      {
        "id": 7,
        "question": "Pregunta aprobada",
        "answer": "Respuesta institucional.",
        "sort_order": 1
      }
    ]
  }
}
```

Solo se envían snapshots publicados y activos. Se ordenan por sort_order numérico
de la versión publicada y luego ID. Guardar un orden nuevo en borrador no cambia
el orden público. No se exponen revisiones, responsables ni contenido del borrador.

Sin contenido publicado: 200 con `data.sections: []` y `data.faqs: []`. Frontend
debe mostrar el estado vacío; no sustituirlo por textos institucionales inventados.
Las consultas públicas no modifican registros ni generan auditorías.

## Guardar una sección

PATCH `/admin/portal/information/sections/{id}`:

```json
{
  "title": "Acerca de EIDA",
  "description": "Texto institucional aprobado.",
  "links": [
    { "label": "Sitio institucional", "url": "https://www.umss.edu.bo/" }
  ],
  "sort_order": 1,
  "revision": 1
}
```

PATCH permite campos parciales y exige revision. title: máximo 200 caracteres;
description: máximo 10000. Si se envían, deben ser textos no vacíos. sort_order
es entero entre 0 y 100000. links es un arreglo de hasta 20 enlaces y puede
vaciarse enviando []; cada enlace contiene exclusivamente label (máximo 100)
y url (máximo 2048).

Enlaces admitidos: HTTPS sin credenciales incrustadas, rutas internas que comiencen
por una sola barra y mailto con una dirección de correo válida. Se rechazan
javascript, data, HTTP, destinos relativos al protocolo, barras invertidas y
caracteres de control, incluidas representaciones codificadas. Los enlaces se
validan como destinos; backend no descarga sus URLs. Un enlace a un módulo interno
no otorga permisos sobre ese módulo. Su ruta real debe coordinarse con frontend.

## Crear y editar FAQ

POST `/admin/portal/information/faqs`:

```json
{
  "question": "Pregunta aprobada",
  "answer": "Respuesta institucional.",
  "sort_order": 1
}
```

Responde 201 con un borrador de revision 1. question y answer son obligatorios,
no vacíos, con máximos de 500 y 10000 caracteres. sort_order es opcional y por
defecto 0. PATCH admite cambios parciales con revision obligatoria:

```json
{ "answer": "Respuesta corregida pendiente de publicación.", "revision": 2 }
```

Todo el contenido es texto plano. Frontend debe renderizarlo escapado, sin
insertarlo como HTML. Las FAQ no reciben campos propios de una sección ni enlaces
estructurados; las secciones no reciben question/answer.

## Respuesta administrativa

Detalle y mutaciones devuelven `{ "data": { ... } }`:

```json
{
  "data": {
    "id": 1,
    "key": "about",
    "revision": 4,
    "is_published": true,
    "has_unpublished_changes": true,
    "draft": {
      "title": "Acerca de EIDA",
      "description": "Texto nuevo todavía sin publicar.",
      "links": [],
      "sort_order": 1
    },
    "published": {
      "title": "Acerca de EIDA",
      "description": "Texto público anterior.",
      "links": [],
      "sort_order": 1
    },
    "published_at": "2026-11-05T10:00:00-04:00",
    "created_by": null,
    "updated_by": 10
  }
}
```

FAQ usa question/answer/sort_order dentro de draft y published, sin key.
published y published_at son null antes de la primera publicación. Las secciones
estructurales iniciales tienen created_by null; las FAQ registran a su creador.
updated_by registra al administrador que modifica o publica.

has_unpublished_changes compara ambos contenidos: es true para un nuevo borrador
sin snapshot y false cuando draft coincide con published. No reemplaza a
is_published: un contenido despublicado puede conservar un snapshot idéntico al
borrador, aunque ya no aparezca en público.

Los listados administrativos usan paginación Laravel (data, links, meta): page,
per_page (15 por defecto, máximo 100), search (máximo 200) e is_published (0/1).
search consulta los textos del borrador. Orden por sort_order del borrador e ID.

## Publicar y despublicar

POST del endpoint publish o unpublish correspondiente:

```json
{ "revision": 4 }
```

Publicar copia todo el borrador al snapshot público de forma atómica, activa su
visibilidad y registra la fecha de esa publicación. Despublicar solo retira la
visibilidad: conserva borrador, último snapshot, fecha y auditoría. Permite
desactivar FAQ sin borrarlas; no hay eliminación física ni creación arbitraria
de secciones. Para reactivar se publica nuevamente el borrador actual.

Cada guardado/publicación/despublicación incrementa revision. Se bloquea la fila
y se comprueba la revisión dentro de la transacción. Una auditoría fallida revierte
la operación completa, conservando la versión pública previa.

409 sin sobrescritura:

```json
{
  "code": "REVISION_CONFLICT",
  "message": "El contenido fue modificado. Recarga su información antes de continuar.",
  "current_revision": 5
}
```

Frontend debe recargar y revisar los cambios, sin sobrescribir automáticamente.
401/403 reutilizan las reglas existentes de sesión, cuenta, permisos y primer
acceso. 404 para ID inexistente o para un ID de FAQ usado como sección y viceversa.
422 con message/errors para validación o sección inicial incompleta.

## Persistencia e integración

Migración: `2026_10_31_000003_create_portal_information_contents_table`.
Aplicar `php artisan migrate` en el ambiente backend antes de integrar.

Una tabla nueva, portal_information_contents, contiene kind, key, draft, published,
is_published, revision, published_at, created_by, updated_by y timestamps. Comparte
el flujo de publicación de secciones y FAQ sin mezclar los campos de sus payloads.
Las modificaciones se conservan en audit_logs, ya existente. No se modifican
tablas de Calendario, Noticias, habilitación, roles ni control de ingreso.

Pruebas: `php vendor/phpunit/phpunit/phpunit tests/Feature/Portal/PortalInformationTest.php`.
Incluyen permisos, separación borrador/publicado, reordenamiento, desactivación,
enlaces, revisión, tipos de contenido, paginación y rollback de publicación.

El contenido definitivo debe proporcionarlo el equipo/cliente. HU44/HU45 quedan
para la siguiente épica y HU34 continúa pendiente de definición funcional.
