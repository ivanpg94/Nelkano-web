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
la exportación es la configuración completa del sitio local, no datos de nodos.

El Composer versionado conserva `public_html/` para el despliegue. El runtime
Docker usa su propio `/opt/drupal/composer.json`, con docroot `web/` y el paquete
`nelkano/nelkano_home` instalado en `local-packages/` para no sobrescribir el
módulo activo montado en `web/modules/custom/nelkano_home`. Su repositorio de
tipo path apunta a este último directorio. Las versiones de dependencias son
las del lock versionado; cambian las rutas de instalación y el hash local del
manifiesto. No copiar directamente el manifiesto de producción sobre el runtime
Docker sin adaptar esas rutas. `symfony/runtime` está autorizado como plugin
Composer requerido por esta versión de Drupal.
