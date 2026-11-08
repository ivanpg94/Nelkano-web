# Nelkano-web

Despliegue vigente: [DEPLOY.md](DEPLOY.md). Sólo Git, Composer install y drush deploy, sin copias de BD ni las recetas históricas.

## Entrega 3.0.7: ejecutables y CSV subidos por Ivan

Los ejecutables se generan fuera del repositorio web. Ivan sube Android (APK),
Windows (EXE y MSI) y SteamOS (ZIP) desde Administración → Versiones, con
versión **3.0.7** y código **17**. El MSI va en Archivo de descarga y el EXE en
Instalador de Windows; el actualizador no consume el EXE. El ZIP de SteamOS
sirve tanto para la descarga inicial como para actualizar. SDK Studio sigue
oculto y no se publica ni se exige una nueva entrega suya.

El despliegue conserva las filas ES/EN, los canales y la visibilidad del SDK
mediante una transformación de configuración en memoria. No crea copias ni
snapshots y no sustituye los ejecutables subidos desde administración.

Ivan también sube los CSV desde Sistemas. No se importan ni publican durante el
despliegue. Los 18 CSV de `Nelkano-emulator/pruebas/` tienen versión 3.0.7 y
1.315 registros; esa carpeta está ignorada por Git. Su validación utiliza el
importador real, sin escribir catálogos en la BD. Se conservan alias de ROM,
observaciones de hasta 4.096 caracteres y la ficha de Switch permanece oculta.

Comprobaciones: `scripts/test-release-version-guard.php`,
`scripts/test-manual-release-import.php`, `scripts/test-app-updates.php` y
`scripts/check-release-csv-files.php`. Son comprobaciones locales; no acreditan
que los ejecutables o CSV de esta entrega estén publicados en producción.


Web de produccion de Nelkano alojada en Hostinger.

Consulta [la receta vigente de despliegue completo](DEPLOY.md) para publicar esta
entrega, respaldar producción, aplicar configuración y Book y activar Codex.
Las secciones de despliegues anteriores se conservan como contexto histórico.

## Entrega 3.0.5: despliegue limpio y eliminación de módulos

Referencia histórica. La eliminación de módulos conserva su confirmación y
autorización. Las antiguas recetas de respaldos, transición y mantenimiento ya
no son el procedimiento de despliegue. Usar únicamente [DEPLOY.md](DEPLOY.md).

## Catálogo de módulos: contrato público SDK — 01/11/2026

El validador admite los 63 permisos públicos actuales del SDK 0.2.0 para
cualquier ID y autor con firma válida, incluidos componentes JAR/DEX declarados.
No se necesitan excepciones por módulo. Reportes conserva su permiso privado
ligado al ID y firmante oficiales. Aceptar un paquete en la web no concede sus
permisos al ejecutarlo: el host mantiene el consentimiento de instalación.

Esta corrección no cambia base de datos ni configuración. Tras subir el commit,
en la instalación web habitual: `git pull --ff-only origin main` y
`./vendor/bin/drush --root=public_html cache:rebuild`.
No requiere ejecutar el despliegue completo de los instaladores 3.0.5.

51 comprobaciones PHP correctas, más un paquete real generado por el SDK con
los 63 permisos y otro autor. La prueba `node scripts/test-module-sdk-compat.cjs`
comprueba que la lista pública del catálogo coincide con la del SDK para evitar
que vuelvan a separarse. La URL pública de Drive también pasa `registry.inspect`.
Se mantienen los límites del catálogo: formato 1, 32 MiB por paquete y 8 MiB por
archivo. El formato 2 de motores externos grandes no forma parte de este cambio.
La preparación local no acredita despliegue en producción.

## Preparación local 3.0.5 (15) — 01/11/2026

Android ARM64, Windows x64 y SDK Studio Windows comparten versión. La entrega
incluye los cambios de privacidad y borrado de cuentas, licencias de terceros,
retirada de la función de traducción y corrección del instalador Android.
Sólo la última versión de cada aplicación permite descargar; se conservan las
notas de las anteriores. SteamOS sigue oculto. Preparación local y commit no
implican push ni despliegue. Instrucciones: [DEPLOY-3.0.5.md](DEPLOY-3.0.5.md).

La reparación conserva 3.0.5 (15), incorpora MSI Windows y ZIP Studio para sus
actualizadores y sustituye el APK por el corregido con la misma firma. Se verificó
la oferta/descarga contra Drupal local con los clientes de escritorio instalados.
En Redmi el instalador reconoce y completa la instalación del APK corregido,
con hash y antigüedad de instalación conservados. La 3.0.4 requiere la corrección por instalación
manual, sin desinstalar. Ver instrucciones y límites en el documento de despliegue.

El catálogo legal contiene 43 componentes y 3 documentos propios. Su fuente es
`../Nelkano-emulator/src/nelkano/legal`; `scripts/sync-legal-assets.py` sincroniza
los bytes exactos y sólo retira archivos gestionados previamente. La descarga
pública de fuentes contiene exclusivamente Java/GCC y sus recetas de terceros,
sin código privado de Nelkano. Se transporta en partes de Git y se reconstruye
con verificación SHA-256 durante el despliegue.

## Entrega local 3.0.3-beta (13) — 18/10/2026

Android, Windows y SDK Studio están preparados en el catálogo local con las notas
solicitadas. Esta entrega ofrece tres archivos: APK Android, EXE Windows y EXE
de Studio. Windows y Studio se instalan y actualizan manualmente con sus EXE;
no se distribuyen el MSI ni el ZIP de actualización de esta versión. Sus canales
automáticos no ofrecen paquetes; Android conserva su actualizador. Los hashes
están en manifest.json y el plan en `release-artifacts/release-3.0.3-beta.json`.
El despliegue reconstruye los instaladores desde fragmentos de Git normal.
antes del preflight. Receta local en
[`scripts/sdk-studio-release.md`](scripts/sdk-studio-release.md).
No se ha hecho push, despliegue remoto ni instalación en dispositivos.

## Estructura

Textos de producto revisados el 2026-10-12: portada, guía, privacidad, SEO y libro
SDK describen las capacidades actuales sin anunciar Drive ni traducción automática
integrada. Las notas y los archivos de descargas históricas se conservan.
La actualización `nelkano_home_update_11039` aplica los cambios editoriales a una
instalación existente desde `config/new-app-copy.json`, conservando ilustraciones
y secciones adicionales. Docker recuperado el 2026-10-12 reiniciando WSL, sin borrar contenedores ni
volúmenes. Aplicadas las actualizaciones 11039–11042 en Drupal local y exportada
la configuración. Libro SDK conciliado con la base, importado y exportado: 23
páginas sin cambios en la comprobación final. Web disponible en
http://localhost:8088; páginas públicas verificadas y 115 checks de fichas
correctos. No desplegado en producción.

- `public_html/`: docroot Drupal.
- `public_html/modules/custom/nelkano_home/`: modulo custom de landing, cuenta, API y descargas.
- `config/sync/`: configuracion Drupal versionada.
- `composer.json` y `composer.lock`: dependencias de Drupal.

No versionar `vendor/`, `public_html/core/`, modulos contrib, `settings.php`, ficheros de usuario ni dumps.

El contrato de consentimiento, exclusión de visitas propias y eventos de descarga está en
[Analítica de visitas y descargas](public_html/modules/custom/nelkano_home/README.analytics.md).

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

La configuración editorial vive en `config/sync`. Los usuarios, reportes,
backlog, CSV y archivos subidos permanecen en producción. La importación
conserva automáticamente el catálogo de ejecutables, sus canales y la
visibilidad del SDK.

El procedimiento es el de [DEPLOY.md](DEPLOY.md): actualizar Git, Composer
install y drush deploy. No requiere copias, exportaciones ni mantenimiento
manual. Las actualizaciones del módulo se ejecutan una vez mediante Drush.

## APK y publicación de versiones

Ivan sube los ejecutables desde el editor de Versiones: APK Android, EXE
Windows para instalación, MSI Windows para actualización y ZIP SteamOS. Usar
la misma versión y código en las aplicaciones publicadas. SDK Studio permanece
oculto. El despliegue de código no empaqueta ni sustituye esos archivos.

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
Los cambios del libro se aplican mediante hooks de drush deploy. La exportación
de configuración no contiene nodos. No ejecutar una receta Book adicional en
cada despliegue.

## Navegación y descubrimiento de módulos (2026-10-18)

La cabecera compartida muestra Sistemas, Módulos, Guía de uso y SDK. Mi perfil,
Streaming, Administración (según permisos) y Cerrar sesión quedan dentro del
menú del avatar. Sin sesión se muestra Entrar. «Mis módulos» queda fuera.
En móvil, el botón de menú abre los enlaces públicos; ambos desplegables
admiten teclado y Escape.

La home incorpora «Amplía Nelkano» entre sistemas y experiencia, con dos tarjetas:
Explorar módulos y Empezar con el SDK. Sus textos y visibilidad se editan en
Administración → Inicio → Amplía Nelkano. El SDK solo se enlaza si su portada
está publicada y el visitante tiene acceso.

`nelkano_home_update_11042()` añade los campos que faltan, conserva valores
personalizados (también vacíos), retira la fila duplicada de módulos/SDK en
Información y publica únicamente la portada española del libro por UUID.
Los capítulos privados y traducciones en borrador conservan su estado.
`config/sync/nelkano_home.settings.yml` y `content/books/nelkano-sdk.yml`
transportan el mismo resultado, para que `drush deploy` no revierta el update.
El despliegue habitual ejecuta updates, importación de configuración e importación
del Book; no hay pasos SQL manuales ni IDs numéricos específicos del entorno.

QA local: 108 checks de cabecera y 17 de la sección; migración real ensayada en
`nelkano_navigation_qa_20261018`, con conservación de contenido/configuración,
esquema válido y dos repeticiones sin cambios. Ensayada también la secuencia
`updatedb` → `config:import` → importación del Book. Navegador revisado en
escritorio, 390 y 320 px; visitantes anónimos ES/EN con enlaces HTTP 200.
No desplegado en producción.

## Catálogo de módulos comunitarios

El catálogo de `/modulos` está enlazado desde el menú público y desde la sección
«Amplía Nelkano» de la home.
Usa nodos con propietarios autenticados y versiones .nelkmod externas firmadas,
Search API y AJAX. La actualización 11036 habilita el módulo y la revisión horaria.
Consulta [catálogo, versiones, seguridad, cron y despliegue](public_html/modules/custom/nelkano_modules/README.md).
La API pública de consulta para la nueva app está en /api/nelkano/v1/modules.
Consulta [contrato de módulos en brain](../Nelkano-emulator/brain/API%20de%20modulos.md).

## Catálogo de descargas por plataforma

Versiones admite Android, Windows y SteamOS. SDK Studio sigue oculto; su
visibilidad se administra por separado y se conserva al desplegar. Las rutas
públicas y los actualizadores sólo ofrecen los archivos publicados del catálogo.
Los ejecutables y CSV se suben directamente desde administración.

## Despliegue de la entrega beta 3 preparada el 12-10-2026

Procedimiento retirado. Sus comandos antiguos se conservan únicamente en el
historial de Git. Para desplegar, seguir [DEPLOY.md](DEPLOY.md).

## Hotfix: completar la descarga Android después de drush deploy

Procedimiento retirado. Sus comandos antiguos se conservan únicamente en el
historial de Git. Para desplegar, seguir [DEPLOY.md](DEPLOY.md).

## Entrega Android 3.0.2-beta y despliegue

Procedimiento retirado. Sus comandos antiguos se conservan únicamente en el
historial de Git. Para desplegar, seguir [DEPLOY.md](DEPLOY.md).

## Licencias, privacidad y descargas históricas

La fuente de los avisos compartidos es `Nelkano-emulator/src/nelkano/legal`.
Actualizar la copia web con `python scripts/sync-legal-assets.py --source-root
../Nelkano-emulator/src/nelkano/legal`; el script conserva los bytes y genera
`legal/sync-manifest.json`. No sustituye binarios publicados ni sus hashes.
Los avisos se publican en `/creditos-licencias` y `/en/credits-licenses`,
enlazados desde el pie y las descargas.

El update 11043 publica los textos ES/EN de
`public_html/modules/custom/nelkano_home/config/legal-compliance-copy.json`
y oculta en el catálogo las versiones anteriores a 3.0.4. Conserva sus filas
y todos sus binarios. Las rutas de descarga y actualización respetan la
visibilidad editable. Los directorios públicos de archivos históricos
(`emulator/` y `public://nelkano-releases/`) quedan protegidos con `.htaccess`
para impedir que sus URLs físicas eludan el catálogo. En servidores que
no lean `.htaccess` debe configurarse una denegación equivalente antes de
publicar esta entrega.

Privacidad y Aviso legal siguen editándose desde Páginas editables y
exportándose a `nelkano_home.docs`. Los perfiles ajenos ya no devuelven el
correo: la búsqueda por correo requiere coincidencia exacta, mientras que
los nombres públicos conservan búsqueda parcial. El correo de la cuenta
propia se mantiene. Esta preparación se aplica únicamente al Drupal local;
la publicación en producción requiere el despliegue autorizado habitual.
