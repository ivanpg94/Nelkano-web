# Book Import Export y documentación del SDK

Drupal 11.4.9; `drupal/book` estable 3.0.8, compatible con Drupal ^11.2.
La dependencia está fijada por `composer.lock`; `composer install` reproduce esa
versión. No copiar módulos contrib a Git ni actualizar Drupal durante el despliegue.

El libro **SDK de Nelkano** contiene 21 páginas en español, basadas en el SDK
0.2.0 de `Nelkano-emulator/src/android-new/`. Su raíz tiene UUID
`de520020-2026-4000-8000-000000000001`. Ruta local:
`http://localhost:8088/es/sdk-de-nelkano`. Los ejemplos y las limitaciones distinguen
Android nuevo, simulador gráfico Windows x64, contratos de ejemplo y funciones todavía sin conectar. El libro señala diferencias del simulador y el desfase GAME_HEADER_ACTION en los tipos TypeScript; no los presenta como compatibilidad completa.

## Configuración y contenido son transportes distintos

`config/sync` contiene la activación de módulos, el tipo `sdk_page`, sus campos,
displays, idiomas y ajustes de Book. **Las páginas viven en la base de datos**.
`drush config:export` NO exporta nodos ni sus cuerpos ni la jerarquía Book.

`content/books/nelkano-sdk.yml` serializa expresamente el contenido mediante
`book:export`. Es la fuente editorial para transportar el libro con Git.
El módulo incluye configuración inicial para el bundle `sdk_page`; requiere el
campo compartido `field.storage.node.body`, presente en este proyecto. Otros
bundles necesitan body/path, habilitación en Book y traducciones configuradas.

Schema 1:

```yaml
schema: 1
book: de520020-2026-4000-8000-000000000001
pages:
  - uuid: de520020-2026-4000-8000-000000000001
    type: sdk_page
    langcode: es
    parent: null
    weight: 10
    translations:
      es:
        title: SDK de Nelkano
        status: true
        body:
          value: '<p>Contenido editorial</p>'
          summary: ''
          format: basic_html
        alias: /sdk-de-nelkano
```

Los UUID canónicos en minúsculas identifican páginas y padres; no se transportan nid/bid/pid/vid locales.
Book ordena hermanos por peso y título traducido; usa pesos distintos cuando
quieras un orden explícito. Se rechaza el empate de peso y título traducido entre hermanos porque Book lo resuelve por NID local, que no es portable. La profundidad máxima es 9. Los idiomas son las claves
de translations; langcode es el idioma original y no se cambia sobre una página
existente. La publicación, título, formato, resumen y alias son propios de cada
traducción. Los idiomas y formatos deben existir antes de importar.

Los enlaces HTML a páginas del libro se convierten a
`book://UUID/idioma?consulta#fragmento` en el YAML y se resuelven a URLs del destino,
incluidos sus prefijos de idioma. Se admiten href con comillas o sin ellas,
aliases actuales y rutas `/node/N` o con prefijo de idioma. Al cambiar un alias se
revisan también las páginas que lo referencian. Los enlaces externos y anchors
locales se conservan. No se trasladan URLs absolutas del dominio de origen:
usa enlaces internos relativos al sitio, sin codificar el dominio local.

La salida tiene un allowlist estricto: solamente los campos del esquema anterior.
No incluye autores, usuarios, correos, permisos, tokens, contraseñas, timestamps,
metadata de revisiones, reportes, backlog, archivos ni otros libros. Se rechazan
campos desconocidos y medios incrustados/subidos; no se transportan uploads.
El texto editorial puede contener lo que escriba un editor: revisa su diff para
asegurar que no ha pegado credenciales, datos personales o material privado.
Un YAML editorial nunca sustituye un backup de la base de datos.

## Editar local → exportar → Git

1. Arranca Docker con `docker compose up -d`. El directorio `content/` está montado
   en `/opt/drupal/content`, fuera del docroot público.
2. Edita las páginas mediante `/admin/content`, `/node/N/edit` y las opciones de
   outline/orden de Book. Añade páginas hijas desde Book; así reciben UUID durables.
   La edición y traducción son tareas editoriales con los permisos Drupal
   correspondientes. No se conceden permisos nuevos a anonymous/tester.
3. Exporta SOLO este libro desde la base local después de cada cambio editorial, importación o modificación de jerarquía/traducción. No terminar la tarea con el YAML desactualizado. Verifica después que la simulación indique unchanged en todas las páginas:

   ```powershell
   docker exec nelkano-drupal vendor/bin/drush book:export de520020-2026-4000-8000-000000000001 content/books/nelkano-sdk.yml
   docker exec nelkano-drupal vendor/bin/drush book:import content/books/nelkano-sdk.yml --dry-run
   git diff -- content/books/nelkano-sdk.yml
   ```

   El plan muestra `create`, `update` o `unchanged` por UUID. Exportar a docroot o
   mediante un enlace simbólico se rechaza. La escritura del YAML es atómica y
   sus bytes son deterministas para un contenido idéntico.
4. Revisa texto, enlaces, permisos y límites reales frente al código del SDK.
   Versiona el YAML junto al código/configuración necesarios:

   ```sh
   git add content/books/nelkano-sdk.yml
   git commit -m "Update Nelkano SDK book"
   git push
   ```

   En la primera entrega incorpora también el módulo, Composer, configuración,
   scripts y documentación de esta tarea. No incluyas cambios ajenos pendientes.
   Estos comandos ilustran el flujo: esta preparación no ha hecho commit/push en el repositorio principal.

## Producción: pendiente de aprobación

Después de aprobar la entrega, conserva backups fuera del docroot y concilia los
cambios editoriales del servidor. Ejecuta el despliegue existente para instalar
Composer y sincronizar configuración. No cambiar el UUID del sitio ni sustituir
settings.php. El script de despliegue existente **no importa automáticamente el
libro**: el contenido tiene una fase explícita y revisable.

```sh
bash scripts/deploy-hostinger.sh
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml --dry-run
# Revisar el plan; aplicar solo la entrega aprobada:
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml
./vendor/bin/drush --root=public_html cr
```

Alternativa preparada: `bash scripts/import-sdk-book.sh` simula; añadir `--apply`
ejecuta el plan después de simular. No se ha ejecutado en producción. Evita
ediciones simultáneas durante la importación. El bloqueo serializa importadores,
pero no bloquea el formulario de edición Drupal. La importación es un flujo
unidireccional: un cambio editorial del servidor debe recuperarse y conciliarse
antes de sustituirlo por un YAML anterior.

El importador comprueba esquema, grafo, formatos, idiomas, pertenencia al libro y
aliases antes de escribir. Un UUID ajeno al libro, tipo o idioma original distinto,
ciclo, padre ausente o enlace roto detiene la operación. `--dry-run` no cambia
contenido, revisiones, usuarios ni aliases (el bloqueo temporal usa Drupal Lock).
La ejecución real es transaccional: un fallo de persistencia revierte nodos,
revisiones, aliases y outline. Cada página nueva/cambiada crea una revisión;
una reimportación idéntica no guarda ni duplica. No copia el autor del origen;
las revisiones de importación se registran como operación del sistema.

Las páginas y traducciones omitidas se conservan. No hay borrado implícito ni
opción prune. Una eliminación explícitamente autorizada se realiza con Drupal,
seguida de exportación/revisión y el mismo borrado autorizado en el destino;
omitirla del YAML no la borra. Las referencias del YAML deben formar un grafo
cerrado de páginas; una traducción omitida puede ser destino si ya existe.

Para revertir texto/jerarquía, importa un YAML anterior y revisa su simulación.
Book mantiene el outline vigente fuera de las revisiones del nodo: revertir una
revisión en la UI no garantiza revertir la jerarquía; el YAML previo sí conserva
padres y pesos. Páginas añadidas después seguirán existiendo hasta un borrado
expreso. Conserva el backup para una restauración completa.

## QA reproducible y alcance

La prueba `scripts/test-book-import-export.php` rechaza bases cuyo nombre no
empiece por `nelkano_book_qa_`. No ejecutarla en producción ni en la base local
editorial. Usa una instalación/multisite descartable con Composer idéntico:

1. Crea una base QA y un usuario local con acceso solo a ella; instala Drupal
   standard en `sites/book-qa.localhost`, mediante `drush --uri=http://book-qa.localhost
   site:install`. Este proyecto requiere almacenamiento `node.body`: en una
   instalación estándar nueva que no lo incluya, créalo como `text_with_summary`,
   cardinalidad 1 y translatable true antes de habilitar este módulo.
2. Habilita `book_import_export`, crea el idioma es y ejecuta `drush cr`.
3. La instalación independiente sirve para probar contenido con NIDs distintos.
   Para ensayar la configuración completa usa otra base descartable que sea una
   copia de la base local real, con settings propios y directorio config/sync.
   Una instalación estándar independiente puede tener tipos de campos compartidos
   distintos; no cambia la identidad de producción ni los tipos de sus campos.
   El ensayo sobre copia se verifica con `scripts/test-book-release-config.php`,
   que exige un nombre `nelkano_book_qa_release_*` y compara las tablas de contenido
   con la base local de origen, sin exportar sus filas.
4. Importa `content/books/nelkano-sdk.yml` en esa instalación y ejecuta:

   ```powershell
   docker exec nelkano-drupal php -d memory_limit=512M vendor/bin/drush.php --uri=http://book-qa.localhost book:import content/books/nelkano-sdk.yml
   docker exec nelkano-drupal php -d memory_limit=512M vendor/bin/drush.php --uri=http://book-qa.localhost php:script scripts/test-book-import-export.php
   ```

   La fixture usa una transacción y revierte sus cambios al terminar, también en
   caso de fallo. La prueba admite BOOK_QA_SOURCE para un YAML de la misma fixture.
   El libro conserva su estado anterior a cada ejecución.

Validación del 04-10-2026: Drupal 11.4.6 / Book 3.0.7 / MariaDB 10.11; 38 checks
superados en la base aislada `nelkano_book_qa_20261004`. El transporte Git se ensayó con commit/push a un repositorio bare local temporal y clone a otro checkout: SHA-256 idéntico, simulación/importación y suite completas en QA. No se hizo push al remoto real. Incluye transporte entre
instalaciones con NIDs distintos, UUID/revisiones, dos idiomas, orden/jerarquía,
enlaces y prefijos, alias renombrado/retirado, omisiones, idempotencia, conflictos,
allowlist, determinismo y rollback real tras asignar nodos. El ensayo completo de configuración en una copia de la BD local preservó los hashes de 88 tablas de contenido/cuentas; la segunda sincronización no presenta diferencias. La configuración de Language oculta langcode en los displays anteriores y conserva los componentes derivados que Drupal añade al guardarlos, sin cambiar su contenido. Sintaxis PHP y HTTP
local del libro/navegación comprobados. No certifica un despliegue en Hostinger,
pruebas del SDK en dispositivo ni compatibilidad con campos arbitrarios, media,
moderación editorial, dominios de idioma o bundles con clases incompatibles con
Book. Schema 1 tiene límites de 1000 páginas, YAML 16 MiB y 1 MiB por cuerpo/resumen.

Actualización local del 11-10-2026: core 11.4.9, Book 3.0.8 y CTools 4.1.1.
Composer audit sin avisos. Ejecutado book_update_103011 y guardado su ajuste
update_child_aliases: false en configuración. Los 38 checks de transporte, 12
de presentación y 20 del editor del SDK pasan en QA con rollback. Las pruebas
usan el número real de páginas del YAML y exportaciones temporales exclusivas.
Producción: aplicar el lock mediante el despliegue existente, que ejecuta los
updates oficiales; no importar el libro para actualizar sus dependencias.

## Presentación pública del SDK

`nelkano_home` presenta las rutas canónicas de las páginas `sdk_page` pertenecientes a un libro con la misma plantilla exterior, menú, pie, consentimiento y CSS de `/guia`. La edición y las revisiones siguen utilizando Drupal. El cuerpo pasa por los filtros de texto de Drupal; el índice excluye páginas sin acceso y sus descendientes. Incluye jerarquía, capítulo activo y navegación anterior/siguiente. Solo ofrece cambio de idioma cuando existe una traducción accesible. El contenido YAML sigue siendo independiente de la presentación.

Prueba local de presentación (base QA desechable):

```sh
docker exec nelkano-drupal php -d memory_limit=512M vendor/bin/drush.php --uri=http://book-qa.localhost php:script scripts/test-sdk-book-presentation.php
```

## Editor del SDK en el panel de Nelkano

En `/admin/nelkano/sdk`, la opción SDK de «Páginas editables» permite editar portada y capítulos, crear capítulos y subpáginas, cambiar su padre/orden, sus URLs, publicación y traducciones ES/EN. Los guardados reutilizan `book_import_export.transfer`, validan la jerarquía y crean revisiones; se rechaza el guardado si la revisión cambió mientras se editaba.

Los botones superiores **Exportar** e **Importar** utilizan la misma serialización de Drush. Exportar descarga `nelkano-sdk.yml` sin publicar un archivo en el servidor. Importar admite un YAML de hasta 2 MiB: carga temporal → simulación sin escrituras → botón «Importar cambios». El panel rechaza libros distintos y tipos distintos de `sdk_page`; conserva páginas/traducciones omitidas. Si el libro cambia tras simular, se exige repetir la simulación. Los formularios usan la protección CSRF de Drupal. No contienen acciones de eliminación.

Requiere ambos permisos `administer nelkano editable pages` y `administer nelkano sdk`. El segundo es restringido y no se concede automáticamente a ningún rol. El administrador local tiene acceso. La edición e importación mantienen el libro en la base de datos; para versionarlo, descarga/exporta el YAML y reemplaza `content/books/nelkano-sdk.yml` antes de subirlo con Git. Importar desde el panel no modifica archivos de Git.

Prueba del editor en base QA, con datos revertidos al terminar:

```sh
docker exec nelkano-drupal php -d memory_limit=512M vendor/bin/drush.php --uri=http://book-qa.localhost php:script scripts/test-sdk-book-admin.php
```

