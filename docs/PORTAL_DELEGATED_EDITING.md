# Edición directa delegada del portal institucional

Implementación local del 20/11/2026. Backend: main@57164f4e4c1c899ed172285b615de7e1d930ac11.
Frontend: main@8c25db4e0f81c4b09b1f82dd27ba76cb4d78dcb9. Había cambios previos de
los bloques 1–3 y de navegación, preparados y sin preparar; se conservaron.
No hay despliegue verificado ni consultas a BD en este trabajo. No se ejecutaron
tests, builds, typecheck, instalaciones, migraciones, seeders ni despliegues.

## Organización y permisos

Se contrastaron los documentos de Bloque 1 y Bloque 2 con el código actual.
Se reutilizan permissions, permission_role, roles, role_user, HU22,
RolePermissionCatalog, PermissionAuthorization, EnsurePermission y /me web.
No hay roles nuevos obligatorios, permiso general de portal ni asignaciones automáticas.

Información institucional y FAQ comparten PortalInformationContent y la pantalla,
pero tienen kind SECTION/FAQ, campos y endpoints distintos. Por eso se separan
en dos permisos. Calendario usa PortalEvent, no los exámenes.

Todos los endpoints de la matriz tienen prefijo /api/v1/admin/portal.
GET colección y detalle son lectura necesaria para localizar/editar registros existentes.
PATCH exige revision y solo edita contenido. El permiso abarca el catálogo global de
**su sección**, incluyendo borradores, registros publicados y otros estados existentes.
Calendario incluye eventos PRIVATE; editarlos no los hace públicos. No se inventan
propiedad, sede ni nuevas políticas sobre registros.

| Permiso | Endpoints autorizados | Ruta web / pantalla reutilizada |
| --- | --- | --- |
| portal.news.update | GET /news, GET /news/{id}, PATCH /news/{id}; GET /news/{id}/image para vista previa | /admin/portal/news — AdminNewsPage / NewsEditor |
| portal.events.update | GET /events, GET /events/{id}, PATCH /events/{id}; GET /event-categories (id/nombre activos, selector existente) | /admin/portal/events — AdminCalendarPage / AdminEventDialog / EventForm |
| portal.information.sections.update | GET /information/sections, GET /information/sections/{id}, PATCH /information/sections/{id} | /admin/portal/information — AdminInformationPage / InformationEditor |
| portal.information.faqs.update | GET /information/faqs, GET /information/faqs/{id}, PATCH /information/faqs/{id} | /admin/portal/information/faqs — misma pantalla y editor, vista FAQ |
| portal.services.update | GET /services, GET /services/{id}, PATCH /services/{id} | /admin/portal/services — AdminServicesPage / ServiceEditor |

Campos permitidos a personal, además de revision:

- Noticias: title, summary, content.
- Calendario: title, description, category_id, starts_at, ends_at, all_day, location.
- Información: title, description, links, sort_order.
- FAQ: question, answer, sort_order.
- Servicios: name, description, icon (clave del catálogo existente, no imagen), url, display_order.

La migración 2026_11_20_000005_add_portal_section_edit_permissions.php añade solo
esas cinco filas habilitadas/configurables. No modifica roles ni asignaciones.
PermissionPicker ya presenta automáticamente las filas del catálogo, descripción
y estado operativo; no se añade otra pantalla de Roles.

## Guardado y controles

auth:sanctum, session.current y password.changed permanecen en el grupo.
EnsurePermission consulta permisos efectivos vigentes en BD. Cada servicio save
revalida la escritura dentro de su transacción mediante PortalEditing y
PermissionAuthorization::authorizeWrite (usuario, asignaciones, permisos y marcador
de sesión actual). Administrador activo conserva el acceso sin enlaces explícitos
a permisos. Las cuentas base sin permiso delegado no ganan accesos.

RestrictsDelegatedPortalFields rechaza cualquier campo fuera de la lista permitida
para personal, incluso vacío, null o con su valor anterior. La validación sucede
antes de guardar; una petición mixta válida + no autorizada devuelve 422 sin
cambiar contenido, revisión ni auditoría. Los servicios refuerzan la lista al guardar.
No se cambia el contrato de validación del administrador.

Continúan exclusivamente con EnsureActiveAdministrator:

- POST /news, /events, /services, /information/faqs.
- PATCH /news/{id}/status, /events/{id}/status, /services/{id}/status.
- POST /information/{sections|faqs}/{id}/publish y /unpublish.
- POST y DELETE /news/{id}/image.
- GET de historiales de cada recurso (no necesarios para editar).
- Gestión de categorías y demás rutas administrativas, donde existan.

No se crean endpoints de eliminación, archivo, importación u otras acciones ausentes.
Los cambios de estado, archivo o retirada existentes siguen por sus operaciones administrativas.
En particular, visibility no se acepta en PATCH delegado de eventos. React tampoco
lo envía, ni siquiera sin cambios. Servicios conserva destination_type y requires_auth
al editar como personal; las filas heredadas incompatibles siguen sujetas a las reglas
existentes y pueden requerir corrección administrativa. No se convierten implícitamente
en servicios públicos para permitir su edición.

Guardar noticia PUBLISHED modifica inmediatamente la consulta pública; DRAFT/ARCHIVED
conservan su estado. Eventos conservan status y visibility; solo PUBLISHED + PUBLIC
aparece en público. Servicios conservan estado y las reglas públicas de enlaces externos.
No se alteraron las consultas públicas.

Información/FAQ tenía dos snapshots: guardar solo modificaba draft aunque el registro
estuviese publicado. Ahora, cuando is_published ya es true, guardar también actualiza
published dentro de la misma transacción; no cambia is_published ni published_at.
Cuando es false, no se altera published ni se publica el registro. Se conserva el control
de revisión, validación de enlaces y campos necesarios para contenido público.
Esto aplica también al administrador: el editor avisa del efecto público y usa
«Guardar cambios» para contenido publicado. Se mantienen sus acciones de publicación
y retirada. Las pruebas antiguas se adaptaron a esta decisión funcional.

Se conserva la auditoría existente y updated_by con el ID del autor real.
Información sigue registrando el nombre histórico PORTAL_INFORMATION_DRAFT_UPDATED,
con before/after que incluyen ambos snapshots; no se inventa un actor publicador.
Los fallos de auditoría revierten la transacción, incluido el contenido público.

Retirar permisos mediante CustomRoleService o asignaciones mediante HU22 conserva
la invalidación vigente de sesiones y tokens: la siguiente solicitud de la cookie
antigua se rechaza por sesión reemplazada. Incluso sin esa invalidación, cada
operación consulta la asignación vigente y devuelve 403 si ya no tiene permiso.
El header puede conservar visualmente una respuesta /me anterior hasta refrescar
la sesión, pero nunca concede autoridad a Laravel.

No se cambian autenticación móvil, contratos públicos, disponibilidad de aulas,
Aulas, catálogos académicos, permisos por examen o perfiles académicos.
No se abrió eida-mobile. El contenido público puede naturalmente reflejar la edición
en cualquier consumidor de la API pública, conservando su contrato.

## Web

PortalRoute protege solo las cinco rutas del portal bajo ProtectedRoute. No abre
la guarda de /admin ni monta AdminLayout para personal (que consulta /admin/test).
Administrador sigue usando su AdminLayout. Las rutas de categorías quedan bajo
la guarda administrativa original.

getPortalAccess y getPortalNavigation consumen /me. delegatedNavigation incorpora
solo enlaces a secciones permitidas en el Header compartido, tanto escritorio como
móvil, para cualquier cuenta no administrativa con ese permiso. Inicio, perfil de
roles base y cierre de sesión se conservan. El destino inicial de personal sigue
siendo /, con el cambio inicial de contraseña cuando corresponda. /personal mantiene
su redirección previa a /; no se vuelve a usar como menú.

Se reutilizan tablas, filtros, drawers, formularios, control de revisión y componentes
del portal. En personal no aparecen alta, categorías, publicación, retirada, archivo,
estado, historial ni mutaciones de imágenes. Noticias conserva una vista previa de
imagen de solo lectura. Información/FAQ filtra el selector a los tipos autorizados;
la nueva URL FAQ no duplica pantalla. Los editores muestran el aviso de actualización
pública antes de guardar contenido publicado.

Diferencia local preexistente: React contiene gestión/selector de categorías de
noticias, pero este backend no define su modelo, columna ni endpoints. La edición
delegada de texto no depende de ese selector ni consulta news-categories. El flujo
administrativo previo permanece; no se implementó un módulo de categorías nuevo.
Esto es un hallazgo de código local, no una afirmación sobre producción.

## Archivos de este cambio

Backend:

- routes/api/v1.php.
- app/Services/Admin/RolePermissionCatalog.php.
- app/Services/Portal/PortalEditing.php (nuevo).
- app/Services/Portal/PortalNewsService.php.
- app/Services/Portal/PortalCalendarService.php.
- app/Services/Portal/PortalInformationService.php.
- app/Services/Portal/PortalServiceCatalog.php.
- app/Http/Requests/Api/V1/Portal/RestrictsDelegatedPortalFields.php (nuevo).
- app/Http/Requests/Api/V1/Portal/SavePortalNewsRequest.php.
- app/Http/Requests/Api/V1/Portal/SavePortalEventRequest.php.
- app/Http/Requests/Api/V1/Portal/SavePortalInformationRequest.php.
- app/Http/Requests/Api/V1/Portal/SavePortalServiceRequest.php.
- database/migrations/2026_11_20_000005_add_portal_section_edit_permissions.php (nueva, sin aplicar).
- tests/Feature/Portal/DelegatedPortalEditingTest.php (nuevo).
- tests/Feature/Portal/PortalInformationTest.php.
- tests/Feature/DelegatedAcademicCatalogTest.php (catálogo dinámico y aislamiento de permisos académicos).
- docs/PORTAL_DELEGATED_EDITING.md (este documento).

Frontend:

- src/app/router/router.tsx.
- src/features/roles/utils/delegatedNavigation.ts.
- src/features/portal-shared/utils/portalAccess.ts (nuevo).
- src/features/portal-shared/components/PortalRoute.tsx (nuevo).
- src/features/portal-news/pages/AdminNewsPage.tsx.
- src/features/portal-news/components/NewsEditor.tsx.
- src/features/portal-calendar/pages/AdminCalendarPage.tsx.
- src/features/portal-calendar/components/AdminEventDialog.tsx.
- src/features/portal-calendar/components/EventForm.tsx.
- src/features/portal-information/pages/AdminInformationPage.tsx.
- src/features/portal-information/components/InformationEditor.tsx.
- src/features/portal-services/pages/AdminServicesPage.tsx.
- src/features/portal-services/components/ServiceEditor.tsx.
- src/features/portal-shared/tests/delegatedPortalEditing.integration.test.mjs (nuevo).
- src/features/auth/tests/staffNavigation.integration.test.mjs.
- Fixtures administrativos explícitos y expectativas de guardado en:
  - portal-news/tests/{hu41.admin.integration.test.mjs,hu41.categories.integration.test.mjs,newsAdmin.browser.mjs,newsEditor.browser.mjs}.
  - portal-calendar/tests/{portalCalendar.integration.test.mjs,eventVisibility.integration.test.mjs,adminCalendarFocus.browser.mjs,adminCalendarPendingEscape.browser.mjs,eventVisibility.browser.mjs}.
  - portal-information/tests/{information.integration.test.mjs,hu42-hu43.ui.browser.mjs}.
  - portal-services/tests/{services.integration.test.mjs,servicesDrawerEscape.browser.mjs}.

Los archivos que ya tenían cambios previos de navegación conservan esos cambios.
No se alteraron los controladores, recursos, modelos, resolución /me ni la asignación HU22.

## Validación pendiente en local

No se ejecutaron las pruebas. Solo se verificó sintaxis y espacios de los cambios.
Pasos para quien cierre la validación:

1. Revisar que .env apunta exclusivamente a la BD local prevista y aplicar allí las
   migraciones pendientes, incluida 000005; no usar migrate:fresh sobre datos propios.
2. Iniciar API y web con el procedimiento local habitual. Como administrador, comprobar
   las cinco entradas habilitadas en Roles; crear roles personalizados de prueba y
   asignarlos con HU22. Crear contenido de prueba publicado y en borrador con administrador.
3. Con solo portal.news.update: completar primer acceso si corresponde y entrar a /.
   Comprobar enlaces del header en escritorio y móvil, listado/drawer existentes, guardar
   noticia publicada y ver su texto en /news/{id}; editar borrador y comprobar que sigue
   sin aparecer públicamente. No deben ofrecerse altas, estados, imágenes ni historial.
4. Probar cada permiso por separado: Calendario, Información, FAQ y Servicios. Comprobar
   los filtros existentes, edición y alcance de su sección. Un evento PRIVATE debe seguir
   privado. Información/FAQ publicado cambia públicamente al guardar y borrador no se publica.
5. Forzar desde API un PATCH con texto válido + status/visibility/is_published o cualquier
   campo no autorizado: esperar 422 y ningún cambio parcial. Acceder a otra sección sin
   permiso: 403 en API y rechazo de ruta web. POST/estado/imágenes/historial: 403.
6. Retirar el permiso con la sesión del editor abierta. La siguiente operación debe
   rechazar la sesión invalidada o el permiso retirado. Tras iniciar de nuevo, /me y header
   no deben incluirlo. Cuenta sin permisos sigue pudiendo usar Inicio y cerrar sesión.
7. Administrador: verificar alta, publicación/retirada, visibilidad, imágenes e historial
   previos; también el guardado público directo de Información/FAQ. Docente y Estudiante
   sin permiso no obtienen acceso; sus destinos y perfiles habituales se conservan.

En backend, usando exclusivamente el entorno testing SQLite :memory: con session/cache
array y sin configuración cacheada:

    php artisan test --filter=DelegatedPortalEditingTest
    php artisan test --filter=PortalInformationTest
    php artisan test --filter=PortalNewsTest
    php artisan test --filter=PortalCalendarTest
    php artisan test --filter=PortalServiceTest
    php artisan test --filter=PortalHistoryTest
    php artisan test --filter=DelegatedAcademicCatalogTest
    php artisan test --filter=CustomRolesBlockOneTest
    php artisan test --filter=RoomPermissionsTest

En frontend (dependencias ya disponibles, sin instalaciones durante este trabajo):

    node --test src/features/portal-shared/tests/delegatedPortalEditing.integration.test.mjs src/features/auth/tests/staffNavigation.integration.test.mjs
    node --test src/features/portal-news/tests/news.integration.test.mjs src/features/portal-news/tests/hu41.admin.integration.test.mjs src/features/portal-news/tests/hu41.categories.integration.test.mjs
    node --test src/features/portal-calendar/tests/portalCalendar.integration.test.mjs src/features/portal-calendar/tests/eventVisibility.integration.test.mjs
    node --test src/features/portal-information/tests/information.integration.test.mjs src/features/portal-services/tests/services.integration.test.mjs
    node --test src/features/rooms/tests/roomPermissions.integration.test.mjs src/features/roles/tests/roleManagement.integration.test.mjs

Para los scripts browser de regresión modificados, leer antes sus requisitos;
no se ejecutaron ni se generaron builds o capturas en este trabajo.

