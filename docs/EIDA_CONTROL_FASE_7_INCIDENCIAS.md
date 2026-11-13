# Adaptador móvil HU27 · Fase 7

Implementación aditiva, escrita sin ejecutar pruebas/migraciones/despliegues. El informe completo y la matriz de contratos están en `C:/proyecto/eida-mobile/docs/EIDA_CONTROL_FASE_7_INCIDENCIAS.md`.

- Nuevos `GET /api/v1/exams/{exam_id}/discipline-context` y `POST /api/v1/exams/{exam_id}/offline/incidents`, separados de HU37.
- El transporte recibe `event_json` original y su SHA-256, valida campos/actor/dispositivo/paquete, conserva evidencia cifrada, UUID único y recibo individual en transacción.
- Reutiliza `IncidentRegistrationService::register`, `IncidentAccessService`, autorización/historial de paquetes y `AuditLogService`. No cambia HU27–HU31 ni sus políticas de sanción. El reporte oficial conserva PENDING interno / REPORTED público.
- Migración pendiente: `2026_11_09_000001_create_mobile_incident_sync_events.php`. No ejecutar rollback con evidencia: contiene una guarda que lo impide.
- `MobileDisciplinePermissions` es una **propuesta no conectada**. `MobileTokenPermissions` y `EnforceMobileTokenAccess` siguen denegando estas rutas para tokens móviles. Antes de activar, revisar scopes, rutas exactas, autorización contextual, reemisión de credenciales y pruebas con el Equipo 1. No quitar middlewares ni añadir permisos comodín.
- El docente que todavía autoriza HU27 puede reportar tarde; se marca revisión y nunca se crea una infracción/expulsión. Colaborador revocado/no disponible conserva conflicto. El tiempo del dispositivo no es una atestación.
- Las pruebas positivas del nuevo `MobileIncidentSyncTest` usan el mapeo propuesto únicamente en el contenedor aislado de testing; otro caso comprueba 403 con la política real intacta. No se ejecutaron. Concurrencia real PostgreSQL y retención/revisión institucional están pendientes.

También permanecen pendientes la conciliación de borradores con respuesta incierta y el acceso de actores que, tras perder su última colaboración, no pueden renovar sesión. No se inventaron roles revisores ni vías de autorización alternativas.
