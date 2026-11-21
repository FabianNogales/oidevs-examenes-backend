# Descarga privada de respaldos EIDA

Worker independiente, desactivado y no desplegado. Endpoint previsto: POST
`/download`, formulario urlencoded con `ticket`. No hay hostname operativo todavía.

Valida HS256/kid/issuer/audience/TTL; consume jti con INSERT único D1 antes de OAuth;
verifica metadata privada Drive y transmite response.body. Sin R2, Blob, rangos,
buffer completo, login Google del navegador o caché pública. D1 caído falla cerrado.

Configurar con autorización: cuenta/aplicación/carpeta drive.file compartidas con
backend/runner, D1 y migración SQL, Secrets, variables y hostname/ruta real.
wrangler.toml deja database_id vacío y ENABLED=false. No se ejecutó Wrangler.

Secrets: DOWNLOAD_SIGNING_SECRET, GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET,
GOOGLE_REFRESH_TOKEN. Variables: TICKET_KEY_ID, TICKET_ISSUER,
TICKET_AUDIENCE=eida-backup-download, DRIVE_FOLDER_ID y APP_ORIGIN. El origen solo
sirve para feedback de errores, nunca autoriza. No registrar JWT/cuerpos/OAuth.

D1 guarda solo jti/exp/consumo. Su PK resuelve replay concurrente. Un fallo después
de consumir exige nuevo ticket, aunque la descarga no termine. Purga manual tras
exp + margen es posible con autorización; no hay cron o borrado de respaldos.

Pruebas preparadas con Google simulado y SQLite en memoria, Node >=22.13. No se
ejecutaron ni se instalaron dependencias. Procedimiento completo y cuotas en
[la guía backend](../../docs/BACKUPS_RNF7_RNF10.md).

## Entorno independiente de Node

El objetivo institucional de la aplicación principal EIDA sigue siendo Node.js 20.
Este directorio es otro paquete: sus engines no se heredan en el frontend ni en
el package.json raíz del backend. No instalar ni ejecutar Wrangler en los servidores
del cliente como parte de sus despliegues.

Solo las herramientas/pruebas del Worker requieren Node >=22.13 por node:sqlite;
el Worker productivo corre en Cloudflare workerd, no en Node.js institucional.
El workflow manual independiente .github/workflows/backup-download-worker.yml usa
Node 24 en GitHub Actions (cumple Node 22+), siempre con working-directory de este
paquete. Ejecuta npm ci, typecheck y pruebas simuladas; no ejecuta builds EIDA.

Preparación pendiente: generar y revisar un package-lock.json propio de este
directorio con Node 22.13+ compatible con el Wrangler resuelto, preferiblemente
Node 24 como CI. No se ejecutó instalación ni se generó un lock en esta revisión.
CI falla explícitamente si falta; nunca usa el lock del backend o frontend.

La opción deploy es false por defecto. El job de publicación exige además rama
protegida backup-execution, variable de repositorio EIDA_BACKUP_WORKER_DEPLOY_ENABLED=true
y Environment protegido eida-backup-download. Configurar allí CLOUDFLARE_ACCOUNT_ID
y CLOUDFLARE_API_TOKEN con alcance mínimo. D1, hostname, configuración Wrangler y
runtime secrets se preparan aparte con autorización. Ningún workflow se ejecutó
ni se desplegó el Worker en esta revisión.
