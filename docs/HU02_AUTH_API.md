# HU02 — Contrato de Autenticación y Sesión

## Vision general

EIDA autentica la SPA con Laravel Fortify, Laravel Sanctum y cookies de sesion. La SPA no usa Bearer Token para este flujo y no debe guardar credenciales ni tokens en `localStorage` o `sessionStorage`.

Flujo base:

```text
Frontend
  -> GET /sanctum/csrf-cookie
  -> POST /login
  -> GET /api/v1/me
  -> usuario + display_name + roles + first access
```

Axios debe enviar credenciales/cookies en las peticiones a Sanctum, Fortify y `/api/v1`.

## Configuracion local necesaria

Variables relevantes segun `.env.example`:

```dotenv
FRONTEND_URL=http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,localhost:5173
SESSION_DRIVER=file
SESSION_LIFETIME=5
EIDA_INSTITUTIONAL_EMAIL_DOMAINS=umss.edu.bo
```

En `.env.example`, `EIDA_INSTITUTIONAL_EMAIL_DOMAINS` existe sin valor por defecto; en desarrollo debe configurarse con el dominio institucional que corresponda. Despues de cambiar variables de entorno:

```bash
php artisan optimize:clear
```

`SANCTUM_STATEFUL_DOMAINS` permite que Sanctum trate al frontend React como una SPA stateful basada en cookies.

## Endpoint: CSRF

```http
GET /sanctum/csrf-cookie
```

Obtiene la cookie XSRF antes de llamar a Login desde la SPA.

Respuesta JSON real para requests JSON:

```text
HTTP 204 No Content
```

## Endpoint: Login

```http
POST /login
```

Login por correo institucional:

```json
{
  "identifier": "usuario@umss.edu.bo",
  "password": "Password1"
}
```

Login por Codigo SIS:

```json
{
  "identifier": "202300123",
  "password": "Password1"
}
```

Regla de resolucion:

| Identifier | Interpretacion |
| --- | --- |
| Contiene `@` | Correo institucional |
| No contiene `@` | Codigo SIS |

Validaciones importantes:

| Campo | Reglas |
| --- | --- |
| `identifier` | requerido, string, maximo 100 |
| Correo | formato email valido y dominio institucional permitido |
| SIS | solo numeros, minimo 9 digitos |
| `password` | requerido, string, minimo 8, maximo 20 |

La compatibilidad legacy permite enviar `email`, pero frontend debe usar `identifier`.

Respuesta de exito real:

```http
HTTP 200 OK
```

```json
{
  "two_factor": false
}
```

Despues de Login, el frontend debe consultar `GET /api/v1/me`.

Credenciales incorrectas o usuario `INACTIVE` devuelven validación `422 Unprocessable Content`.

Credenciales incorrectas:

```http
HTTP 422 Unprocessable Content
```

```json
{
  "message": "These credentials do not match our records.",
  "errors": {
    "identifier": [
      "These credentials do not match our records."
    ]
  }
}
```

Usuario `INACTIVE`:

```http
HTTP 422 Unprocessable Content
```

```json
{
  "message": "La cuenta se encuentra inactiva.",
  "errors": {
    "identifier": [
      "La cuenta se encuentra inactiva."
    ]
  }
}
```

## Endpoint: Usuario autenticado

```http
GET /api/v1/me
```

Es el endpoint principal para conocer usuario actual, estado, roles y primer acceso.
`display_name` es una representacion para interfaz: no sustituye los endpoints
de perfil ni expone CI, SIS u otros datos especificos.

Contrato real:

```http
HTTP 200 OK
```

```json
{
  "success": true,
  "data": {
    "id": 1,
    "display_name": "Juan Perez",
    "email": "usuario@umss.edu.bo",
    "status": "ACTIVE",
    "must_change_password": false,
    "roles": [
      "ADMINISTRADOR"
    ]
  }
}
```

Regla de `display_name`:

| Perfil disponible | Valor |
| --- | --- |
| Teacher | `teacher.first_names + teacher.last_names` |
| Student | `student.first_names + student.last_names` |
| Sin perfil nominal | `user.email` |

Si existen Teacher y Student para el mismo usuario, Teacher tiene precedencia.
Si el nombre calculado queda vacio, se usa `user.email`.

## Roles

Roles oficiales definidos en `RoleName`:

| Rol |
| --- |
| `ADMINISTRADOR` |
| `DOCENTE` |
| `ESTUDIANTE` |

Frontend debe usar estos valores exactos y no inventar alias como `ADMIN`, `TEACHER` o `STUDENT`.

`/api/v1/me` solo expone roles activos: el rol debe estar `ACTIVE` y la asignacion en `role_user.status` tambien debe estar `ACTIVE`.

## Endpoint: Logout

```http
POST /logout
```

Invalida la sesion actual. `ClearActiveSessionOnLogout` limpia `users.active_session_id` solo si coincide con la sesion que hizo logout, para que una cookie vieja no pueda borrar una sesion nueva.

Respuesta JSON real:

```text
HTTP 204 No Content
```

## Sesion expirada

Cuando ya no existe autenticacion valida, Laravel responde:

```http
HTTP 401 Unauthorized
```

```json
{
  "message": "Unauthenticated."
}
```

Frontend debe limpiar el usuario en memoria y redirigir a Login si estaba en una ruta privada. No mezclar este caso con `SESSION_REPLACED`, porque una sesion expirada no incluye `code`.

## SESSION_REPLACED

Respuesta real de `EnsureCurrentSession`:

```http
HTTP 401 Unauthorized
```

```json
{
  "success": false,
  "message": "La sesion fue cerrada porque se inicio sesion en otro dispositivo.",
  "code": "SESSION_REPLACED"
}
```

Escenario:

```text
Navegador A inicia sesion
  -> Navegador B inicia sesion con la misma cuenta
  -> users.active_session_id pasa a la sesion B
  -> Navegador A intenta acceder
  -> recibe SESSION_REPLACED
```

Frontend debe detectar `code === "SESSION_REPLACED"` y mostrar un mensaje especifico.

## Primer acceso

El campo `users.must_change_password` identifica usuarios que deben cambiar su password despues del primer login.

Cuando `/api/v1/me` devuelve:

```json
{
  "must_change_password": true
}
```

frontend debe enviar al usuario al flujo obligatorio de cambio de password.

Respuesta real del middleware `password.changed`:

```http
HTTP 403 Forbidden
```

```json
{
  "success": false,
  "message": "Debe cambiar su contrasena antes de continuar.",
  "code": "PASSWORD_CHANGE_REQUIRED"
}
```

`InitialPasswordService` se usa al crear cuentas nuevas:

```text
CI
  -> Hash
  -> users.password
  -> must_change_password = true
```

Nunca se almacena el CI como contrasena en texto plano.

## Endpoint: Cambio de contrasena

```http
PUT /user/password
```

Payload real esperado por Fortify y `UpdateUserPassword`:

```json
{
  "current_password": "Password1",
  "password": "NewPassword1",
  "password_confirmation": "NewPassword1"
}
```

Reglas:

| Campo | Reglas |
| --- | --- |
| `current_password` | requerido, string, debe coincidir con la password actual del guard `web` |
| `password` | requerido, string, reglas default de Laravel `Password::default()`, maximo 20, confirmada |
| `password_confirmation` | debe coincidir con `password` |

Respuesta JSON real de exito:

```text
HTTP 200 OK
cuerpo vacio
```

Despues de un cambio exitoso, `must_change_password` pasa a `false`.

## Recuperacion de contrasena

Solicitar enlace de recuperacion:

```http
POST /forgot-password
```

Payload real:

```json
{
  "email": "usuario@umss.edu.bo"
}
```

Respuesta JSON real de exito:

```http
HTTP 200 OK
```

```json
{
  "message": "We have emailed your password reset link."
}
```

Restablecer password:

```http
POST /reset-password
```

Payload real:

```json
{
  "email": "usuario@umss.edu.bo",
  "token": "token-recibido",
  "password": "NewPassword1",
  "password_confirmation": "NewPassword1"
}
```

Reglas principales: `token` requerido, `email` requerido y valido, `password` requerido, confirmado y con maximo 20 por las reglas locales.

Respuesta JSON real de exito:

```http
HTTP 200 OK
```

```json
{
  "message": "Your password has been reset."
}
```

El reset exitoso tambien marca `must_change_password=false`.

## Roles + redireccion

| Rol | Panel esperado |
| --- | --- |
| ADMINISTRADOR | /admin |
| DOCENTE | /docente |
| ESTUDIANTE | /estudiante |

Esas rutas pertenecen a otras HUs. HU02 solo proporciona identidad y roles.

## Middlewares reutilizables

| Middleware | Uso |
| --- | --- |
| `auth:sanctum` | Autentica peticiones SPA stateful mediante cookies de Sanctum |
| `session.current` | Verifica que la cookie actual sea la sesion activa registrada en `users.active_session_id` |
| `password.changed` | Bloquea rutas protegidas cuando `must_change_password=true` |

Ejemplo conceptual para futuras rutas protegidas:

```text
auth:sanctum
  -> session.current
  -> password.changed
  -> autorizacion especifica del rol
```

La autorizacion ADMIN definitiva pertenece a HU03.

## Servicios reutilizables por otras HUs

| Pieza | Uso |
| --- | --- |
| `RoleName` | Nombres oficiales de roles |
| `User::hasRole()` | Comprueba si el usuario tiene un rol activo |
| `InitialPasswordService` | Crea password inicial basada en CI, hasheada y con primer acceso obligatorio |
| `LoginUserResolver` | Resuelve correo institucional o SIS hacia `User` |
| `AuditLog` | Infraestructura de auditoria |
| `GET /api/v1/me` | Identidad, display_name y roles de la sesion |

HU04 y HU05 deben reutilizar `InitialPasswordService`; no duplicar esa logica en docentes o estudiantes.

## Ejemplos para frontend

```ts
import axios from 'axios';

const httpClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000',
  withCredentials: true,
  withXSRFToken: true,
});

await httpClient.get('/sanctum/csrf-cookie');

await httpClient.post('/login', {
  identifier,
  password,
});

const response = await httpClient.get('/api/v1/me');
```

No guardar tokens en `localStorage` ni `sessionStorage`.

## Respuestas importantes

| Situacion | HTTP | Codigo/resultado |
| --- | ---: | --- |
| Login correcto | 200 | `{"two_factor": false}` y sesion creada |
| Credenciales incorrectas | 422 | Error de validacion en `identifier` |
| Usuario `INACTIVE` | 422 | Error de validacion en `identifier`: `La cuenta se encuentra inactiva.` |
| Sin sesion | 401 | `{"message": "Unauthenticated."}` |
| Sesion reemplazada | 401 | `SESSION_REPLACED` |
| Primer acceso en ruta protegida | 403 | `PASSWORD_CHANGE_REQUIRED` |
| Logout correcto | 204 | Sin cuerpo |
| Cambio de contrasena correcto | 200 | Cuerpo vacio |

Formato JSON real para errores de validacion:

```json
{
  "message": "The identifier field is required.",
  "errors": {
    "identifier": [
      "The identifier field is required."
    ]
  }
}
```

El texto de `message` cambia segun la regla que falle.

## Seguridad

- `password` nunca se devuelve.
- `active_session_id` nunca se devuelve.
- `identity_number`, `sis_code` e `institutional_code` nunca se devuelven en `/api/v1/me`.
- Las credenciales no van a `localStorage` ni `sessionStorage`.
- Las cookies administran la sesion.
- La password se almacena hasheada.
- Login exitoso genera auditoria `LOGIN`.
- Solo una sesion activa por usuario.
- Timeout actual de sesion: 5 minutos.

## Problemas comunes

### `GET /api/v1/me` devuelve 401 aunque Login parece correcto

Revisar:

- `FRONTEND_URL`
- `SANCTUM_STATEFUL_DOMAINS`
- cookies del navegador
- `withCredentials`
- `withXSRFToken`

Despues de ajustar `.env`:

```bash
php artisan optimize:clear
```

Caso ya detectado en el equipo: si faltan estas variables, Sanctum puede no reconocer correctamente la SPA como stateful.

```dotenv
FRONTEND_URL=http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,localhost:5173
```
