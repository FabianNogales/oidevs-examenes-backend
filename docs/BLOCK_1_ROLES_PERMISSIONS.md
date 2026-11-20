# Bloque 1: roles personalizados y cuentas de personal

Este documento conserva el alcance histórico del Bloque 1. La habilitación posterior de
Aulas, sus rutas y su validación están documentadas en [Bloque 2](BLOCK_2_ROOM_PERMISSIONS.md).
La ampliación a catálogos académicos se documenta en [Bloque 3](BLOCK_3_ACADEMIC_CATALOG_PERMISSIONS.md).

Implementación contrastada con backend `main@57164f4e4c1c899ed172285b615de7e1d930ac11`
y frontend `main@8c25db4e0f81c4b09b1f82dd27ba76cb4d78dcb9`. Ambos estaban limpios al iniciar.

## Alcance y límites

- Reutiliza `roles`, `role_user` y `AdminUserService::role` para asignar, retirar y reactivar.
- Solo un administrador activo, con sesión vigente y primer cambio de contraseña completado,
  puede administrar roles o crear personal. Las mutaciones revalidan al actor en transacción.
- Los roles existentes quedan protegidos por defecto (`is_custom=false`). No se renombran ni
  se editan sus privilegios en este bloque. Los roles personalizados no admiten nombres base,
  aliases administrativos o de colaboración temporal, ni permisos ajenos al catálogo controlado.
- Los nombres personalizados son únicos tras normalizar mayúsculas y espacios. Se conserva
  la unicidad original de `roles.name` y se añade una clave normalizada única.
- El catálogo inicial contiene exclusivamente `rooms.view`, `rooms.create`, `rooms.update`.
  Consulta corresponde a GET listado/detalle; creación a POST y edición a PUT de
  `/api/v1/admin/rooms`. No se incluyen importación ni cambios de estado.
- Esos tres permisos son configurables pero **no están habilitados**. La resolución efectiva
  exige tanto habilitación en código como en BD. No se modifican rutas, middleware, servicios,
  pantallas o botones de Aulas. La adaptación completa pertenece al Bloque 2.
- No se añade ningún alcance global ni relación de delegación por examen/oferta.
- Colaborador sigue siendo una designación temporal por examen. Este bloque no cambia su
  jerarquía ni habilita cuentas exclusivamente personalizadas como nuevas colaboradoras.
- GET `/api/v1/rooms` conserva su acceso de disponibilidad autenticada anterior; no es la
  administración de Aulas y no se amplía ni restringe por estos permisos.

## Datos y primer acceso

Migraciones nuevas, no ejecutadas:

1. `database/migrations/2026_11_20_000001_create_role_permissions.php`: añade `is_custom`,
   `custom_name_key`, `revision` a roles; crea `permissions` y `permission_role` con claves
   únicas/compuesta y referencias restrictivas; inicializa los tres permisos deshabilitados.
2. `database/migrations/2026_11_20_000002_add_staff_identity_to_users.php`: identidad de
   personal nullable en `users`, con CI de personal único. No crea perfiles académicos.

El alta recibe nombres, apellidos, CI, correo institucional y un rol personalizado activo.
Reutiliza `LoginUserResolver` para la validación de dominio/correo y las reglas de CI numérico
como string del alta manual. Rechaza CI ya registrado en personal, estudiantes o docentes.
Reutiliza `InitialPasswordService`: hash del CI y `must_change_password=true`.
La cuenta y la asignación inicial de HU22 se confirman en una sola transacción.
No se envían correos. No se devuelve una contraseña separada ni se audita CI/contraseña/hash/token.

## API

Todos los endpoints administrativos mantienen `auth:sanctum`, `session.current`,
`password.changed` y `EnsureActiveAdministrator`.

| Método/ruta relativa a `/api/v1` | Contrato |
| --- | --- |
| GET `/admin/permissions` | `data[]`: `key`, `module`, `label`, `description`, `is_configurable`, `is_enabled` |
| GET `/admin/roles` | Catálogo existente ampliado con roles personalizados, metadatos y permisos |
| GET `/admin/roles/{role}` | Detalle del rol; `data`: `id`, `name`, `status`, `is_custom`, `is_protected`, `revision`, `permission_keys` |
| POST `/admin/roles` | `{name, permission_keys: string[]}`; 201, mismo detalle |
| PATCH `/admin/roles/{role}` | `{name, permission_keys: string[], revision}`; 200; revisión obsoleta 409; rol protegido 403 |
| POST `/admin/users/staff` | `{first_names, last_names, identity_number, email, role_id}`; 201, `AdminUserResource` |
| GET `/admin/users/{user}/roles` | Misma asignación HU22; incluye personalizados y conserva `assigned` |
| POST `/admin/users/{user}/roles` | Contrato HU22 existente `{role_id}` sin cambios |
| DELETE `/admin/users/{user}/roles/{role}` | Contrato de retiro HU22 sin cambios |
| GET `/me` web | Añade `permissions: string[]` efectivos; en Bloque 1 está vacío |

`permission_keys` puede ser vacío. No admite claves desconocidas, administrativas o repetidas.
No existen endpoints para crear permisos técnicos, editar roles base, borrar roles o cambiar
su estado. Los estados existentes se respetan en la asignación.

El recurso compartido `CurrentUserResource`, el login móvil y las abilities del APK no cambian.
GET `/me` con un token identificado como móvil conserva su forma anterior, sin `permissions`.

## Sesiones y auditoría

La resolución consulta roles/asignaciones/cuentas activos de la BD; no utiliza roles cargados
en memoria ni listas del navegador. `configured()` expresa configuración; `effective()`
expresa habilitación para uso y no sustituye las reglas de alcance del módulo.

Asignar/retirar roles conserva el comportamiento de HU22: marcador de sesión rotado y tokens
eliminados. Editar nombre/permisos de un rol personalizado aplica esa revocación a todas sus
asignaciones activas. El próximo request con la cookie anterior es rechazado por el middleware
existente. La revocación y auditoría se confirman juntas; un fallo revierte todo.

Eventos nuevos: `ROLE_CREATED`, `ROLE_UPDATED`, `ROLE_PERMISSIONS_CHANGED`,
`STAFF_ACCOUNT_CREATED`. La asignación inicial también genera `USER_ROLE_ASSIGNED` por HU22.
El historial de cuentas incorpora el alta de personal. La auditoría administrativa muestra
nombre y claves controladas para los eventos de rol mediante allowlist.

## Frontend

- `/admin/users/roles`: catálogo de roles, formulario de creación/edición y permisos agrupados
  por módulo; los roles base se muestran protegidos. Las acciones de Aulas indican Bloque 2.
- `/admin/users/assignments`: selección de cuenta para asignar/retirar roles.
- `/admin/users/:userId/roles`: conserva la asignación individual HU22.
- `/admin/users/manage`: añade «Crear cuenta de personal», con formulario propio.
- `/personal`: entrada autenticada sin rol base, identidad y estado sin funciones habilitadas.
- Administrador, Docente y Estudiante conservan rutas de inicio, guardas y menús actuales;
  se añade el enlace administrativo de asignaciones.
- `staff` en `HeaderRole` es únicamente un contexto visual, no un rol global en BD.
- Reutiliza PageContainer, PageHeader, DataTable, PortalDialog, ConfirmDialog nativo,
  Field, TextInput, Select, Checkbox, Button, Card y Snackbar; usa los tokens del proyecto.
- Conserva carga/error/vacío/guardado, cancelación de consultas y prevención de doble envío.

## Archivos del bloque

Backend nuevos:

- `app/Models/Permission.php`
- `app/Services/Admin/AdministratorOperation.php`
- `app/Services/Admin/CustomRoleService.php`
- `app/Services/Admin/RolePermissionCatalog.php`
- `app/Services/Admin/StaffAccountService.php`
- `app/Http/Controllers/Api/V1/Admin/AdminRoleController.php`
- `app/Http/Requests/Api/V1/Admin/SaveCustomRoleRequest.php`
- `app/Http/Requests/Api/V1/Admin/StoreStaffAccountRequest.php`
- `app/Http/Resources/Admin/AdminRoleResource.php`
- Las dos migraciones indicadas arriba.
- `tests/Feature/CustomRolesBlockOneTest.php`
- Este documento.

Backend ajustados:

- `app/Models/Role.php`
- `app/Models/User.php`
- `app/Services/Admin/AdminUserService.php`
- `app/Http/Controllers/Api/V1/Admin/AdminUserController.php`
- `app/Http/Controllers/Api/V1/Auth/CurrentUserController.php`
- `app/Http/Resources/Admin/AdminUserResource.php`
- `app/Http/Resources/Audit/AuditLogResource.php`
- `routes/api/v1.php`
- `tests/Feature/Mobile/MobileAuthenticationTest.php`

Frontend nuevos:

- `src/features/roles/api/rolesApi.ts`
- `src/features/roles/components/PermissionPicker.tsx`
- `src/features/roles/components/RoleEditorDialog.tsx`
- `src/features/roles/components/RoleManagement.module.css`
- `src/features/roles/pages/AdminRolesPage.tsx`
- `src/features/roles/tests/roleManagement.integration.test.mjs`
- `src/features/users/components/CreateStaffAccountDialog.tsx`
- `src/features/users/pages/StaffHomePage.tsx`

Frontend ajustados:

- `src/app/router/router.tsx`
- `src/features/auth/types/auth.ts`
- `src/features/auth/utils/authRoutes.ts`
- `src/features/auth/components/PublicAuthLayout.tsx`
- `src/features/users/api/adminUsersApi.ts`
- `src/features/users/pages/AdminManageUsersPage.tsx`
- `src/features/users/pages/AdminUserRolesPage.tsx`
- `src/layouts/AppLayout/AppLayout.tsx`
- `src/shared/components/Header/header.types.ts`
- `src/shared/components/Header/headerNavigation.config.ts`

## Verificación pendiente para cierre

No se ejecutaron pruebas, builds, typecheck, instalaciones, migraciones ni servidores.
La revisión realizada es estática; `git diff --check` solo comprueba el diff.

Cuando se autorice ejecutar verificaciones, usar exclusivamente un entorno de pruebas:

1. Revisar/aplicar las migraciones en una BD local desechable; comprobar unicidad e integridad
   también en PostgreSQL. No usar producción ni ejecutar seeders de demostración.
2. Backend: `php artisan test --filter=CustomRolesBlockOneTest`, luego
   `php artisan test --filter=AdminAccountIntegrationTest`,
   `php artisan test --filter=AdminReadCatalogTest`, y
   `php artisan test --filter=MobileAuthenticationTest` en sus entornos aislados.
   Las pruebas nuevas rechazan BD persistente y configuración cacheada.
3. Frontend: `node --test src/features/roles/tests/roleManagement.integration.test.mjs`,
   `node --test src/features/users/tests/adminAccounts.integration.test.mjs` y
   `node --test src/shared/tests/headerNavigation.test.mjs`.
   El harness comprueba lógica/transporte/render simulado; no es una prueba visual de navegador.
4. Ejecutar los chequeos de compilación/estilo permitidos para el cierre y revisión visual en
   móvil/escritorio, teclado, foco de modales y errores, sin habilitar mocks.
5. Probar alta, login por correo/CI, cambio inicial, identidad y logout de personal; comprobar
   que no aparecen menús administrativos y que URLs/APIs de administración siguen denegadas.
6. Probar consulta/creación/edición de Aulas con solo roles personalizados configurados: deben
   seguir denegadas. Verificar que los usuarios base conservan sus accesos previos.
7. Retirar permiso o asignación con sesión/token activo, verificar rechazo posterior y auditoría
   sin credenciales; incluir fallo de auditoría y de asignación para comprobar rollback.

Pendiente de Bloque 2: adaptar Aulas de punta a punta (guardas, endpoint, reglas de registro,
DTO, navegación, botones y regresiones) antes de habilitar los tres permisos. No debe activarse
solo un flag ni usarse una concesión para eludir los controles actuales.
