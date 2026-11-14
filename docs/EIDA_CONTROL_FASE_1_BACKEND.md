# EIDA Control — fase 1 del backend

Fecha: 5 de noviembre de 2026. Implementación local; no desplegada.

## 1. Alcance

Autenticación móvil real sobre los mismos usuarios y Sanctum, registro y revocación de instalaciones, y prototipo criptográfico QR v3 aislado. No se crearon roles ni se modificó el frontend.

HU11/HU14 siguen emitiendo y validando QR v2 HS256. HU35–HU37 conservan sus contratos JSON y reglas de negocio. Ninguna ruta productiva emite QR v3 y no se añadió endpoint HTTP de claves públicas en esta fase.

## 2. Arquitectura

- `MobileLoginRequest`: estructura y normalización; reutiliza `ValidateLoginIdentifier` para la política institucional HU02.
- `MobileAuthService`: credenciales mediante `LoginUserResolver`, comprobación bajo bloqueo de usuario, emisión Sanctum y auditoría transaccional.
- `MobileOperatorAccess`: reutiliza `ExamManagementPolicy::canView` para un examen propio y las reglas de candidatura/disponibilidad de colaboradores.
- `MobileDeviceService`: presentación explícita sin secretos, consulta propia, revocación y revocación por cambio de contraseña.
- `MobileTokenPermissions`: abilities fijas y lista explícita de operaciones permitidas.
- `EnforceMobileTokenAccess`: en el grupo API; identifica tokens móviles por vínculo o prefijo reservado, deja a Sanctum validar hash/vencimiento y comprueba instalación, cuenta y ability.
- Controladores pequeños separados de los controladores de ingreso.
- `QrV3Service`, `QrV3KeyRing`, `QrV3Factory`: prototipo separado del validador v2.

La lista permitida se aplica a todo token móvil, incluso si por error recibe `*`. Una cookie SPA no puede ocultar un token móvil vigente para obtener autoridad web: la mezcla se rechaza con `MOBILE_TOKEN_INVALID`. Cookies SPA sin token móvil y tokens personales anteriores mantienen el comportamiento existente.

## 3. Persistencia y despliegue

Nueva migración: `database/migrations/2026_11_06_000001_create_mobile_devices.php`.

Tabla `mobile_devices`:

| Campo | Función |
| --- | --- |
| id | Identificador servidor de instalación por usuario |
| user_id | FK a usuario existente |
| installation_id | UUID declarado por el cliente; único junto a user_id |
| name | Nombre visible, máximo 100 caracteres |
| platform | android |
| app_version | Opcional, máximo 40 caracteres |
| last_login_at | Última autenticación de la instalación |
| revoked_at | Revocación lógica |
| created_at, updated_at | Historial temporal |

`personal_access_tokens.mobile_device_id`: FK nullable. No cambia la estructura de los tokens anteriores.

El UUID de instalación identifica una instalación declarada; no prueba identidad física, no es secreto ni atestación. No se impone exclusividad por lista o por celular.

**No se aplicó la migración a la base local real ni a producción.** Solo se aplica automáticamente en SQLite `:memory:` durante las pruebas aisladas. El operador deberá revisar el destino y aplicar esta migración mediante el proceso de despliegue antes de activar el código móvil y las acciones de contraseña que la utilizan. No hace falta seeder.

## 4. Login móvil

`POST /api/v1/auth/mobile/login`

Cabeceras: `Accept: application/json`, `Content-Type: application/json`. Enviar sin cookie ni Bearer. La ruta excluye el middleware stateful de Sanctum, incluso con un Origin de SPA; no inicia una sesión web.

Petición:

```json
{
  "identifier": "docente@umss.edu.bo",
  "password": "<credencial enviada solo para autenticar>",
  "installation_id": "7c4dfeaf-e588-4db1-9524-496a7905582d",
  "device_name": "Android del responsable",
  "app_version": "0.1.0"
}
```

Se admiten el correo institucional y los códigos existentes que resuelve HU02. Se conservan su política de dominio, longitud y validación de contraseña. El cliente no elige roles, abilities ni vencimiento.

Respuesta HTTP 200 (datos ilustrativos):

```json
{
  "data": {
    "access_token": "<token Sanctum entregado una sola vez>",
    "token_type": "Bearer",
    "expires_at": "2026-11-06T02:05:00.000000Z",
    "abilities": [
      "mobile:session", "mobile:devices", "control:read",
      "offline:prepare", "offline:download", "offline:sync"
    ],
    "device": {
      "id": 1,
      "installation_id": "7c4dfeaf-e588-4db1-9524-496a7905582d",
      "device_name": "Android del responsable",
      "platform": "android",
      "app_version": "0.1.0",
      "status": "ACTIVE",
      "last_login_at": "2026-11-05T14:05:00.000000Z",
      "revoked_at": null
    },
    "user": {
      "id": 7,
      "display_name": "Responsable de ejemplo",
      "email": "docente@umss.edu.bo",
      "status": "ACTIVE",
      "must_change_password": false,
      "roles": ["DOCENTE"]
    },
    "server_time": "2026-11-05T14:05:00.000000Z"
  }
}
```

`Cache-Control: private, no-store`. Contraseña, hash, APP_KEY y credencial del colaborador no se devuelven. Sanctum persiste únicamente el hash del token. Auditoría registra instalación y vencimiento, nunca token o contraseña.

Autorización de emisión:

- Cuenta global ACTIVE.
- Contraseña inicial ya cambiada; en caso contrario completar el flujo existente en EIDA Web.
- Al menos un examen propio que permita `ExamManagementPolicy::canView`, o colaboración ACTIVE no revocada, examen disponible y candidatura vigente.
- No se crea un rol COLABORADOR. Se conservan las reglas actuales de perfiles/roles; no se redefine el dominio académico.

Errores:

| HTTP | Significado |
| --- | --- |
| 422 | Credenciales/identificador/campos inválidos; errores Laravel por campo |
| 403 PASSWORD_CHANGE_REQUIRED | Completar cambio inicial en la web |
| 403 MOBILE_OPERATOR_REQUIRED | Sin examen propio ni colaboración vigente |
| 429 | Límite de intentos |
| 503 MOBILE_CONFIGURATION_INVALID | Vencimiento configurado fuera del intervalo permitido |

Rate limiting separado del login web: 5 solicitudes/minuto por identificador normalizado e IP, y 30/minuto agregadas por IP. El despliegue debe preservar la IP real y usar caché compartida si hay múltiples instancias; el almacenamiento file limita por instancia.

## 5. Tokens, dispositivos y sesiones

`EIDA_MOBILE_TOKEN_TTL_MINUTES`: predeterminado 720 (12 horas), rango 1–10080 minutos. Siempre se fija `expires_at`; si Sanctum tiene un límite global positivo menor, se usa el menor de los dos también en la fecha devuelta.

Un nuevo login de la misma cuenta/installation_id reutiliza su fila y rota sus tokens. Otras instalaciones y `users.active_session_id` no cambian. Reautenticarse con credenciales correctas puede reactivar una instalación revocada: la revocación cierra sus credenciales vigentes, no prohíbe físicamente el teléfono.

`GET /api/v1/auth/devices`, autenticado con Bearer o sesión web vigente:

```json
{
  "data": [{
    "id": 1,
    "installation_id": "7c4dfeaf-e588-4db1-9524-496a7905582d",
    "device_name": "Android del responsable",
    "platform": "android",
    "app_version": "0.1.0",
    "status": "ACTIVE",
    "last_login_at": "2026-11-05T14:05:00.000000Z",
    "revoked_at": null
  }]
}
```

Solo devuelve instalaciones propias, incluidas revocadas. No devuelve token/hash.

`DELETE /api/v1/auth/devices/{id}`: HTTP 204. Solo dispositivo propio; ajeno/inexistente: 404. Revoca todos los tokens ligados a esa instalación y marca revoked_at en una transacción auditada. Repetir la revocación no duplica la auditoría del cambio.

`GET /api/v1/me`: se reutiliza con Bearer.

`POST /api/v1/auth/logout`: conserva respuesta existente `{"message":"Session revoked successfully"}`. Para móvil, revoca su instalación; otros dispositivos y sesión web sobreviven. Para tokens anteriores y sesiones SPA conserva sus ramas existentes.

Cambio y recuperación de contraseña: eliminan tokens móviles y revocan instalaciones dentro de la transacción de contraseña, con bloqueo de usuario. Los tokens personales ajenos al módulo móvil conservan su política previa. El cambio web conserva la sesión y su actualización de hash existentes. La recuperación web no cambia sus contratos.

La revocación impide nuevas peticiones autenticadas; no cancela una petición que ya pasó autenticación. Estando offline, el dispositivo no puede conocer inmediatamente una revocación.

Servidor: utilizar HTTPS confiable, APP_DEBUG=false y un proxy que preserve Authorization. No se modificó .env ni se consultó la configuración efectiva del servidor desplegado. La APK deberá guardar el token en almacenamiento seguro y conservar la cola al vencer; nunca almacenar la contraseña.

## 6. Abilities realmente aplicadas

| Ability | Operaciones permitidas |
| --- | --- |
| mobile:session | GET /me, POST /auth/logout |
| mobile:devices | GET /auth/devices, DELETE /auth/devices/{id} |
| control:read | GET /teacher/exams, GET /teacher/exams/{exam}, GET /me/collaborations, GET /exams/{exam_id}/offline/assignments |
| offline:prepare | POST /exams/{exam_id}/offline/prepare |
| offline:download | GET /exams/{exam_id}/offline/package |
| offline:sync | POST /exams/{exam_id}/offline/sync |

Rutas relativas a `/api/v1`. Además de la ability, se ejecutan los middleware y servicios de autorización existentes. Un colaborador no puede preparar listas ni consultar exámenes docentes ajenos.

Otras API se rechazan con 403 MOBILE_ABILITY_REQUIRED, incluso para una cuenta que también sea administradora. Incidencias, fotos y control online necesitan una ampliación explícita posterior; no quedan abiertas por emitir el token.

Se comprueba cuenta activa en cada uso y que la instalación pertenezca al titular y no esté revocada. Un token móvil sin expires_at o sin vínculo válido se rechaza.

La revocación de colaboración impide descargas, pero un token móvil vigente puede enviar su paquete histórico a HU37, que conserva AUTHORIZATION_CHANGED por evento. No se sustituye esta comprobación por una autorización global que perdería conflictos. Si la colaboración ya venció y no queda otra función autorizada, no se emite un nuevo token solo para rescatar pendientes: ese flujo supervisado queda pendiente.

## 7. QR v2 comprobado y conservado

Emisor: `StudentQrService::generateStandardJwt`.
Validador: `QrTokenValidator::decode/decodeAuthentic`.

Header HS256/JWT; payload exacto v=2, jti UUID, exp Unix. Firma HMAC-SHA256 sobre los segmentos codificados con `config('app.key')` tal cual. Identidad y examen se resuelven en student_qr_tokens. Este código no se modifica.

No se modifica SERVER_ON_SYNC, la clasificación de resultados HU37 ni la emisión al estudiante.

## 8. Prototipo QR v3

Formato: JWS compacto, RS256 (RSASSA-PKCS1-v1_5 + SHA-256), RSA de al menos 2048 bits. No es RSA-PSS ni cifrado.

Header protegido, exactamente:

```json
{"alg":"RS256","kid":"interop-rsa-2026-01","typ":"eida-entry-qr+jwt"}
```

Payload, exactamente:

```json
{
  "v": 3,
  "jti": "f1cf8459-ae26-4def-9a24-294629314c17",
  "sub": "101",
  "exam_id": "42",
  "iss": "https://eida.example.test",
  "aud": "eida-control-qr",
  "iat": 1893456000,
  "nbf": 1893456000,
  "exp": 1893459600
}
```

sub y exam_id son cadenas decimales canónicas para evitar pérdida de precisión JavaScript; jti es UUID. No hay CI, SIS, nombres, fotos ni identificación del operador/dispositivo.

La prueba acepta solo JSON compacto con la codificación exacta del emisor (sin espacios internos, claves duplicadas ni escapes alternativos), base64url canónico sin padding y cabeceras/claims exactos. El orden de claves se conserva al decodificar; la verificación criptográfica utiliza los segmentos originales, sin reconstruirlos.

Se rechazan algoritmos distintos, typ incorrecto, kid no confiable, claves débiles/no RSA, cabeceras extras (incluido jku/crit), firma manipulada, emisor/audiencia distintos, claims extras/tipos incorrectos, estudiante/examen incompatibles, futuro o expiración. Ventana: iat <= nbf < exp y exp - iat <= 172800 segundos. No hay tolerancia temporal implícita en este prototipo.

`verify(token, expectedStudentId, expectedExamId, now)` solo necesita claves públicas. La firma no demuestra pertenencia a lista, habilitación, ausencia de revocación reciente ni ingreso confirmado. El prototipo no consulta ni escribe student_qr_tokens.

Este servicio estrecho prueba el contrato y la interoperabilidad; antes de activarlo en producción se revisará su perfil JOSE y la biblioteca final. No se afirma compatibilidad Android por el hecho de pasar PHP o Node.

## 9. Claves y configuración

Esta sección describe la configuración histórica de la fase 1. Para el cargador actual por variables de ejecución en Vercel, consulta la [guía de QR v3 offline](EIDA_BACKEND_QR_V3_OFFLINE.md#claves-configuración-y-rotación).

`config/qr_v3.php`: deshabilitado por defecto.

| Variable | Uso |
| --- | --- |
| EIDA_QR_V3_ENABLED | false por defecto; habilita solo resolver el servicio experimental |
| EIDA_QR_V3_KID | Identificador confiable de la clave |
| EIDA_QR_V3_ISSUER | Emisor esperado |
| EIDA_QR_V3_AUDIENCE | eida-control-qr por defecto |

QrV3KeyRing admite varias públicas por kid en memoria; la fábrica de configuración de esta fase carga una. Publicación JWKS, rotación operativa, revocaciones y distribución al paquete quedan pendientes. No hay endpoint público ni privado de emisión/verificación v3.

## 10. Vectores e interoperabilidad

`tests/Fixtures/qr-v3/rs256-vectors.json` contiene PEM público, huella SHA-256 del PEM exacto, claims, signing_input y diez casos con contexto y resultado esperado.

Casos: válido, payload manipulado, firma manipulada, algoritmo incorrecto, kid desconocido, cabecera inesperada, estudiante incorrecto, examen incorrecto, expiración exacta y todavía no vigente.

El reloj de prueba es fijo en 2030. No comparar los vectores con la hora real del teléfono. No son credenciales de producción.

Generación opcional:

```powershell
php tests/Support/GenerateQrV3Vectors.php
```

Solo carga el autoloader; no arranca Laravel ni lee .env. Genera una privada efímera en memoria, exporta únicamente la pública y descarta la privada. Regenerar reemplaza los vectores públicos, no reproduce exactamente la misma clave. Las pruebas normales consumen los vectores versionados sin regenerarlos.

En Windows, las pruebas aportan un archivo OpenSSL mínimo propio: no necesitan alterar openssl.cnf del sistema ni instalar otra biblioteca.

Verificador independiente de Node:

```powershell
node --test tests/Interop/qr-v3.node.mjs
```

Utiliza node:crypto y padding RSA_PKCS1_PADDING. No es una prueba de React Native/Hermes.

Próxima prueba Android: fijar Expo/RN y biblioteca nativa compatible; cargar solo public_key_pem; verificar los segmentos ASCII originales; ejecutar los diez casos con su now explícito; confirmar los rechazos y probar cámara/tamaño QR en APK real. No transportar la privada ni APP_KEY.

## 11. Pruebas ejecutadas

Resultados ejecutados con PHP 8.2.33, PHPUnit 10.5.64 y OpenSSL local:

| Verificación | Resultado |
| --- | --- |
| Conjunto focalizado inicial: móvil, regresiones web, HU35–HU37, QR v2 y QR v3 | 181 pruebas, 1492 aserciones; todas aprobadas |
| Reejecución de autenticación móvil y continuidad web tras añadir casos de configuración y rollback de contraseña | 40 pruebas, 702 aserciones; todas aprobadas |
| Revocación individual, después de validar IDs fuera de rango y cero | 1 prueba, 13 aserciones; aprobada |
| Interoperabilidad PHP → Node, vectores públicos | 10 pruebas; todas aprobadas |
| Sintaxis PHP del código creado/modificado | 33 archivos sin errores |
| Laravel Pint sobre los archivos PHP de esta fase | Formato aplicado correctamente |
| git diff --check | Sin errores |

La segunda ejecución cubre tres casos añadidos después de la primera y las clases afectadas por los ajustes. No son 221 pruebas distintas: el XML descubre 184 casos únicos en su estado actual. El resto de la primera ejecución no se reetiqueta como una nueva ejecución completa.

Comando seguro:

```powershell
php vendor/phpunit/phpunit/phpunit -c phpunit.mobile.xml --do-not-cache-result
```

El bootstrap aborta antes de arrancar Laravel si no hay testing, SQLite :memory:, DB_URL vacío y ausencia de configuración cacheada. Las pruebas usan migraciones normales en memoria, sin seeders ni comandos de vaciado. Las regresiones de sesión usan directorios temporales nuevos, no storage/framework/sessions.

Incluye autenticación móvil, dispositivos, abilities, Bearer HU35–HU37, revocaciones y contraseñas; regresiones focalizadas de login/sesión/logout/cambio inicial; pruebas QR v3 y compatibilidad online QR v2.

Pendientes: APK Android, PostgreSQL/concurrencia y servidor desplegado. No se ejecutan suites completas ni pruebas sobre datos reales.

## 12. Pendientes y límites

- Construir APK y demostrar PHP → Android antes de activar QR v3.
- Migración y revisión de configuración en el destino autorizado.
- Claves públicas en paquete y política de rotación/revocación.
- Fotos e incidencias autorizadas en el alcance móvil.
- Firma/cifrado/retención de paquetes en Android.
- Reconexión tardía, recuperación supervisada y resolución de conflictos.
- Política funcional de varios celulares por lista.
- Tratamiento uniforme de estados de perfiles y cuenta según política común existente.
- No hay refresh token: reautenticación online al vencer; conservar la cola local.
- Verificar concurrencia PostgreSQL con instalaciones, revocación y contraseña.
- Laravel 11 está fuera de soporte de seguridad; coordinar actualización sin mezclarla con este cambio.

## 13. Inventario de archivos

Creados:

```text
config/mobile.php
database/migrations/2026_11_06_000001_create_mobile_devices.php
app/Models/MobileDevice.php
app/Services/Auth/MobileOperatorAccess.php
app/Services/Auth/MobileTokenPermissions.php
app/Http/Middleware/EnforceMobileTokenAccess.php
app/Http/Requests/Auth/MobileLoginRequest.php
app/Services/Auth/MobileDeviceService.php
app/Services/Auth/MobileAuthService.php
app/Http/Controllers/Api/V1/Auth/MobileAuthController.php
app/Http/Controllers/Api/V1/Auth/MobileDeviceController.php
app/Services/QrV3/QrV3Exception.php
app/Services/QrV3/QrV3KeyRing.php
app/Services/QrV3/QrV3Service.php
config/qr_v3.php
app/Services/QrV3/QrV3Factory.php
tests/mobile-bootstrap.php
phpunit.mobile.xml
tests/Support/IsolatedMobileDatabase.php
tests/Feature/Mobile/MobileAuthenticationTest.php
tests/Feature/Mobile/LegacyLoginCompatibilityTest.php
tests/Feature/Mobile/LegacySingleSessionCompatibilityTest.php
tests/Feature/Mobile/LegacyPasswordContinuityTest.php
tests/Feature/Mobile/LegacyLogoutCompatibilityTest.php
tests/Support/GenerateQrV3Vectors.php
tests/Unit/QrV3/QrV3Test.php
tests/Interop/qr-v3.node.mjs
tests/Support/qr-v3-openssl.cnf
tests/Support/QrV3TestKeys.php
tests/Fixtures/qr-v3/rs256-vectors.json
docs/EIDA_CONTROL_FASE_1_BACKEND.md
```

Modificados:

```text
app/Actions/Fortify/ResetUserPassword.php
app/Actions/Fortify/UpdateUserPassword.php
app/Providers/AppServiceProvider.php
app/Providers/FortifyServiceProvider.php
app/Services/Auth/AuthenticationService.php
bootstrap/app.php
routes/api/v1.php
```

## 14. Preservación y alcance Git

Inicio: backend main / ca126a63af4c3dfe1faa14b2dec38c5960bb4273; frontend main / ad444911e2fb694614a05daeaa6f81776fe6f4c8. Ambos limpios inicialmente.

No se cambia de rama ni se modifica staging; no se realizan commits ni operaciones remotas. No se buscan archivos desde proyecto ni se accede a repositorios -compare. El frontend permanece de solo lectura. Se verifican rama, HEAD y huellas del índice al cerrar la tarea; el único diff autorizado es el código y la documentación enumerados arriba.

Verificación final: backend conserva main/ca126a63af4c3dfe1faa14b2dec38c5960bb4273 e índice SHA-256 DF856E45A5C95582E38183E60371CA82D640E302699D76510CD7AD34BA3085DA; frontend conserva main/ad444911e2fb694614a05daeaa6f81776fe6f4c8, árbol limpio e índice SHA-256 7AA3B09D75ECFE5A19C9F0603CB585A1BB92649848DFE7C43410CED433232E53. Staging sigue vacío en ambos. Se crearon 31 archivos y modificaron 7 archivos del backend; no había cambios previos que sobrescribir.

