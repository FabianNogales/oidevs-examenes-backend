# Datos locales para integración HU34/HU39

Datos académicos ficticios de prueba, asociados a estudiantes demo existentes. No representan notas reales. No se importan automáticamente ni se alteran decisiones manuales, notas previas o revisiones.

## HU34

Usuario: Docente Principal Demo (`demo.docente@umss.edu.bo`).

En la base local de Héctor: oferta **1**, `DEMO EIDA Taller de Ingenieria de Software`.

Archivo listo: `docs/examples/hu34/docente_demo_oferta_1.csv`.

| SIS | Estudiante demo | P1 | P2 | Asistencia |
| --- | --- | --- | --- | --- |
| 202600001 | Ana | 60 | 70 | 19/20 = 95% |
| 202600002 | Bruno | 20 | 25 | 15/20 = 75% |
| 202600006 | Fabián | rendido sin nota | sin datos | sin datos |

Escenario recomendado: seleccionar asistencia mínima 90% y promedio mínimo 50 entre FIRST_PARTIAL y SECOND_PARTIAL, en un examen de esa oferta.

- Ana: computed_status ELIGIBLE.
- Bruno: computed_status INELIGIBLE.
- Fabián: computed_status PENDING; no se convierte en INELIGIBLE por campos vacíos.
- Si ya existe decisión manual/LEGACY, effective_status la conserva, aunque computed_status sea diferente. No borrar esa decisión para forzar la demostración.

En otra base, comprobar oferta, SIS e inscripciones: los IDs no son universales. El preview identifica referencias incorrectas. Si los estudiantes ya tienen datos importados, utilizar la corrección explícita por record_id, sin reiniciar ni reemplazar silenciosamente sus datos.

Flujo para Erick:

1. GET `/api/v1/academic-offerings/1/academic-records/import-schema`.
2. GET `/api/v1/academic-offerings/1/academic-records?search=` debe devolver 200, con data y meta.
3. Subir el CSV a POST `/api/v1/academic-offerings/1/academic-records/imports/preview` como multipart `file`.
4. Revisar can_confirm=true, 5 filas, 3 registros agrupados. Todavía no aparecen en el listado definitivo.
5. POST `/api/v1/academic-offerings/1/academic-records/imports/{preview_id}/confirm`. Devuelve created_records=3. Reintentar devuelve el mismo resultado.
6. Consultar listado y obtener record_id/revision.
7. Corregir un registro desde la UI. PATCH requiere reason, revision y todos los assessments que se deseen conservar, además de attended_classes/total_classes. No recalcula habilitaciones automáticamente.
8. Configurar los criterios del examen, ejecutar preview de evaluación, revisar computed/current/effective y confirmar. Refrescar HU12 e historial.

Para comprobar segunda instancia, utilizar un examen `evaluation_type=makeup` de esta misma oferta: el criterio obligatorio usa promedio >25. Ana cumple; Bruno no; Fabián queda PENDING. No hace falta cambiar el tipo de un examen existente solo para esta prueba.

No es necesario provocar un 409 para importar los datos. Opcionalmente, abrir el mismo registro en dos pestañas; guardar una corrección válida en la primera y luego intentar guardar el formulario anterior desde la segunda. Debe devolver 409 sin sobrescribir lo recién guardado. Es una edición concurrente normal; realizarla solo con estos datos demo. La validación automatizada ya cubre el caso.

## HU39

Seeder explícito y repetible:

```bash
php artisan db:seed --class=PortalCalendarDemoCategorySeeder
```

Agrega `Actividades académicas` si no existe. No cambia categorías existentes ni crea/publica eventos. Desde la corrección de HU39, la migración 2026_11_04_000002 instala automáticamente esta categoría si falta al ejecutar migrate. El seeder sigue disponible para comprobaciones y no exige ejecutar el seeder general de demo.

Consultar con ADMINISTRADOR activo: GET `/api/v1/admin/portal/event-categories`. Usar el ID real devuelto.

Luego probar: crear DRAFT → publicar PUBLIC → consultar portal → PRIVATE (desaparece, detalle público 404) → PUBLIC (reaparece) → despublicar → cancelar → historial. El evento creado durante la prueba puede quedar cancelado como evidencia histórica.

Los datos de la base local de Héctor no se transfieren por Git. Para otra BD, ejecutar las migraciones (incluida la inicialización de categorías) y cargar el CSV mediante la API/UI.
