# Backlog de HU y estados por taxonomía

La implementación activa está en `public_html/modules/custom/nelkano_home/`.

## Acceso desde Codex

Consulta [API privada del backlog](README.backlog-api.md) para consultar, crear,
editar, asignar sprints y cambiar estados desde Codex con credenciales dedicadas.
La API se prepara para subir; su activación es independiente de reportes.

## Administración

`/admin/nelkano/backlog` aparece justo debajo de **Reportes de errores** en la
administración de Nelkano. Requiere `administer nelkano backlog`; los
administradores Drupal lo reciben por su rol administrador. Los roles de
edición de páginas o consulta de reportes no obtienen acceso adicional.

El tipo de contenido `hu` se llama **HU** y contiene título, descripción en texto
plano, número y referencia de estado. El número se asigna al guardar: `00000`,
`00001`, etc. Es inmutable, no se reutiliza al borrar y crece más allá de cinco
dígitos si es necesario. Una secuencia SQL evita duplicados entre creaciones
simultáneas; puede haber huecos tras un intento fallido. Las HU son privadas.

El tablero presenta Nuevo, En proceso, Bloqueado y Terminado. Se puede arrastrar
una tarjeta o utilizar su selector, también desde móvil o teclado. Pulsar el
título abre la edición. El movimiento se confirma en servidor antes de cambiar
de columna; los errores mantienen la tarjeta en su columna anterior. Un cambio
concurrente devuelve 409 y pide recargar. Los formularios usan CSRF de Drupal y
el movimiento exige sesión, permiso y cabecera `X-CSRF-Token`.

## Eliminar una HU desde el tablero

Cada tarjeta incorpora Eliminar HU. Antes de enviar la petición pide confirmar
el borrado permanente de la historia y todas sus revisiones. Cancelar no envía
ninguna solicitud. La tarjeta solo desaparece tras la confirmación del servidor;
se actualizan contadores y columnas vacías por AJAX, sin cambiar búsqueda/sprint.
Un error conserva la tarjeta y permite reintentar.

POST /admin/nelkano/backlog/{hu}/delete exige sesión, el permiso existente
administer nelkano backlog y X-CSRF-Token. Recibe JSON con revision entero
positivo; rechaza propiedades adicionales, JSON inválido y cuerpos mayores
de 2048 bytes. HuWorkflow::delete usa el mismo bloqueo de edición/movimiento:
una revisión antigua u otra escritura devuelve 409 y conserva la HU.
Solo acepta nodos hu; otros tipos o nodos ausentes devuelven 404.

El borrado nativo de Drupal retira nodo, campos, revisiones y entrada de
Search API. La secuencia HU permanece intacta: el número eliminado no vuelve
a asignarse. El tablero ignora entradas obsoletas cuyo nodo haya desaparecido
mientras otra persona lo está consultando. Se conserva el control de acceso
privado, sin nuevos permisos ni cambios de esquema/configuración de BD;
no necesita otro update N y el despliegue no borra HU automáticamente.

Prueba local de borrado:

    docker compose exec -T -e NELKANO_LOCAL_BACKLOG_DELETE_TEST=1 drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/backlog-delete-smoke.php

Validación local de 2026-10-11: 26 comprobaciones de borrado, CSRF, permisos,
revisiones, bloqueo entre procesos HTTP, aislamiento por tipo, Search API,
numeración y conservación de nodos preexistentes. La prueba de Chrome cubre
confirmar/cancelar, fallo/reintento, contadores, filtros preservados y cero
navegaciones. Solo se han eliminado datos sintéticos locales; no se ha
desplegado ni eliminado contenido en producción.

## Taxonomía y contrato de reportes

### Sprint (11022)

Las HU tienen el campo entero opcional `field_hu_sprint` (Sprint). Las existentes
quedan sin asignar. Se puede crear, modificar o vaciar desde el formulario; el
arrastre conserva su sprint. El filtro GET `?sprint=2` limita las tarjetas de las
cuatro columnas y sus contadores. Ofrece Todos los sprints, Sin sprint
(`?sprint=none`) y los números asignados, ordenados numéricamente. Admite Sprint 0.
La selección se mantiene al editar, guardar o volver al tablero; las nuevas HU
preseleccionan el sprint filtrado. La actualización 11022 añade el campo y sus
widgets de forma independiente, sin alterar datos existentes ni reportes.


El vocabulario `nelkano_workflow` (**Estados de Nelkano**) contiene términos con
`field_workflow_code`. Los nombres visibles se pueden editar desde la gestión
de taxonomías; los códigos API son fijos y los términos no se pueden eliminar.
Ambos tipos de contenido usan `field_workflow_status`, una referencia real a
taxonomía. Las HU admiten cuatro estados; los reportes conservan los cinco:

| Código API | Nombre inicial | HU | Reportes |
| --- | --- | --- | --- |
| `new` | Nuevo | Sí | Sí |
| `in_progress` | En proceso | Sí | Sí |
| `blocked` | Bloqueado | Sí | Sí |
| `resolved` | Terminado | Sí | Sí |
| `rejected` | Descartado | No | Sí |

La actualización **11021** crea el vocabulario, sus términos, los campos, el tipo
HU y la secuencia. Copia los estados existentes, incluidas traducciones y
revisiones históricas, sin guardar de nuevo los nodos ni modificar sus IDs de
revisión, fechas, adjuntos o recibos. Conserva `field_report_status` oculto como
campo de compatibilidad, sincronizado desde la taxonomía al guardar un reporte.
La API, la cola de nuevos, el detalle y los cambios masivos utilizan la taxonomía.
El contrato JSON y los hashes de manifiestos no cambian; `If-Match`, la revisión
del recibo y el motivo obligatorio para Bloqueado siguen vigentes.

Los UUID de la configuración nueva se toman de `config/sync` cuando existe;
los IDs numéricos de términos se resuelven por código y no se exportan como
valores por defecto. Se actualizan los manejadores de estado de las Views
existentes conservando sus columnas, orden y demás personalizaciones.

## Activación

La actualización **11023** crea los términos de taxonomía que falten en
producción: Nuevo (`new`), En proceso (`in_progress`), Bloqueado (`blocked`),
Terminado (`resolved`) y Descartado (`rejected`). Se ejecuta mediante
`vendor/bin/drush updatedb -y` después de subir el módulo. Es idempotente:
busca por vocabulario y código API, conserva los IDs y nombres personalizados
existentes y solo crea los ausentes. No requiere exportar/importar contenido
de taxonomía ni modifica HU, reportes o sus referencias. Las instalaciones
nuevas y la actualización 11021 utilizan el mismo inicializador.

La prueba local `tests/workflow-terms-update-smoke.php` verifica creación desde
cero, ejecución repetida, conservación de nombres e IDs y reparación de un
término ausente. Sus cambios quedan dentro de una transacción revertida y los
términos originales se comprueban de nuevo al finalizar.

Antes de desplegar, realizar y verificar una copia de la base de datos. Activar
mantenimiento mientras se sube el módulo y se ejecuta la migración, para evitar
peticiones con código nuevo y esquema antiguo. Desde el proyecto Drupal:

```sh
vendor/bin/drush state:set system.maintenance_mode 1 -y
vendor/bin/drush updatedb -y
vendor/bin/drush cache:rebuild
vendor/bin/drush state:set system.maintenance_mode 0 -y
vendor/bin/drush cache:rebuild
```

El hook aplica toda la configuración necesaria sin una importación global.
Si también se va a importar configuración, ejecutar `updatedb` **antes**.
Comprobar el resultado
de cada comando; si alguno falla, mantener mantenimiento hasta resolverlo.
Importar únicamente contra la instalación cuyos UUID y configuración se han
conciliado con este repositorio. Docker conserva diferencias anteriores en la
View de reportes (observaciones y un display de exportación) que no se han
incluido en este cambio; importar la vista completa las reemplazaría. El hook
modifica únicamente sus manejadores de estado. Los términos son contenido: los crea el hook
de actualización/instalación, no una exportación de configuración.

En Docker local, anteponer `docker compose exec -T drupal` a los comandos.
El contenedor Drupal local carece del ejecutable `mysql`, por lo que su
`drush sql:dump` no funciona; usar `mariadb-dump` en el servicio `database`.

## Pruebas locales

```sh
docker compose exec -T drupal php web/modules/custom/nelkano_home/tests/report-workflow-unit.php
docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/report-api-smoke.php
docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/report-bulk-smoke.php
docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/backlog-smoke.php
docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/report-taxonomy-migration.php
```

Utilizar un entorno local de pruebas. Los tests eliminan sus nodos y usuarios
sintéticos; no retroceden la secuencia HU, por lo que consumen números. La
prueba de migración comprueba conservación de datos, revisiones y recibos,
estado histórico, cobertura de todas las filas y repetición sin duplicados.
La prueba del backlog cubre permisos reales por HTTP, CSRF, secuencia,
inmutabilidad, estados, revisiones, conflictos y escape de títulos.

## Términos adicionales y ordenación (2026-09-12)

El formulario de `/admin/structure/taxonomy/manage/nelkano_workflow/add`
permite crear términos normales, sin pedir códigos ni equivalencias API.
El identificador interno de los cinco términos originales se conserva oculto
y es opcional para los términos nuevos. Los términos adicionales no se añaden
a los estados ni a la cola de la API; su contrato y sus cinco referencias
originales siguen siendo los anteriores. Se pueden editar y borrar los términos
adicionales; los originales conservan su protección.

11024 configura el alta ordinaria. 11025 aplica la misma configuración en los
entornos que llegaron a ejecutar la versión intermedia y elimina su campo de
equivalencia, conservando los términos. Desplegar con `vendor/bin/drush deploy -y`.

La vista de reportes permite ordenar por fecha de actualización ascendente o
descendente. Por defecto usa `changed DESC` y desempata por `nid DESC`.

## Acciones de reportes (2026-09-12)

El selector administrativo incluye todos los términos de Estados de Nelkano,
incluidos los creados sin código. Se guardan por su referencia de taxonomía;
no se pide ni se genera una equivalencia API. Los cinco estados de la API
conservan sus códigos y su cola. Un reporte en un término adicional queda
fuera de ese flujo: no aparece en la cola y las rutas API por ID devuelven 404
hasta devolverlo a un estado original. La administración puede mostrarlo y editarlo.

La cabecera sustituye Exportar CSV por **Eliminar terminado** y
**Eliminar descartado**. Son formularios POST con CSRF y requieren
`delete nelkano error reports`, además del permiso de consulta.
Seleccionan todos los reportes en el término original correspondiente,
sin aplicar el paginador ni los filtros de la vista. Drupal procesa la lista
en lotes de diez y vuelve a comprobar el estado bajo el bloqueo compartido
con la API. Los reportes que cambian de estado antes de procesarse se omiten.

El bloque inferior se llama **Acciones**, sin el texto explicativo anterior.
Incluye el selector compacto, Aplicar a los seleccionados y
**Eliminar seleccionados**. Este último usa únicamente las casillas válidas de
la página actual y no requiere elegir un nuevo estado.

El borrado elimina el nodo con sus revisiones y recibos API, y las carpetas
privadas de sus adjuntos actuales e históricos, incluidos archivos sobrantes
dentro de esas carpetas. Solo admite directorios UUID bajo
`private://nelkano-error-reports`; rechaza rutas ajenas, enlaces simbólicos y
directorios referenciados por otros reportes. Los errores de disco se registran
y se notifican al terminar; no se presenta un borrado fallido como correcto.
Los adjuntos se guardan como archivos privados sin entidades File.
No se ha ejecutado ninguna limpieza sobre reportes reales durante el desarrollo.

Pruebas locales:
```sh
docker compose exec -T -e NELKANO_LOCAL_REPORT_ACTIONS_TEST=1 drupal vendor/bin/drush --uri=http://localhost php:script web/modules/custom/nelkano_home/tests/report-actions-smoke.php
docker compose exec -T -e NELKANO_LOCAL_REPORT_ACTIONS_TEST=1 drupal vendor/bin/drush --uri=http://localhost php:script web/modules/custom/nelkano_home/tests/report-actions-http-smoke.php
```
La primera revierte sus datos y prueba borrado físico, revisiones, recibos,
rutas, permisos, estados nuevos y cobertura de más de 50 reportes. La segunda
crea una cuenta y un reporte temporales para enviar el formulario HTTP real,
comprobar CSRF y ejecutar el lote de eliminación. Ambas conservan los reportes
preexistentes y limpian sus datos sintéticos.

## Búsqueda y privacidad (11035)

Search API 1.41 y su backend de base de datos gestionan la búsqueda del tablero;
no se implementa un motor propio. El buscador admite título y descripción,
palabras parciales, varias palabras, mayúsculas y búsquedas sin tildes. Para
número se puede escribir 00042, 42 o HU 00042. El selector Sprint se mantiene
y se combina con la consulta del índice, incluido Sprint 0 y Sin sprint.

La búsqueda se actualiza al escribir, cambiar sprint, pulsar Filtrar o limpiar.
Usa los comandos AJAX de Drupal y conserva las cuatro columnas, tarjetas,
contadores, arrastre y cambios de estado. Los filtros permanecen en la URL y
al crear, editar, guardar o cancelar una HU. Sin JavaScript funciona el GET
normal del formulario. Las solicitudes sucesivas se procesan en orden; un fallo
conserva el tablero anterior y permite reintentar.

Las HU están despublicadas deliberadamente desde la implementación original:
HuWorkflow::save() las crea con status=0 y hook_node_presave() impide
publicarlas incluso desde el formulario de nodo. hook_node_access() exige
el permiso administrativo del backlog para cada nodo. Los roles públicos/editoriales
versionados no reciben ese permiso; el administrador Drupal lo obtiene por su
rol administrativo. Se conserva esta política, sin publicar actividades para
hacerlas buscables. Las pantallas administrativas usan page--nelkano-admin.html.twig para mostrar
solo la cabecera de Nelkano, sin la cabecera ni el pie del tema activo. Se aplica
también a las cuentas limitadas que no pueden usar el tema administrativo;
no se amplían sus permisos. Las regiones de mensajes y contenido se conservan.
Los listados de backlog y reportes comparten el diseño compacto de las demás
páginas administrativas mediante redesign_admin y admin_data. Los ajustes
de tarjetas, filtros y tabla se limitan a esos dos listados; la tabla conserva
Views y su formulario, con desplazamiento horizontal independiente de las
acciones en móvil. No cambia el diseño de las demás pantallas ni el esquema.
El endpoint HTML/AJAX también exige el permiso y comprueba
de nuevo acceso a cada nodo cargado. No hay página pública de este índice.

La actualización **11035** habilita search_api y search_api_db, crea solo el
servidor/índice ausente y completa el indexado inicial en lotes de 50. Las recetas
viven en config/backlog/; la configuración desplegable, en config/sync/.
No guarda ni elimina HU, usuarios, reportes, taxonomía, revisiones ni números.
Los cambios posteriores se indexan al terminar cada petición mediante
index_directly; cron conserva el seguimiento estándar de Search API.

Desplegar el repositorio completo con composer install y drush deploy -y
(o el script de despliegue existente). updatedb debe ejecutarse antes de la
importación de configuración; 11035 deja indexadas las HU anteriores y es
reanudable si falla el backend. No es necesario publicar nodos ni vaciar tablas.
Para diagnóstico: drush search-api:status nelkano_backlog.

Pruebas locales, nunca en producción:

    docker compose exec -T -e NELKANO_LOCAL_BACKLOG_SEARCH_TEST=1 drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/backlog-search-smoke.php
    docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/sprint-smoke.php
    docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/backlog-smoke.php
    node scripts/test-backlog-browser.cjs

La prueba de búsqueda exige la BD Docker nelkano_drupal, recrea exclusivamente
metadatos del índice y verifica la conservación mediante hashes de todas las
tablas de contenido durante la migración y su repetición. También cubre
resultados por encima de 10, HTML/AJAX, filtros combinados, escape y denegación
de nodos, edición, revisiones y búsquedas a usuarios anónimos y ordinarios,
incluido el propietario con permiso para ver contenido propio despublicado.
Las pruebas eliminan únicamente sus entidades sintéticas; la secuencia HU
permanece monotónica y puede consumir números.

La prueba de navegador usa Playwright (resoluble con NODE_PATH) y Chrome
instalado; NELKANO_TEST_BROWSER permite seleccionar otro canal. Comprueba
cero navegaciones, foco, refresco, movimiento posterior, solicitudes sucesivas
y recuperación ante error, además de la envoltura sin Olivero en los temas
Olivero y Claro y en el formulario de alta; limpia sus HU/cuentas al finalizar. Si se interrumpe,
ejecutar el fixture con NELKANO_LOCAL_BACKLOG_BROWSER_TEST=1 y
NELKANO_BACKLOG_BROWSER_ACTION=cleanup.

Validación local del 11-10-2026: 51 comprobaciones de búsqueda/update/acceso,
23 de sprint, 26 de backlog y prueba real en Chrome superadas. Las capturas
desktop/móvil muestran el tablero existente con el nuevo control. No se ha
desplegado ni ejecutado la migración en producción.
