# HU15 — Jerarquía de candidatos a colaboradores

Contrato acordado con frontend el 30 de octubre de 2026.

La regla se centraliza en `CollaboratorRoleHierarchy`: ADMINISTRADOR > DOCENTE >
ESTUDIANTE. El nivel efectivo es el mayor rol permanente cuyo catálogo y asignación
estén activos. No depende de IDs, del perfil elegido en pantalla ni de autorizaciones
temporales como colaborador. Una cuenta sin rol activo reconocido no es candidata.

## Endpoints y permisos

- `GET /api/v1/users?exam_id={exam_id}&search={query}` mantiene su respuesta `data`.
  Filtra cuentas inactivas, al propio responsable, inscripciones estudiantiles activas
  en la oferta y candidatos de nivel superior. No introduce paginación ni campos nuevos.
- `POST /api/v1/exams/{exam_id}/collaborators` mantiene `{ "user_id": 5 }` y el 201
  existente. Revalida actividad, inscripción, autoasignación, roles, disponibilidad y
  duplicados dentro de la transacción. Las cuentas implicadas se bloquean por ID antes
  de comprobar los roles, en coordinación con las mutaciones administrativas de cuentas.
- Solo el docente responsable puede gestionar colaboradores. Ser administrador no
  permite gestionar cualquier examen; un responsable con DOCENTE y ADMINISTRADOR
  activos sí usa su nivel efectivo ADMINISTRADOR.

Un candidato superior recibe 422, sin crear ni reactivar la autorización:

```json
{
  "message": "No puedes asignar como colaborador a un usuario con un rol superior al tuyo.",
  "errors": {
    "user_id": ["No puedes asignar como colaborador a un usuario con un rol superior al tuyo."]
  }
}
```

La jerarquía se aplica a nuevas asignaciones y reactivaciones. No se aplica como
revocación retrospectiva en `isCandidate`, `VerifyExamAccess` o `/me/collaborations`.
La vigencia, revocación, cuenta activa y restricción de inscripción existentes siguen
operando. No se modifican roles globales, fotografías ni esquemas de base de datos.

Frontend consume los candidatos y los errores del servidor; no replica la jerarquía.
