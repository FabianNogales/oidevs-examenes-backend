# Ajustes de integración HU13 / HU14 / HU15

Definiciones aceptadas por frontend: filtros server-side, fotografía institucional
separada de la foto actual y candidatos paginados con roles activos normalizados.

## HU13 — filtros

GET `/api/v1/exams/{exam_id}/entry-control/students` admite los parámetros actuales
y los siguientes:

- entry_status=REGISTERED: existe un ingreso VALID en ese examen.
- entry_status=PENDING: no existe ingreso VALID; un ingreso ANNULLED no lo registra.
- has_incident=true (también 1): estudiante inactivo, habilitación pendiente o
  inhabilitación, incluido NOT_ELIGIBLE legado. No incluye EXAM_UNAVAILABLE ni
  ROOM_MISMATCH ni el hecho de que ya tenga un ingreso. No consulta incidencias HU27.
- has_incident=false (también 0): estudiantes sin esas condiciones individuales.

Los filtros se combinan con search antes de paginar. meta.total/last_page reflejan
la consulta filtrada; summary mantiene los contadores globales de inscritos activos
en la oferta del examen. Los valores inválidos producen 422.

Después de registrar al último pendiente, volver a consultar PENDING debe devolver
data [] y meta.total 0; summary conserva el total global y pending 0.

## HU15 — candidatos

GET `/api/v1/users?exam_id={id}&search={query}&page=1&per_page=10` devuelve:

```json
{
  "data": [
    {
      "id": 15,
      "display_name": "Nombre del candidato",
      "email": "estudiante@example.com",
      "identity_number": "123456",
      "roles": ["ESTUDIANTE"]
    }
  ],
  "meta": { "current_page": 1, "per_page": 10, "total": 1, "last_page": 1 }
}
```

page >= 1; per_page entre 1 y 100, por defecto 10. La paginación ocurre después
de búsqueda y restricciones de elegibilidad/jerarquía. Roles activos normalizados
y únicos se conservan. POST sigue rechazando candidatos superiores con 422.

## HU14 — datos de fotografía

profile_photo_url sigue siendo la foto actual de users.profile_photo, sin cambios
en su carga. historical_photo_url identifica exclusivamente un archivo institucional
cargado por un administrador mediante el nuevo flujo. Ambos pueden ser null de
forma independiente. No se toma una foto durante el ingreso ni se copia/infiere
una histórica desde perfil, auditoría o archivos anteriores.

Se agrega historical_photo_url a:

- HU13: data de listado y detalle del estudiante.
- HU14 y registro MANUAL: data.student en resultados con identidad disponible,
  incluidos duplicados y rechazos que ya entregaban student.
- Historial de ingresos: data[].student.
- Detalle administrativo GET `/api/v1/admin/students/{student_id}`.

Si el resultado no puede identificar al estudiante (por ejemplo, QR inválido),
se conserva data null; no se inventa identidad ni fotografía.

El detalle administrativo agrega también profile_photo_url y
historical_photo_revision (0 si nunca se cargó una referencia).

## Carga y corrección institucional

Solo ADMINISTRADOR activo, con sesión válida y primer acceso completado.
Usar students.id, no users.id.

Primera carga:

```text
POST /api/v1/admin/students/{student_id}/historical-photo
multipart/form-data:
  photo: archivo JPEG/PNG/WebP, máximo 5120 KB
  revision: 0 (valor devuelto por el detalle)
```

201 con el detalle administrativo actualizado, historical_photo_url y nueva
historical_photo_revision. Se valida contenido de imagen, no solo extensión.
Si ya existe una referencia, no se permite sobrescribir por esta ruta:
409 HISTORICAL_PHOTO_EXISTS. No hay migración que copie fotos actuales.

Corrección explícita:

```text
POST /api/v1/admin/students/{student_id}/historical-photo/corrections
multipart/form-data:
  photo: archivo nuevo
  revision: revisión actual del detalle
  reason: justificación obligatoria, máximo 500 caracteres
```

200 con detalle actualizado. Revisión antigua: 409 HISTORICAL_PHOTO_CONFLICT,
con current_revision y message; frontend debe recargar y no sobrescribir
automáticamente. Corregir sin referencia previa devuelve 409 HISTORICAL_PHOTO_MISSING.
No se ofrece eliminación ni modificación desde la actualización normal del perfil.

La operación bloquea el estudiante y guarda referencia/revisión/responsable/fecha
y auditoría en una transacción. Si falla, se revierte y se limpia la carga nueva.
Una corrección conserva el archivo institucional anterior en almacenamiento privado
con su referencia en auditoría. Ningún flujo de foto actual lo borra.

## Lectura de archivos

Backend devuelve URLs completas; frontend no construye rutas de archivos.

- GET `/api/v1/admin/students/{student_id}/historical-photo`: administrador activo.
- GET `/api/v1/exams/{exam_id}/entry-control/students/{student_id}/historical-photo`:
  docente responsable o colaborador vigente; verifica la asociación con el examen.

Sin referencia o archivo: 404. Acceso no autorizado: reglas existentes 401/403.
Las imágenes están en disco local privado, no bajo public/storage. Las URLs de
verificación conservan los permisos del examen y no se comparten como enlaces públicos.
Con Bearer, frontend debe obtener el archivo como blob mediante la petición
autenticada habitual; un img src por sí solo no adjunta Authorization. Con cookie,
se utilizan las credenciales válidas de la sesión. No enviar tokens en la URL.

## Migración y despliegue

Aplicar `php artisan migrate` antes de integrar. Migración nueva:
`2026_11_01_000002_add_institutional_photo_to_students`.

Agrega en students historical_photo nullable, historical_photo_revision default 0,
historical_photo_updated_at y historical_photo_updated_by. No cambia fotos actuales
ni rellena referencias históricas para alumnos existentes.

El almacenamiento privado debe ser persistente en despliegue y permitir escritura.
Ajustar los límites PHP/proxy para recibir 5 MiB más el multipart. Los cambios de
filtros y paginación no requieren migraciones por sí mismos.
