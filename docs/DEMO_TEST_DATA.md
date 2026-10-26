# EIDA Demo/Test Data

El único seeder actual es `database/seeders/EidaFullDemoSeeder.php`. Los seeders antiguos y sus credenciales/escenarios anteriores fueron retirados por solicitud del usuario.

Consultar [EIDA_FULL_DEMO_SEEDER.md](EIDA_FULL_DEMO_SEEDER.md) para el dataset completo, credenciales ficticias `fullqa.*`, escenarios por HU, protección de demo e idempotencia.

Ejecución explícita, sobre una base ya migrada y con `EIDA_ALLOW_DEMO_SEEDING=true`:

```bash
php.exe artisan db:seed --class=EidaFullDemoSeeder
```

No existe DatabaseSeeder: indicar siempre la clase. El seeder crea por sí mismo los tres roles oficiales; no depende de otra seed.

Para vaciar la base local y reconstruirla, seguir la sección «Vaciar únicamente la BD local y cargar la demo desde cero» de la guía completa. Ese procedimiento elimina todos los datos de la base seleccionada; verificar primero el destino local. No se ejecutó como parte de la limpieza de seeders.
