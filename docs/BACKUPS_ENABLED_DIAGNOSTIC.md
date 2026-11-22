# Diagnóstico seguro de summary.enabled

Inspección actual del commit local c83a6fde47951489122d9320449639b2c7ca88cf.
No se inspeccionaron valores secretos ni el entorno efectivo de Vercel. Por ello
no se atribuye el fallo de producción a una condición concreta sin su diagnóstico.
El usuario confirmó EIDA_BACKUPS_GITHUB_REF=backup-execution en Production; falta
confirmar que ese valor llegue a config() en el deployment que sirve la API.

## Ubicación y expresión exacta

- app/Http/Controllers/Api/V1/Admin/BackupController.php:30 asigna el campo
  summary.enabled llamando a $this->service->enabled().
- app/Services/Backups/BackupService.php:24–27 implementa esa decisión:

```php
return config('backups.enabled') && strlen(config('backups.callback_secret')) >= 32
    && $this->executor->ready() && $this->storage->ready();
```

- GithubBackupExecutor.php:10–16 aplica cuatro comprobaciones locales.
- DriveBackupStorage.php:11–13 delega exclusivamente en DriveClient::ready().
- DriveClient.php:16–21 aplica otras cuatro comprobaciones locales.
- BackupServiceProvider.php:15–16 registra ambos adaptadores; bootstrap/providers.php:8
  registra el provider. BackupResource solo serializa filas; no calcula el summary.

La conjunción cortocircuita: basta un false. La disponibilidad de una cuenta activa,
permisos Google/GitHub, carpeta privada real o D1 no se consulta en estos métodos.

| Condición | Variable relacionada | Resultado esperado | Causa posible |
| --- | --- | --- | --- |
| Módulo habilitado | EIDA_BACKUPS_ENABLED | config('backups.enabled') verdadero; configurar true | Ausente, false, 0, vacío o caché anterior; default false |
| HMAC mínimo | EIDA_BACKUPS_CALLBACK_SECRET | strlen >=32 bytes | Ausente, vacío o secreto corto; no se decodifica base64 |
| Ejecutor seleccionado | EIDA_BACKUPS_EXECUTOR | github exacto, minúscula | Otro nombre, mayúsculas/espacios; default github |
| Repositorio | EIDA_BACKUPS_GITHUB_REPOSITORY | OWNER/REPO; regex ASCII [A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+ | URL completa, espacio, barra final, nombre sin propietario; default vacío |
| Token GitHub | EIDA_BACKUPS_GITHUB_TOKEN | Valor distinto de cadena vacía | Ausente o vacío; no hay comprobación de longitud, scopes o vigencia |
| Rama de ejecución | EIDA_BACKUPS_GITHUB_REF | backup-execution exacto | main, refs/heads/backup-execution, mayúsculas/espacios o caché antigua; default backup-execution |
| Cliente OAuth | EIDA_BACKUPS_DRIVE_CLIENT_ID | Valor distinto de cadena vacía | Ausente, vacío, nombre erróneo o configurado solo fuera de Laravel |
| Secreto OAuth | EIDA_BACKUPS_DRIVE_CLIENT_SECRET | Valor distinto de cadena vacía | Ausente, vacío, nombre erróneo o configurado solo fuera de Laravel |
| Refresh token | EIDA_BACKUPS_DRIVE_REFRESH_TOKEN | Valor distinto de cadena vacía | Ausente, vacío, nombre erróneo o configurado solo fuera de Laravel |
| Carpeta | EIDA_BACKUPS_DRIVE_FOLDER_ID | Solo ID; 1–200 caracteres ASCII A–Z/a–z/0–9/_/- | URL Drive completa, query, espacios, vacío o longitud excesiva |

Las comprobaciones !== '' son literales, sin trim ni validación de credenciales;
espacios o un token inválido pero no vacío pueden pasar ready() y fallar después.
No confundir configuración habilitada con conectividad verificada. null o tipos
inesperados pueden producir error según la expresión; no convertirlos en éxito.

## Condiciones que no causan este false en el código actual

- APP_ENV=production no está prohibido por enabled() ni los ready() involucrados.
- Cloudflare, D1 y las variables EIDA_BACKUPS_DOWNLOADS_ENABLED,
  EIDA_BACKUPS_DOWNLOAD_WORKER_URL, EIDA_BACKUPS_DOWNLOAD_SIGNING_SECRET,
  EIDA_BACKUPS_TICKET_ISSUER y EIDA_BACKUPS_TICKET_KEY_ID se validan al emitir un
  ticket, no al calcular summary.enabled. BackupDownloadTickets::ready() no se llama aquí.
- Ni PostgreSQL/pg_dump, ni GitHub Actions Secrets, ni Workers Secrets se leen para
  esta decisión. Los secretos configurados allí no rellenan variables de Laravel.
- No se requiere AWS SDK/Flysystem S3 o Google SDK. Drive usa HTTP de Laravel.
  Una clase/provider ausente normalmente impide resolver el servicio (error HTTP),
  no explica un false normal del código actual. Un deployment viejo sí puede
  contener validaciones de otra versión: confirmar Source/commit de Vercel.
- La rama que despliega Laravel en Vercel puede ser main y la que ejecuta pg_dump
  en GitHub ser backup-execution. Son configuraciones distintas.

## Comando de diagnóstico preparado

```bash
php artisan backups:diagnose
```

El comando y el endpoint administrativo descrito abajo comparten
app/Services/Backups/BackupReadinessDiagnostic.php. El comando conserva su formato
de salida anterior. Lee configuración, bindings y
ready(); no llama al controlador index (que concilia), ni consulta DB/Google/GitHub,
genera backups, limpia cachés, modifica configuración o emite tickets. No se
ejecutó durante esta inspección: solo se revisó su sintaxis.

Salida: nombres fijos de condición/variable y booleans/null; jamás secretos,
longitudes exactas, valores, hashes de secretos o mensajes de excepción.

- failed_conditions indica cada comprobación falsa.
- conditions_with_evaluation_error indica errores de tipos/configuración sin traza.
- summary_enabled ejecuta el mismo método de dominio; no lo fuerza ni sustituye.
- configuration_matches_env=false indica diferencia entre config() y el helper
  Env actual con su default. Con configuración cacheada y variables realmente
  presentes en el proceso, es evidencia de que la caché tiene otros valores.
  Sin caché, Env también puede reflejar .env; no identifica por sí solo el origen.
- expected_bindings comprueba adaptadores y provider efectivos.
- configuration_cached y flags de producción ayudan a distinguir entornos.
- El exit code 0 indica diagnóstico completado, no servicio operativo.

**Alcance:** ejecutarlo localmente solo diagnostica el proceso local. No demuestra
qué ve una función de Vercel. Vercel no ofrece una sesión SSH/Artisan en la función
por defecto: no se inventa ese acceso. Si el pipeline PHP permite comandos CLI,
el operador puede ejecutarlo en el build exacto de destino y revisar solo su JSON;
eso diagnostica el build, no garantiza igualdad con un runtime personalizado.
Para comprobar el runtime se preparó el endpoint administrativo temporal descrito
a continuación. No se desplegó ni se habilitó en Vercel durante este trabajo.

## Endpoint administrativo temporal

GET /api/v1/admin/backups/diagnostics/readiness

La operación real se llama BackupService::enabled(), no BackupService::ready().
overall_ready usa ese método y github_executor_ready / drive_storage_ready usan
los mismos ready() del módulo. No se modificó ninguna validación de dominio.

La ruta está dentro del mismo grupo de backups: auth:sanctum, session.current,
password.changed, EnsureActiveAdministrator, EnsureBackupAdministrator y el
throttle de lectura existente. Exige cuenta activa, contraseña actualizada, sesión
web vigente y rol activo base ADMINISTRADOR (is_custom=false). No acepta permisos
delegados ni roles personalizados como sustituto. Los middleware conservan sus
respuestas de autenticación/autorización.

Está deshabilitada por defecto. Para investigar, el operador debe configurar
EIDA_BACKUPS_DIAGNOSTICS_ENABLED=true en el backend y entorno de Vercel correctos
y publicar el código/configuración mediante su procedimiento autorizado.
La variable no habilita backups ni evita sus validaciones. No depende de
EIDA_BACKUPS_ENABLED: debe poder diagnosticar precisamente ese false.

Respuesta autorizada, incluso cuando overall_ready es false: HTTP 200 y
Cache-Control: private, no-store. Ejemplo:

~~~json
{
  "module_enabled": true,
  "callback_secret_valid": true,
  "github_executor_ready": false,
  "drive_storage_ready": true,
  "overall_ready": false,
  "failed_conditions": ["github_token_not_empty", "github_executor_ready", "overall_ready"]
}
~~~

Solo se devuelven estos cinco booleanos y una lista de códigos fijos; no hay
valores, nombres de variables, IDs, longitudes, mensajes de excepción o trazas.
Los datos ampliados del comando CLI no se serializan en HTTP.

| Código de condición | Significado |
| --- | --- |
| module_enabled | La configuración del módulo no es verdadera |
| callback_secret_minimum | El secreto HMAC no alcanza 32 bytes |
| github_executor_selected | El ejecutor no es github |
| github_repository_format | El repositorio no cumple OWNER/REPO |
| github_token_not_empty | El token es una cadena vacía |
| github_execution_ref | La referencia no es backup-execution |
| drive_client_id_not_empty | Client ID es una cadena vacía |
| drive_client_secret_not_empty | Client Secret es una cadena vacía |
| drive_refresh_token_not_empty | Refresh Token es una cadena vacía |
| drive_folder_id_format | El ID de carpeta no cumple el formato |
| github_executor_ready | El ready() real del ejecutor es falso o no pudo evaluarse |
| drive_storage_ready | El ready() real del almacenamiento es falso o no pudo evaluarse |
| overall_ready | enabled() es falso o no pudo evaluarse |

Un código base terminado en _evaluation_error indica que esa expresión no pudo
evaluarse; se ocultan los detalles de la excepción. Un indicador no evaluable se
representa como false. Se inspeccionan todas las condiciones aunque enabled()
cortocircuite en la primera. El diagnóstico no hace reconcile, dispatch, llamadas
a Google/GitHub/Cloudflare, generación de dumps o tickets. Los controles de acceso
siguen consultando usuario/roles/sesión con el mecanismo habitual de Laravel.

### Prueba desde Chrome

1. Iniciar sesión en EIDA como Administrador base activo y completar el cambio de
   contraseña si está pendiente. Mantener la pestaña autenticada abierta.
2. En DevTools → Network identificar el origen real del backend usado por EIDA.
   Abrir Console en esa misma pestaña del frontend y ejecutar, sustituyendo solo
   el dominio del ejemplo:

   ~~~javascript
   const readinessResponse = await fetch(
     'https://DOMINIO-REAL-DEL-BACKEND/api/v1/admin/backups/diagnostics/readiness',
     { method: 'GET', credentials: 'include', cache: 'no-store',
       headers: { Accept: 'application/json' } }
   )
   console.log(readinessResponse.status, await readinessResponse.json())
   ~~~

3. En Network comprobar el JSON y Cache-Control de la respuesta. Se reutiliza la
   cookie de sesión existente; no copiar tokens/cookies ni agregar credenciales
   al código. Si CORS/cookies bloquean la petición, comprobar el origen y sesión
   del listado que ya funciona; no relajar CORS ni autenticación.
4. 401 indica sesión inválida/reemplazada o cuenta inactiva; 403, contraseña
   pendiente o rol insuficiente. 404 con diagnostics_disabled significa que el
   switch está apagado. Un 404 genérico también puede indicar código antiguo o
   URL equivocada. No generar un backup para probar este endpoint.
5. Guardar únicamente el JSON del diagnóstico para identificar las condiciones
   fallidas, sin exportar HAR, cookies ni cabeceras de autenticación.

### Cierre de la investigación

Obligatorio al terminar: volver a EIDA_BACKUPS_DIAGNOSTICS_ENABLED=false en el
entorno investigado y aplicar la configuración mediante un nuevo deployment
autorizado, regenerando config:cache si se utiliza. Verificar con la sesión
Administradora que el GET devuelve 404 con diagnostics_disabled. No basta cambiar
la variable del panel si el deployment existente conserva la configuración.
La retirada definitiva puede eliminar la ruta y BackupReadinessController;
el servicio compartido y el comando CLI pueden conservarse. La investigación
no ha terminado aquí: el endpoint queda apagado por defecto y no se desplegó.

### Pruebas preparadas

tests/Feature/Backups/BackupReadinessDiagnosticTest.php cubre acceso anónimo,
otros roles, ADMINISTRADOR personalizado, asignación inactiva, cuenta inactiva,
contraseña pendiente, sesión reemplazada, switch apagado, respuesta permitida,
las diez condiciones, fallos simultáneos, excepción sin filtración y el comando
compartido. Usa únicamente SQLite :memory:, session/cache array, bloqueo/fakes
HTTP y comprobación de que no se envían solicitudes ni se crean respaldos.

~~~powershell
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Backups/BackupReadinessDiagnosticTest.php
~~~

La investigación posterior del 401 amplió y ejecutó únicamente este archivo:
29 casos, 170 aserciones, correctos. El guard de aislamiento falla antes de
RefreshDatabase si detecta configuración externa o cacheada. Los esquemas de
prueba se crean exclusivamente en SQLite :memory:; no se ejecutó Artisan migrate
contra una base local persistente ni productiva, ni una suite completa.

## Investigación de listado 200 y diagnóstico 401

El usuario confirmó que Production tiene Source
c83a6fde47951489122d9320449639b2c7ca88cf, idéntico al HEAD inspeccionado.
También confirmó Copy as fetch de una solicitud del listado que funciona,
cambiando solo la URL. No se atribuye el problema a falta de login.
No se accedió a Production desde esta investigación.

### Evidencia local

Se ejecutó php artisan route:list --path=backups -vv con APP_ENV=testing,
DB_URL vacío, SQLite :memory:, sesión/cache array y APP_KEY de prueba, sin
consultas productivas. No hay duplicación local de las rutas.
Ambos GET resuelven exactamente este pipeline, en el mismo orden:

1. EnforceMobileTokenAccess
2. EnsureFrontendRequestsAreStateful
3. Authenticate:sanctum
4. ThrottleRequests:60,1,backups-read:
5. SubstituteBindings
6. EnsureCurrentSession
7. EnsurePasswordHasBeenChanged
8. EnsureActiveAdministrator
9. EnsureBackupAdministrator

EnsureFrontendRequestsAreStateful abre internamente el pipeline de cookies/sesión
solo si Referer u Origin coincide con sanctum.stateful; no clasifica por la ruta
de backups. Sanctum usa el guard web. No se sustituyó ningún guard.

La ruta estática se registra antes de /{backup}; además, /{backup} solo admite
un segmento UUID y no puede absorber diagnostics/readiness. No hay parámetro
backup ni binding de modelo en el diagnóstico.

### Origen exacto del mensaje y límites de la conclusión

bootstrap/app.php:47–50 convierte AuthenticationException en HTTP 401 con:
La sesión no es válida o ha expirado.

Ese render común no distingue las dos fuentes aplicables del pipeline:

| Fuente | Condición que lanza la excepción |
| --- | --- |
| Illuminate\Auth\Middleware\Authenticate (auth:sanctum) | Ninguno de los guards indicados autentica al usuario; con sanctum, no se obtiene usuario web ni token válido |
| Laravel\Sanctum\Http\Middleware\AuthenticateSession | Hay usuario y sesión, existe password_hash_web en sesión y no coincide con el hash esperado; cierra esa sesión y lanza AuthenticationException |

La respuesta por sí sola no permite afirmar cuál de las dos condiciones ocurrió.
No se registran hashes ni valores de sesión para distinguirlas.

| Otro control | Respuesta distinta en este código |
| --- | --- |
| EnsureCurrentSession: cuenta inactiva | 401 con code ACCOUNT_INACTIVE |
| EnsureCurrentSession: sesión reemplazada | 401 con code SESSION_REPLACED |
| password.changed | 403 con code PASSWORD_CHANGE_REQUIRED |
| Rol/cuenta administrativa insuficiente | 403 |
| Diagnóstico apagado, después de autorizar | 404 con diagnostics_disabled |
| Ruta/binding inexistente, una vez autorizado | 404 |

El switch del diagnóstico se evalúa dentro del controlador, después de auth.
Ni apagarlo ni una credencial Drive/GitHub inválida generan este mensaje 401.
Por eso no se relajaron los controles ni se cambió la ruta para ocultar el error.
La causa concreta del comportamiento diferente en Vercel sigue sin demostrarse.
El mismo Source no certifica que dos peticiones usen el mismo artefacto,
rutas compiladas, función, configuración o estado de sesión.

### Regresión con autenticación real

Se añadieron cinco casos a BackupReadinessDiagnosticTest:

- Login real Fortify → listado 200 → diagnóstico 200 con la misma cookie,
  tanto con backups.enabled=true como con false (dos casos).
- Selección exacta del controlador estático, ausencia de parámetros y comparación
  del middleware resuelto con el listado; el detalle UUID sigue resolviendo show.
- Sesión real destruida → 401 genérico.
- Cookie desconocida tras un login real → acceso rechazado, sin reutilizar
  accidentalmente un guard autenticado que quedó en memoria.

El helper persiste la sesión array y olvida guards entre solicitudes, siguiendo
el patrón de SingleSessionTest. Los casos de login real no usan actingAs ni
fuerzan active_session_id: lo escribe el flujo existente RecordLastLogin.
Se conservan las pruebas anteriores de invitado, rol ajeno/personalizado, cuenta
inactiva, contraseña pendiente, sesión reemplazada, switch apagado y JSON seguro.

### Comprobación pendiente en Vercel, a cargo del operador

1. En el navegador conservar el contexto autenticado ya comprobado. Repetir
   listado → diagnóstico → listado en una misma sesión, anotando solo hora,
   estado HTTP, host/ruta final y presencia de redirecciones. El segundo listado
   permite detectar si la sesión fue invalidada entre peticiones. No exportar
   Copy as fetch, HAR, Cookie, Authorization ni Set-Cookie.
2. En el proyecto de Vercel abrir Logs y filtrar Environment Production,
   Deployment, GET y los dos Request Path. Correlacionar por hora/request ID.
   Comparar deploymentId, función y recursos Rewrite/Redirect si aparecen.
   La documentación de [Runtime Logs](https://vercel.com/docs/logs/runtime)
   describe estos filtros. Los logs existentes pueden no indicar qué rama de
   autenticación falló: su ausencia no prueba que no existió el rechazo.
3. Revisar en privado, sin compartir valores: presencia de cookie en ambas
   solicitudes finales, coincidencia de Referer/Origin con sanctum.stateful,
   mismo backend de sesiones y configuración consistente de descifrado de
   cookies entre las funciones. Una cookie presente no demuestra que se
   descifró ni que su sesión pudo recuperarse. No regenerar APP_KEY ni cookies
   globales como ensayo: invalidaría sesiones.
4. En el artefacto PHP exacto que publica el pipeline, si permite CLI, obtener
   route:list --path=backups -vv y comparar el pipeline anterior. Revisar solo
   existencia/fecha/origen de bootstrap/cache/routes*.php y config.php; no
   imprimir el contenido de config.php ni valores de configuración.
   Un resultado obtenido solo en build no demuestra el estado del runtime.
5. No usar env dumps, config:show, phpinfo o APP_DEBUG=true en Production.
   Si los pasos anteriores no distinguen auth:sanctum de AuthenticateSession,
   hace falta evidencia privada dentro de la invocación: únicamente código de
   condición, controlador esperado y booleanos de contexto; jamás trazas,
   hashes, cookies o datos del usuario. Este cambio no añade instrumentación
   previa a la autenticación ni un endpoint público para obtenerla.

No se requiere un deployment para ejecutar las pruebas locales añadidas.
No hay un parche productivo confirmado que desplegar. Si la evidencia identifica
un artefacto/ruta cacheada incorrecta, la corrección corresponde al pipeline y
requerirá un nuevo deployment autorizado desde el Source correcto; no se afirma
que esa sea la causa. Conservar EIDA_BACKUPS_DIAGNOSTICS_ENABLED=true solo
durante la investigación y apagarlo al finalizar.

### Particularidad del ejecutor local de PHPUnit en Windows

El comando directo se detuvo antes de cargar tests con Cannot open bootstrap
script, aunque el archivo existe: PHP devuelve is_readable=false para archivos
del repositorio, incluido vendor/autoload.php. No se atribuye este problema
local al 401 de Vercel ni se cambiaron ACL, vendor o configuración de PHPUnit.

Para verificar los 29 casos se usaron copias temporales idénticas del archivo de
pruebas y un bootstrap que requiere el autoloader original. Se mantuvo
phpunit.offline.xml y se eliminaron las copias al terminar. Reproducción local
desde la raíz del backend si aparece el mismo problema:

~~~powershell
$readinessTempDirectory = Join-Path ([IO.Path]::GetTempPath()) ('eida-readiness-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $readinessTempDirectory | Out-Null
$readinessBootstrap = Join-Path $readinessTempDirectory 'bootstrap.php'
$readinessTestCopy = Join-Path $readinessTempDirectory 'BackupReadinessDiagnosticTest.php'
try {
    Set-Content -LiteralPath $readinessBootstrap -Value "<?php require 'C:/proyecto/oidevs-examenes-backend/vendor/autoload.php';" -Encoding ascii
    Copy-Item -LiteralPath 'tests/Feature/Backups/BackupReadinessDiagnosticTest.php' -Destination $readinessTestCopy
    php vendor/bin/phpunit -c phpunit.offline.xml --bootstrap $readinessBootstrap $readinessTestCopy
    $readinessTestExit = $LASTEXITCODE
} finally {
    Remove-Item -LiteralPath $readinessBootstrap -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $readinessTestCopy -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $readinessTempDirectory -ErrorAction SilentlyContinue
}
Write-Output "PHPUnit exit: $readinessTestExit"
~~~

## Corrección manual en Vercel, según el resultado

1. Verificar en Network qué host sirve realmente /api/v1/admin/backups. En Vercel,
   abrir ese proyecto backend, Deployments y el deployment al que apunta el dominio.
   Revisar Environment y Source/commit. No basta que el proyecto tenga variables
   configuradas si el dominio sirve otro proyecto o release.
2. En Settings → Environment Variables, comprobar nombres exactos de las diez
   variables de la tabla y scope Production para ese deployment. Si la URL es
   Preview, revisar su scope y los overrides de rama correspondientes. No editar
   frontend, Worker o GitHub Secrets como sustituto de las variables Laravel.
3. Corregir solo la condición que falle. Mantener GITHUB_REF=backup-execution
   (ya confirmado en el panel); HMAC >=32 bytes y consistente con el runner;
   repositorio OWNER/REPO sin URL; carpeta solo ID, no enlace. No mostrar valores
   de credenciales en capturas, logs o comandos de diagnóstico genérico.
4. Revisar el Build Command/runtime PHP real en Vercel. Este repositorio no versiona
   vercel.json/entrypoint PHP de Vercel; no se puede inferir su pipeline del Dockerfile.
   Si config:cache está incluido, debe generarse con las variables del entorno
   correcto, no copiar una caché creada con placeholders/locales. Localmente no
   existe bootstrap/cache/config.php; eso no prueba que falte en Vercel.
5. En el pipeline autorizado, retirar una caché de configuración obsoleta mediante
   php artisan config:clear y, si su estrategia usa caché, regenerarla con
   php artisan config:cache después de disponer de las variables correctas.
   No ejecutar optimize:clear como sustituto: puede afectar otros cachés. No se
   ejecutaron estos comandos y no se agregan al startup automáticamente.
6. Tras revisar/corregir variables y fuente, el operador debe crear un nuevo
   deployment Production desde el commit apropiado. En Deployments → Redeploy,
   desmarcar Use existing Build Cache si se sospecha reutilización de config vieja.
   Redeploy de un commit viejo no incorpora código más nuevo: verificar Source.
   No se realiza ese despliegue en este trabajo.
7. Confirmar que el dominio apunta al nuevo deployment y volver a consultar el
   listado autenticado, sin POST de generación. enabled=true solo confirma estas
   comprobaciones locales; no certifica OAuth, permisos, workflow o recuperación.

Las variables cambiadas en Vercel solo se aplican a nuevos deployments. Una caché
Laravel ya generada puede conservar los valores antiguos aunque env esté bien.
Fuentes: [Vercel variables](https://vercel.com/docs/environment-variables/managing-environment-variables),
[scopes/overrides](https://vercel.com/docs/environment-variables/manage-across-environments),
[redeploy sin caché](https://vercel.com/docs/deployments/troubleshoot-a-build),
[Laravel config cache](https://laravel.com/docs/11.x/configuration#configuration-caching).

Se prepararon el servicio diagnóstico compartido, comando, controlador, ruta
administrativa, switch apagado por defecto, ejemplo de entorno y pruebas. No se
alteran enabled(), ready(), controles SEC-01/02, frontend, Worker, índices Git o
configuración productiva. La modificación local previa de .gitignore se conserva.
