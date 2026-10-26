# Demostración EIDA por LAN sin Internet

Guía para Windows 11, Git Bash, PostgreSQL local, Laravel 11 y React/Vite. Auditada el 25 de octubre de 2026 exclusivamente sobre los dos repositorios principales. No se inspeccionaron repositorios `*-compare`.

**HTTP permite acceso y login; la cámara del celular requiere HTTPS local confiable.** No se ejecutaron servidores, builds, lint, tests, migraciones, seeders ni consultas a la BD durante esta preparación. Los comandos de arranque, diagnóstico funcional, certificados y firewall siguientes quedan para ejecución manual del responsable. No se cambiaron `.env`, claves, contraseñas, datos ni configuración de producción.

## Arquitectura y diagnóstico actual

```text
Celular 1: hotspot Wi-Fi, sin datos móviles/Internet
  ├─ Laptop: Vite :5173 → proxy HTTP → Laravel 127.0.0.1:8000 → PostgreSQL 127.0.0.1:5432
  └─ Celular 2: http(s)://IP_LAPTOP:5173
```

Solo el puerto 5173 necesita acceso entrante desde el cliente. El proxy está en `../oidevs-examenes-frontend/vite.config.ts` y se usa en desarrollo. El navegador nunca debe llamar al 127.0.0.1 de la laptop desde el celular; usa rutas relativas a la URL abierta. La laptop tampoco necesita salida a Render, Neon o Vercel.

| Componente auditado | Hallazgo / preparación |
| --- | --- |
| Vite 8.2.2 | Antes no tenía proxy ni HTTPS. Se agrega proxy y TLS opcional, puerto 5173 fijo con strictPort; host se elige por CLI. Las IP ya están admitidas por Vite: no se agrega allowedHosts=true ni CORS abierto. HMR conserva host/protocolo/puerto del navegador. |
| `.env` frontend actual | VITE_BACKEND_URL/VITE_API_URL apuntan a 127.0.0.1:8000; fallarían desde el celular. Se entrega `.env.local.example` para sobrescribirlas localmente con /backend y /backend/api/v1. El `.env` actual no fue editado. |
| Axios / authApi / AuthProvider | Dos clientes centralizados con withCredentials=true, withXSRFToken=true y Accept: application/json. Flujo csrf-cookie → login → me ya implementado; no necesita cambios. No se encontraron fetch/API/CDN externos en las rutas de ejecución revisadas. |
| Vercel | Rewrites /backend y /api hacia Render ya existen. `vercel.json` no se modifica. Vite proxy no forma parte de la aplicación estática desplegada. |
| Sanctum / CORS / sesiones | statefulApi ya habilitado; CORS admite FRONTEND_URL exacto con credenciales; Sanctum utiliza dominios con puerto. Sesiones file y SameSite lax disponibles. Falta incorporar el origen LAN mediante el perfil local. |
| Fortify / enlaces | Login recibe JSON, sin navegación a FRONTEND_URL en el flujo Axios habitual. FRONTEND_URL es también base de recuperación y destino de redirección no JSON. APP_URL ayuda a URLs fuera de una petición. Se configura el origen LAN, conservando CSRF y autenticación. |
| Fotos | Los resources y servicios usan asset('storage/...') o conservan URLs HTTP externas guardadas. Se agrega proxy /storage para las fotos locales sin cambiar servicios de negocio; faltan actualmente public/storage y, potencialmente, archivos de fotos. |
| PostgreSQL local | `.env`: pgsql, 127.0.0.1:5432, oidevs_examenes. DB_URL vacío/ausente y no exportado en el proceso auditado; sin bootstrap/cache/config.php. Servicio postgresql-x64-15 observado Running. No se abrió conexión SQL. |
| Alcance de PostgreSQL | Se observó listen_addresses='*' y listeners 0.0.0.0/:: en 5432. Eso NO demuestra acceso permitido por firewall/pg_hba, pero no es escucha exclusiva local. Restringir manualmente antes de la exposición, como se explica abajo. No se cambió el servicio. |
| Cámara HU14 | useEntryQrCamera usa getUserMedia, BarcodeDetector o @zxing/browser, y crypto.subtle.digest. HTTP sobre IP LAN no garantiza estas APIs. Se entrega soporte HTTPS con certificados aportados por el responsable. |
| Borrador HU05 entre pestañas | studentImportDraft usa crypto.randomUUID para emitir una señal de invalidación por localStorage. Está dentro de try/catch: HTTP LAN no bloquea el login por esa ausencia, pero puede perder esa señal entre pestañas. HTTPS es preferible también para demostrar esa sincronización completa. No se cambia la lógica ni se introduce un generador alternativo. |
| Dependencias instaladas | Se observaron Node 20.19.0, npm 10.8.2, PHP 8.2.33 y pdo_pgsql/openssl/mbstring/dom. Hay vendor/autoload.php, los paquetes npm declarados, ZXing y archivos de Poppins locales. Esto no certifica su ejecución. mkcert y psql no se encontraron en PATH. |
| Correo actual | MAIL_MAILER=smtp: no debe dependerse de él offline. Perfil LAN usa log; no se envían correos reales. |

## 1. Preparar antes de salir de Internet

- Tener los dos repositorios principales, `node_modules`, `vendor`, PostgreSQL y una BD local **ya preparada** con esquema, cuentas conocidas y datos útiles. Esta guía no migra ni siembra nada. Las cuentas de una base Neon no aparecen automáticamente en la base local.
- No ejecutar npm install/composer install el día de la exposición sin conexión. Conservar los paquetes completos y archivos de fuentes, no solo lockfiles. Node/PHP deben estar en PATH de Git Bash. Si hace falta instalar dependencias, hacerlo con Internet como una preparación independiente; los scripts Composer existentes pueden ejecutar Artisan, por eso no se ejecutaron aquí.
- Para cámara móvil, disponer previamente de mkcert (binario Windows de sus releases oficiales o instalación del proyecto), y del cable/método local para transferir su CA pública. No se instaló ni descargó mkcert durante esta tarea.
- Disponer de un usuario operador autorizado y otro estudiante; por sesión única, no usar la misma cuenta simultáneamente en laptop y celular. Tener un examen cuya ventana real de ingreso permita la demostración. No se ajustaron fechas ni datos.
- Guardar esta guía y los ejemplos localmente para consultarlos sin Internet. Verificar fecha/hora de Windows y del celular: QR/JWT, certificados y ventanas de examen dependen de ellas.

Desde Git Bash, comprobar herramientas sin arrancar servicios:

```bash
node --version
npm --version
php.exe --version
php.exe -m
```

Si usas Chocolatey, la instalación documentada por mkcert para Windows es `choco install mkcert`, desde una terminal administrativa **con Internet antes de la exposición**. Alternativa sin Chocolatey: descargar el binario Windows de la arquitectura de la laptop desde releases oficiales, guardarlo como mkcert.exe y agregar su carpeta al PATH de usuario; abrir otra terminal y comprobar `mkcert -help`. Tener ese ejecutable local permite emitir certificados para nuevas IPs sin Internet. No se ejecutó ninguna instalación.

## 2. Crear la red y obtener IPs

1. Activar el hotspot del celular 1 y desactivar sus datos móviles si la prueba debe demostrar ausencia de Internet. Algunos teléfonos no mantienen hotspot sin datos: comprobarlo previamente en ese modelo.
2. Conectar laptop y celular 2 al mismo SSID. En el celular 2, elegir permanecer conectado cuando Chrome/Android indique «sin Internet»; desactivar el cambio automático a datos móviles/VPN durante la demostración si desvía tráfico.
3. En Git Bash ejecutar `ipconfig.exe` (o `ipconfig` en PowerShell/CMD). Buscar la IPv4 del adaptador Wi-Fi conectado al hotspot, no la puerta de enlace, VPN, adaptador virtual ni la IP del celular 1.
4. Anotar `IP_LAPTOP` y la IP del celular 2 desde sus detalles Wi-Fi. `192.168.43.100` es solo un ejemplo, no un valor configurado por el proyecto.
5. En PowerShell comprobar interfaz y perfil con `Get-NetConnectionProfile`. El perfil puede ser Public o Private aun siendo una red de demostración. No es necesario desactivar el firewall ni convertir automáticamente toda red a Private.

## 3. Frontend: ejemplo local sin IP fija

Desde la raíz que contiene ambos repositorios, en Git Bash:

```bash
cd oidevs-examenes-frontend
# Solo si .env.local aún NO existe; si existe, integrar las líneas a mano.
test -e .env.local || cp .env.local.example .env.local
```

El archivo `.env.local` debe contener:

```dotenv
VITE_BACKEND_URL=/backend
VITE_API_URL=/backend/api/v1
VITE_USE_AUTH_MOCK=false
VITE_USE_STUDENTS_MOCK=false
VITE_EIDA_INSTITUTIONAL_EMAIL_DOMAINS=umss.edu.bo,est.umss.edu
```

Alinear los dominios con el backend si difieren. `.env.local` está ignorado y tiene prioridad sobre `.env` en el arranque normal; variables exportadas o archivos `.env.development.local` pueden tener mayor prioridad y deben revisarse sin divulgar secretos. No usar `--mode production` para esta demo. Ninguna variable de frontend necesita la IP de la laptop. Reiniciar Vite tras cambiar variables.

| URL del navegador | Ruta que recibe Laravel |
| --- | --- |
| /backend/login | /login |
| /backend/sanctum/csrf-cookie | /sanctum/csrf-cookie |
| /backend/api/v1/me | /api/v1/me |
| /backend/api/v1/health | /api/v1/health |
| /backend/storage/avatar.jpg | /storage/avatar.jpg |
| /storage/avatar.jpg | /storage/avatar.jpg |

El rewrite quita **un solo** /backend inicial. No agrega /api/v1 ni elimina una segunda aparición. La ruta /storage adicional resuelve las URLs absolutas que Laravel ya genera durante peticiones proxificadas. Se conserva Host y se envían X-Forwarded-Host/Proto/Port mediante xfwd; el backend ya confía en esos encabezados. Origin y Referer no se reemplazan: Sanctum sigue comprobando el origen real contra su lista.

## 4. Backend: integrar perfil parcial, conservando secretos

Abrir `oidevs-examenes-backend/.env.hotspot.example`. **No copiarlo sobre `.env`**: es un perfil parcial y no incluye APP_KEY, usuario/contraseña PostgreSQL ni otros secretos. Integrar/reemplazar solo las líneas indicadas en el `.env` local existente, evitando claves repetidas. Conservar APP_KEY, DB_USERNAME, DB_PASSWORD y EIDA_INSTITUTIONAL_EMAIL_DOMAINS actuales.

Para HTTP, cambiar solamente `EIDA_LAN_HOST` por la IP real y mantener estas derivaciones:

```dotenv
EIDA_LAN_HOST=IP_LAPTOP
EIDA_LAN_SCHEME=http
FRONTEND_URL="${EIDA_LAN_SCHEME}://${EIDA_LAN_HOST}:5173"
APP_URL="${FRONTEND_URL}/backend"
SANCTUM_STATEFUL_DOMAINS="${EIDA_LAN_HOST}:5173,localhost:5173,127.0.0.1:5173"
APP_ENV=local
SESSION_DRIVER=file
SESSION_PATH=/
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
DB_CONNECTION=pgsql
DB_URL=
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=oidevs_examenes
FILESYSTEM_DISK=local
CACHE_STORE=file
QUEUE_CONNECTION=sync
MAIL_MAILER=log
```

`IP_LAPTOP` es un placeholder que no debe quedar literal. Los `${...}` los expande Dotenv. No se necesita una variable nueva en config PHP: las dos variables EIDA_LAN_* son auxiliares del perfil local. DB_DATABASE solo cambia si el nombre de tu base local es otro. No poner la IP LAN en DB_HOST ni publicar 5432.

SANCTUM_STATEFUL_DOMAINS lleva host:puerto sin http(s), rutas, espacios ni comodín. SESSION_DOMAIN=null genera cookies limitadas al host que usa el navegador. SESSION_PATH=/ hace que XSRF-TOKEN sea visible tanto para React como para /backend. Las cookies se sirven por el proxy en el mismo origen; no usar el dominio de Render o Vercel en la LAN.

Comprobar variables exportadas sin imprimir sus valores privados, en una terminal Git Bash dedicada al entorno local:

```bash
if [ -n "${DB_URL:-}" ]; then echo 'DB_URL exportada: puede anular la configuración local'; fi
# Quitar DB_URL exportada en ESTA terminal, si la había. No modifica .env.
unset DB_URL
```

Revisar igualmente overrides de DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE, APP_ENV, APP_CONFIG_CACHE, FRONTEND_URL, APP_URL, SANCTUM_STATEFUL_DOMAINS, SESSION_* y EIDA_LAN_*; si están exportados con otros valores, quitarlos con `unset NOMBRE` en esta terminal local. No imprimir ni cambiar APP_KEY/DB_PASSWORD. DATABASE_URL no es la variable que lee este repo: lee DB_URL.

Después de editar el `.env` local, desde backend:

```bash
php.exe artisan config:clear
```

Este comando solo limpia configuración; no migra ni siembra la BD. Está documentado para ejecución manual, no se ejecutó en esta preparación. No usar optimize:clear como sustituto innecesario. Reiniciar Laravel después de cambios de red/configuración.

## 5. PostgreSQL: arrancar, restringir escucha y comprobar destino real

En PowerShell, consultar el servicio observado en esta laptop:

```powershell
Get-Service -Name '*postgres*'
# Solo si está detenido; requiere permisos administrativos.
Start-Service -Name 'postgresql-x64-15'
```

Equivalente desde Git Bash, abriendo PowerShell con los permisos necesarios:

```bash
powershell.exe -NoProfile -Command "Start-Service -Name 'postgresql-x64-15'"
```

En otra laptop el nombre puede variar: usar el que devuelva Get-Service. No instalar un servidor nuevo ni inicializar otro clúster para resolver un problema de conexión.

**Hallazgo pendiente de ajuste manual en Windows:** actualmente 5432 escucha en todas las interfaces. Para mantenerlo exclusivamente en la laptop, un responsable debe localizar el `postgresql.conf` del servicio (ruta -D en sus propiedades; alternativamente `SHOW config_file;` en su consola SQL local), configurar `listen_addresses = 'localhost'`, y reiniciar ese servicio cuando pueda interrumpir sus conexiones. No cambiar contraseñas ni ampliar pg_hba.conf. La configuración local existente de autenticación debe seguir admitiendo 127.0.0.1/::1. No se ejecutó ninguna de estas acciones.

Verificación posterior en PowerShell:

```powershell
Get-NetTCPConnection -State Listen -LocalPort 5432 |
    Select-Object LocalAddress, LocalPort
```

Se espera 127.0.0.1 y/o ::1; 0.0.0.0/:: indican escucha general. No crear una regla de entrada para 5432. La ausencia de esa regla no basta para certificar aislamiento si ya existen permisos generales: revisar reglas existentes o restringir la escucha del servicio antes de exponer la laptop al hotspot.

Antes de cualquier operación sobre datos, comprobar qué configuración **resuelta** utiliza Laravel y qué servidor responde. El siguiente comando es manual y hace solo un SELECT; se detiene antes de consultar si el destino resuelto no es PostgreSQL loopback. No imprime usuario, contraseña ni URL de conexión. Desde backend en Git Bash:

```bash
php.exe artisan tinker --execute='
$c = DB::connection();
$driver = $c->getConfig("driver");
$host = $c->getConfig("host");
dump(["driver" => $driver, "host" => $host,
    "port" => $c->getConfig("port"), "database" => $c->getConfig("database"),
    "DB_URL_presente" => filled(config("database.connections.".config("database.default").".url"))]);
if ($driver !== "pgsql" || !in_array($host, ["127.0.0.1", "localhost", "::1"], true)) {
    throw new RuntimeException("Destino no local: no se ejecuta la consulta.");
}
dump($c->selectOne("select current_database() as database, inet_server_addr()::text as server_address, inet_server_port() as server_port"));
'
```

Config cacheada y DB_URL exportada pueden cambiar el resultado aunque DB_HOST en `.env` parezca correcto. El parser de Laravel aplica los componentes de DB_URL sobre DB_HOST/DB_DATABASE. No usar `config:show database` ni volcar toda la configuración porque podría revelar secretos. `/api/v1/health` confirma Laravel, **no verifica la BD**.

## 6. Iniciar Laravel y React (dos terminales Git Bash)

Terminal A, desde backend:

```bash
cd oidevs-examenes-backend
php.exe artisan serve --host=127.0.0.1 --port=8000
```

Este es el enlace mínimo necesario con Vite; el cliente no necesita entrar directamente a 8000. También es compatible el comando solicitado, si se desea escuchar Laravel en toda la LAN:

```bash
php.exe artisan serve --host=0.0.0.0 --port=8000
```

Usar uno u otro, no los dos. No es necesario abrir 8000 en el firewall para el recorrido por proxy.

Terminal B, desde frontend:

```bash
cd oidevs-examenes-frontend
npm run dev -- --host 0.0.0.0
```

Los `cd` anteriores parten del directorio común. Si ya estás dentro de un repo, no repetir su nombre. strictPort evita que Vite cambie silenciosamente a 5174, lo que invalidaría el origen configurado.

Desde laptop y celular 2 abrir **la misma URL** `http://IP_LAPTOP:5173`. En la laptop también funciona `http://localhost:5173`, incluido en Sanctum, pero tendrá cookies independientes de la IP. No abrir la interfaz desde 0.0.0.0 ni intentar usar localhost desde el celular para llegar a la laptop.

Diagnóstico manual desde laptop (Git Bash):

```bash
curl.exe -i http://127.0.0.1:8000/api/v1/health
# Sustituir IP_LAPTOP antes de ejecutar:
curl.exe -i http://IP_LAPTOP:5173/backend/api/v1/health
```

Abrir también la segunda URL en Chrome del celular. Si el endpoint del proxy responde JSON, la ruta móvil → Vite → Laravel funciona; todavía falta comprobar sesión y BD. Estos requests no se ejecutaron durante la preparación.

## 7. Login, cookies y errores

En DevTools de la laptop —o inspección remota de Chrome Android por USB preparada previamente— comprobar el flujo:

1. GET /backend/sanctum/csrf-cookie devuelve cookies XSRF-TOKEN y sesión para IP_LAPTOP, Path=/, sin Domain ajeno. Sesión HttpOnly; XSRF-TOKEN accesible a Axios.
2. POST /backend/login envía la cookie y X-XSRF-TOKEN leído por Axios; Accept application/json evita la redirección HTML habitual de Fortify.
3. GET /backend/api/v1/me devuelve la cuenta, roles y must_change_password. Su Origin/Referer corresponde a IP_LAPTOP:5173 y debe estar en SANCTUM_STATEFUL_DOMAINS.
4. AuthProvider restaura/verifica la sesión por /me; un 401 activa la lógica existente de sesión expirada/reemplazada. Completar cambio obligatorio si la cuenta lo requiere. No habilitar mocks para ocultar fallos.

La misma IP/puerto es usada por React y API desde el navegador; no requiere CORS comodín. CORS backend conserva sus orígenes de desarrollo y FRONTEND_URL exacto con credenciales. No se deshabilitan CSRF, Sanctum, VerifyExamAccess ni la protección de contraseña inicial. Mantener el mismo host entre csrf-cookie, login y me; cambiar de localhost a IP o de HTTP a HTTPS implica otro contexto de cookies/sesión.

| Síntoma | Revisar |
| --- | --- |
| 401 después de login | Host/puerto de Sanctum, cookies de sesión, cache/overrides de configuración, cuenta activa y sesión única. La sesión configurada vence tras 5 minutos de inactividad. |
| 419 | csrf-cookie previo, XSRF-TOKEN, X-XSRF-TOKEN, Path=/, Domain=null; Secure=false en HTTP; no mezclar hosts o sesiones viejas. |
| Error CORS | Network debe mostrar IP_LAPTOP:5173/backend, no localhost:8000 ni Render. Revisar .env.local, variables exportadas, FRONTEND_URL y reinicio de procesos. No abrir allowed_origins='*'. |
| 403 | Puede ser permiso/estado/cambio inicial/examen fuera de disponibilidad; comprobar respuesta real, no atribuirlo automáticamente a la red. |
| 422 de login | Revisar identificador institucional, contraseña y cuenta local existente; no es un fallo de proxy. |
| Respuesta HTML donde se esperaba JSON | URL sin /backend, rewrite incorrecto, API base duplicada, redirect no JSON o frontend sirviendo fallback. |
| Foto 404 | public/storage inexistente o archivo ausente. Las fotos externas almacenadas como URL no estarán disponibles offline. |

Si se necesitan fotos locales, preparar previamente el enlace `public/storage` a `storage/app/public` y los archivos. Actualmente no se observó el enlace. `php.exe artisan storage:link` es el mecanismo del proyecto, sujeto a permisos de enlaces en Windows; no se ejecutó. Sin fotos, las pantallas deben conservar sus estados de ausencia. El config/app.php actual no declara ASSET_URL: no hace falta introducirla para este recorrido, que conserva el origen de la petición y proxifica /storage.

## 8. QR: HTTP y HTTPS son escenarios distintos

| Capacidad | HTTP por IP LAN | HTTPS con certificado confiable |
| --- | --- | --- |
| Navegar, login y operaciones autorizadas | Sí, sujeto a red/configuración | Sí |
| Generar/mostrar QR del estudiante | Sí, servicio PHP local y datos válidos | Sí |
| Consultar estudiantes/ingresos y registrar manualmente | Sí | Sí |
| Escanear con cámara del celular | No se garantiza: contexto inseguro | Preparado, aún requiere permiso y prueba en ese celular |

El QR no usa un servicio externo: BaconQrCode genera SVG y Laravel firma JWT con APP_KEY. ZXing está instalado como paquete local y el fallback no descarga un lector desde CDN. El escáner usa además crypto.subtle.digest; confiar solo en que el navegador muestre video no demuestra que pueda procesar el QR.

La ventana QR es [inicio−24h, fin), mientras que el ingreso requiere inicio <= ahora < fin, en America/La_Paz y con examen/usuario/matrícula/habilitación/autorización válidos. No cambiar estas reglas para la demostración.

### Preparar HTTPS completamente local

No se generaron certificados ni claves. Hacer esta preparación manual con mkcert ya instalado y conservar todos los archivos en la laptop. Desde frontend, Git Bash:

```bash
mkcert -install
mkdir -p .certs
# CAMBIAR por la IPv4 real, sin http:// ni puerto:
IP_LAPTOP=192.168.43.100
mkcert -key-file .certs/eida-lan-key.pem -cert-file .certs/eida-lan.pem "$IP_LAPTOP" localhost 127.0.0.1 ::1
mkcert -CAROOT
```

El último comando indica la carpeta de la CA. Transferir por USB **solo** su `rootCA.pem` público al celular (puede renombrarse `eida-lan-ca.crt`). Nunca transferir rootCA-key.pem ni eida-lan-key.pem. No poner certificados/claves dentro de public; `.certs/` y `.env.local` están ignorados por Git y Vite conserva su bloqueo predeterminado de archivos PEM y otros secretos.

- **Android/Chrome:** instalar el certificado de CA para confianza web desde Ajustes → Seguridad/Privacidad → Más ajustes de seguridad → Cifrado y credenciales → Instalar certificado → Certificado de CA (los nombres varían por fabricante/versión). No elegir únicamente certificado Wi-Fi o de cliente. Puede requerir PIN y un aviso de confianza de CA. Reiniciar Chrome y verificar que la URL no muestra error TLS; dispositivos administrados pueden impedir instalar CAs. La compatibilidad concreta se comprueba en el teléfono de la exposición.
- **iPhone/Chrome:** abrir el certificado público transferido, instalar su perfil en Ajustes y habilitar confianza total en General → Información → Ajustes de confianza de certificados. Un perfil instalado sin confianza total no basta.
- Comprobar nombre/IP del certificado, fechas y confianza. No usar flags inseguros de Chrome ni saltar advertencias TLS para intentar habilitar cámara. Conservar la CA local bajo control y retirar su confianza del dispositivo al dejar de necesitarla.

En frontend `.env.local`, habilitar **ambas** líneas:

```dotenv
EIDA_DEV_HTTPS_KEY=.certs/eida-lan-key.pem
EIDA_DEV_HTTPS_CERT=.certs/eida-lan.pem
```

En backend `.env` cambiar:

```dotenv
EIDA_LAN_SCHEME=https
SESSION_SECURE_COOKIE=true
```

Las derivaciones APP_URL/FRONTEND_URL pasan a HTTPS; Sanctum sigue usando el mismo host:5173, sin esquema. Limpiar config manualmente y reiniciar Laravel/Vite con los mismos comandos de la sección 6. Laravel continúa HTTP loopback detrás del proxy; no hace falta otro certificado en 8000. El proxy reenvía el esquema HTTPS y Laravel ya tiene trustProxies para reconocerlo. El navegador ve un único origen HTTPS, también para API/fotos locales, sin requests HTTP mixtos a 8000. No se configura HMR con una IP fija: debe usar WSS del mismo origen.

Abrir `https://IP_LAPTOP:5173` en ambos dispositivos, iniciar sesión nuevamente y conceder permiso de cámara. En consola del navegador comprobar `window.isSecureContext === true`, `navigator.mediaDevices?.getUserMedia` y `crypto.subtle`; después probar una lectura real y su respuesta de backend. Confirmar que login, foto y HMR siguen funcionando en HTTPS. Estas pruebas quedan pendientes.

Si cambia la IP, emitir otro certificado con la nueva IP/SAN, conservando la misma CA: no hace falta reinstalar esa CA si sigue siendo la misma. Reiniciar Vite para cargar los nuevos archivos. Si no puede instalarse confianza en el celular, conservar HTTP para login/control manual y mostrar el QR en el celular; como alternativa, usar la cámara de la laptop desde http://localhost:5173 (localhost tiene tratamiento de contexto seguro en navegadores compatibles). Incluir localhost en Sanctum, tener cámara/permisos y usar cuentas distintas. No prometer cámara móvil por HTTP.

## 9. Firewall Windows y aislamiento del hotspot

Diagnóstico en PowerShell, después de arrancar manualmente los servidores:

```powershell
Get-NetConnectionProfile
Get-NetIPAddress -AddressFamily IPv4
Get-NetTCPConnection -State Listen -LocalPort 5173,8000 |
    Select-Object LocalAddress, LocalPort, OwningProcess
```

5173 debe escuchar en 0.0.0.0 o en la interfaz Wi-Fi, no solo 127.0.0.1. Para Laravel se espera 127.0.0.1:8000 con la opción recomendada, o 0.0.0.0:8000 si se eligió explícitamente esa variante. `Test-NetConnection 127.0.0.1 -Port 8000` sirve para el salto interno; no prueba el acceso desde el celular.

Si el firewall bloquea Vite, el responsable puede crear **manualmente** una regla restringida en PowerShell como administrador, después de reemplazar TODOS los placeholders. No se ejecutó ni se requiere abrir otros puertos:

```powershell
$demoLaptopIp = 'IP_LAPTOP'
$demoClientIp = 'IP_CELULAR_2'
$demoInterface = 'Wi-Fi' # nombre real de Get-NetConnectionProfile
$demoProfile = 'Public' # usar Public o Private según ese adaptador
New-NetFirewallRule -Name 'EIDA-LAN-VITE-DEMO' -DisplayName 'EIDA LAN Vite demo' `
    -Direction Inbound -Action Allow -Protocol TCP -LocalPort 5173 `
    -LocalAddress $demoLaptopIp -RemoteAddress $demoClientIp `
    -InterfaceAlias $demoInterface -Profile $demoProfile
```

Si esa regla ya existe, revisar/actualizarla con Set-NetFirewallRule en lugar de crear otra. Al cambiar de red/perfil/IP de celular debe actualizarse su alcance. Una regla de bloqueo existente puede prevalecer sobre la nueva: inspeccionar Windows Defender Firewall con seguridad avanzada, sin deshabilitarlo globalmente ni borrar reglas generales. Al terminar, retirar únicamente la regla propia con `Remove-NetFirewallRule -Name 'EIDA-LAN-VITE-DEMO'`.

Si laptop abre la URL por su IP pero el celular no, comprobar SSID, IPs, perfil/reglas, ruta y VPN. Si con el listener correcto y regla restringida el teléfono sigue sin alcanzar /backend/api/v1/health, el hotspot puede aislar clientes o impedir tráfico cliente→cliente. Un ping fallido por sí solo no prueba aislamiento: ICMP puede estar bloqueado. Probar el acceso TCP/HTTP desde el cliente y, si es posible, con otro dispositivo o un router/hotspot alternativo que permita comunicación local. El código no puede eliminar el aislamiento del punto de acceso.

## 10. Qué funciona offline

| Clasificación | Funcionalidades / condiciones |
| --- | --- |
| Funciona sin Internet con dependencias y datos locales | React, fuentes Poppins importadas de node_modules, iconos/assets del repo, Laravel, PostgreSQL, login por credenciales locales, administración, inscripciones, habilitaciones, colaboradores, auditoría, QR PHP y control manual. Los requests usan Axios centralizado, sin API/CDN externo necesario encontrado. |
| Funciona con preparación local adicional | Cámara con HTTPS confiable/permisos; fotos con archivos y public/storage; importaciones con CSV local; roles/datos/exámenes válidos y vigentes. No se crean datos ni archivos de importación en esta tarea. |
| Funciona perdiendo recurso no esencial | Foto de perfil guardada como URL externa: no carga offline; el resto del flujo no obtiene esa imagen de Internet. Revisar visualmente ausencia de foto. |
| Diferencia de APIs del navegador por HTTP LAN | La señal HU05 de invalidación entre pestañas puede omitirse por falta de crypto.randomUUID. Se mantiene invalidación local y control backend; usar HTTPS y comprobar varias pestañas si se demuestra ese flujo. |
| No funciona como entrega externa sin Internet | SMTP/servicios de correo remotos, recursos cloud S3 si se configuraran, nuevas descargas npm/Composer/mkcert y servicios Render/Neon/Vercel. Este perfil no los usa. |
| Recuperación con MAIL_MAILER=log | No entrega email. El responsable puede revisar el mensaje/enlace local en storage/logs según LOG_CHANNEL. Los logs pueden contener tokens de recuperación: no publicarlos ni mostrarlos en pantalla compartida. |

La existencia de namespaces XML como http://www.w3.org/2000/svg no implica una descarga de Internet. No se detectaron fuentes Google Fonts, mapas, scripts CDN ni imágenes remotas fijas de ejecución en el frontend auditado. URLs externas que estén **en la BD** no se inspeccionaron porque no se consultaron registros; verificar los datos que se mostrarán. No se cambió el escáner ni se agregó un servicio externo de QR.

## QUÉ TENGO QUE CAMBIAR CADA VEZ QUE CAMBIE DE RED

| Elemento | Acción exacta |
| --- | --- |
| IPv4 de laptop | Obtener con ipconfig.exe después de conectar al hotspot |
| Backend `.env` | Cambiar únicamente `EIDA_LAN_HOST=...` por esa IPv4. APP_URL, FRONTEND_URL y SANCTUM_STATEFUL_DOMAINS se derivan de ella |
| HTTP/HTTPS | Mantener o cambiar EIDA_LAN_SCHEME; SESSION_SECURE_COOKIE=false para HTTP, true para HTTPS confiable |
| Config efectiva Laravel | Resolver overrides exportados; ejecutar config:clear manualmente y reiniciar Laravel |
| Frontend `.env.local` | /backend y /backend/api/v1 NO cambian por IP. Solo revisar mocks/dominios y rutas TLS si corresponde |
| Certificado HTTPS | Regenerarlo con la nueva IP/SAN y reiniciar Vite; CA del celular solo se reinstala si cambió la CA |
| Vite HTTP | No necesita reiniciarse por IP porque escucha 0.0.0.0 y no fija HMR; sí si cambian variables/certificados |
| Firewall | Actualizar LocalAddress, RemoteAddress del celular, InterfaceAlias y perfil de la regla propia si variaron |
| PostgreSQL | No cambiar DB_HOST=127.0.0.1 ni publicar 5432; verificar escucha loopback |
| URL del celular/laptop | Abrir http://NUEVA_IP:5173 o https://NUEVA_IP:5173 y volver a iniciar sesión |

Si se elige no utilizar las derivaciones del perfil, deberán cambiarse manualmente las tres variables APP_URL, FRONTEND_URL y SANCTUM_STATEFUL_DOMAINS de forma coherente. La plantilla reduce esa repetición a una IP.

## 11. Recorrido y checklist del día

- [ ] Hotspot encendido, laptop/celular conectados, tráfico móvil automático controlado, comunicación entre clientes permitida.
- [ ] IPv4 real anotada; EIDA_LAN_HOST actualizado y perfil/firewall revisados.
- [ ] PostgreSQL local iniciado, 5432 restringido a loopback, DB_URL/overrides/cache revisados y SELECT manual confirma base local.
- [ ] APP_KEY/credenciales conservadas; datos, usuarios y examen vigente disponibles previamente; no se migra ni siembra para improvisar.
- [ ] Frontend .env.local usa rutas relativas, mocks=false; configuración Laravel recargada.
- [ ] Laravel en 8000 y Vite en 5173; el celular abre el health del proxy y la web.
- [ ] Login → me funciona con una cuenta autorizada; primer acceso/roles y sesión única considerados.
- [ ] Probar listados/búsquedas/detalles de estudiantes, catálogos y ofertas; docente ve solo lo permitido.
- [ ] Mostrar habilitado/inhabilitado, QR disponible/futuro e ingreso pendiente/registrado según datos actuales. No deducir autorización solo por tener QR.
- [ ] HTTPS: certificado vigente con IP/SAN actual, CA instalada/confiada, contexto seguro y permiso de cámara; probar escaneo real. Si falla, alternativa manual/laptop prevista.
- [ ] Comparar mobile/laptop: cookies, fotos, navegación profunda, mensajes, contadores y HMR sin URLs localhost/Render/Neon/Vercel en Network.
- [ ] Desactivar toda salida a Internet y repetir el recorrido, manteniendo la LAN activa. No se realizó esta prueba en la preparación.
- [ ] Detener ambos servidores con Ctrl+C, retirar la regla temporal y guardar registros de demo necesarios. Detener PostgreSQL con Stop-Service solo si se inició exclusivamente para esta demo y no sirve a otros proyectos. Retirar confianza de la CA cuando deje de necesitarse.

Para ese caso opcional de detener PostgreSQL, comando exacto en PowerShell administrativo: `Stop-Service -Name 'postgresql-x64-15'`. No detener un servicio compartido que ya estaba funcionando antes de la demostración.

## Archivos y comprobación estática

Frontend: `vite.config.ts`, `.gitignore`, `.env.local.example` y guía de acceso en `docs/DEMO_LAN_OFFLINE.md`. Backend: `.env.hotspot.example` y esta guía. Sin cambios a vercel.json, URLs Render/Neon, controladores, servicios, middleware, rutas de negocio o configuración de producción.

La UI conserva estilos/rutas/componentes. El comportamiento nuevo es el proxy de desarrollo y HTTPS opcional. Se verifican sintaxis/configuración y git diff/check/status sin arrancar aplicaciones; quedan pendientes el login real, cámara, confianza móvil, HMR, fotos, firewall y ausencia de aislamiento en los tres dispositivos. Ninguna revisión estática certifica una demostración funcional completa.

## Fuentes técnicas

La implementación real se auditó en `vite.config.ts`, package.json, src/app/config/env.ts, src/shared/api/httpClient.ts, authApi/AuthProvider, useEntryQrCamera, archivos de fuentes y clientes de API; en backend, .env.example, bootstrap/app.php, config/{cors,sanctum,session,database,fortify,filesystems,mail}, routes, StudentQrService, servicios/resources de fotos y AppServiceProvider. También se revisaron las implementaciones **instaladas** de Vite (proxy, allowedHosts y deny de archivos), Axios (XSRF), Sanctum (Origin/Referer) y el parser DB_URL de Laravel.

- [Vite: host, hosts admitidos, proxy, HTTPS y HMR](https://vite.dev/config/server-options).
- [Laravel 11: autenticación SPA con Sanctum](https://laravel.com/docs/11.x/sanctum).
- [MDN: getUserMedia y contexto seguro](https://developer.mozilla.org/en-US/docs/Web/API/MediaDevices/getUserMedia), [digest y contexto seguro](https://developer.mozilla.org/en-US/docs/Web/API/SubtleCrypto/digest).
- [mkcert: instalación, certificados y CA móvil](https://github.com/FiloSottile/mkcert), [binarios oficiales](https://github.com/FiloSottile/mkcert/releases).
- [Apple: confianza de certificados instalados manualmente](https://support.apple.com/en-us/102390), [Google: gestión de certificados Android; menús según dispositivo](https://support.google.com/pixelphone/answer/2844832?hl=en), [Chromium: confianza local de certificados en conexiones TLS/TCP](https://chromium.googlesource.com/chromium/src/+/main/net/data/ssl/chrome_root_store/faq.md).
- [Microsoft: reglas de firewall con alcance de dirección/interfaz/perfil](https://learn.microsoft.com/en-us/powershell/module/netsecurity/new-netfirewallrule), [PostgreSQL 15: listen_addresses](https://www.postgresql.org/docs/15/runtime-config-connection.html).
