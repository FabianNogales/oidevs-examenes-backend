# QR v3 para control de ingreso offline

## Entrega al estudiante y compatibilidad web

HU11 entrega QR v2 desde `GET /api/v1/students/exams` y `GET /api/v1/students/exams/{exam_id}/qr`. Usa HS256 con `APP_KEY` y lleva `v`, `jti` y `exp`. HU14 valida v2 en el control web. Android requiere RS256 para autenticar un QR sin conexión.

La nueva opción es `GET /api/v1/students/exams/{exam_id}/qr?version=3`. Usa la autenticación Sanctum, sesión vigente, contraseña actualizada y rol de estudiante de HU11. El perfil autenticado determina la identidad. Se exige estudiante activo, inscripción activa y habilitación `ELIGIBLE` para ese examen.

La respuesta conserva `data.exam_id`, `data.subject`, `data.exam_title`, `data.scheduled_at`, `data.qr_code_base64` y `data.token`. La imagen es una URL de datos SVG y codifica el token. La respuesta v3 usa `Cache-Control: private, no-store`. La ruta devuelve HTTP 503 con `code: QR_V3_UNAVAILABLE` si la emisión no está disponible.

El portal ofrece «QR para EIDA Control» en Mis exámenes y muestra `data.qr_code_base64`. «QR para control web» usa la ruta sin parámetro. Consulta el [informe final](EIDA_QR_V3_INTEGRACION_FINAL.md) para preparar el laboratorio.

## Contrato JWS y manifiesto

El perfil consta en [la fase 1 del backend](EIDA_CONTROL_FASE_1_BACKEND.md#8-prototipo-qr-v3) y los [vectores públicos](../tests/Fixtures/qr-v3/rs256-vectors.json). El JWS compacto usa tres segmentos base64url canónicos sin padding. RS256 aplica RSA PKCS#1 v1.5 y SHA-256 a los dos primeros segmentos originales separados por un punto.

- El encabezado contiene exactamente `alg: "RS256"`, `typ: "eida-entry-qr+jwt"` y `kid` de 1 a 64 caracteres ASCII alfanuméricos, guiones o guiones bajos.
- El payload contiene exactamente `v: 3`, `jti` UUID, `sub` y `exam_id` como cadenas decimales canónicas positivas de hasta 9223372036854775807, `iss` y `aud` como cadenas, e `iat`, `nbf` y `exp` como segundos Unix enteros. El token no incluye CI, SIS, nombres, fotos ni credenciales.
- La entrega abre 24 horas antes del inicio y cierra al fin del examen en America/La_Paz. El instante de emisión fija `iat` y `nbf`. El fin del examen fija `exp` como límite exclusivo. La ventana completa debe caber en los 172 800 segundos del perfil. Ambos QR comparten el `jti` activo del registro existente.

El personal descarga su lista propia con `GET /api/v1/exams/{exam_id}/offline/package?schema_version=2` usando una credencial móvil vinculada. La respuesta lleva `data.mobile.manifest_json` y su SHA-256. El sobre y el manifiesto interno conservan `schema_version: 1`.

| Campo de `qr` | Disponible | No disponible |
| --- | --- | --- |
| `available` | `true` | `false` |
| `reason` | `null` | `PRODUCTION_QR_V3_NOT_CONFIRMED` |
| `issuer`, `audience` | Valores exactos de `iss` y `aud` | `null` |
| `keys` | Lista con `{kid, algorithm: "RS256", pem}`. PEM público SPKI `BEGIN PUBLIC KEY`. | `[]` |
| `revoked_jtis` | `null` | `null` |

`revoked_jtis: null` indica que el paquete carece de información de revocación. El servidor consulta el registro al sincronizar. Las autorizaciones HU35 y el manifiesto por dispositivo siguen aplicándose.

## Claves, configuración y rotación

| Variable | Uso |
| --- | --- |
| `EIDA_QR_V3_ENABLED` | `false` por defecto. Habilita la emisión explícita. |
| `EIDA_QR_V3_KID` | Identificador único del par activo. |
| `EIDA_QR_V3_ISSUER` | Identificador estable, por ejemplo `https://eida.example.test`. |
| `EIDA_QR_V3_AUDIENCE` | `eida-control-qr` por defecto. Coordinar con Android. |
| `EIDA_QR_V3_PUBLIC_KEY` | Cuerpo Base64 en una línea del PEM público SPKI (`PUBLIC KEY`). |
| `EIDA_QR_V3_PRIVATE_KEY` | Cuerpo Base64 en una línea del PEM privado PKCS#8 (`PRIVATE KEY`), sin frase de paso. |
| `EIDA_OFFLINE_SYNC_QR_V3_VERIFY` | `false` por defecto. Activa la verificación pública v3 en sincronización. |
| `MOBILE_OFFLINE_QR_V3_ISSUED` | `false` por defecto. Habilita la publicación tras validar la entrega estudiantil y la interoperabilidad Android. |

En Vercel, las dos variables deben estar disponibles para PHP en ejecución. Usar el cuerpo Base64 original de cada PEM RSA de 2048 a 4096 bits, sin líneas BEGIN/END, espacios ni saltos de línea. Conservar el relleno `=` si existe. Laravel reconstruye el PEM en memoria y entrega el resultado a OpenSSL. No hace falta volver a codificar las claves ni cargar archivos. Los cuerpos se leen fuera de la caché de configuración. La sincronización carga solo la pública. Emisor y audiencia requieren ASCII imprimible sin espacios.

`qr.available` exige los tres interruptores, una lista propia autorizada, una ventana compatible, un par correspondiente, una prueba de firma y verificación satisfactoria y el esquema de evidencia de HU37. Mantener deshabilitada la publicación en producción hasta validar portal y APK.

Solo se carga un par activo. Para rotar entre convocatorias, detener emisiones y publicaciones nuevas mientras se conserva la clave pública anterior para sincronizar pendientes. Una vez procesados los pendientes y cerrada la vigencia de los paquetes, cambiar el contenido de ambas variables y el `kid` de forma coordinada. Los estudiantes deben volver a obtener su QR y el personal debe descargar paquetes nuevos. Una rotación con paquetes activos requeriría verificar de forma simultánea con la clave nueva y la anterior.

Tras habilitar o rotar, descargar con `/package?schema_version=2`. Reanudar mediante `/packages/{package_id}` recupera el manifiesto original. Conservar los ingresos pendientes con su `package_id` original. Una descarga nueva crea un paquete para la distribución existente.

## Validación local y Android

1. En un entorno aislado con un par de prueba, activar emisión y verificación. Ejecutar `php artisan test --filter 'StudentQrStatusTest|MobileOfflinePackageTest|OfflineSyncEvidenceTest|QrV3Test|ManualEntryTest|OnlineQrCompatibilityTest|OfflineBackendTest'` y `node --test tests/Interop/qr-v3.node.mjs`.
2. Con una cuenta estudiantil habilitada, obtener v2 y v3. Confirmar que el texto escaneado coincide con el token devuelto y que su firma concuerda con la pública. Probar la falta de privada y un par incompatible.
3. En integración, activar la publicación y descargar un paquete nuevo. Comprobar `qr.available: true` y `qr_versions: [2, 3]` en `/offline/sync-capabilities`. Sin conexión, escanear el QR generado y comprobar los rechazos de v2, datos alterados, QR vencido y QR de otro examen. Comprobar que la lista, la habilitación y la confirmación explícita siguen aplicándose.
4. Recuperar conexión durante la vigencia y sincronizar por protocolo 2. Comprobar recibo, duplicados, reenvío del evento, registro manual y rechazo de listas ajenas. La validación en un APK real sigue pendiente para producción.

La firma acredita el origen del QR. Una captura de pantalla sigue siendo reutilizable mientras el código esté vigente. El backend confirma el ingreso y conserva la revisión existente de evidencia tardía.

## Archivos, pruebas y migraciones

- Emisión: `StudentQrController.php`, `StudentQrService.php`, `ExamQrV3.php`, `QrV3Factory.php`, `QrV3KeyRing.php` y `QrV3Service.php`.
- Manifiesto y configuración: `MobileOfflinePackageService.php`, `OfflineSyncService.php` (comprobación compartida del esquema), `config/mobile_offline.php`, `config/qr_v3.php` y `.env.example`.
- Regresiones añadidas: `StudentQrStatusTest.php`, `MobileOfflinePackageTest.php` y `OfflineSyncEvidenceTest.php`, con claves efímeras de `tests/Support/ConfiguresQrV3Issuer.php`. Cubren firma, claims, autorización, vigencia, configuración incompleta, renovación de paquetes y recibos. Los vectores existentes cubren firma y payload alterados, algoritmo inválido, vencimiento, otro examen y formato malformado.

No se requieren migraciones ni dependencias nuevas. El protocolo 2 requiere la migración existente `2026_11_08_000001_add_offline_sync_evidence.php`. Las pruebas quedan pendientes de ejecución.
