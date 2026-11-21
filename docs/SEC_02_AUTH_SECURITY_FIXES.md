# SEC 02 Seguridad de autenticación contraseñas y sesiones

Fecha: 21 de noviembre de 2026, America/La_Paz.

Se implementaron las correcciones de enumeración en login (#9), revocación
transaccional al restablecer contraseña (#18) y política común de longitud (#19).
El endurecimiento de sesiones/tokens (#23) y protección del canal de recuperación
(#14) es parcial. Sustituir el CI como contraseña inicial (#3) queda pendiente de
una decisión institucional y un canal de entrega seguro comprobado.

Se añadieron 36 métodos de prueba focalizados y se ajustaron dos archivos de
pruebas existentes. No se ejecutó ninguna prueba durante SEC-02.

## Estado inicial y conservación de SEC 01

Repositorio: `C:/proyecto/oidevs-examenes-backend`.
Rama: `main`. HEAD: `272e955c90889fd0b9f9f018de68e35e1bec8af1`.

Al comenzar, SEC-01 tenía 24 archivos staged, sin cambios unstaged ni untracked.
No había MERGE_HEAD ni conflictos. Se registró una huella del índice y de sus
archivos para comprobar que el staging existente no cambiara.

El índice de SEC-01 permanece intacto. SEC-02 queda fuera del índice:
14 archivos existentes modificados y 11 nuevos, incluido este informe.
Tres archivos staged de SEC-01 tienen además ampliaciones unstaged de SEC-02:
.env.example, FortifyServiceProvider.php y config/sanctum.php. El resto de los
archivos de SEC-01 conserva también su contenido de trabajo.

No se cambiaron rama, rutas API, frontend, APK, migraciones, dependencias ni
datos reales. Tampoco se abrieron directorios -compare.

## Estado de los hallazgos

| Hallazgo | Estado | Corrección | Archivos | Pruebas añadidas | Pendientes |
| --- | --- | --- | --- | --- | --- |
| #3 CI inicial | Pendiente de decisión | Se conserva InitialPasswordService y la incorporación existente; se evaluaron alternativas de activación y entrega. | Sin cambio en InitialPasswordService, altas individuales o importaciones. Propuesta en este informe. | Regresión de login con CI corto y primer cambio; flujos de alta existentes recomendados. | Aprobar activación, verificar titularidad/correo y proveedor de entrega; no está corregido. |
| #9 Enumeración | Implementado | Sintaxis sin consulta de existencia docente; fallo genérico compartido; Hash::check con dummy válido para usuarios inexistentes; códigos institucionales con su capitalización original. | ValidateLoginIdentifier.php, MobileLoginRequest.php, LoginUserResolver.php, MobileAuthService.php, FortifyServiceProvider.php | LoginIdentifierSecurityTest (6), PasswordVerificationSecurityTest (4). | Ejecutar pruebas y medir en staging si se requiere una evaluación temporal. No se promete tiempo constante absoluto. |
| #18 Restablecimiento | Implementado; remember_token ya tenía una rotación parcial de Fortify | Rotar active_session_id y remember_token con contraseña, flag y revocación móvil bajo bloqueo y transacción; sincronizar modelo después de commit. | ResetUserPassword.php | PasswordResetSessionSecurityTest (5). | Validar flujo con cookies reales. Consumo del token de recuperación sigue a cargo del broker. |
| #19 Contraseñas | Implementado | Nuevas contraseñas entre 12 y 64 caracteres; espacios permitidos; máximo común 64 para login/cambio/reset; límite técnico bcrypt de 72 bytes al establecer. Login conserva credenciales históricas. | PasswordPolicy.php, PasswordValidationRules.php, ValidateLoginIdentifier.php, MobileLoginRequest.php, UpdateUserPassword.php; fixtures de LoginTest/PasswordRecoveryTest. | PasswordPolicySecurityTest (6), más casos de login web/móvil. | Ajustar mensajes/validadores de clientes si mantienen 20 caracteres; evaluación de comprometidas no habilitada. |
| #23 Sesiones/tokens | Parcialmente corregido; controles móviles ya corregidos anteriormente | Secure por defecto en producción. Se mantiene cifrado de sesiones como opt-in y expiration global null para respetar TTL móvil explícito y acotado. | config/session.php, comentarios config/sanctum.php, .env.example | SessionConfigurationSecurityTest (4), SessionTokenPolicySecurityTest (6). | Revisar overrides reales, planificar cifrado y política de tokens genéricos antiguos sin expires_at. |
| #14 Mailer log | Parcialmente corregido | Recuperación en producción rechaza log/array/fallbacks inseguros con 503 antes de generar token o notificación, incluyendo overrides MAIL_URL/driver legado. | PasswordResetDeliveryPolicy.php, EnsurePasswordResetDelivery.php, config/fortify.php, .env.example | PasswordResetDeliverySecurityTest (5). | Proveedor SMTP/API autorizado y entrega real verificada; la política no certifica conectividad ni credenciales. |

## Flujo de autenticación revisado

Web: POST /login pasa por middleware web/Fortify, normalización del identificador,
sesión vigente y rate limiting SEC-01. ValidateLoginIdentifier valida entrada;
LoginUserResolver resuelve correo/SIS/código y comprueba la contraseña.
Fortify inicia/regenera sesión y RecordLastLogin guarda active_session_id,
last_login_at y auditoría. Los recursos protegidos mantienen sesión vigente,
cuenta activa, primer cambio y roles/permisos según su ruta.

Móvil: POST /api/v1/auth/mobile/login usa MobileLoginRequest y el mismo resolver.
MobileAuthService bloquea primer cambio pendiente y exige autorización de operador.
En una transacción vuelve a comprobar cuenta/hash, vincula instalación, rota el
token de esa instalación y devuelve expires_at. EnforceMobileTokenAccess verifica
Sanctum, dispositivo, expiración y capacidades. No reemplaza la sesión web.

Cambio: PUT /user/password usa la acción compartida, comprueba current_password,
escribe hash/flag y revoca credenciales móviles. Se conserva el ajuste del hash
de sesión que evita cerrar la sesión actual autorizada después del primer cambio.

Recuperación: POST /forgot-password usa el broker y notificación de Fortify,
ahora precedidos por la comprobación de transporte en producción.
POST /reset-password mantiene guest:web, verifica token y ejecuta la acción.
No inicia sesión automáticamente; un login posterior establece un marcador nuevo.

Se inspeccionaron servicios de creación de estudiantes, docentes y personal,
CSV, auditoría, middleware, configuración de sesiones/mail y código vendor del
broker y acciones Fortify. No se consultó una BD para investigar estos flujos.

## Enumeración y verificación de contraseña

ValidateLoginIdentifier ya no consulta Teacher::exists() para decidir si un
identificador es válido. El contrato de creación de docentes admite códigos
no vacíos de hasta 50 caracteres, incluso códigos numéricos cortos. Ahora se
validan como formato sin averiguar si existen. Se conserva el máximo general
de identificador 100 y el camino numérico SIS existente. Los correos mantienen
validación de formato y dominio institucional configurado.

La validación de formato no se usa para ocultar errores sintácticos: un email
mal formado puede seguir produciendo 422 de formato, de manera independiente
de si existe una cuenta. Un correo/SIS/código de formato válido inexistente y
una contraseña incorrecta de una cuenta existente comparten errors.identifier
y trans('auth.failed') en web y móvil. Móvil cambia su texto anterior por el mismo
mensaje genérico de la web; conserva HTTP 422 y estructura de errores.

La condición de cuenta inactiva se comprueba después de verificar contraseña.
Se conserva su respuesta específica para credenciales correctas, sin autenticar.
Una contraseña incorrecta no permite distinguir si la cuenta está inactiva.

El resolver ejecuta Hash::check también cuando no encuentra usuario. Usa un
hash bcrypt de una credencial aleatoria descartada, que no pertenece a ninguna
cuenta. Con el driver/costo predeterminado no necesita generar otro hash. Si la
configuración de hashing cambia, prepara el dummy para esa configuración en
ambas rutas, antes de resolver al usuario, para no añadir trabajo solo a cuentas
inexistentes. Un resultado positivo de la comprobación dummy nunca autentica
si no hay usuario.

Esto elimina el cortocircuito sin comprobación criptográfica. No iguala
necesariamente consultas, cachés, transporte o hashes históricos con costos
distintos; no se midieron tiempos ni se afirma inmunidad a todo canal temporal.
Una configuración distinta al bcrypt/costo predeterminado añade trabajo de
preparación tanto a cuentas existentes como inexistentes; evaluar carga en staging.

La canonicalización global de Fortify bajaba también los códigos docentes a
minúsculas antes de buscarlos. Se retiró de este pipeline personalizado:
el resolver sigue comparando emails sin distinguir mayúsculas y los códigos
institucionales conservan exactamente la escritura de creación, igual que móvil.
No se introdujo una búsqueda ambigua entre códigos históricos que solo difieren
en mayúsculas. SIS/correo/código mantienen la prioridad de resolución previa.

Los límites por identificador y por IP de SEC-01 permanecen intactos. Sus
limitaciones de almacenamiento distribuido documentadas en SEC-01 siguen pendientes.

## Revocación al restablecer contraseña

La acción obtiene un User fresco con lockForUpdate y guarda juntos:

- Nuevo hash de contraseña.
- must_change_password=false.
- active_session_id aleatorio de 40 caracteres.
- remember_token aleatorio de 60 caracteres.
- Revocación y auditoría móvil mediante el servicio existente.

No se usa null como marcador de revocación: EnsureCurrentSession permite ausencia
de marcador para compatibilidad histórica; un valor aleatorio obliga a que
cookies antiguas no coincidan. Un login nuevo guarda después el ID correcto.

La acción sincroniza el modelo recibido solo después de confirmar su transacción.
Así no restaura datos obsoletos en el guardado posterior de Fortify y un fallo
de auditoría deja sin cambiar también el objeto recibido. Se preservan roles,
fotos y otros campos del usuario obtenido bajo bloqueo.

Fortify CompletePasswordReset ya rotaba remember_token después de la acción,
fuera de esta transacción; esa rotación adicional permanece. La corrección
añade atomicidad y protege también la invocación directa de ResetUserPassword.
No se modificó el broker: la eliminación del token de recuperación ocurre después
del callback, como en Laravel. No se certifica una nueva garantía de consumo
atómico bajo restablecimientos simultáneos.
[Flujo oficial de recuperación](https://laravel.com/docs/11.x/passwords).

La recuperación no mantiene autenticado un navegador antiguo. Se conserva el
guest:web existente; la prueba completa recuperación en una sesión sin autenticar,
reintenta la cookie anterior y luego inicia una sesión nueva con la nueva clave.
Se preserva la continuidad del primer cambio mediante PUT /user/password, que
tiene un objetivo distinto y no recibe esta rotación de active_session_id.

## Política de contraseñas

PasswordPolicy centraliza las reglas:

| Operación | Política |
| --- | --- |
| Primer cambio, cambio voluntario y recuperación | 12–64 caracteres, confirmación y sin bytes nulos |
| Login web y móvil | 1–64 caracteres no vacíos; permite credenciales históricas anteriores a la política nueva |
| current_password | Mismo máximo/compatibilidad del login, más comprobación actual del guard web |
| Nuevas contraseñas usando bcrypt | Además, máximo de 72 bytes UTF-8 |
| Credencial inicial por CI | Conservada por #3 pendiente; no se cambia ningún hash existente |

El mínimo más flexible del login es deliberado: no permite establecer nuevas
claves cortas, pero conserva claves existentes y CI de primer acceso. Toda
contraseña admitida al establecerse puede volver a pasar el validador de login.
No se impone mezcla de letras/símbolos/mayúsculas que impida frases seguras.
Los espacios de las contraseñas no se eliminan ni se normalizan.

bcrypt trunca más allá de 72 bytes; 64 caracteres ASCII caben, pero 64 caracteres
Unicode pueden ocupar más. Se rechazan nuevas claves que exceden ese límite
cuando el driver actual es bcrypt para evitar aceptar claves truncadas. Por
ejemplo, 36 caracteres é ocupan 72 bytes; 40 ocupan 80 y se rechazan al establecer.
No se cambia HASH_DRIVER ni se rehacen contraseñas existentes.
[Límite documentado de bcrypt](https://www.php.net/manual/en/function.password-hash.php).

No se habilitó uncompromised(): requiere una consulta externa de comprobación
de filtraciones y agrega disponibilidad/latencia de ese servicio. No se
configuró esa dependencia ni se introdujeron consultas externas en autenticación.
La política rechaza claves nuevas cortas; no afirma detectar toda contraseña
común o comprometida que cumpla longitud.
[Reglas de contraseña Laravel](https://laravel.com/docs/11.x/validation#validating-passwords).

Se ajustaron fixtures que esperaban máximo 20 o restablecimientos con 8 caracteres.
Los demás escenarios de primer cambio y sesión única se conservan para ejecución
manual. No hubo cambio general de contraseña ni se marcaron cuentas reales
para un cambio obligatorio.

## Sesiones y tokens

SESSION_SECURE_COOKIE tiene ahora fallback true cuando APP_ENV=production; HTTP
local/testing sigue permitido. Un valor explícito false sigue prevaleciendo:
corregirlo en el entorno real antes del despliegue. No se tocaron dominios,
SameSite=lax, HttpOnly, path=/ ni SESSION_LIFETIME predeterminado de 5 minutos.

SESSION_ENCRYPT ya era configurable. Se conserva false por compatibilidad porque
activar cifrado cambia el formato de almacenamiento y puede dejar ilegibles
sesiones antiguas. Para activarlo posteriormente se necesita un plan autorizado
de retirada de sesiones previas y un APP_KEY estable; no se hizo ese cambio ni
se cerraron sesiones reales ahora.

Los tokens móviles ya estaban protegidos por controles previos:

- EIDA_MOBILE_TOKEN_TTL_MINUTES predeterminado 720; rango permitido 1–10080.
- Cada emisión tiene expires_at explícito.
- Rechazo de configuración inválida antes de emitir dispositivos/tokens.
- Middleware rechaza expiración ausente, Sanctum rechaza la pasada.
- Vinculación, revocación y capacidades de dispositivo se mantienen.
- Nuevo login rota solo la instalación correspondiente.

Se conserva sanctum.expiration=null y no se conecta SANCTUM_EXPIRATION a un corte
global nuevo. Sanctum aplica la expiración global además de expires_at, y podría
acortar tokens móviles. Si código/configuración ya establece una expiración
global positiva explícitamente, se conserva el cap mínimo previo de MobileAuthService;
SEC-02 no lo activa ni lo elimina.
[Expiración de Sanctum](https://laravel.com/docs/11.x/sanctum#token-expiration).

Los PAT genéricos antiguos con expires_at=null son un pendiente real: hay
compatibilidad y consumidores históricos sin política propia revisada. No se
inventó una caducidad ni se revocaron indiscriminadamente. Antes de cerrarlo se
necesita inventario autorizado de emisores/consumidores y una política por cliente.
No se consultaron tokens reales.

La expiración o revocación móvil no borra paquetes, listas, evidencia ni UUID.
El cambio/restablecimiento revoca credenciales móviles según el servicio actual,
por lo que el operador deberá autenticarse de nuevo. Paquetes vinculados a la
instalación conservan las verificaciones de SEC-01. No se altera su cifrado.

## Credenciales iniciales y alternativas

InitialPasswordService sigue haciendo Hash::make(CI) y must_change_password=true.
Lo utilizan StudentRegistrationService (alta manual/importación), TeacherService
y StaffAccountService. No se cambiaron esos servicios ni las importaciones.
El CI sigue siendo una credencial predecible: #3 no está corregido.

Las altas actuales requieren correo: estudiantes y personal usan dominios
institucionales configurados; docentes también; el CSV de estudiantes comprueba
@umss.edu.bo. Esto acredita un campo validado, no su titularidad ni que el buzón
reciba mensajes. No se verificaron correo, SMTP o entregas de producción.

| Alternativa | Capacidades disponibles | Dependencias y decisión |
| --- | --- | --- |
| A Activación por enlace de un uso | Broker, tabla de tokens hashed, caducidad actual de 60 minutos, throttle de 60 segundos, notificación y página /reset-password existente. | Propuesta preferida después de aprobar activación y verificar proveedor/titularidad. Hay que definir si reutiliza recuperación o requiere propósito de activación separado, estados y auditoría. |
| B Clave temporal aleatoria | Generación segura y flag de primer cambio ya disponibles. | Falta canal seguro de entrega y política de expiración/reentrega. No usar CSV, logs, respuesta persistente ni email de clave permanente en texto plano. |
| C Otro canal institucional | No se encontró un canal alternativo de activación implementado y verificable. | Un proceso presencial/identidad institucional requeriría aprobación, implementación y trazabilidad; no se inventó uno. |

Aplicación por población:

| Población y flujo | Condición necesaria |
| --- | --- |
| Estudiante alta individual | Correo institucional y entrega verificada; conservar SIS, rol y auditoría. |
| Docente alta individual | Correo institucional, código exacto y entrega verificada; conservar perfil/rol y primer acceso. |
| Personal con rol personalizado | Correo y rol vigentes; activación no debe crear nuevos permisos ni roles implícitos. |
| Importación masiva | Decidir envío/reintentos por fila después de confirmar alta, evitar duplicar mensajes y poder informar estados sin secretos. No reejecutar CSV para recuperar una clave. |
| Correo ausente o inaccesible | El alta actual lo exige; un caso histórico/excepcional necesita corrección verificada o canal institucional aprobado, sin email ficticio ni fallback a CI como nueva solución. |
| Mensaje no recibido | Reenvío con throttling, caducidad y sustitución del token, control de titularidad y trazabilidad sin guardar el enlace en auditoría. |

Propuesta concreta pendiente: aprobar A, configurar/probar entrega real, definir
separación activación/recuperación y estados auditables, y luego sustituir el CI
de manera transaccional para altas nuevas. Para cuentas/importaciones existentes,
acordar transición y reenvío antes de tocar sus credenciales. No se crea una clave
aleatoria inaccesible como sustitución parcial.

## Recuperación y secretos en logs

El mailer log guarda el contenido del mensaje en logs y no constituye entrega
al usuario. Un reset por ese canal puede registrar su enlace/token.
La configuración también tenía failover SMTP→log.
[Driver log de Laravel](https://laravel.com/docs/11.x/mail#log-driver).

EnsurePasswordResetDelivery se registra en el middleware Fortify y solo actúa
sobre POST /forgot-password en APP_ENV=production. PasswordResetDeliveryPolicy
revisa el transport efectivo, el override de MAIL_URL y el driver legado; recorre
failover/roundrobin, rechazando ramas log/array, inexistentes o cíclicas.

Si el canal es inseguro/no configurado, responde antes del broker:

```json
{
  "code": "PASSWORD_RECOVERY_UNAVAILABLE",
  "message": "La recuperación por correo no está disponible. Comunícate con administración."
}
```

Estado HTTP 503 y Cache-Control private,no-store. No genera token ni notificación,
no escribe el enlace en log y no informa si el email existe. No impide consumir
por /reset-password un enlace válido previamente entregado. Local/testing mantiene
sus transportes de desarrollo; no usar APP_ENV=local para evitar el guard en
producción.

Este cambio hace explícita la falta de entrega, en vez de aparentar éxito mientras
el enlace termina en logs. No configura SMTP ni verifica credenciales/red. Un
transport real aún puede fallar por configuración y necesita validación autorizada.
El guard cubre esta ruta pública de recuperación; no es una auditoría de todos
los posibles envíos de correo de extensiones futuras.

No se añadieron enlaces de activación ni contraseñas temporales. LoginAuditService
guarda identificador de usuario, IP, user_agent y evento, sin password/token.
No se incorporaron nuevos logs de entrada, hashes de usuarios, claves o enlaces.

## Archivos de SEC 02

Inventario relativo al estado de trabajo inicial; M modificado y A añadido:

```text
M .env.example
M app/Actions/Fortify/PasswordValidationRules.php
M app/Actions/Fortify/ResetUserPassword.php
M app/Actions/Fortify/UpdateUserPassword.php
M app/Actions/Fortify/ValidateLoginIdentifier.php
M app/Http/Requests/Auth/MobileLoginRequest.php
M app/Providers/FortifyServiceProvider.php
M app/Services/Auth/LoginUserResolver.php
M app/Services/Auth/MobileAuthService.php
M config/fortify.php
M config/sanctum.php
M config/session.php
M tests/Feature/Auth/LoginTest.php
M tests/Feature/Auth/PasswordRecoveryTest.php
A app/Http/Middleware/EnsurePasswordResetDelivery.php
A app/Services/Auth/PasswordPolicy.php
A app/Services/Auth/PasswordResetDeliveryPolicy.php
A tests/Feature/Security/LoginIdentifierSecurityTest.php
A tests/Feature/Security/PasswordPolicySecurityTest.php
A tests/Feature/Security/PasswordResetDeliverySecurityTest.php
A tests/Feature/Security/PasswordResetSessionSecurityTest.php
A tests/Feature/Security/PasswordVerificationSecurityTest.php
A tests/Feature/Security/SessionConfigurationSecurityTest.php
A tests/Feature/Security/SessionTokenPolicySecurityTest.php
A docs/SEC_02_AUTH_SECURITY_FIXES.md
```

No se modificó ningún archivo vendor, config/mail.php, migration ni esquema.
Los ajustes de mail son un guard del flujo existente y documentación de entorno,
no un cambio de proveedor.

## Pruebas focalizadas preparadas

| Archivo nuevo bajo tests/Feature/Security | Métodos | Cobertura |
| --- | --- | --- |
| LoginIdentifierSecurityTest.php | 6 | Errores genéricos, cero consultas en validación de código, email/SIS/código, personal, CI corto e inactivos en web/móvil. |
| PasswordVerificationSecurityTest.php | 4 | Hash dummy válido, comprobación existente/ausente, adaptación de driver/costo y dummy sin usuario nunca autentica. |
| PasswordPolicySecurityTest.php | 6 | Frase de 64 caracteres, límite 65, primer cambio, reset, ambos logins, Unicode/bcrypt, NUL y ausencia de HTTP externo. |
| PasswordResetSessionSecurityTest.php | 5 | Marcadores, remember, cookie antigua, tokens móviles, nueva clave, roles, replay inválido y rollback de auditoría. |
| SessionConfigurationSecurityTest.php | 4 | Secure prod/local, cifrado optativo en memoria, flags/duración y Sanctum sin corte global. |
| SessionTokenPolicySecurityTest.php | 6 | TTL explícito/máximo/inválido, expiración ausente/pasada, dispositivo revocado, rotación y conservación de paquetes. |
| PasswordResetDeliverySecurityTest.php | 5 | 503 en producción insegura, notificación fake con SMTP, modo local, fallbacks, MAIL_URL y driver legado. |

También se ajustaron LoginTest y PasswordRecoveryTest a las nuevas fronteras.
Las pruebas de BD usan la infraestructura aislada existente; las de verificación
criptográfica mockeada/configuración no necesitan BD. Las notificaciones se
simulan; no se enviaron correos. Ninguna prueba se ejecutó en esta tarea.

## Variables de entorno y operación

No hay variables nuevas obligatorias. .env.example conserva perfil local y añade
advertencias y requisitos de producción.

| Variable/configuración | Estado y requisito |
| --- | --- |
| APP_ENV | production en Vercel para Secure por defecto y guard de recuperación. |
| APP_KEY | Conservar secreto estable. No rotarlo para habilitar SESSION_ENCRYPT: afectaría otros datos cifrados. |
| SESSION_SECURE_COOKIE | true explícito en producción. Un false existente prevalece sobre el nuevo default. |
| SESSION_ENCRYPT | Sigue opt-in. Activación requiere planificar sesiones antiguas y reautenticación, sin borrar offline. |
| SESSION_LIFETIME | Se conserva 5 minutos por defecto; no se cambió la política institucional. |
| SANCTUM_EXPIRATION | No se añadió lectura global de esta variable; mantener TTL móvil propio. |
| EIDA_MOBILE_TOKEN_TTL_MINUTES | Ya soportada: 720 predeterminado, 1–10080. No usar expiración nula. |
| MAIL_MAILER | Transport real autorizado en producción; log/array no son entrega y bloquean recuperación con 503. |
| MAIL_URL | Si se usa, comprobar su scheme efectivo; el guard detecta overrides log/array. No incluir su secreto en comandos o informes. |
| MAIL_HOST, MAIL_PORT, MAIL_ENCRYPTION | Según proveedor autorizado, con transporte cifrado compatible; no se inventaron valores. |
| MAIL_USERNAME, MAIL_PASSWORD | Solo secretos del proveedor, nunca repositorio, exports de usuarios o logs. |
| MAIL_FROM_ADDRESS, MAIL_FROM_NAME | Remitente autorizado y verificado. |
| config/mail.php mailers.failover | Todos sus destinos deben ser canales reales; no dejar log como fallback de producción. |
| HASH_DRIVER, BCRYPT_ROUNDS | No se modificaron. Mantener compatibilidad de hashes existentes; no cambiar algoritmo como atajo para la longitud. |

No se leyeron ni cambiaron variables reales de Vercel/Railway o credenciales .env.

## Impacto en frontend

| Página/flujo | Ruta backend | Impacto |
| --- | --- | --- |
| Login | POST /login | Mantiene identifier y alias email, 422 y campos de error. Código docente exacto, email sin distinguir mayúsculas, error genérico uniforme. Login admite credenciales históricas. |
| Primer cambio y cambio voluntario | PUT /user/password | Nuevas reglas 12–64 y límite byte bcrypt; conserva current_password y confirmation, continuidad de sesión actual y flag obligatorio. |
| Olvidé mi contraseña | POST /forgot-password | Nuevo 503 PASSWORD_RECOVERY_UNAVAILABLE en producción sin canal seguro. React debe mostrar indisponibilidad y no un éxito ficticio. |
| Restablecer | POST /reset-password | Nuevas reglas; revoca sesiones anteriores y dispositivos móviles. Sigue sin auto-login y exige iniciar sesión con clave nueva. |
| Alta/importación de estudiantes/docentes/personal | Rutas existentes sin cambios | Sigue CI inicial y primer cambio; propuesta de activación pendiente. No se agregó un campo de secreto o enlace de entrega a respuestas. |

Si los formularios React mantienen máximos de 20 o mínimos de 8 para claves nuevas,
deben actualizarse a 12–64 y mostrar el error de 72 bytes cuando corresponda. El
login no debe imponer mínimo 12 a credenciales históricas ni pasar a minúsculas
códigos institucionales. No se inspeccionó ni modificó el repositorio React; los
nombres/rutas exactos de sus páginas no se certifican aquí.

CORS, CSRF, origen autorizado, fotografías, roles personalizados y permisos de
SEC-01 se conservan. La recuperación continúa construyendo enlaces desde
FRONTEND_URL; no se inventaron dominios.

## Impacto en EIDA Control

POST /api/v1/auth/mobile/login conserva campos, Bearer, instalación, device_name,
access_token y expires_at. El mensaje de credenciales inválidas se unifica con
la web; HTTP 422 y errors.identifier permanecen. El primer cambio sigue haciéndose
en web y devuelve PASSWORD_CHANGE_REQUIRED en móvil.

Android necesitará permitir hasta 64 caracteres si su UI mantiene el máximo
anterior. No imponer allí mínimo 12 para login histórico. No se modificó la APK.

Los dispositivos y TTL se conservan. Después de cambiar/restablecer contraseña
se necesita nuevo login móvil por revocación existente. No se borran colas
offline, listas, recibos, evidencia o claves locales. Los eventos pendientes
conservan sus UUID y reglas de dispositivo/protocolo de SEC-01; reautenticarse
no transforma rechazos previos en ACCEPTED.

## Validación local posterior

No ejecutar sobre una BD persistente ni con config cacheada de producción.
Usar phpunit.offline.xml, que fuerza APP_ENV=testing, DB_URL vacío, SQLite
:memory:, sesión/caché array y evita pruebas de concurrencia contra PostgreSQL.
Los helpers validan aislamiento antes de crear su esquema de prueba.

Comandos para ejecución manual por el usuario, no ejecutados:

```powershell
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/LoginIdentifierSecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/PasswordVerificationSecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/PasswordPolicySecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/PasswordResetSessionSecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/SessionConfigurationSecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/SessionTokenPolicySecurityTest.php
php vendor/bin/phpunit -c phpunit.offline.xml tests/Feature/Security/PasswordResetDeliverySecurityTest.php
```

Después, revisar por archivo las regresiones existentes de LoginTest,
PasswordRecoveryTest, FirstAccessTest, InitialPasswordSessionContinuityTest,
SingleSessionTest, MobileAuthenticationTest, LegacyLoginCompatibilityTest,
LegacyPasswordContinuityTest, TeacherStatusSessionTest,
AdminAccountIntegrationTest y CustomRolesBlockOneTest. Los casos antiguos de
sesiones file deben usar sus helpers/entorno aislado correspondiente.

Repetir focalmente SEC-01: HttpBoundarySecurityTest, BrowserOriginsTest,
StudentQrErrorSecurityTest, RoomSelectionSecurityTest,
MobileOfflineSyncSecurityTest y OfflineSyncEvidenceTest, con su configuración
aislada. No se ejecutó ninguna de estas regresiones durante SEC-02.

## Requisitos antes del despliegue

1. Ejecutar las pruebas preparadas y revisar errores sin eliminar aserciones.
2. Revisar el diff de SEC-02 separado del índice SEC-01 y completar el commit
   manual solo tras aprobación del usuario.
3. Validar en staging login de roles oficiales/personal, códigos exactos, correo,
   SIS, primer cambio con frase larga y recuperación desde otra sesión.
4. Configurar un proveedor real autorizado y probar entrega, dominio remitente,
   enlace HTTPS/origen correcto, token de un uso y recuperación si no llega.
   Hasta entonces la recuperación en producción con log devuelve 503.
5. Acordar y actualizar mensajes/validadores React/Android donde sea necesario,
   en sus propias tareas.
6. Confirmar Secure=true y decidir por separado la activación de cifrado de sesiones.
7. Inventariar PAT genéricos y acordar transición/caducidad; no imponer TTL global
   que acorte el contrato móvil.
8. Resolver #3 antes de declarar eliminadas las credenciales iniciales predecibles.
   No activar una sustitución parcial sin entrega.
9. Mantener los pendientes de infraestructura/TLS de SEC-01; esta tarea no los
   modifica ni certifica producción.

## Verificación estática y Git final

Se comprobó sintaxis con php -l en los 23 archivos PHP afectados por SEC-02,
sin errores; 53 imports App tienen archivo existente. git diff --check está limpio.
Se verificó que el árbol staged de SEC-01 continúa idéntico a la huella inicial.

Estado final: main en 272e955c90889fd0b9f9f018de68e35e1bec8af1, sin operación Git
incompleta. SEC-01 conserva 24 archivos staged. SEC-02 agrega 14 archivos con
cambios unstaged y 11 archivos nuevos sin seguimiento, contando este informe.
No hubo git add ni cambio de rama durante SEC-02.

No se ejecutaron pruebas, Artisan, migraciones, seeders, instalaciones, builds,
npm, Gradle, EAS, despliegues, conexión a producción ni cambios de infraestructura.
No hubo commit, push, merge, rebase, reset, clean o stash.

## Corrección de dos errores de preparación de pruebas SEC-02

La ejecución compartida por el usuario produjo un error en cada clase:
PasswordPolicySecurityTest (6 pruebas, 27 aserciones, 1 error) y
PasswordResetSessionSecurityTest (5 pruebas, 39 aserciones, 1 error).
Las otras cinco clases nuevas pasaron según el resultado comunicado.

- test_bcrypt_byte_limit_and_null_bytes_cannot_silently_truncate_new_passwords:
  HashManager no implementa setDefaultDriver(); Manager reenvía esa llamada al
  BcryptHasher, que tampoco la implementa. Se selecciona bcrypt mediante
  config(['hashing.driver' => 'bcrypt']), la configuración que lee HashManager.
  Se conservan las aserciones de aceptación de 36 caracteres é (72 bytes),
  rechazo de 40 caracteres é (80 bytes) y rechazo de bytes nulos.
- test_direct_reset_rotates_both_session_secrets_and_revokes_only_mobile_credentials:
  createUserProvider() sin argumento consulta auth.defaults.provider, ausente en
  config/auth.php, y devuelve null. Se obtiene el proveedor real del guard web
  con Auth::guard('web')->getProvider(). Se conservan las aserciones de rechazo
  del remember_token anterior, aceptación del nuevo, rotación de sesión,
  sincronización del modelo y revocación selectiva de credenciales móviles.

Son errores de uso de las API en las pruebas; los errores comunicados no
demuestran un defecto de producción. Esta corrección solo modifica las dos
clases y esta documentación. No cambia política de contraseñas, autenticación,
sesiones, recuperación, frontend ni EIDA Control. Las aserciones que seguían
al punto de error necesitan volver a ejecutarse para confirmar su resultado.
Por instrucción del usuario, no se ejecutaron PHPUnit, migraciones ni seeders
durante esta corrección; la revisión de sintaxis no sustituye esas pruebas.
