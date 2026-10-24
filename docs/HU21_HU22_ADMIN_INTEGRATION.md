# HU21/HU22: cuentas, roles e información administrativa real

Todos los endpoints siguientes pertenecen a `/api/v1/admin` y requieren Sanctum,
cuenta activa, sesión vigente, contraseña inicial cambiada y rol/asignación
ADMINISTRADOR activos. No sustituyen al buscador `/api/v1/users` de colaboradores.

## Cuentas (HU21)

- `GET /users?search=&page=1&per_page=10`: cuentas de todos los perfiles, búsqueda
  por nombres/apellidos, correo o código; respuesta Laravel `data` + `meta`.
- `GET /users/{user}`: `data` con el ID real de User y referencias separadas
  `student_id` y `teacher_id`, nombres, correo, código/CI disponibles, estado
  global, estados de perfiles y roles asignados. Sin credenciales ni hashes.
- `PATCH /users/{user}/status`, JSON `{ "status": "ACTIVE|INACTIVE" }`.
  Solo cambia User: conserva perfiles, asignaciones, exámenes e historial.
  Rechaza campos de roles, autodesactivación y pérdida del acceso administrativo.

## Roles (HU22)

- `GET /roles`: catálogo persistido de ADMINISTRADOR, DOCENTE y ESTUDIANTE.
- `GET /users/{user}/roles`: añade `assigned` según la asignación persistida;
  el estado del rol se informa por separado y un rol inactivo no se puede conceder.
- `POST /users/{user}/roles`, JSON `{ "role_id": 2 }`: asigna o reactiva la relación.
- `DELETE /users/{user}/roles/{role}`: desactiva la relación, sin borrarla.

Asignación y retiro devuelven el recurso de cuenta actualizado. Se exige perfil
académico activo para DOCENTE/ESTUDIANTE; no se inventan perfiles al asignar roles.
La colaboración es temporal por examen y no forma parte de este catálogo.
No se retira el acceso administrativo propio. Como protección conservadora,
el rol DOCENTE no se retira mientras existan ofertas activas; la transferencia
de responsabilidades requiere definición funcional, no borrado ni reasignación implícita.

Cambios reales de estado o roles revocan tokens y la sesión anterior. Se conserva
el estado independiente de los perfiles y los demás roles. Mutación, revocación
y auditoría se confirman/revierten juntos. El actor se obtiene de autenticación.
Se bloquea primero el rol ADMINISTRADOR y luego las cuentas ordenadas por ID;
se vuelve a comprobar la autorización dentro de la transacción.
El menú web combina los accesos de todos los roles permanentes activos recibidos
de `/me`; no concede acceso por sí mismo ni sustituye la autorización Laravel.

## Historial

`GET /users/history` y `GET /users/{user}/history`: eventos persistidos
USER_STATUS_CHANGED, USER_ROLE_ASSIGNED y USER_ROLE_REVOKED. Paginación real,
`search` por ID de cuenta, código de acción o responsable; filtros opcionales
`user_id` y `action`. `data` + `meta`; contextos limitados a estado, rol y asignación.
El nombre del actor es el del perfil actual. No se fabrican eventos anteriores.

## Pestañas de perfiles

`GET /profiles/{students|teachers}/{profileId}/records` usa el ID del perfil,
no el de User. Query `section=subjects|subject-history|exams|exam-history|roles|collaborations`,
`page`, `per_page`, `search`; colaboraciones admite `state=ACTIVE|COMPLETED`.
Respuesta `data`, `meta` y `summary` de colaboraciones cuando corresponde.

- Materias actuales: oferta y período activos (también inscripción activa para estudiantes).
- Historia académica: relaciones de otros períodos o inactivas, no una historia
  retrospectiva de cada edición. Los nombres/catálogos son los actuales.
- Exámenes: mismas condiciones temporales de la gestión docente; historia incluye cancelados.
- Colaboraciones: última asignación conservada por examen. Aperturas/reasignaciones/revocaciones
  anteriores se consultan en los eventos persistidos de la pestaña de autorizaciones.
- No existen grupos ni calificaciones finales en estas fuentes: se devuelven ausencias
  explícitas, nunca grupos, aprobaciones o notas inventadas.

Los errores conservan 401/403/404/409/422. No se crean tablas ni migraciones.
PostgreSQL concurrente y revisión visual en navegador requieren comprobación posterior.
La política general de los antiguos cambios de estado de perfiles no se modifica aquí.
