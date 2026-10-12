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
imagen sí se aloja en la web; los paquetes .nelkmod siguen siendo externos.
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

El transporte usa cURL con TLS verificado, solo HTTPS público, IP validada
fijada por cada conexión, límite de bytes/tiempo/redirecciones y sin proxy.
Rechaza direcciones privadas/reservadas/multicast, credenciales embebidas,
puertos alternativos y redirecciones inseguras. Guarda metadatos y URL, nunca
el paquete. El visitante descarga directamente desde el alojamiento del autor.
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
Conservar la copia SQL y archivos como describe el README raíz. No se necesita
subir ningún .nelkmod al servidor.

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
plataformas Android/escritorio, versiones anteriores y una versión retirada.
Los títulos incluyen (prueba) y las descripciones explican que las descargas
son simuladas. Se conservan para revisar /modulos; no pertenecen al fixture
temporal de pruebas ni se eliminan al terminar la comprobación visual.

Para recrear/restaurar sin duplicar desde la raíz de nelkano-web:

    node scripts/render-module-demo.cjs
    docker compose exec -T drupal mkdir -p /tmp/nelkano-module-demo
    docker compose cp outputs/module-demo/. drupal:/tmp/nelkano-module-demo/
    docker compose exec -T -u www-data -e NELKANO_LOCAL_MODULE_DEMO=1 drupal vendor/bin/drush php:script scripts/seed-module-demo.php

El render usa Playwright/Chrome y los SVG ya existentes del sitio; NODE_PATH
puede resolver las dependencias del runtime local. Las PNG de outputs/module-demo
son salidas desechables: su receta está en scripts y no depende de build.
El seed exige opt-in, base nelkano_drupal y host database. No es un update N ni
una receta de producción. Solo crea/restaura entidades registradas en el estado
local nelkano_modules_demo_content, con paquetes firmados de prueba y transporte
simulado únicamente en ese proceso; no modifica validadores ni cron de producción.
La identidad de firma de demostración se conserva solo en ese estado local.

Los enlaces GitHub son ficticios y no ofrecen descargas reales. El cron normal
puede retirar estas versiones del buscador al comprobar sus enlaces; repetir
el seed restaura los ejemplos sin duplicarlos, conservando ediciones de las fichas.
Verificación visual local: 16 resultados, páginas de 12 + 4, búsqueda AJAX,
11 resultados Android y 11 escritorio, imágenes, versiones y presentación móvil.
### Sistemas compatibles

La ficha permite marcar varios sistemas emulados mediante casillas nativas de Drupal. Campo `field_module_systems`, `list_string`, cardinalidad ilimitada; IDs compartidos con `GameSystems` de la nueva app. Es metadata declarada por el propietario, distinta de las plataformas Android/Windows/Linux verificadas en los paquetes. La selección se muestra en tarjetas y ficha. Sin selección, sigue visible sin filtro y no coincide con un sistema concreto.

El catálogo combina `q`, `plataforma` y `sistema` mediante Search API y AJAX, incluidos limpieza, cambio de filtros y paginación. Se contrastan los sistemas actuales del nodo para descartar datos obsoletos del índice. La API pública añade `systems` y el filtro `system` de catálogo; contrato canónico en `brain/API de modulos.md` del repositorio Nelkano-emulator.

Update `nelkano_home_update_11038`: añade el campo y el campo de índice, conserva configuración y contenido, e indexa en lotes de 50. No asigna compatibilidad a nodos existentes. El seed local añade selecciones a los ejemplos sin duplicarlos; nunca se ejecuta al desplegar. Verificación: `NELKANO_LOCAL_MODULE_CATALOG_TEST=1 vendor/bin/drush php:script web/modules/custom/nelkano_modules/tests/systems-smoke.php` y `scripts/test-module-catalog-browser.cjs`.
