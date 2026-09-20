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
servidor. Los APK de Versiones se transportan en `release-artifacts/`, con su manifiesto
SHA-256. Los demás uploads permanecen en el servidor.

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

## APK y publicación de versiones

Incluye `release-artifacts/` completo y `config/sync/nelkano_home.docs.yml` en el
paquete de despliegue. No basta con copiar el módulo o exportar la configuración.
`deploy-hostinger.sh` verifica los APK referenciados, los copia a
`public://nelkano-releases`, los registra como archivos permanentes y después
importa la configuración. Un archivo ausente, corrupto o distinto de otro ya
existente con el mismo nombre detiene el proceso antes de importar la versión.
No sustituye APK publicados con otros binarios ni cambia la visibilidad o fecha.

La 2.0.0-beta está preparada como borrador en ES y EN, con el archivo
`Nelkano 2.0.0-beta.apk`. El binario es ARM64, aunque el nombre sigue la convención
pública. Antes del despliegue definitivo, tras aprobar la revisión en el teléfono,
marca la versión visible y fija la fecha real en ambos idiomas; exporta esa
configuración a `config/sync`. Hasta entonces, la home conserva la 1.0.0-beta.
La home ordena las versiones visibles por número y elige la última con APK
existente. No requiere cambiar manualmente el enlace de descarga.

Comprobaciones locales: `NELKANO_RELEASE_ASSETS_TEST=1 drush php:script scripts/test-release-assets.php`
(selección ES/EN, borrador, publicación simulada sin guardar, archivo ausente y
rechazo de SHA-256 incorrecto) y dos ejecuciones consecutivas del instalador.
No ejecutar este test en producción: altera temporalmente el manifiesto de prueba.
## SEO: dominio y sitemap

La regla `scripts/scaffold/canonical-host.htaccess`, incorporada a `.htaccess`
mediante Composer Scaffold, redirige `www.nelkano.com` con HTTP 301 a
`https://nelkano.com`, conservando ruta y parámetros. No redirige localhost.
El `.htaccess` utiliza la plantilla completa de Drupal 11.4.6; la copia local anterior
estaba truncada. No copiar el `.htaccess` antiguo después de Composer.

El sitemap incluye `/sistemas`, `/en/systems`, `/guia` y `/en/guide`, además de
las fichas visibles y páginas existentes. No emite `lastmod` hasta disponer de
fechas reales de actualización. Comprobación local: 39 URLs únicas, las cuatro
páginas responden 200, redirección www 301 sin bucle y sintaxis Apache/PHP válida.

Pendiente después del despliegue aprobado: verificar por HTTPS el 301 de www,
las URLs canónicas y `https://nelkano.com/sitemap.xml` en el servidor; enviar ese
sitemap desde Search Console (Indexación → Sitemaps) usando la propiedad de
nelkano.com y comprobar que Google lo procesa correctamente. El envío no se ha
realizado desde local y no garantiza indexación ni posiciones.