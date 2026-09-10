# Backlog de HU y estados por taxonomía

La implementación activa está en `public_html/modules/custom/nelkano_home/`.

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
