# Nelkano-web

Web de produccion de Nelkano alojada en Hostinger.

## Estructura

- `public_html/`: docroot Drupal.
- `public_html/modules/custom/nelkano_home/`: modulo custom de landing, cuenta, API y descargas.
- `config/sync/`: configuracion Drupal versionada.
- `composer.json` y `composer.lock`: dependencias de Drupal.

No versionar `vendor/`, `public_html/core/`, modulos contrib, `settings.php`, ficheros de usuario ni dumps.

## Reportes del emulador

La implementación activa del módulo está en `modules/custom/nelkano_home/`
(montada por Docker y utilizada por Composer como repositorio local).
Consulta [API de reportes para PC](modules/custom/nelkano_home/README.report-api.md)
para activar campos, emitir/revocar credenciales y consultar/descargar reportes.

## Entorno local Docker — Drupal 11.4.6

Actualizado y validado el 2026-09-05 con `drush updb`, `drush cex` y la prueba
HTTP de reportes. `config/sync` está montado con escritura para permitir `cex`;
`cex` exporta configuración, no datos de nodos. Atención: `config/sync` se ha
corregido después usando la base versionada anterior de producción, conservando
sus UUID, roles y ajustes y añadiendo los reportes y migraciones de Drupal 11.4.
La base local se instaló por separado y tenía otros UUID. El 2026-09-05 se
alinearon sus UUID de configuración por nombre/ID, con copia SQL previa y
validación de Drupal sin borrados ni renombrados. Después se ejecutó `cim`
correctamente; un segundo `cim` no tiene cambios y DB/sync coinciden. Los UUID
de contenido y los reportes se conservaron. Esta corrección se aplicó solo a
Docker local, nunca a producción. No exportar sobre este directorio desde otra
instalación independiente sin conciliar antes sus identificadores y ajustes.

El Composer versionado conserva `public_html/` para el despliegue. El runtime
Docker usa su propio `/opt/drupal/composer.json`, con docroot `web/` y el paquete
`nelkano/nelkano_home` instalado en `local-packages/` para no sobrescribir el
módulo activo montado en `web/modules/custom/nelkano_home`. Su repositorio de
tipo path apunta a este último directorio. Las versiones de dependencias son
las del lock versionado; cambian las rutas de instalación y el hash local del
manifiesto. No copiar directamente el manifiesto de producción sobre el runtime
Docker sin adaptar esas rutas. `symfony/runtime` está autorizado como plugin
Composer requerido por esta versión de Drupal.
