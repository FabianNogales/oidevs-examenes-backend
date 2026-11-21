# Bloque 2: autorización por permisos en Aulas

## Ampliación QA Saúl (20/11/2026)

- Aplicar `2026_11_20_000001_add_room_buildings_and_temporary_inactivity`.
  Conserva aulas, identificadores y textos anteriores; agrega edificios y `inactive_until`.
- POST/PUT de aulas: nombre único sin distinguir mayúsculas; capacidad informada mínima 10;
  piso entero entre -5 y 200, planta baja `0` (`PB` anterior se normaliza a `0`).
  `is_ground_floor: true` establece piso 0. POST sin `code` genera un código interno;
  PUT omitiéndolo conserva el código. Ubicación/descripción siguen opcionales por compatibilidad.
- `building_id` opcional debe identificar un edificio activo. GET `/api/v1/admin/rooms/buildings`
  entrega `data: [{id, name}]` y permite los permisos rooms.view/create/update.
  POST a esa ruta, solo ADMIN, recibe `{name}` y devuelve 201 con `{data: {id, name}}`.
  La migración deja `Edificio principal`; no deduce edificios desde ubicaciones antiguas.
- PATCH `/api/v1/admin/rooms/{id}/status` conserva `{status}` y admite, solo al desactivar,
  `inactive_until` opcional ISO 8601 con zona y posterior al momento actual. Activar cancela el plazo.
- PATCH `/api/v1/admin/rooms/bulk-activate`, solo ADMIN: `{room_ids: [1,2]}`, máximo 500,
  devuelve `data` con los recursos. Es atómico y audita cada cambio; un ID inválido devuelve 422.
- GET del catálogo admite `date=YYYY-MM-DD`, desde hoy y dentro del período activo.
  Sin fecha se mantiene la disponibilidad del instante actual. Con fecha se consulta todo ese día.
  Incluye aulas adicionales, ignora exámenes cancelados y agrega `availability_date`,
  `reservation_count` y fechas ISO del `current_exam`, sin quitar los campos existentes.
- `catalogs:refresh-statuses` vence períodos y reactiva aulas; programado cada minuto.
  Configurar el scheduler Laravel en despliegue. Las consultas administrativas y la consulta/reserva
  de aulas también actualizan estados vencidos, sin depender exclusivamente del scheduler.
- Frontend aún debe integrar los controles de edificio, fecha, plazo y selección masiva;
  sus errores de formato/validación continúan siendo 422 y los permisos conservan 401/403.


Este documento conserva el alcance del Bloque 2. La reutilización del evaluador y los
catálogos añadidos después se documentan en [Bloque 3](BLOCK_3_ACADEMIC_CATALOG_PERMISSIONS.md).

Implementación local del 20/11/2026, sin despliegue verificado. Backend en `main`, HEAD
`57164f4e4c1c899ed172285b615de7e1d930ac11`; frontend en `main`, HEAD
`8c25db4e0f81c4b09b1f82dd27ba76cb4d78dcb9`. Al iniciar este bloque existían los cambios
del Bloque 1 preparados en el índice; se conservaron. Los cambios del Bloque 2 quedan sin
preparar ni confirmar. No se cambiaron ramas ni se consultó una base de datos en este bloque.

## Operaciones y alcance

Todas estas rutas conservan `auth:sanctum`, `session.current` y `password.changed`.
Administrador activo conserva sus accesos sin necesitar permisos explícitos. Para otras
cuentas, `RoomAuthorization` consulta usuario, roles activos, asignaciones activas y permisos
efectivos actuales en BD; no confía en los permisos enviados por React ni en relaciones precargadas.

| Método y endpoint (prefijo `/api/v1`) | Control |
| --- | --- |
| GET `/admin/rooms` | `rooms.view`: listado, búsqueda, filtro por estado y paginación existentes |
| GET `/admin/rooms/{room}` | `rooms.view`: detalle existente |
| POST `/admin/rooms` | `rooms.create`: alta con estado inicial ACTIVE |
| GET `/admin/rooms/{room}/edit` | `rooms.update`: dependencia exclusiva del formulario de edición |
| PUT `/admin/rooms/{room}` | `rooms.update`: editar los seis campos existentes |
| POST `/admin/rooms/import/preview` | Solo ADMINISTRADOR, middleware original |
| POST `/admin/rooms/import/confirm` | Solo ADMINISTRADOR, middleware original |
| PATCH `/admin/rooms/{room}/status` | Solo ADMINISTRADOR, middleware original |
| GET `/rooms` | Disponibilidad autenticada original, sin cambios ni nuevos permisos |

El catálogo administrativo existente es global, incluyendo aulas activas e inactivas. No
tenía restricciones por propietario o sede; no se inventaron filtros nuevos. Consulta incluye
la disponibilidad y el resumen de examen actual que ya devolvía `RoomResource`; no habilita
los endpoints del módulo de exámenes. Los campos editables siguen siendo `code`, `name`,
`location`, `description`, `capacity`, `floor`. `SaveRoomRequest` ya prohibía `status`,
`availability`, `current_exam`, `id`, `exams`, y `RoomService` mantiene su lista explícita de
campos. Cambiar un aula INACTIVE no la reactiva.

La nueva lectura para edición devuelve únicamente `id` y esos seis campos del registro
seleccionado. Resuelve la dependencia real de edición sin otorgar listado o detalle general
por tener `rooms.update`. No devuelve estado, disponibilidad ni datos de exámenes. POST y
PUT conservan las respuestas de escritura existentes. No hay permisos implícitos: crear no
concede consultar ni editar; editar no concede consultar ni crear.

## Reutilización, habilitación y revocación

Se reutilizan `permissions`, `permission_role`, `roles`, `role_user`, el catálogo y los servicios
de gestión de roles/asignaciones del Bloque 1. No se crean claves, roles fijos ni perfiles
académicos adicionales. Solo se habilitan `rooms.view`, `rooms.create`, `rooms.update` en
la lista controlada de código y en una migración que actualiza las tres filas existentes.
Si falta una fila del Bloque 1, la migración falla explícitamente. Su reversión deshabilita
esas filas sin borrar roles, permisos o asignaciones. **La migración no se ejecutó.** Mientras
no se aplique, la habilitación persistida sigue en false y el personal aún no obtiene acceso.

Controladores, requests y servicios originales de Aulas no tenían una segunda exigencia de
ADMINISTRADOR o perfil docente. Se reemplazó el bloqueo fijo de las tres operaciones en
rutas por `EnsureRoomPermission`, sin abrir el grupo del resto de la administración.
`RoomService::save` además bloquea al actor en la transacción y revalida sesión y permiso
actual antes de escribir; conserva transacción, validaciones, unicidad, bloqueo del aula y
auditoría ROOM_CREATED/ROOM_UPDATED. La importación administrativa sigue usando ese mismo
servicio con el acceso del administrador.

Retirar permisos mediante la gestión de roles del Bloque 1 sigue rotando `active_session_id`
y eliminando tokens de los usuarios con asignaciones activas del rol editado. La siguiente
petición de esa sesión se rechaza; tras iniciar sesión de nuevo, `/me` ya no ofrece el permiso.
Además, el middleware vuelve a consultar permisos efectivos en cada petición, por lo que una
instantánea antigua de React o abrir una URL directa no autoriza la operación. Si la interfaz
sigue mostrando una acción antes de actualizar su sesión, Laravel la rechaza. Los controles
de cuenta activa y primer cambio de contraseña siguen vigentes.

## Web

- `/personal`: identidad y acceso a Aulas según permisos efectivos de `/me`. Consulta y
  registro tienen enlaces independientes. El menú también añade Aulas al personal autorizado;
  las prioridades y entradas de los roles base permanecen iguales.
- `/admin/rooms`: guarda exclusiva de Aulas dentro de `ProtectedRoute`. Con consulta, reutiliza
  `AdminRoomsPage`, filtros, tabla, detalle, formularios y modales. Sin consulta pero con creación,
  monta únicamente el formulario vacío, sin ejecutar el hook de listado ni pedir detalles.
- `/admin/rooms/create`: formulario existente de alta; exige creación. Al guardar vuelve al
  listado solo si tiene consulta; en otro caso vuelve a `/personal`.
- `/admin/rooms/:roomId/edit`: exige edición; carga únicamente los campos de edición del aula
  indicada. Con solo edición se accede por un enlace de registro conocido y no se ofrece un
  mecanismo para descubrir/listar aulas. La entrada de Aulas explica ese límite.
- `/admin/rooms/import`: conserva la guarda administrativa original. Estado e importación
  desaparecen en la vista delegada incluso con los tres permisos. Administrador conserva
  creación, edición, detalle, CSV, estado y `AdminLayout` con su comprobación `/admin/test`.
- `/admin/users/roles`: el catálogo ya no presenta Aulas como pendiente del Bloque 2. Refleja
  `is_enabled` del backend; no añade módulos ni permisos nuevos.

Se reutilizan PageContainer, PageHeader, RoomForm/RoomFormField, RoomModal, RoomDetails,
RoomsTable/DataTable y componentes de UI. Se mantienen CSS, paleta, Poppins y estilos
responsive existentes. Las acciones ocultas no se montan como controles interactivos.

Sin cambios en `ProtectedRoute`, guardas del resto del panel, autenticación, HU22, modelos,
requests de Aulas, RoomAvailabilityService, validación del formulario, RoomStatusDialog,
pantalla/servicio de importación, programación de exámenes o recursos/contratos móviles.
No se accedió a `eida-mobile` ni directorios `-compare`.

## Archivos del bloque

Rutas relativas a `oidevs-examenes-backend`:

- `app/Services/Admin/RolePermissionCatalog.php`: habilitación en código de las tres claves.
- `database/migrations/2026_11_20_000003_enable_administrative_room_permissions.php`: nueva, pendiente.
- `app/Services/Rooms/RoomAuthorization.php`: nuevo evaluador de autorización vigente.
- `app/Http/Middleware/EnsureRoomPermission.php`: nueva guarda para las operaciones delegadas.
- `routes/api/v1.php`: permisos por operación, CSV/estado reservados.
- `app/Http/Controllers/Api/V1/Admin/RoomController.php`: lectura mínima para edición.
- `app/Services/Rooms/RoomService.php`: revalidación de escritura.
- `tests/Feature/Rooms/RoomPermissionsTest.php`: nuevas regresiones focalizadas.
- `tests/Feature/CustomRolesBlockOneTest.php`: expectativas de habilitación ajustadas al Bloque 2;
  conserva la prueba de que configurar permisos deshabilitados no autoriza.
- `docs/BLOCK_1_ROLES_PERMISSIONS.md`: enlace al estado posterior, sin borrar su alcance histórico.
- `docs/BLOCK_2_ROOM_PERMISSIONS.md`: este documento.

Rutas relativas a `oidevs-examenes-frontend`:

- `src/features/rooms/utils/roomAccess.ts`: nuevo mapa independiente de acciones.
- `src/features/rooms/components/RoomRoute.tsx`: nueva guarda exclusiva de Aulas.
- `src/features/rooms/pages/RoomWorkspacePage.tsx`: nueva entrada que evita consultas implícitas.
- `src/features/rooms/pages/RoomWritePage.tsx`: nuevo montaje independiente del formulario existente.
- `src/features/rooms/pages/AdminRoomsPage.tsx`: acciones y contexto según permisos.
- `src/features/rooms/components/RoomsTable.tsx`: edición/estado opcionales.
- `src/features/rooms/components/RoomDetails.tsx`: edición opcional.
- `src/features/rooms/components/RoomDialog.tsx`: permisos por modo y carga mínima de edición.
- `src/features/rooms/components/RoomForm.tsx`: acepta DTO editable sin depender del detalle general.
- `src/features/rooms/types/room.types.ts`: tipo RoomEditable.
- `src/features/rooms/utils/roomValidation.ts`: acepta RoomEditable, mismas reglas y payload.
- `src/features/rooms/api/adminRoomsApi.ts`: lectura mínima para edición; contratos previos conservados.
- `src/app/router/router.tsx`: rutas propias de Aulas; importación conserva rama administrativa.
- `src/layouts/AppLayout/AppLayout.tsx`: enlace Aulas según acciones disponibles.
- `src/features/users/pages/StaffHomePage.tsx`: entrada del personal según permisos.
- `src/features/roles/pages/AdminRolesPage.tsx`: explicación del catálogo habilitado.
- `src/features/roles/components/RoleEditorDialog.tsx`: explicación de permisos independientes.
- `src/features/roles/components/PermissionPicker.tsx`: etiqueta de habilitación.
- `src/features/users/components/CreateStaffAccountDialog.tsx`: ayuda coherente con acciones habilitadas.
- `src/features/rooms/tests/roomPermissions.integration.test.mjs`: nuevas pruebas de integración de código
  real con hooks/HTTP simulados; no equivale a validación visual en navegador.
- `src/features/rooms/tests/rooms.browser.mjs`: fixture de administrador con identidad activa completa.
- `src/features/roles/tests/roleManagement.integration.test.mjs`: expectativas de textos actualizadas.

## Pruebas añadidas y validación pendiente

No se ejecutaron tests, builds, typecheck, lint global, instalaciones, migraciones, seeders ni
despliegues. La revisión realizada es estática: comprobación focalizada de sintaxis PHP
(`php -l`), sintaxis de los scripts de pruebas JavaScript (`node --check`) y `git diff --check`.
No se afirma que las pruebas pasen ni se
ha verificado el funcionamiento en una base local o desplegada durante este bloque.

Backend: catálogo sin duplicados, consulta sola, consulta+creación sin edición, creación sola,
edición sola y DTO mínimo, falta de permisos, unión de roles, rol/asignación inactivos, retirada vigente con
relaciones precargadas, token y sesión web activos revocados al editar el rol, acceso del administrador incluso
con catálogo deshabilitado, rechazo de CSV/estado/payload de estado, primer cambio, cuenta
inactiva, sesión sustituida, revalidación en servicio y disponibilidad docente intacta.
La nueva clase bloquea su ejecución si no hay entorno testing, SQLite :memory: real y
sesión/cache array, o si hay configuración cacheada; evita usar una BD persistente.

Frontend: listado/detalle sin edición, alta independiente, alta sin ninguna consulta, carga
mínima de edición sin listado, guardas directas y del resto del panel, retirada de permisos,
menú/entrada de personal, acciones reservadas ocultas, administrador intacto y catálogo habilitado.

Pasos recomendados para cerrar el bloque, **a ejecutar posteriormente**:

1. Revisar ambos diffs sin alterar los cambios preparados del Bloque 1. Confirmar que la BD
   elegida es local y que las dos migraciones del Bloque 1 ya están aplicadas. Revisar también
   las migraciones anteriores pendientes; no ejecutar un `migrate` general para este bloque.
   Para habilitar solo las tres filas en esa BD local:
   `php artisan migrate --path=database/migrations/2026_11_20_000003_enable_administrative_room_permissions.php`.
2. Confirmar antes el aislamiento de PHPUnit y ausencia de caché de configuración/DB_URL que
   redirija a una BD persistente. Ejecutar la prueba nueva:
   `php artisan test --filter=RoomPermissionsTest` y las de `CustomRolesBlockOneTest`.
   En un entorno aislado, ejecutar después las regresiones existentes de `tests/Feature/Rooms`
   (catálogo, escritura, estado, importación, concurrencia y disponibilidad), HU22 y contrato `/me` móvil.
3. En frontend, ejecutar `node --test src/features/rooms/tests/roomPermissions.integration.test.mjs`
   y `node --test src/features/roles/tests/roleManagement.integration.test.mjs`.
4. Validación manual en navegador: administrador con sus acciones originales; personal solo
   consulta; consulta+creación sin edición; solo creación (comprobar en red que no aparece GET
   del catálogo); solo edición por URL conocida; usuario sin permisos; y retirada de consulta
   con sesión abierta. Comprobar 401 de sesión invalidada y, tras login, 403 en URL/API retirada.
   Incluso enviando los tres permisos, CSV y estado deben seguir devolviendo 403 al personal;
   enviar status en POST/PUT debe fallar con 422 sin modificarlo.
5. Revisar navegación por teclado, foco/errores de los formularios y tamaños de pantalla
   existentes. La QA `rooms.browser.mjs` puede reutilizarse tras revisar sus prerrequisitos;
   arranca servicios locales y tampoco se ejecutó aquí. Verificar programación docente,
   coexistencia de roles base+personalizados y colaboración por examen sin permisos nuevos.
