# Nelkano-web

Web de produccion de Nelkano alojada en Hostinger.

## Estructura

- `public_html/`: docroot Drupal.
- `public_html/modules/custom/nelkano_home/`: modulo custom de landing, cuenta, API y descargas.
- `config/sync/`: configuracion Drupal versionada.
- `composer.json` y `composer.lock`: dependencias de Drupal.

No versionar `vendor/`, `public_html/core/`, modulos contrib, `settings.php`, ficheros de usuario ni dumps.

## Reportes del emulador

La implementación activa del módulo está en `public_html/modules/custom/nelkano_home/`
(montada por Docker y utilizada por Composer como repositorio local).
Consulta [API de reportes para PC](public_html/modules/custom/nelkano_home/README.report-api.md)
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

## Fichas de sistemas

Las fichas públicas de cada core se editan en **Páginas editables → Sistemas**
(`/admin/nelkano/sistemas`), como sección independiente de Home, y se abren desde
«Más información» en Estado actual. Consulta [Fichas de sistemas: edición,
activación y pruebas](public_html/modules/custom/nelkano_home/README.systems.md).

## Backlog de HU

El tablero está en /admin/nelkano/backlog, debajo de Reportes de errores.
Consulta [Backlog, taxonomía y migración 11021](public_html/modules/custom/nelkano_home/README.backlog.md) para activación, permisos y pruebas.

## Despliegue del rediseño y configuración guardada

La configuración editorial actual está guardada en `config/sync`: Home,
sistemas ES/EN, guía, SEO, documentación y estilo de miniaturas. No incluye
usuarios, reportes, backlog, catálogos CSV ni archivos subidos: esos datos
permanecen en la base de datos y en los directorios de archivos del servidor.
Los iconos de Figma y el logo WebP sí están versionados con el módulo.

Para actualizar una instalación existente, usa `bash scripts/deploy-hostinger.sh`.
`hostinger-deploy.sh` es un alias de esa misma entrada segura; ya no ejecuta
`site:install`, cambia UUID ni reescribe los ajustes de cuenta, caché o portada.
El orden es `composer install --no-dev --optimize-autoloader --no-interaction`
y `drush --root=public_html deploy --yes` (updates, configuración y cachés).
No usar `composer update` ni sustituir `settings.php` por el de Docker.

Antes del despliegue, conserva una copia SQL, los archivos públicos/privados y
un exportado de la configuración **del servidor**, fuera de `public_html`.
Revisa los cambios editoriales de producción que aún no estén en Git: `cim`
publica el contenido de `config/sync` y sustituye la configuración correspondiente.
El UUID del sitio, las credenciales de BD y sus rutas deben seguir siendo los del
servidor. El APK ya referenciado por Versiones permanece en los archivos del
servidor; Git no transporta ese archivo ni otros uploads.

Las actualizaciones nuevas son `11026–11033`, todas en `nelkano_home.install`:
catálogo de sistemas, compatibilidad CSV, contenido inicial del rediseño, guía,
metadatos de configuración, SEO, visibilidad conjunta ES/EN y miniaturas WebP.
`11029` usa el lector YAML de Drupal (también admite el JSON inicial) y `11033`
habilita la dependencia de imágenes antes de crear el estilo. No hay cambios
en tablas de cuentas, reportes o backlog, ni en los contratos API existentes.
No repetir hooks manualmente: Drupal registra cuáles se han ejecutado.

Validación del 20-09-2026: copia aislada de la BD local, configuración previa al
rediseño y versión del módulo `11025`; ejecución real de `updatedb`, `deploy` y
un segundo `deploy` sin actualizaciones ni cambios de configuración pendientes.
Se verificaron el UUID y los hashes de 101 tablas persistentes antes/después.
Pasaron 1.346 comprobaciones funcionales (sistemas, CSV, formularios, SEO,
visibilidad e imágenes), la sintaxis PHP y la protección del módulo ante Composer.
Esto es un ensayo local con Drupal 11.4.6, no una ejecución en producción.

La prueba de migraciones `scripts/test-release-updates.php` rechaza cualquier
BD cuyo nombre no empiece por `nelkano_release_qa_`. En una copia aislada, coloca
los dos YAML anteriores (`nelkano_home.settings` y `nelkano_home.docs`) en
`/tmp/nelkano-release-baseline` y ejecuta sus fases mediante la variable
`NELKANO_RELEASE_TEST_PHASE`: `prepare`, después `updatedb`, `verify`, después
`deploy`, y finalmente `verify-sync`. La fase `prepare` modifica únicamente la
configuración de la copia y su versión de esquema para reproducir la migración.
Nunca ejecutar esta preparación sobre la BD local de trabajo o producción.
