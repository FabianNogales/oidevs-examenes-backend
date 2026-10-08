# HU12 — Contrato de habilitaciones por examen

Implementado en Dev_Hector. Base: /api/v1.
Requiere autenticación Sanctum, rol DOCENTE activo, cuenta activa, cambio inicial de contraseña completado y ser responsable del examen.
Los colaboradores no pueden gestionar habilitaciones.

## Actualización

    git pull --ff-only origin Dev_Hector
    php artisan migrate
    php artisan optimize:clear

La migración agrega reason_code y observations como columnas nullable.
Conserva los textos reason existentes sin asignarles un código inventado.
No modifica inscripciones, usuarios, roles, QR ni autorizaciones de colaboradores.

## Catálogo

GET /exams/{exam_id}/eligibilities/reasons

200:
{
  "data": [
    {"code":"INSTITUTIONAL_REQUIREMENT_PENDING","label":"Requisito institucional pendiente"},
    {"code":"OTHER","label":"Otro motivo"}
  ]
}

Catálogo fijo definido por backend. No exige seeders ni administración adicional.
El frontend debe consumir estos valores.

## Actualización individual

PATCH /exams/{exam_id}/eligibilities/{student_id}

El parámetro student_id es el ID del perfil students, no users ni exam_eligibilities.

Body:
{
  "status":"INELIGIBLE",
  "reason_code":"INSTITUTIONAL_REQUIREMENT_PENDING",
  "observations":"Documento pendiente"
}

200:
{
  "message":"Estado de habilitación actualizado correctamente.",
  "data":{
    "id":12,
    "exam_id":1,
    "student_id":5,
    "status":"INELIGIBLE",
    "reason_code":"INSTITUTIONAL_REQUIREMENT_PENDING",
    "reason":"Requisito institucional pendiente",
    "observations":"Documento pendiente",
    "evaluated_at":"2026-10-08T21:30:00+00:00"
  }
}

reason_code debe ser válido; el backend determina reason desde la etiqueta.
observations es opcional, máximo 500 caracteres.
Para habilitar: {"status":"ELIGIBLE"}. Se limpian reason_code, reason y observations.
Compatibilidad transitoria: PATCH también acepta {"status":"INELIGIBLE","reason":"Texto libre anterior"}, máximo 500 caracteres, sin código. El frontend nuevo debe enviar reason_code.
Si se envían reason_code y reason, prevalece la etiqueta del código. Un código desconocido se rechaza aunque haya texto libre.
La actualización registra evaluated_by/evaluated_at y una entrada de auditoría en la misma transacción.

## Listado y foto

GET /exams/{exam_id}/eligibilities
Filtros existentes: ?status=INELIGIBLE&search={nombre_apellido_sis_ci}

Se mantienen data y los campos previos. Se agregan exam_id, reason_code, observations y profile_photo_url.

Ejemplo de registro:
{
  "id":12,
  "exam_id":1,
  "student_id":5,
  "sis_code":"202300001",
  "identity_number":"12345678",
  "first_names":"Carlos",
  "last_names":"Pérez",
  "email":"carlos@example.com",
  "profile_photo_url":"https://backend.example.com/storage/profile-photos/student.png",
  "status":"INELIGIBLE",
  "reason_code":"OTHER",
  "reason":"Otro motivo",
  "observations":"Información adicional",
  "evaluated_by":"docente@example.com",
  "evaluated_at":"2026-10-08T21:30:00+00:00"
}

La foto sigue el formato ya utilizado por StudentProfileResource:
- URL http/https almacenada: se devuelve directamente.
- Ruta en el disco público: se devuelve asset('storage/' + ruta).
- Sin foto: null; frontend debe usar avatar y también puede manejar errores de carga de la imagen.

El endpoint de perfil propio no es necesario ni permite consultar otros estudiantes.
Para fotos públicas, el entorno debe tener APP_URL correcto y el enlace público de storage configurado, como en HU10.

Registros antiguos conservan reason con reason_code:null y observations:null.
El listado continúa sin paginación de servidor; los contadores y la paginación visual pueden calcularse sobre el listado completo. Al filtrar por backend solo se devuelve el subconjunto: para contadores globales usar el listado sin filtros.
Este cambio no introduce un endpoint de contadores.

## Carga masiva

POST /exams/{exam_id}/eligibilities/bulk
multipart/form-data, campo file.

CSV UTF-8, extensión .csv, delimitador coma, máximo 5 MB y 1.000 registros.
Encabezados obligatorios y únicos: sis_code,status,reason_code,observations.
Se acepta UTF-8 BOM, CRLF/LF, campos entrecomillados, comas y saltos de línea dentro de campos entrecomillados.
Las líneas vacías se ignoran. Se rechaza formato de comillas inválido antes de guardar.
El SIS se trata como texto y conserva sus ceros iniciales.

    sis_code,status,reason_code,observations
    202300001,INELIGIBLE,INSTITUTIONAL_REQUIREMENT_PENDING,Documento pendiente
    202300002,ELIGIBLE,,

En CSV, INELIGIBLE exige reason_code válido; no hay fallback de reason libre.
ELIGIBLE limpia los tres campos. Los valores suministrados igualmente deben respetar los límites y catálogo.

200, incluso con errores por fila:
{
  "message":"Carga de habilitaciones procesada.",
  "data":{
    "total_rows":3,
    "updated_rows":2,
    "failed_rows":1,
    "errors":[{
      "row":4,
      "sis_code":"202300099",
      "messages":{
        "sis_code":["El estudiante no tiene una habilitación asociada a este examen."]
      }
    }]
  }
}

total_rows cuenta registros no vacíos. updated_rows cuenta filas válidas aplicadas, aunque el estado solicitado coincida con el anterior.
failed_rows cuenta filas rechazadas, no la cantidad de mensajes.
row identifica la línea física inicial del registro, contando el encabezado como línea 1; contempla observaciones multilínea.
Se rechazan todas las filas de un SIS duplicado en el archivo.
Solo se actualizan habilitaciones ya asociadas a ese examen; no se crean perfiles, inscripciones ni habilitaciones.
Las filas con error mantienen sus datos anteriores. Las válidas se aplican en una transacción con auditoría.
Ante un fallo de persistencia se revierte toda la carga, incluyendo auditorías.

## Errores

401: sin autenticación.
403: falta de permisos, docente ajeno al examen o cuenta inactiva.
404: examen o habilitación inexistente.
422: payload inválido o archivo/encabezados/UTF-8/formato/límites inválidos. Errores de archivo se presentan en errors.file y no producen actualizaciones.
500: fallo inesperado de persistencia; se revierte la transacción.

Después de PATCH o bulk, refrescar listado y contadores.
Conservar el detalle de errores de bulk para que el usuario corrija el CSV.

## Pruebas

    php artisan test --filter=ExamEligibilityTest
    php artisan test

Cobertura: catálogo, códigos, límites, limpieza, compatibilidad antigua, fotos, permisos reales, carga parcial, duplicados, aislamiento por examen, SIS con ceros, BOM, líneas físicas, límites de archivo, CSV malformado, preservación de datos en migración y rollback ante fallo.
