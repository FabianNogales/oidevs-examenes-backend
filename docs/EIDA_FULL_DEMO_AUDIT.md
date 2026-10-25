# Auditoría estática previa al dataset integral EIDA

Fuente de verdad: código local revisado el 24 de octubre de 2026. No se ejecutaron Laravel, migraciones, seeders ni pruebas. Este documento describe implementación comprobada, no certificación de ejecución en PostgreSQL.

## Modelo real

En las tablas de dominio hay PK `id`. Salvo `audit_logs` y `role_user`, tienen `created_at`/`updated_at`. `NN` significa obligatorio/no nullable; los campos indicados como opcionales admiten null. Los estados siguientes son los que admite el código; solamente los CHECK expresamente mencionados los restringen en PostgreSQL.

| Entidad | Campos NN relevantes, relaciones y UNIQUE | Estados y reglas vigentes |
| --- | --- | --- |
| users | email UNIQUE, password, status; opcionales profile_photo, email_verified_at, last_login_at, remember_token, active_session_id; must_change_password default false | ACTIVE/INACTIVE (`UserStatus`). Password cast hashed. Roles activos requieren también pivot activo. Inicio por correo institucional, SIS o código docente; sesión única. |
| roles / role_user | roles.name UNIQUE, status; description opcional. Pivot: user_id FK users, role_id FK roles, assigned_at default actual, status; assigned_by FK users opcional; UNIQUE(user_id,role_id) | ADMINISTRADOR, DOCENTE, ESTUDIANTE (`RoleName`). Colaboración no es un rol permanente. FK de asignador SET NULL, las otras RESTRICT. |
| students | user_id FK UNIQUE, sis_code UNIQUE, identity_number UNIQUE, first_names, last_names, career_id FK, status | ACTIVE/INACTIVE. CI numérico; SIS numérico de al menos 9 dígitos para login. Estado de perfil y cuenta pueden diferir por HU21. |
| teachers | user_id FK UNIQUE, institutional_code UNIQUE, identity_number UNIQUE, first_names, last_names, status | ACTIVE/INACTIVE, enum cast. course_offerings apunta a teachers.id, NO users.id. |
| careers | code UNIQUE, name, status; hasMany students; belongsToMany subjects | ACTIVE/INACTIVE en catálogos. Solo lectura administrativa de carreras. |
| subjects / career_subject | subjects.code UNIQUE, name, status. Pivot career_id/subject_id FK, UNIQUE(career_id,subject_id) | ACTIVE/INACTIVE; alta/edición exige carreras existentes activas. Code ASCII mayúsculas, hasta 50 caracteres. |
| academic_terms | name UNIQUE, start_date, end_date, status | ACTIVE/INACTIVE. CHECK end_date >= start_date. AcademicTermIntegrity además exige nombre único normalizado, ningún solapamiento (incluso inactivos), como máximo un ACTIVE; bloqueo transaccional compartido. |
| rooms | code UNIQUE, status; name/location/description/capacity/floor opcionales en BD | CHECK status IN ACTIVE/INACTIVE y capacity NULL o >0. API exige name y unicidad de name además de code. AVAILABLE/OCCUPIED son disponibilidad calculada, no estados persistidos. |
| course_offerings | subject_id, academic_term_id, teacher_id FK; status; UNIQUE(teacher_id,subject_id,academic_term_id) | ACTIVE/INACTIVE. API exige docente/cuenta/rol/materia/periodo habilitados al crear/reactivar. No hay paralelo/grupo. Guard impide crear/reasignar en periodo inactivo. |
| enrollments | course_offering_id/student_id/registered_by FK, status; UNIQUE(course_offering_id,student_id) | ACTIVE/INACTIVE. Acceso docente responsable y periodo habilitado para escritura. Inscripción tardía inicializa habilitación ELIGIBLE en exámenes disponibles. |
| exams | course_offering_id/room_id/created_by FK; name, exam_date, start_time, duration_minutes, status; evaluation_type default partial; rules opcional | Tipos partial/final/makeup. Servicios leen SCHEDULED, ACTIVE legado, IN_PROGRESS, CANCELLED, COMPLETED, FINISHED. API crea SCHEDULED, exige inicio futuro y duración >0. Reservas sin solapamiento para los tres primeros estados. Edición/cancelación solo propietario, antes del inicio, con expected_updated_at y referencias habilitadas. No hay transición pública para iniciar/finalizar. |
| exam_eligibilities | exam_id/student_id FK; status; reason/reason_code/observations/evaluated_by/evaluated_at opcionales; UNIQUE(exam_id,student_id); revision default 1 CHECK >0 | ELIGIBLE/INELIGIBLE. Motivos INSTITUTIONAL_REQUIREMENT_PENDING/OTHER; explicación/observación hasta 500. Lectura tolera NOT_ELIGIBLE legado, pero no debe sembrarse. PATCH exige revision. Ausencia se presenta como ELIGIBILITY_PENDING. |
| exam_collaborators | exam_id/user_id/assigned_by FK; access_code_hash, status, assigned_at; revoked_at opcional; UNIQUE(exam_id,user_id) | ACTIVE/REVOKED. Usuario activo de cualquier rol, sin inscripción estudiantil ACTIVE en esa oferta. Propietario asigna/revoca mediante servicio; hash rotatorio y eventos inmutables delimitan episodios. |
| student_qr_tokens | student_id/exam_id FK, token UUID UNIQUE, status, generated_at; revoked_at opcional | ACTIVE/REVOKED. Se persiste UUID por estudiante/examen, no imagen ni JWT. Servicio genera JWT v2 HS256 con APP_KEY y exp al fin, y SVG. Solo ELIGIBLE + perfil/inscripción activos y ventana [inicio−24h,fin). FK exam CASCADE; student RESTRICT. |
| exam_entries | exam_id/student_id/room_id/verified_by FK, verification_method, entered_at, status; annulled_at/annulled_by/annulment_reason opcionales | VALID/ANNULLED reconocidos por consultas; índice UNIQUE parcial (exam_id,student_id) WHERE status='VALID'. Registro QR/MANUAL requiere inicio <= ahora < fin y condiciones favorables. Sin endpoint público de anulación. |
| audit_logs | action, entity_type, created_at; user_id FK opcional RESTRICT, entity_id opcional sin FK, old_values/new_values JSONB opcionales, IP/user-agent opcionales | INSERT solamente: modelo y trigger prohíben UPDATE/DELETE. Trigger de INSERT exams agrega CREATE/Exam con actor null; no sustituye eventos de aplicación. Sin filtros avanzados generales. |
| room_imports / subject_imports / course_offering_imports | preview_id UUID UNIQUE, user_id FK RESTRICT, file_hash char64, status, expires_at timestamptz, preview_report JSONB; result_report/completed_at opcionales | CHECK PENDING/PROCESSING/COMPLETED. Previews efímeros reales ligados a actor/hash/expiración. HU05 estudiantes usa reporte/cache, sin tabla student_imports. |

Salvo excepciones señaladas, las FK del dominio usan RESTRICT; `exam_eligibilities.evaluated_by`, `exam_entries.annulled_by`, `special_cases.resolved_by` permiten SET NULL. Las siguientes tablas iniciales existen físicamente, pero no tienen modelos/servicios/rutas funcionales actuales. No se siembran ni se inventan sus estados o workflows:

| Tabla solo de esquema | Campos obligatorios y relaciones | Restricciones relevantes |
| --- | --- | --- |
| eligibility_criteria | name, source, criterion_type, created_by FK users, status; description opcional | Sin catálogo vigente de estados/tipos ni UNIQUE natural |
| exam_eligibility_criteria | exam_id FK, eligibility_criterion_id FK, is_required; parameters JSONB opcional | UNIQUE(exam_id,eligibility_criterion_id) |
| student_criterion_results | exam_id/student_id/eligibility_criterion_id FK, result, evaluated_by FK users, evaluated_at default actual; observation opcional | UNIQUE(exam_id,student_id,eligibility_criterion_id); sin estados de resultado implementados |
| special_cases | exam_id FK, type, description, reported_by FK users, status; student_id/resolved_at/resolved_by opcionales | FK student RESTRICT, resolved_by SET NULL; sin CHECK de estados ni UNIQUE natural |
| infractions | exam_id/student_id FK, type, description, reported_by FK users, occurred_at default actual, status | Sin CHECK de estados ni UNIQUE natural |
| expulsions | exam_id/student_id FK, reason, expelled_at default actual, expelled_by FK users; infraction_id FK opcional | UNIQUE(exam_id,student_id) |

También existen password_reset_tokens (email PK, token, created_at opcional) y personal_access_tokens (token UNIQUE, tokenable_type/id polimórficos, name, abilities/last_used_at/expires_at opcionales) para infraestructura; no se precargan sesiones ni tokens de recuperación/acceso.

## Funcionalidad y rutas comprobadas

Prefijo de las siguientes rutas: `/api/v1`, excepto autenticación Fortify. Los grupos protegidos aplican Sanctum, sesión vigente, cambio inicial y autorización específica según `routes/api/v1.php` y `bootstrap/app.php`.

| Módulo/HU | Implementación actual | Endpoints principales | Datos necesarios para demo |
| --- | --- | --- | --- |
| HU01/HU02 autenticación | Sí, Fortify; sesión única y primer acceso | POST /login, /logout, /forgot-password, /reset-password; PUT /user/password; GET /me; POST /auth/logout | Admin activo, cuentas listas, primer acceso, cuenta inactiva |
| HU04 docentes | Sí, alta/edición/estado/lista/detalle | GET/POST /admin/teachers; GET/PUT /admin/teachers/{id}; PATCH .../status | Dos docentes y perfiles reales |
| HU05 importación estudiantes | Sí, preview/confirm CSV | POST /admin/students/import/preview y /confirm | Carreras por nombre, cuentas existentes para duplicados; archivo real posterior |
| HU06 panel docente | Sí | GET /teacher/dashboard/subjects y /upcoming-exams | Ofertas de dos propietarios distintos |
| HU07 inscripciones | Sí, manual/lote/preview | GET .../enrollments; POST /course-offerings/{id}/enrollments/manual, /bulk, /bulk/preview | Padrón y matrículas activas/inactivas |
| HU08 programación | Sí | POST /course-offerings/{id}/exams | Oferta activa y aulas reservables |
| HU10 perfil estudiante | Sí, foto opcional | GET /students/profile; POST /students/profile/photo | Perfil, carrera, foto null |
| HU11 QR estudiante | Sí, generación dinámica y UUID persistido | GET /students/exams y /students/exams/{id}/qr | Exámenes dentro/fuera de ventana y habilitaciones |
| HU12 habilitaciones | Sí, lista/búsqueda/estado, PATCH y CSV | GET /exams/{id}/eligibilities, /reasons; PATCH .../{student}; POST .../bulk | ELIGIBLE/INELIGIBLE, motivos, revision |
| HU13 identificación | Sí, consulta previa y bloqueos | GET /exams/{id}/entry-control/students y .../{student} | Inscritos, inactivo, inhabilitado, pendiente, ingreso previo |
| HU14 ingreso | Sí, QR y manual | POST .../entry-control/qr; POST .../students/{student}/entries; GET .../entry-control/entries | Examen ya iniciado y operador autorizado |
| HU15 colaboradores | Sí, autorización temporal por usuario | GET/POST /exams/{id}/collaborators; DELETE .../{user}; GET /users?exam_id=... | Usuario no inscrito activo en oferta del examen |
| HU16 panel colaborador | Sí | GET /me/collaborations | Asignación activa en examen no vencido |
| HU17 aulas | Sí, CRUD sin eliminación, estados/importaciones/disponibilidad | GET/POST /admin/rooms; GET/PUT .../{id}; PATCH .../status; POST .../import/{preview,confirm}; GET /rooms | Aulas ACTIVE/INACTIVE, reserva vigente y aula libre |
| HU18 gestión exámenes | Sí, vistas próximas/pasadas/canceladas y concurrencia | GET /teacher/exams, /{id}; PATCH /{id}; POST /{id}/cancel | Exámenes en distintos tiempos/estados y propietario alternativo |
| HU19 ofertas | Sí, catálogo/bulk/edición/estado/CSV | GET /admin/course-offerings y /{id}; POST /bulk, /import/{preview,confirm}; PUT /{id}; PATCH /{id}/status | Combinaciones únicas docente/materia/periodo |
| HU20 estudiantes | Sí, alta/edición/estado/búsqueda/detalle | GET/POST /admin/students; GET/PATCH .../{id}; PATCH .../status | 12 perfiles y carreras; lista NO tiene filtro backend por estado |
| HU21/HU22 cuentas/roles/expedientes | Sí actualmente, a diferencia de documentación histórica HU25/26 | GET /admin/users, /roles, /users/history, /users/{id}, /users/{id}/{roles,history}; PATCH .../status; POST .../roles; DELETE .../roles/{role}; GET /admin/profiles/{students,teachers}/{id}/records | Cuentas, roles/pivots, historial de asignación, materias/exámenes/colaboraciones. Búsqueda de usuarios, sin filtro general de estado/rol en service |
| HU23 materias | Sí, alta/edición/estado/búsqueda/CSV | GET/POST /admin/subjects; GET/PUT .../{id}; PATCH .../status; GET /careers; POST /import/{preview,confirm} | Materias con carreras, ACTIVE/INACTIVE |
| HU24 periodos | Sí, alta/edición/estado/activo/lista | GET/POST /admin/academic-terms; GET /active, /{id}; PUT /{id}; PATCH /{id}/status | Un activo, uno histórico sin solapamiento |
| HU25 carreras | Parcial: lectura, búsqueda y detalle | GET /admin/careers y /{id} | ACTIVE/INACTIVE; no prometer alta/edición/estado vía API |
| HU26 auditoría | Parcial: listado paginado y detalle inmutables | GET /admin/audit-logs y /{id} | Eventos reales variados. Solo page/per_page, sin filtros de actor/fecha/acción generales |
| Salud | Sí | GET /health; /up fuera del prefijo | Sin datos |
| Criterios, infracciones, casos especiales, expulsiones | Solo esquema inicial | Ninguno | No crear datos para módulos inexistentes |

## Seeders y factories existentes

| Seeder | Datos/idempotencia | Cambios a existentes y decisión |
| --- | --- | --- |
| DatabaseSeeder | Solo llama RoleSeeder | Se conserva; seed genérico no cargará la demo |
| RoleSeeder | Tres roles oficiales con firstOrCreate | No cambia descripciones/estados/passwords existentes. Único seeder reutilizado; si un rol existente está inactivo, la demo falla sin reactivarlo |
| EidaDemoSeeder | Usuarios/catálogos/ofertas/exámenes/habilitaciones; evita algunos duplicados | Restablece passwords, estados, roles y fechas; puede reutilizar/modificar carrera SIS ajena, periodo absoluto, NOT_ELIGIBLE legado. No se llama ni modifica |
| StudentPanelDevSeeder | Cinco estudiantes y dos exámenes | Limpieza con borrados amplios; AVAILABLE en rooms viola CHECK actual; fechas fijas. No se llama ni modifica |
| UserSeeder | Tres cuentas test.com y roles | No idempotente, dominios externos, no crea perfiles. Cast de User hashea passwords. No se llama ni modifica |
| UserFactory/TeacherFactory | Datos aleatorios orientados a pruebas | No se usan: no aseguran dominio institucional ni namespace identificable |

## Evidencia de reglas que condicionan el diseño

- Migración inicial y todas las posteriores en `database/migrations/`: FK, UNIQUE parcial de ingresos, cambio teacher_id, colaborador user_id, checks y triggers.
- `app/Enums/{RoleName,UserStatus}.php`, `app/Models/*`: roles, casts, relaciones e inmutabilidad.
- `app/Services/Entries/{ExamEntryAvailability,EntryControlService,QrEntryService}.php`: zona America/La_Paz, preparación antes del inicio, ingreso desde inicio hasta fin, bloqueos y prioridad ALREADY_REGISTERED.
- `app/Services/Students/StudentQrService.php`: tokens bajo demanda, QR no equivale a autorización de ingreso; NOT_ELIGIBLE es etiqueta de respuesta compatible, no nuevo estado persistido.
- `app/Services/AcademicTerms/*`, `app/Services/Rooms/RoomAvailabilityService.php`, `app/Services/Exams/*`: integridad, reservas y control de versiones.
- `app/Services/{Students/StudentRegistrationService,Teachers/TeacherService,Auth/InitialPasswordService,Auth/LoginUserResolver,Admin/AdminUserService,Admin/AdminProfileRecordsService}.php`: CI inicial, flags, dominios, roles y permisos actuales.
- `app/Support/Eligibility/EligibilityRules.php`, requests/resources/controllers de cada módulo y rutas actuales: contratos públicos y límites reales.
- Revisión estática de pruebas, especialmente `SharedExamAvailabilityTest`, `ExamCollaboratorTest`, `AcademicTermIntegrityTest` y fixtures; no se ejecutaron.
- `docs/EXAM_ENTRY_AVAILABILITY.md` coincide con código. `docs/HU25_HU26_READ_API.md` contiene una afirmación histórica sobre HU21/HU22 superada por las rutas/servicios actuales; se sigue el código.
- `../oidevs-examenes-frontend/src/app/router/router.tsx` se revisó únicamente para identificar páginas afectadas por los nuevos datos.

El dataset y su operación están documentados en [EIDA_FULL_DEMO_SEEDER.md](EIDA_FULL_DEMO_SEEDER.md).
