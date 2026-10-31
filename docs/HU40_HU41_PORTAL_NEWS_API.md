# HU40 / HU41 — Noticias institucionales

Segunda épica implementada del portal del Sprint 3, independiente del calendario.
Base `/api/v1`. No incorpora comentarios, reacciones ni editor HTML.

## Endpoints

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | `/portal/news` | Público; solo PUBLISHED |
| GET | `/portal/news/{id}` | Público; solo PUBLISHED |
| GET | `/portal/news/{id}/image` | Público; solo PUBLISHED con imagen |
| GET | `/admin/portal/news` | Administrador activo |
| GET | `/admin/portal/news/{id}` | Administrador activo |
| POST | `/admin/portal/news` | Administrador activo |
| PATCH | `/admin/portal/news/{id}` | Administrador activo |
| PATCH | `/admin/portal/news/{id}/status` | Administrador activo |
| GET | `/admin/portal/news/{id}/image` | Administrador activo |
| POST | `/admin/portal/news/{id}/image` | Administrador activo |
| DELETE | `/admin/portal/news/{id}/image` | Administrador activo |

Administración reutiliza Sanctum, validación de sesión, cambio de contraseña de
primer acceso y ADMINISTRADOR activo (cuenta, rol y asignación). La autorización
temporal como colaborador no permite gestionar noticias.

## Crear y editar

POST crea siempre un DRAFT con revision 1 y responde 201:

```json
{
  "title": "Comunicado institucional",
  "summary": "Resumen de la publicación.",
  "content": "Contenido completo.\nSegunda línea."
}
```

Obligatorios al crear: title (máximo 200), summary (máximo 1000) y content
(máximo 50000). Son textos planos; frontend debe mostrarlos como texto escapado,
respetando saltos de línea, sin insertarlos como HTML.

PATCH admite campos parciales y exige revision:

```json
{ "summary": "Resumen corregido.", "revision": 1 }
```

No se pueden proporcionar status, image_path, image_url, published_at ni los
responsables mediante estos endpoints. Se gestionan por operaciones específicas.

## Respuestas

Detalle público:

```json
{
  "data": {
    "id": 1,
    "title": "Comunicado institucional",
    "summary": "Resumen de la publicación.",
    "content": "Contenido completo.\nSegunda línea.",
    "image_url": null,
    "published_at": "2026-11-05T10:00:00-04:00"
  }
}
```

Administración agrega status, revision, created_by, updated_by y published_by.
published_by y published_at son null antes de la primera publicación; después
conservan el primer responsable y la primera fecha. Se auditan las publicaciones
y modificaciones posteriores. Las fechas se devuelven con offset America/La_Paz.

Los listados, públicos y administrativos, omiten content. El detalle sí lo incluye.
image_path nunca se expone. image_url es null si no se adjuntó una imagen.

## Listados

```text
GET /portal/news?search=comunicado&page=1&per_page=6
GET /admin/portal/news?search=comunicado&status=DRAFT&page=1&per_page=15
```

search busca texto literal en título, resumen y contenido (máximo 200 caracteres).
status solo filtra en administración; enviarlo a la consulta pública no revela
borradores o archivados. Orden por published_at descendente y luego ID descendente;
en administración los borradores nunca publicados aparecen al final.

Paginación Laravel: data, links y meta, con current_page, last_page, per_page y
total. Por defecto 15 elementos; per_page entre 1 y 100. Para el Home frontend
puede solicitar per_page=6. Sin resultados: 200 con data [].

## Publicar, despublicar y archivar

PATCH `/admin/portal/news/{id}/status`:

```json
{ "status": "PUBLISHED", "revision": 2 }
```

Estados: DRAFT, PUBLISHED y ARCHIVED. Volver a DRAFT despublica. ARCHIVED conserva
el registro fuera del portal. Se permite volver a publicar mediante el mismo
endpoint, conservando la primera fecha de publicación. No hay eliminación física.

Guardar una edición de una noticia que continúa PUBLISHED actualiza su contenido
público inmediatamente. Para prepararla fuera del público se debe despublicar
primero. Esta épica no tiene las versiones draft/published de HU42/HU43.

## Imágenes

POST `/admin/portal/news/{id}/image` recibe multipart/form-data:

```text
revision: 3
image: archivo JPEG, PNG o WebP
```

Máximo 5120 KB (5 MiB). Se valida el tipo de archivo y que tenga dimensiones de
imagen válidas; GIF, SVG y archivos disfrazados no están admitidos. Responde 200
con el detalle actualizado, su nueva revision e image_url.

DELETE del mismo endpoint recibe JSON:

```json
{ "revision": 4 }
```

Responde 200 con image_url null y nueva revision. La noticia no se elimina.

**Entrega de imágenes:** image_url apunta a un GET del backend, no a una ruta de
archivo que deba construir frontend. Las imágenes se almacenan en el disco local
privado bajo portal-news. La URL pública comprueba que la noticia siga PUBLISHED;
tras despublicar o archivar devuelve 404. La URL administrativa requiere la misma
autenticación que el resto del panel y permite previsualizar borradores.

Con autenticación por cookie, enviar las credenciales habituales. Si frontend usa
Bearer sin cookies, obtener la imagen administrativa con una petición autenticada
y mostrar el blob; un img src no agrega por sí solo el encabezado Authorization.
No colocar tokens en la URL. El GET público no requiere autenticación.

Los GET de imágenes devuelven Content-Type del archivo, Content-Disposition inline,
X-Content-Type-Options nosniff y Cache-Control private/no-store para evitar que
una caché siga sirviendo un recurso despublicado. Como con cualquier descarga,
despublicar no elimina copias que un visitante ya haya guardado.

El reemplazo conserva el archivo previo hasta confirmar la referencia nueva y
su auditoría. Ante fallo se revierte la referencia y se limpia el archivo nuevo;
tras confirmar se elimina el anterior. Un fallo de limpieza se registra y no
revierte una actualización ya confirmada.

## Conflictos, permisos y errores

Toda modificación de texto, estado o imagen exige revision y la comprueba con
bloqueo de fila dentro de una transacción. Cada éxito incrementa revision.
La auditoría se guarda en esa misma transacción.

409 sin sobrescribir ni guardar un archivo nuevo:

```json
{
  "code": "REVISION_CONFLICT",
  "message": "La noticia fue modificada. Recarga su información antes de continuar.",
  "current_revision": 5
}
```

Frontend debe recargar y presentar los cambios; no sobrescribir automáticamente.
Otros errores: 401 sin sesión válida/cuenta inactiva según middleware existente,
403 por permisos o primer acceso, 404 por recurso inexistente/no publicado/sin
imagen y 422 con message/errors por campos inválidos. No se añaden privilegios
al DOCENTE ni al colaborador temporal.

## Integración y despliegue

Migración nueva: `2026_10_31_000002_create_portal_news_table`. Crea únicamente
portal_news; no modifica datos de Sprint 2 ni eventos de calendario. Aplicar
`php artisan migrate` antes de consumir estas rutas en el ambiente de integración.
No se cargan noticias institucionales inventadas ni registros de demostración.

El disco local debe tener almacenamiento persistente y permisos de escritura en
despliegue; un filesystem efímero puede perder archivos al desplegar. Esta épica
no necesita storage:link para noticias porque sus imágenes se sirven mediante
los endpoints autorizados. Ajustar los límites PHP/proxy (upload_max_filesize y
post_max_size) para permitir archivos de 5 MiB más el contenido multipart.

Pruebas: `php vendor/phpunit/phpunit/phpunit tests/Feature/Portal/PortalNewsTest.php`.
Se cubren publicación, archivo, permisos, revisión, paginación, privacidad de
imágenes, formatos/tamaño, reemplazo, eliminación y rollback ante fallo de auditoría.

HU42–HU45 quedan para las siguientes épicas. HU34 permanece pendiente de definición
funcional y no se altera HU12, habilitación ni control de ingreso.
