# Catálogo de módulos

Implementación: public_html/modules/custom/nelkano_modules. Página pública
/modulos, sin entradas nuevas en menús, home, pie, perfil ni sitemap. El botón
para publicar y Mis módulos aparecen únicamente dentro del catálogo.

Las páginas de Módulos conservan su navegación pública también para usuarios
con acceso a la barra administrativa de Drupal. ModulePageHooks retira la barra
y su clase de carga después de los hooks de Toolbar, conservando nm-catalog.
Verificado en Chrome con admin: catálogo y formulario propio sin barra Drupal;
/admin/content mantiene la barra. Los permisos y acciones de autor no cambian.
Captura local: outputs/modules-public-admin.png.

## Contenido y permisos

Cada ficha es un nodo nelkano_module, propiedad del usuario que la crea.
El formulario permite una imagen opcional PNG/JPG/WebP de hasta 2 MiB y
4096 × 4096 píxeles, con vista previa AJAX, sustitución y retirada. Se guarda
como archivo gestionado público de Drupal en nelkano-modules/images; el
campo de imagen nativo conserva uso y revisiones. Las tarjetas usan una
miniatura 16:9 y la ficha muestra la imagen completa, sin recortarla.
Solo se pueden adjuntar imágenes propias o conservar la ya asociada a la
ficha; se comprueban formato real, extensión, tamaño y dimensiones. Esta
imagen sí se aloja en la web para módulos públicos. Los módulos Tester no admiten imágenes públicas y alojan su paquete privado en el servidor.
Cada versión es un nodo nelkano_module_release relacionado con su ficha,
con el mismo propietario, siempre despublicado y sin acceso público directo.
La página del módulo muestra los metadatos públicos y las descargas de versiones.

El permiso create nelkano modules se añade al rol authenticated mediante la
instalación/update. Los formularios propios comprueban sesión, propietario,
CSRF y revisiones; los administradores pueden editar cualquier ficha con
administer nelkano modules. El editor genérico de nodos no permite a un usuario
normal alterar los metadatos verificados. No se concede bypass node access ni
edición de contenido ajeno. Los propietarios pueden retirar su ficha de la
publicación y seguir viéndola desde Mis módulos.

La cuenta propietaria de la ficha y la clave que firma el paquete son identidades
distintas: no hay un sello de identidad verificada ni OAuth con GitHub. El ID
del módulo es único en el catálogo; las versiones conservan su ID y huella de
firma. Una versión existente conserva también su hash: reparar su enlace
requiere exactamente el mismo archivo. Las versiones anteriores no se borran.

## Descargas externas

La primera versión exige un enlace HTTPS público y directo cuyo path termine
en .nelkmod; admite query strings y redirecciones HTTPS seguras. El enlace al
repositorio del código fuente es opcional y solo se valida como URL HTTPS.

La creación/alta/reparación comprueba el archivo completo antes de guardar.
El verificador implementa el formato ZIP 1 del SDK 0.1.0/0.2.0: rutas y tamaños,
inventario de archivos, CRC, manifiesto, hooks/dependencias/skins, hashes y firma
Ed25519 sobre el inventario canónico. Admite JS/JSON/SVG/PNG/TXT/TTF. Límites:
32 MiB comprimidos/descomprimidos, 128 entradas, 8 MiB por archivo y 256 KiB
por documento de metadatos. No ejecuta el JavaScript ni extrae archivos.

La capacidad reservada error-reports.send sólo se admite tras verificar Ed25519,
con ID nelkano.error-reports y la huella oficial fijada por el SDK. Otro ID,
otro firmante o una firma inválida siguen rechazados. No se añade al conjunto
público de capacidades ni se almacena código o claves privadas en este módulo.

El transporte usa cURL con TLS verificado, solo HTTPS público, IP validada
fijada por cada conexión, límite de bytes/tiempo/redirecciones y sin proxy.
Rechaza direcciones privadas/reservadas/multicast, credenciales embebidas,
puertos alternativos y redirecciones inseguras. Guarda metadatos y URL, nunca
el paquete. En módulos públicos externos el visitante descarga directamente desde el alojamiento del autor.
Requiere PHP cURL, zlib y sodium, comprobados en requisitos del módulo.

## Disponibilidad, búsqueda y cron

Search API ya instalado, con servidor e índice nelkano_modules independientes.
Busca nombre, descripción e ID; filtro de plataformas y paginación de 12.
La búsqueda, filtros y paginación usan AJAX de Drupal y mantienen fallback GET.
Solo devuelve fichas publicadas con alguna versión verificada disponible;
recomprueba el nodo y sus versiones para excluir estados obsoletos del índice.

Las descargas ausentes/inaccesibles (4xx, excepto 408/425/429), alteradas o
inválidas quedan no disponibles. Timeouts, 429 y 5xx conservan la última
disponibilidad y el error para su propietario. HTTP 304 mantiene la verificación
anterior; ETag/Last-Modified evitan descargar archivos sin cambios. Si una
versión se rompe, las anteriores válidas siguen descargables. Sin ninguna
válida, la ficha desaparece del buscador, conservando nodo, revisiones y
versiones. Una descarga idéntica restaurada recupera disponibilidad.

El hook cron encola las versiones cuya última revisión tiene al menos una hora.
El worker nelkano_module_links procesa trabajos en lotes con un presupuesto
de 120 segundos; las marcas de cola evitan duplicados recientes y permiten
recuperar trabajos detenidos. Una reparación concurrente no puede ser
sobrescrita por una comprobación de la revisión anterior.

El update establece automated_cron en 3600 segundos, si está instalado.
Esto necesita tráfico para dispararse. Para una hora real sin tráfico, programar
en el hosting un trabajo horario con esta receta, adaptando la ruta:

    0 * * * * cd /RUTA/nelkano-web && ./vendor/bin/drush --root=public_html php:script scripts/check-module-links.php

El script usa la misma cola, un presupuesto de cuatro minutos y actualiza
el índice. Si hay una cola grande, se puede ejecutar más frecuentemente:
solo encola revisiones vencidas. No se ha programado ni ejecutado en producción.

La API pública v1 está implementada en /api/nelkano/v1/modules para la nueva app.
Contrato canónico en [brain/API de modulos.md del repositorio Nelkano-emulator](../../../../../Nelkano-emulator/brain/API%20de%20modulos.md). Las versiones
guardan URL, SHA-256, firma, SDK, plataformas y permisos para ese contrato.

## Despliegue y conservación

La actualización nelkano_home_update_11036 habilita este módulo. El update 11037 añade el campo opcional de imagen y sus estilos sin guardar ni borrar nodos existentes. Su instalador
crea solo los tipos, campos, configuración del índice/servidor y permiso ausentes.
No migra, guarda ni elimina usuarios, reportes, HU, libros ni otros nodos existentes.
La configuración está versionada en config/sync, con UUID estables, y las recetas
del índice se mantienen en config/catalog para actualizar antes de cim.
No reutilizar ni borrar build como fuente: toda la implementación reside aquí.

Desplegar el repositorio completo y ejecutar el flujo habitual Composer install
y drush deploy (updatedb antes de configuración). No usar site:install.
Conservar la copia SQL y archivos como describe el README raíz. Los módulos públicos externos no necesitan subir su .nelkmod; los paquetes Tester requieren el almacenamiento privado de Drupal fuera del docroot.

## Verificación local

Pruebas exclusivamente locales, nunca en producción:

    docker compose exec -T drupal php web/modules/custom/nelkano_modules/tests/package-unit.php
    docker compose exec -T -e NELKANO_LOCAL_MODULE_CATALOG_TEST=1 drupal vendor/bin/drush php:script web/modules/custom/nelkano_modules/tests/catalog-smoke.php
    docker compose exec -T -u www-data -e NELKANO_LOCAL_MODULE_CATALOG_TEST=1 drupal vendor/bin/drush php:script web/modules/custom/nelkano_modules/tests/image-smoke.php
    node scripts/test-module-sdk-compat.cjs
    node scripts/test-module-catalog-browser.cjs

La prueba de integración exige la BD Docker nelkano_drupal, usa transporte
simulado y solo elimina sus nodos/cuentas sintéticos. Comprueba los hashes de
todas las tablas de contenido durante la repetición del update/setup, propiedad,
permisos, versiones, búsqueda, retirada/restauración y cola horaria. El test
de paquetes cubre firmas, archivos alterados, ZIP inseguro, destinos y
redirecciones. La compatibilidad SDK genera un paquete con el SDK real del
repositorio hermano y compara hash y huella con el verificador PHP.

Los scripts de navegador usan Playwright resoluble con NODE_PATH y Chrome
instalado; NELKANO_TEST_BROWSER permite otro canal. Sus datos se limpian al
terminar. Tras una interrupción, ejecutar tests/browser-fixture.php mediante
Drush con NELKANO_LOCAL_MODULE_CATALOG_TEST=1 y
NELKANO_MODULE_BROWSER_ACTION=cleanup. Los IDs de entidades consumidos no
se reutilizan. Las capturas son datos sintéticos, no módulos publicados.

Validación local 11-10-2026: 33 comprobaciones de paquetes/transporte,
49 de catálogo/versiones/permisos/cron, compatibilidad con el SDK real y flujo
en Chrome (AJAX, paginación, escritorio/móvil, edición, mensajes y CSRF).
El transporte HTTP externo se prueba con sustitutos; no se ha publicado una
release real en GitHub para las pruebas. No se ha desplegado en producción.
Imagen opcional: 12 comprobaciones de validación/propiedad/conservación y flujo
real en Chrome de subida, vista previa y retirada AJAX; miniaturas públicas y
ficha en escritorio/móvil. Update 11037 aplicado solamente en Docker local.

## Demostración persistente del buscador (solo local)

scripts/module-demo.json define 16 fichas con propietarios ficticios, 14 portadas,
plataformas Android/escritorio y versiones anteriores. Son ejemplos inocuos:
los paquetes firmados se descargan de verdad, pero su JavaScript no implementa
las funciones anunciadas en las fichas. No son módulos de producto.

Para crear o reparar las fichas registradas por la receta, sin duplicarlas:

    docker compose exec -T -u www-data -e NELKANO_LOCAL_MODULE_DEMO=1 drupal vendor/bin/drush php:script scripts/seed-module-demo.php

La receta exige CLI, opt-in y la base Docker nelkano_drupal en host database.
Antes de escribir verifica TODOS los ID, propietarios de demo, huellas de autor,
tamaños y hashes de los paquetes reconstruidos. Conserva nombres, imágenes,
propietarios e identidades. No adopta fichas o cuentas ajenas y nunca cambia
los roles persistentes: el permiso de administración del verificador se aplica
sólo a una UserSession acotada con el mismo UID de cada autor ficticio.

Las versiones descargables se guardan como archivos gestionados privados y se
sirven mediante el controlador oficial con audiencia public/download_auth none.
No hay URLs ficticias marcadas disponibles ni transporte simulado. La firma y
los hashes permanecen idénticos a los originales. El cron comprueba esos bytes
locales; no retira los ejemplos por buscar repositorios inexistentes.

La versión retirada editor-de-mandos 1.0.0 se conserva sin paquete asociado,
sin cambiar su estado, hash ni fecha de comprobación. Al reconstruir desde cero
se omite esa versión intencionalmente retirada si no existe; no se inventa una
descarga disponible. Cada una de las 16 fichas mantiene al menos una versión válida.
Los archivos ya asociados se reutilizan al repetir; las imágenes existentes
no necesitan renderizarse ni copiarse de nuevo. Para una creación nueva sin
ilustración guardada se requieren sus PNG en /tmp/nelkano-module-demo, preparadas
con la receta permanente scripts/render-module-demo.cjs; las salidas son desechables.

Los nodos nuevos se crean en español y la migración de idioma usa
ModuleRegistry::setContentLanguage con revisión esperada para ficha y versiones,
sin alterar firmas, tamaños, fechas de comprobación ni disponibilidad. El índice
se reconstruye al terminar. La identidad de firma de estas muestras permanece
sólo en el estado local nelkano_modules_demo_content; no se imprime ni se exporta.
No ejecutar esta receta como actualización o despliegue en producción.

### Sistemas compatibles

La ficha permite marcar varios sistemas emulados mediante casillas nativas de Drupal. Campo `field_module_systems`, `list_string`, cardinalidad ilimitada; IDs compartidos con `GameSystems` de la nueva app. Es metadata declarada por el propietario, distinta de las plataformas Android/Windows/Linux verificadas en los paquetes. La selección se muestra en tarjetas y ficha. Sin selección, sigue visible sin filtro y no coincide con un sistema concreto.

El catálogo combina `q`, `plataforma` y `sistema` mediante Search API y AJAX, incluidos limpieza, cambio de filtros y paginación. Se contrastan los sistemas actuales del nodo para descartar datos obsoletos del índice. La API pública añade `systems` y el filtro `system` de catálogo; contrato canónico en `brain/API de modulos.md` del repositorio Nelkano-emulator.

Update `nelkano_home_update_11038`: añade el campo y el campo de índice, conserva configuración y contenido, e indexa en lotes de 50. No asigna compatibilidad a nodos existentes. El seed local añade selecciones a los ejemplos sin duplicarlos; nunca se ejecuta al desplegar. Verificación: `NELKANO_LOCAL_MODULE_CATALOG_TEST=1 vendor/bin/drush php:script web/modules/custom/nelkano_modules/tests/systems-smoke.php` y `scripts/test-module-catalog-browser.cjs`.


## Módulos exclusivos Tester y paquetes alojados

La ficha añade field_module_access: public o tester; los valores vacíos de
fichas anteriores siguen siendo públicos. El acceso se fija al crearla, para
no convertir un enlace o imagen antes públicos en una supuesta distribución
privada. Publicar, subir, actualizar, editar o retirar módulos exclusivos requiere
una cuenta autenticada con el permiso administer nelkano modules. Ese administrador
puede gestionarlos y consultarlos sin tener el rol Tester. Tester es consumidor:
puede consultar y descargar exclusivos, pero no publicarlos ni gestionarlos, aunque
figure como propietario. Los autores de módulos públicos conservan sus permisos.
No se asignan roles ni permisos nuevos a usuarios existentes.

El formulario mantiene los enlaces externos para públicos. Sólo el administrador
puede subir un .nelkmod exclusivo firmado hasta 32 MiB mediante managed_file, bajo
private://nelkano-modules/packages. La ficha Tester no incluye repositorio; el formulario oculta ese campo y el servidor rechaza un valor no vacío. Los públicos conservan su repositorio opcional. La imagen pública se deshabilita para esta audiencia.
Se verifica el mismo ZIP, inventario, Ed25519, identidad, tamaño y SHA-256;
versiones y reparaciones conservan las reglas existentes. El archivo debe
pertenecer al autor de la subida y no estar vinculado a otra versión.
field_release_package guarda su referencia gestionada; nunca una URL pública.

La API v1 conserva sus rutas y filtros. Acepta Authorization: Bearer con el
access token nkat_ de sesiones v2; no emite credenciales nuevas ni admite
tokens en la URL. Sin cabecera utiliza la sesión web o el acceso anónimo.
Un Bearer inválido/caducado produce 401 invalid_token, sin fallback a cookies.
Se consulta la cuenta activa y sus roles en cada petición. El catálogo Tester
y el administrativo incluyen públicos y Tester; los demás sólo públicos. Ficha, versiones y descarga
no autorizadas responden 404. No se devuelve UID ni email del propietario.

El resumen añade access: public|tester y cada release download_auth: none|session.
Un paquete alojado usa exactamente
/api/nelkano/v1/modules/{id}/versions/{version}/download, GET/HEAD binario sin
redirección. download_auth session exige Bearer o cookie válidos de Tester o de una cuenta con permiso de administración;
none es público. El transporte de la app sólo debe enviar Bearer al catálogo
y a esta ruta exacta del mismo origen, nunca a imágenes ni repositorios externos.
Los paquetes públicos alojados se admiten internamente para muestras locales;
el formulario normal conserva el enlace externo. Su archivo también reside en
private://, y el controlador decide su audiencia. /system/files y las rutas
genéricas de archivo no permiten descargar ninguno de estos paquetes. El permiso de entidad download sólo se permite al administrador autenticado para validar el widget ManagedFile, sin exigirle rol Tester; no habilita el acceso HTTP genérico a los bytes.

Catálogo y descargas alojadas comparten el límite por IP de 240 peticiones en 60 segundos y Retry-After. Las respuestas autenticadas son private, no-store y varían por Authorization
y Cookie. Las anónimas mantienen ETag y revalidación. Search API filtra la
audiencia en servidor e incluye los registros públicos antiguos sin valor;
después vuelve a contrastar el nodo actual. Los grants de nodos protegen las
consultas de entidades/Views/sitemap y los guards de rutas/entidades protegen
el acceso directo. Las versiones privadas y sus referencias de archivo no se
proyectan mediante los displays genéricos.

La actualización aditiva nelkano_modules_update_11001 crea sólo los dos campos
y el campo del índice, reconstruye permisos e indexa sin crear ejemplos ni
modificar cuentas, libros, reportes o fichas. Configuración exportada en
config/sync y receta del índice en config/catalog. Para local o un despliegue
autorizado se usa el flujo normal drush updatedb seguido de reconstruir caché;
no ejecutar install ni seeds en producción. Las muestras y QA son recetas
locales opt-in independientes.
