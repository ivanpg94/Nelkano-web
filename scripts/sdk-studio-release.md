# Entrega local 3.0.3-beta (13)

Android, Windows y SDK Studio comparten versión y código. Las tres filas se
preparan visibles en Drupal local. Esto no publica ni despliega producción.
Las notas ES/EN y los nombres de archivo están en
`release-artifacts/release-3.0.3-beta.json`; los SHA-256 están en `manifest.json`.

Windows y SDK Studio ofrecen dos archivos en cada fila:

- `installer_file`: EXE para la primera instalación. Studio incluye Node.js y Java; Nelkano Desktop incluye Java.
- `apk_file`: MSI para el canal Windows y ZIP completo para `sdk-studio-windows-x86_64`.

Los instaladores usan `/descargar/{language}/{platform}/{version}/instalador`
para `windows` y `sdk`; las rutas sin
`/instalador` conservan el MSI de Windows y el ZIP de Studio. Los MSI anteriores siguen
admitidos. Ocultar una fila retira sus descargas y su oferta de actualización.

## Preparar y verificar en local

Copiar los cinco artefactos verificados y ambos JSON a una carpeta fuera del
docroot del contenedor. Con `NELKANO_RELEASE_PLAN` apuntando al plan JSON:

```sh
vendor/bin/drush php:script scripts/prepare-local-release.php
```

La llamada predeterminada solo verifica. Con `NELKANO_RELEASE_APPLY=1`, registra
los cinco archivos privados, completa el borrador Android y añade Windows/SDK
en ES/EN. Exporta únicamente `nelkano_home.docs`; conserva filas históricas y
rechaza versiones o códigos registrados con otros bytes.

```sh
vendor/bin/drush php:script scripts/test-platform-downloads.php
vendor/bin/drush php:script scripts/test-sdk-installer-release.php
vendor/bin/drush book:import content/books/nelkano-sdk.yml --dry-run
```

La primera prueba usa fixtures temporales. La segunda verifica la descarga
inicial del SDK, el ZIP del actualizador y el transporte de los binarios; también
comprueba que un checksum corrupto se rechaza sin cambiar configuración.

## Sustituir el borrador local antes de publicarlo

La limpieza autorizada de 3.0.3-beta (13) conserva esa identidad. El helper solo
permite reemplazarla en Docker local con `NELKANO_REPLACE_LOCAL_DRAFT=3.0.3-beta:13`
y `NELKANO_RELEASE_PREVIOUS_MANIFEST` apuntando al manifiesto anterior verificado.
Primero comprueba los cinco archivos nuevos y los hashes existentes; con APPLY
conserva los bytes y el catálogo anteriores bajo el almacenamiento privado antes
de reemplazar cada archivo de forma atómica. Sin esas opciones sigue rechazando
cualquier colisión. Esta excepción no se usa en producción.
## Transporte cuando se autorice el despliegue

Los APK se transportan por Git normal. MSI, EXE y el ZIP nuevo de SDK se
transportan con Git LFS, como los instaladores grandes anteriores. Antes del
despliegue hay que materializar los binarios con `git lfs pull`. Si el destino
no dispone de LFS, se pueden transferir los archivos reales por SFTP, según el
procedimiento del README. Los punteros LFS no son instaladores: el preflight
rechaza cualquier puntero o archivo cuyo SHA-256 no coincida.

Transportar también código, configuración, manifiesto, libro e imágenes. El
script `scripts/install-release-assets.php` verifica y registra `apk_file` e
`installer_file` antes de importar configuración; no basta con subir YAML.
Mantener los binarios fuera de `public_html`, en el almacenamiento privado.

El libro se importa con `scripts/import-sdk-book.sh --apply`. Después se
comprueban las tres API de versiones, los cinco hashes de descarga y la
referencia `/api/nelkano/v1/sdk/reference`. Esta preparación no hace push,
despliegue remoto ni instalaciones en dispositivos.
