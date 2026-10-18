# Referencia del SDK para Studio

`GET /api/nelkano/v1/sdk/reference` entrega los capítulos actuales del libro
SDK publicado. Lee Drupal, no una copia YAML. Cada capítulo contiene su UUID,
idioma, título, URL relativa y HTML completo, incluidos los ejemplos de código.
La portada y los padres deben estar publicados y ser accesibles como anónimo.
La sesión de quien hace la petición no amplía el contenido exportado.

Studio usa por defecto
`https://nelkano.com/api/nelkano/v1/sdk/reference`. Para desarrollar contra Drupal
local, definir `NELKANO_SDK_REFERENCE_URL` antes de abrir Studio:

```powershell
$env:NELKANO_SDK_REFERENCE_URL = 'http://localhost:8088/api/nelkano/v1/sdk/reference'
```

También se puede crear, en el SDK de desarrollo,
`tooling/reference/source.local.json` con
`{"url":"http://localhost:8088/api/nelkano/v1/sdk/reference"}`. Esa configuración
local tiene menor prioridad que la variable de entorno y no se distribuye.
Studio conserva una referencia completa sin conexión. Al enviar mensajes,
revalida el libro con ETag si han pasado cinco minutos desde la última consulta;
el índice se prepara en cada envío, incluidas las conversaciones reanudadas.
Los tipos, ejemplos y fuentes del SDK nativo se toman de la instalación del
SDK, no de Drupal.

`revision` es el SHA-256 del JSON compacto UTF-8 de `schemaVersion`, `sdkVersion`,
`book` y `chapters`, en ese orden, sin `revision` ni fechas. Los caracteres Unicode
y las barras no se escapan. La respuesta usa ese valor como `ETag`; una petición
con `If-None-Match` recibe `304` si el libro público no ha cambiado. Se revalida
en cada petición y no se almacena en las cachés de páginas de Drupal.

## Actualizar la copia sin conexión

El empaquetado npm y el ZIP de Studio ejecutan automáticamente
`node reference-distribution.mjs stage`: descargan y validan el libro, conservan
`tooling/reference/book.json` y preparan las fuentes públicas para uso offline.
Con `source.local.json` usan el Drupal local; sin él, la web de producción.
Si la web no responde, conservan la última copia válida incluida.

También se puede exportar sin HTTP con Drush, después de editar o importar el libro:

```powershell
docker exec -w /opt/drupal nelkano-drupal vendor/bin/drush cache:rebuild
docker exec -e SDK_REFERENCE_OUTPUT=/opt/drupal/content/sdk-reference.json -w /opt/drupal nelkano-drupal vendor/bin/drush php:script scripts/export-sdk-reference.php
```

El resultado aparece en `content/sdk-reference.json` del repositorio web porque
esa carpeta está montada en el contenedor. Copiarlo a
`src/nelkano/sdk/tooling/reference/book.json` del repositorio del emulador al
preparar la distribución. El JSON intermedio de `content` no es una segunda
fuente de contenido y no debe incluirse en un commit web.

El exportador exige una carpeta existente fuera del directorio público de la
web y rechaza enlaces simbólicos de destino. No modifica el libro. En otro
entorno, cambiar `SDK_REFERENCE_OUTPUT` por una ruta apropiada fuera de docroot.

## Comprobar el contrato

```powershell
docker exec -w /opt/drupal nelkano-drupal vendor/bin/drush php:script scripts/test-sdk-reference.php
```

Las pruebas comprueban contenido público, aislamiento de la sesión, orden,
HTML completo, enlaces, revisión, ETag y exportación. Los cambios temporales de
texto y publicación se ejecutan dentro de una transacción que se revierte.
