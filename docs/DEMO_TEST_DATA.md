# EIDA Demo/Test Data

Dataset local creado para probar manualmente lo que actualmente esta integrado en `main`.

Comando:

```bash
php artisan db:seed --class=EidaDemoSeeder
```

No esta registrado en `DatabaseSeeder`, por lo que no se ejecuta automaticamente.

## Configuracion local requerida

Backend debe aceptar el dominio institucional usado por estas cuentas:

```dotenv
EIDA_INSTITUTIONAL_EMAIL_DOMAINS=umss.edu.bo
```

Despues de cambiar `.env`:

```bash
php artisan optimize:clear
```

## Credenciales

| Usuario | Rol | Identificador | Password inicial | Estado | Uso |
| --- | --- | --- | --- | --- | --- |
| Administrador Demo | ADMINISTRADOR | demo.admin@umss.edu.bo | DemoAdmin1 | ACTIVE, must_change_password=false | Login, `/admin`, HeaderAccount, display_name fallback, logout |
| Docente Principal Demo | DOCENTE + rol legado Docente | demo.docente@umss.edu.bo | 71000001 | ACTIVE, must_change_password=false | Login, `/docente`, `/teacher/subjects`, estudiantes, examenes |
| Estudiante Demo | ESTUDIANTE | 202600001 o demo.estudiante@umss.edu.bo | 81000001 | ACTIVE, must_change_password=false | Login, `/students/profile`, `/students/qr`, QR disponible/no disponible |
| First Access Demo | ESTUDIANTE | 202600004 o demo.firstaccess@umss.edu.bo | 81000004 | ACTIVE, must_change_password=true | Login y redireccion a `/cambiar-contrasena-inicial` |
| Inactive Demo | ESTUDIANTE | 202600005 o demo.inactive@umss.edu.bo | 81000005 | INACTIVE | Rechazo de Login |
| Docente Sin Materias Demo | DOCENTE + rol legado Docente | demo.docente.sinmaterias@umss.edu.bo | 71000003 | ACTIVE, must_change_password=false | Estado vacio del panel docente |

Nota: las passwords de perfiles docentes/estudiantes se inicializan con CI usando `InitialPasswordService` y se guardan hasheadas. Algunos usuarios se dejan con `must_change_password=false` para permitir entrada directa a paneles.

## Docentes HU04

| Docente | Email | Codigo institucional | CI | Estado |
| --- | --- | --- | --- | --- |
| Docente Principal Demo | demo.docente@umss.edu.bo | DEMO-EIDA-DOC-001 | 71000001 | ACTIVE |
| Marcela Quiroga Demo | demo.docente.segundo@umss.edu.bo | DEMO-EIDA-DOC-002 | 71000002 | ACTIVE |
| Ruben Vacio Demo | demo.docente.sinmaterias@umss.edu.bo | DEMO-EIDA-DOC-003 | 71000003 | ACTIVE |
| Elena Inactiva Demo | demo.docente.inactivo@umss.edu.bo | DEMO-EIDA-DOC-004 | 71000004 | INACTIVE |

## Estudiantes

| Estudiante | SIS | CI | Estado | Escenario |
| --- | --- | --- | --- | --- |
| Ana Estudiante Demo | 202600001 | 81000001 | ACTIVE | Inscrita y habilitada |
| Bruno Inscrito Demo | 202600002 | 81000002 | ACTIVE | Inscrito y habilitado |
| Carla Padron Demo | 202600003 | 81000003 | ACTIVE | Existe en padron, no inscrita |
| Diego Primer Acceso Demo | 202600004 | 81000004 | ACTIVE | First access |
| Eva Inactiva Demo | 202600005 | 81000005 | INACTIVE | Login rechazado |
| Fabian No Habilitado Demo | 202600006 | 81000006 | ACTIVE | Inscrito con `exam_eligibilities=NOT_ELIGIBLE` |

## Estructura academica

| Tipo | Dato |
| --- | --- |
| Carrera | SIS - Ingeniería de Sistemas |
| Periodo | DEMO EIDA 2/2026 |
| Materia A | DEMO-EIDA-TIS - DEMO EIDA Taller de Ingenieria de Software |
| Materia B | DEMO-EIDA-RED - DEMO EIDA Redes de Computadoras |
| Aula A | DEMO-EIDA-AULA-A |
| Aula B | DEMO-EIDA-AULA-B |

`course_offerings.teacher_id` referencia `teachers.id`.

## HU05 Importacion CSV de estudiantes

Carrera valida para CSV:

```text
Ingeniería de Sistemas
```

Headers exactos:

```csv
sis_code,identity_number,first_names,last_names,email,career,profile_photo
```

`career` debe coincidir exactamente con una carrera existente en BD. HU05 compara contra `careers.name`; no crea carreras automaticamente desde el CSV.

`profile_photo` actualmente debe ser un string no vacio. HU05 no requiere que exista un archivo fisico de imagen.

## Examenes HU11

| Examen | Oferta | Fecha dinamica | Resultado esperado |
| --- | --- | --- | --- |
| DEMO EIDA QR disponible - Parcial TIS | Taller de Ingenieria de Software | `now('America/La_Paz')->addHours(2)` | `GET /students/exams` muestra disponible y `GET /students/exams/{id}/qr` devuelve 200 |
| DEMO EIDA QR no disponible - Final TIS | Taller de Ingenieria de Software | `now('America/La_Paz')->addDays(3)->setTime(10, 0)` | Lista visible, QR todavia no disponible |
| DEMO EIDA examen pasado - Redes | Redes de Computadoras | `now('America/La_Paz')->subDay()->setTime(8, 0)` | Lista visible para inscritos; QR rechaza por examen finalizado |

El seeder no crea SVG ni QR precalculado. El token se genera bajo demanda mediante `StudentQrService`.

## Hallazgos funcionales relevantes

| HU | Estado actual | Evidencia |
| --- | --- | --- |
| HU01 | Parcial | `/api/v1/admin/test` protegido por admin; no hay UI admin real, `/admin` renderiza HomePage |
| HU02 | Implementada con ajuste local requerido | Fortify/Sanctum, `/login`, `/api/v1/me`, first access, single session, inactive user |
| HU03 | Parcial | middleware admin y auditoria de acceso; no hay panel administrativo funcional en frontend |
| HU04 | Backend implementado, frontend no implementado | endpoints CRUD de docentes bajo `/api/v1/admin/teachers`; header admin muestra "Docentes" sin ruta |
| HU05 | Parcial | import CSV de SIS para inscripciones, no importador de padron estudiantil completo |
| HU06 | Parcial | dashboard docente muestra materias/examenes, pero middleware usa rol legado `Docente` |
| HU07 | Implementada | listado e inscripcion manual/bulk de estudiantes por `course_offering` con verificacion de docente propietario |
| HU08 | Parcial | backend programa examenes; frontend muestra boton "Crear examen" deshabilitado |
| HU09 | No implementada | no se encontraron endpoints/UI especificos para esta HU |
| HU10 | Implementada | perfil estudiante y subida de foto en backend/frontend |
| HU11 | Parcial | listado y QR por examen existen; no se valida `exam_eligibilities` y depende de migracion aplicada |

## Checklist manual

AUTH

- [ ] Login ADMIN con `demo.admin@umss.edu.bo` / `DemoAdmin1`
- [ ] Login DOCENTE con `demo.docente@umss.edu.bo` / `71000001`
- [ ] Login ESTUDIANTE con `202600001` / `81000001`
- [ ] Ver `display_name` en HeaderAccount
- [ ] Logout
- [ ] First access con `202600004` / `81000004`
- [ ] Usuario inactivo con `202600005` / `81000005`
- [ ] Sesion unica: iniciar sesion dos veces con el mismo usuario

ADMIN

- [ ] Acceder a `/admin`
- [ ] Probar backend `/api/v1/admin/test`
- [ ] Probar `/api/v1/admin/teachers`
- [ ] Buscar docentes por nombre/codigo/email
- [ ] Activar/desactivar docente

DOCENTE

- [ ] Acceder a `/teacher/subjects`
- [ ] Acceder a `/teacher/students`
- [ ] Abrir estudiantes de Taller de Ingenieria de Software
- [ ] Ver estudiantes inscritos
- [ ] Agregar manualmente SIS `202600003`
- [ ] Importar CSV con header exacto `sisCode`
- [ ] Entrar con docente sin materias y confirmar estado vacio

ESTUDIANTE

- [ ] Acceder a `/students/profile`
- [ ] Acceder a `/students/qr`
- [ ] Ver examen QR disponible
- [ ] Descargar/ver QR del examen disponible
- [ ] Ver examen futuro con QR no disponible
- [ ] Probar QR de examen pasado

SEGURIDAD

- [ ] Confirmar que `/api/v1/me` no expone password, hash, CI, SIS ni `active_session_id`
- [ ] Confirmar que el JWT del QR contiene ids tecnicos (`std`, `exm`, `exp`, `jti`) y no nombres, correo, CI ni SIS
- [ ] Confirmar que ejecutar el seeder dos veces no duplica usuarios, roles, docentes, estudiantes, ofertas, inscripciones ni examenes
