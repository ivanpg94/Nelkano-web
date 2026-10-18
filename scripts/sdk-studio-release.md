# Entrega local 3.0.3-beta (13)

Esta entrega contiene tres archivos: APK Android, EXE de Nelkano Windows y EXE
de SDK Studio. Las notas ES/EN y nombres están en
`release-artifacts/release-3.0.3-beta.json`; los SHA-256 están en `manifest.json`.

Windows y Studio solo publican `installer_file`. Se instalan y actualizan
manualmente con sus EXE. Los clientes actuales necesitan MSI y ZIP para las
actualizaciones automáticas; sin esos archivos, sus canales devuelven
`latest=null` y no anuncian un EXE incompatible. Android conserva `apk_file`
y su actualización automática.

Los EXE usan `/descargar/{language}/{platform}/{version}/instalador`, con
`platform=windows` o `sdk`. Ocultar la fila retira también su descarga manual.
Los archivos y notas históricos se conservan.

## Preparación local

Copiar los tres artefactos y los JSON a una carpeta fuera del docroot. Definir
`NELKANO_RELEASE_PLAN` con la ruta del plan y ejecutar:

```sh
vendor/bin/drush php:script scripts/prepare-local-release.php
```

La llamada predeterminada solo comprueba entradas. Con
`NELKANO_RELEASE_APPLY=1`, registra archivos privados y filas ES/EN, conserva
históricos y exporta `nelkano_home.docs`. Windows y SDK admiten filas con solo
`installer_file`; un paquete automático opcional debe seguir siendo MSI o ZIP.

La sustitución autorizada del borrador local13 exige
`NELKANO_REPLACE_LOCAL_DRAFT=3.0.3-beta:13` y un manifiesto anterior en
`NELKANO_RELEASE_PREVIOUS_MANIFEST`. Conserva un respaldo antes de sustituir
bytes y no habilita reemplazos en producción.

## Transporte cuando se autorice el despliegue

El APK viaja por Git normal; los dos EXE, por Git LFS. Antes del despliegue,
materializar los EXE con `git lfs pull` o transferir sus archivos reales por SFTP.
Los punteros LFS no son instaladores. Los paquetes MSI y ZIP retirados no forman
parte de esta entrega ni deben copiarse al servidor.

La receta vigente está en DEPLOY.md. `deploy-hostinger.sh` comprueba el
manifiesto, registra los artefactos, importa configuración y libro y reconstruye
cachés. Mantener los archivos privados fuera de `public_html`. No ejecutar
`install-platform-catalog.php`: no hace falta para el despliegue.

Esta preparación no hace push, despliegue remoto ni instalaciones en dispositivos.
