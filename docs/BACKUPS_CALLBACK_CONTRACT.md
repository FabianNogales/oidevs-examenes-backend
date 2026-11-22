# Contrato de callbacks: main y backup-execution

Actualización posterior: el incidente 409 y la corrección de instantes UTC y
reclamación remota se documentan en [BACKUPS_RUNNING_TRANSITION.md](BACKUPS_RUNNING_TRANSITION.md).
Las comparaciones y resultados de esta sección corresponden a sus commits base.

## Resultado y evidencia pendiente

Comparación obtenida mediante fetch de solo lectura de la rama remota, sin
checkout, commit, push, merge, rebase ni cambios en HEAD/índice:

- main: c238802f565f305ea60890f2f3089bc627edf004.
- origin/backup-execution: 2004b047ff5ac169d28d01d409eacd86c47ca3df.
- El usuario indica que el fallo corresponde a un run nuevo, no a un Re-run.

No se encontró incompatibilidad entre los productores/contrato de esas versiones.
Las pruebas PHP aceptan callbacks firmados por el Python original de 2004b04
contra el Laravel actual. No se consultaron valores secretos ni Production.
La causa exacta del HTTP de Production sigue pendiente de la nueva evidencia.
No atribuir el rechazo automáticamente a una rama vieja ni a secretos diferentes.

## Diferencias reales antes de este cambio

| Componente | Comparación entre los commits |
| --- | --- |
| .github/workflows/database-backup.yml | Idéntico; blob fc5c799ad5ee9d7b50e5f0eda660ec8fed2f51d6 |
| scripts/backups/executor.py | Idéntico; blob f4a3d1ca5ffcd58888d991c7812ab5137114420a |
| drive_storage.py | Idéntico; blob 6c8333665aee04a100596b9c9bba804f80124645 |
| backup_errors.py | Idéntico; blob a4d2397574ddbeabddddc763eaf75e9898208540 |
| requirements.txt y demás fuentes/tests Python | Idénticos; runtime solo biblioteca estándar |
| Controller, FormRequest y middleware HMAC del callback | Idénticos |
| BackupService::callback | Mismo contrato y transiciones; las diferencias del servicio están en recuperación/reconciliación |
| BackupExecutor / GithubBackupExecutor | main añade comprobación de ejecución terminada para recuperar; no es una dependencia Python |
| Rutas/configuración de backups | main añade diagnósticos y recuperación administrativa; la ruta HMAC se conserva |
| Bootstrap, sesiones, middleware móvil y dependencias Composer revisados | Sin diferencias en los archivos comparados |

También existen pyc versionados, idénticos en ambas ramas. No se modifican ni
se incluyen en la sincronización; las pruebas usan -B y no generan nuevos pyc.
No hace falta copiar código Laravel, recuperación, frontend o todo main a
backup-execution. El workflow solo ejecuta Python desde esa rama protegida.

## Contrato verificado

El workflow asigna BACKUP_ID desde inputs.backup_id y BACKUP_RUN_ID como
github.run_id.github.run_attempt. Mapea el secreto de Actions
EIDA_BACKUPS_CALLBACK_SECRET a BACKUP_CALLBACK_SECRET para Python. Laravel lee
EIDA_BACKUPS_CALLBACK_SECRET de su propia configuración.

RUNNING envía JSON compacto UTF-8 con backup_id, external_run_id y status.
Ambos lados firman exactamente esta secuencia de bytes:

~~~text
timestamp + LF + nonce + LF + POST + LF + /api/v1/backup-executor/callback + LF + cuerpo original
~~~

HMAC-SHA256, clave codificada en UTF-8, digest hexadecimal; el servidor no
re-serializa JSON. Se usan X-Backup-Timestamp, X-Backup-Nonce y X-Backup-Signature.
La ventana es 300 s, el nonce debe ser UUID y el cuerpo no exceder 16384 bytes.
Los reintentos conservan nonce y cuerpo, renovando timestamp y firma.

El receptor valida UUID, formato numérico run_id.attempt, plazo, vinculación a
la misma ejecución, unicidad y transición QUEUED → RUNNING. FAILED libera el slot.
No se introducen campos workflow/branch nuevos en el payload. La corrección
posterior conserva HMAC y añade, antes del primer claim, la verificación remota
del UUID/repositorio/workflow/rama/run/intento, reutilizando el validador del
mecanismo de recuperación. Los productores originales siguen siendo compatibles.

## Corrección aplicada: diagnóstico seguro

executor.py mantiene firma, payload, reintentos y códigos finales existentes.
Antes de CALLBACK_REJECTED o un retry, imprime un evento
BACKUP_CALLBACK_DIAGNOSTIC con:

- phase y execution_state: claim_pending, running, uploading o terminating.
- attempt, http_status y categoría de rechazo.
- response_code únicamente si pertenece a una lista fija de códigos conocidos.
- validation_fields únicamente con nombres de campos del contrato permitidos.
- transport_error: timeout, tls, dns, connection o transport_error.

Se leen como máximo 4097 bytes de una respuesta de error; solo se analiza JSON
si no supera 4096. No se imprimen mensajes, HTML, URLs, cabeceras, cuerpos,
firmas, hashes de secretos, tokens, valores de validación ni excepciones.

Ejemplo de forma, sin afirmar que sea el fallo actual:

~~~json
{"event":"BACKUP_CALLBACK_DIAGNOSTIC","phase":"RUNNING","attempt":1,"http_status":422,"category":"payload_invalid","response_code":null,"validation_fields":["external_run_id"],"execution_state":"claim_pending","transport_error":null}
~~~

En main, AuthenticateBackupExecutor conserva todas las condiciones y su orden,
incluida hash_equals. Solo añade condition a la auditoría privada
BACKUP_CALLBACK_REJECTED: BODY_TOO_LARGE, TIMESTAMP_INVALID, NONCE_INVALID,
TIMESTAMP_EXPIRED o SIGNATURE_MISMATCH. No devuelve esa condición al público;
mantiene el mismo HTTP 401 / EXECUTOR_UNAUTHENTICATED.

| Evidencia nueva | Qué revisar, sin asumir la causa |
| --- | --- |
| 3xx / redirect_rejected | Dominio de destino definitivo; mantener HTTPS, ruta exacta y rechazo de redirects |
| 401 / EXECUTOR_UNAUTHENTICATED | condition en auditoría; configuración efectiva, cabeceras y bytes originales |
| 401 sin response_code / 403 | Acceso a destino, proxy, deployment y ruta efectiva; no asumir que lo emitió HMAC |
| 409 / INVALID_BACKUP_TRANSITION | Plazo, estado terminal, duplicado o binding run/intento |
| 422 / payload_invalid | Solo los campos listados en validation_fields; revisar formato del runtime |
| 429 | Rate limit; se conservan retries acotados |
| 5xx o transport_failure | Disponibilidad, timeout/TLS/DNS; no convertirlo en éxito |

En GitHub debe revisarse el environment eida-backups, que el workflow declara.
Un secreto de environment prevalece sobre uno del repositorio con el mismo
nombre: actualizar solo el repositorio no demuestra que cambió la clave usada.
Referencia: [GitHub Actions secrets](https://docs.github.com/en/actions/reference/security/secrets).
Esto es una comprobación pendiente, no una causa confirmada del caso.
En Vercel revisar el deployment Production efectivo y cualquier config:cache
generado antes del cambio de variables, sin imprimir configuración ni secretos.

## Cambios preparados por rama

main, en el árbol de trabajo actual:

- scripts/backups/executor.py y test_executor.py: diagnóstico y pruebas Python.
- app/Http/Middleware/AuthenticateBackupExecutor.php: condición privada de rechazo.
- tests/Feature/Backups/BackupCallbackContractTest.php: integración real.
- tests/Support/Backups/emit_python_callback.py: utilidad offline de pruebas.
- Este documento.

backup-execution, parche independiente preparado contra 2004b04:

- scripts/backups/executor.py.
- scripts/backups/test_executor.py.

Artefacto: C:\proyecto\artifacts\eida-callback-2004b04\backup-execution.patch.
Se comprobó git apply --check contra una exportación temporal del commit base
y se aplicó solo a esa copia para ejecutar las pruebas. No se aplicó a la rama
ni se modificó su ref. El parche no incluye workflow, módulos Drive, dependencias,
Laravel ni binarios. Para ejecutar el runner solo es necesario actualizar
executor.py; el segundo archivo conserva su cobertura de pruebas junto al código.

El operador, cuando ya esté trabajando en backup-execution, puede revisar:

~~~powershell
git apply --check C:\proyecto\artifacts\eida-callback-2004b04\backup-execution.patch
~~~

Si la rama avanzó, revisar diferencias antes de aplicarlo; no forzar un parche
ni sustituir archivos no relacionados.

## Pruebas focalizadas y reproducción

La utilidad offline llama al Callback real de Python con opener HTTP simulado,
reloj y nonce controlados y claves sintéticas fixture-. PHP recibe los bytes y
cabeceras resultantes sin volver a firmarlos. No usa secretos de entorno, DB
externa, Docker, Drive ni un respaldo. La salida de la utilidad es IPC privado
con datos sintéticos; no se utiliza en el workflow ni en logs productivos.

Resultados:

- Python de main: 14 pruebas correctas.
- Copia temporal de 2004b04 con el parche aplicado: 14 pruebas Python correctas.
- PHP focalizado (contrato, módulo manual, dispatch y recuperación GitHub):
  75 pruebas, 369 aserciones, correctas.
- Contrato con productor original e inmutable 2004b04: 14 casos,
  132 aserciones, correctos; mismo resultado con el productor actualizado.

La cobertura incluye RUNNING/FAILED, slot, firma válida/inválida, cuerpo
alterado, clave UTF-8, timestamp/nonce, UUID inválido/desconocido, run/intento
inválido o diferente, duplicados, plazo vencido y respuestas seguras. Python
simula 401/403/409/422/308 y transporte; la recuperación GitHub comprueba
repositorio/workflow/rama/run/intento mediante fakes de API. No se afirma que
el callback valide metadatos remotos que no contiene.

~~~powershell
python -B -m unittest discover -s scripts/backups -p 'test_*.py'
php vendor/bin/phpunit -c phpunit.offline.xml --filter 'BackupCallbackContractTest|ManualBackupTest|GithubBackupRecoveryTest|GithubBackupExecutorTest'
$env:EIDA_TEST_BACKUP_EXECUTOR_REF='2004b047ff5ac169d28d01d409eacd86c47ca3df'
php vendor/bin/phpunit -c phpunit.offline.xml --filter BackupCallbackContractTest
Remove-Item Env:EIDA_TEST_BACKUP_EXECUTOR_REF
~~~

La selección por ref solo lee tres blobs locales con git show, sin fetch ni
checkout durante las pruebas. Requiere que el objeto exista localmente.
Puede definirse EIDA_TEST_PYTHON con el ejecutable Python de pruebas.
En este equipo se utilizaron las copias temporales de tests/bootstrap descritas
en BACKUPS_ENABLED_DIAGNOSTIC.md por el problema local de is_readable, siempre con
phpunit.offline.xml, SQLite :memory: y session/cache array. No hubo migraciones
contra bases persistentes ni suites completas.

## Orden de publicación y prueba real posterior

Todo este apartado corresponde al operador; no se ejecutó:

1. Revisar destino HTTPS de callback, scopes Production/eida-backups y vigencia
   del deployment tras la actualización de variables. No rotar claves otra vez
   como ensayo ni mostrarlas para compararlas.
2. Publicar primero los cambios revisados de main y desplegar Laravel, incluyendo
   el motivo privado de rechazo. Mantener HMAC, sesiones y SEC-01/02.
3. Publicar en backup-execution únicamente los dos archivos del parche; comprobar
   el nuevo Source de la rama. El workflow actual no necesita modificarse.
4. Confirmar que el run fallido terminó y recuperar solo su UUID mediante
   POST /api/v1/admin/backups/{uuid}/recover con sesión Administradora y CSRF.
   La API vuelve a verificar GitHub. No usar SQL ni liberar el slot a mano.
5. Actualizar /admin/backups. Solo cuando no haya ejecución activa y el servicio
   esté habilitado, el operador podrá generar un único respaldo nuevo desde EIDA,
   con UUID nuevo. No hacer Re-run de un UUID recuperado o terminal.
6. Si vuelve a fallar, conservar únicamente el evento de diagnóstico seguro y,
   para 401 HMAC, condition de auditoría. Corregir la condición demostrada antes
   de otra ejecución. No ejecutar un dump adicional para obtener más logs.
7. Apagar y retirar el diagnóstico público temporal al terminar la investigación.
   Esta corrección no lo amplía ni modifica sus permisos.

No cambian rutas, páginas, frontend, Node institucional, Cloudflare, APK,
workflow, generación de dumps ni credenciales. No se ejecutaron commits,
pushes, merges, despliegues, migraciones productivas o respaldos reales.
