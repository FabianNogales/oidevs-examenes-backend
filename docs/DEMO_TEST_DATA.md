# EIDA Demo/Test Data

Datos locales para demostrar manualmente HU01-HU11 del Sprint 1.

No contiene credenciales reales. Todas las contrasenas son de desarrollo local.

## Reconstruir la BD local

```bash
php artisan migrate:fresh
php artisan db:seed --class=EidaDemoSeeder
```

`EidaDemoSeeder` no esta registrado en `DatabaseSeeder`; se ejecuta manualmente.

## Configuracion local esperada

```dotenv
APP_URL=http://127.0.0.1:8000
FRONTEND_URL=http://127.0.0.1:5173
SANCTUM_STATEFUL_DOMAINS=127.0.0.1:5173,localhost:5173
MAIL_MAILER=log
QUEUE_CONNECTION=sync
EIDA_INSTITUTIONAL_EMAIL_DOMAINS=umss.edu.bo
```

Para recuperacion de contrasena con `MAIL_MAILER=log`, tomar el enlace desde:

```text
storage/logs/laravel.log
```

## Usuarios demo

| Rol | Correo | SIS | CI | Contrasena | Estado | Proposito |
| --- | --- | --- | --- | --- | --- | --- |
| ADMINISTRADOR | demo.admin@umss.edu.bo | - | - | DemoAdmin1 | ACTIVE | HU02, HU03, HU04, HU05 |
| DOCENTE | demo.docente@umss.edu.bo | - | 71000001 | 71000001 | ACTIVE | HU06, HU07, HU08 |
| DOCENTE | demo.docente.sinmaterias@umss.edu.bo | - | 71000003 | 71000003 | ACTIVE | HU06 estado vacio |
| DOCENTE | demo.docente.inactivo@umss.edu.bo | - | 71000004 | 71000004 | INACTIVE | HU02 login bloqueado |
| ESTUDIANTE | demo.estudiante@umss.edu.bo | 202600001 | 81000001 | 81000001 | ACTIVE | HU09, HU10, HU11 QR disponible |
| ESTUDIANTE | demo.estudiante.b@umss.edu.bo | 202600002 | 81000002 | 81000002 | ACTIVE | HU07 segundo estudiante inscrito |
| ESTUDIANTE | demo.estudiante.noinscrito@umss.edu.bo | 202600003 | 81000003 | 81000003 | ACTIVE | HU07/HU11 estudiante no inscrito |
| ESTUDIANTE | demo.firstaccess@umss.edu.bo | 202600004 | 81000004 | 81000004 | ACTIVE, must_change_password=true | HU02 primer acceso |
| ESTUDIANTE | demo.inactive@umss.edu.bo | 202600005 | 81000005 | 81000005 | INACTIVE | HU02 login bloqueado |
| ESTUDIANTE | demo.estudiante.nohabilitado@umss.edu.bo | 202600006 | 81000006 | 81000006 | ACTIVE | HU11 inscrito pero NOT_ELIGIBLE |
| ESTUDIANTE | 202300116@est.umss.edu | 202300116 | 92000116 | 92000116 | ACTIVE | HU02 recuperacion de contrasena con correo real |

Los docentes y estudiantes usan la logica real de contrasena inicial basada en CI. Los usuarios que deben entrar directo a paneles quedan con `must_change_password=false`, salvo el usuario de primer acceso.

## Cuenta recuperacion visual

| Correo | SIS | CI | Contrasena inicial | Objetivo |
| --- | --- | --- | --- | --- |
| 202300116@est.umss.edu | 202300116 | 92000116 | 92000116 | HU02 recuperacion de contrasena mediante correo real |

Esta cuenta no tiene inscripciones, examenes ni habilitaciones. Para login visual con correo real, el backend y frontend deben aceptar `est.umss.edu` en sus variables de dominios institucionales.

## Roles

El seeder crea solo roles oficiales:

- ADMINISTRADOR
- DOCENTE
- ESTUDIANTE

No crea el rol legado `Docente`.

## Catalogos

| Tipo | Codigo/nombre | Estado |
| --- | --- | --- |
| Carrera | SIS - Ingenieria de Sistemas | ACTIVE |
| Periodo academico | DEMO EIDA 2/2026 | ACTIVE |
| Materia 1 | DEMO-EIDA-TIS - DEMO EIDA Taller de Ingenieria de Software | ACTIVE |
| Materia 2 | DEMO-EIDA-RED - DEMO EIDA Redes de Computadoras | ACTIVE |
| Aula 1 | DEMO-EIDA-AULA-A - DEMO EIDA Aula A | ACTIVE |
| Aula 2 | DEMO-EIDA-AULA-B - DEMO EIDA Aula B | ACTIVE |

## Ofertas e inscripciones

| Oferta | Docente | Materia | Periodo | Uso |
| --- | --- | --- | --- | --- |
| Oferta principal | demo.docente@umss.edu.bo | DEMO EIDA Taller de Ingenieria de Software | DEMO EIDA 2/2026 | HU06, HU07, HU08, HU11 |
| Oferta secundaria | demo.docente@umss.edu.bo | DEMO EIDA Redes de Computadoras | DEMO EIDA 2/2026 | navegacion docente y examen finalizado |

Estudiantes inscritos en oferta principal:

- 202600001 Ana Estudiante Demo
- 202600002 Bruno Inscrito Demo
- 202600006 Fabian No Habilitado Demo

Estudiantes inscritos en oferta secundaria:

- 202600001 Ana Estudiante Demo
- 202600002 Bruno Inscrito Demo

No inscrito en la oferta principal:

- 202600003 Carla Padron Demo

## Examenes HU08/HU11

Las fechas se generan relativas al momento de ejecutar el seeder, usando zona horaria `America/La_Paz`.

| Escenario | Nombre | Estado | Fecha relativa | Estudiante clave | Resultado esperado |
| --- | --- | --- | --- | --- | --- |
| A | DEMO EIDA A - QR disponible | SCHEDULED | now + 2 horas | 202600001 ELIGIBLE | Aparece en Mis examenes y permite generar QR |
| B | DEMO EIDA B - QR aun no disponible | SCHEDULED | now + 48 horas | 202600001 ELIGIBLE | Aparece en Mis examenes, QR aun no disponible |
| C | DEMO EIDA C - Estudiante no habilitado | SCHEDULED | now + 3 horas | 202600006 NOT_ELIGIBLE | No debe poder obtener QR |
| D | DEMO EIDA D - Examen finalizado | SCHEDULED | now - 3 horas | 202600001 ELIGIBLE | Aparece como finalizado, no emite QR |

El seeder no precalcula QR. `student_qr_tokens` inicia vacia y los tokens se generan bajo demanda desde HU11.

## Habilitaciones

| Examen | SIS | Estado |
| --- | --- | --- |
| DEMO EIDA A - QR disponible | 202600001 | ELIGIBLE |
| DEMO EIDA A - QR disponible | 202600002 | ELIGIBLE |
| DEMO EIDA B - QR aun no disponible | 202600001 | ELIGIBLE |
| DEMO EIDA C - Estudiante no habilitado | 202600006 | NOT_ELIGIBLE |
| DEMO EIDA D - Examen finalizado | 202600001 | ELIGIBLE |

Sin registro de habilitacion, HU11 no muestra examen ni permite QR.

## Datos reservados para HU05

No estan sembrados. Usarlos para importacion manual CSV.

| SIS | CI | Correo |
| --- | --- | --- |
| 202600101 | 99000101 | hu05.test101@umss.edu.bo |
| 202600102 | 99000102 | hu05.test102@umss.edu.bo |
| 202600103 | 99000103 | hu05.test103@umss.edu.bo |
| 202600104 | 99000104 | hu05.test104@umss.edu.bo |
| 202600105 | 99000105 | hu05.test105@umss.edu.bo |

Headers exactos para HU05:

```csv
sis_code,identity_number,first_names,last_names,email,career,profile_photo
```

Valor valido para `career`:

```text
Ingenieria de Sistemas
```

`profile_photo` debe ser un texto no vacio; el flujo actual no exige que exista un archivo fisico.

## Casos sugeridos

- Login correcto: `demo.admin@umss.edu.bo` / `DemoAdmin1`.
- Cuenta inactiva docente: `demo.docente.inactivo@umss.edu.bo` / `71000004`.
- Cuenta inactiva estudiante: `202600005` / `81000005`.
- Primer acceso: `202600004` / `81000004`.
- Docente con materias: `demo.docente@umss.edu.bo` / `71000001`.
- Docente sin materias: `demo.docente.sinmaterias@umss.edu.bo` / `71000003`.
- Estudiante inscrito y habilitado: `202600001` / `81000001`.
- Estudiante no inscrito: `202600003` / `81000003`.
- QR disponible: `202600001` en `DEMO EIDA A - QR disponible`.
- QR fuera de ventana: `202600001` en `DEMO EIDA B - QR aun no disponible`.
- Estudiante no habilitado: `202600006` en `DEMO EIDA C - Estudiante no habilitado`.
