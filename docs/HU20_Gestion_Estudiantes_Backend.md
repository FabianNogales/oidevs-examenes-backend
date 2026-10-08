# HU20 — Gestión de estudiantes: contrato backend

## Alcance

HU20 permite al ADMINISTRADOR listar el padrón institucional, buscar, paginar, registrar estudiantes manualmente, consultar su detalle, editar datos permitidos y activar/desactivar sus cuentas.

No incluye edición de `profile_photo`, gestión física de imágenes, gestión de carreras o roles ni eliminación física de estudiantes. El listado no está limitado a estudiantes inscritos en materias y comprende ACTIVE e INACTIVE.

Este contrato describe el código actual de `sprint_2`, revisado estáticamente. Los ejemplos son ilustrativos; IDs, dominios y URLs deben ajustarse al entorno.

## Autenticación y middleware

Todos los endpoints siguientes heredan, en este orden:

| Middleware | Responsabilidad |
|---|---|
| `auth:sanctum` | Requiere autenticación. |
| `session.current` | Bloquea cuentas no ACTIVE y comprueba que la cookie corresponda a la sesión vigente. |
| `password.changed` | Bloquea si todavía debe cambiarse la contraseña inicial. |
| `verify.admin` | Requiere rol administrativo y asignación activos; registra acceso concedido/denegado. |

Para la SPA, enviar cookies y `Accept: application/json`; en escrituras, `Content-Type: application/json` y el mecanismo XSRF habitual. Seguir [HU02 — Autenticación y sesión](HU02_AUTH_API.md) para login y cookies. HU20 no agrega otro mecanismo de autenticación.

## Endpoints

| Método | Ruta | Propósito | Éxito | Errores relevantes |
|---|---|---|---|---|
| GET | `/api/v1/admin/students` | Listar/buscar con paginación | 200, colección | 401, 403, 422 |
| POST | `/api/v1/admin/students` | Registrar manualmente | 201, StudentResource | 401, 403, 409, 422, 500 |
| GET | `/api/v1/admin/students/{student}` | Consultar detalle por ID de Student | 200, StudentDetailResource | 401, 403, 404 |
| PATCH | `/api/v1/admin/students/{student}` | Edición parcial | 200, StudentDetailResource | 401, 403, 404, 409, 422, 500 |
| PATCH | `/api/v1/admin/students/{student}/status` | Sincronizar estado del perfil y cuenta | 200, StudentResource | 401, 403, 404, 422, 500 |

`{student}` es el ID de Student, no el de User ni el SIS. Fallos de infraestructura pueden producir 500 también en lecturas/middleware; no tienen el mismo manejo controlado de los métodos de escritura.

## Listado y búsqueda

```http
GET /api/v1/admin/students?page=1&per_page=15
GET /api/v1/admin/students?search=Juan%20P%C3%A9rez&page=1&per_page=15
```

| Query param | Comportamiento |
|---|---|
| `search` | Opcional, texto o null; vacío no filtra. Coincidencias parciales en SIS, nombres, apellidos y correo. |
| `page` | Opcional, entero mayor o igual a 1; predeterminado 1. |
| `per_page` | Opcional, entero; predeterminado 15. Se ajusta a mínimo 1 y máximo 100. |

Tipos inválidos producen 422. El orden es apellidos, nombres e ID como desempate. No hay filtro por carrera ni por estado.

La búsqueda normaliza espacios y usa `LOWER(...) LIKE` con parámetros enlazados. Para varias palabras, permite que cada una aparezca entre nombres y apellidos: `Juan Pérez` puede encontrar `Juan Carlos Pérez Rojas`. También intenta la coincidencia parcial del término completo en cada campo. No hay normalización de acentos; el tratamiento de mayúsculas acentuadas depende del motor. `%` y `_` conservan su significado de comodines LIKE.

Ejemplo paginado con una coincidencia y `per_page=15`:

```json
{
  "data": [
    {
      "id": 42,
      "sis_code": "20260001",
      "first_names": "Juan Carlos",
      "last_names": "Pérez Rojas",
      "email": "juan@umss.edu.bo",
      "status": "ACTIVE",
      "user_status": "ACTIVE",
      "career": { "id": 1, "code": "SIS", "name": "Sistemas" }
    }
  ],
  "links": {
    "first": "https://eida.example/api/v1/admin/students?per_page=15&page=1",
    "last": "https://eida.example/api/v1/admin/students?per_page=15&page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "links": [
      { "url": null, "label": "&laquo; Previous", "active": false },
      { "url": "https://eida.example/api/v1/admin/students?per_page=15&page=1", "label": "1", "active": true },
      { "url": null, "label": "Next &raquo;", "active": false }
    ],
    "path": "https://eida.example/api/v1/admin/students",
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

`data` contiene registros; `links`, navegación; `meta`, página actual, última página, total y tamaño efectivo. Las etiquetas de navegación dependen de la traducción Laravel instalada. Los enlaces conservan `search` y `per_page` enviados. Sin coincidencias: HTTP 200 con `data: []` y metadatos; el frontend puede mostrar «No se encontraron estudiantes».

### StudentResource

Listado, registro y cambio de estado devuelven `id`, `sis_code`, `first_names`, `last_names`, `email`, `status`, `user_status` y `career` (ID, código y nombre). No exponen CI.

`status` corresponde a Student; `user_status`, a User. Normalmente deben coincidir; ambos se muestran para no ocultar una inconsistencia. User y Career se cargan anticipadamente, evitando consultas por cada fila.

## Registro manual

```http
POST /api/v1/admin/students
```

```json
{
  "sis_code": "20260001",
  "identity_number": "01234567",
  "first_names": "Juan Carlos",
  "last_names": "Pérez Rojas",
  "email": "juan@umss.edu.bo",
  "career_id": 1
}
```

| Campo | Requerido | Validación y normalización |
|---|---|---|
| `sis_code` | Sí | String, trim, máximo 255, único en students. |
| `identity_number` | Sí | String, trim, únicamente dígitos 0–9, máximo 255, único en students. |
| `first_names` | Sí | String, trim, máximo 255. |
| `last_names` | Sí | String, trim, máximo 255. |
| `email` | Sí | String, trim, minúsculas, formato válido, máximo 255, dominio configurado y único en users sin distinguir mayúsculas. |
| `career_id` | Sí | Entero y carrera existente. |

Enviar SIS y CI entre comillas, preservando ceros iniciales. El correo utiliza `eida.institutional_email_domains`, configurado mediante `EIDA_INSTITUTIONAL_EMAIL_DOMAINS`; sin dominios configurados se rechaza. No se vincula silenciosamente una cuenta existente.

La carrera puede estar ACTIVE o INACTIVE: actualmente se exige existencia, sin crear/modificar carreras. La política de estado queda pendiente de HU25.

User y Student se crean ACTIVE; se asigna el rol canónico ESTUDIANTE ACTIVE sin caducidad. El estudiante utilizará su CI como contraseña inicial y deberá cambiarla (`must_change_password=true`). La foto no es un campo del contrato manual; se crea con null.

Respuesta HTTP 201:

```json
{
  "data": {
    "id": 42,
    "sis_code": "20260001",
    "first_names": "Juan Carlos",
    "last_names": "Pérez Rojas",
    "email": "juan@umss.edu.bo",
    "status": "ACTIVE",
    "user_status": "ACTIVE",
    "career": { "id": 1, "code": "SIS", "name": "Sistemas" }
  }
}
```

### Servicio común de creación

`StudentRegistrationService` recibe datos ya validados/normalizados y Career. Centraliza User → InitialPasswordService → rol ESTUDIANTE → Student → auditoría dentro de una transacción. Si falla la creación o auditoría, se revierte todo. El rol ESTUDIANTE debe existir y estar ACTIVE.

HU05 lo invoca con contexto `IMPORT`; HU20 con `MANUAL`. El contexto distingue el origen en `STUDENT_CREATED`; el servicio no procesa CSV.

## Detalle

```http
GET /api/v1/admin/students/42
```

HTTP 200 mediante `StudentDetailResource`, que añade CI a los campos de StudentResource:

```json
{
  "data": {
    "id": 42,
    "sis_code": "20260001",
    "first_names": "Juan Carlos",
    "last_names": "Pérez Rojas",
    "email": "juan@umss.edu.bo",
    "status": "ACTIVE",
    "user_status": "ACTIVE",
    "career": { "id": 1, "code": "SIS", "name": "Sistemas" },
    "identity_number": "01234567"
  }
}
```

Un ID inexistente produce 404 mediante binding Laravel. Este endpoint administrativo es distinto de `/api/v1/students/profile`, que consulta exclusivamente el perfil propio del estudiante autenticado.

## Edición parcial

```http
PATCH /api/v1/admin/students/42
```

```json
{
  "first_names": "Juan Carlos",
  "email": "juan.carlos@umss.edu.bo"
}
```

Campos permitidos: `sis_code`, `identity_number`, `first_names`, `last_names`, `email`, `career_id`. Se aplican las reglas del registro solo cuando el campo está presente; no puede ser null ni vacío. SIS/CI ignoran el Student actual en unicidad; correo ignora el User asociado (`student.user_id`), no el ID de Student.

No permite cambiar `status`, `user_status`, `password`, `must_change_password`, `roles` ni `profile_photo`. Los campos no declarados se ignoran: no se garantiza un error por enviarlos. Un PATCH vacío o sin cambios reales devuelve la representación actual, sin auditoría de edición.

**Cambiar CI no cambia contraseña ni must_change_password.** La credencial basada en CI solo se configura al crear la cuenta. No se llama a InitialPasswordService al editar.

StudentService actualiza el mismo Student y el correo de su User dentro de una transacción con auditoría. Conserva `student.id`, `user_id`, enrollments, QR, habilitaciones e historial. Si falla User, Student o auditoría, se revierte la edición.

Respuesta HTTP 200: `data` con StudentDetailResource actualizado; en el ejemplo, `email` será `juan.carlos@umss.edu.bo`. No hace falta una segunda consulta para conocer los valores nuevos.

## Activación y desactivación

```http
PATCH /api/v1/admin/students/42/status
```

Para desactivar:

```json
{ "status": "INACTIVE" }
```

Para reactivar, misma ruta:

```json
{ "status": "ACTIVE" }
```

Solo `status` se utiliza; se normaliza trim/mayúsculas y se exige ACTIVE o INACTIVE. La operación sincroniza Student y User dentro de una transacción con auditoría.

| Anterior | Solicitado | Resultado |
|---|---|---|
| ACTIVE | INACTIVE | Ambos INACTIVE, acceso anterior invalidado. |
| INACTIVE | ACTIVE | Ambos ACTIVE; conserva credenciales y roles. |
| ACTIVE | ACTIVE | Sin cambios ni auditoría de estado. |
| INACTIVE | INACTIVE | Sigue inactivo; reafirma revocación de acceso sin auditoría falsa de cambio. |

Si estaban desincronizados, ambos quedan con el estado solicitado y se registra el cambio. Respuesta HTTP 200 con StudentResource; ejemplo de desactivación:

```json
{
  "data": {
    "id": 42,
    "sis_code": "20260001",
    "first_names": "Juan Carlos",
    "last_names": "Pérez Rojas",
    "email": "juan@umss.edu.bo",
    "status": "INACTIVE",
    "user_status": "INACTIVE",
    "career": { "id": 1, "code": "SIS", "name": "Sistemas" }
  }
}
```

Desactivar no elimina User, Student, rol ESTUDIANTE, enrollments, QR, habilitaciones, ingresos ni historia. Tampoco cambia carrera ni datos personales.

### Sesiones y reactivación

Al desactivar se reemplaza `active_session_id` por un marcador aleatorio no nulo y se revocan los tokens Sanctum de la cuenta, dentro de la transacción. No se pone el ID en null: eso no bloquearía una cookie anterior con la comprobación actual.

En la siguiente petición con cookie a una ruta con `session.current`, una cuenta inactiva recibe el contrato exacto:

```json
{
  "success": false,
  "message": "La cuenta se encuentra inactiva.",
  "code": "ACCOUNT_INACTIVE"
}
```

HTTP 401. El middleware cierra la sesión local, la invalida y regenera el token CSRF. Para un token Sanctum ya revocado, `auth:sanctum` puede rechazar primero y devolver un 401 genérico, sin ese código.

Frontend debe limpiar su estado autenticado y redirigir al login ante 401; no depender exclusivamente de `ACCOUNT_INACTIVE`. No hay notificación push de desactivación y no se cancelan peticiones ya en ejecución.

Los nuevos logins de cuentas INACTIVE son rechazados por LoginUserResolver. Al reactivar se conservan contraseña, roles, `must_change_password` e historial. El usuario debe iniciar una nueva sesión: la cookie antigua no coincide con el marcador y los tokens revocados no se recuperan.

La comprobación de inactividad del middleware aplica a rutas que lo incorporan, no a cualquier ruta del proyecto. Los flujos normales de usuarios ACTIVE mantienen la comprobación de sesión única.

## Errores para frontend

| HTTP | Caso real | Manejo sugerido |
|---|---|---|
| 401 | Sin autenticación, cuenta inactiva, sesión reemplazada o token revocado | Limpiar estado autenticado y volver al login. |
| 403 | Sin permisos administrativos o contraseña inicial pendiente | Consultar `code`; no confundir permisos con cambio de contraseña. |
| 404 | ID de Student inexistente en detalle, edición o estado | Mostrar que el estudiante ya no está disponible. |
| 409 | Colisión concurrente de unicidad en registro/edición | Mostrar mensaje y actualizar datos antes de reintentar. |
| 422 | Validación, duplicados, CI no numérico, carrera inexistente, estado inválido o query inválida | Mostrar errores por campo. |
| 500 | Fallo interno controlado en registro/edición/estado | Mostrar mensaje; la transacción del servicio se revierte. |

HU20 no define un 400 específico. La protección CSRF de la SPA también puede responder 419 conforme al flujo de autenticación; no es un error propio de HU20.

Ejemplo 422 de CI inválido (el mensaje global es generado por Laravel):

```json
{
  "message": "El CI debe contener únicamente números.",
  "errors": { "identity_number": ["El CI debe contener únicamente números."] }
}
```

Ejemplo exacto 409 de registro/edición:

```json
{
  "success": false,
  "message": "El SIS, CI o correo ya fue registrado. Actualice los datos e intente nuevamente."
}
```

401 genérico para peticiones JSON: `{"message":"La sesión no es válida o ha expirado."}`. Sesión reemplazada: `success:false`, `code:"SESSION_REPLACED"` y mensaje de sesión cerrada.

403 por contraseña pendiente: `success:false`, `code:"PASSWORD_CHANGE_REQUIRED"`, `message:"Debe cambiar su contrasena antes de continuar."`. El texto conserva literalmente el contrato actual.

403 de permisos: `{"code":"FORBIDDEN","message":"Forbidden - Insufficient permissions"}`; no incluye `success`.

500 controlado: `success:false` y uno de estos mensajes según operación:

- Registro: «No se pudo registrar el estudiante. Intente nuevamente más tarde.»
- Edición: «No se pudo actualizar el estudiante. Intente nuevamente más tarde.»
- Estado: «No se pudo cambiar el estado del estudiante. Intente nuevamente más tarde.»

No asumir un envelope `success` uniforme: éxitos usan `data`, validación usa `message/errors` y los conflictos/fallos controlados usan `success/message`.

## Flujo recomendado de integración

1. Listado: GET `/api/v1/admin/students`; usar `data`, `links` y `meta`.
2. Búsqueda: enviar `search` codificado y reiniciar `page=1`.
3. Registro: POST con los seis campos; agregar/refrescar la fila con `response.data.data`, considerando el orden y filtros actuales.
4. Detalle: GET por ID para obtener CI y carrera actual.
5. Edición: PATCH con los campos cambiados; utilizar la representación actualizada.
6. Estado: PATCH `/status`; actualizar ambos estados de la fila con la respuesta.

No se requiere recargar toda la página. Si un cambio afecta orden o pertenencia al filtro, puede refrescarse la página del listado. El formulario de carrera necesita IDs ya disponibles; HU20 no agrega catálogo ni CRUD de carreras.

## Auditoría

- `STUDENT_CREATED`: creación con contexto MANUAL o IMPORT, actor, Student, cuenta/carrera/estado, IP y user-agent; sin contraseñas ni CI.
- `STUDENT_UPDATED`: valores anteriores/nuevos relevantes y campos cambiados; el CI solo aparece como nombre de campo modificado, sin su valor.
- `STUDENT_STATUS_CHANGED`: estados anteriores/nuevos de Student y User; sin identificadores de sesión ni tokens.
- `verify.admin` registra acceso concedido/denegado, incluso en lecturas; HU20 no añade otra auditoría de búsquedas/paginación.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `app/Http/Controllers/Api/V1/Admin/StudentController.php` | Coordina los cinco endpoints y respuestas HTTP. |
| `app/Services/Students/StudentService.php` | Listado, detalle, edición y estado con transacciones/auditoría. |
| `app/Services/Students/StudentRegistrationService.php` | Creación común para HU05 y HU20. |
| `app/Http/Resources/Students/StudentResource.php` | Representación reducida sin CI. |
| `app/Http/Resources/Students/StudentDetailResource.php` | Representación de detalle con CI. |
| `app/Http/Requests/Api/V1/Students/StoreStudentRequest.php` | Normalización y validación del registro. |
| `app/Http/Requests/Api/V1/Students/UpdateStudentRequest.php` | PATCH parcial y unicidad ignorando los registros correctos. |
| `app/Http/Requests/Api/V1/Students/UpdateStudentStatusRequest.php` | Validación de ACTIVE/INACTIVE. |
| `app/Models/Student.php` | Relaciones y scope de búsqueda parcial/por palabras. |
| `app/Http/Middleware/EnsureCurrentSession.php` | Bloqueo de cuentas inactivas y sesión reemplazada. |
| `routes/api/v1.php` | Rutas y grupo de protección administrativa. |

## Observaciones pendientes

- HU05 usa el dominio fijo `@umss.edu.bo`; HU20, docentes y autenticación usan configuración. No se modificó esta diferencia.
- Carreras inactivas siguen permitidas hasta definir la regla de HU25.
- `verify.admin` admite también los nombres históricos Admin/Administrador además de ADMINISTRADOR.
- La comprobación de estado de sesión no está incorporada a todas las rutas del proyecto; evaluar aparte si debe ampliarse su cobertura.
- Gestión física de imágenes y normalización avanzada de búsquedas quedan fuera de HU20.
