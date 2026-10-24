# EIDA: Render + Neon + Vercel

## Alcance de la revisión

Auditoría estática del 24 de octubre de 2026. Estado inicial: **PARCIALMENTE LISTO**.
Laravel y la autenticación ya estaban configurados; faltaba definir la imagen,
el servidor y el puerto de producción. No había Dockerfile, Compose, Procfile,
render.yaml, scripts de despliegue ni configuración de servidor versionados.
`public/.htaccess` ya permite reutilizar Apache con `mod_rewrite`.

No se ejecutaron builds, servidores, pruebas, migraciones, seeders, generación
de claves ni conexiones a Neon. La revisión estática no sustituye la posterior
validación del build y del flujo de autenticación desplegado.

## Imagen y arranque

- Base: `php:8.3-apache-bookworm`, que obtiene los parches de la rama 8.3 en
  cada nueva resolución de la imagen. El proyecto exige PHP `^8.2`.
- Lock: Laravel `11.56.1`, Fortify `1.39.0`, Sanctum `4.3.3`.
- La imagen oficial incluye PDO, mbstring, openssl, tokenizer, ctype, JSON,
  fileinfo, curl, iconv y DOM/libxml/XML/XMLWriter. Se compila `pdo_pgsql`.
  XMLWriter se utiliza para los QR SVG. No hacen falta GD, intl, bcmath ni zip
  en el código actual; `unzip` permite instalar los paquetes dist de Composer.
- Dependencias añadidas: `libpq5` y `unzip`; `libpq-dev` solo durante compilación.
- Composer 2 instala desde `composer.lock` con
  `--no-dev --prefer-dist --no-interaction --optimize-autoloader` y comprueba
  los requisitos de plataforma en el build.
- `post-autoload-dump` limpia los manifiestos y descubre paquetes. Los providers
  inspeccionados no consultan la BD en ese flujo. `post-update-cmd` y
  `post-create-project-cmd` no forman parte de `composer install`.
- `storage` y `bootstrap/cache` se crean y quedan escribibles por `www-data`.
  El código conserva la propiedad de root. Apache usa el proceso padre de la
  imagen oficial y workers `www-data`.
- DocumentRoot: `/var/www/html/public`. Se crea el enlace `public/storage`.
- PHP admite archivos de hasta 5 MiB y cuerpos de 8 MiB, para no bloquear los
  endpoints existentes que validan CSV de hasta 5120 KiB; las reglas Laravel
  de cada endpoint siguen aplicándose.
- CMD: `["apache2-foreground"]`. Apache sustituye `${PORT}` al arrancar y
  escucha en `0.0.0.0:${PORT}`. Valor por defecto: `10000`.
- No hay migraciones, seeders ni comandos de caché en build o arranque.

`.dockerignore` excluye Git, dependencias locales, `.env` y sus variantes,
credenciales de Composer, claves, logs, contenido local de storage, cachés de
bootstrap, pruebas, QA y Compose local. Los directorios necesarios se recrean.
`.env.example` queda como plantilla sin secretos y no se copia a `.env`.

## Configuración del Web Service

| Campo | Valor |
| --- | --- |
| Runtime / Language | Docker |
| Branch | main |
| Root Directory | Raíz del repositorio backend |
| Dockerfile Path | ./Dockerfile |
| Docker Build Context | . |
| Docker Command / Start Command | Dejar vacío: usa el CMD de la imagen |
| Comando efectivo | apache2-foreground |
| Health Check Path | /up |

No hace falta `render.yaml`: el dashboard y el Dockerfile bastan.
`GET /up` ya existe, no requiere login ni consulta la BD por defecto.
También existe `GET /api/v1/health`, con JSON y sin autenticación.

## Variables de Render

Configurar las siguientes variables; los valores secretos se introducen solo
en el dashboard. No subir el `.env` local ni cargarlo como Secret File.

| Obligatoria para esta configuración | Valor o procedencia |
| --- | --- |
| APP_NAME | EIDA |
| APP_ENV | production (también predeterminado en Docker) |
| APP_DEBUG | false (también predeterminado en Docker) |
| APP_KEY | Secreto generado externamente y estable entre despliegues |
| APP_URL | https://<servicio>.onrender.com |
| FRONTEND_URL | https://oidevs-examenes-frontend.vercel.app |
| SANCTUM_STATEFUL_DOMAINS | oidevs-examenes-frontend.vercel.app, sin protocolo |
| EIDA_INSTITUTIONAL_EMAIL_DOMAINS | umss.edu.bo,est.umss.edu |
| DB_CONNECTION | pgsql (también predeterminado en Docker) |
| DB_URL | Connection string secreta de Neon con sslmode=require |
| SESSION_SECURE_COOKIE | true (también predeterminado en Docker) |

Valores opcionales/recomendados, ya soportados por el código:

| Variable | Valor para la demo / efecto |
| --- | --- |
| SESSION_DRIVER | file; predeterminado en Docker y configuración |
| SESSION_LIFETIME | 5 minutos; se conserva el valor actual del proyecto |
| SESSION_ENCRYPT | false |
| SESSION_PATH | /; permite que el frontend lea XSRF-TOKEN |
| SESSION_SAME_SITE | lax; navegador y /backend comparten origen |
| SESSION_DOMAIN | Omitir; si se define, usar null, nunca el hostname Render |
| LOG_CHANNEL | stderr; predeterminado en Docker y soportado por logging.php |
| LOG_LEVEL | info; predeterminado en Docker |
| FILESYSTEM_DISK | local |
| QUEUE_CONNECTION | sync; predeterminado en Docker |
| CACHE_STORE | file; predeterminado en Docker |
| APP_TIMEZONE | UTC actual; no se cambian reglas de fechas de exámenes |
| MAIL_MAILER | log para demo; smtp para enviar recuperación real |
| MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION | Según el proveedor SMTP |
| MAIL_USERNAME, MAIL_PASSWORD | Secretos SMTP, únicamente en Render |
| MAIL_FROM_ADDRESS, MAIL_FROM_NAME | Remitente autorizado por el proveedor |
| PGCHANNELBINDING | require si se quiere exigir channel binding vía libpq |

No necesarias en este modo: `DATABASE_URL`, `DATABASE_PASSWORD`, variables
`RAILWAY_*`, Redis, Memcached, AWS, Node/Vite ni un worker de colas.
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` y `DB_PASSWORD` no son
necesarias si se suministra `DB_URL`; siguen disponibles como alternativa.
`PORT` lo proporciona Render y no necesita definirse manualmente.

## Neon y migraciones

`config/database.php` lee **DB_URL**, no DATABASE_URL. El parser de Laravel
reconoce `postgres://` y `postgresql://`; los parámetros de la URL prevalecen
sobre los valores de configuración. `sslmode=require` llega al DSN PDO y
reemplaza el default `prefer`. No fue necesario modificar esa configuración.

Copiar la URL de Neon sin el prefijo de shell `psql` ni comillas envolventes.
Mantener los caracteres reservados de las credenciales codificados en la URL.
Si la URL incluye `channel_binding=require`, conservarla; Laravel 11 no pasa
ese parámetro al DSN. Para exigirlo realmente se puede definir además
`PGCHANNELBINDING=require`, que sí consume libpq.

Las migraciones revisadas usan FK, UUID, JSONB, índices parciales, CHECK y
funciones/triggers PL/pgSQL compatibles con PostgreSQL; no requieren una
extensión adicional. El rol de migración necesita permisos DDL sobre `public`.
Para migraciones es preferible la conexión directa de Neon. En una BD ya
poblada, revisar antes los datos: hay conversiones de docentes/colaboradores,
restricciones nuevas y una columna `exam_id` no nullable que pueden exigir
corregir datos existentes. No se modificaron migraciones históricas.

Comandos manuales posteriores, desde `/var/www/html` y con las variables
secretas del servicio cargadas:

```sh
php artisan migrate:status
php artisan migrate --force
```

En una BD vacía, `migrate:status` puede indicar que aún no existe la tabla de
migraciones. No usar `migrate:fresh`. Para crear únicamente los roles oficiales
se puede ejecutar, opcionalmente:

```sh
php artisan db:seed --class=RoleSeeder --force
```

**Sprint2DemoSeeder no existe en este checkout**. No ejecutar el comando con
ese nombre: fallaría al resolver la clase. El namespace PSR-4
`Database\\Seeders\\` sí está registrado, pero no crea una clase ausente.
No se inventó un seeder ni se eligió un sustituto automáticamente.

`EidaDemoSeeder` existe y no depende de Faker, rutas locales o Railway, pero
actualiza usuarios, contraseñas y catálogos existentes y contiene credenciales
conocidas de demo; no se certifica como carga no destructiva. `UserSeeder`
también contiene contraseñas de prueba. `StudentPanelDevSeeder` elimina datos
y referencia columnas/estados que no coinciden con el esquema actual; además
intenta borrar auditorías protegidas por trigger. No utilizarlos en producción.
`DatabaseSeeder` llama únicamente a `RoleSeeder`, que usa `firstOrCreate`.

Generar APP_KEY externamente mediante `php artisan key:generate --show` y
guardarla solo como secreto del servicio. No se generó ninguna durante la tarea.
Render Free no ofrece shell ni jobs de una sola ejecución. Con ese plan,
ejecutar las migraciones desde un entorno externo autorizado con las mismas
versiones y variables. No añadirlas al CMD para compensar esa limitación.

## Vercel, cookies y HTTPS

Flujo: navegador → Vercel `/backend/*` → Render sin el prefijo `/backend` → Neon.
Las rutas Laravel conservan sus contratos. Ejemplos de traducción:

| Navegador | Laravel |
| --- | --- |
| /backend/sanctum/csrf-cookie | /sanctum/csrf-cookie |
| /backend/login | /login |
| /backend/api/v1/health | /api/v1/health |

Frontend: `VITE_BACKEND_URL=/backend`, `VITE_API_URL=/backend/api/v1`.
El `vercel.json` del frontend vecino aún apunta a Railway en los rewrites
`/backend/:path*` y `/api/:path*`: sustituir sus destinos por el servicio Render
cuando exista. Esta revisión no modificó el frontend.

Sanctum ya usa `statefulApi()` y el guard `web`; Fortify usa sesiones y vistas
desactivadas. CORS ya incluye FRONTEND_URL, desarrollo local, credenciales y
las rutas api, csrf-cookie, login, logout, forgot-password, reset-password y
user/password. No hay wildcard de orígenes ni hace falta añadir Render.

La confianza de proxies se añadió porque Laravel no reconoce Render de forma
automática: Render termina TLS y entrega HTTP al contenedor. Se confía en el
proxy inmediato para X-Forwarded-For/Host/Port/Proto. El contenedor debe quedar
detrás del ingreso administrado de Render. No se fuerza un dominio en código.
Las cookies sin Domain pertenecen al host Vercel visto por el navegador y
deben mantenerse con Path=/, Secure y SameSite=lax. Verificar después del
deploy que el rewrite conserva Cookie, Set-Cookie, Origin/Referer y X-XSRF-TOKEN.
El frontend vecino ya configura withCredentials y withXSRFToken.

Los emails de recuperación usan FRONTEND_URL y Fortify también la usa como
destino; APP_URL sirve de base para enlaces generados fuera de una petición.
Los recursos de fotos aún usan `asset('storage/...')` y conservan deuda de URL:
el prefijo /backend y el host efectivo deben validarse al desplegar. No se
garantiza todavía que todos los enlaces de fotos pasen por el proxy.

## Riesgos y límites pendientes

- `storage/app/public/avatars` contiene fotos que deben sobrevivir despliegues.
  El enlace público resuelve su acceso local, pero Render Free no las persiste.
  No se implementó almacenamiento externo.
- Sesiones file, caché file y temporales pueden perderse al reemplazar la
  instancia; la demo admite volver a iniciar sesión. No usar múltiples réplicas
  con sesiones locales. CSV se procesa en temporales; los previews/resultados
  de aulas, materias y ofertas se guardan en PostgreSQL, no como CSV persistente.
- No se detectó almacenamiento de documentos persistentes adicional en el
  código revisado. Los registros de negocio y auditoría viven en PostgreSQL.
- Render Free se suspende tras 15 minutos sin tráfico y puede tardar alrededor
  de un minuto en reactivarse. Su filesystem es efímero. Neon puede añadir
  latencia al reactivar el compute.
- MAIL_MAILER=log no entrega emails y puede registrar enlaces de recuperación
  con tokens: para cuentas reales configurar SMTP y restringir acceso a logs.
  Render Free bloquea salida a los puertos SMTP 25, 465 y 587: para correo real
  hace falta un puerto alternativo admitido por el proveedor o un transporte
  por HTTPS soportado y configurado con sus dependencias. No se añadió ninguno.
- No se encontraron APP_KEY reales, URLs de BD con credenciales, claves
  privadas ni tokens de proveedor en los patrones de `git grep` del árbol
  versionado actual. `.env` local está ignorado y no se leyó. Sí existen
  contraseñas conocidas de demo/prueba en seeders, factories y documentación
  (valores censurados en el reporte). No se reescribió el historial de Git.
- Al 24 de octubre de 2026, Laravel 11 ya terminó su soporte de seguridad
  (12 de marzo de 2026). Se conserva por la restricción explícita del proyecto.
  composer.json contiene excepciones de bloqueo para tres avisos de seguridad;
  no se realizó una auditoría online de dependencias ni se certifica su ausencia.

El repositorio queda **LISTO PARA CREAR EL WEB SERVICE EN RENDER**, sujeto al
build y a las comprobaciones reales posteriores. No equivale a producción ya
validada: faltan secretos, migraciones manuales, el destino Render de Vercel y,
si se requiere esa demo específica, el Sprint2DemoSeeder ausente.

Referencias oficiales: [Render Docker](https://render.com/docs/docker),
[puerto y TLS](https://render.com/docs/web-services),
[límites Free](https://render.com/docs/free),
[Neon](https://neon.com/docs/connect/connect-from-any-app),
[variables libpq](https://www.postgresql.org/docs/current/libpq-envars.html),
[expansión de variables Apache](https://httpd.apache.org/docs/2.4/configuring.html),
[soporte PHP](https://www.php.net/supported-versions.php),
[soporte Laravel 11](https://laravel.com/docs/11.x/releases).
