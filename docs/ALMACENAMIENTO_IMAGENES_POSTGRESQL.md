# Almacenamiento de imágenes en PostgreSQL

Implementación preparada el 5 de noviembre de 2026. No desplegada ni verificada en producción.

## Corrección de visualización local mediante proxy (5 de noviembre de 2026)

La causa reproducida no era la persistencia BYTEA: `.env.local` del frontend utiliza `/backend/api/v1`, y Vite elimina `/backend` al reenviar a Laravel. Faltaba transmitir ese prefijo y confiar en él al generar URLs con `route()`. Laravel devolvía, por ejemplo, `http://localhost:5173/api/v1/students/profile/photo?v=...`, en lugar de `http://localhost:5173/backend/api/v1/students/profile/photo?v=...`. Al ser absoluta, Axios usaba esa URL directamente y no añadía su `baseURL`.

Se reprodujo una respuesta **404** de Vite para la URL incorrecta con `Accept: application/json` (cliente autenticado), y **200 text/html** con `Accept: */*` o `text/html` (fallback de la SPA, relevante para noticias públicas). Ninguna de esas respuestas contiene la fotografía.

Corrección mínima: `vite.config.ts` transmite `X-Forwarded-Prefix: /backend` en el proxy que elimina ese prefijo; `bootstrap/app.php` incluye `Request::HEADER_X_FORWARDED_PREFIX` entre las cabeceras de proxy confiables. Así todas las URLs se generan correctamente, manteniendo el origen, protocolo y puerto del navegador. El acceso directo a Laravel sigue generando URLs sin `/backend`. No se cambian los servicios de almacenamiento, permisos, autenticación, componentes Blob, reglas de ingreso ni QR.

### Verificación realizada para esta corrección

- Lectura de la imagen local existente dentro de una transacción PostgreSQL **READ ONLY**: PDO devuelve un recurso; respuesta del servicio 200, `image/jpeg`, 21.475 bytes, tamaño y SHA-256 coincidentes e imagen reconocida por PHP. No se imprimieron los bytes ni se modificó la fotografía. Esta comprobación del registro existente llamó al servicio; la autorización HTTP se verificó con fixtures temporales, no con una sesión real del usuario.
- `php vendor/phpunit/phpunit/phpunit -c phpunit.images.xml tests/Feature/Images/ImageProxyUrlsTest.php`: **3 pruebas, 44 aserciones**, correctas. Comprueba las nueve rutas de imágenes, prefijo/protocolo/puerto, acceso directo y rechazo JSON 401 para todas las rutas privadas. La prueba del prefijo fallaba antes de la corrección.
- `$env:EIDA_TEST_TEMP_IMAGE_TABLES='1'; php -d extension=gd vendor/phpunit/phpunit/phpunit -c phpunit.image-reading-pgsql.xml`: **4 pruebas, 44 aserciones**, correctas. Guardado y lectura BYTEA real, respuesta HTTP binaria, MIME, hash, comparación hexadecimal, decodificación GD, reemplazo, separación entre `student_id` y `user_id`, propietario incorrecto, imagen ausente, autorización administrativa, fotografía histórica y noticia pública/borrador/archivada.
- Esta nueva configuración PostgreSQL usa la conexión local configurada, pero **todos los fixtures y escrituras están aislados en tablas TEMP de la sesión**, sin filas copiadas. También sustituye los defaults de secuencias por secuencias temporales y fija `search_path` exclusivamente al esquema temporal. Cada prueba termina con rollback. No utiliza `RefreshDatabase`, migraciones ni seeders; no modifica registros ni secuencias persistentes. Rechaza conexión no PostgreSQL, host no local o configuración cacheada y requiere la variable explícita anterior.
- Frontend: las siete suites focalizadas indicadas más abajo: **93 pruebas correctas**.
- `node --experimental-websocket src/shared/tests/imageProxy.browser.mjs`: correcto en Edge headless. Usa Vite y Axios reales contra un servidor HTTP fixture: prefijo, cookie, React StrictMode, decodificación de Blob, reemplazo, recuperación tras 404, fotografía histórica, noticias administrativas/públicas y liberación de Object URLs al desmontar. No contacta con datos reales. En Node 20 requiere `--experimental-websocket`; se puede definir `EIDA_TEST_BROWSER` con la ruta de otro navegador Chromium.
- `npm run build`: correcto; permanece el aviso de chunks mayores de 500 kB. Sintaxis PHP y Pint de las pruebas nuevas correctos. La revisión Pint de `bootstrap/app.php` señala formato preexistente en imports/closures; no se reformateó código ajeno a la corrección.

### Comprobación manual local

1. Iniciar Laravel como de costumbre (`php artisan serve`, puerto 8000) y reiniciar únicamente Vite con `npm run dev` para cargar la configuración del proxy. No reiniciar PostgreSQL ni ejecutar migraciones.
2. Abrir el origen que muestre Vite, iniciar sesión como estudiante y abrir **Mi perfil**. La foto ya almacenada debe aparecer sin volver a subirla.
3. En DevTools → Network, comprobar que `profile_photo_url` contiene `/backend/api/v1/students/profile/photo`, y que su GET devuelve 200, `Content-Type: image/jpeg`, `image/png` o `image/webp` y cuerpo binario. El `<img>` privado debe usar una URL `blob:`.
4. Pulsar **Cambiar foto**, seleccionar JPEG/PNG/WebP de hasta 2 MiB y comprobar que cambia `?v=...`, aparece la nueva imagen y sigue visible tras recargar.
5. Como administrador, consultar perfil y fotografía institucional de un estudiante. Comprobar ambos endpoints privados con `/backend/api/v1/admin/students/...`. En HU13/HU14, usar un examen/estudiante existente y un usuario autorizado; comprobar las dos fotos sin alterar reglas de ingreso.
6. En noticias, comprobar el borrador en administración y una noticia ya publicada en el portal. El endpoint público debe responder 404 para borradores/archivadas. No publicar contenido de prueba automáticamente.

La corrección del prefijo alcanza perfil, administración, fotografía histórica, HU13, HU14, habilitaciones, colaboradores y HU40/HU41. Las rutas de todos ellos están cubiertas por la prueba de URLs; los escenarios completos de ingreso HU13/HU14 se comprobaron mediante las pruebas focalizadas del frontend, no mediante operaciones sobre exámenes reales. Cookies/CORS de dominios de producción siguen sin verificarse en esta sesión.

Archivos de esta corrección: backend `bootstrap/app.php`, este documento, `tests/Feature/Images/ImageProxyUrlsTest.php`, `tests/Integration/Images/PostgresImageReadTest.php`, `phpunit.image-reading-pgsql.xml`; frontend `vite.config.ts` y `src/shared/tests/imageProxy.browser.mjs`. Se conservaron los cambios previos y el contenido de ambos índices Git; no se realizaron commits ni operaciones remotas.

## Arquitectura

Todas las nuevas cargas de perfil, fotografía histórica institucional y noticias usan `DatabaseImageService`. La persistencia definitiva es la columna `stored_images.content`, de tipo **BYTEA en PostgreSQL**. No se usan Cloudinary, S3, servicios de almacenamiento externos ni archivos permanentes para nuevas imágenes. PHP utiliza su archivo temporal normal de recepción multipart; se lee y no se copia a `storage`.

Se reutilizaron los servicios, controladores, recursos JSON, formularios, permisos, revisiones y auditoría existentes. El servicio central usa Query Builder y sólo consulta el contenido al atender una petición binaria. Usuarios, estudiantes, noticias, listados e identificaciones consultan únicamente las referencias UUID. Las respuestas JSON siguen entregando `profile_photo_url`, `historical_photo_url` o `image_url`, sin bytes ni Base64. La generación de QR existente no se modificó.

### Esquema nuevo

Migración aditiva: `database/migrations/2026_11_05_000001_create_stored_images.php`.

| Tabla/columna | Función |
| --- | --- |
| `stored_images.id` | UUID primario de la imagen |
| `purpose` | `profile`, `historical` o `news` |
| `student_id`, `news_id` | Propietario con FK a `students` o `portal_news` |
| `content` | Binario BYTEA; BLOB exclusivamente en las pruebas SQLite |
| `mime_type`, `byte_size` | MIME detectado y longitud de los bytes persistidos |
| `sha256` | Hash SHA-256 del contenido finalmente almacenado |
| `width`, `height` | Dimensiones finales |
| `uploaded_at`, `uploaded_by` | Fecha de carga y FK al usuario responsable |
| `users.profile_image_id` | FK nullable de fotografía de perfil |
| `students.historical_image_id` | FK nullable de fotografía institucional actual |
| `portal_news.image_id` | FK nullable de imagen de noticia |

Se añaden índices por estudiante/finalidad y por noticia. PostgreSQL comprueba que exista exactamente el propietario correspondiente a la finalidad; también valida MIME, tamaño real con `octet_length`, límite de 5 MiB y dimensiones. Las FKs eliminan las imágenes al eliminar su propietario y ponen las referencias activas en null si se elimina una imagen. El responsable de carga puede quedar null al eliminar su cuenta. La lectura verifica además propietario, finalidad, tamaño y hash; una referencia de otro estudiante no permite acceder a sus bytes.

No se añade un modelo Eloquent con una relación que pudiera cargar accidentalmente los binarios. La escritura PostgreSQL usa parámetros y `decode(?, 'hex')`: la representación hexadecimal es sólo transporte SQL, la columna conserva bytes reales. La lectura soporta el recurso binario que puede devolver PDO. No requiere `pgcrypto` ni otras extensiones del servidor de base de datos.

### Transacciones y auditoría

- Perfil: se bloquean estudiante y usuario, se inserta la imagen, se actualiza la referencia, se audita y se elimina la imagen de perfil sustituida, dentro de una transacción.
- Histórica: se conserva la revisión optimista, la distinción entre primera carga y corrección, y el motivo obligatorio. Las versiones anteriores permanecen vinculadas al mismo estudiante para conservar la trazabilidad institucional. No se exponen mediante un endpoint genérico por UUID.
- Noticias: se bloquea la noticia y se verifica la revisión existente. Carga, reemplazo o eliminación, actualización de revisión y auditoría se confirman juntos. Las imágenes sustituidas o retiradas se eliminan dentro de la transacción.
- Un fallo de auditoría revierte los bytes nuevos y las referencias. La conservación de versiones históricas es intencional, no constituye una imagen huérfana.
- La auditoría registra referencias y revisiones, nunca contenido binario. El historial de noticias interpreta tanto `image_id` como la antigua `image_path` para presentar `has_image`.

## Endpoints y permisos

Todos los paths siguientes llevan el prefijo `/api/v1`. No existe un endpoint público para fotos de estudiantes ni un endpoint general que permita descargar cualquier UUID.

| Método y path | Acceso y comportamiento |
| --- | --- |
| `POST /students/profile/photo` | Estudiante: carga o reemplaza su foto, campo multipart `photo` |
| `GET /students/profile/photo` | Nuevo: estudiante autenticado, sólo su propia foto |
| `GET /admin/students/{student}/profile-photo` | Nuevo: administrador activo, foto de perfil del estudiante seleccionado |
| `GET /admin/students/{student}/historical-photo` | Administrador activo: lee foto institucional |
| `POST /admin/students/{student}/historical-photo` | Administrador: primera carga con `photo` y `revision` |
| `POST /admin/students/{student}/historical-photo/corrections` | Administrador: corrección con `photo`, `revision` y `reason` |
| `GET /exams/{exam_id}/entry-control/students/{student_id}/profile-photo` | Nuevo: autorización de examen y asociación del estudiante, igual que HU13/HU14 |
| `GET /exams/{exam_id}/entry-control/students/{student_id}/historical-photo` | Misma autorización de examen y asociación del estudiante |
| `GET /exams/{exam}/eligibilities/{student}/profile-photo` | Nuevo: docente responsable, con registro de habilitación en ese examen |
| `GET /exams/{exam_id}/collaborators/{user_id}/profile-photo` | Nuevo: docente responsable, examen disponible y colaboración activa |
| `GET /admin/portal/news/{news}/image` | Administrador activo: incluye borradores y archivadas |
| `POST /admin/portal/news/{news}/image` | Administrador: carga/reemplaza con multipart `image` y `revision` |
| `DELETE /admin/portal/news/{news}/image` | Administrador: retira imagen con la revisión actual |
| `GET /portal/news/{news}/image` | Público: únicamente si la noticia está actualmente `PUBLISHED` |

Se mantienen los controles existentes de Sanctum, sesión vigente, cambio obligatorio de contraseña, cuentas y roles activos. El endpoint de ingreso verifica el acceso del docente responsable o colaborador vigente y que el estudiante pertenezca al contexto consultado; conocer una URL no concede acceso. Las imágenes de borradores o noticias archivadas devuelven 404 desde la ruta pública, aun con un UUID conocido.

Las respuestas binarias incluyen `Content-Type`, `Content-Length`, `Content-Disposition: inline` con nombre generado, `X-Content-Type-Options: nosniff`, CSP restrictiva, ETag SHA-256 y `Vary: Origin, Cookie, Authorization`. Las fotos privadas usan `Cache-Control: private, no-store`. Las noticias públicas también usan `no-store` para comprobar publicación en cada petición. No se devuelve 304 sin volver a autorizar ni se habilita una caché compartida que sobreviva a la despublicación.

Una imagen ausente devuelve 404. Un contenido persistido cuyo tamaño/hash ya no coincide falla sin entregar los bytes. El parámetro `v` en URLs de fotos cambia con la referencia UUID; invalida el estado del componente React al reemplazar la foto, sin ser una credencial ni permitir seleccionar versiones anteriores.

## Validaciones y procesamiento local

- Perfil: máximo 2 MiB. Históricas y noticias: máximo 5 MiB. Se mantienen los límites del formulario y de Laravel.
- JPEG, PNG y WebP únicamente. Se rechazan SVG, GIF, texto, archivos disfrazados y contenido que no coincide con su MIME detectado.
- Dimensiones positivas, máximo 8000 píxeles por lado y 16 megapíxeles antes de decodificar.
- Validación de contenedor: firma y cierre JPEG, tamaño RIFF/WebP, y estructura, CRC y cierre de PNG. Se rechazan PNG truncados o con contenido añadido después del cierre.
- Con GD: decodificación completa, recodificación local, eliminación de metadatos y reducción proporcional a un máximo de 2048 píxeles por lado. JPEG/WebP usan calidad 85; PNG conserva transparencia. Se comprueba nuevamente el tamaño final antes de persistir.
- Sin GD: se conserva el archivo validado por MIME, dimensiones y contenedor; no se promete decodificación completa, recodificación ni eliminación de metadatos. El Dockerfile incluye ahora GD con JPEG, PNG y WebP para el despliegue.
- El hash y el tamaño corresponden a los bytes finales, que pueden diferir del archivo original tras optimizar.

No se usa el nombre suministrado por el usuario para servir rutas ni para construir SQL. El límite de píxeles reduce el riesgo de agotar memoria durante la decodificación. El procesamiento sigue siendo síncrono y debe dimensionarse según la concurrencia del despliegue.

## React y orígenes distintos

`StudentProfilePhoto`, las dos fotografías en `EntryQrStudentIdentity`, la vista administrativa, la habilitación y el detalle de colaboradores usan el componente existente `AuthenticatedImage`. Éste utiliza el cliente Axios existente con credenciales, solicita un blob, muestra una URL `blob:` y revoca el Object URL al cambiar de imagen o desmontarse. Los errores de una foto no hacen que se muestre la otra como sustituto.

Las noticias conservan sus componentes existentes: `AuthenticatedNewsImage` para administración y `NewsImage` para la publicación pública. Los contratos de los formularios multipart y de los recursos JSON permanecen iguales.

Para orígenes distintos, verificar `FRONTEND_URL`, el origen exacto admitido en CORS, `supports_credentials`, los dominios de Sanctum y las cookies de sesión del entorno. Las políticas del navegador sobre cookies entre sitios siguen aplicando; si los dominios son sitios distintos, validar `SameSite=None`/Secure o usar dominios del mismo sitio/proxy según la infraestructura. No se añadieron tokens en URLs ni fotos públicas para evitar esa configuración. Las pruebas de componentes simulan transporte y ciclo de vida; no sustituyen una prueba real de cookies/CORS en los dominios publicados.

## Compatibilidad y HU36

Se mantienen las columnas antiguas `users.profile_photo`, `students.historical_photo` y `portal_news.image_path`. La migración deja las nuevas referencias en null y no actualiza registros existentes. Si sólo hay una referencia local antigua, el endpoint autorizado puede leerla de forma transitoria: `avatars/` o `profile-photos/` para perfil, `institutional-photos/` para histórica y `portal-news/` para noticia. Se rechazan traversal y URLs remotas; no se descargan imágenes externas. Una ruta inexistente conserva su registro y produce 404.

La siguiente carga reemplaza la referencia del propietario por su FK nueva y deja en null la ruta antigua de ese propietario. No hay migrador de imágenes, descarga automática, backfill, seeder ni carga de fotografías reales. No se eliminan archivos antiguos durante la migración ni durante este trabajo. Si aparecieran archivos públicos históricos en otro entorno, su retirada/protección debe revisarse allí: los nuevos uploads nunca utilizan ese directorio.

HU36 conserva el funcionamiento offline existente: no se modificaron PWA, manifiestos, instantáneas, IndexedDB ni reglas de sincronización. No se incluyeron fotografías ni Base64 en las respuestas offline. Una ampliación futura podría descargar blobs por estos endpoints mientras el usuario tenga sesión y autorización vigentes, con selección explícita del examen, cuotas, caducidad y limpieza al cerrar sesión o revocar acceso. Antes de persistirlos en el dispositivo se debe acordar la política de privacidad y retención; esta funcionalidad futura no se implementó.

## Pruebas ejecutadas en aislamiento

Se creó un clúster PostgreSQL 15 temporal dentro del workspace, escuchando únicamente en `127.0.0.1:55439`, con usuario y base **`eida_images_test`**. No se utilizaron credenciales ni datos del despliegue. Los usuarios, estudiantes, noticias e imágenes de las pruebas fueron fixtures exclusivos de esa base temporal o de SQLite en memoria; no se ejecutaron seeders.

`tests/images-bootstrap.php` aborta antes de arrancar Laravel si detecta configuración cacheada o una conexión distinta de SQLite `:memory:` o de la configuración local de pruebas indicada. Ambos XML fuerzan `APP_ENV=testing`, vacían `DB_URL` y fijan conexión/destino; sesiones, caché, correo y cola usan recursos de prueba.

| Verificación | Resultado |
| --- | --- |
| `php vendor/phpunit/phpunit/phpunit -c phpunit.images.xml` | 108 pruebas, 1015 aserciones, todas correctas; SQLite en memoria sin GD |
| `php -d extension=gd vendor/phpunit/phpunit/phpunit -c phpunit.images-pgsql.xml` | 108 pruebas, 1009 aserciones, 1 omitida; PostgreSQL temporal con GD |
| Historial de noticias, con XML aislado y filtro `news_history` | 1 prueba, 12 aserciones, correcta |
| Pruebas focalizadas Node de imágenes, HU13/HU14, HU40/HU41 | 93 pruebas, todas correctas |
| ESLint de los componentes modificados y nueva prueba | Correcto |
| `npm run build` | Correcto; aviso de Vite por chunks mayores de 500 kB |
| Laravel Pint y `git diff --check` | Correctos |

La omisión PostgreSQL ya existía: `QrEntryTest` contiene una inyección de fallo mediante un trigger exclusivo de SQLite. Se ejecutó en SQLite. Las nuevas pruebas de imágenes verifican rollback de bytes/referencias ante fallo de auditoría en ambos motores.

Cobertura: subida, lectura binaria y tipo BYTEA real, comparación hexadecimal de bytes en PostgreSQL, MIME/tamaño/hash, reemplazo, ausencia, propietario incorrecto, autenticación y acceso denegado, foto administrativa, aislamiento perfil/histórica, correcciones institucionales y auditoría, endpoints de habilitación/colaboración, HU13/HU14 por QR/manual/historial, noticia publicada y protección de borradores/archivadas, eliminación de imagen, FKs y eliminación del propietario, rutas antiguas, rechazo de archivos inválidos/truncados y optimización con/sin GD. Se comprueba que las consultas normales no accedan a `stored_images`.

Para reproducir SQLite no hace falta un servidor:

```sh
php vendor/phpunit/phpunit/phpunit -c phpunit.images.xml
```

Para reproducir PostgreSQL, preparar **otro clúster temporal vacío**, con los parámetros locales reservados anteriormente. El XML usa `RefreshDatabase` y puede recrear el esquema de esa base de pruebas. No apuntarlo a un túnel, réplica, base compartida o despliegue. La extensión GD se habilitó sólo para el proceso CLI mediante `-d extension=gd`, sin modificar `php.ini`.

Comando frontend ejecutado desde su repositorio:

```sh
node --test src/shared/tests/databasePhotoConsumers.test.mjs src/shared/tests/authenticatedImage.test.mjs src/features/exams/tests/entryQrPhotos.test.mjs src/features/admin/tests/historicalPhoto.integration.test.mjs src/features/portal-news/tests/news.integration.test.mjs src/features/portal-news/tests/hu40.public.integration.test.mjs src/features/portal-news/tests/hu41.admin.integration.test.mjs
```

## Despliegue pendiente y primeras fotografías

1. Revisar los cambios y respaldar PostgreSQL. Comprobar que las migraciones anteriores, incluidas noticias y foto institucional, estén aplicadas. Esta entrega no ejecutó migraciones sobre la BD configurada del deploy.
2. Construir la imagen del backend con el Dockerfile actualizado. Verificar `pdo_pgsql`, `fileinfo` y GD con soporte JPEG/PNG/WebP. No fue posible validar la construcción Docker en esta sesión; el procesamiento GD sí se ejecutó con PHP local.
3. **Con autorización para el entorno objetivo**, aplicar únicamente la migración nueva. El comando siguiente queda documentado, no ejecutado en despliegue:

   ```sh
   php artisan migrate --path=database/migrations/2026_11_05_000001_create_stored_images.php --force
   ```

4. Publicar backend y frontend coordinadamente después de la migración, evitando que clientes antiguos intenten abrir las nuevas fotos privadas mediante un `<img>` sin la descarga autenticada. Actualizar configuración/cachés de Laravel según el proceso habitual, con `APP_DEBUG=false`, URL HTTPS correcta y CORS/Sanctum/cookies verificados.
5. Como estudiante existente: abrir **Mi perfil**, pulsar **Cambiar foto**, seleccionar JPEG/PNG/WebP de hasta 2 MiB y comprobar la foto. Repetir para probar reemplazo. No hace falta crear una cuenta o inscripción.
6. Como administrador: abrir el detalle de un estudiante existente y la sección de **fotografía institucional**, cargar hasta 5 MiB y confirmar. Para corregir, usar la acción de corrección con motivo y revisión actual. La foto de perfil se visualiza por separado; no se añadió permiso administrativo de reemplazo de perfil.
7. Como administrador: abrir noticias, crear o editar el texto, subir una imagen de hasta 5 MiB y comprobar que el borrador sólo se ve desde administración. Publicar explícitamente para mostrarla en el portal; verificar también que desaparezca públicamente al despublicar o archivar.
8. Comprobar HU13 y HU14 con usuarios autorizados y estudiantes ya inscritos, y repetir el acceso como usuario sin permiso. Verificar desde un navegador real con los dominios de producción y revisar la auditoría, sin introducir datos de demostración automáticamente.

## Limitaciones y riesgos operativos pendientes

- No se verificó el entorno de producción ni se cargaron allí fotografías. La migración y la publicación de ambos repositorios siguen pendientes.
- Se deben validar la imagen Docker, configuración de cookies/CORS y límites del proxy/PHP en el entorno objetivo. El Dockerfile mantiene `upload_max_filesize=5M` y `post_max_size=8M`.
- Sin GD se valida el contenedor, pero no se elimina metadata ni se garantiza una decodificación completa. JPEG con orientación EXIF no se rota automáticamente antes de retirar sus metadatos; conviene usar imágenes orientadas correctamente.
- La lectura materializa una imagen acotada en memoria para comprobar hash y longitud antes de enviarla. La codificación hexadecimal del parámetro SQL usa temporalmente aproximadamente el doble del tamaño binario. Medir memoria/latencia bajo concurrencia real.
- Los BYTEA y las versiones institucionales conservadas aumentan base, WAL y respaldos. Definir capacidad, copias/restauraciones y política de retención institucional antes de un uso masivo.
- Las URLs remotas antiguas no se descargan. Deben reemplazarse mediante carga web si existieran. No hay migración de archivos ni datos históricos en esta entrega.
- El almacenamiento offline de fotos no forma parte de esta entrega.

Se conservaron ramas, HEAD, staging y el documento que ya estaba staged. No se hicieron commits, push, pull, reset, restore ni clean, ni se accedió a directorios `-compare`. Las credenciales y archivos `.env` no se modificaron.

Referencias técnicas: [BYTEA de PostgreSQL](https://www.postgresql.org/docs/current/datatype-binary.html), [manejo de binarios con PDO](https://www.php.net/manual/en/pdo.lobs.php).

## Inventario de archivos de esta entrega

### oidevs-examenes-backend

- `Dockerfile`
- `app/Http/Controllers/Api/V1/Collaborators/ExamCollaboratorController.php`
- `app/Http/Controllers/Api/V1/Eligibility/ExamEligibilityController.php`
- `app/Http/Controllers/Api/V1/Portal/PortalNewsController.php`
- `app/Http/Controllers/Api/V1/Students/HistoricalPhotoController.php`
- `app/Http/Controllers/Api/V1/Students/ProfilePhotoController.php`
- `app/Http/Controllers/Api/V1/Students/StudentProfileController.php`
- `app/Http/Requests/Api/V1/Portal/PortalNewsImageRequest.php`
- `app/Http/Requests/Api/V1/Portal/SavePortalNewsRequest.php`
- `app/Http/Requests/Api/V1/Students/HistoricalPhotoRequest.php`
- `app/Http/Requests/Students/UpdateProfilePhotoRequest.php`
- `app/Http/Resources/Portal/PortalHistoryResource.php`
- `app/Http/Resources/Portal/PortalNewsResource.php`
- `app/Http/Resources/Students/StudentDetailResource.php`
- `app/Http/Resources/Students/StudentProfileResource.php`
- `app/Rules/SafeRasterImage.php`
- `app/Services/Entries/EntryControlService.php`
- `app/Services/Entries/EntryHistoryPresenter.php`
- `app/Services/Entries/QrEntryService.php`
- `app/Services/Images/DatabaseImageService.php`
- `app/Services/Portal/PortalNewsService.php`
- `app/Services/Students/HistoricalPhotoService.php`
- `app/Services/Students/StudentProfileService.php`
- `database/migrations/2026_11_05_000001_create_stored_images.php`
- `docs/ALMACENAMIENTO_IMAGENES_POSTGRESQL.md`
- `phpunit.images-pgsql.xml`
- `phpunit.images.xml`
- `routes/api/v1.php`
- `tests/Feature/Api/V1/Entries/EntryControlTest.php`
- `tests/Feature/Images/DatabaseImagesTest.php`
- `tests/Feature/Portal/PortalNewsTest.php`
- `tests/Feature/Students/StudentProfileTest.php`
- `tests/Support/RasterImageFixture.php`
- `tests/images-bootstrap.php`

### oidevs-examenes-frontend

- `src/features/admin/pages/AdminStudentDetailPage.tsx`
- `src/features/collaborations/components/CollaboratorDetailModal.tsx`
- `src/features/eligibilities/components/ManageEligibilityModal.tsx`
- `src/features/exams/components/EntryQrStudentIdentity.tsx`
- `src/features/students/components/StudentProfilePhoto.tsx`
- `src/shared/tests/databasePhotoConsumers.test.mjs`

