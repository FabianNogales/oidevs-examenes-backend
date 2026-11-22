# Recuperación de respaldos atascados

## Hallazgo y límites de la investigación

Caso comunicado: f7504a6c-2c7f-4a45-88de-d0bb229e2234.
Título GitHub comunicado: EIDA backup f7504a6c-2c7f-4a45-88de-d0bb229e2234.
No se consultó ni modificó Production, ni se ejecutaron respaldos reales.

El flujo anterior explica que GitHub termine y Laravel siga en QUEUED:

1. Laravel guarda QUEUED y active_slot=1 antes de workflow_dispatch.
2. ACCEPTED solo confirma la aceptación del dispatch, no el resultado del job.
3. executor.py envía RUNNING antes de conectar PostgreSQL o generar un dump.
4. Un HTTP <500 distinto de 429 produce CALLBACK_REJECTED. Si falla ese primer
   callback, claimed sigue siendo false; el ejecutor no envía FAILED.
5. Incluso si ya estaba reclamado, el ejecutor evita reenviar FAILED cuando el
   canal de callbacks fue rechazado o es inaccesible. No puede confiar en él.
6. Laravel no consultaba el resultado de Actions: dependía de callbacks y plazo.

No es correcto aceptar una notificación sin HMAC para liberar ese bloqueo.
Se mantienen firma, ventana temporal, nonce, validación y estados terminales.

La reconciliación ya existía: deadline de 60 minutos, llamada desde listado,
detalle, nueva solicitud, callback y comando backups:reconcile, programado cada
cinco minutos si realmente se opera el scheduler. Antes liberaba solo por reloj.
Por tanto, si ya habían pasado 60 minutos y un listado actualizado respondió
correctamente, el código anterior debía expirar la fila. No se inspeccionaron
deadline_at, reloj, caché ni scheduler de Production; no se afirma que se hayan
cumplido esas condiciones en este caso.

El motivo específico del rechazo del callback aún requiere el estado HTTP y
código de respuesta reales. El mensaje Python pierde ese detalle:

| HTTP/código posible | Comprobación |
| --- | --- |
| 401 / EXECUTOR_UNAUTHENTICATED | Igualdad del secreto entre Laravel y el environment eida-backups de Actions; timestamp, ventana 300 s, nonce UUID y firma del cuerpo original |
| 409 / INVALID_BACKUP_TRANSITION o CALLBACK_REPLAY | Estado/plazo, binding run_id.attempt e idempotencia del nonce |
| 422 | Payload validado: UUID, external_run_id numérico con intento, estado y evidencia requerida |
| 403/404/419 u otra respuesta 4xx | Host/ruta/despliegue de destino, políticas de acceso y posibles rewrites; no atribuirlo automáticamente a HMAC |

BACKUP_CALLBACK_REJECTED en la auditoría existente puede aportar la hora y
EXECUTOR_UNAUTHENTICATED. No se imprimen secretos, firmas, cabeceras, cuerpos
de callbacks ni trazas para investigarlo. La URL configurada debe ser HTTPS y
tener exactamente /api/v1/backup-executor/callback. No se modificó Python.

## Corrección aplicada

Se reutiliza BackupService y la transición de reconciliación:

- completedExecution en BackupExecutor verifica el estado remoto. Su
  implementación GitHub hace solo GET: listado del workflow configurado y
  consulta fresca del run encontrado, sin redirects ni reintentos de dispatch.
- Busca el título exacto EIDA backup {uuid}, repositorio, workflow, evento
  workflow_dispatch, rama backup-execution y fecha posterior a requested_at
  menos cinco minutos de tolerancia. No recibe un run arbitrario del navegador.
- Exige un único match, status=completed y conclusión terminal conocida.
  Otra ejecución no terminal dentro del listado también impide recuperar.
  Luego vuelve a consultar el run para detectar un rerun recién iniciado.
- El intento debe coincidir con external_run_id si ya estaba vinculado. Un
  intento diferente, incluso terminado, no reemplaza silenciosamente ese vínculo.
- Las respuestas incompletas, ausentes, ambiguas, no autorizadas o no verificables
  conservan el bloqueo. Se limita el listado a 100 resultados completos; no se
  acepta una primera página si hay más resultados. Esto mantiene un presupuesto
  acotado de dos GET, hasta 5 s cada uno y 2 s de conexión por petición.
- El token del backend necesita lectura de Actions para consultar ejecuciones.
  Referencia primaria: [API de workflow runs](https://docs.github.com/en/rest/actions/workflow-runs?apiVersion=2022-11-28).
  No se realizaron estas consultas reales durante la implementación.
- Bajo bloqueo de la fila se revalidan estado activo, active_slot=1, ausencia de
  completed_at, identidad remota y exclusividad del run. Se cambia solo esa fila
  a FAILED, se fija completed_at y se libera su slot, sin borrar historia.
- La auditoría se inserta en la misma transacción: si falla, se revierte también
  la liberación. El índice único active_slot y AdministratorOperation permanecen.
- La reconciliación automática exige la misma evidencia remota. Si GitHub está
  activo o no puede verificarse, conserva el estado incluso vencido.
- AVAILABLE no se degrada. FAILED con completed_at y slot vacío es idempotente.
  Los callbacks tardíos no pueden cambiar estados terminales.

La comprobación remota es una fotografía en el tiempo, no una transacción
distribuida con GitHub. No volver a ejecutar manualmente el UUID recuperado.
Si se inicia un rerun después de verificarlo, el callback RUNNING encuentra FAILED
y es rechazado antes de que Python conecte PostgreSQL o genere el dump.

## Endpoint administrativo

POST /api/v1/admin/backups/{uuid}/recover, cuerpo JSON vacío {}.

Usa el mismo grupo auth:sanctum, session.current, password.changed,
EnsureActiveAdministrator y EnsureBackupAdministrator. Revalida el Administrador
base activo dentro de AdministratorOperation y mantiene CSRF de sesiones SPA.
Límite adicional: seis recuperaciones por minuto. No es un endpoint público.

| Resultado | Efecto |
| --- | --- |
| 200, data.state=FAILED | Recuperado o ya fallido; Cache-Control: private, no-store |
| 409, EXECUTOR_ACTIVE | Ejecución/espera/reintento activo; no cambia la fila |
| 409, EXECUTOR_IDENTITY_MISMATCH | El run/intento no coincide o ya está vinculado a otra fila |
| 409, BACKUP_NOT_RECOVERABLE | Estado o invariantes no permiten recuperarlo |
| 503, EXECUTOR_STATE_UNVERIFIED | No se obtuvo evidencia suficiente; conserva slot y estado |
| 401/403 | Autenticación, sesión, contraseña, cuenta o rol rechazado; no se omiten controles |
| 404 | UUID inexistente o backend todavía sin esta ruta |

Recuperar no requiere backups.enabled=true: debe poder liberar una ejecución
confirmada como terminada aunque se haya deshabilitado la generación.
Sí requiere configurar correctamente el acceso GitHub para verificarla.
No convierte un dump en AVAILABLE, no descarga/sube Drive, no cancela ni inicia
jobs, no restablece la cuota de tres solicitudes por hora y no elimina registros.

Eventos: BACKUP_RECOVERED para la acción manual; BACKUP_ABANDONED para el plazo
verificado; BACKUP_RECOVERY_REJECTED para la acción manual rechazada.
La auditoría guarda actor, UUID, estado previo, nuevo estado, código y
external_run_id, sin credenciales.

## Procedimiento para el registro actual

A cargo del operador; no se ejecutó:

1. Revisar/publicar los cambios del backend mediante el procedimiento autorizado,
   manteniendo SEC-01/SEC-02 y los demás cambios. No hay migración nueva.
2. Comprobar configuración efectiva de repo/token/ref en el backend. Conservar
   backup-execution como referencia. Si se utiliza config:cache, generarlo con
   las variables correctas. La función necesita presupuesto para hasta dos GET
   acotados a GitHub y la transacción; no relajar el timeout a un valor ilimitado.
3. En Actions comprobar el título comunicado y que el run y su último intento
   están completed. No pulsar Re-run. La API hará su propia verificación;
   una confirmación verbal, captura o checkbox no autoriza la transición.
4. Con una sesión Administradora vigente y el mecanismo CSRF habitual del
   cliente EIDA, enviar únicamente:

   ~~~http
   POST /api/v1/admin/backups/f7504a6c-2c7f-4a45-88de-d0bb229e2234/recover
   Accept: application/json
   Content-Type: application/json

   {}
   ~~~

   En Chrome puede reutilizarse Copy as fetch de un POST autenticado anterior,
   conservando credentials y CSRF, cambiando URL y cuerpo a los anteriores.
   No ejecutar el POST original de generación para obtener esa plantilla ni
   compartir sus cookies/cabeceras. El diagnóstico público no sirve para mutar.
5. Esperar 200 con FAILED/EXECUTOR_TERMINATED (o FAILED previo). Si devuelve
   EXECUTOR_ACTIVE, esperar o gestionar el run manualmente en GitHub y volver
   a verificar. Ante 503/identidad incoherente, investigar permisos/historial;
   no usar SQL, borrar la fila o vaciar active_slot como alternativa.
6. Pulsar Actualizar en /admin/backups y verificar summary.active=false.
   La página visible ya consulta cada 15 s mientras hay un respaldo activo.
   El botón Generar se habilita si además summary.enabled=true y no hay otro
   error. La siguiente solicitud usa un UUID nuevo. No la ejecutó este trabajo.
7. Investigar/corregir el rechazo del callback antes del siguiente respaldo;
   recuperar el registro no corrige una firma o URL mal configurada.

No se propone ejecutar backups:reconcile para reparar solo este UUID: ese
comando trata los vencidos. La acción administrativa permite seleccionar
únicamente el registro afectado y funciona antes del plazo.

## Impacto y verificación

Solo backend y documentación. Se añade una ruta POST administrativa; no cambian
páginas ni frontend React, Worker, Drive o workflows. No se añade botón nuevo.
La UI existente refleja FAILED y elimina el bloqueo al refrescar el listado.
Se mantienen los códigos de estado de solicitud/descarga y el contrato del resource.

Los GET del listado/detalle y los demás puntos de reconciliación vencida pueden
hacer ahora consultas de lectura a GitHub. Si fallan, el listado sigue mostrando
el respaldo activo. La preservación del bloqueo ante incertidumbre es deliberada.

Validación focalizada: 96 pruebas, 444 aserciones, correctas, con
phpunit.offline.xml, SQLite :memory: y session/cache array. Incluye pruebas de
recuperación, autorización, auditoría atómica, slot único, nueva solicitud,
historia inalterada, callbacks tardíos, timeout vencido pero run activo,
API inaccesible, identidad incorrecta, duplicados, paginación y rerun reciente;
también regresión de dispatch y de los diagnósticos público/administrativo.

~~~powershell
php vendor/bin/phpunit -c phpunit.offline.xml --filter 'ManualBackupTest|GithubBackupRecoveryTest|GithubBackupExecutorTest|BackupReadinessDiagnosticTest'
~~~

En este equipo PHP devuelve is_readable=false para archivos del repositorio.
Se verificaron esos cuatro archivos mediante copias temporales idénticas y un
bootstrap que requiere el autoloader original, manteniendo phpunit.offline.xml.
Es el mismo procedimiento documentado en BACKUPS_ENABLED_DIAGNOSTIC.md,
ampliado a los cuatro archivos; las copias se eliminaron al terminar. No se
cambiaron ACL ni vendor. No se ejecutaron suites completas, migraciones contra
bases persistentes, conexiones productivas, respaldos, commits ni despliegues.
