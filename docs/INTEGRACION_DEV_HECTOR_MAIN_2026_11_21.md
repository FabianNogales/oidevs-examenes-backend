# Integración de Dev Hector en main del backend EIDA

Fecha: 21 de noviembre de 2026, America/La_Paz.

El merge de `origin/Dev_Hector` quedó preparado en `main`, sin commit y sin conflictos. Se conservaron las rutas y reglas de autorización de main y se ajustó la creación de noticias para seguir admitiendo solicitudes sin categoría. La validación realizada es estática: las pruebas funcionales y las verificaciones de datos quedan pendientes antes del despliegue.

## 1 Estado inicial de las ramas

Repositorio: `C:/proyecto/oidevs-examenes-backend`.
Rama actual y destino: `main`.

| Referencia | SHA después del fetch |
| --- | --- |
| HEAD, main y origin/main | `90f987816284a45517501b86eae8a98939270ee2` |
| origin/Dev_Hector | `a7dfb1004ea812fbb286e2aa4440535282e86cf9` |
| Ancestro común | `90f987816284a45517501b86eae8a98939270ee2` |

El árbol inicial estaba limpio: ningún archivo modificado, staged o untracked.
El fetch de origin terminó correctamente. main no tenía adelanto ni atraso
respecto de origin/main (`0 / 0`), por lo que no se necesitó avance fast-forward.
El HEAD de main ya era ancestro de la rama origen.

Commits exclusivos de la rama origen:

- `9172f18`: categorías de calendario/noticias con revisiones y auditoría.
- `7779b48`: revisión HU34 e importación académica parcial.
- `c2ed83a` y `ba09a40`: merges de main hacia Dev_Hector.
- `a7dfb10`: correcciones de validaciones y catálogos del QA de Saúl.

Se ejecutó `git merge --no-commit --no-ff origin/Dev_Hector`.
Git informó que el merge automático terminó y se detuvo antes del commit.
No se cambió de rama.

## 2 FIX y funcionalidades integradas

| Área | Resultado |
| --- | --- |
| Portal | Catálogos independientes de categorías de calendario y noticias, selección de activas, administración, cambios de estado, revisión optimista e historial. Índices únicos normalizados contra duplicados concurrentes. |
| Noticias | Asociación opcional a categoría, recurso category y filtro category_id antes de paginar. Las noticias históricas siguen visibles según sus reglas de publicación. |
| Calendario | Revalidación de categorías con bloqueo; creación o reprogramación desde mañana en Bolivia; duración y horizonte de hasta cinco años. Ediciones descriptivas de eventos históricos siguen permitidas. |
| HU34 | Búsqueda y filtros de resultados antes de paginar, resumen global, distinción entre cambio real y actualización de trazabilidad, motivos efectivos y decisiones protegidas. |
| Datos académicos | Modo MERGE optativo para notas/asistencia parciales; celdas vacías preservan valores; justificación al modificar datos; detección de revisiones obsoletas y confirmación repetible. CREATE_ONLY sigue siendo el modo predeterminado. |
| Períodos | Duración de 30 a 366 días; fin posterior al inicio; límites de antigüedad y planificación; rechazo de activación vencida y cierre automático auditado. |
| Aulas | Código generado si se omite al crear, edificios opcionales, piso entre -5 y 200 y planta baja 0/PB, capacidad informada mínima 10, inactividad temporal y activación masiva administrativa. |
| Disponibilidad | Consulta diaria opcional, inclusión de aulas secundarias, conteo de reservas y fechas ISO. Se conservan períodos de reserva redondeados y exclusión de exámenes cancelados. |
| Materias | Nombres únicos sin distinguir mayúsculas, protección transaccional PostgreSQL y validación CSV, conservando asociaciones a varias carreras y duplicados históricos sin renombrarlos. |
| Incidencias | Catálogo de exámenes filtrable por período, materia, carrera, oferta y tipo de evaluación antes de paginar, con metadatos adicionales. |

Las reglas nuevas de períodos también se validan al guardar una edición: un período
histórico o con duración fuera de rango puede devolver 422 aunque se intente cambiar
su nombre. Los datos históricos siguen disponibles para consulta.
Los pisos históricos de texto distintos de PB/números pueden necesitar corrección
explícita antes de guardar un formulario que los vuelva a enviar. No hubo backfill.

HU34 ahora propone ELIGIBLE cuando no quedan criterios automáticos aplicables;
no cambia habilitaciones hasta confirmar explícitamente. Conserva decisiones
MANUAL, LEGACY u otras fuentes protegidas. Los previews de evaluaciones anteriores
a la versión 2 del contrato siguen consultables, pero si no estaban confirmados
deben regenerarse para confirmar; responden 409. Las confirmaciones ya realizadas
conservan su respuesta idempotente.

## 3 Archivos modificados

Dev_Hector aporta 59 archivos respecto de main: 1809 inserciones y 81 eliminaciones.
La integración agrega una ampliación de pruebas de permisos delegados y este informe.
El resultado final contiene 61 archivos staged, sin eliminaciones.

Inventario completo respecto del HEAD inicial; A significa añadido y M modificado:

```text
M	app/Http/Controllers/Api/V1/Admin/RoomController.php
M	app/Http/Controllers/Api/V1/Eligibility/AcademicRecordController.php
A	app/Http/Controllers/Api/V1/Portal/PortalCategoryController.php
A	app/Http/Middleware/RefreshCatalogLifecycle.php
M	app/Http/Requests/Api/V1/Portal/ListPortalNewsRequest.php
M	app/Http/Requests/Api/V1/Portal/SavePortalNewsRequest.php
M	app/Http/Requests/Api/V1/Rooms/ListRoomsRequest.php
M	app/Http/Requests/Api/V1/Rooms/SaveRoomRequest.php
M	app/Http/Requests/Api/V1/Rooms/UpdateRoomStatusRequest.php
M	app/Http/Requests/Api/V1/Subjects/SaveSubjectRequest.php
M	app/Http/Requests/Incidents/IncidentExamCatalogRequest.php
M	app/Http/Resources/Portal/PortalHistoryResource.php
M	app/Http/Resources/Portal/PortalNewsResource.php
M	app/Http/Resources/Rooms/RoomResource.php
M	app/Models/PortalNews.php
A	app/Models/PortalNewsCategory.php
M	app/Models/Room.php
A	app/Rules/UniqueCatalogName.php
M	app/Services/AcademicTerms/AcademicTermIntegrity.php
A	app/Services/CatalogLifecycle.php
A	app/Services/CatalogNameGuard.php
M	app/Services/Eligibility/Automation/AcademicDataService.php
A	app/Services/Eligibility/Automation/AcademicMergeService.php
M	app/Services/Eligibility/Automation/AutomationSupport.php
M	app/Services/Eligibility/Automation/EvaluationService.php
M	app/Services/Incidents/IncidentExamCatalog.php
M	app/Services/Portal/PortalCalendarService.php
M	app/Services/Portal/PortalNewsService.php
M	app/Services/Rooms/RoomAvailabilityService.php
M	app/Services/Rooms/RoomCsvParser.php
M	app/Services/Rooms/RoomService.php
M	app/Services/Subjects/SubjectCsvParser.php
M	app/Services/Subjects/SubjectImportService.php
M	app/Services/Subjects/SubjectService.php
A	config/catalog_limits.php
A	database/migrations/2026_11_09_000001_manage_portal_categories.php
A	database/migrations/2026_11_20_000001_add_room_buildings_and_temporary_inactivity.php
M	docs/BLOCK_2_ROOM_PERMISSIONS.md
M	docs/BLOCK_3_ACADEMIC_CATALOG_PERMISSIONS.md
M	docs/HU34_ELIGIBILITY_AUTOMATION_API.md
M	docs/HU38_HU39_PORTAL_CALENDAR_API.md
M	docs/HU40_HU41_PORTAL_NEWS_API.md
A	docs/PORTAL_CATEGORIES_API.md
A	docs/examples/portal_categories_responses.json
A	routes/api/portal-categories.php
M	routes/api/v1.php
M	routes/console.php
M	tests/Feature/AcademicTerms/AcademicTermIntegrityTest.php
M	tests/Feature/Api/V1/Eligibility/EligibilityAutomationTest.php
M	tests/Feature/Portal/DelegatedPortalEditingTest.php
M	tests/Feature/Portal/PortalCalendarTest.php
A	tests/Feature/Portal/PortalCategoryTest.php
M	tests/Feature/Portal/PortalHistoryTest.php
M	tests/Feature/Portal/PortalNewsTest.php
M	tests/Feature/Rooms/RoomImportPreviewTest.php
M	tests/Feature/Rooms/RoomPermissionsTest.php
M	tests/Feature/Rooms/RoomWriteTest.php
A	tests/Feature/SaulBackendRegressionTest.php
M	tests/Feature/Subjects/SubjectWriteTest.php
M	tests/Integration/Images/PostgresImageReadTest.php
A	docs/INTEGRACION_DEV_HECTOR_MAIN_2026_11_21.md
```

## 4 Conflictos y decisiones de integración

No hubo conflictos textuales ni archivos unmerged. No se usaron ours/theirs
sobre archivos completos.

Se encontró una incompatibilidad de contrato en
`SavePortalNewsRequest.php`: Dev_Hector exigía category_id en todo POST de
noticias, mientras main admitía title, summary y content sin ese campo.
La columna nueva es nullable y el servicio ya admite su ausencia. Se dejó
category_id opcional tanto al crear como al editar; si se envía, debe ser entero,
existente y activo, y no puede ser null. Esto conserva el contrato anterior y
permite la funcionalidad nueva sin asignar categorías ficticias.

Se adaptó `PortalCategoryTest.php` para cubrir creación/publicación sin categoría,
rechazo de categorías inválidas/inactivas y asignación normal. Se actualizaron
los contratos escritos de categorías y noticias.

Los permisos delegados de main permiten editar texto de noticias, pero no asignar
categorías de noticias. Se conservó esa restricción en `PortalEditing` y se
amplió `DelegatedPortalEditingTest.php` para comprobar el rechazo atómico de
category_id. La gestión de categorías permanece exclusiva de ADMIN. No se
ampliaron permisos ni se agregaron permisos implícitos a roles personalizados.

Se añadieron dos pruebas en `SaulBackendRegressionTest.php` para el comando CLI:
repetición sin auditorías duplicadas, conservación de inactividad permanente/futura
y rollback de la transición cuando falla la auditoría, seguido de reintento.
Todas estas pruebas quedan sin ejecutar.

## 5 Funcionalidades de main preservadas

La inspección de rutas mediante un Router aislado, sin arrancar la aplicación ni
cargar .env o conectar a BD, comparó las rutas de HEAD con el resultado del merge.
Se conservaron las 356 combinaciones de método/URI previas y se agregaron 22
(incluidos HEAD automáticos). No cambiaron acciones, nombres, restricciones
de parámetros ni middleware previo. La única ampliación del middleware existente
es RefreshCatalogLifecycle en los grupos administrativos de períodos y aulas.
Todas las acciones registradas tienen una clase y un método existentes.

Se conservaron:

- Evaluación de roles/permisos personalizados, migraciones de permisos y
  comprobaciones de autorización dentro de los servicios de escritura.
- Administración del Portal, publicación, visibilidad, imágenes y edición delegada.
- Habilitaciones manuales, auditoría, revisiones, ownership y confirmación
  explícita de automatización académica.
- Programación, reservas, cancelación, ingresos, incidencias y contratos vigentes.
- HU35/HU36/HU37, paquetes offline, QR v3, autenticación móvil y sincronización.

No hay archivos eliminados. Los servicios de roles, offline, QR v3, entradas,
autenticación móvil y bootstrap no fueron reemplazados por versiones anteriores.
La disponibilidad de aulas sí cambia de manera complementaria y mantiene las
reglas de reserva de main. Preservación comprobada por código y rutas; no equivale
a una validación funcional ejecutada en navegador o Android.

## 6 Migraciones y procedimiento posterior

Hay exactamente dos migraciones nuevas. No se ejecutó ninguna ni se consultó
la BD local o de producción.

| Orden | Migración | Esquema y datos |
| --- | --- | --- |
| 1 | `2026_11_09_000001_manage_portal_categories.php` | Agrega revision default 1 a portal_event_categories; crea portal_news_categories con nombre único, estado, revisión y timestamps; agrega portal_news.category_id nullable con FK restrictOnDelete. Inicializa cuatro categorías de noticias y asegura Actividades académicas. Crea índices únicos LOWER(TRIM(name)) en ambos catálogos. |
| 2 | `2026_11_20_000001_add_room_buildings_and_temporary_inactivity.php` | Crea buildings con nombre único, estado y timestamps; agrega rooms.building_id nullable con FK restrictOnDelete e inactive_until timestampTz nullable con índice. Inicializa Edificio principal. |

Dependen del esquema inicial y de las migraciones de Portal ya presentes en main.
La de categorías se ordena después de create_mobile_incident_sync_events por
su nombre completo; la de edificios antes de create_role_permissions, aunque
compartan prefijo numérico. Los nombres completos son distintos y no hay
dependencias cruzadas con las migraciones de roles. Si main ya está migrado,
Laravel detectará igualmente estos nombres pendientes aunque su fecha sea anterior
a otras migraciones ya aplicadas.

No duplican tablas/columnas/índices de las migraciones versionadas de main.
No son seguras para reejecutarse manualmente sobre un esquema ya alterado sin
su registro en migrations. Debe verificarse cualquier deriva de esquema previamente.

Riesgos para datos existentes:

- Nombres de categorías de eventos equivalentes tras LOWER(TRIM(name)) impiden
  crear el índice. No se fusionan IDs ni se reasignan eventos automáticamente.
- No se transforma ninguna noticia: category_id queda null. No se deducen edificios
  desde location y las aulas conservan IDs, textos, estado y capacidad.
- Las migraciones insertan datos de referencia dentro de up(); no requieren seeders.
- ALTER TABLE e índices pueden bloquear escrituras durante su ejecución.
  PostgreSQL permite transacciones de esquema; cada migración tiene su propia
  transacción. Si falla la segunda, la primera puede quedar ya aplicada.
- down() de categorías está vacío: rollback no restaura el esquema y puede
  desregistrar una migración cuyo esquema sigue presente. No usar rollback
  automático seguido de un nuevo migrate.
- down() de edificios elimina asociaciones y plazos nuevos. Un rollback de
  aplicación requiere un plan separado que preserve esos datos.

Procedimiento manual posterior, en un entorno autorizado con la revisión aprobada,
PHP y dependencias correspondientes y las variables del destino correcto:

1. Preparar respaldo recuperable y validar primero en una copia aislada.
2. Ejecutar `php artisan migrate:status`. Confirmar que solo las dos migraciones
   anteriores están pendientes para esta entrega. Si faltan migraciones previas
   de main, resolverlas con un plan explícito antes de continuar.
3. Hacer las comprobaciones de solo lectura siguientes, mediante el responsable
   autorizado de la BD; no se ejecutaron durante esta integración.
4. Aplicar las dos migraciones en orden con los comandos de abajo.
5. Repetir migrate:status y comprobar esquema, IDs históricos, noticias sin
   asociación y aulas sin edificio/plazo. Probar lectura y escritura con los
   contratos nuevos antes de habilitar el disparador automático.

Consultas PostgreSQL de prevalidación:

```sql
SELECT LOWER(TRIM(name)) AS normalized_name, COUNT(*) AS total,
       ARRAY_AGG(id ORDER BY id) AS ids
FROM portal_event_categories
GROUP BY LOWER(TRIM(name))
HAVING COUNT(*) > 1;

SELECT to_regclass('public.portal_news_categories') AS news_categories,
       to_regclass('public.buildings') AS buildings;

SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema = 'public'
  AND ((table_name = 'portal_event_categories' AND column_name = 'revision')
    OR (table_name = 'portal_news' AND column_name = 'category_id')
    OR (table_name = 'rooms' AND column_name IN ('building_id', 'inactive_until')));

SELECT indexname, indexdef
FROM pg_indexes
WHERE schemaname = 'public'
  AND tablename IN ('portal_event_categories', 'portal_news_categories', 'rooms');
```

Usar el esquema efectivo si no es public. Duplicados requieren una decisión
explícita sobre sus IDs y relaciones; no eliminarlos automáticamente.

Comandos posteriores, no ejecutados, desde la raíz del backend:

```sh
php artisan migrate --force --path=database/migrations/2026_11_09_000001_manage_portal_categories.php
php artisan migrate --force --path=database/migrations/2026_11_20_000001_add_room_buildings_and_temporary_inactivity.php
php artisan migrate:status
```

Los paths limitan esta operación a las dos migraciones revisadas; no sustituyen
la comprobación de sus prerequisitos. `php artisan migrate --force` sin paths
aplicaría todas las pendientes, por lo que no debe asumirse que ejecutará solo estas.
No hay migraciones adicionales para HU34 en esta rama.

## 7 Scheduler y configuración pendiente

### Operaciones y consistencia

`bootstrap/app.php` ya carga `routes/console.php`. No hay comandos implementados
en archivos dentro de app/Console/Commands. La rama agrega un comando de closure,
`catalogs:refresh-statuses`, y una única programación:

```php
Schedule::command('catalogs:refresh-statuses')->everyMinute()->withoutOverlapping();
```

El comando llama a CatalogLifecycle::refresh con una Request sintética.
No requiere un usuario interactivo y registra las transiciones con user_id null.

| Operación | Criterio | Escritura |
| --- | --- | --- |
| Cerrar período | status ACTIVE y end_date anterior a hoy en America/La_Paz | academic_terms.status INACTIVE; auditoría ACADEMIC_TERM_EXPIRED |
| Reactivar aula | status INACTIVE e inactive_until menor o igual al instante actual UTC | rooms.status ACTIVE, inactive_until null; auditoría ROOM_AUTOMATICALLY_REACTIVATED |

La fecha final del período incluye todo su día local. Las aulas sin plazo
permanecen inactivas; las de plazo futuro no cambian. No se crean ingresos ni
exámenes ni se recalculan habilitaciones, notas o QR. Cambiar el estado del período
puede afectar indirectamente catálogos/ofertas que exigen un período activo, y
reactivar un aula la hace elegible para reservas posteriores. No cancela ni
reprograma reservas existentes.

Cada transición y su auditoría se guardan juntas. El cierre usa AcademicTermLock
(advisory PostgreSQL 240024,1) y bloqueo de filas. La reactivación bloquea las
aulas en orden de ID y vuelve a comprobar el estado. Repetir la tarea no vuelve a
auditar transiciones ya completadas. Los locks de BD también coordinan con
peticiones HTTP y reservas. La efectividad concurrente en PostgreSQL requiere
prueba posterior; SQLite no demuestra los advisory locks.

El cierre de períodos y la reactivación de aulas son transacciones separadas:
si falla la segunda, el cierre previo puede quedar confirmado. Un reintento
continúa sin duplicar ese cierre. Las excepciones se propagan y deshacen la
transacción afectada; no hay reintentos personalizados ni alertas configuradas.
La salida del comando programado va por defecto a /dev/null en Laravel:
no basta con esperar ver su texto informativo en el log del disparador.
Antes de activarlo debe definirse observación de fallos y ausencia de ejecuciones,
y comprobar logs de excepciones y auditoría.
En el vendor instalado, ScheduleRunCommand muestra el fallo de la tarea pero no
propaga necesariamente su código de salida al proceso schedule:run. Un job del
proveedor marcado como exitoso no demuestra que el comando hijo haya terminado
bien; comprobar el resultado de la tarea y las transiciones esperadas.

withoutOverlapping usa la caché configurada y tiene expiración predeterminada
de 1440 minutos en el vendor instalado. El Dockerfile usa CACHE_STORE=file:
ese mutex no coordina contenedores distintos ni sobrevive necesariamente a
reemplazos. Los locks transaccionales siguen protegiendo estas transiciones.
No existe migración de cache/cache_locks en este repositorio: cambiar a caché
database requeriría provisionar explícitamente su esquema; no hacerlo a ciegas.
Un mutex huérfano debe investigarse antes de liberar locks, sin borrar la caché
de un proceso aún activo. [Referencia Laravel](https://laravel.com/docs/11.x/scheduling#preventing-task-overlaps).

Además del scheduler, RefreshCatalogLifecycle se ejecuta en peticiones
autenticadas a admin/academic-terms y admin/rooms. El refresco está en el grupo,
antes de los permisos específicos de cada ruta: una petición que luego recibe
403 puede haber disparado transiciones automáticas de sistema. La consulta de
aulas disponibles y su reserva también reactivan aulas vencidas. Deshabilitar
el disparador periódico no deshabilita estos efectos HTTP del código integrado.

### Entorno comprobado en el repositorio

Dockerfile configura PHP 8.3 + Apache, WORKDIR /var/www/html y
CMD apache2-foreground. No instala cron, no inicia schedule:work y no llama a
schedule:run en build o arranque. bootstrap confía en proxies de Render.
docs/DEPLOY_RENDER.md documenta Laravel en Render, PostgreSQL en Neon y
Vercel como frontend/proxy. No hay vercel.json, runtime PHP serverless,
cron, render.yaml ni workflow de scheduler versionado en este backend.

Esto respalda la arquitectura Render + Vercel proxy. El estado actual de los
dashboards, plan contratado, dominios y configuración remota no fue consultado;
el repositorio por sí solo no certifica el host real desplegado hoy.
No se inspeccionaron ni modificaron repositorios vecinos.

### Procedimiento de habilitación posterior

Primero confirmar en el panel del despliegue dónde corre realmente Laravel y qué
revisión está desplegada. Aplicar las migraciones y validar las pruebas antes
de activar periodicidad. No se creó ni activó infraestructura durante esta tarea.

Si se confirma el despliegue Docker en Render documentado:

1. Usar un ejecutor programado aprobado que ejecute el mismo backend y revisión,
   con las mismas variables de conexión y configuración del destino.
2. Si se elige un Cron Job de Render, debe crearse como recurso separado con
   aprobación de su coste. Render admite repositorio/imagen y horario UTC.
   En Docker hay que sobrescribir el comando; el CMD Apache por defecto no sirve
   para un proceso que debe finalizar. [Configuración oficial](https://render.com/docs/cronjobs).
3. Definir horario `* * * * *` y comando Docker
   `php /var/www/html/artisan schedule:run --verbose --no-interaction`.
   Confirmar WORKDIR, dependencias y variables seguras, sin copiarlas al repositorio.
   Un ejecutor CLI existente autorizado puede usar ese mismo comando cada minuto;
   esta revisión no presume que exista uno.
4. Mantener un solo disparador. Si se necesitan múltiples ejecutores y un mutex
   global, decidir primero una caché compartida con locks y su aprovisionamiento.
5. En una BD aislada, usar `php artisan schedule:list` para revisar el horario;
   ejecutar manualmente `php artisan catalogs:refresh-statuses` dos veces y
   comprobar límites horarios, estados, auditoría y fallos. Estos comandos
   de ejecución sí escriben datos y necesitan el entorno de validación correcto.
6. Tras validar, habilitar el job, observar las primeras ejecuciones, revisar
   las acciones de auditoría y confirmar que no hay duplicados. Vigilar también
   los registros que debieron cambiar y no cambiaron. Para detener la periodicidad,
   deshabilitar el job en su proveedor; recordar el fallback HTTP.

Si Laravel está efectivamente en Vercel:

Vercel Cron invoca por GET una ruta HTTP del despliegue de producción; no ejecuta
Artisan ni mantiene un daemon CLI por incluir routes/console.php.
Este backend no tiene endpoint de scheduler ni configuración Vercel que lo conecte.
No existe una URL activable del scheduler con el código actual.
[Funcionamiento oficial](https://vercel.com/docs/cron-jobs).

Se necesitaría una implementación posterior revisada que exponga una ruta
específica protegida por secreto de servicio, compare la autorización antes de
cualquier escritura, ejecute la operación acotada, coordine concurrencia y
registre resultados/fallos. Una función del proyecto Vercel que reenvíe al backend
también necesitaría autenticación en ambos extremos. No usar una ruta pública
de catálogo ni sesiones de usuarios como disparador. CRON_SECRET se transmite
como Bearer y el receptor debe validarlo.
[Protección oficial](https://vercel.com/docs/cron-jobs/manage-cron-jobs#securing-cron-jobs).

El plan Hobby permite como máximo frecuencia diaria; Pro/Enterprise admiten
cada minuto. Confirmar el plan antes de elegir Vercel como disparador de esta tarea.
[Frecuencias admitidas](https://vercel.com/docs/cron-jobs/usage-and-pricing).
La elección de plataforma queda pendiente de confirmar el despliegue real;
no se inventaron dominios, endpoints existentes, servidores ni credenciales.

## 8 Verificaciones y pruebas antes de desplegar

Verificaciones realizadas:

- Sin entradas unmerged en git ls-files -u ni marcadores de conflictos.
- git diff --check y git diff --cached --check, sin errores al cierre.
- php -l en los 53 archivos PHP afectados, sin errores con PHP 8.2.33.
- 209 imports App revisados, todos con archivo existente.
- Comparación aislada de rutas: 356 combinaciones conservadas, 22 añadidas;
  acciones presentes y sin cambios inesperados en permisos/constraints.
- Dos migraciones nuevas, ninguna migración histórica alterada ni archivo eliminado.

No se ejecutaron PHPUnit, Artisan, migraciones, seeders, builds o despliegues.
La inspección aislada de rutas usó únicamente Container, Router, autoload y
declaraciones de rutas, sin bootstrap de Laravel, .env, middleware o BD.

Pruebas recomendadas, todas pendientes:

- PortalCategoryTest, PortalNewsTest, PortalCalendarTest y PortalHistoryTest:
  categorías, contratos sin categoría, visibilidad, revisiones y fallos de auditoría.
- DelegatedPortalEditingTest, CustomRolesBlockOneTest y DelegatedAcademicCatalogTest:
  permisos personalizados, aislamiento de edición y revocación en caliente.
- EligibilityAutomationTest: MERGE parcial, conservación de datos, conflicto de
  revisión, filtros globales y decisiones manuales protegidas. Comprobar previews
  antiguos y confirmación de todos los resultados aunque la vista esté filtrada.
- SaulBackendRegressionTest: límites de fechas/capacidad/piso, edificios,
  inactividad temporal, activación masiva, tarea CLI y rollback ante fallo de auditoría.
- Aulas/períodos/materias y programación de exámenes: importación, disponibilidad
  diaria, cruce de medianoche, fin exclusivo y aulas secundarias.
- En PostgreSQL de pruebas dedicado: migraciones con datos históricos/duplicados,
  concurrencia scheduler + HTTP + cambio manual + reserva, reintentos y auditoría.
  Respetar los requisitos de aislamiento de los scripts existentes.
- Smoke de entradas online, QR v3, paquetes HU35/HU36 y sincronización HU37
  desde web y Android, con autorización y revisión actuales.
- Validación del disparador real y sus logs en staging antes de producción.

Las pruebas nuevas preparan su propio esquema con RefreshDatabase cuando se
ejecuten; deben usar una BD aislada. No apuntarlas a producción.

## 9 Impacto en el frontend y EIDA Control

No se modificaron React ni Expo. Los nombres exactos de rutas de pantalla del
frontend no se verificaron porque esta tarea se limitó al repositorio backend.
Las áreas consumidoras afectadas y sus contratos son:

| Pantalla o área | Contratos afectados |
| --- | --- |
| Administración y lectura de Noticias | POST/PATCH /api/v1/admin/portal/news conserva creación sin categoría; categoría activa opcional para ADMIN. Listado/detalle público y administrativo agregan category; filtro category_id. Un editor delegado debe omitir category_id. |
| Administración de categorías del Portal | /api/v1/admin/portal/{event,news}-categories/manage con GET/POST, PATCH de nombre/estado e historial; selectores /api/v1/portal/news-categories y su variante administrativa. Gestión solo ADMIN. |
| Calendario | /api/v1/admin/portal/events conserva rutas; nuevas restricciones temporales y categoría revalidada. Edición descriptiva histórica conservada. |
| HU34 y datos académicos | /api/v1/exams/{exam}/eligibility-automation/evaluations y /api/v1/academic-offerings/{offering}/academic-records. Filtros, resumen y flags adicionales; import-schema informa CREATE_ONLY/MERGE. No confirmar únicamente las filas visibles: el backend aplica todo el snapshot. |
| Catálogos de Aulas | /api/v1/admin/rooms agrega date; building_id, inactive_until, availability_date y reservation_count en recursos. /buildings para selección/creación, /bulk-activate para activación masiva; /{room}/status acepta plazo ISO con zona. |
| Períodos y Materias | Rutas conservadas; formularios y CSV reciben nuevos errores 422 por fechas, duraciones y nombres duplicados. Los períodos pueden pasar automáticamente a INACTIVE al consultar. |
| Incidencias | /api/v1/admin/incidents/exams acepta filtros de período, materia, carrera, oferta y evaluation_type; agrega IDs y nombre del período. |
| EIDA Control y offline web | Sin cambio directo en rutas, QR v3 o payloads offline/móviles. Validar el flujo completo porque las reglas académicas y disponibilidad compartidas pueden afectar preparación de datos. |

Los clientes actuales pueden ignorar campos añadidos. No fijar IDs de categorías
o edificios; usar los selectores. La UI que quiera las funcionalidades nuevas
necesitará trabajo posterior en su propio repositorio. No se hizo ese trabajo
como parte de esta integración.

## 10 Estado final de Git

- Rama: main; HEAD continúa en 90f987816284a45517501b86eae8a98939270ee2.
- origin/main continúa en ese mismo SHA.
- ORIG_HEAD: 90f987816284a45517501b86eae8a98939270ee2.
- MERGE_HEAD: a7dfb1004ea812fbb286e2aa4440535282e86cf9.
- Merge en progreso, resuelto y pendiente del commit manual del usuario.
- 61 archivos preparados en el índice; sin cambios unstaged, untracked o conflictos.
- No hubo commit, push, reset, clean, rebase, stash ni cambio de rama.
- No se modificaron otros repositorios ni directorios -compare.

Para revisar el resultado:

```sh
git status
git diff --cached --stat
git diff --cached
git diff --cached --check
```

El commit final corresponde al usuario.
