# SEC 01 Correcciones de seguridad del backend

Fecha: 21 de noviembre de 2026, America/La_Paz.

Se corrigieron los hallazgos #5, #6, #7 y #8 en el código y se prepararon 26
pruebas focalizadas. En el seguimiento CORS se ejecutaron las nueve pruebas de
frontera HTTP y orígenes; las demás siguen pendientes. El #2 queda parcialmente mitigado: se retiró
la confianza en X-Forwarded-Host fuera de desarrollo local y se añadieron límites
de login por identificador independientes de la IP. La restricción efectiva del
ingreso Vercel requiere verificar su runtime y su frontera de confianza.
El #15 queda configurable, pero el TLS de la conexión real a Railway está pendiente.

El reporte PDF recibido tiene 15 páginas y detalla los números 2–25; no contiene
el hallazgo crítico #1. No se inventó ese hallazgo ni se ampliaron estas correcciones
a los demás números.

## Estado inicial y alcance

Repositorio: `C:/proyecto/oidevs-examenes-backend`.
Rama: `main`. HEAD y origin/main:
`272e955c90889fd0b9f9f018de68e35e1bec8af1`.

Git estaba limpio, sin archivos staged/untracked ni MERGE_HEAD. El commit anterior
ya había cerrado la integración de Dev_Hector; no se mezcló SEC-01 con un merge
pendiente. Solo se modificó el backend. No se abrieron directorios -compare.

La fuente de instrucciones es la solicitud SEC-01 del usuario. Las recomendaciones
del PDF se trataron como hipótesis de auditoría y se contrastaron con el código.

## Resultado por hallazgo

| ID | Evidencia actual y estado | Corrección implementada | Archivos afectados | Pruebas añadidas | Riesgos o decisiones pendientes |
| --- | --- | --- | --- | --- | --- |
| #2 | Confianza global en bootstrap y límites dependientes de IP confirmados. El bypass concreto del PDF no queda demostrado para el ingreso real de Vercel. Mitigación parcial. | TrustDeploymentProxies reemplaza el middleware estándar; ignora X-Forwarded-Host fuera de local, conserva Proto/Port/Prefix/For y permite configurar IPs/CIDRs explícitos. Login web/móvil añaden bucket por identificador, independiente de IP. | bootstrap/app.php; app/Http/Middleware/TrustDeploymentProxies.php; config/http_security.php; app/Providers/FortifyServiceProvider.php; .env.example | HttpBoundarySecurityTest: cabeceras desde peer no confiable, HTTPS/prefijo/fotos desde proxy configurado, lista vacía y límites ante IP variable. | Sin IP/CIDR ni runtime versionado verificables, se conserva el fallback de transporte anterior para no romper HTTPS. No declarar cerrada la confianza global. Comprobar almacenamiento compartido del rate limiter en Vercel. |
| #5 | localhost/127.0.0.1 estaban permitidos incondicionalmente en CORS y Sanctum tenía defaults locales en cualquier entorno. Confirmado y corregido en código. | Orígenes exactos desde FRONTEND_URL y CORS_ALLOWED_ORIGINS; defaults loopback solo APP_ENV=local; sin null/wildcards. Dominios Sanctum explícitos o derivados de esos orígenes. HandleExplicitCors omite cabeceras CORS ante orígenes rechazados e incluye Vary: Origin. Se conservan credenciales, preflight y CSRF. | config/cors.php; config/sanctum.php; app/Support/BrowserOrigins.php; app/Http/Middleware/HandleExplicitCors.php; bootstrap/app.php; .env.example | BrowserOriginsTest y preflight/GET después de peticiones válidas en HttpBoundarySecurityTest. | Configurar el origen real y revisar SANCTUM_STATEFUL_DOMAINS de producción; valores locales explícitos no se eliminan silenciosamente si el operador los configura. |
| #6 | catch(Exception) devolvía getMessage() con 403 para cualquier fallo de emisión. El catch seguro de QrV3Exception ya existía. Confirmado y corregido el catch general. | Excepción de dominio con códigos/mensajes fijos para denegaciones; Throwable inesperado devuelve 500 QR_GENERATION_FAILED y log mínimo sin mensaje, SQL, stack, tokens o claves. Se conserva QR_V3_UNAVAILABLE/503. | app/Services/Students/StudentQrAccessException.php; app/Services/Students/StudentQrService.php; app/Http/Controllers/Api/V1/Students/StudentQrController.php | StudentQrErrorSecurityTest: denegaciones, RuntimeException/Error, fallo del logger, 503 existente, respuesta v3 y versión inválida. | Ejecutar pruebas de emisión real y pantalla Ver mi QR; los fallos internos dejan de parecer denegaciones 403. |
| #7 | GET rooms omitía password.changed, leía course_offering_id sin validar/autorizar y exponía identificación de exámenes institucionales. Confirmado y corregido. | Se añade password.changed; oferta positiva validada y autorización ADMIN o DOCENTE propietario mediante CourseOfferingAccess. Disponibilidad básica para los demás; identidad de reservas ajenas redactada para docentes. | routes/api/v1.php; app/Http/Requests/Api/V1/Rooms/AvailableRoomsRequest.php; app/Http/Controllers/Api/V1/Rooms/RoomController.php; app/Services/Rooms/RoomCatalogContextService.php | RoomSelectionSecurityTest: invitados, primera contraseña, cuenta inactiva, estudiante/custom role, oferta propia/ajena, entradas inválidas, revocación de rol, administrador y exclusión de examen. | Reservas ajenas del selector docente usan exam_id=null y exam_name=Reservado; los clientes deben tolerarlo. Las fechas/horas de ocupación se conservan para planificar. |
| #8 | Paquete móvil con protocolo omitido/1 eludía binding y evidencia v2. El middleware ya rechazaba tokens de dispositivo revocado, pero otra sesión del mismo usuario podía evitar esa ruta. Confirmado y corregido. | El snapshot móvil exige protocolo 2 antes de registrar/reutilizar UUID. Binding/dispositivo vigente se comprueba también antes de devolver recibos existentes. Se conserva protocolo 1 para paquetes web legítimos. | app/Services/Offline/OfflineSyncService.php | MobileOfflineSyncSecurityTest: downgrade, replay desde otra instalación/cookie, revocación, conflicto inmutable, QR v3 válido/inválido, UUID repetido, duplicado y web v1. | Los eventos móviles históricos v1 no se transforman. Un cambio de payload/protocolo con UUID histórico puede seguir generando EVENT_ID_REUSED y requiere revisión. |
| #15 | config/database.php fijaba sslmode=prefer; DB_URL ya podía sobrescribirlo con sus parámetros. Confirmado, configuración corregida y activación pendiente. | DB_SSLMODE configurable manteniendo prefer como fallback compatible; DB_SSLROOTCERT permite CA para verify-full. | config/database.php; .env.example | Sin prueba de conexión ni prueba de integración TLS ejecutada. Verificación manual indicada abajo. | En la conexión pública Vercel→Railway se debe configurar require o verify-full y revisar que DB_URL no lo rebaje. No se certifica TLS real ni se cambió la infraestructura. |

## Proxies y Vercel

El usuario confirmó que Laravel corre en Vercel, Railway alojará solo PostgreSQL y
no hay CIDRs de proxies verificados ni un proxy externo confirmado. Esa aclaración
prevalece sobre las guías antiguas del repositorio.

El árbol versionado contiene Dockerfile PHP/Apache y docker/apache.conf, destinados
a un servidor que escucha PORT. No contiene vercel.json, runtime PHP de Vercel,
función de entrada ni manifiesto de despliegue que permita comprobar cómo se
traducen las variables de servidor hasta Laravel. No se modificaron Docker/Apache
ni se asumió que estén ejecutándose en Vercel.

La documentación oficial de Vercel indica que X-Forwarded-Host es igual a Host,
que X-Forwarded-Proto informa el protocolo y que su ingreso sustituye X-Forwarded-For.
Esto permite retirar la confianza redundante en Forwarded-Host para ese flujo
directo. No prueba cómo el runtime PHP instalado actualmente construye REMOTE_ADDR
o si existen caminos que evitan el ingreso. No se usa la presencia de una cabecera
X-Vercel-* como autenticación de un proxy.
[Cabeceras oficiales de Vercel](https://vercel.com/docs/headers/request-headers).

TrustDeploymentProxies resuelve configuración en cada petición, después de cargar
el entorno. Fuera de APP_ENV=local confía solamente en For, Proto, Port y Prefix
desde los proxies seleccionados. En local conserva Host para el proxy Vite
documentado que cambia el Host de transporte. No se tocan las URLs de recuperación,
FRONTEND_URL, cookies ni rutas de fotografías.

TRUSTED_PROXIES permite una lista explícita y rechaza entradas inválidas,
REMOTE_ADDR literal, comodines internos y redes /0. Un valor vacío no confía
en ningún proxy. Cuando no está definida, conserva como fallback el comportamiento
anterior de confiar en el peer inmediato; **ese fallback sigue sin verificar y
no constituye el cierre del hallazgo #2**. No activarlo ni reemplazarlo por rangos
inventados. La ausencia de datos de infraestructura impide estrecharlo ahora sin
arriesgar HTTPS, cookies y prefijos. Laravel documenta la selección explícita de
proxies y encabezados.
[Laravel 11 sobre proxies](https://laravel.com/docs/11.x/requests#configuring-trusted-proxies).

Además, la afirmación del PDF de que * equivale necesariamente a confiar en todas
las IP del encabezado no coincide literalmente con el vendor instalado: Laravel
traduce * al peer inmediato y Symfony selecciona direcciones según la cadena de
confianza. El resultado explotable depende de esa cadena y de los encabezados
sustituidos por el ingreso. Se mantuvo esa distinción en el diagnóstico.

Para cerrar esta parte posteriormente:

1. Revisar la configuración real de Vercel y su runtime PHP con acceso de lectura.
   Verificar qué produce REMOTE_ADDR, Host, Proto y Prefix y qué rutas llegan a PHP.
2. Confirmar aislamiento del origen y qué encabezados sustituye el ingreso antes
   de confiar en un modo de plataforma sin IPs fijas.
3. Si existen IPs/CIDRs verificables, configurar TRUSTED_PROXIES y validar HTTPS,
   cookies y fotos tanto en rutas directas como con /backend.
4. Si no existen rangos estables, definir una frontera de confianza verificable
   específica del runtime; no inventarla basándose en cabeceras declaradas.
5. Ejecutar las pruebas de encabezados y un smoke autorizado en staging.

Los limitadores web y móvil mantienen el máximo de 5 intentos por minuto por
combinación de identificador/IP y añaden 5 por identificador normalizado aunque
cambie la IP, más 30 por IP. Web conserva identifier y el alias email.
Los buckets se separan entre web y móvil. No se promete unificar SIS/correo/código
como una misma identidad, porque hacerlo requeriría resolver cuentas antes de
autenticar. Las claves usan hash del identificador.

Las cuotas conservan el backend de caché existente. La imagen Docker usa file y
config/cache.php admite otros stores; el store real de Vercel no está verificado.
En varias instancias, file efímero no demuestra una cuota distribuida. Confirmar
un store compartido compatible antes de declarar esa garantía; no se provisionó
ninguno ni se modificó infraestructura. El límite por identificador también
permite que intentos dirigidos agoten temporalmente la cuota de ese identificador;
se conserva la respuesta 429/Retry-After y debe revisarse la experiencia de soporte.

## CORS y sesiones

FRONTEND_URL sigue autorizado como origen exacto. CORS_ALLOWED_ORIGINS agrega
orígenes explícitos separados por coma; se descartan vacíos, literal null,
comodines, credenciales, rutas, fragmentos y query strings. Los orígenes de
desarrollo predeterminados solo se agregan en local.

SANCTUM_STATEFUL_DOMAINS explícito conserva el formato host:puerto y es filtrado.
Si falta, se deriva de los orígenes autorizados. Si ambas fuentes faltan, las
listas quedan vacías, sin permitir Origin:null. No se modificaron validadores
CSRF, cookies, credenciales CORS ni el flujo de primer cambio de contraseña.
allowed_methods y allowed_headers se conservan para no retirar cabeceras
legítimas de los consumidores existentes.

CORS no sustituye autenticación ni autorización: la corrección de /rooms es
independiente. Un navegador puede recibir una respuesta de red sin permiso CORS
para leerla; las pruebas verifican ausencia de Access-Control-Allow-Origin para
los orígenes rechazados.

### Seguimiento de la prueba CORS fallida

Se reprodujo el fallo original de HttpBoundarySecurityTest. La primera petición
rechazada tenía estos valores comprobados:

| Campo | Valor |
| --- | --- |
| Origin enviado y recibido por Laravel | http://localhost:5173 |
| allowed_origins efectivo | [https://portal.example.test] |
| supports_credentials | true |
| Access-Control-Allow-Origin de la respuesta original | https://portal.example.test |
| Access-Control-Allow-Credentials original | true |
| isOriginAllowed() para localhost | false |

La causa está en configureAllowedOrigin() de Fruitcake CorsService: con un solo
origen configurado, emite ese valor fijo sin compararlo con Origin. Laravel 11
delega allí las respuestas de HandleCors. Se reprodujo tanto con una instancia
nueva como con una reutilizada: no era persistencia de configuración ni
contaminación de APP_ENV, FRONTEND_URL o CORS_ALLOWED_ORIGINS. La prueba fija
su lista explícita y ahora verifica también el Origin realmente recibido.
[Implementación de CorsService](https://github.com/fruitcake/php-cors/blob/master/src/CorsService.php).

No se confirmó una fuga real de autorización al navegador: el valor devuelto era
el portal permitido, no localhost, y no coincide con el origen solicitante. El
navegador rechaza esa discrepancia. La prueba exigía una política de cabeceras
más estricta que esta optimización válida de la librería.
[Semántica de Access-Control-Allow-Origin](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Allow-Origin).

Para conservar esa política y todas las aserciones de seguridad, se incorporó
HandleExplicitCors como reemplazo de HandleCors en bootstrap/app.php, sin tocar
vendor. Reutiliza el preflight y manejo de credenciales de Laravel y retira los
encabezados Access-Control-* de respuestas a orígenes no permitidos o ausentes.
Las respuestas de las rutas CORS incluyen Vary: Origin, tanto válidas como
rechazadas, para que una caché no mezcle ambas variantes. Fuera de las rutas CORS
configuradas no altera la respuesta.

La nueva regresión mantiene expresamente la misma aplicación y CorsService y
alterna portal válido, atacante, portal válido, localhost, portal válido y null,
para OPTIONS y GET. Comprueba ausencia de autorización y credenciales tras
peticiones válidas. No se eliminó ni comentó ninguna aserción existente.

El comportamiento de cabecera fija también se da en producción cuando hay un
solo origen; no depende del runner de pruebas. No autorizaba a orígenes ajenos,
pero el ajuste elimina esa cabecera engañosa. No cambia la lista de orígenes,
cookies, sesiones, CSRF, rutas de login, fotografías o frontend autorizado.

## QR y contratos de la web

La emisión conserva versiones 2/3, ventana de 24 horas previas al examen hasta
su fin exclusivo, registro/revocación del token y payload exitoso. Los rechazos
funcionales conservan HTTP 403 y sus mensajes conocidos, agregando códigos seguros:

| Código | Situación |
| --- | --- |
| EXAM_UNAVAILABLE | Examen inexistente, no visible o no accesible al estudiante |
| STUDENT_NOT_ELIGIBLE | No hay habilitación válida |
| QR_NOT_YET_AVAILABLE | Todavía no comienza la ventana previa |
| EXAM_FINISHED | El examen terminó |
| QR_GENERATION_FAILED | Fallo interno inesperado, HTTP 500 genérico |
| QR_V3_UNAVAILABLE | Configuración/emisión v3 no disponible, HTTP 503 ya existente |

La versión inválida conserva HTTP 422 y el perfil faltante conserva
STUDENT_PROFILE_MISSING/403. Los errores de emisión y denegaciones se marcan
private, no-store. El log interno nuevo contiene solo nombre de clase y exam_id;
no registra getMessage(), exception, trace, request, claves o QR. Un fallo del
logger no expone la excepción original.

## Aulas y autorización

GET /api/v1/rooms conserva autenticación, sesión actual y disponibilidad de aulas;
ahora exige también haber cambiado la contraseña inicial.

- ADMIN activo puede consultar metadatos de ofertas y reservas.
- DOCENTE vigente puede consultar metadatos de su oferta mediante el servicio
  existente. Oferta ajena/no autorizada: 403. ID malformado: 422.
- Para docentes, horarios propios conservan ID/nombre; los ajenos conservan
  ocupación pero usan exam_id=null y exam_name=Reservado.
- Estudiantes y otros roles autenticados conservan aulas filtradas por
  disponibilidad con busy_slots=[], schedules_list=[] y meta=null.
  No obtienen métricas por añadir course_offering_id.
- rooms.view conserva su alcance administrativo; no concede acceso universal a
  ofertas ni se exige a docentes para el selector.
- exclude_exam_id conserva el control de propiedad previo. La autorización de
  oferta se comprueba antes de consultar disponibilidad y sus efectos de lifecycle.

Se conservan formato del catálogo, capacidades, reservas de 90 minutos, aulas
secundarias y validación transaccional al reservar. Se cambió la firma interna
del servicio de contexto para recibir el actor; se revisaron sus referencias.

## Sincronización y EIDA Control

El tipo del snapshot persistido determina si el paquete es móvil, no una bandera
enviada por el cliente. Omitir protocol_version o enviar 1 en un paquete móvil
devuelve 422 MOBILE_SYNC_PROTOCOL_REQUIRED antes de insertar eventos; no consume
el UUID y permite que el transporte legítimo reintente usando su contrato actual.

En protocolo 2 se exige token vinculado a dispositivo, usuario del manifiesto,
instalación, device_id original y dispositivo no revocado, con bloqueo de la fila.
La comprobación también se ejecuta antes de devolver un recibo ya registrado.
Otra instalación o una cookie del mismo usuario no obtienen el ACCEPTED histórico:
reciben CONFLICT DEVICE_BINDING_MISMATCH sin sobrescribir recibo ni evidencia.

Se mantienen:

- Verificación de evidencia QR v3 y tokens registrados.
- Examen, distribución, lista, estudiante, habitación, habilitación y ventana.
- Hash de identidad del evento, UUID originales y resultados inmutables.
- Reintento válido del mismo UUID sin duplicar ingreso.
- Conflictos/rechazos existentes: no se convierten en ACCEPTED.
- Protocolo 1 para paquetes web no móviles y su autorización propia.

No se borró cola pendiente, registro, recibo ni evidencia histórica. No hubo
migraciones ni backfill. Los históricos móviles v1 requieren revisión operativa
si un cambio de protocolo con el mismo UUID genera EVENT_ID_REUSED; no deben
reescribirse ni aceptarse para sortear ese resultado.

No se modificó la APK. El flujo móvil actual con protocolo 2 conserva el contrato;
un cliente que intentaba enviar paquetes móviles por protocolo 1 deja de funcionar
por esa vía insegura. Si hay uno desplegado, debe corregirse su transporte y
validarse preservando sus pendientes; esta tarea no lo transforma automáticamente.

## PostgreSQL público en Railway

Esta es una corrección separada del transporte HTTP. DB_SSLMODE ahora puede exigir
TLS con DB_HOST/DB_PORT, y DB_SSLROOTCERT puede proporcionar una CA para verify-full.
Se conserva prefer como fallback compatible; **no implica que la conexión de
producción esté cifrada**.

Laravel combina la configuración con los parámetros de DB_URL; su query string
prevalece. Definir DB_SSLMODE=require no basta si DB_URL contiene sslmode=prefer
o disable. Este backend lee DB_URL, no DATABASE_URL/DATABASE_PUBLIC_URL
automáticamente. Usar la URL pública correcta suministrada por Railway desde
Vercel; no sustituirla por un host privado de Railway.

La documentación de Railway describe su imagen PostgreSQL con SSL y la conexión
pública por TCP Proxy. Eso no acredita la imagen/configuración del servicio al
que se está migrando.
[PostgreSQL en Railway](https://docs.railway.com/databases/postgresql).

En el entorno autorizado se debe configurar require para impedir fallback a
texto plano; verify-full verifica además CA e identidad del servidor y requiere
certificado/hostname compatibles. No inventar una ruta de CA ni desactivar su
validación para resolver un error.
[Modos TLS de PostgreSQL](https://www.postgresql.org/docs/current/libpq-ssl.html).

Validación posterior en staging, no realizada:

1. Verificar que el servicio PostgreSQL real admite TLS en su endpoint público.
2. Configurar DB_SSLMODE=require, o verify-full más DB_SSLROOTCERT verificado.
   Revisar/coincidir sslmode y sslrootcert de DB_URL.
3. Renovar la configuración cacheada únicamente mediante el proceso de despliegue
   autorizado y comprobar la conexión desde el runtime real.
4. En esa conexión de validación, consultar:

```sql
SELECT ssl, version, cipher
FROM pg_stat_ssl
WHERE pid = pg_backend_pid();
```

El resultado debe indicar ssl=true. No se ejecutó esta consulta ni se intentó
conectar a Railway durante SEC-01. No se modificaron credenciales.

## Variables y configuración posterior

| Variable | Acción necesaria |
| --- | --- |
| APP_ENV | production en Vercel. local conserva compatibilidad Vite. No usar local como solución de proxy en producción. |
| APP_DEBUG | false en producción; se conserva el manejo genérico de QR incluso si un logger falla. |
| FRONTEND_URL | Mantener el origen real ya autorizado; también se usa en recuperación de contraseña. No se inventó ni cambió su dominio. |
| CORS_ALLOWED_ORIGINS | Opcional: orígenes extra exactos separados por coma. Vacío no autoriza sitios adicionales. |
| SANCTUM_STATEFUL_DOMAINS | Hosts reales y puertos del frontend, sin esquema/rutas/wildcards; si falta, se deriva del catálogo de orígenes. Revisar que no contenga localhost de una plantilla local en producción. |
| TRUSTED_PROXIES | Pendiente de verificar el ingreso. No definido conserva fallback previo; vacío no confía en nadie; lista requiere IP/CIDR comprobados. No copiar IPs ficticias de las pruebas. |
| CACHE_STORE y variables del store | Confirmar almacenamiento compartido para cuotas de login entre instancias. No se cambió el store ni se creó infraestructura. |
| DB_SSLMODE | require o verify-full para Vercel→PostgreSQL público; prefer queda para compatibilidad local. |
| DB_SSLROOTCERT | Solo para CA real suministrada/verificada; ruta disponible en el runtime. |
| DB_URL | Conservar secreto y endpoint autorizado, pero revisar sslmode porque sus parámetros prevalecen. |

Las fotografías siguen en PostgreSQL y se sirven por las mismas rutas protegidas.
Se conservan Proto/Port/Prefix para las URLs proxificadas y la generación de enlaces
de recuperación sigue usando FRONTEND_URL. Validar estos flujos en el runtime real antes de desplegar.

## Pruebas preparadas y ejecución manual

Se añadieron cinco archivos con 26 métodos de prueba:

| Archivo | Métodos | Cobertura |
| --- | --- | --- |
| tests/Unit/Support/BrowserOriginsTest.php | 3 | Orígenes prod/local, ausencia de null y dominios Sanctum |
| tests/Feature/Security/HttpBoundarySecurityTest.php | 6 | Proxy explícito, host manipulado, HTTPS/fotos/prefijo, preflight/GET sin herencia de autorización y limitadores web/móvil |
| tests/Feature/Security/StudentQrErrorSecurityTest.php | 5 | Dominio frente a fallos internos, logging seguro, contratos v3/503/422 |
| tests/Feature/Rooms/RoomSelectionSecurityTest.php | 6 | Autorización por oferta, mínima disponibilidad, roles y exclusión |
| tests/Feature/Offline/MobileOfflineSyncSecurityTest.php | 6 | Downgrade, dispositivo, revocación, evidencia, idempotencia y web v1 |

En el seguimiento CORS se ejecutaron HttpBoundarySecurityTest (6 tests, 140
aserciones) y BrowserOriginsTest (3 tests, 8 aserciones), todos correctos. Las demás
pruebas siguen pendientes. Las pruebas HTTP de frontera y QR usan rutas/servicios
aislados y no necesitan BD; las de aulas/offline exigen SQLite en memoria antes
de crear su esquema. La ejecución posterior de esas pruebas sí realizará
migraciones de prueba dentro de la BD aislada.

Desde la raíz del backend, estos comandos son para ejecución manual posterior:

```powershell
php vendor/bin/phpunit -c phpunit.offline.xml tests/Unit/Support/BrowserOriginsTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/HttpBoundarySecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/StudentQrErrorSecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Rooms/RoomSelectionSecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Offline/MobileOfflineSyncSecurityTest.php
```

Se usa esa configuración por sus overrides forzados DB_URL vacío, SQLite :memory:,
sesión/caché array y entorno testing. No ejecutar sobre BD persistente ni con
configuración cacheada de producción.

Regresiones focalizadas existentes que conviene ejecutar después, por archivo:
ImageProxyUrlsTest, StudentQrStatusTest, ExamSchedulingRulesTest,
RoomAvailabilityTest, OfflineSyncEvidenceTest, MobileAuthenticationTest y
LegacyLoginCompatibilityTest. Probar manualmente en staging login web,
recuperación, Ver mi QR, fotos, selección docente y sincronización Android con
pendientes reales de prueba. Esto no requiere modificar frontend o APK en SEC-01.

## Verificación estática y estado final

Se comprobó sintaxis mediante php -l en los 22 archivos PHP modificados/nuevos,
con PHP 8.2.33, sin errores. git diff --check quedó limpio y los 124 imports App
de los archivos afectados tienen un archivo existente. Se revisaron referencias
a las firmas del contexto de aulas y se preservaron las rutas existentes;
la única ampliación de middleware de ruta es password.changed en GET rooms.

Se ejecutaron únicamente las pruebas focalizadas indicadas, sin suite completa.
No se ejecutaron Artisan, migraciones, seeders, builds, conexiones a producción,
despliegues ni cambios de infraestructura. No hubo commit, push,
merge, rebase, reset, clean o stash durante esta tarea.

HEAD sigue en 272e955c90889fd0b9f9f018de68e35e1bec8af1, rama main, sin
MERGE_HEAD ni conflictos. Los cambios de SEC-01 quedan sin commit y sin stage:
13 archivos versionados modificados y 11 archivos nuevos, incluido este informe.
No se modificaron otros repositorios.
