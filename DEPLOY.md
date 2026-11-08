# Despliegue de Nelkano web

Procedimiento vigente desde el 08/11/2026, por instrucción expresa de Ivan.
Desde el repositorio de la web en producción:

```bash
git pull --ff-only origin main &&
composer install --no-dev --optimize-autoloader --no-interaction &&
./vendor/bin/drush --root=public_html deploy --yes
```

Si el código ya está actualizado, sólo hacen falta Composer y Drush. También
puede usarse `bash scripts/deploy-hostinger.sh`, que ejecuta exactamente esos
dos comandos; no se ejecuta además del bloque anterior.

No ejecutar copias de BD, archivos o configuración, exportaciones, SQL dumps,
restauraciones, empaquetadores, activaciones auxiliares ni mantenimiento manual
como parte del despliegue habitual. No usar `composer update`.

`drush deploy` ejecuta las actualizaciones pendientes, importa configuración,
reconstruye cachés y ejecuta los hooks de despliegue. La documentación del libro
que cambie se aplica mediante esos hooks, sin comandos Book separados.

Las versiones y ejecutables subidos desde administración, sus canales y la
visibilidad del SDK se conservan durante la importación mediante una
transformación en memoria. No se crean snapshots ni copias para conservarlos.
Los CSV y los ejecutables los sube Ivan directamente a producción; no se
transportan ni se publican con el despliegue de código. SDK Studio sigue oculto.

Detenerse si un comando falla y corregir la causa concreta. Añadir únicamente
el paso que requiera ese error o un cambio específico, sin recuperar las recetas
antiguas. El despliegue no reinstala Drupal ni sustituye settings.php.

Despliegue comprobado el 08/11/2026: Composer y Drush finalizaron correctamente,
esquema de nelkano_home 11048 y documentación SDK conciliada mediante deploy hook.
Se verificaron home, Versiones, compatibilidad y canales de actualización.
Las aplicaciones publicadas siguen en 3.0.6/16 hasta que Ivan suba la entrega 3.0.7.
No se hicieron copias de la BD ni se subieron ejecutables o CSV.
