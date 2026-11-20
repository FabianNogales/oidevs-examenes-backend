# Bloque 3: permisos de catálogos académicos

## Versión y límites de esta entrega

Revisión e implementación local del 20/11/2026:

- Backend: rama main, HEAD 57164f4e4c1c899ed172285b615de7e1d930ac11.
- Frontend: rama main, HEAD 8c25db4e0f81c4b09b1f82dd27ba76cb4d78dcb9.
- Ambos tenían los bloques 1 y 2 preparados en el índice al comenzar. Se conservaron esos
  cambios y su preparación; este bloque queda sin preparar ni confirmar.
- No se cambiaron ramas ni se ejecutaron commits, push, merges, pulls, instalaciones, pruebas,
  builds, typecheck, lint global, migraciones, seeders o despliegues.
- No se consultó ninguna BD. El estado de las migraciones locales y de despliegue NO se verificó.
  No se abrió eida-mobile ni se accedió a directorios -compare.
- La comprobación realizada es estática: sintaxis PHP focalizada, análisis sintáctico TS/TSX sin
  comprobar tipos ni emitir código, sintaxis de scripts de pruebas y git diff --check.
  Esto no demuestra que las pruebas pasen ni sustituye la revisión visual.

## Contraste de los bloques anteriores con el código

BLOCK_1_ROLES_PERMISSIONS.md es histórico: su catálogo inicialmente deshabilitado ya fue
conectado a Aulas por el Bloque 2. En el código actual, RolePermissionCatalog::ENABLED habilitaba
las tres claves rooms.*, la migración 000003 habilitaba sus filas y las rutas de Aulas usaban
EnsureRoomPermission. La aplicación de esas migraciones no se infiere de los documentos.

Se reutilizan permissions/permission_role, roles/role_user, CustomRoleService y
AdminUserService::role (HU22); no se reconstruyen flujos de configuración ni asignación.
CurrentUserController continúa añadiendo permisos efectivos solo a /me web. La entrada
/personal y las prioridades de inicio de los roles base se conservan.

La lógica vigente de RoomAuthorization se extrae a PermissionAuthorization. RoomAuthorization
conserva su punto de entrada heredando ese evaluador; no cambian endpoints, servicios de negocio,
formularios ni guardas de Aulas. Sus pruebas siguen verificando las tres claves y ahora filtran
su módulo dentro de un catálogo ampliado, en lugar de asumir que todo el sistema tiene tres permisos.

## Matriz implementada

Prefijo de API: /api/v1. Todos los accesos delegados requieren autenticación, cuenta activa,
sesión vigente y primer cambio de contraseña completado. Administrador activo conserva su
acceso sin asignaciones explícitas de permisos. No se asignan permisos nuevos a roles base.

| Módulo / permiso | Acción | Endpoint y método | Ruta web | Alcance |
| --- | --- | --- | --- | --- |
| Materias / subjects.view | Consultar | GET /admin/subjects; GET /admin/subjects/{subject} | /admin/subjects | Catálogo global, activas/inactivas, código, nombre, estado y carreras asociadas |
| Materias / subjects.view | Filtrar por carrera | GET /admin/subjects/careers | Filtros de /admin/subjects | Identificador, código, nombre y estado de carreras; no abre el módulo Carreras |
| Materias / subjects.create | Registrar | POST /admin/subjects | /admin/subjects/create; modal actual del listado si también tiene consulta | Nuevas materias ACTIVE con las asociaciones permitidas por las reglas existentes |
| Materias / subjects.update | Editar | PUT /admin/subjects/{subject} | /admin/subjects/{recordId}/edit; modal actual del listado si también tiene consulta | Código, nombre y carreras del registro seleccionado; conserva estado |
| Materias / subjects.update | Cargar formulario | GET /admin/subjects/{subject}/edit | Mismo editor | Solo id, código, nombre y carreras ya asociadas, incluidas las históricas |
| Materias / subjects.create O subjects.update | Selector del formulario | GET /admin/subjects/form-careers | Formularios anteriores | Solo opciones de carreras activas; asociaciones inactivas existentes vienen del editor del registro |
| Carreras / careers.view | Consultar | GET /admin/careers; GET /admin/careers/{career} | /admin/careers | Catálogo global activo/inactivo: id, código, nombre y estado. No existen operaciones de alta/edición que habilitar |
| Periodos / academic_terms.view | Consultar | GET /admin/academic-terms; GET /admin/academic-terms/{academicTerm}; GET /admin/academic-terms/active | /admin/academic-terms | Catálogo global, nombre, fechas y estado; conserva detección de varios periodos activos |
| Periodos / academic_terms.create | Registrar inactivo | POST /admin/academic-terms | /admin/academic-terms/create; modal del listado si también tiene consulta | Periodo nuevo INACTIVE. El permiso no autoriza crear ACTIVE |
| Periodos / academic_terms.update | Editar | PUT /admin/academic-terms/{academicTerm} | /admin/academic-terms/{recordId}/edit; modal del listado si también tiene consulta | Nombre y fechas del periodo seleccionado; mantiene su estado |
| Periodos / academic_terms.update | Cargar formulario | GET /admin/academic-terms/{academicTerm}/edit | Mismo editor | Datos del único periodo: id, nombre, fechas y estado de solo lectura. Sin listado ni consulta del activo |
| Ofertas / course_offerings.view | Consultar | GET /admin/course-offerings; GET /admin/course-offerings/{courseOffering} | /admin/teachers/assignments | Catálogo global de asignaciones activas/inactivas, materia/carreras, periodo, docente y conteos. El listado está en la web; el detalle individual ya existente sigue disponible en API |
| Ofertas / course_offerings.view | Selector de periodos | GET /admin/course-offerings/periods | Filtro del mismo listado | Solo id, nombre y estado de periodos; sin consulta general ni permiso de Periodos |
| Aulas / rooms.view, rooms.create, rooms.update | Regresión del Bloque 2 | Rutas ya documentadas en BLOCK_2_ROOM_PERMISSIONS.md | Rutas existentes de Aulas | Sin ampliación del alcance |

El catálogo de Ofertas incorpora del docente id, nombres, apellidos, código institucional,
correo institucional y estado; NO incluye CI, foto, historial o acceso a su cuenta. Incluye
enrollments_count y exams_count, sin autorizar los endpoints de matrículas o exámenes.
teacher_id, academic_term_id, estado y búsqueda siguen siendo filtros, no restricciones de
propiedad: CourseOfferingService::paginate y find eran consultas globales administrativas.
Quien configure course_offerings.view está concediendo esa consulta global, no solo las ofertas
de un docente. No se inventó un reparto por sede o responsable.

CourseOfferingService::lockTeacher comprueba perfil, cuenta y rol DOCENTE del docente destino;
no es un requisito de rol del actor lector. Esa regla de las escrituras se conserva y no se
resuelve creando perfiles implícitos para el personal.

Evidencia de los alcances: SubjectService::paginate/save, CareerController::index/show y
CareerResource::toArray, AcademicTermService::paginate/find/active y
AcademicTermIntegrity::validatePayload/assertCanSave, CourseOfferingService::paginate/find,
CourseOfferingResource::toArray y sus rutas previas. Los requests correspondientes autorizaban
mediante el middleware del grupo; no había una segunda exigencia de perfil docente en esas
consultas ni en los servicios de Materias/Periodos.

## Reglas y dependencias conservadas

- Materias mantiene normalización/unicidad del código, una o más carreras existentes, carreras
  activas para nuevas asociaciones y conservación de asociaciones históricas inactivas.
  SaveSubjectRequest sigue rechazando todo campo ajeno, incluido status. SubjectService mantiene
  bloqueos, sincronización y auditoría SUBJECT_CREATED/SUBJECT_UPDATED.
- Periodos mantiene nombres normalizados únicos, fechas válidas, rango, no solapamiento incluso
  entre inactivos, bloqueo global e integridad de periodo activo único. PUT ya rechazaba status.
  POST admitía estado inicial: el servicio conserva ACTIVE para administrador y lo rechaza para
  personal aunque manipule el payload. El formulario delegado fija INACTIVE y oculta el selector.
  No se ocultan fallos de autorización como errores 500 en el servicio.
- Las escrituras de Materias y Periodos revalidan actor, sesión y permisos dentro de su
  transacción actual antes de guardar. No se eliminan controles de negocio ni auditoría.
- Crear sin consultar no monta hooks de listado ni el resumen de periodo activo. Materias
  solo obtiene sus opciones de asociación, no /admin/careers ni el catálogo general de materias.
- Editar sin consultar carga únicamente el registro cuyo enlace se conoce. /personal y la entrada
  del módulo explican ese límite; no ofrecen un buscador o listado implícito. Un permiso de
  edición global permite modificar cualquier registro del catálogo mediante su id, sujeto a
  las reglas existentes; no supone propiedad por usuario.
- Carreras usa AdminReadPage/ReadPage, su tabla paginada, búsqueda y modal de detalle existentes.
  No se crea otro módulo ni escrituras de Carreras.
- Ofertas reutiliza AdminCourseOfferingsPage, CourseOfferingsPanel y CourseOfferingsTable en modo
  de consulta. Se ocultan creación/asignación, importación, edición y estado para personal.
  Su selector de periodos deja de depender del acceso al catálogo administrativo de Periodos.
  Los formularios administrativos conservan sus datos necesarios y controles anteriores.
- Se reutilizan PageContainer, PageHeader, DataTable, formularios, selectores, PortalDialog,
  botones y CSS actuales. No se cambia paleta, Poppins o estructura responsive. La guarda de
  los nuevos catálogos proporciona un main al personal; AdminLayout conserva el main y la
  comprobación /admin/test del administrador.
- ProtectedRoute, la guarda general de /admin y las prioridades de inicio base no se flexibilizan.
  Solo se extraen las cuatro rutas de catálogo a ramas protegidas por su permiso.
  Importación y las demás pantallas administrativas permanecen en la rama de Administrador.
- /personal y el encabezado muestran módulos según permisos efectivos de /me. Crear y editar no
  implican consultar. La configuración de Roles muestra los ocho permisos nuevos como operativos
  cuando sus filas estén habilitadas, sin anunciar permisos de operaciones pendientes.
- HU22 y editar un rol siguen revocando tokens y rotando active_session_id. La próxima petición
  de la cookie sustituida recibe SESSION_REPLACED; un token eliminado ya no autentica. Tras login,
  la operación retirada responde 403. También se consulta BD en cada autorización: falsificar
  permissions en React o abrir una URL directa no concede acceso.
- No se tocan CurrentUserController, CurrentUserResource, MobileTokenPermissions ni
  EnforceMobileTokenAccess. La allowlist móvil de backend sigue sin incluir APIs administrativas.
  Disponibilidad GET /rooms y programación/operaciones por examen conservan sus rutas y contratos.

## Operaciones reservadas o pendientes: decisión concreta

Esta clasificación conserva los controles actuales; no constituye una política nueva del cliente.
No se crean permisos configurables para operaciones cuya conexión se aplaza.

| Módulo / operación existente aplazada | Qué se conserva | Decisión necesaria antes de delegar |
| --- | --- | --- |
| Materias: activar y desactivar, PATCH /admin/subjects/{id}/status | Administrador | Confirmar si personal puede cambiar la vigencia del catálogo global y qué autoridad puede hacerlo, independiente de editar metadatos |
| Materias: CSV preview y confirm | Administrador y propiedad/vencimiento de la vista previa | Autorizar expresamente carga masiva y su alcance de carreras/materias; definir responsables de confirmar filas, sin incluirla en crear |
| Periodos: activar y desactivar, PATCH /admin/academic-terms/{id}/status | Administrador; estado único global y controles de conflictos | Confirmar quién puede cambiar el ciclo vigente de toda la institución. Este bloque no concede esa autoridad por editar fechas ni por crear un periodo |
| Ofertas: asignación manual/bulk POST /admin/course-offerings/bulk | Administrador y validación de docente/cuenta/rol, materias y periodo activos | Definir si el personal puede asignar cualquier docente o solo un conjunto autorizado y quién concede esa responsabilidad |
| Ofertas: edición PUT /admin/course-offerings/{id} | Administrador; no sustituir referencias cuando ya hay exámenes/matrículas | Definir sobre qué asignaciones puede reasignar docente/materia/periodo, diferenciándolo de la consulta global |
| Ofertas: activar/desactivar PATCH .../{id}/status | Administrador y validación de referencias | Definir quién puede habilitar/deshabilitar asignaciones y en qué periodos/docentes, mediante acciones independientes |
| Ofertas: CSV preview/confirm | Administrador, propietario de preview e integridad de cada fila | Definir autoridad para asignación masiva y confirmación sobre los docentes/periodos anteriores; no reutilizar consulta |
| Estudiantes: listado GET /admin/students | Administrador; representación reducida sin CI | Confirmar si la consulta de personal puede ser global o debe limitarse por carrera/oferta, y qué datos de contacto puede ver |
| Estudiantes: detalle GET /admin/students/{id}, fotos e historial académico | Administrador; detalle incluye CI y enlaces de fotos; records combina datos por perfil | Determinar conjunto de estudiantes, atributos visibles, permiso independiente de fotos/historial y finalidad autorizada |
| Estudiantes: alta manual POST /admin/students e importación CSV existente | Administrador; alta de perfil + cuenta + rol + contraseña inicial; importación tiene sus propios controles | Confirmar si personal puede crear cuentas/roles base, para qué población, y si se separará carga académica de provisión de acceso |
| Estudiantes: edición PATCH /admin/students/{id} | Administrador; puede cambiar CI, datos y correo de la cuenta | Definir campos delegables y estudiantes permitidos, separando cambios de identidad/correo de acceso de edición académica |
| Estudiantes: activar/desactivar PATCH .../{id}/status | Administrador; cambia perfil y cuenta y revoca sesiones al desactivar | Mantener reservado acceso o aprobar expresamente una operación distinta de estado académico; no derivarla de editar estudiante |
| Estudiantes: carga/corrección de foto histórica POST .../historical-photo[/corrections] | Administrador, revisión y auditoría existentes | Definir quién valida evidencia/identidad, qué estudiantes abarca y si consulta, carga y corrección se delegan por separado |
| Docentes: listado/detalle GET /admin/teachers[/{id}] e historial por perfil | Administrador; TeacherResource expone CI además de contacto y código | Confirmar población y atributos visibles al personal, separando CI/historial de la identidad institucional ya incluida en Ofertas |
| Docentes: alta POST /admin/teachers | Administrador; crea cuenta, perfil, rol DOCENTE y primer cambio de contraseña | Decidir autoridad para provisionar cuentas docentes. El permiso de alta académica no puede concederla implícitamente |
| Docentes: edición PUT /admin/teachers/{id} | Administrador; incluye CI y correo de users | Decidir campos y conjunto autorizado de docentes, y reservar cambios de acceso o separarlos explícitamente |
| Docentes: activar/desactivar PATCH .../{id}/status | Administrador; cambia perfil/cuenta y revoca sesiones | Decidir sobre administración de acceso, que continúa reservada; no incluirla en edición del perfil |
| Portal: consultar listados, detalles, imágenes e historiales administrativos de noticias/eventos/información/servicios/categorías | Administrador; la API pública conserva sus consultas actuales | Definir si personal puede ver todos los borradores/ocultos, solo propios o asignados; decidir visibilidad de historiales/autores |
| Portal: crear noticias, eventos, servicios y FAQ; editar borradores/contenido/imágenes/orden/categorías | Administrador; revisiones y auditoría actuales | Definir quién edita qué contenidos y si puede modificar contenido ya publicado. Noticias/eventos pueden cambiar contenido visible directamente; no asumir un flujo de aprobación inexistente |
| Portal: publicar/retirar noticias/eventos/información; activar/desactivar servicios/categorías | Administrador; acciones de estado/publicación existentes | Nombrar autoridad de publicación por tipo y alcance, separada de creación/edición. Cambiar categorías/servicios activos también puede afectar la web pública |
| Exámenes: catálogo/detalle, programar, editar y cancelar; colaboradores | Docente responsable de oferta/examen y controles de estado/tiempo; asignación de colaboradores por responsable | Definir cómo se asigna responsabilidad por oferta/examen al personal sin fabricar un perfil docente; distinguir gestionar exámenes ajenos y gestionar colaboradores |
| Matrículas: consulta y altas manual/CSV por oferta | Docente y EnsureCourseOfferingAccess | Definir ofertas y estudiantes permitidos y quién aprueba cambios en su matrícula; no usar consulta administrativa de Ofertas como autorización |
| Habilitaciones: consulta/foto/motivos, ajuste individual y carga masiva | ExamEligibilityService::responsibleExam y rol docente responsable | Definir exámenes, datos sensibles y autoridad para habilitar o revocar estudiantes; consulta y cambios requieren decisiones independientes |
| Habilitación automática: criterios globales, configuración por examen, preview/confirm, historial y registros académicos/importación | Administración para criterios; responsabilidad por examen/oferta para configuración/evaluación/registros | Decidir quién define reglas globales, quién evalúa y confirma por examen y qué población académica puede consultar/importar; mantener separación preview/confirm |
| Ingresos: consulta de estudiantes/fotos/entradas, QR/manual y anulación | Responsable o asignación de colaborador vigente por examen; anulación con EntryAnnulmentAccess | Definir asignación explícita por examen, ventana de operación y quién puede anular entradas ajenas; no convertir Colaborador en rol global |
| Asistencia: resumen, estudiantes y colaboradores | EnsureAttendanceExamOwner/AttendanceAccess | Definir exámenes consultables y visibilidad de asistencia por persona; no abrir todos los exámenes por un permiso de catálogo |
| Incidencias: consulta/detalle/registro y exportación; reportes globales | IncidentAccessService distingue responsable, colaborador y administrador; exportación tiene otra capacidad | Definir exámenes asignados, si puede registrar o solo consultar y quién puede exportar datos nominativos, separadamente |
| Infracciones: opciones, consulta, historiales, alta, edición y estado | EnsureInfractionExamOwner/InfractionAccess y estado del examen | Definir responsable por examen, acceso a antecedentes y autoridad para registrar/corregir/anular una sanción; no eliminar propiedad para delegar |
| Expulsiones: consulta, opciones, historial, registro y detalle | EnsureExpulsionExamOwner/ExpulsionAccess y reglas sobre estudiantes/infracciones | Definir exámenes y estudiantes autorizados y quién decide la expulsión, distinguiendo consulta de sanción |
| Casos disciplinarios: consulta, resolución de contexto, registro, expulsión y exportación | DisciplineService y accesos por examen vigentes | Definir el alcance por examen y si personal puede resolver/registrar/expulsar o solo leer; exportación separada por datos incluidos |
| Reportes de exámenes: catálogo, consulta y exportación PDF/CSV por tipo | ExamReportAccess: administrador global o docente propietario | Decidir exámenes y tipos consultables, atributos nominativos y autoridad independiente para exportar. No dar acceso global para sustituir al docente propietario |
| Preparación/consulta/sincronización offline de examen y fotos del paquete | OfflineExamAccess y propietario/asignación histórica de paquetes | Definir operador por examen, custodia de datos offline y revocación/sincronización. No ampliar contratos compartidos con APK |

Gestión de roles/permisos, alta de personal, asignación/retiro de roles, cambios de acceso a
cuentas y auditoría administrativa permanecen reservados al administrador por instrucción de
alcance. No están pendientes de un permiso técnico genérico.

Evidencia adicional conservada: TeacherService::create/update/changeStatus y TeacherResource;
StudentRegistrationService::create, StudentService::update/changeStatus y StudentDetailResource;
PortalNewsService::save/changeStatus/changeImage, PortalCalendarService::save/changeStatus,
PortalInformationService::save/publish y PortalServiceCatalog::save/changeStatus;
ExamManagementPolicy::canView, ExamCollaboratorService::authorizeTransition,
ExamEligibilityService::responsibleExam, VerifyExamAccess, IncidentAccessService::permissions,
InfractionAccess::exam, ExpulsionAccess::exam y ExamReportAccess::scope/exam.
Las rutas de automatización se mantienen en routes/api/eligibility-automation.php.

## Migración y archivos

Migración nueva: database/migrations/2026_11_20_000004_add_delegated_academic_catalog_permissions.php.
Inserta únicamente ocho claves nuevas con su módulo/etiqueta/alcance; la restricción única impide
duplicados. No cambia roles base ni asigna permisos a cuentas. Revertirla elimina solo esas
claves y sus vínculos permission_role, conservando roles y asignaciones HU22. No se ejecutó.

Backend (rutas relativas a oidevs-examenes-backend):

- `app/Http/Controllers/Api/V1/Admin/AcademicTermController.php`
- `app/Http/Controllers/Api/V1/Admin/CourseOfferingController.php`
- `app/Http/Controllers/Api/V1/Admin/SubjectController.php`
- `app/Http/Middleware/EnsurePermission.php`
- `app/Services/AcademicTerms/AcademicTermService.php`
- `app/Services/Admin/PermissionAuthorization.php`
- `app/Services/Admin/RolePermissionCatalog.php`
- `app/Services/Rooms/RoomAuthorization.php`
- `app/Services/Subjects/SubjectService.php`
- `database/migrations/2026_11_20_000004_add_delegated_academic_catalog_permissions.php`
- `docs/BLOCK_1_ROLES_PERMISSIONS.md`
- `docs/BLOCK_2_ROOM_PERMISSIONS.md`
- `docs/BLOCK_3_ACADEMIC_CATALOG_PERMISSIONS.md`
- `routes/api/v1.php`
- `tests/Feature/CustomRolesBlockOneTest.php`
- `tests/Feature/DelegatedAcademicCatalogTest.php`
- `tests/Feature/Rooms/RoomPermissionsTest.php`

Frontend (rutas relativas a oidevs-examenes-frontend):

- `src/app/router/router.tsx`
- `src/features/academic-terms/api/academicTermsApi.ts`
- `src/features/academic-terms/components/AcademicTermDetails.tsx`
- `src/features/academic-terms/components/AcademicTermDialog.tsx`
- `src/features/academic-terms/components/AcademicTermFields.tsx`
- `src/features/academic-terms/components/AcademicTermForm.tsx`
- `src/features/academic-terms/components/AcademicTermsTable.tsx`
- `src/features/academic-terms/hooks/useAcademicTermRecord.ts`
- `src/features/academic-terms/pages/AdminAcademicTermsPage.tsx`
- `src/features/course-offerings/api/courseOfferingsApi.ts`
- `src/features/course-offerings/components/CourseOfferingsPanel.tsx`
- `src/features/course-offerings/components/OfferingEditForm.tsx`
- `src/features/course-offerings/components/OfferingRecordDialog.tsx`
- `src/features/course-offerings/hooks/useOfferingPeriods.ts`
- `src/features/course-offerings/pages/AdminCourseOfferingsPage.tsx`
- `src/features/course-offerings/types/courseOffering.types.ts`
- `src/features/roles/components/CatalogRoute.tsx`
- `src/features/roles/components/RoleEditorDialog.tsx`
- `src/features/roles/pages/AdminRolesPage.tsx`
- `src/features/roles/pages/CatalogWorkspacePage.tsx`
- `src/features/roles/tests/academicCatalogPermissions.integration.test.mjs`
- `src/features/roles/utils/moduleAccess.ts`
- `src/features/subjects/api/adminSubjectsApi.ts`
- `src/features/subjects/api/subjectMutationsApi.ts`
- `src/features/subjects/components/AdminSubjectsTable.tsx`
- `src/features/subjects/components/SubjectCareerSelector.tsx`
- `src/features/subjects/components/SubjectDetails.tsx`
- `src/features/subjects/components/SubjectDialog.tsx`
- `src/features/subjects/components/SubjectForm.tsx`
- `src/features/subjects/hooks/useAdminSubjects.ts`
- `src/features/subjects/pages/AdminSubjectsPage.tsx`
- `src/features/subjects/types/adminSubject.types.ts`
- `src/features/users/pages/StaffHomePage.tsx`
- `src/layouts/AppLayout/AppLayout.tsx`
- `src/shared/components/ui/tests/pageLayout.browser.mjs`

Las pantallas adaptadas son AdminSubjectsPage, AdminAcademicTermsPage, AdminCourseOfferingsPage,
AdminRolesPage y StaffHomePage, más sus diálogos/formularios. Carreras reutiliza AdminReadPage
sin modificar su pantalla. CatalogWorkspacePage solo monta esas pantallas/componentes existentes
según la acción; no implementa versiones por rol.

## Pruebas añadidas y pasos de validación local

No se ejecutaron las pruebas.

- tests/Feature/DelegatedAcademicCatalogTest.php: 18 casos para catálogo, consulta, creación sin
  consulta/edición, edición independiente, carreras históricas, estado de Periodos y payloads,
  alcance de Ofertas/selectores, APIs reservadas, retiro, /me, sesiones/tokens, HU22, Aulas,
  administrador y roles base sin permisos nuevos. Rechaza BD persistente, configuración
  cacheada o sesión/cache distintos de array antes de RefreshDatabase.
- src/features/roles/tests/academicCatalogPermissions.integration.test.mjs: integración de
  fuentes reales con hooks/HTTP simulados para consulta, consulta+creación, creación sin
  listado, edición por enlace, formularios y sus peticiones mínimas, rutas directas, permisos
  retirados, menú, Carreras, Ofertas, administrador y regresión de Aulas. No equivale a QA visual.
- CustomRolesBlockOneTest y RoomPermissionsTest: ajustes de expectativas del catálogo ampliado;
  se conservan pruebas de seguridad de HU22 y Aulas.
- pageLayout.browser.mjs: fixtures de identidad administrativa y dependencias nuevas adaptados;
  tampoco se ejecutó y requiere revisar sus prerrequisitos de servicios/navegador.

Secuencia recomendada, a ejecutar después de esta entrega:

1. Revisar diffs contra el índice de los bloques 1 y 2. Confirmar entorno local y nombre/host de
   BD, copia adecuada y estado de migraciones. No inferirlo de este informe ni ejecutar migrate
   general si hay migraciones ajenas pendientes. Con los bloques anteriores aplicados, ejecutar
   únicamente php artisan migrate --path=database/migrations/2026_11_20_000004_add_delegated_academic_catalog_permissions.php.
2. En un entorno de pruebas aislado (testing, SQLite :memory:, sesión/cache array, sin DB_URL
   persistente ni caché de configuración), ejecutar:
   - php artisan test --filter=DelegatedAcademicCatalogTest
   - php artisan test --filter=CustomRolesBlockOneTest
   - php artisan test --filter=RoomPermissionsTest
   - Regresiones de tests/Feature/Subjects, AcademicTerms, CourseOfferings, Rooms, HU22/cuentas,
     docente/estudiante y MobileAuthenticationTest del backend, siempre en BD de pruebas.
3. En frontend:
   - node --test src/features/roles/tests/academicCatalogPermissions.integration.test.mjs
   - node --test src/features/roles/tests/roleManagement.integration.test.mjs
   - node --test src/features/rooms/tests/roomPermissions.integration.test.mjs
   - Regresiones de cuentas, encabezado y accesibilidad relacionadas. Revisar prerrequisitos
     antes de la QA de navegador; no iniciar servicios de prueba contra una API compartida.
4. Crear mediante la UI de Roles cuentas de prueba con: solo consulta por módulo, consulta+
   creación sin edición, solo creación, solo edición y sin permisos. Probar también varias
   asignaciones activas y retirar una sin perder los permisos aportados por otra.
5. En red, creación sin consulta no debe pedir el listado ni /admin/careers; solo Materias
   carga /admin/subjects/form-careers. Editores independientes deben pedir únicamente su
   registro /edit. Ofertas debe usar /admin/course-offerings/periods sin pedir /admin/academic-terms.
6. Probar payloads directos con status en edición, alta delegada de periodo ACTIVE, CSV, estado,
   cuentas, roles y rutas de portal/reportes: deben seguir denegados. Administrador debe
   conservar CSV, estado y alta de periodo activo, sin ampliación para Docente/Estudiante.
7. Retirar un permiso con sesión abierta por gestión de Roles: verificar marcador rotado,
   tokens eliminados, siguiente request rechazado y 403 tras nuevo login en la operación
   retirada, aunque se abra la URL o se manipulen permisos de React.
8. Validar en PostgreSQL local unicidad, rollback de auditoría, fechas/solapamientos, integridad
   de asociaciones y concurrencia. Revisar visualmente 320/390/768/1440 px, teclado, foco,
   modales, errores, cancelación y menús. La sintaxis comprobada aquí no valida estos aspectos.
