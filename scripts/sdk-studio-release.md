# Primera beta de SDK Studio para Windows

SDK Studio `1.0.0-beta`, código `2`, ofrece dos archivos en la misma fila:

- `installer_file`: MSI con Node.js y Java, para instalar desde Módulos o Versiones.
- `apk_file`: ZIP completo, para el canal `sdk-studio-windows-x86_64` del actualizador.

El MSI utiliza `/descargar/{language}/sdk/{version}/instalador`. La ruta anterior
`/descargar/{language}/sdk/{version}` conserva el ZIP. Ambos requieren una fila
publicada en ese idioma; ocultarla retira también la oferta del actualizador.
Los archivos privados carecen de enlaces directos.

## Preparar en local

Copiar los binarios compilados a `release-artifacts` con estos nombres, sin
sobrescribir otros archivos con bytes diferentes:

```
Nelkano-SDK-Studio-1.0.0-beta-windows-x86_64.msi
Nelkano-SDK-Studio-1.0.0-beta-windows-x86_64.zip
```

Añadir sus SHA-256 a `release-artifacts/manifest.json`, conservar las entradas
anteriores y copiar los dos binarios y el manifiesto al contenedor, fuera del
directorio público. Definir `NELKANO_SDK_RELEASE_DIR` con esa carpeta al llamar:

```
vendor/bin/drush php:script scripts/prepare-sdk-studio-release.php
```

La llamada predeterminada verifica sin escribir. Con
`NELKANO_SDK_RELEASE_APPLY=1`, registra los dos archivos privados, publica la
fila en ES/EN y exporta solo `nelkano_home.docs` a config/sync. La receta se
limita al Drupal Docker local. Rechaza sobrescribir bytes distintos o cambiar
la identidad de una versión ya publicada. El marcador beta anterior oculto
puede sustituirse para su primera publicación, conservando versión y código.

Para comprobar la entrega local:

```
vendor/bin/drush php:script scripts/test-platform-downloads.php
vendor/bin/drush php:script scripts/test-sdk-installer-release.php
vendor/bin/drush php:script scripts/test-sdk-reference.php
vendor/bin/drush book:import content/books/nelkano-sdk.yml --dry-run
```

La segunda prueba es de lectura y añade una comprobación de corrupción del MSI
en un juego temporal de transporte; no modifica los binarios publicados.
`NELKANO_SDK_BOOK_BEFORE` permite contrastar también un snapshot previo del
libro y confirmar que metadatos, capturas y capítulos privados se conservan.

## Transporte a producción

Transportar código/rutas/esquema del instalador y de la referencia del SDK,
`config/sync/nelkano_home.docs.yml`, el libro y sus imágenes, los dos binarios y
`manifest.json`. `scripts/install-release-assets.php` verifica e instala tanto
`apk_file` como `installer_file` antes de importar configuración. No basta con
subir YAML. Mantener un directorio privado fuera de public_html.

El despliegue debe importar expresamente el libro con
`scripts/import-sdk-book.sh --apply` y reconstruir cachés. Comprobar después la
descarga MSI, el manifiesto ZIP y `/api/nelkano/v1/sdk/reference`. Esta preparación
local no ejecuta el despliegue remoto.
