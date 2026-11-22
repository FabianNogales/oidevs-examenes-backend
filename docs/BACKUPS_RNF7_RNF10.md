# RNF7 / RNF10 — Drive privado y descarga integrada

Refactorización: 21 de noviembre de 2026. **Preparado en código, desactivado y sin
despliegue. No se ha configurado OAuth, Drive, GitHub, Cloudflare o D1, ni ejecutado
migraciones, respaldos, restauraciones, instalaciones, builds o pruebas.**

Google Drive sustituye a Supabase/S3 solo para respaldos. Se adapta el módulo
existente. El límite anterior de 50 MB desaparece sin otro límite artificial.

## Arquitectura y núcleo conservado

~~~text
Generación:
React → Laravel autoriza y registra → workflow_dispatch (solo UUID)
→ runner: TLS/versión real/pg_dump -Fc/pg_restore --list
→ Drive v3 privado: resumable + checksums + lectura SHA256
→ manifiesto → callback HMAC → Laravel verifica → AVAILABLE

Descarga:
React → POST Laravel /admin/backups/{uuid}/download-ticket (Sanctum + CSRF)
→ ticket HS256, 60 segundos, jti único → formulario POST /download en Worker
→ firma/claims/tiempo → INSERT único D1 → OAuth servidor
→ metadata Drive privada → response.body → navegador
~~~

Se conservan Backup, UUID y nombre histórico, BackupService, BackupExecutor,
GithubBackupExecutor, BackupStorage, estados, HMAC/idempotencia, recibos, auditoría,
roles, concurrencia PostgreSQL, scheduler, comandos, pg_dump y UI administrativa.
S3BackupStorage se reemplaza por DriveBackupStorage. La firma es responsabilidad
separada de BackupDownloadTickets; temporaryUrl deja el contrato de almacenamiento.

La migración original sigue pendiente y se adapta en el mismo archivo, sin una
migración Laravel adicional. No se modifica una base existente. Si la premisa de
migración pendiente cambia, preparar posteriormente una migración revisada y nunca
resetear producción.

El repositorio conserva Docker/Apache y DEPLOY_RENDER.md para Render/Neon; el
contexto funcional menciona Vercel/Railway. No se consultaron paneles ni se cambió el
despliegue. Confirmar el proveedor efectivo, sesiones/caché compartidas y tiempos
máximos del runtime. Laravel nunca ejecuta pg_dump ni recibe el dump. El dispatch
tiene timeout de 8 s y los checks pequeños de Drive tienen timeout de 8 s por
petición; revisar presupuesto total del callback y del endpoint de ticket.

Para migrar a un laboratorio Linux, implementar BackupExecutor y cambiar su binding;
reutilizar Python/Docker/OAuth/callback. No se instala un proceso residente.

## Tabla, estados y seguridad

Migración pendiente: database/migrations/2026_11_21_000001_create_backups_tables.php.

backups conserva UUID; solicitante nullable con SET NULL y nombre histórico; tipo;
estado; filename; bytes; SHA256; versión PostgreSQL; ID de ejecución; fechas de
solicitud/inicio/finalización/verificación/plazo; código seguro y active_slot único.
Nuevos campos: storage_provider=google_drive, storage_file_id, manifest_file_id,
storage_parent_id, storage_version, md5_checksum nullable, integrity_method.
Se eliminan storage_key/object_etag exclusivos del proveedor anterior. Nunca se
guardan OAuth, secretos, passwords, conexiones o URLs temporales.

backup_callback_receipts conserva nonce único, digest del cuerpo, UUID y fecha.
Los índices de estado/fecha/solicitante/plazo y las referencias únicas se mantienen.
La FK de auditoría existente puede restringir borrado de usuarios; no se altera.
Desactivar o eliminar eventualmente una cuenta no elimina su metadata histórica.

QUEUED → RUNNING → UPLOADING → AVAILABLE. Las primeras tres admiten FAILED.
AVAILABLE/FAILED son terminales. active_slot=1 único y AdministratorOperation
impiden respaldos simultáneos entre administradores e instancias. Tres solicitudes
por hora se comprueban también dentro del bloqueo de base. Se conservan límites
separados de caché: 60 operaciones/min, 20 tickets/min y 60 callbacks/min por IP.
En serverless, usar caché compartida para los límites de lectura/callback.

Plazo absoluto para callbacks: 60 minutos. Workflow: 30 minutos y límites por
proceso. Reconciliación en listado/detalle/solicitud/callback y backups:reconcile.
Ahora el vencimiento solo inicia la comprobación: para liberar active_slot se
exige confirmar en GitHub que la ejecución terminó; ante estado activo o
desconocido se conserva el bloqueo. El scheduler existente declara cada cinco
minutos, pero solo corre si se opera ese scheduler: no se configuró cron externo.
Un trabajo vencido no puede reclamar ni revivir una fila.
Dispatch rechazado definitivamente produce FAILED; una respuesta ambigua conserva
QUEUED/DISPATCH_UNCONFIRMED sin redispatch ciego. Los reruns no vuelven a reclamar.

Recuperación administrativa antes del plazo: POST /admin/backups/{uuid}/recover.
Reutiliza la transición verificada de reconciliación y registra BACKUP_RECOVERED
en la misma transacción. Conserva autenticación, sesión, contraseña y rol base,
y no inicia ni cancela ejecuciones. Véase [procedimiento y límites](BACKUPS_RECOVERY.md).

Se reutilizan auth:sanctum, session.current, password.changed,
EnsureActiveAdministrator y EnsureBackupAdministrator: ADMINISTRADOR con
is_custom=false. Sin delegaciones nuevas. SEC-01/SEC-02 y restricciones móviles se
conservan. Solo el callback externo omite stateful/CSRF y exige su propia HMAC.

## Google Cloud, cuenta, consentimiento y OAuth offline

Configuración manual pendiente:

1. Elegir cuenta institucional dedicada y capacidad de My Drive. El adaptador
   exige ownedByMe=true, shared=false y archivos no eliminados. No admite carpetas
   compartidas, Shared Drives o enlaces públicos.
2. Crear/revisar proyecto Google Cloud y habilitar Drive API v3.
3. Configurar OAuth consent screen: identidad/contactos/audiencia, usuarios de prueba
   o publicación, políticas Workspace y aprobaciones institucionales.
4. Crear cliente OAuth confidencial. Autorizar únicamente scope
   https://www.googleapis.com/auth/drive.file con access_type=offline y cuando
   proceda prompt=consent. Usar código de autorización, redirect URI registrada,
   state validado y tratamiento seguro del código. No implementar login Google en
   la descarga de EIDA.
5. Obtener refresh token por un procedimiento administrativo seguro y guardarlo en
   bóveda. Backend, runner y Worker usan la misma aplicación OAuth y cuenta; sus
   refresh tokens pueden emitirse para esa misma aplicación. No enviarlos como
   parámetros de dispatch, argumentos de shell o valores versionados.
6. Crear una carpeta mediante esa aplicación. Como alternativa futura, implementar
   autorización Google Picker, marcarla como raíz administrada y verificarla.
   drive.file no concede acceso automático a una carpeta manual.

Helper preparado, nunca ejecutado automáticamente, solo tras autorización y con
BACKUP_DRIVE_CLIENT_ID, BACKUP_DRIVE_CLIENT_SECRET y BACKUP_DRIVE_REFRESH_TOKEN
inyectados desde un gestor seguro:

~~~bash
python scripts/backups/create_drive_folder.py --create-private-folder
~~~

Preasigna ID, crea carpeta marcada appProperties.eida_backup_root=1, comprueba
privacidad y muestra solo PRIVATE_FOLDER_ID. Si se pierde la respuesta, revisar
antes de repetir; no crear carpetas automáticamente. Configurar el mismo ID en
los tres entornos. No hay una carpeta ficticia de fallback.

No se solicitan scopes globales drive/drive.readonly. Los archivos deben ser
creados/autorizados a esa aplicación y llevan appProperties de UUID, run y tipo.
Los access tokens se renuevan y quedan en memoria. Backend/runner renuevan una vez
ante 401. invalid_grant se trata como OAUTH_REVOKED. La revocación/caducidad requiere
revisión operativa, nunca login Google del administrador de EIDA.

Una app OAuth externa en Testing con este scope puede emitir refresh tokens de
siete días. Revisar publicación/verificación o uso interno según política Google.
No tratar credenciales de prueba como permanentes ni emitir tokens repetidamente
sin control: existen límites y pueden invalidarse tokens anteriores.

## Ejecutor, subida reanudable y manifiesto

Se conservan claim antes de conectar, TLS y pg_stat_ssl, descubrimiento real de
server_version_num, Docker con cliente pg_dump de la misma versión mayor y
verificación --version (rango explícito 14–19). No se supone PostgreSQL 15.10.
pg_dump --format=custom es completo: no excluye tablas, datos ni fotografías.
pg_restore --list verifica estructura, sin restaurar. Permisos/RLS insuficientes
fallan cerrado. Se limpian temporales y contenedores incluso al fallar.

drive_storage.py usa biblioteca estándar Python, sin boto3 ni SDK Google.
requirements.txt lo documenta; el workflow prepara venv sin pip install.
generateIds preasigna dos IDs reales, se comprueba ausencia del UUID y se inicia
uploadType=resumable. El dump usa application/octet-stream y el manifiesto JSON;
nunca se convierten a Docs.

Chunks de 8 MiB, múltiplos de 256 KiB, se reensamblan en un único archivo Drive.
No se fragmenta el respaldo en archivos independientes. Tras 308 se usa Range
confirmado; ante respuesta ambigua se consulta la misma sesión con
Content-Range: bytes */total. Solo se continúa desde progreso confirmado, con
reintentos acotados. No se crean IDs nuevos o sobrescriben archivos para reintentar.
Iniciación ambigua, sesión vencida, ausencia de progreso y duplicados fallan
cerrado. No se reinicia un POST de creación a ciegas.

El runner calcula SHA256 y MD5 local, compara tamaño y checksums nativos cuando
existen y siempre relee el binario remoto por streaming para verificar SHA256.
El manifiesto identifica UUID, filename, proveedor, carpeta, ambos IDs, versión,
run, bytes, SHA256, MD5, formato, pg_restore_verified y readback_sha256.
Se recupera/compara el manifiesto y se verifica de nuevo la versión/privacidad.

Laravel valida metadata privada, carpeta autorizada, nombres/MIME, parents exacto,
owner, shared/trashed/isAppAuthorized, canDownload, appProperties, tamaño, versión,
checksums nativos y manifiesto máximo 8192 bytes. Relee versiones para detectar
cambios. No se certifica disponibilidad por dispatch o subida parcial.
Fallos temporales/rate limit permiten retry del callback sin consumir su nonce.

Drive expone sha256Checksum cuando está disponible para binarios. MD5 no equivale
a SHA256: es una comparación adicional. DRIVE_SHA256_AND_READBACK registra ambos
checks; READBACK_SHA256 conserva evidencia del ejecutor si falta SHA nativo.
Laravel/Worker no hashean de nuevo el dump completo: en el fallback confían en el
ejecutor HMAC, el manifiesto y la versión ligada. Comprometer juntos OAuth,
ejecutor y firmas rompe esa confianza. Drive no es WORM; la aplicación nunca
actualiza o borra respaldos, pero la cuenta/OAuth con escritura podría hacerlo.
No hay retención ni borrado automático. Inventariar huérfanos de fallos ambiguos
y revisar manualmente; no borrarlos sin política autorizada.

## API y callback

Prefijo /api/v1:

| Método | Ruta | Contrato |
| --- | --- | --- |
| GET | /admin/backups | data/links/meta paginados y summary global |
| POST | /admin/backups | 202 UUID/estado; no espera dump |
| GET | /admin/backups/{uuid} | estado y metadata sanitizada |
| POST | /admin/backups/{uuid}/download-ticket | ticket para formulario Worker |
| POST | /backup-executor/callback | HMAC y transición verificada |

Se retira GET /download de S3. El nuevo POST conserva sesión Sanctum, CSRF, cuenta
activa, password cambiado y rol base; exige AVAILABLE y comprueba Drive.
Historial no expone IDs Drive, carpetas, OAuth, tickets o URLs.
summary.recorded_bytes representa dumps AVAILABLE registrados, no consumo total
de Drive/manifiestos/huérfanos. Los errores API 401/403/404/409/419/422/429/500/503
se conservan, con códigos cerrados de OAuth/cuota/permisos/falta/integridad.

Callback conserva HMAC SHA256 sobre timestamp + salto + nonce + salto + POST +
salto + /api/v1/backup-executor/callback + salto + cuerpo original. Ventana 300 s,
nonce persistente, cuerpo máximo 16 KiB, comparación constante. Repetición idéntica
es idempotente; cambio de cuerpo/UUID con nonce igual es 409. Evidencia corrupta
repetida nunca confirma AVAILABLE. Run y terminalidad siguen vinculados.

AVAILABLE exige storage_provider=google_drive, file_id, manifest_id, parent_id,
storage_version, bytes, sha256, integrity_method, versión PostgreSQL y run.
MD5 es adicional nullable. Todo se contrasta con Drive/manifiesto. No acepta
storage_key/ETag como evidencia.

## Tickets y Worker

Worker independiente: infra/backup-download-worker. No se despliega con Laravel.
JWT HS256 con header alg/typ/kid fijos y claims jti, backup UUID, file_id, carpeta,
nombre/tamaño/hash/versión, iat/exp/iss/aud. TTL 60 s. Secreto aleatorio separado
del callback, mínimo 32 caracteres ASCII, compartido solo Laravel/Worker.
El ticket está firmado, no cifrado: la referencia Drive es legible al decodificarlo,
como exige el contrato, pero nunca se muestra en UI y no es una credencial Google.

Worker solo acepta formulario POST /download, sin query/file_id libre ni Range.
Verifica firma/algoritmo/kid/issuer/audience/tiempo/claims antes de Drive.
D1 usa INSERT con jti PRIMARY KEY y ON CONFLICT DO NOTHING; solo changes=1 continúa.
No hay SELECT previo ni confianza en IP/Origin/Referer. Las escrituras van al
primario y la unicidad resuelve replay concurrente. D1 caído/incierto falla cerrado
antes de OAuth. Replay: 409; expirado: 410.

D1 guarda exclusivamente jti/expiración/consumo, sin dumps, OAuth o metadata Drive.
Un fallo Google posterior deja el ticket consumido. Solicitar uno nuevo en EIDA.
Purga manual de expirados + margen (por ejemplo diez minutos) es segura tras
autorización; no se configura cron ni se eliminan respaldos.

Worker renueva OAuth servidor, verifica metadata, abre files/{id}?alt=media con
host/API fijos y vuelve a comprobar versión antes de devolver el stream. Valida
Content-Length cuando existe. Devuelve response.body sin arrayBuffer/Blob del
dump, caché pública o R2. Cabeceras: attachment, application/octet-stream,
private/no-store, nosniff y no-referrer.

El TTL limita inicio, no corta una transferencia ya autorizada. No se reutilizan
tickets/rangos para reanudar: una interrupción requiere ticket nuevo y descarga
nueva. Tras enviar headers no siempre es posible mostrar un error si el stream se
corta; verificar SHA256 del archivo recibido. La emisión de ticket se audita con
UUID/jti, sin JWT/URL; no se afirma descarga completa. D1 permite correlacionar
consumo con emisión, no certificar recepción completa del navegador.

Errores Worker son HTML accesible y códigos seguros enviados al opener EIDA.
El origen solo sirve para feedback, nunca autorización. React valida origen y
ventana de esos mensajes. No registrar cuerpo del POST, JWT o cabeceras OAuth;
observabilidad se deja deshabilitada.

## Variables, secrets y configuración pendiente

### Node institucional y Worker externo

El objetivo institucional del proyecto principal sigue siendo Node.js 20. Esta
revisión no modifica package.json, package-lock.json ni requisitos/dependencias
del frontend o backend principales. Sus scripts no ejecutan Wrangler. Laravel
usa PHP y el ejecutor usa Python/Docker; backups no les añade Node 22.

El paquete infra/backup-download-worker es independiente: sus herramientas/tests
requieren Node >=22.13 por node:sqlite sin flag. Producción corre en Cloudflare
workerd. El workflow manual backup-download-worker.yml usa Node 24 en GitHub
Actions (cumple Node 22+) y directorio de trabajo exclusivo del Worker; no cambia
el Node de servidores del cliente ni ejecuta builds EIDA. La carpeta del Worker
se excluye además del contexto Docker institucional.

CI exige package-lock.json propio del Worker, todavía pendiente de generar/revisar
con autorización; nunca usa el lock raíz. Después usa npm ci, typecheck y tests
simulados. El despliegue es opt-in: input deploy=false por defecto, validación
previa, rama protegida backup-execution, Environment eida-backup-download y variable
de repositorio EIDA_BACKUP_WORKER_DEPLOY_ENABLED=true. Solo el paso deploy recibe
CLOUDFLARE_ACCOUNT_ID/CLOUDFLARE_API_TOKEN. No crea D1 ni aplica sus migraciones o
runtime secrets. Todo sigue pendiente de configuración; no se ejecutó el workflow.

**Incompatibilidades preexistentes**, confirmadas también en los locks de HEAD
anteriores a los cambios de backups:

| Proyecto/paquete | engines.node declarado | Implicación para Node 20 |
| --- | --- | --- |
| Frontend Vite 8.2.2 y plugin React 6.1.1 | ^20.19.0 o >=22.12.0 | Requieren al menos 20.19 dentro de Node 20 |
| Frontend ESLint 10.9.1 | ^20.19.0 o ^22.13.0 o >=24 | Requiere 20.19+ dentro de Node 20 |
| Ambos: peer @zxing/library 0.23.0 de @zxing/browser 0.2.1 | >=24.0.0 | No permite certificar compatibilidad integral del árbol con Node 20 |
| Backend: @napi-rs/lzma-linux-x64-gnu 1.5.1, dev/opcional de Rollup | ^22.20 o ^24.12 o >=25 | Excluye Node 20; revisar impacto del tooling opcional Linux x64 |

Son declaraciones de engines, no resultados de builds. engine-strict puede rechazar
el árbol; una instalación con advertencias tampoco acredita compatibilidad. No
se cambian versiones ni se eleva Node institucional para resolverlas. Hace falta
una revisión de dependencias principal separada y autorizada. @types/node 24 aporta
tipos y no exige por sí solo runtime Node 24. El Dockerfile Laravel usa PHP/Composer,
sin ejecutar npm/Node: conflictos npm no implican requisito Node 22 del runtime PHP.

Referencias oficiales: [Node SQLite](https://nodejs.org/download/release/v22.13.0/docs/api/sqlite.html),
[Vite 8](https://vite.dev/blog/announcing-vite8),
[Wrangler](https://developers.cloudflare.com/workers/wrangler/install-and-update/) y
[CI Workers](https://developers.cloudflare.com/workers/ci-cd/external-cicd/github-actions/).

Laravel conserva ENABLED=false, EXECUTOR=github, GITHUB_REPOSITORY,
GITHUB_REF=backup-execution, GITHUB_TOKEN y CALLBACK_SECRET con prefijo
EIDA_BACKUPS_. Nuevas variables en .env.example:

~~~dotenv
EIDA_BACKUPS_DRIVE_CLIENT_ID=
EIDA_BACKUPS_DRIVE_CLIENT_SECRET=
EIDA_BACKUPS_DRIVE_REFRESH_TOKEN=
EIDA_BACKUPS_DRIVE_FOLDER_ID=
EIDA_BACKUPS_DOWNLOADS_ENABLED=false
EIDA_BACKUPS_DOWNLOAD_WORKER_URL=
EIDA_BACKUPS_DOWNLOAD_SIGNING_SECRET=
EIDA_BACKUPS_TICKET_ISSUER=
EIDA_BACKUPS_TICKET_KEY_ID=
~~~

Worker URL: HTTPS exacto terminado en /download, sin credenciales/query/fragmento.
Issuer/kid/carpeta/cuenta coinciden entre componentes. Guardar valores en gestores
de secretos, nunca VITE_. Mantener APP_DEBUG=false, APP_KEY estable y SEC-01/02.
No requiere paquetes PHP nuevos: HTTP Laravel existente. Se retira solo sugerencia
Composer S3, sin alterar requisitos/lock o disco S3 general.

GitHub conserva .github/workflows/database-backup.yml, solo UUID, rama protegida
backup-execution, Environment eida-backups restringido a esa rama, contents:read,
checkout por SHA, concurrencia y timeout. Workflow debe existir en default branch
y rama de ejecución. Token Laravel fine-grained: solo repositorio y Actions:write,
con caducidad/rotación. Se conservan secrets CALLBACK_URL/CALLBACK_SECRET y
PGHOST/PGPORT/PGDATABASE/PGUSER/PGPASSWORD/PGSSLMODE/PG_CA_PEM con prefijo EIDA_BACKUPS_.
Se reemplazan secrets S3 por DRIVE_CLIENT_ID/DRIVE_CLIENT_SECRET/DRIVE_REFRESH_TOKEN/
DRIVE_FOLDER_ID con ese mismo prefijo. No borrar secrets S3 legítimos de otros módulos.
No hay pip install o GitHub Artifacts. Revisar/fijar digests Docker antes de activar.

Worker: wrangler.toml deja ENABLED=false, database_id vacío, workers_dev/preview
deshabilitados y ningún dominio inventado. Configurar con autorización cuenta,
hostname/ruta HTTPS real, D1 y binding TICKETS. Aplicar su migración
migrations/0001_consumed_tickets.sql separadamente de Laravel.

Variables Worker: TICKET_ISSUER, TICKET_KEY_ID, TICKET_AUDIENCE=eida-backup-download,
DRIVE_FOLDER_ID, APP_ORIGIN (origen HTTPS exacto de EIDA solo para feedback).
Workers Secrets: DOWNLOAD_SIGNING_SECRET, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET,
GOOGLE_REFRESH_TOKEN. No guardar valores en wrangler.toml o .dev.vars versionados.

Comandos de referencia futuros, **no ejecutados**, desde el directorio Worker:

~~~bash
# Solo tras autorizar instalación/creación/configuración y revisar lock:
npm install
wrangler d1 create eida-backup-download-tickets
# Copiar ID real en wrangler.toml y revisar:
wrangler d1 migrations apply eida-backup-download-tickets --remote
wrangler secret put DOWNLOAD_SIGNING_SECRET
wrangler secret put GOOGLE_CLIENT_ID
wrangler secret put GOOGLE_CLIENT_SECRET
wrangler secret put GOOGLE_REFRESH_TOKEN
# Configurar hostname/ruta real y habilitación revisada antes de:
wrangler deploy
~~~

No añadir login Access interactivo delante del POST si rompe descarga integrada.
Revisar CSP form-action del frontend para permitir solo el Worker autorizado;
no agregar comodines o dominios ficticios. No se cambia CSP productiva aquí.

## Despliegue, primer respaldo y recuperación

1. Confirmar proveedor real y migración original pendiente.
2. Configurar OAuth/cuenta/carpeta con autorización y revisar privacidad/cuotas.
3. Ejecutar pruebas aisladas cuando se autoricen; después autorizar migración
   Laravel y despliegues separados backend/frontend/Worker, D1 y secrets.
4. Probar expiración/replay concurrente/D1 caído/archivo eliminado/OAuth revocado
   con datos de prueba. Revisar auditoría, ventanas, errores y streaming.
5. Solo con autorización del primer respaldo real, habilitar banderas y confirmar
   en EIDA. Observar estados y manifiesto; 202 no equivale a respaldo disponible.
6. Descargar, verificar hash y ensayar restauración aislada antes de aceptación.

Si Railway o Laravel desaparecen, operadores autorizados recuperan dump y
manifiesto directamente de la carpeta privada de la cuenta dedicada, mediante
Drive o credenciales de emergencia en equipo seguro. No depende del login o tabla
de la aplicación caída. Esto es acceso administrativo de emergencia; las
descargas normales de EIDA no exigen iniciar sesión en Google.

Verificar localmente con Get-FileHash -Algorithm SHA256 o sha256sum y comparar
tamaño/hash con manifiesto. Helper offline preparado, sin conexión a bases:

~~~bash
python scripts/backups/verify_backup.py --dump /ruta/archivo.dump --manifest /ruta/archivo.dump.manifest.json
~~~

Solo calcula hash/tamaño e inspecciona pg_restore --list. No restaura. Conservar
original, inspeccionar origen confiable, preparar versiones/extensiones/roles
compatibles y una base temporal aislada. Con autorización para ese entorno:

~~~bash
pg_restore --exit-on-error --single-transaction --dbname=eida_restore_check archivo.dump
~~~

Nunca passwords en argumentos ni restauración sobre producción sin autorización.
Verificar tablas, datos, secuencias, FK, auditoría, roles/permisos de aplicación,
fotos y reglas de negocio; --list no sustituye ese ensayo.

El dump mantiene todas las fotos actuales en PostgreSQL. Incluye roles/permisos
de aplicación guardados en tablas, no los roles globales/tablespaces del clúster.
No incluye archivos externos, APP_KEY, secretos, DNS/despliegue o binarios de
extensiones; conservarlos aparte en bóveda. Decidir roles destino o --no-owner
--no-acl y reconstrucción explícita de ACL. Planificar RPO manual, credenciales,
sesiones/tokens y corte al proveedor de reemplazo por separado.

La metadata restaurada puede mostrar este mismo respaldo RUNNING porque el
snapshot precede al callback. El manifiesto externo permite identificarlo sin
Railway. Conciliar vencidos en la copia recuperada; no importar manifiestos o
apuntar a producción automáticamente.

## Cuotas y costes

No hay límite EIDA de 50 MB. Drive impone capacidad, permisos y cuotas según cuenta
y proyecto. Revisar consola vigente: Google cambió cuotas en 2026 y pueden depender
de la fecha del proyecto; revisar también condiciones de cobro sobre umbrales.
No asumir antiguos límites por minuto o coste cero. Readback agrega transferencia
completa; GitHub consume minutos/disco, PostgreSQL CPU/E/S/egreso y Google API/red.
El timeout del workflow limita duración, sin truncar/dividir el dump.

Workers Free publica 100 000 solicitudes/día, 128 MB y 10 ms de CPU; medir
HMAC/JSON/D1/OAuth antes de afirmar que Free basta. Streaming evita depender del
tamaño total en memoria. Workers no impone límite de tamaño de respuesta, pero
CPU/conexiones/runtime/desconexiones y planes siguen aplicando. D1 limita/factura
filas e índices leídos/escritos y almacenamiento: agotamiento falla cerrado.
Paid puede añadir costes. Definir cuotas, alertas y capacidad antes de habilitar.

Fuentes oficiales consultadas:

- [Scopes Drive](https://developers.google.com/workspace/drive/api/guides/api-specific-auth),
  [OAuth offline](https://developers.google.com/identity/protocols/oauth2/web-server),
  [caducidad](https://developers.google.com/identity/protocols/oauth2#expiration).
- [Resumable](https://developers.google.com/workspace/drive/api/guides/manage-uploads),
  [checksums/metadata](https://developers.google.com/workspace/drive/api/reference/rest/v3/files),
  [cuotas Drive](https://developers.google.com/workspace/drive/api/guides/limits).
- [D1 statements](https://developers.cloudflare.com/d1/worker-api/prepared-statements/),
  [consistencia](https://developers.cloudflare.com/d1/best-practices/read-replication/),
  [precios D1](https://developers.cloudflare.com/d1/platform/pricing/).
- [Límites Workers](https://developers.cloudflare.com/workers/platform/limits/),
  [precios Workers](https://developers.cloudflare.com/workers/platform/pricing/).

## Informe de archivos, pruebas y Git

Backend conservado: Backup, BackupExecutor, GithubBackupExecutor, BackupFailure,
controladores administrativo/callback, autenticación/base-admin, estados,
auditoría, rutas y scheduler. Adaptados: BackupStorage, BackupService,
BackupCallbackRequest, BackupServiceProvider, config/backups.php, .env.example,
migración original y contexto permitido de auditoría. Nuevos DriveClient,
DriveBackupStorage, BackupDownloadTickets y pruebas. Se retiran únicamente
S3BackupStorage y su prueba específica, reemplazada por DriveBackupStorageTest,
el disco backups S3 y la sugerencia opcional Composer. Disco S3 general y locks
de dependencias conservados.

Worker nuevo: src/index.ts, wrangler.toml, package.json, tsconfig.json, .gitignore,
migración SQL, tests/worker.test.mjs y README, bajo infra/backup-download-worker.
Endpoint POST /download; D1 solo consumos; secrets/configuración/despliegue pendientes.

Runner: executor.py conserva dominio pg_dump/claim/HMAC/limpieza, reemplaza upload
por drive_storage.py y backup_errors.py. requirements.txt sin paquetes externos.
Helpers manuales create_drive_folder.py y verify_backup.py; test_executor.py
adaptado y test_recovery.py nuevo. Workflow conserva estructura, reemplaza secretos
y elimina pip/boto3/S3.

Frontend: se conservan página/CSS/ruta/router/menú/componentes. Solo Descargar usa
el API POST nuevo y backupDownload.ts (formulario/ventana/errores) con limpieza de
listeners. Se añade downloadTicket.integration.test.mjs; se conserva
backups.integration.test.mjs. Sin dependencias web nuevas o credenciales Google.
Sin cambio de diseño, tabla, confirmación, polling o indicadores. QA visual sigue pendiente.

Pruebas focalizadas con conexiones externas simuladas. El usuario reportó una
ejecución inicial fallida de PHP/Python; véase la corrección al final. El agente
no ejecuta pruebas en esta entrega:

~~~bash
php.exe vendor/bin/phpunit -c phpunit.offline.xml --testsuite Backups --filter='ManualBackupTest|DriveBackupStorageTest|DriveClientTest|BackupDownloadTicketsTest|GithubBackupExecutorTest'
python -m unittest discover -s scripts/backups -p 'test_*.py'
# En frontend:
node --test src/features/backups/tests/backups.integration.test.mjs src/features/backups/tests/downloadTicket.integration.test.mjs
# Solo Worker externo/GitHub Actions, después de instalar con autorización; Node >=22.13 (CI: 24):
npm test
~~~

Se preservan autorización, roles, cuenta/contraseña, concurrencia, límites,
estados, dispatch, HMAC/replay, reconciliación, historial y auditoría. Se añaden/
adaptan checksums/tamaño/privacidad/ausencia/manifiesto, OAuth/cuota/permisos,
resumable ambiguo, firma/expiración, replay simultáneo con unicidad SQLite
simulando D1, D1 caído, streaming, POST navegador y recuperación offline sin Railway.
Feature tests mantienen guardas SQLite :memory:/sesión/caché aisladas. No usar
.env productiva. Tests simulados no sustituyen integración con D1 real.

Solo comprobaciones estáticas locales. Pendientes: suites focalizadas autorizadas,
instalación/lock Worker, validación Wrangler/D1/Drive, QA visual/accesibilidad/
capturas, primer respaldo y restauración aislada. No afirmar cumplimiento operativo
de RNF7/RNF10 hasta completar estas actividades.

Resultados estáticos: PHP -l correcto en PHP nuevos/afectados; AST Python correcto;
JSON y Wrangler TOML parseables; TypeScript de frontend y Worker sin diagnósticos;
ESLint del módulo web sin errores; Pint en modo de revisión sobre PHP nuevos
aprobado; Prettier y git diff --check correctos. No se ejecutan archivos de pruebas,
ni validación remota de YAML/GitHub o Wrangler. Las comprobaciones de formato/tipos
no acreditan integración funcional ni disponibilidad de infraestructura.

Git inicial de la refactorización: ambos main, sin staged. Backend HEAD
04dcb7f4f17c1058077e2020a920b26e0e3c929e, 7 tracked modificados y 25 untracked.
Frontend HEAD cbcbbd91b3a72a5387c681bbdac18ace02afcf2e, 2 tracked modificados y
5 untracked. Se inspeccionaron antes de editar.

Final: mismas ramas/HEAD y sin cambios de índice. Se preserva trabajo local previo;
los componentes S3 exclusivos se sustituyen según esta solicitud. Cambios
unstaged/untracked. SEC-01/SEC-02, móvil, APK, -compare y otros proyectos no se
modifican. Sin add/commit/push/reset/clean/stash/rebase/merge ni despliegue.
Inventario final: backend 6 tracked modificados y 42 untracked; frontend 2 tracked
modificados y 7 untracked. La configuración del disco general vuelve a su estado
base al retirar solo el disco S3 exclusivo de backups.

## Corrección focalizada de descubrimiento y mocks

El usuario reportó una primera ejecución con tres fallos. Las correcciones
siguientes quedan pendientes de ejecución por el usuario; no se declaran pruebas
aprobadas ni se ejecutan migraciones o conexiones externas en esta corrección.

- PHPUnit: phpunit.offline.xml solo incluía tests/Feature/Offline. El filtro de
  clases no incorpora directorios fuera de la suite. Se añade la suite Backups
  limitada a tests/Feature/Backups y tests/Unit/Backups, conservando la suite Offline
  y todas las variables forzadas: testing, DB_URL vacío, SQLite :memory:, sesión y
  caché array. Nombres, namespaces y atributos DataProvider son compatibles con
  PHPUnit 10 del proyecto; no se cambian para resolver el descubrimiento.
- Python: el mock genérico rechaza atributos assert* no especificados. Se usa
  autospec de la clase real DriveClient para reconocer assert_private sin unsafe
  ni cambios del método productivo. La prueba conserva contenido remoto del mismo
  tamaño y hash distinto, exige INTEGRITY_MISMATCH y verifica que solo se haya
  subido el dump, nunca un manifiesto certificador.
- Windows: construir el argumento --user llamaba os.getuid/getgid antes del comando
  simulado. La prueba simula ambas funciones con create=True dentro de su contexto,
  aísla variables y comprueba UID/GID, red host, PGSSLMODE y consulta pg_stat_ssl.
  SSL falso sigue rechazado antes de pg_dump. No se añade soporte productivo Docker
  Windows ni se cambia el ejecutor Linux, OAuth, SHA, estados, frontend o Worker.

Archivos modificados en esta corrección: phpunit.offline.xml,
scripts/backups/test_executor.py y este documento. Comandos focalizados para
repetir desde la raíz del backend (no ejecutados por el agente):

~~~powershell
php.exe vendor/bin/phpunit -c phpunit.offline.xml --testsuite Backups --filter='ManualBackupTest|DriveBackupStorageTest|DriveClientTest|BackupDownloadTicketsTest|GithubBackupExecutorTest'
python -B -m unittest discover -s scripts/backups -p 'test_*.py'
~~~

La invocación original con -c phpunit.offline.xml y el mismo --filter también
incluye ahora los archivos de Backups; --testsuite Backups acota el descubrimiento.
-B evita generar o actualizar bytecode local; no altera las comprobaciones.
No se usa phpunit.xml general como sustituto de la configuración aislada.

Git al comenzar esta corrección: main, HEAD
04dcb7f4f17c1058077e2020a920b26e0e3c929e; 54 rutas staged, ninguna unstaged o
untracked. Se conservan íntegros el índice, los cambios preparados y los pyc ya
preparados por el usuario. Solo las tres correcciones anteriores quedan unstaged,
sin commit, push, cambio de rama o despliegue.

Revisión de esta corrección: AST Python y esquema XML de PHPUnit válidos;
configuración de aislamiento forzada conservada y cinco archivos Backups incluidos
estáticamente. Esto no equivale a pruebas aprobadas: no se ejecutó PHPUnit ni unittest.
