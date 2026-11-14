# Integración final de QR v3 en EIDA

## Estado y cambios

Laravel ya emitía QR v3 para estudiantes autorizados, publicaba claves en el paquete móvil y verificaba evidencia en HU37. El usuario informó que la suite anterior pasó con 512 MB de memoria PHP. Android ya tenía el escáner, el verificador RS256, la ficha de revisión y la cola provisional. El portal solo consumía QR v2.

Ahora «Mis exámenes» ofrece «QR para EIDA Control» como acción principal y «QR para control web» como acción separada. Laravel usa `Cache-Control: private, no-store` en el listado y en las respuestas QR exitosas de ambas versiones. Android permite iniciar una descarga nueva aunque haya una incompleta, y ofrece reanudar la anterior como acción distinta. Falta ejecutar las pruebas añadidas y validar este flujo con una APK y el servidor de laboratorio.

| Repositorio | Archivos de esta entrega |
| --- | --- |
| Laravel | `app/Http/Controllers/Api/V1/Students/StudentQrController.php`, `tests/Feature/Students/StudentQrStatusTest.php`, este informe y `docs/EIDA_BACKEND_QR_V3_OFFLINE.md` |
| Portal | En `src/features/students/`: `api/studentQrApi.ts`, `api/studentQrError.ts`, `types/studentQr.ts`, `utils/studentQrFreshness.ts`, `hooks/useStudentQr.ts`, `pages/StudentQrPage.tsx`, `pages/StudentQrPage.module.css`, `components/StudentExamCard.tsx`, `components/StudentQrCard.tsx`, `tests/studentQrApi.test.mjs`, `tests/studentQrFreshness.test.mjs`, `tests/studentQrPresentation.test.mjs` y `tests/studentBreadcrumbs.test.mjs`. Se ajustó la cuenta simulada de `src/shared/components/ui/tests/pageLayout.browser.mjs`. |
| Android | `src/screens/PrepareOfflineScreen.tsx`, `tests/scanner.test.ts` y `docs/EIDA_CONTROL_DIAGNOSTICO_QR_ANDROID.md` |

## Portal, contrato y confianza

Con sesión estudiantil autorizada, abre `/students/qr` y elige el examen habilitado. «QR para EIDA Control» usa `GET /api/v1/students/exams/{exam_id}/qr?version=3`. El portal muestra `data.qr_code_base64` tal como lo entrega Laravel, con un marco ampliado, vigencia en hora de Bolivia y las descargas SVG y PNG. Usa `data.token` en memoria para comprobar la versión y ocultar el QR al vencer o al cambiar su `jti` en el listado. Al salir de la cuenta o cambiar de usuario, se desmonta la vista anterior y se cancelan sus consultas. Un error de v3 se muestra en su propia acción. El botón web conserva la ruta sin versión y el QR v2 de HU14.

Laravel y el verificador de Android usan el mismo perfil. Es un JWS compacto de tres segmentos base64url sin padding, con encabezado exacto `alg=RS256`, `kid` y `typ=eida-entry-qr+jwt`. El payload contiene `v=3`, `jti` UUID, `sub` y `exam_id` como cadenas decimales, `iss`, `aud`, `iat`, `nbf` y `exp`. La firma usa RSA PKCS#1 v1.5 con SHA-256 sobre los segmentos originales de encabezado y payload. La emisión abre 24 h antes del examen, usa `iat=nbf` y vence al finalizar el examen, con un máximo de 172 800 segundos. Android usa su reloj anclado y selecciona por `kid` una clave pública SPKI de `qr.keys` dentro de la preparación autorizada. La firma autentica el QR. La lista, la habilitación, la confirmación explícita y el recibo determinan qué ocurre con el ingreso.

Los vectores públicos de Laravel y Android coinciden byte por byte. La nueva regresión móvil consume el vector firmado por el emisor PHP con el verificador que llama el escáner. En escritorio usa Node/OpenSSL para la firma y un servidor simulado para los recibos. La cámara, Quick Crypto, SQLCipher y el transporte real se validan con el APK.

## Configuración de laboratorio

En el backend de Vercel, usa las variables de ejecución `EIDA_QR_V3_PRIVATE_KEY` y `EIDA_QR_V3_PUBLIC_KEY`. Deben contener los cuerpos Base64 originales de los PEM RSA de 2048 a 4096 bits. La privada de `openssl genpkey` es PKCS#8 (`PRIVATE KEY`) y la pública de `openssl pkey -pubout` es SPKI (`PUBLIC KEY`). Cada valor va en una línea sin espacios ni los delimitadores PEM. Conserva el relleno `=` cuando exista. Laravel reconstruye los PEM en memoria, sin una segunda codificación Base64.

```dotenv
EIDA_QR_V3_ENABLED=true
EIDA_QR_V3_KID=lab-rsa-01
EIDA_QR_V3_ISSUER=https://eida.lab.example
EIDA_QR_V3_AUDIENCE=eida-control-qr
EIDA_QR_V3_PUBLIC_KEY=
EIDA_QR_V3_PRIVATE_KEY=
EIDA_OFFLINE_SYNC_QR_V3_VERIFY=true
MOBILE_OFFLINE_QR_V3_ISSUED=false
```

Los campos de clave se muestran vacíos a propósito. Conserva los valores ya configurados en Vercel y usa el emisor de tu infraestructura. Verifica primero que la cuenta autorizada pueda obtener v3. Para la prueba de integración, aplica la configuración de flags del entorno. `qr.available` requiere los tres flags, esquema de evidencia HU37, ventana compatible, autorización de la lista y un par que supere la comprobación de firma. El flag de publicación representa la validación operativa de la entrega y del APK. Los cuerpos de las claves se leen en ejecución fuera de la caché de configuración.

El portal requiere `VITE_BACKEND_URL` para la raíz Laravel y `VITE_API_URL` con `/api/v1`, con autenticación real y la sesión Sanctum configurada para su origen. Android requiere `EXPO_PUBLIC_EIDA_API_BASE_URL` con la URL HTTPS de laboratorio y `/api/v1`, junto con una build que incluya los módulos nativos ya usados. No hay dependencias ni migraciones nuevas. El laboratorio necesita el esquema HU37 existente. Hay un solo par activo. Para rotarlo, sincroniza pendientes con la clave anterior y sigue la [guía de rotación](EIDA_BACKEND_QR_V3_OFFLINE.md#claves-configuración-y-rotación).

## Validación que ejecutará el usuario

1. **Laravel**. Ejecuta `php -d memory_limit=512M artisan test --filter 'StudentQrStatusTest|QrV3Test|MobileOfflinePackageTest|OfflineSyncEvidenceTest|OnlineQrCompatibilityTest|ManualEntryTest'` y `node --test tests/Interop/qr-v3.node.mjs`.
2. **Portal**. Ejecuta `npm run build`, `npm run lint` y `node --test src/features/students/tests/studentQrApi.test.mjs src/features/students/tests/studentQrFreshness.test.mjs src/features/students/tests/studentQrPresentation.test.mjs src/features/students/tests/studentBreadcrumbs.test.mjs`. Los casos cubren versión solicitada, SVG intacto, error, cambio de cuenta, frescura y v2 explícito. Usa el servidor de laboratorio para iniciar sesión con un estudiante elegible, verificar las dos acciones y probar una cuenta ajena.
3. **Android**. Ejecuta `npm run typecheck` y `npm test`. Usa la development build con `npm start` o genera tu APK con `npm run build:android` si necesitas actualizarlo. En el mismo examen, abre «Preparar lista offline», elige «Preparar una copia nueva» y espera su activación. La descarga usa `/api/v1/exams/{exam_id}/offline/package?schema_version=2`. «Reanudar descarga anterior» conserva el manifiesto original.
4. **Dispositivo**. En modo sin conexión y durante el examen, abre «Control de ingreso» y «Escanear QR». Presenta el QR desde otro dispositivo o desde una descarga. Verifica la ficha de revisión y que cancelar no genere un evento. Repite y pulsa «Confirmar ingreso». Debe aparecer un pendiente; un nuevo escaneo del mismo estudiante no crea otro ingreso.
5. **HU37**. Recupera conexión durante la ventana del examen y sincroniza en «Pendientes». Comprueba `qr_versions: [2, 3]` en `/offline/sync-capabilities` y que el recibo válido cambie la UI a «Ingreso confirmado». Si existían ingresos de una preparación anterior, verifica que salgan con su paquete y UUID originales. El envío tardío seguirá sujeto a revisión.

## Diagnóstico de errores esperados

| Síntoma | Comprobación |
| --- | --- |
| HTTP 401 o 403 | Revisar sesión, rol, perfil, inscripción activa, habilitación y ventana. Probar con un estudiante ajeno a la oferta. |
| HTTP 503 `QR_V3_UNAVAILABLE` | Revisar flag de emisión, ventana, disponibilidad de las variables en ejecución, formato de una línea y coincidencia del par RSA. El estudiante puede elegir por separado el QR de control web. |
| Escáner deshabilitado | Verificar `qr.available`, lista propia, flags, esquema HU37 y que se descargó un manifiesto nuevo. |
| `UNKNOWN_KID` | Confirmar que el portal emitió con el `kid` presente en el paquete. Renovar ambos después de una rotación. |
| `SIGNATURE_INVALID` o `HEADER_INVALID` | Probar QR alterado, una firma incorrecta y un algoritmo distinto. El QR v2 se rechaza offline. |
| `TIME_INVALID` o `SUBJECT_EXAM_MISMATCH` | Probar QR vencido, futuro o de otro examen. Revisar vigencia del examen y ancla de tiempo. |
| Identidad fuera de la lista, inhabilitada o ya registrada | La verificación criptográfica no habilita el ingreso. Comprobar la lista, la habilitación y la conservación del UUID anterior. |
| QR pendiente tras volver a línea | Verificar sesión y dispositivo originales, capacidad v3, protocolo 2 y recibo. Los conflictos y rechazos conservan evidencia para revisión. |

No se ejecutaron comandos de pruebas, builds ni despliegues en esta entrega. La compatibilidad en cámara y la sincronización con el backend real quedan pendientes de la prueba física.
