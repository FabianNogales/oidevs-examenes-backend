# EidaFullDemoSeeder: demostración y QA

## 1. Propósito y alcance auditado

Poblar una PostgreSQL nueva, con todas las migraciones actuales ya aplicadas, mediante una invocación explícita. El diseño se basa en la [auditoría funcional y de esquema](EIDA_FULL_DEMO_AUDIT.md), que contiene el mapa de entidades, FK, UNIQUE, CHECK, estados, rutas y revisión de seeders existentes. No se modificaron reglas de negocio, migraciones, constraints ni frontend.

Se crea `database/seeders/EidaFullDemoSeeder.php`. `DatabaseSeeder` sigue llamando únicamente a `RoleSeeder`. No se ejecutan `EidaDemoSeeder`, `StudentPanelDevSeeder` ni `UserSeeder`.

## 2. Preparación y comando

Revisar los cambios antes de ejecutar. Este desarrollo se verificó exclusivamente de forma estática: no se ejecutó ninguna migración, seeder, prueba, servidor, Docker ni conexión a Neon.

Configurar en el entorno de **demostración**:

```dotenv
EIDA_ALLOW_DEMO_SEEDING=true
EIDA_INSTITUTIONAL_EMAIL_DOMAINS=umss.edu.bo,est.umss.edu
```

El dominio del primer elemento se utiliza para todas las cuentas. El ejemplo de dominios no reemplaza la configuración institucional real. Sin dominio válido la seed falla: no inventa uno. La bandera es obligatoria en cualquier APP_ENV y `--force` no la evita. La nueva configuración está en `config/eida.php` y `.env.example`, con default false. Si el despliegue cachea configuración, su proceso habitual debe actualizarla antes de invocar la seed. No se modificó ni leyó el `.env` privado.

Desde el backend, **después** de que el usuario haya aplicado las migraciones actuales a su base de demo:

```bash
php artisan db:seed --class=EidaFullDemoSeeder --force
```

Después de completar la carga, volver a `EIDA_ALLOW_DEMO_SEEDING=false` y actualizar la configuración efectiva del despliegue. Para refrescar el reloj de demo se necesita habilitarla otra vez. APP_KEY, conexión PostgreSQL, dominio permitido, cookies/CORS y frontend deben estar configurados por el despliegue; este documento no incluye secretos de infraestructura.

## 3. Dataset final previsto en una base nueva

Cantidades deducidas del código, **no resultados de una ejecución**:

| Entidad | Cantidad | Distribución |
| --- | ---: | --- |
| users | 15 | 1 admin, 2 docentes, 12 estudiantes; 14 ACTIVE / 1 INACTIVE |
| administradores | 1 | Activo y must_change_password=false |
| teachers | 2 | Ambos activos, ofertas separadas |
| students | 12 | 10 perfiles ACTIVE / 2 INACTIVE; dos carreras activas |
| roles / role_user | 3 / 15 | Solo roles oficiales; asignaciones ACTIVE |
| careers | 3 | FULLQA-SIS, FULLQA-INF ACTIVE; FULLQA-LEG INACTIVE |
| subjects | 4 | FULLQA-TIS, FULLQA-RED, FULLQA-BD ACTIVE; FULLQA-OLD INACTIVE |
| career_subject | 7 | TIS/RED/BD compartidas entre SIS e INF, OLD histórica de LEG |
| academic_terms | 2 | Un ACTIVE y un INACTIVE, sin solapamiento |
| rooms | 4 | FULLQA-A/B/C ACTIVE, FULLQA-OFF INACTIVE; A ocupada inicialmente, C libre |
| course_offerings | 5 | 3 ACTIVE, 2 INACTIVE; UNIQUE docente/materia/periodo |
| enrollments | 21 | 19 ACTIVE y 2 INACTIVE |
| exams | 7 | 4 SCHEDULED, 1 IN_PROGRESS, 1 COMPLETED, 1 CANCELLED |
| exam_eligibilities | 40 | 38 ELIGIBLE, 2 INELIGIBLE; revision=1 |
| exam_collaborators | 3 | 2 ACTIVE, 1 REVOKED; no nuevo rol |
| exam_entries | 3 | VALID: 2 MANUAL y 1 QR histórico; cada par examen/estudiante distinto |
| audit_logs | 62 | 55 de aplicación/seed y 7 CREATE/Exam del trigger; incluye 3 manifiestos |
| student_qr_tokens | 0 al sembrar | El servicio oficial los crea al consultar QR disponibles |
| previews/importaciones | 0 | No se fabrican operaciones de importación |
| infraestructura/criterios/infracciones/expulsiones | 0 | Sin sesiones, access tokens, reset tokens ni datos de módulos no implementados |

En BD existente los roles oficiales se reutilizan sin cambios; las cantidades anteriores describen el dataset inicial, no el total de toda la base. Las relaciones usan IDs reales resueltos durante la carga. No hay IDs fijos, grupos inventados ni calificaciones.

## 4. Credenciales demo

`<dominio>` es el primer valor de `EIDA_INSTITUTIONAL_EMAIL_DOMAINS`. Estas contraseñas son ficticias y corresponden solo a la primera carga. La reejecución **no** las restablece.

| Perfil | Usuario | Password inicial disponible | Estado / must_change_password |
| --- | --- | --- | --- |
| Administrador | fullqa.admin@<dominio> | DemoAdmin1 | ACTIVE / false; acceso a panel administrativo |
| Docente 1, Irene Demo Docente | fullqa.docente1@<dominio> o fullqa-doc-01 | DemoTeacher1 | ACTIVE / false; principal |
| Docente 2, Tomas Demo Docente | fullqa.docente2@<dominio> o fullqa-doc-02 | DemoTeacher1 | ACTIVE / false; aislamiento y colaborador de B |
| Estudiantes 01–07, 10–12 | fullqa.estudianteXX@<dominio> o SIS | DemoStudent1 | Cuenta ACTIVE / false; perfil 10 INACTIVE |
| Estudiante 08, Hugo | fullqa.estudiante08@<dominio> o 990260008 | 99010008 (CI ficticio) | ACTIVE / true; primer acceso pendiente |
| Estudiante 09, Ines | fullqa.estudiante09@<dominio> o 990260009 | DemoStudent1 | Cuenta y perfil INACTIVE / false; login rechazado |

SIS: `990260001` a `990260012`. CI ficticio de estudiantes: `99010001` a `99010012`. CI docentes: `99000101` y `99000102`. La cuenta admin no necesita perfil académico. Fotos null; no se crean archivos en storage.

`StudentRegistrationService` y `TeacherService` crean cuentas/perfiles/pivots y llaman `InitialPasswordService`: CI como credencial inicial hasheada y must_change_password=true. Para las cuentas listas se guarda después un hash de contraseña DEMO y false, representando un primer acceso ya completado. Estudiante 08 conserva exactamente la credencial/flag del servicio. El admin usa `Hash::make()` directamente. No se almacenan contraseñas en texto plano, ni se incluyen hashes/passwords en auditoría. No se fabrican eventos LOGIN o PASSWORD_CHANGED.

## 5. Ofertas, inscripciones y fechas

Todos los tiempos de examen se calculan desde un único `CarbonImmutable::now('America/La_Paz')->startOfMinute()`. No se separa fecha de hoy de una hora calculada para mañana: el cambio de día funciona correctamente. Los timestamps de evaluación/ingreso se convierten a la zona configurada en app, como el resto de timestamps sin zona del proyecto.

| Oferta | Docente | Materia / periodo | Inscripciones |
| --- | --- | --- | --- |
| MAIN | 1 | TIS / actual / ACTIVE | Estudiantes 01–10; 05 INACTIVE, los demás ACTIVE |
| SECOND | 1 | RED / actual / ACTIVE | 01, 04, 07, 08 ACTIVE |
| OTHER | 2 | BD / actual / ACTIVE | 11, 12 ACTIVE |
| HISTORY | 1 | TIS / histórico / INACTIVE | 01–04 ACTIVE conservadas como historia |
| OFF | 2 | RED / actual / INACTIVE | 12 INACTIVE |

Periodo actual: ahora−30 días hasta ahora+30 días, ACTIVE. Histórico: ahora−100 hasta ahora−50 días, INACTIVE. La oferta histórica inactiva es un snapshot inicial de una relación que existió, no una llamada a la API que actualmente impide crear ofertas en periodos inactivos. Las ofertas/pivots históricos y los estados iniciales se insertan respetando las FK y los UNIQUE.

| Examen (nombre persistido con FULLQA) | Inicio relativo / duración | Estado | Escenario |
| --- | --- | --- | --- |
| A-INGRESO | −15 minutos / 240 min | IN_PROGRESS | Ingreso abierto inmediatamente durante aproximadamente 3h45; aula A, MAIN |
| B-PROXIMO | +6 horas / 90 min | SCHEDULED | QR disponible, ingreso bloqueado EXAM_NOT_STARTED; aula A, MAIN |
| C-FUTURO | +3 días / 90 min | SCHEDULED | QR UPCOMING fuera de ventana de 24h; aula B, MAIN |
| D-VENCIDO | −3 días / 90 min | SCHEDULED | Tiempo vencido, QR FINISHED aunque conserva estado programado; aula B, SECOND |
| E-HISTORICO | −75 días / 90 min | COMPLETED | Historia del periodo anterior, fuera del panel QR; aula A, HISTORY |
| F-CANCELADO | +2 días / 90 min | CANCELLED | Historia de cancelados y lectura sin ingreso/QR; aula C, MAIN |
| G-OTRO-DOCENTE | +8 horas / 90 min | SCHEDULED | Solo docente 2 administra; aula B, OTHER |

No hay endpoint para iniciar/finalizar exámenes: IN_PROGRESS/COMPLETED son estados que los servicios actuales reconocen, precargados como snapshots. Se usan los tipos reales partial/final/makeup. Los intervalos de reservas de los escenarios no se solapan. El servicio real comprueba reservas al crear los snapshots reservables.

## 6. Recorrido funcional e identificación de estudiantes

| Nº / nombre | MAIN y examen A | Resultado que se puede probar |
| --- | --- | --- |
| 01 Ana | Inscripción ACTIVE, ELIGIBLE, sin ingreso | Ingreso QR o manual positivo. También QR de B para probar WRONG_EXAM al escanearlo en A |
| 02 Bruno | ACTIVE, INELIGIBLE | Motivo INSTITUTIONAL_REQUIREMENT_PENDING y observación; sin QR de A/B. HU12 puede cambiar la decisión con revision |
| 03 Carla | ACTIVE, ELIGIBLE, ingreso MANUAL previo | REGISTERED y ALREADY_REGISTERED, sin duplicar ingreso VALID |
| 04 Diego | ACTIVE, ELIGIBLE, sin ingreso A | Segundo positivo; ingreso previo MANUAL en D para historia |
| 05 Elena | Inscripción INACTIVE, habilitación A retenida | Detalle directo muestra NOT_ENROLLED; no aparece en listado HU13 de inscritos activos ni obtiene QR de MAIN |
| 06 Felipe | ACTIVE, sin fila de habilitación A | ELIGIBILITY_PENDING / PENDING_ELIGIBILITY; sí tiene ELIGIBLE en B/C |
| 07 Gabriela | ACTIVE, ELIGIBLE | Positivo adicional, búsqueda por nombre/SIS/CI |
| 08 Hugo | ACTIVE, ELIGIBLE; contraseña inicial | Primero cambio obligatorio usando su CI; después acceso normal y QR |
| 09 Ines | Cuenta/perfil INACTIVE; inscripción y habilitación A retenidas | Login inactivo; docente ve STUDENT_INACTIVE en control |
| 10 Julia | Cuenta ACTIVE, perfil INACTIVE; inscripción y habilitación A retenidas | Demuestra independencia de HU21 y STUDENT_INACTIVE; sin QR estudiantil |
| 11 Kevin | Sin inscripción en MAIN; inscrito en OTHER | Manual A devuelve 404 (sin asociación). Autorización de colaborador A REVOKED, denegada |
| 12 Lucia | Sin inscripción en MAIN; inscrita en OTHER | Colaboradora ACTIVE de A con rol permanente ESTUDIANTE |

La ausencia de habilitación de 06 representa un snapshot heredado soportado por las consultas actuales. La programación e inscripción modernas normalmente inicializan ELIGIBLE mediante `ExamEligibilityInitializer`; no se falsea un tercer estado ni se llama al inicializador para luego borrar la fila. No hay endpoint que cree una habilitación ausente: PATCH/CSV actualizan filas existentes. Para probar edición HU12, usar 01/02 u otro con fila; para probar inicialización moderna, crear un examen o inscripción desde la aplicación.

Resumen inicial HU13 de A: 9 inscritos ACTIVE, 1 registrado y 8 pendientes (pendiente significa sin VALID, incluso inhabilitado/inactivo). 05 se consulta por su ID para el negativo de inscripción; 11/12 no se fuerzan al listado con relaciones falsas. Los ID se resuelven desde listados, no se asumen IDs numéricos de una BD vacía.

Negativos adicionales sin registros inválidos:

- En A, consultar/registrar 01 enviando `room_id` de FULLQA-B produce ROOM_MISMATCH.
- Enviar el QR real de B al endpoint QR de A produce WRONG_EXAM y su evento de auditoría real. No se generan tokens manualmente.
- Docente 2 intenta gestionar exámenes/inscritos/habilitaciones de docente 1: debe denegarse por propiedad. Su colaboración B le habilita únicamente el control autorizado de ese examen.
- Lucia ve A en `/me/collaborations` y `/collaborator`; Kevin no ve A tras revocación. C no tiene colaboradores.
- Repetir el alta de colaborador activo, una matrícula o una combinación de oferta existente permite probar rechazos reales de duplicados.
- B ofrece QR antes del inicio, pero ambos métodos de ingreso deben esperar. C no entrega QR todavía. D está vencido; E completado y F cancelado no aparecen en el panel QR.

## 7. Correspondencia módulo → datos

| HU/módulo | Datos y escenario disponible |
| --- | --- |
| HU01/02 | Admin listo, estudiante 08 primer acceso, 09 login denegado, sesión única probada manualmente con dos sesiones |
| HU04/06 | Dos docentes reales, tres ofertas activas y dashboard con futuros/examen en curso |
| HU05 | Carreras FULLQA Sistemas/Informatica y estudiantes existentes; preview/confirm y duplicados requieren CSV real |
| HU07/08 | Padrón de 12, 21 matrículas, ofertas únicas y aulas; alta manual/lote y programación posterior |
| HU10/11 | Perfiles sin foto, carreras, QR AVAILABLE/UPCOMING/FINISHED/NOT_ELIGIBLE/PENDING_ELIGIBILITY |
| HU12 | 40 habilitaciones, dos negativas con motivo y revision; filtros de estado y búsqueda |
| HU13/14 | A con positivos/negativos, pendientes/registrado, ingreso QR/MANUAL, aula incorrecta, QR de otro examen |
| HU15/16 | Tres relaciones y eventos de apertura/revocación generados por servicio, dos paneles activos |
| HU17 | Cuatro aulas con estados, capacidades, ubicaciones y disponibilidad calculada; crear aula posterior sin tocar fixtures |
| HU18 | Próximos, en curso, vencido, completado, cancelado; B/C/G para editar/cancelar antes de inicio, aislamiento docente y expected_updated_at |
| HU19 | Cinco combinaciones válidas, estados y dos propietarios; listados/filtros/edición/bulk/CSV |
| HU20 | Doce alumnos, dos carreras, búsqueda/detalle/edición/estado; no prometer filtro backend de estado inexistente |
| HU21/22 | Quince cuentas/pivots; asignaciones reales en historial y pestañas de materias/exámenes/colaboraciones; perfil 10 y cuenta 09 contrastan estados |
| HU23/24 | Cuatro materias/siete vínculos, periodos actual/histórico, estados y reglas de unicidad/solapamiento |
| HU25/26 | Carreras consultables y 62 eventos paginados con actores/acciones/fechas distintas; no existen mutaciones de carreras ni filtros avanzados generales de auditoría |

## 8. Auditoría, importaciones y QR

Se reutilizan `AuditLogService`, los eventos de registro de estudiantes/docentes y `ExamCollaboratorService` (incluida revocación y cierre de episodio). Cada INSERT de examen conserva el trigger CREATE/Exam con actor null y agrega un evento DEMO_EXAM_SNAPSHOT_CREATED explícito. Las asignaciones permanentes nuevas se registran como USER_ROLE_ASSIGNED y alimentan el historial HU21/22. Las cuentas/relaciones existentes nunca vuelven a emitir asignaciones al reejecutar.

Los tres ingresos se insertan como snapshots coherentes: examen/estudiante/aula/verificador existentes, habilitación ELIGIBLE y fecha dentro de su intervalo. Incluyen un QR histórico, sin fabricar su token. No pueden registrarse mediante el endpoint actual los exámenes ya vencidos. Sus eventos DEMO_ENTRY_SNAPSHOT_CREATED usan la fecha ficticia de ingreso y están expresamente identificados como snapshots. Los manifiestos y estos eventos iniciales utilizan INSERT a través del modelo/servicio existente: nunca UPDATE ni DELETE de audit_logs. Se preservan trigger y eventos anteriores incluso cuando se refresca el reloj de demo.

HU26 sanea contextos por allowlist: no muestra el JSON completo de manifiestos/snapshots ni datos sensibles. Es normal que algunos contextos DEMO aparezcan vacíos. El historial específico de usuarios sí permite sus filtros implementados; el listado general de auditoría solo page/per_page. El conteo 62 incluye eventos, no operaciones únicas.

No se crean previews falsos en room_imports, subject_imports o course_offering_imports. Tampoco existe una tabla de importaciones de estudiantes que necesite poblarse. Para probar importaciones se debe subir un CSV real con el contrato vigente; usar códigos FULLQA nuevos o existentes según se busque éxito/duplicado. No se crean archivos CSV, fotos ni dependencias de storage efímero.

La seed inserta cero tokens QR. `/students/exams` puede generar tokens al consultar los exámenes AVAILABLE mediante `StudentQrService`. El JWT/SVG se genera con el servicio oficial, requiere APP_KEY y expira al final del examen. No debe copiarse ningún token de documentación o de ejecuciones anteriores.

## 9. Idempotencia y transacciones

Tres fases transaccionales independientes: foundation (catálogos/cuentas), academic (periodos/ofertas/matrículas), scenarios (exámenes/habilitaciones/colaboraciones/ingresos). Cada fase inserta una única auditoría inmutable DEMO_SEED_STAGE_CREATED con entity_type EIDA-FULLQA-v1, dominio e IDs de sus registros. El bloqueo PostgreSQL de demo evita dos creaciones concurrentes; se adquiere después del bloqueo de integridad de periodos para mantener el mismo orden. No se invocan operaciones externas desde las transacciones.

Al encontrar el manifiesto se cargan **sus IDs** y se comprueba que los registros existen. No se apropia de una cuenta coincidente por correo/CI/SIS. En primera carga, cualquier colisión de clave natural aborta la fase. Los UNIQUE compuestos usan referencias recién creadas, sin asignaciones repetidas en los planes. Si falta un registro ya manifestado, hay manifiestos duplicados o cambia el primer dominio, se detiene para revisión: no se crean duplicados silenciosos.

Después de las fases, una cuarta transacción refresca únicamente estos campos **managed demo**:

| Registros propiedad de los manifiestos | Campos gestionados al reejecutar |
| --- | --- |
| Los dos periodos FULLQA | start_date y end_date relativos; nombre/estado se conservan |
| Los siete exámenes FULLQA | exam_date y start_time relativos; updated_at avanza con ExamRevision; nombre/estado/aula/duración/reglas/tipo se conservan |
| Los tres ingresos iniciales, mientras sigan VALID | entered_at = inicio del examen +5 minutos; updated_at; conserva verificador/método/estado |
| Tokens activos del examen FULLQA cuya ventana cambia | Se marcan REVOKED, con revoked_at/updated_at; mismo efecto temporal que reprogramación HU18, sin generar QR |

El refresh es una operación de fixture explícita: no se hace pasar por una edición normal HU18, que bloquearía exámenes ya iniciados. No agrega una regla ni un endpoint. Mantiene la versión monotónica con el servicio existente, revoca los tokens que quedarían obsoletos y agrega un único evento DEMO_TIMELINE_REFRESHED si hubo cambios, sin modificar auditorías previas. La revocación se limita a los exámenes cuyos IDs constan en el manifiesto. Estos campos temporales se sobrescriben deliberadamente, incluso si se editaron a mano; el resto se preserva.

Se vuelven a comprobar integridad de periodos y reservas destino antes de confirmar. Si una oferta cambió sus referencias, un examen fue vinculado fuera de los escenarios, el aula destino está inactiva, una edición de duración/aula causa solapamiento o existen ingresos nuevos posteriores a la seed en un examen que debe desplazarse, el refresh **falla y revierte esa transacción**. No desplaza nuevos ingresos ni altera una reserva ajena. Para una demostración nueva después de generar actividad manual extensa, preferir otra base demo vacía y conservar la anterior para historial.

Repetir dentro del mismo minuto sin cambios no agrega auditorías ni toca ventanas. Repetir después cambia las ventanas y suma un evento de refresh si no hay conflictos; no suma usuarios/roles/pivots/matrículas/habilitaciones/colaboraciones/ingresos. No restaura el escenario original si se cambió una habilitación, se anuló un ingreso, se revocó un colaborador, se desactivó un usuario o se cambió su contraseña. En tal caso las credenciales/escenarios iniciales de este documento pueden dejar de corresponder al estado manual actual.

Una falla en una fase conserva fases anteriores ya confirmadas; la reejecución las reconoce. No hay una transacción gigante ni limpieza destructiva de reintentos. No se programa la seed en arranque, deploy ni DatabaseSeeder.

## 10. BD existente, seguridad y reconocimiento

Namespace: códigos/nombres FULLQA, correos fullqa.*, SIS 990260001–012, CI 99010001–012/99000101–102 y manifiestos EIDA-FULLQA-v1. Ninguna cuenta ajena se actualiza; la reutilización global se limita a los tres roles oficiales mediante firstOrCreate sin sobrescribirlos. No hay credenciales reales ni datos personales reales.

Una BD con otro periodo ACTIVE o intervalos de periodos que colisionan **no se modifica para acomodar la demo**. La comprobación inicial falla antes de crear foundation. Elegir una BD demo nueva es la operación recomendada. Las colisiones posteriores se revierten por fase; revisar el mensaje antes de reintentar. No se desactivan periodos/roles ajenos ni se debilitan constraints.

Para reconocer exactamente qué IDs creó cada fase, una persona autorizada puede hacer esta consulta de solo lectura; la API pública de auditoría oculta el manifiesto completo por diseño:

```sql
SELECT id, new_values
FROM audit_logs
WHERE entity_type = 'EIDA-FULLQA-v1'
  AND action = 'DEMO_SEED_STAGE_CREATED'
ORDER BY id;
```

Para retirar manualmente los escenarios de uso, desactivar las cuentas/perfiles/ofertas/aulas y revocar colaboraciones mediante las APIs/UI disponibles; comprobar antes dependencias y protección del último admin. No hay endpoint de estado para carreras. No ejecutar borrados por prefijos, ni inferir propiedad solo por un código: usar los IDs del manifiesto.

La eliminación física completa en una BD compartida no es compatible con la inmutabilidad y las FK de auditoría (también preservan usuarios). No se ofrece un limpiador ni una receta que desactive triggers. Si se necesita retirar físicamente todo el dataset, el responsable debe gestionar el ciclo de vida de una base **desechable dedicada**, conservando el historial necesario; este cambio no automatiza esa operación. No intentar eliminar manifiestos para volver a sembrar: las claves existentes se rechazarán y se perdería la prueba de propiedad.

## 11. Impacto frontend y recorrido manual

No se cambió código visual ni se ejecutó build frontend. Al conectar la web a esta BD, cambia el contenido obtenido del backend en las rutas existentes de `../oidevs-examenes-frontend/src/app/router/router.tsx`:

| Página existente | Diferencia perceptible y revisión en navegador |
| --- | --- |
| /admin/students/manage, /admin/students/{id} | 12 perfiles, búsquedas, detalle/carreras, estados y pestañas de materias/historia/roles/colaboraciones |
| /admin/teachers, /admin/teachers/{id} | Dos docentes y expedientes con ofertas/exámenes separados |
| /admin/users/manage, /admin/users/roles, /admin/users/history, /admin/users/{id}/roles | Cuentas/pivots e historial de asignación; comprobar independencia de perfil 10, inactividad 09 y guardas de admin |
| /admin/teachers/assignments | Cinco ofertas, filtros por docente/periodo/estado, referencias reales |
| /admin/subjects, /admin/academic-terms, /admin/rooms, /admin/careers | Catálogos con estados; relaciones, periodo único, aula A ocupada y otras libres; carreras de solo consulta |
| /admin/audit-logs | Listado paginado/detalle y contextos saneados; comparar conteo de eventos, no número de operaciones |
| /admin/students/import, /admin/subjects/import, /admin/rooms/import, /admin/teachers/assignments/import | Formularios existentes; no mostrarán reportes precargados; requieren subir archivo |
| /docente, /teacher/subjects, /teacher/students, /teacher/students/{courseOfferingId} | Materias y matrículas del propietario, sin filtración del otro docente |
| /teacher/exams, /teacher/exams/{id}, /teacher/exams/{id}/edit | Próximos/pasados/cancelados y permisos temporales de edición/cancelación |
| /teacher/exams/{id}/eligibilities, /teacher/exams/{id}/collaborators | Habilitaciones, motivos/revision, dos asignaciones activas y revocación histórica en expediente |
| /students/profile, /students/qr | Fotos ausentes soportadas; mensajes QR por escenario, perfil/carrera y primer acceso |
| /collaborator, /exams/{id}/entry-control | Autorización de Lucia en A/Tomas en B; contador y bloqueos; control de cámara/QR y registro manual |

Comparar manualmente con cuentas admin, docente1, docente2, estudiantes01/02/03/06/08/09/10/11/12, preferiblemente en perfiles de navegador separados por la sesión única. Revisar navegación, nombres/IDs reales, paginación, búsqueda, estados, acciones permitidas/denegadas y mensajes temporales. Para escanear usar el QR real generado en otra pantalla/dispositivo y cámara con permisos/HTTPS. A debe aceptar el ingreso de 01 y rechazar un segundo ingreso, mientras B no permite ingreso antes de iniciar aunque entregue QR. No interpretar un placeholder de cámara o un listado con datos como verificación del escaneo real.

## 12. Limitaciones y validación realizada

- Solo revisión estática. `php -l` y Git revisan sintaxis/diff, no certifican consultas, triggers, hashing, permisos, concurrencia ni idempotencia ejecutada en PostgreSQL.
- No se ejecutaron migraciones, la seed, pruebas, PHPUnit, servidores, Docker, frontend build, composer dump-autoload ni conexiones a Neon. No commit/push/merge/rebase.
- Las fechas se vuelven a anclar únicamente al invocar la seed. No hay actualización automática mientras pasa el tiempo; A deja de admitir ingresos al terminar.
- La seguridad de reejecución conserva modificaciones manuales. El refresco se detiene ante actividad de ingreso nueva o conflictos que impedirían conservar coherencia.
- Recuperación por correo requiere mailer/destino real configurado por el entorno; estos correos/CI son ficticios, no bandejas verificadas. La seed no envía mensajes.
- Importaciones requieren archivos reales; cámara/QR requieren interacción de navegador. No se falsifican previews, tokens, fotos ni pruebas de correo.
- Auditoría general no tiene filtros avanzados; carreras no tiene mutaciones. No hay CRUD funcional para criterios, infracciones, casos especiales/expulsiones, ni notas/grupos o endpoint de anular ingresos/iniciar/finalizar examen.
- Los manifiestos y algunos snapshots de auditoría son internos; su contexto puede aparecer vacío en HU26 por el saneamiento existente.
- Roles oficiales existentes inactivos, primer dominio cambiado, claves naturales ocupadas, periodos incompatibles o registros de manifiesto faltantes requieren revisión; el seeder se detiene en vez de reparar datos ajenos.

## 13. Git y archivos entregados

Archivos nuevos: `database/seeders/EidaFullDemoSeeder.php`, este documento y `docs/EIDA_FULL_DEMO_AUDIT.md`. Archivos modificados: `config/eida.php`, `.env.example` (opt-in explícito). Ningún seeder previo ni archivo frontend modificado.

Comprobaciones autorizadas: `php -l database/seeders/EidaFullDemoSeeder.php`, `php -l config/eida.php`, `git diff`, `git diff --stat`, `git diff --check`, `git status --short`, y búsqueda estática de seeders/password/operaciones destructivas. Los archivos nuevos permanecen sin seguimiento; por eso `git diff --stat` normal solo enumera los dos archivos ya versionados. No se agregaron al índice.

Resultado de la revisión estática: ambos PHP sin errores de sintaxis; `git diff --check` sin salida y los tres archivos nuevos también revisados con `git diff --no-index --check` sin incidencias. La búsqueda del nuevo seeder no encontró truncate, borrados, drop ni ejecución de migraciones. Los únicos updates de reejecución están acotados a los campos temporales gestionados descritos arriba. `Sprint2DemoSeeder` no existe; sus menciones históricas no se convierten en dependencias.

Salida de `git diff --stat`:

```text
 .env.example    | 3 +++
 config/eida.php | 2 ++
 2 files changed, 5 insertions(+)
```

Salida de `git status --short`:

```text
 M .env.example
 M config/eida.php
?? database/seeders/EidaFullDemoSeeder.php
?? docs/EIDA_FULL_DEMO_AUDIT.md
?? docs/EIDA_FULL_DEMO_SEEDER.md
```

Los nuevos archivos también se inspeccionaron con `git diff --no-index` para que el control de whitespace no dependa de que estén agregados al índice. No se hizo commit ni push.
