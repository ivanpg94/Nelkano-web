# Nelkano-web

Web de produccion de Nelkano alojada en Hostinger.

## Estructura

Textos de producto revisados el 2026-10-12: portada, guía, privacidad, SEO y libro
SDK describen las capacidades actuales sin anunciar Drive ni traducción automática
integrada. Las notas y los archivos de descargas históricas se conservan.
La actualización `nelkano_home_update_11039` aplica los cambios editoriales a una
instalación existente desde `config/new-app-copy.json`, conservando ilustraciones
y secciones adicionales. Docker recuperado el 2026-10-12 reiniciando WSL, sin borrar contenedores ni
volúmenes. Aplicadas las actualizaciones 11039–11041 en Drupal local y exportada
la configuración. Libro SDK conciliado con la base, importado y exportado: 23
páginas sin cambios en la comprobación final. Web disponible en
http://localhost:8088; páginas públicas verificadas y 115 checks de fichas
correctos. No desplegado en producción.

- `public_html/`: docroot Drupal.
- `public_html/modules/custom/nelkano_home/`: modulo custom de landing, cuenta, API y descargas.
- `config/sync/`: configuracion Drupal versionada.
- `composer.json` y `composer.lock`: dependencias de Drupal.

No versionar `vendor/`, `public_html/core/`, modulos contrib, `settings.php`, ficheros de usuario ni dumps.

## Reportes del emulador

La implementación activa del módulo está en `public_html/modules/custom/nelkano_home/`
(montada por Docker; su código está gestionado por Git).
Consulta [API de reportes para PC](public_html/modules/custom/nelkano_home/README.report-api.md)
para activar campos, emitir/revocar credenciales y consultar/descargar reportes.

## Entorno local Docker — Drupal 11.4.9

Actualización del 2026-10-11: core 11.4.9, Book 3.0.8 y CTools 4.1.1, con sus
dependencias de Symfony/Twig. Composer audit no encuentra avisos de seguridad
ni paquetes abandonados; todos los paquetes Drupal del lock están al día.
Las versiones y referencias de Docker coinciden con el lock de producción.

Ejecutado el update oficial book_update_103011 (update_child_aliases: false),
cuyo ajuste se conserva en config/sync/book.settings.yml. No se añade un update
vacío de Nelkano: la BD se actualiza mediante los update_N de las dependencias.
No quedan updates pendientes. Se verificaron UUID, recuentos y hashes de 149
tablas persistentes antes/después y 281 archivos propios tras Composer.

Copias SQL, archivos públicos/privados y manifiestos fuera del docroot, en
%LOCALAPPDATA%/Nelkano/backups/web-20261011-171730. Validación: 383 checks de APIs,
Search API/sistemas, privacidad, backlog y Book/SDK; Chrome con AJAX,
paginación, formularios, imágenes y borrado de HU en escritorio/móvil.
Las pruebas de Book usan la base QA aislada con rollback. El informe nativo de
Drupal reconoce core y contrib al día. Producción: despliegue pendiente.

Historial del entorno:

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
Docker usa su propio `/opt/drupal/composer.json`, con docroot `web/` y módulos
propios montados desde Git en `web/modules/custom/`. El antiguo paquete
`nelkano/nelkano_home` ya no forma parte de las dependencias Composer. Las versiones
y referencias son las del lock versionado; cambian las rutas de instalación y
el hash local del manifiesto. No copiar directamente el manifiesto de producción sobre el runtime
Docker sin adaptar esas rutas. `symfony/runtime` está autorizado como plugin
Composer requerido por esta versión de Drupal.

El perfil de usuario usa la presentación compartida de Sistemas (`redesign.css`)
con cabecera degradada, contenedor de 1280 px, tarjetas y controles consistentes.
`profile.css` adapta únicamente el perfil; el login y los otros formularios de
cuenta mantienen sus estilos. Comprobado en Chrome local: Perfil/Amigos con
carga AJAX, formularios ES/EN, dos columnas en escritorio y una a 390 px sin
desbordamiento horizontal. Captura: outputs/profile-restyled-desktop.png.

Aviso legal, Privacidad y cookies, Seguridad y privacidad, Contacto y Streaming
comparten también la presentación de Sistemas. Las páginas informativas usan
`nelkano-editorial-content.html.twig` con índice y enlaces a sus secciones;
`site-pages.css` adapta Contacto y Streaming conservando los campos y hooks del
visor. Verificadas las ocho rutas informativas ES/EN (HTTP 200), Contacto y
Streaming autenticado en Chrome, y las cinco páginas a 390 px sin desbordamiento.
El visor se revisó en espera, sin certificar una emisión real. Captura:
outputs/site-pages-restyled.png.

## Sesiones y roles de la app

La app usa la API de sesiones v2 (access token corto, refresh con rotación, permisos
comprobados en el servidor y capabilities firmadas con Ed25519). Consulta
[Sesiones y roles de la app](public_html/modules/custom/nelkano_home/README.app-auth.md)
para los endpoints, los permisos por rol y la gestión de la clave de firma.

## Fichas de sistemas

Las fichas públicas de cada core se editan en **Páginas editables → Sistemas**
(`/admin/nelkano/sistemas`), como sección independiente de Home, y se abren desde
«Más información» en Estado actual. Consulta [Fichas de sistemas: edición,
activación y pruebas](public_html/modules/custom/nelkano_home/README.systems.md).

## Backlog de HU

El tablero está en /admin/nelkano/backlog, debajo de Reportes de errores.
El buscador usa Search API y AJAX, integrado con el selector Sprint. La actualización
11035 crea el índice y conserva los datos existentes.
Consulta [Backlog, búsqueda, privacidad y migraciones](public_html/modules/custom/nelkano_home/README.backlog.md) para activación, permisos y pruebas.

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
El `.htaccess` utiliza la plantilla completa de Drupal 11.4.9; la copia local anterior
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
## SDK de Nelkano: libro versionado como contenido

El SDK 0.2.0 está documentado en Drupal Book: `/es/sdk-de-nelkano`.

En Páginas editables → SDK, la casilla Publicado de la portada controla su
disponibilidad pública y el enlace SDK del menú, también para administradores.
El estado corresponde al idioma editado; una traducción despublicada no ofrece
un enlace de respaldo a otro idioma. Se mantiene la edición y vista previa
administrativa. QA local: 21 checks de presentación y 20 de administración,
incluidos despublicación, republicación y traducciones; fixtures con rollback.
Book 3.0.8 se instala mediante el lock Composer. Las páginas y su jerarquía
viven en la base de datos y se transportan expresamente en
`content/books/nelkano-sdk.yml`; `config:export` no exporta esas páginas.

El módulo `book_import_export` proporciona `book:export UUID archivo.yml` y
`book:import archivo.yml --dry-run`, actualiza por UUID y crea revisiones sin
borrados implícitos. Consulta [flujo local → Git → producción, esquema y QA](public_html/modules/custom/book_import_export/README.md).
El despliegue del libro en producción queda pendiente de aprobación; la
importación de contenido es una fase explícita posterior a la configuración.

## Catálogo de módulos comunitarios

El catálogo está preparado en /modulos, sin enlaces desde la navegación pública.
Usa nodos con propietarios autenticados y versiones .nelkmod externas firmadas,
Search API y AJAX. La actualización 11036 habilita el módulo y la revisión horaria.
Consulta [catálogo, versiones, seguridad, cron y despliegue](public_html/modules/custom/nelkano_modules/README.md).
La API pública de consulta para la nueva app está en /api/nelkano/v1/modules.
Consulta [contrato de módulos en brain](../Nelkano-emulator/brain/API%20de%20modulos.md).

## Catálogo de descargas por plataforma

La página y administración de Versiones admiten Android (APK), Windows (MSI), SteamOS (ZIP) y SDK Studio Windows (ZIP). La portada ofrece un selector de las plataformas publicadas ; el botón SDK pertenece a Módulos. Estado solicitado: Android3.0.0-beta visible; Windows, SteamOS y SDK1.0.0-beta ocultos. Mostrar versión controla también el actualizador cuando la fila tiene código positivo, y se comparte entre los dos idiomas. Los archivos nuevos son privados y sus rutas rechazan descargas despublicadas. El panel técnico app-updates queda como canal legado, con prioridad del catálogo nuevo.

`release-artifacts/manifest.json` y `scripts/install-release-assets.php` transportan y verifican todos los archivos, incluidos los ocultos. `config/multiplatform-releases.json` documenta la entrega inicial; `install-platform-catalog.php` solo inicializa esa entrega cuando se solicita explícitamente, no debe ejecutarse para sobrescribir ediciones posteriores del admin. Las publicaciones vigentes se conservan en config/sync/nelkano_home.docs.yml. Desarrollo y verificación locales; pendiente de despliegue en producción.

Diseño vigente (2026-10-12): la Home usa un botón Instalar en Android/Windows/SteamOS con detección del navegador e iconos de las plataformas publicadas para cambiar manualmente. Linux se ofrece como SteamOS; el navegador no identifica de forma fiable cada distribución. SDK queda exclusivamente en Módulos. Versiones usa pestañas Android/Windows/SteamOS/SDK y su admin divide Contenido en esos cuatro grupos. Solo Android permanece publicado. El APK final contiene solo los cinco módulos obligatorios, sin el host/modelos del asistente, y ocupa59,6MiB. Límites de subida512MiB y POST520MiB autorizados por Ivan y aplicados en Apache local; public_html/.user.ini transporta esa configuración para PHP compatible.

Entrega Android preparada para producción: APK Release3.0.0-beta/código10,52,8MiB, firmado con la nueva identidad estable autorizada para Nelkano. Todos los cores Android recompilados y solo los cinco módulos obligatorios. release-artifacts y manifest.json usan exactamente el archivo de outputs/android-production del emulador. No desplegado en producción.

## Despliegue de la entrega beta 3 preparada el 12-10-2026

Esta entrega transporta código propio, composer.lock, configuración completa,
las 23 páginas del libro SDK y los seis artefactos de descarga. El APK Android
3.0.0-beta/código 10 es Release ARM64, 55.341.057 bytes, con SHA-256
`f2d0a6200d9e7dd074d6b9a880cb858584c1a4b96e66c383ca4080b4cbcd2284`.
Solo Android queda publicado; Windows, SteamOS y SDK Studio permanecen ocultos.
La portada del libro SDK permanece despublicada y sin enlace en el menú.

Los dos archivos de Windows y SteamOS se versionan con Git LFS. Antes de ejecutar
el despliegue de esas plataformas hay que materializar sus binarios con `git lfs pull`. Si Hostinger
no dispone de Git LFS, sube por SFTP los archivos reales de `release-artifacts/`
o extrae el paquete local `outputs/deploy/release-artifacts.zip` en la raíz de
la aplicación. Los punteros de LFS no son instaladores; el preflight los rechaza.
Las plataformas despublicadas sin binarios verificados se omiten y no bloquean
la entrega Android. Los tres APK publicados se transportan como archivos normales
en Git; no necesitan Git LFS ni descomprimir un paquete.
La carpeta `release-artifacts/` debe quedar fuera del docroot `public_html/`.

1. En el PC, publicar el commit preparado: `git push origin main`.
2. Por SSH, entrar en la raíz existente de la aplicación (la carpeta que contiene
   composer.json y public_html; no reemplazar settings.php). Antes de actualizar,
   guardar una copia de la BD, configuración y archivos públicos/privados fuera
   del docroot. Por ejemplo, desde esa raíz:

   ```bash
   backup_dir="$HOME/nelkano-backups/$(date +%Y%m%d-%H%M%S)"
   mkdir -p "$backup_dir"
   ./vendor/bin/drush --root=public_html sql:dump --gzip --result-file="$backup_dir/database.sql"
   ./vendor/bin/drush --root=public_html config:export --destination="$backup_dir/config" --yes
   git rev-parse HEAD > "$backup_dir/revision.txt"
   ```

   Guardar también los directorios reales de archivos públicos y privados
   indicados por settings.php o mediante el backup de archivos de Hostinger.
   Revisar cambios editoriales hechos en producción que todavía no estén en Git.
3. Activar mantenimiento, actualizar el checkout y obtener los binarios:

   ```bash
   ./vendor/bin/drush --root=public_html state:set system.maintenance_mode 1
   ./vendor/bin/drush --root=public_html cache:rebuild
   git pull --ff-only origin main
   # Opcional: solo para preparar también las plataformas con Git LFS.
   # git lfs pull
   ```

   Si usas un checkout separado del sitio, sigue el mismo proceso allí y pasa
   APP_DIR con la ruta existente de la aplicación al script. Conserva la BD,
   settings.php y directorios de archivos actuales. private:// debe apuntar a
   un directorio fuera de public_html.
4. Ejecutar la receta completa:

   ```bash
   bash scripts/deploy-hostinger.sh
   ```

   Comprueba primero los archivos publicados y el directorio privado, hace
   composer install desde el lock, verifica los hashes y registra
   los archivos, ejecuta drush deploy e importa el SDK por UUID con simulación
   previa. No ejecutar install-platform-catalog.php ni seeds/demo/tests en el
   servidor. No restaurar una base Docker ni cambiar el UUID del sitio. Si el
   preflight detecta una descarga existente con otro hash, detenerse y revisar
   esa versión; no borrar el archivo para eludir la comprobación.
5. Si termina correctamente, desactivar mantenimiento y comprobar HTTPS:

   ```bash
   ./vendor/bin/drush --root=public_html state:set system.maintenance_mode 0
   ./vendor/bin/drush --root=public_html cache:rebuild
   ./vendor/bin/drush --root=public_html updatedb:status
   ./vendor/bin/drush --root=public_html config:status
   ```

   Revisar /versiones, /en/releases, /modulos con y sin sesión, perfil, Contacto,
   páginas legales y Streaming. /es/sdk-de-nelkano debe rechazar visitantes y el
   enlace SDK no debe aparecer. El API /api/nelkano/v1/app/releases/android-arm64
   debe devolver beta 3, código 10, tamaño y hash anteriores; descargar el APK y
   verificar su SHA-256. Los otros tres canales deben permanecer sin oferta.
   Comprobar además el 301 de www.nelkano.com, canonical y sitemap.xml.

Validación local de la entrega: firma APK correcta y no debuggable; seis archivos
verificados por el instalador; descarga HTTP del APK con hash idéntico al original;
87 checks de plataformas; config:status sin diferencias; importación simulada del
SDK con 23 unchanged. No se ha desplegado ni hecho push a producción.
## Hotfix: completar la descarga Android después de drush deploy

`drush deploy` aplica updates y configuración, pero no copia ni registra los
artefactos ni importa el libro SDK. Puede dejar la beta 3 en Versiones y el API
con latest=null si falta el APK en private://nelkano-releases.

El hotfix se entrega únicamente como código y documentación. No hace falta ZIP
para Android: release-artifacts/Nelkano 3.0.0-beta.apk ya es un archivo normal
versionado. Los punteros/archivos ausentes de las plataformas despublicadas se
omiten con un aviso; cuando se publiquen se exige su binario y checksum correctos.
Scaffold conserva .gitattributes y el lock mantiene las mismas dependencias.

En el servidor, después de publicar y obtener este commit:

1. Si private:// no está configurado, crea `private` en la raíz del proyecto y
   añade en el PHP de `public_html/sites/default/settings.php` (antes de cualquier
   cierre de PHP y sin sustituir el resto del archivo):

   ```php
   $settings['file_private_path'] = dirname(DRUPAL_ROOT) . '/private';
   ```

   Si ya hay un directorio privado configurado, conservarlo. Debe estar fuera de
   public_html y ser escribible por PHP. La línea usa la ruta del servidor.
2. Desde `~/domains/nelkano.com`:

   ```bash
   mkdir -p private
   ./vendor/bin/drush --root=public_html cache:rebuild
   bash scripts/deploy-hostinger.sh
   ```

   El script instala los APK publicados e importa el SDK por UUID conservando
   su portada despublicada. Solo modifica artefactos con checksums verificados;
   nunca sobrescribe una descarga existente con contenido distinto.
3. Comprobar:

   ```bash
   ./vendor/bin/drush --root=public_html updatedb:status
   ./vendor/bin/drush --root=public_html config:status
   curl -fsS https://nelkano.com/api/nelkano/v1/app/releases/android-arm64
   ```

   latest debe contener3.0.0-beta, código10,55.341.057 bytes y SHA-256
   f2d0a6200d9e7dd074d6b9a880cb858584c1a4b96e66c383ca4080b4cbcd2284.
   Las notas ES/EN deben conservar los siete puntos elegidos por Ivan. El SDK
   sigue oculto. Si se activó mantenimiento para esta operación, retirarlo solo
   tras completar el script y reconstruir la caché.

Verificación local del hotfix: Composer JSON/lock consistentes, sintaxis Bash,
instalador completo de seis archivos, preflight con solo tres APK y plataformas
ocultas ausentes/puntero LFS, y rechazo de un APK publicado corrupto. Consulta
pública de producción durante la revisión: Versiones y Módulos200, SDK404,
actualizador200 con latest=null. Esto verifica el problema de disponibilidad,
no certifica una emisión real, formularios autenticados ni pruebas de la app.
## Entrega Android 3.0.1-beta y prueba de actualización

Entrega preparada localmente: **3.0.1-beta, código 11**, paquete
`com.ivanp.nelkano.modular`, Release ARM64 no depurable. Se recompilaron todos
los sistemas Android desde fuentes: nueve bibliotecas JNI y los cores Java;
solo se empaquetan los cinco módulos obligatorios. Tamaño: **55.341.066 bytes**.
SHA-256: `5f1c47479bc0a33d1eccc41237fb83ea6f72270efa14caf6e5af39032f3ad6df`.
Certificado de producción SHA-256:
`57780bcd54cdddceeb9ab5445f9b256ec64ad05ec153ef249b78941a8ec4adc5`.
El manifiesto y hashes de bibliotecas están en `release-artifacts/android-3.0.1-beta.json`.
Las descargas anteriores se conservan. Windows, SteamOS y SDK continúan ocultos
con versión 1.0.0-beta. No se ha hecho push, desplegado ni instalado esta versión
sobre los móviles: el Moto conserva 3.0.0-beta de producción para el ensayo.

Para desplegar, seguir los cinco pasos de «Despliegue de la entrega beta 3»
indicados arriba: publicar el commit, guardar respaldo SQL/configuración y
archivos, activar mantenimiento, actualizar el checkout y ejecutar
`bash scripts/deploy-hostinger.sh`. El APK nuevo es un archivo Git normal de
52,8 MiB y no necesita LFS. Si solo se ejecutó `drush deploy`, completar también
`drush --root=public_html php:script scripts/install-release-assets.php`;
la configuración por sí sola no transporta ni registra la APK.
La receta compara correctamente APP_DIR y REPO_DIR y conserva settings.php,
archivos de usuarios y directorio privado existente. No ejecutar la herramienta
local `scripts/register-android-release.php` en producción.

Al terminar el despliegue y desactivar mantenimiento, comprobar:

```bash
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/android-arm64
curl --fail --location --output /tmp/Nelkano-3.0.1-beta.apk \
  https://nelkano.com/api/nelkano/v1/app/releases/android-arm64/11/download
printf '%s  %s\n' \
  5f1c47479bc0a33d1eccc41237fb83ea6f72270efa14caf6e5af39032f3ad6df \
  /tmp/Nelkano-3.0.1-beta.apk | sha256sum --check
```

El catálogo debe indicar 3.0.1-beta, version_code 11, 55341066 bytes y el hash
anterior. Revisar `/versiones` y `/en/releases`: 3.0.1-beta debe ser la última
Android, manteniendo el historial. Los otros tres canales deben dar latest=null.
En administración → Versiones, la fila Android nueva permite editar notas y
ocultar/publicar la oferta igual que las anteriores.

Para la prueba real del aviso, usar **Moto G15**, cuya 3.0.0-beta está firmada con
esta misma identidad de producción. Con Internet y sin una partida abierta,
abrir Nelkano y entrar en Ajustes → General → Actualización de Nelkano → Comprobar.
La comprobación manual evita la espera automática de seis horas; también se
comprueba en un arranque nuevo de la aplicación. Debe ofrecer 3.0.1-beta.
Pulsar Descargar, esperar la verificación, pulsar Instalar, autorizar Nelkano como
fuente si Android lo solicita y confirmar la actualización en el instalador.
Confirmar versión 3.0.1-beta y que permanecen los 1000 juegos y guardados.
Una nueva comprobación debe indicar que ya está actualizada.
**Redmi tiene una firma debug diferente**: el actualizador rechaza esta APK de
producción; no desinstalar ni borrar datos para forzar el ensayo.

Verificado localmente: 10 tests JVM del actualizador, 40 checks de pestañas,
8 checks de descargas, QA de los cuatro canales, catálogo real build 11,
canales ocultos, preflight de siete artefactos y descarga HTTP con SHA-256
idéntico. La aparición e instalación del aviso en el Moto queda para después
del despliegue HTTPS; no se afirma realizada en esta preparación.
