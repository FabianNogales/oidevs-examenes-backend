# EIDA — QA Sprint 1 / RUN-01

Artefactos preparados mediante inspección estática del backend y `php artisan route:list`. No se ejecutaron requests HTTP, pruebas de integración, migraciones ni seeders. No se modificó código funcional ni `.gitignore`; no se hizo commit ni push. Los resultados esperados de esta guía no son resultados de una ejecución QA.

## Archivos e importación

- `EIDA-QA-RUN01.postman_environment.json`: environment **EIDA - QA RUN-01**.
- `EIDA-QA-Sprint1-HU01-HU05.postman_collection.json`: colección **EIDA - QA Sprint 1 - HU01-HU05**, formato Postman v2.1.
- `README.md`: instrucciones y contratos comprobados.

Importar ambos JSON en Postman y seleccionar el environment. Backend: `http://127.0.0.1:8000`; frontend/origen: `http://127.0.0.1:5173`.

## Variables y secretos

| Variable | Valor inicial / acción |
| --- | --- |
| `base_url` | `http://127.0.0.1:8000` |
| `frontend_url` | `http://127.0.0.1:5173` |
| `admin_email` | `demo.admin@umss.edu.bo` |
| `teacher_email` | `demo.docente@umss.edu.bo` |
| `student_sis` | `202600001` |
| `first_access_sis` | `202600004` |
| `inactive_sis` | `202600005` |
| `admin_password` | Vacía; rellenar manualmente en Postman para ADMIN. |
| `teacher_password` | Vacía; reservar para una prueba manual de login docente. |
| `student_password` | Vacía; rellenar manualmente para ESTUDIANTE. |
| `first_access_password` | Vacía; rellenar manualmente para FIRST ACCESS. |
| `inactive_password` | Vacía; rellenar con la credencial correcta de la cuenta inactiva. |
| `xsrf_token` | Vacía; se completa automáticamente al enviar CSRF Cookie. |
| `teacher_id` | Vacía; copiar `data[].id` del listado o usar el `data.id` que Crear docente guarda automáticamente después de un 201. Es el ID de `teachers`, no de `users`. |
| `import_token` | Vacía y sin uso: el backend no emite ni recibe este parámetro. |

Los valores de las cuentas son los indicados para RUN-01; no se comprobó su existencia ni sus contraseñas en la base de datos. Las cuatro solicitudes de login usan `identifier` y `password`; para una prueba docente se puede duplicar Login ADMIN en Postman y sustituir sus variables por `teacher_email` y `teacher_password`.

Guardar contraseñas únicamente en los valores locales de Postman, sin compartirlas ni exportarlas al repositorio. Los campos de password y tokens se marcan como secretos, pero esa marca no cifra un JSON exportado. Mantener vacíos los secretos de la plantilla versionable, incluidos tokens y cookies; revisar también cualquier evidencia o exportación de ejecución.

`qa/postman/` no existía antes de esta preparación: no se encontraron archivos locales previos con secretos. No hace falta excluir estas plantillas vacías. Si se necesita guardar una exportación local con credenciales, la propuesta sería nombrarla `EIDA-QA-RUN01.local.postman_environment.json` y añadir **previo aviso, sin haberlo aplicado aquí**:

```gitignore
/qa/postman/*.local.postman_environment.json
```

## Autenticación y orden de uso manual

La colección usa cookies de sesión Sanctum y `No Auth`. Mantener habilitado el cookie jar; Postman debe conservar la cookie de sesión y `XSRF-TOKEN` para `127.0.0.1`. No configurar Bearer ni un header Cookie fijo.

Todos los requests incluyen `Accept: application/json`, `Origin: {{frontend_url}}` y `Referer: {{frontend_url}}/`. Los POST, PUT y PATCH incluyen `X-XSRF-TOKEN: {{xsrf_token}}`; los bodies JSON también llevan `Content-Type: application/json`.

1. Para comenzar un escenario sin sesión, limpiar las cookies del backend si hubiera una sesión desconocida. Enviar **GET - CSRF Cookie**. Se espera 204. Su post-response obtiene `XSRF-TOKEN`, aplica `decodeURIComponent` y guarda `xsrf_token`.
2. Enviar **un solo** login del escenario elegido. Con credenciales válidas, ADMIN, ESTUDIANTE y FIRST ACCESS esperan 200. El post-response de colección actualiza `xsrf_token` cuando Laravel devuelve una cookie nueva, sin enviar solicitudes adicionales.
3. Enviar **GET - Current User** después del login. La respuesta contiene `success` y `data` con `id`, `display_name`, `email`, `status`, `must_change_password` y `roles`. Para FIRST ACCESS, comprobar `data.must_change_password = true`.
4. Con ADMIN activo y contraseña ya cambiada, usar Admin Test, Docentes e Importación según el caso. Para HU04, listar y seleccionar un ID o crear primero el docente QA antes de verlo/editarlo/cambiar su estado.
5. Cerrar la sesión con **POST - Logout**, que usa `/api/v1/auth/logout`. Se espera 200. También existe `/logout` de Fortify, pero no se agrega como request adicional. Antes del siguiente login, volver a enviar CSRF Cookie.
6. Probar INACTIVO desde una sesión cerrada y con su contraseña correcta: se espera 422 y `errors.identifier` indicando cuenta inactiva. Una contraseña equivocada prueba credenciales inválidas, no el rechazo por estado.

Las carpetas agrupan casos; su orden **no constituye un flujo completo para Collection Runner**: Current User requiere login previo, los distintos logins deben aislarse y las operaciones de escritura necesitan revisión manual. Crear/editar/desactivar docentes y confirmar importaciones modificarán datos cuando QA los envíe.

Comprobaciones manuales de autorización para HU01/HU03: Admin Test espera 200 y `success: true` con ADMIN habilitado; 401 sin sesión; 403 con `code: FORBIDDEN` para un usuario sin rol administrativo y con contraseña ya cambiada; 403 con `code: PASSWORD_CHANGE_REQUIRED` en primer acceso. Las rutas administrativas registran auditoría, incluso en verificaciones GET. HU01 se cubre con estos controles, sin agregar una carpeta distinta de las solicitadas.

Para diagnosticar 419, volver a obtener CSRF Cookie y comprobar cookies, dominio, token y headers. Si las rutas API no reconocen la sesión, comprobar que la configuración efectiva de Sanctum incluye `127.0.0.1:5173` y que las cookies de sesión son compatibles con HTTP local. No mezclar `localhost` con `127.0.0.1`. La aplicación permite una sola sesión activa: una sesión reemplazada puede recibir 401 con `SESSION_REPLACED`. Login limita a cinco intentos por minuto por identificador e IP (429 al excederlo). La validación de password exige entre 8 y 20 caracteres. Estas configuraciones se inspeccionaron en código; no se leyó ni modificó `.env`.

## Inventario de requests

Todas las rutas de la tabla llevan el prefijo `{{base_url}}`.

| Carpeta | Request | Ruta |
| --- | --- | --- |
| 00 - Auth Setup | GET - CSRF Cookie | `/sanctum/csrf-cookie` |
| 00 - Auth Setup | GET - Current User | `/api/v1/me` |
| HU02 - Login | POST - Login ADMIN | `/login` |
| HU02 - Login | POST - Login ESTUDIANTE | `/login` |
| HU02 - Login | POST - Login FIRST ACCESS | `/login` |
| HU02 - Login | POST - Login INACTIVO | `/login` |
| HU02 - Login | POST - Logout | `/api/v1/auth/logout` |
| HU03 - Administración | GET - Admin Test | `/api/v1/admin/test` |
| HU04 - Docentes | GET - Listar docentes | `/api/v1/admin/teachers` |
| HU04 - Docentes | GET - Ver docente | `/api/v1/admin/teachers/{{teacher_id}}` |
| HU04 - Docentes | POST - Crear docente | `/api/v1/admin/teachers` |
| HU04 - Docentes | PUT - Editar docente | `/api/v1/admin/teachers/{{teacher_id}}` |
| HU04 - Docentes | PATCH - Cambiar estado docente | `/api/v1/admin/teachers/{{teacher_id}}/status` |
| HU05 - Importación estudiantes | POST - Preview importación | `/api/v1/admin/students/import/preview` |
| HU05 - Importación estudiantes | POST - Confirmar importación | `/api/v1/admin/students/import/confirm` |

## HU04: bodies reales

Crear docente requiere exactamente los campos del ejemplo: `institutional_code`, `identity_number` (strings de hasta 50 caracteres), `first_names`, `last_names` (hasta 100) y `email` (hasta 255). Código institucional, CI y email deben ser únicos; el email debe pertenecer a un dominio permitido en `config('eida.institutional_email_domains')`. Para usar el ejemplo se requiere `umss.edu.bo` habilitado y un rol DOCENTE activo.

El ejemplo contiene datos ficticios de RUN-01 y un email diferente del docente demo. Antes de repetir la creación, cambiar código, CI y email para evitar duplicados (422). La creación responde 201 con `data.id`; no requiere enviar contraseña.

PUT admite los mismos campos de forma parcial mediante reglas `sometimes|required`; el ejemplo solo cambia nombres y apellidos. PATCH de estado recibe JSON `{"status":"INACTIVE"}` y permite únicamente `ACTIVE` o `INACTIVE`; actualiza al docente y a su usuario. Cambiar a `ACTIVE` para reactivar el registro de prueba. Ver, editar y cambiar estado devuelven el recurso bajo `data`; un ID inexistente devuelve 404. El listado devuelve `data`, `links` y `meta`, y admite `page`, `per_page` y `search` como parámetros opcionales.

## HU05: archivo y contrato real

Preview y confirmación usan **Body → form-data**, clave **`file`**, tipo **File**. Seleccionar manualmente el archivo local en ambos requests; no hay una ruta absoluta preconfigurada. Dejar que Postman construya `Content-Type: multipart/form-data` con su boundary.

El controlador exige `required|file|mimes:csv,txt`; el servicio exige además extensión `.csv` en minúsculas y tamaño máximo de 10 × 1024 × 1024 bytes. Según el contrato, usar UTF-8 y comas. Usar UTF-8 sin BOM porque el servicio compara la cabecera literalmente. La cabecera debe tener estas siete columnas en este orden, y cada registro debe tener siete campos:

```csv
sis_code,identity_number,first_names,last_names,email,career,profile_photo
```

SIS y CI son texto obligatorio y único. Nombres, apellidos, email, carrera y foto son obligatorios. El servicio exige email `@umss.edu.bo`, carrera por nombre ya existente en `careers` y un valor no vacío en `profile_photo`. SIS, CI y email se contrastan con otras filas y registros existentes. No se generó un CSV ni se consultaron carreras o estudiantes en la base de datos.

Preview responde JSON con esta estructura (ejemplo ilustrativo de forma, no resultado ejecutado):

```json
{
  "success": true,
  "message": "Vista previa generada correctamente.",
  "data": {
    "valid": false,
    "total_rows": 1,
    "valid_rows": 0,
    "error_rows": 1,
    "errors": [],
    "rows": [
      {
        "row": 2,
        "data": {
          "sis_code": "209900001",
          "identity_number": "99000002",
          "first_names": "Estudiante QA",
          "last_names": "Prueba RUN01",
          "email": "qa.run01.estudiante001@umss.edu.bo",
          "career": "",
          "profile_photo": "qa/run01/avatar.png"
        },
        "valid": false,
        "errors": ["La carrera es obligatoria."]
      }
    ]
  }
}
```

`data.errors` recoge errores generales del archivo; `data.rows[].errors` recoge errores de fila. HTTP 200 y `success: true` pueden acompañar `data.valid: false`: revisar contenido y contadores. Un archivo faltante o rechazado por la validación del controlador devuelve 422; errores detectados dentro del servicio pueden venir en una respuesta 200.

Para confirmar, **adjuntar de nuevo el mismo archivo revisado**, con el mismo campo `file`. El backend revalida el CSV; no conserva la preview ni requiere que se haya enviado antes. No acepta un contrato de confirmación basado en token o filas JSON. La respuesta conserva los campos de preview y añade `data.imported_rows` y `data.failed_rows`. Las filas inválidas se omiten y las válidas se importan mediante transacciones individuales; no repetir confirmación suponiendo que sea idempotente.

Diferencias detectadas por lectura del código que QA debe comprobar:

- El contrato indica rechazar archivos sin estudiantes; un archivo con solo la cabecera correcta devuelve actualmente cero filas y `valid: true`. Un archivo totalmente vacío sí produce error.
- El servicio relanza excepciones de persistencia: puede interrumpir la confirmación sin devolver el resumen y conservar filas importadas en transacciones anteriores. `failed_rows` se inicializa en cero y no se incrementa; no debe interpretarse como garantía de que una ejecución interrumpida no tuvo efectos.
- Las filas con distinto número de campos pasan a `array_combine` sin validación previa y pueden provocar una excepción en lugar de un error por fila.

## Fuentes y validación

Fuentes locales revisadas: `routes/api/v1.php`, salida de `php artisan route:list`, `TeacherController`, `StoreTeacherRequest`, `UpdateTeacherRequest`, `UpdateTeacherStatusRequest`, `ValidatesTeacherInput`, `TeacherResource`, `TeacherService`, `StudentImportController`, `StudentCsvImportService`, `docs/HU5_CSV_IMPORT_CONTRACT.md`, configuración Fortify/Sanctum y clases de autenticación/autorización.

La validación de los artefactos es local y estática: parseo de ambos JSON, inventario de cinco carpetas y quince requests, variables referenciadas, passwords/tokens iniciales vacíos, bodies JSON, campos multipart y sintaxis de scripts JavaScript. No constituye validación de respuestas reales del backend.
