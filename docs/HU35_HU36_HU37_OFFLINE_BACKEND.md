# HU35, HU36 y HU37: backend offline inicial

## 1. Objetivo y alcance

- **HU35:** distribuir inscripciones activas en listas exclusivas, equilibradas y ordenadas; conservar su versión y responsables.
- **HU36:** entregar al responsable su paquete mínimo, persistido y vinculado a una autorización vigente.
- **HU37:** validar eventos, conservar resultados idempotentes y registrar los ingresos aceptados en el historial oficial existente.

Implementación exclusivamente backend. No incluye PWA, almacenamiento del navegador ni resolución manual de conflictos. No requiere dependencias nuevas.

## 2. Auditoría inicial y reutilización

El árbol de trabajo y staging estaban limpios al comenzar. Rama: `Dev_Fab_Oli`. HEAD: `f20dc97e49e97da535194ebd54b15887011f6de6`. No se consultaron repositorios `-compare`, no se cambió de rama ni se ejecutaron operaciones Git de escritura. La búsqueda inicial de nombres de archivos se realizó desde `proyecto` antes de leer la restricción del adjunto; las inspecciones posteriores y toda la implementación se limitaron al backend.

Se revisaron los modelos, migraciones, rutas, autenticación, auditoría y servicios existentes. No había tablas, atributos ni servicios offline preparados.

| Estructura real | Uso en estas HU |
| --- | --- |
| `Exam`, `course_offering_id`, `room_id`, `exam_date`, `start_time`, `duration_minutes` | Identificación y programación reales del examen. |
| `CourseOffering.teacher.user_id` y `ExamManagementPolicy::canView` | Propiedad del docente, cuenta activa y rol DOCENTE activo. |
| `ExamCollaborator`, `user_id`, `status`, `access_code_hash` | Autorización temporal; el hash rota al reasignar mediante HU15. No se expone al cliente. |
| `ExamCollaboratorService::isCandidate` | Cuenta activa y ausencia de inscripción estudiantil activa en la misma oferta. |
| `Student`: `first_names`, `last_names`, `sis_code`, `identity_number`, `status` | Orden e identificación mínima para verificación manual. |
| `enrollments`, `course_offering_id`, `student_id`, `status` | Solamente inscripciones `ACTIVE` se incorporan a las listas. |
| `exam_eligibilities`, `status`, `revision` | Se exige `ELIGIBLE` para aceptar; `INELIGIBLE`, el legado `NOT_ELIGIBLE` y ausencia de evaluación no habilitan. Se compara la revisión descargada. |
| `EntryControlService`, `ExamEntryAvailability` | Mismas condiciones actuales y ventana institucional del control online. |
| `QrEntryService`, `StudentQrService`, `StudentQrToken`, `QrTokenValidator` | Historial oficial y evidencia QR existentes. |
| `exam_entries` | Única fuente oficial de ingresos, métodos `MANUAL`/`QR`, estado `VALID`. |
| `uq_exam_entries_valid_student` | Índice único parcial existente sobre `(exam_id, student_id) WHERE status = 'VALID'`; se reutiliza sin duplicarlo. |
| `AuditLogService`, `audit_logs` | Auditoría en la misma transacción que cada cambio; registros existentes inmutables. |

La disponibilidad reutiliza `SCHEDULED`, `ACTIVE` e `IN_PROGRESS`. La preparación se permite antes del inicio, hasta el cierre. `CANCELLED`, `COMPLETED`, `FINISHED`, estados incompatibles y exámenes cuya ventana terminó bloquean la preparación. La aceptación exige además que el examen haya comenzado. Las ventanas se calculan en **America/La_Paz**, independientemente de `APP_TIMEZONE`; la API expresa instantes en ISO 8601 UTC.

## 3. Arquitectura

- `OfflineAssignmentService`: prepara y consulta la distribución.
- `OfflinePackageService`: autoriza, crea y entrega snapshots de la lista propia.
- `OfflineSyncService`: procesa cada evento en una transacción independiente, compara versiones/evidencia y persiste resultado e ingreso.
- `OfflineExamAccess`: reutiliza la política docente y la selección de colaboradores; calcula huellas de examen y autorización.
- `OfflineExamController`: cuatro acciones delgadas.
- `SyncOfflineEntriesRequest`: valida estructura, límites y fechas explícitas con zona horaria.

Se utilizan los modelos `ExamOfflineDistribution`, `ExamOfflineList` y `ExamOfflinePackage`. Miembros y eventos se manipulan mediante Query Builder, como `exam_entries` en el código existente.

`QrTokenValidator::decodeAuthentic` es una extracción de la comprobación existente de firma y contrato. `decode` continúa rechazando tokens expirados con el mismo contrato online; el nuevo método permite distinguir evidencia auténtica expirada y devolver un conflicto offline. No se cambiaron rutas, respuestas ni reglas funcionales de HU13/HU14.

## 4. Persistencia y migración

Migración nueva: `database/migrations/2026_11_01_000001_create_exam_offline_tables.php`.

| Tabla | Función y restricciones |
| --- | --- |
| `exam_offline_distributions` | Una distribución por examen, versión 1, autor, fecha y huellas de entrada/examen. `exam_id` único. |
| `exam_offline_lists` | Responsable, posición y huella privada de su episodio de autorización. Único `(distribution_id, responsible_id)`. |
| `exam_offline_members` | Membresía por ID de estudiante y posición. Único `(exam_id, student_id)` y `(list_id, position)`. |
| `exam_offline_packages` | UUID de paquete, lista, usuario que descargó, preparación, vencimiento y snapshot mínimo. |
| `exam_offline_sync_events` | UUID global único, examen/lista/paquete/usuario, estudiante resuelto, versión enviada, huella del contenido, mecanismo, dispositivo, fechas declarada/real y resultado JSON. |

Todas las referencias persistidas usan claves foráneas con borrado restringido. Para un estudiante inexistente, la referencia resuelta del evento es nula y el ID solicitado queda en `audit_logs`; así se conserva el rechazo sin inventar un estudiante. Las tablas offline conservan evidencia de procesamiento, **no un segundo historial oficial de asistencia**.

No se aplicó esta migración a la base local existente. Tras revisar y autorizar el destino, ejecutar manualmente:

```powershell
php artisan migrate --path=database/migrations/2026_11_01_000001_create_exam_offline_tables.php
```

Requiere las migraciones previas del proyecto, incluida `revision` de habilitaciones. No requiere seeders, vaciado, reinicialización ni modificaciones de datos históricos. No ejecutar `migrate:fresh`, `db:wipe` ni `truncate`.

## 5. Endpoints y autenticación

Todos conservan los middleware `auth:sanctum`, `session.current`, `password.changed` y agregan el límite `throttle:60,1`. La identidad se obtiene de la sesión/token autenticado. Las pruebas usan usuarios autenticados, no identidades declaradas en JSON.

| Método y ruta | Autorización y respuesta |
| --- | --- |
| `POST /api/v1/exams/{exam_id}/offline/prepare` | Solo docente propietario; sin body obligatorio; `200` con distribución, incluso en repetición idéntica. |
| `GET /api/v1/exams/{exam_id}/offline/assignments` | Solo docente propietario; `200` con distribución completa. |
| `GET /api/v1/exams/{exam_id}/offline/package` | Solo responsable vigente de una lista propia; `200` con paquete nuevo y `Cache-Control: private, no-store`. |
| `POST /api/v1/exams/{exam_id}/offline/sync` | Responsable histórico del paquete, con cuenta/sesión activa. La autorización vigente se comprueba por evento para conservar conflictos por revocación. |

El docente supervisa todas las listas mediante `assignments`. Cuando hay colaboradores y el docente no tiene una lista propia, `package` devuelve `403`. Un parámetro `list_id` en la descarga no permite seleccionar listas ajenas: el servidor siempre obtiene la lista del usuario autenticado.

Examen, distribución o recurso inexistente: `404`. Autorización insuficiente o paquete ajeno: `403`. Estado incompatible con preparación/descarga, examen modificado o regeneración bloqueada: `409` con `message` y `code`. JSON estructuralmente inválido: `422` con errores estándar Laravel. Falta de autenticación: `401`; límite de solicitudes: `429`.

### Ejemplo de preparación y consulta

```http
POST /api/v1/exams/42/offline/prepare
Accept: application/json
```

Respuesta ilustrativa con un solo estudiante y sin colaboradores:

```json
{
  "data": {
    "exam_id": 42,
    "version": 1,
    "prepared_at": "2026-11-02T13:00:00.000000Z",
    "lists": [{
      "list_id": 8,
      "responsible_id": 7,
      "responsible_name": "Ana Pérez",
      "student_count": 1,
      "surname_range": {"from": "García", "to": "García"},
      "students": [{"list_id": 8, "student_id": 101, "last_names": "García", "first_names": "Luis", "sis_code": "202600101"}]
    }]
  }
}
```

`GET assignments` usa el mismo contrato. El responsable de un estudiante se identifica por la lista que contiene su `student_id`; los apellidos son etiquetas, no reglas de autorización.

### Ejemplo de descarga

```http
GET /api/v1/exams/42/offline/package
Accept: application/json
```

```json
{
  "data": {
    "package_id": "1ba37ca3-1c61-4ed0-8740-a53776bb4d90",
    "exam_id": 42,
    "exam": {
      "id": 42, "name": "Primer parcial", "subject_name": "Programación I",
      "exam_date": "2026-11-02", "start_time": "10:00:00", "duration_minutes": 60,
      "evaluation_type": "partial", "status": "SCHEDULED", "entry_available": false,
      "entry_unavailable_reason": "EXAM_NOT_STARTED",
      "entry_opens_at": "2026-11-02T14:00:00.000000Z",
      "entry_closes_at": "2026-11-02T15:00:00.000000Z",
      "server_time": "2026-11-02T13:30:00.000000Z",
      "room": {"id": 3, "code": "A-101", "name": "Aula 101", "location": "Edificio central"}
    },
    "list_id": 8, "version": 1, "responsible_id": 7,
    "prepared_at": "2026-11-02T13:30:00.000000Z",
    "expires_at": "2026-11-02T15:00:00.000000Z",
    "timezone": "America/La_Paz", "room_id": 3,
    "surname_range": {"from": "García", "to": "García"},
    "qr_validation": "SERVER_ON_SYNC",
    "students": [{
      "student_id": 101, "first_names": "Luis", "last_names": "García",
      "sis_code": "202600101", "identity_number": "70000101",
      "student_status": "ACTIVE", "enrollment_status": "ACTIVE",
      "eligibility_status": "ELIGIBLE", "eligibility_revision": 1,
      "entry_status": "PENDING"
    }]
  }
}
```

El paquete no contiene fotografías, correos, contraseñas, cookies, claves, credenciales de colaboradores ni tokens QR de estudiantes. `identity_number` se incluye para la verificación manual; los motivos/observaciones de habilitación se omiten. `eligibility_status` y su revisión pueden ser nulos si la evaluación no existe. El estado de ingreso es el conocido al descargar, no una reserva de asistencia.

## 6. Reglas HU35

1. Se toman las inscripciones `ACTIVE` de la oferta del examen. Una habilitación aislada no equivale a inscripción.
2. No se filtra la lista por habilitación: el paquete informa el estado y la sincronización lo valida nuevamente. Un perfil inactivo inscrito puede figurar, pero no registrar un ingreso válido.
3. Los colaboradores `ACTIVE` que siguen siendo candidatos se ordenan por `user_id`. Sin candidatos vigentes, la lista se asigna al docente.
4. Estudiantes ordenados por comparación binaria de apellidos y nombres convertidos a minúsculas UTF-8, después SIS y finalmente ID. El orden no depende de la colación PostgreSQL/SQLite y no elimina acentos. No se promete un orden lingüístico español.
5. Se reparten bloques consecutivos: cociente entero más uno para las primeras listas hasta agotar el resto. 100/3 produce 34/33/33. Puede haber listas vacías, con ambos extremos del rango nulos.
6. Cada estudiante pertenece a una sola lista del examen, protegido también por índice único.
7. La primera preparación queda congelada, incluso antes de descargar. Misma huella de examen, roster ordenado y responsables/autorizaciones devuelve los mismos IDs sin nuevas filas ni nueva auditoría de preparación.
8. Si esos datos cambian, una nueva preparación devuelve `409 DISTRIBUTION_FROZEN`. No hay regeneración ni reasignación automática en esta versión.

Las listas conservan su membresía por IDs; la consulta docente muestra los nombres actuales del padrón. Cada paquete conserva el snapshot de identificación realmente descargado. Las correcciones posteriores se detectan al sincronizar.

## 7. Autorizaciones y vigencia HU36

La huella del episodio de colaboración deriva del ID de colaboración y su credencial rotatoria existente; nunca se entrega al cliente. Revocar impide descargar. Revocar y volver a asignar genera una huella distinta, de modo que tampoco rehabilita silenciosamente listas o paquetes anteriores.

Cada descarga crea un UUID de paquete con snapshot persistido; su versión, lista, responsable, examen y autorización se verifican contra el servidor al sincronizar. El UUID no sustituye autenticación. Los cambios de programación, ambiente o revisión del examen requieren revisión; la descarga devuelve `DISTRIBUTION_STALE`.

Se audita `OFFLINE_LISTS_PREPARED` y `OFFLINE_PACKAGE_PREPARED`. Esta última acción registra la preparación/entrega HTTP, no garantiza que el dispositivo haya terminado de almacenar la respuesta. Si falla la auditoría, la operación se revierte.

## 8. Operación offline y QR

El cliente podrá consultar su snapshot e identificar manualmente estudiantes de su propia lista. Toda captura será provisional hasta sincronizar.

El QR actual es JWT HS256, contrato v2 estricto con `v`, `jti` y `exp`. El identificador del token se resuelve contra `student_qr_tokens`; no incluye SIS ni estudiante/examen en el payload. No permite al cliente comprobar la firma sin conocer la clave secreta compartida, y esa clave no se distribuye. El backend no simula validación criptográfica offline.

La sincronización verifica firma, contrato, estudiante/examen, token `ACTIVE`, ausencia de revocación y expiración actual. Firma inválida o evidencia faltante: `REJECTED INVALID_QR`. Firma auténtica expirada: `CONFLICT QR_EXPIRED_UNVERIFIABLE`. Token revocado: `CONFLICT QR_REVOKED`. Un QR de otro estudiante/examen se rechaza sin revelar datos del titular real. Una captura declarada anterior a la generación del token queda como `CONFLICT CAPTURE_TIME_UNVERIFIABLE`. El emisor existente `StudentQrService` guarda `generated_at` como hora institucional sin zona en una columna `timestamp`; la lectura offline interpreta ese valor explícitamente en `America/La_Paz`, en lugar del cast general dependiente de `APP_TIMEZONE`. Si un importador/seed legado guardó ese campo en otra zona, sus valores necesitan revisión; no se transforma ni corrige la tabla existente automáticamente.

La fecha del teléfono únicamente se comprueba por consistencia. No prueba cuándo ocurrió el escaneo. Para verificación criptográfica offline completa se necesitaría diseñar un contrato firmado con clave asimétrica y claves públicas distribuidas, rotación/revocación, vinculación al examen/estudiante/lista y protección frente a repetición. Una firma asimétrica por sí sola tampoco demuestra el instante real de captura: hace falta acordar evidencia temporal confiable y la política para dispositivos sin conexión, junto con manejo de revocaciones que no pudieron descargarse.

## 9. Sincronización: petición y respuesta

Un lote contiene eventos del mismo examen, lista y paquete; máximo 100 eventos. Campos comunes: `package_id` UUID, `list_id`, `version`, `room_id`. Cada evento requiere UUID propio, ID estudiante, método `MANUAL` o `QR` y `captured_at` ISO 8601 con segundos y zona explícita. `device_id` es opcional, máximo 100 caracteres; no se usa como prueba de identidad. `qr_token`, máximo 4096 caracteres, contiene el JWT completo cuando el mecanismo es QR.

```http
POST /api/v1/exams/42/offline/sync
Content-Type: application/json
Accept: application/json
```

```json
{
  "package_id": "1ba37ca3-1c61-4ed0-8740-a53776bb4d90",
  "list_id": 8, "version": 1, "room_id": 3,
  "events": [{
    "event_id": "74921761-bba4-4ca5-8fc5-27016fc534c7",
    "student_id": 101, "method": "MANUAL",
    "captured_at": "2026-11-02T10:05:00-04:00",
    "device_id": "puesto-A"
  }]
}
```

Sincronizando durante la ventana del examen, con todos los estados y versiones vigentes:

```json
{
  "data": {
    "exam_id": 42,
    "results": [{
      "event_id": "74921761-bba4-4ca5-8fc5-27016fc534c7",
      "status": "ACCEPTED", "code": "ENTRY_REGISTERED",
      "message": "Ingreso registrado.", "entry_id": 501
    }]
  }
}
```

Para QR, establecer `method: "QR"` y enviar `qr_token` con el token real escaneado. No enviar una marca de validez del cliente. Los campos adicionales dentro de un evento producen `422`; campos de identidad o validez enviados fuera de los eventos no se utilizan.

La respuesta HTTP `200` significa que el lote fue procesado; hay que examinar cada resultado. Por ejemplo, un evento de un estudiante ajeno devuelve:

```json
{
  "event_id": "1cb3847a-87ce-4512-9075-66e89b7e787e",
  "status": "REJECTED", "code": "STUDENT_NOT_ASSIGNED",
  "message": "El estudiante no pertenece a esta lista.", "entry_id": null
}
```

La validación estructural se aplica antes de procesar el lote: un UUID/fecha mal formado o un lote mayor al límite devuelve `422` sin procesar eventos. Los errores funcionales se resuelven individualmente; un lote puede mezclar aceptaciones, duplicados, conflictos y rechazos.

## 10. Idempotencia y prevención de duplicados

- `event_id` es global y único. Se normalizan UUID, enteros, fechas equivalentes y campos opcionales antes de calcular la huella del contenido. No depende del orden de claves JSON.
- Un reenvío del mismo usuario con el mismo contenido devuelve exactamente el resultado guardado, también después de revocación o cancelación, siempre que siga teniendo cuenta/sesión activa y sea dueño del paquete. No crea otra auditoría de procesamiento ni otro ingreso.
- Reutilizar el UUID con contenido o actor distinto devuelve `CONFLICT EVENT_ID_REUSED`, sin sobrescribir el evento original ni revelar su resultado. Se audita el intento por separado.
- Cambiar de paquete/lista/versión, dispositivo o evidencia QR cambia el contenido del evento. Los reintentos deben conservar el paquete original.
- Un UUID nuevo para un estudiante con un ingreso válido devuelve `DUPLICATE ALREADY_REGISTERED`, siempre que las condiciones previas sigan siendo verificables.
- El evento registra la captura declarada y la recepción real; `exam_entries.entered_at` usa **la hora del servidor**, nunca retrodata asistencia con el reloj del teléfono.

Cada evento adquiere primero `exams FOR UPDATE`, igual que HU13/HU14, HU15 y cambios/cancelación del examen. Luego bloquea la cuenta responsable con `FOR NO KEY UPDATE`, el estudiante y sus registros de inscripción/habilitación. El bloqueo de cuenta conserva compatibilidad con los chequeos FK de auditoría existentes. Transacciones con hasta tres intentos ante deadlock.

La unicidad de `event_id` y el índice parcial existente en `exam_entries` son la protección final, incluso frente a escritores concurrentes. Las inserciones usan `insertOrIgnore` (`ON CONFLICT DO NOTHING` en PostgreSQL), luego leen el resultado persistido, evitando continuar una transacción PostgreSQL abortada por capturar una excepción de unicidad.

Ingreso, evento y auditoría se confirman juntos. No se crea asistencia cuando el resultado es conflicto/rechazo. Los resultados anteriores del lote ya confirmados sobreviven a un fallo de infraestructura en un evento posterior: tras un `500`, reenviar los mismos UUID permite recuperar los resultados confirmados y reintentar lo pendiente.

## 11. Estados y tratamiento de conflictos

| Estado | Significado | Códigos principales |
| --- | --- | --- |
| `ACCEPTED` | Ingreso oficial persistido. | `ENTRY_REGISTERED` |
| `DUPLICATE` | Ya existe un ingreso válido; no se inserta otro. | `ALREADY_REGISTERED` |
| `CONFLICT` | Evidencia o estado desactualizado/ambiguo; requiere revisión. | `EVENT_ID_REUSED`, `LIST_VERSION_MISMATCH`, `AUTHORIZATION_CHANGED`, `EXAM_CHANGED`, `EXAM_CANCELLED`, `EXAM_ENDED`, `EXAM_NOT_STARTED`, `EXAM_FINISHED`, `EXAM_STATE_NOT_ALLOWED`, `CAPTURE_TIME_UNVERIFIABLE`, `STUDENT_DATA_CHANGED`, `QR_EXPIRED_UNVERIFIABLE`, `QR_REVOKED` |
| `REJECTED` | Condición verificable incumplida. | `STUDENT_NOT_ASSIGNED`, `ROOM_MISMATCH`, `ENTRY_CONDITIONS_NOT_MET`, `INVALID_QR`, `QR_MISMATCH` |

La precedencia de validaciones puede determinar el primer motivo mostrado: por ejemplo, un examen modificado y cancelado puede producir `EXAM_CHANGED` antes que `EXAM_CANCELLED`. Ambos impiden crear asistencia. Se conservan el estado final y un motivo por evento; no hay panel ni API de resolución manual en esta versión.

## 12. Limitaciones y política conservadora

- Solo distribución versión 1, congelada. No existe regeneración, cambio de responsable, liberación de listas ni rescate automático de paquetes revocados. Esto también puede bloquear trabajo cuando un colaborador es sustituido; requiere una evolución explícita con historial.
- Se permite aceptar sincronización durante la ventana vigente, con condiciones actuales comprobadas y snapshot compatible. No se afirma haber verificado criptográficamente el momento histórico de captura.
- Sincronización después del cierre, cancelación, cambio de autorización o QR expirado queda en conflicto aunque el dispositivo declare haber capturado antes. No existe período de gracia institucional inventado.
- La revisión de habilitación detecta modificaciones aun si el estado vuelve a `ELIGIBLE`. Para otras entidades se comparan estados/datos actuales: no se garantiza detectar toda transición intermedia que vuelva exactamente al mismo valor. Definir revisiones institucionales adicionales es trabajo futuro.
- Se conserva evidencia mínima y huellas; el token QR completo no se persiste ni se incluye en auditoría.
- Las listas exclusivas se aplican a las nuevas API offline. Las rutas online conservan las facultades y contratos de los sprints anteriores.
- No hay firma del paquete para probar integridad local, caducidad criptográfica offline, dispositivo confiable, cifrado del almacenamiento del navegador ni política de retención automática implementada.
- La concurrencia real en PostgreSQL no se considera demostrada por las pruebas SQLite. La prueba multiproceso queda disponible y pendiente de ejecución autorizada.

## 13. Trabajo pendiente del frontend

1. Preparación y supervisión docente usando las cuatro rutas existentes nuevas.
2. Descargar y almacenar paquete, identidad responsable, UUID, versión, vigencia y snapshot mínimo en almacenamiento local adecuado; gestionar borrado al cerrar sesión y política de datos personales.
3. Mostrar únicamente estudiantes de la lista propia y estados conocidos, advirtiendo que pueden cambiar.
4. Captura manual y escaneo provisional de JWT completo, sin afirmar autenticidad offline.
5. Generar UUID por evento y conservar el payload original para reintentos; no reemplazar UUID para ocultar conflictos.
6. Enviar lotes de hasta 100 eventos y mantener resultados por evento, distinguiendo `ACCEPTED`, `DUPLICATE`, `CONFLICT` y `REJECTED` incluso ante HTTP `200`.
7. Gestionar pérdida/reemplazo de sesión según HU02; el modo offline no extiende la sesión del servidor.
8. Implementar PWA/IndexedDB/Service Worker y controles locales de duplicados, que complementan los controles del servidor.

## 14. Decisiones pendientes con el equipo y puntos de extensión

- Flujo de regeneración/versionado, invalidación de paquetes y transferencia explícita de responsables conservando historial.
- Criterios institucionales para sincronización posterior al cierre, evidencia temporal y resolución supervisada. Nunca habilitarlos basándose únicamente en la hora local.
- Diseño QR asimétrico y transición compatible con HU14; distribución y rotación de claves públicas y revocaciones.
- Orden lingüístico español frente al orden UTF-8 determinista actual y tratamiento de apellidos compuestos.
- Necesidad justificada de fotos y política de retención/borrado de snapshots y datos locales.
- Integración HU34 mediante el estado y `revision` reales de `exam_eligibilities`; no crear otra fuente de habilitación.
- Acordar si deben restringirse también las facultades online por lista en una HU futura, sin cambiar ahora HU13/HU14.

Extender `OfflineAssignmentService` para nuevas versiones; `OfflinePackageService` para campos mínimos adicionales; `OfflineSyncService` para evidencia y decisiones institucionales. Cambiar las reglas comunes de disponibilidad/habilitación en sus servicios compartidos solo con aprobación de alcance y pruebas de regresión.

## 15. Pruebas focalizadas y ejecución futura

### SQLite aislado

```powershell
php vendor/phpunit/phpunit/phpunit -c phpunit.offline.xml --do-not-cache-result
```

El XML fuerza `APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, URL de base vacía, claves solo de prueba, caché/sesión en memoria y deshabilita concurrencia PostgreSQL. `IsolatedOfflineDatabase` verifica esos valores y ausencia de configuración cacheada **antes** de conectar. Cada aplicación de prueba recibe una base nueva en memoria y ejecuta únicamente `migrate`, sin comandos de vaciado.

Incluye pruebas funcionales de distribución 34/33/33, orden y exclusividad, repetición, fallback docente, permisos, listas vacías, descarga propia, minimización, revocación y nuevo episodio, sincronización manual/QR, reenvíos, UUID reutilizado, duplicados, lote mixto, otra lista, inscripción/habilitación inválidas, cancelación, versión/ambiente incorrectos, tiempos imposibles y cambios de snapshot. Comprueba rollback de ingreso/evento ante fallo de auditoría.

`OnlineQrCompatibilityTest` hereda las pruebas QR existentes y reemplaza su preparación de base por el mismo mecanismo aislado sin vaciado. Esto verifica el contrato online del validador modificado sin ejecutar toda la suite EIDA ni modificar aquellas pruebas.

### PostgreSQL: ejecución pendiente y condicionada

`OfflinePostgresConcurrencyTest` omite la prueba antes de conectar si no hay autorización explícita, ambiente de pruebas y nombre `hu35_37_test_<12 hex>`. Si la base contiene tablas en `public`, la omite sin eliminarlas. Se requiere una base nueva PostgreSQL aislada, previamente creada y autorizada por el operador, configuración sin caché y sin `DB_URL`.

Ejemplo de ejecución **futura**, una vez provisionada y autorizada esa base, usando credenciales de pruebas en el entorno de esa terminal:

```powershell
$env:APP_ENV = 'testing'
$env:DB_CONNECTION = 'pgsql'
$env:DB_DATABASE = 'hu35_37_test_a1b2c3d4e5f6'
$env:DB_URL = ''
$env:EIDA_OFFLINE_PG_CONCURRENCY = '1'
php vendor/phpunit/phpunit/phpunit -c phpunit.xml tests/Feature/Offline/OfflinePostgresConcurrencyTest.php --do-not-cache-result
```

Establecer también `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` y SSL de ese destino aislado; no reutilizar credenciales de producción por conveniencia. No usar `phpunit.offline.xml` para esta prueba, pues fuerza SQLite y desactiva el indicador.

La prueba ejecuta migraciones normales en la base inicialmente vacía y deja sus datos para revisión. No borra ni reinicia datos. Para repetir, provisionar otra base vacía autorizada. El worker replica las guardas de destino. Dos procesos independientes compiten por el bloqueo de examen; el primero comprueba en `pg_stat_activity` que el segundo efectivamente espera un bloqueo. Verifica eventos distintos para un estudiante (aceptado/duplicado), mismo UUID simultáneo (mismo resultado) e ingreso manual online frente a offline (una asistencia oficial). La cuenta de pruebas debe poder observar sus propias sesiones en `pg_stat_activity`.

### Registro de validación

Ejecución focalizada completada con PHP 8.2.33 y PHPUnit 10.5.64: **67 pruebas descubiertas, 66 aprobadas, 1 omitida, 493 aserciones**. La omitida es exclusivamente la prueba de concurrencia PostgreSQL, por falta de habilitación explícita y destino aislado autorizado. Incluye regresión del contrato QR online existente.

Verificaciones adicionales: sintaxis PHP de todos los archivos nuevos y de los dos archivos PHP existentes modificados; Laravel Pint sobre el código nuevo y el validador; `git diff --check`; registro de exactamente cuatro rutas offline mediante `route:list`.

Las pruebas SQLite no sustituyen la ejecución PostgreSQL ni demuestran su concurrencia. No se ejecutó la suite completa ni se inició servidor/build frontend. Las migraciones ejecutadas por las pruebas fueron únicamente sobre SQLite `:memory:`; no se migró la base local existente. No se ejecutaron comandos de vaciado.

## 16. Inventario de cambios y estado Git

Archivos nuevos (18):

```text
app/Http/Controllers/Api/V1/Entries/OfflineExamController.php
app/Http/Requests/Offline/SyncOfflineEntriesRequest.php
app/Models/ExamOfflineDistribution.php
app/Models/ExamOfflineList.php
app/Models/ExamOfflinePackage.php
app/Services/Offline/OfflineAssignmentService.php
app/Services/Offline/OfflineExamAccess.php
app/Services/Offline/OfflinePackageService.php
app/Services/Offline/OfflineSyncService.php
database/migrations/2026_11_01_000001_create_exam_offline_tables.php
docs/HU35_HU36_HU37_OFFLINE_BACKEND.md
phpunit.offline.xml
tests/Feature/Offline/OfflineBackendTest.php
tests/Feature/Offline/OfflinePostgresConcurrencyTest.php
tests/Feature/Offline/OnlineQrCompatibilityTest.php
tests/Support/IsolatedOfflineDatabase.php
tests/Support/OfflineConcurrencyWorker.php
tests/Support/OfflineExamFixture.php
```

Archivos existentes modificados (2):

```text
app/Services/Entries/QrTokenValidator.php
routes/api/v1.php
```

El primero conserva el contrato `decode` online y extrae autenticación de evidencia para offline; el segundo incorpora únicamente las cuatro rutas nuevas. No se modificaron otros archivos previamente versionados ni archivos frontend.

Staging estaba vacío y se conserva vacío; su huella inicial/final es `e69de29bb2d1d6434b8b29ae775ad8c2e48c5391`. La rama y HEAD indicados en la auditoría permanecen iguales. No había modificaciones previas que sobrescribir. No se realizaron commits, push ni operaciones Git de preparación/restauración.
