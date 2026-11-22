# INVALID_BACKUP_TRANSITION en el primer RUNNING

## Condición confirmada del incidente

UUID: 77335eb4-232d-4ef1-a2c1-a322cc925919.

Datos aportados por el usuario (lectura SQL realizada por el usuario):

| Campo | Valor |
| --- | --- |
| status al consultar | FAILED |
| requested_at | 2026-11-22 17:01:05+00 |
| deadline_at | 2026-11-22 18:01:05+00 |
| external_run_id | 45069670270.1 |
| APP_TIMEZONE indicado | UTC |

Metadatos públicos del run consultados en modo lectura:

- ID 45069670270, intento 1; workflow_dispatch.
- UUID del título idéntico al respaldo.
- Rama backup-execution; .github/workflows/database-backup.yml.
- Source f6f7959d6d16dc0641f5ea39444e8d122bf09133.
- Creado e iniciado 2026-11-22T21:01:06Z; falló a las 21:01:25Z.

Por tanto, cuando llegó el primer RUNNING, el plazo almacenado ya estaba vencido
por más de tres horas. La condición exacta del 409 era deadline_at->isPast(),
primera comprobación del único punto de emisión de INVALID_BACKUP_TRANSITION.
La validación HMAC y de payload ya había pasado; no se investigan credenciales
ni Drive como explicación de este 409.

El estado FAILED leído después no demuestra por sí solo qué estado tenía la
fila al recibir el callback: pudo intervenir la recuperación/reconciliación.
Esto no cambia la conclusión del plazo, que se comprueba antes que el estado.

## Reconstrucción desde el código

1. AdministratorOperation bloquea rol/actor y valida permisos/sesión.
2. BackupService::request comprueba slot y cuota, genera UUID, inserta QUEUED,
   active_slot=1 y deadline_at=now()+60 minutos. external_run_id queda NULL.
3. Termina esa transacción antes de llamar a GithubBackupExecutor::dispatch.
4. El dispatch solo envía ese UUID y ref=backup-execution al workflow configurado.
5. Python envía RUNNING y run_id.run_attempt con HMAC antes de ejecutar el dump.
6. El receptor verifica nonce, timestamp, firma, UUID y formato del run.
7. callback bloquea la fila y comprueba plazo, transición, vínculo y exclusividad.
   NULL no requiere un run previamente registrado: se vincula al aceptar el claim.
8. Si el callback llegó durante dispatch, el procesamiento posterior de su
   respuesta no sobrescribe RUNNING: solo modifica filas que continúan QUEUED.

Las pruebas reproducen el callback durante/después del dispatch y durante
respuestas ambiguas/rechazadas. No se encontró una carrera de persistencia que
explique este incidente.

Antes de la corrección, INVALID_BACKUP_TRANSITION agrupaba cuatro condiciones:

| Orden | Condición |
| --- | --- |
| 1 | deadline_at ya pasado |
| 2 | siguiente estado no permitido desde el estado almacenado |
| 3 | external_run_id ya vinculado y diferente del callback |
| 4 | mismo run/intento vinculado a otro UUID |

## Defecto de representación y corrección

Backup usaba el formato Eloquent de conexión sin offset (Y-m-d H:i:s) para
columnas PostgreSQL timestamptz. Ese texto pierde la zona del instante original;
PostgreSQL lo interpreta usando la zona de su sesión.
[Semántica oficial de PostgreSQL](https://www.postgresql.org/docs/current/datatype-datetime.html).

Se reprodujeron exactamente las horas del incidente con una aplicación UTC y
una sesión DB UTC+04, así como el desfase equivalente con aplicación
America/La_Paz y sesión UTC. No se afirma que Production use Asia/Dubai: se usa
como fixture de UTC+04. No se inspeccionó la zona efectiva de la conexión de
Laravel ni su caché de configuración. Lo confirmado en Production es el plazo
vencido; lo demostrado en código y pruebas es la pérdida de offset y su efecto.
Tampoco se reparan retrospectivamente fechas históricas de origen ambiguo.

Cambios:

- Backup::fromDateTime normaliza exclusivamente sus fechas a UTC y guarda offset
  explícito: Y-m-d H:i:sP. No cambia la zona global ni la de otros modelos.
- Los límites de consulta de expiración y cuota se envían también como UTC con
  offset, para que comparación SQL y comparación Carbon usen el mismo instante.
- No se amplía el plazo ni se permite reclamar un respaldo realmente vencido.
- No se modifican filas históricas, índices, columnas o migraciones.

La prueba de control vuelve a introducir el binding antiguo en el INSERT real
del flujo administrativo: requested_at=17:01:05+00, deadline_at=18:01:05+00,
RUNNING firmado y 409, con auditoría DEADLINE_EXPIRED. La misma solicitud con el
binding corregido conserva requested_at=21:01:05+00, deadline_at=22:01:05+00 y 200.
El round trip timestamptz se emula en SQLite; no se abrió una DB PostgreSQL real.

## Identidad del primer claim y controles conservados

La inspección detectó que, antes del primer binding, se confiaba en HMAC/UUID y
el formato del run, pero no se contrastaban metadatos remotos de esa ejecución.
Para cumplir la protección contra callbacks de otras ejecuciones se añade
assertRunningExecution, reutilizando validatedExecutionId de la recuperación:

- GET acotado al run numérico en el repositorio configurado.
- Título EIDA backup {uuid}, workflow, rama backup-execution y evento dispatch.
- Run e intento exactos, fecha coherente y ejecución in_progress.
- Se verifica antes de RUNNING o de una primera transición sin vínculo.
- Un run ajeno, workflow/rama incorrectos o intento distinto produce 409.
- Estado API pendiente/incoherente o consulta no verificable produce 503,
  conserva QUEUED/slot y permite los reintentos existentes del ejecutor.
- Usa el permiso de lectura de Actions ya necesario para la recuperación.
  Una consulta, timeout 5 s/conexión 2 s, sin redirects ni retries HTTP nuevos.

Se mantienen HMAC, UUID, ventana temporal, replay por nonce/digest, slot único,
cuota, bloqueo de fila, estados terminales, verificación Drive y recuperación.
No cambia el contrato JSON del productor Python.

Se añade BACKUP_TRANSITION_REJECTED a la auditoría privada con condición fija,
estado almacenado y estado solicitado. La entrada se confirma sin modificar
backup/recibos antes de devolver el rechazo fuera de la transacción. Los códigos
públicos conservan INVALID_BACKUP_TRANSITION / EXECUTOR_STATE_UNVERIFIED.

Condiciones auditadas: DEADLINE_EXPIRED, STATE_NOT_ALLOWED, RUN_ATTEMPT_MISMATCH,
RUN_ALREADY_BOUND, ACTIVE_SLOT_INVALID, EXECUTION_IDENTITY_MISMATCH y
EXECUTION_UNVERIFIED. No se guardan cuerpos, firmas ni credenciales.

## Pruebas focalizadas

94 pruebas y 738 aserciones correctas: contrato Python/Laravel, módulo manual,
dispatch y recuperación GitHub. Además, 33 pruebas de contrato y 501 aserciones
correctas usando el Python del commit real f6f7959.

Incluyen solicitud administrativa real a la ruta, INSERT QUEUED, payload del
dispatch, UUID generado, run/intento, límites de transacción, callback durante
dispatch, respuestas 204/403/503, doble claim, nonce idempotente, ejecución ajena,
rama/workflow/intento incorrectos, metadata pendiente, FAILED inmutable,
concurrencia y RUNNING → UPLOADING → AVAILABLE.

~~~powershell
php vendor/bin/phpunit -c phpunit.offline.xml --filter 'BackupCallbackContractTest|ManualBackupTest|GithubBackupRecoveryTest|GithubBackupExecutorTest'
$env:EIDA_TEST_BACKUP_EXECUTOR_REF='f6f7959d6d16dc0641f5ea39444e8d122bf09133'
php vendor/bin/phpunit -c phpunit.offline.xml --filter BackupCallbackContractTest
Remove-Item Env:EIDA_TEST_BACKUP_EXECUTOR_REF
~~~

SQLite :memory:, session/cache array, HTTP simulado y Python real con claves de
fixture. No se genera dump ni se conecta a DB externa. Por is_readable de este
equipo se usó el procedimiento de copias temporales documentado anteriormente.
El esquema se creó solo dentro de esas bases de prueba en memoria.

## Publicación y respaldo afectado

Solo requiere publicar main como unidad coherente: modelo, contrato/interface,
servicio y adaptador GitHub, junto con tests/documentación. El Source de
backup-execution f6f7959 se probó compatible: no necesita sincronización ni un
cambio de workflow/Python. No hay migración, nueva ruta ni ajuste de APP_TIMEZONE.

A cargo del operador, sin ejecutar estas acciones durante el trabajo:

1. Revisar/publicar el cambio de main y crear el deployment Production de Vercel
   desde esa revisión. No hay limpieza de datos ni cambio global de zona.
2. Mantener 77335eb4-232d-4ef1-a2c1-a322cc925919 en FAILED. No extender su plazo,
   volverlo a QUEUED ni hacer Re-run del mismo UUID.
3. Actualizar el listado administrativo y comprobar que no hay slot activo.
   Si corresponde ejecutar recuperación, el endpoint ya existente es:
   POST /api/v1/admin/backups/77335eb4-232d-4ef1-a2c1-a322cc925919/recover,
   cuerpo {}, sesión Administradora y CSRF. Un FAILED consistente devuelve 200
   idempotente; no se fuerza una fila con invariantes inconsistentes.
4. Solo con intervención del operador, generar desde EIDA un respaldo nuevo,
   respetando configuración, cuota y slot. Su requested_at debe coincidir en
   instante con el dispatch y su deadline estar 60 minutos después.
5. La UI existente refleja los estados al actualizar o durante su polling.
   No se añade botón, página ni ruta. Los motivos específicos son de auditoría.

No se ejecutaron commits, pushes, merges, despliegues, migraciones productivas
ni respaldos reales. SEC-01/SEC-02 y el control de sesiones no se modificaron.
