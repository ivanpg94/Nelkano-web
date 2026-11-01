# Desplegar Nelkano 3.0.5 (15)

Preparación local de Android ARM64, Nelkano Windows x64 y SDK Studio Windows.
Esta reparación está preparada en local, sin push ni despliegue remoto. El Redmi
recibió el APK corregido mediante ADB, conservando la instalación y sus datos. La entrega
incluye la web, privacidad y borrado de cuentas, licencias, retirada de traducción
y corrección del archivo compartido con el instalador Android.

Sólo se descarga la última versión por aplicación; las notas anteriores se
conservan. SteamOS sigue oculto. Android tiene APK de actualización; Windows usa
MSI y SDK Studio un ZIP completo. Sus EXE siguen disponibles para instalación manual.
Las fuentes propias de Nelkano siguen privadas. El ZIP público contiene sólo
las fuentes y recetas correspondientes de Java/GCC, no las de la aplicación.

## Comprobación de limpieza del despliegue

El script preparado exige un checkout limpio antes de empezar y verifica que
siga limpio antes y después de reabrir la web. Los logs e instaladores
reconstruidos no deben seguir versionados. No ejecutar git add/commit,
git reset --hard ni git clean para ocultar un fallo: revisar los archivos
indicados. Los cambios de preparación de una entrega pertenecen al PC y deben
revisarse antes de publicar el commit; no se generan durante el despliegue.
Una instalación antigua con modificaciones necesita el respaldo y la transición
del apartado 3. Los cambios de código no incluidos en esta entrega se conservan
y requieren revisión; nunca se hace un reset ni una limpieza general.

## 1. Subir el commit desde el PC

En PowerShell, revisar que el último commit sea el de esta entrega y ejecutar:

```powershell
Set-Location 'C:\Users\ivanp\Desktop\nelkano-web'
git status --short # debe estar vacío antes del push
git log -1 --oneline
git push origin main
```

El commit web contiene los instaladores y sus metadatos. Los EXE, MSI, ZIP de Studio y el archivo de
fuentes de terceros viajan en fragmentos de hasta 40 MiB: el script los reconstruye
y comprueba automáticamente. No hace falta subirlos por separado. Las fuentes
de la app y los trabajos locales de cores están en el repositorio vecino y no
se suben al hosting con este commit.

## 2. Respaldar producción

Abrir tu sesión SSH habitual. No editar contenido mientras dure la actualización.
Ejecutar el siguiente bloque y comprobar el SHA del commit web entregado.
Si hay un error, parar; no usar `reset --hard`, no borrar archivos privados y
no continuar quitando mantenimiento.

```bash
set -euo pipefail
umask 077
cd /home/u985217890/domains/nelkano.com
test "$(git branch --show-current)" = main
git status --short
# Los archivos nuevos de private/ son datos del sitio: no se borran.
# Git también rechazará un merge que sobrescriba archivos sin seguimiento.
git diff --cached --quiet # no continuar con cambios preparados en el índice
git fetch origin main
release_commit="$(git rev-parse origin/main)"
[[ "$release_commit" =~ ^[a-f0-9]{40}$ ]]
# Debe ser el commit de reparación indicado por Codex; comprobar antes de seguir.
git show --no-patch --oneline "$release_commit"
git cat-file -e "$release_commit^{commit}"
git merge-base --is-ancestor "$release_commit" origin/main
git merge-base --is-ancestor HEAD "$release_commit"
backup_dir="$HOME/nelkano-backups/before-3.0.5-$(date +%Y%m%d-%H%M%S)"
test ! -e "$backup_dir"
mkdir -p "$backup_dir"
git rev-parse HEAD > "$backup_dir/revision.txt"
git status --porcelain > "$backup_dir/git-status-before.txt"
git diff --no-ext-diff > "$backup_dir/source-before.patch"
./vendor/bin/drush --root=public_html state:set system.maintenance_mode 1 --input-format=integer
./vendor/bin/drush --root=public_html cache:rebuild
./vendor/bin/drush --root=public_html sql:dump --result-file="$backup_dir/database.sql"
test -s "$backup_dir/database.sql"
gzip "$backup_dir/database.sql"
gzip -t "$backup_dir/database.sql.gz"
./vendor/bin/drush --root=public_html config:export --destination="$backup_dir/config" --yes
./vendor/bin/drush --root=public_html book:export de520020-2026-4000-8000-000000000001 "$backup_dir/nelkano-sdk.yml"
public_files="$(./vendor/bin/drush --root=public_html php:eval 'echo \Drupal::service("file_system")->realpath("public://");')"
private_files="$(./vendor/bin/drush --root=public_html php:eval 'echo \Drupal::service("file_system")->realpath("private://");')"
test -d "$public_files"
test -d "$private_files"
case "$private_files" in "$PWD/public_html"|"$PWD/public_html/"*) echo 'private:// debe quedar fuera del docroot'; exit 1;; esac
tar -czf "$backup_dir/files-settings.tar.gz" -- public_html/sites/default/settings.php "$public_files" "$private_files"
tar -tzf "$backup_dir/files-settings.tar.gz" >/dev/null
erasure_registry="$(./vendor/bin/drush --root=public_html php:eval 'echo \Drupal\Core\Site\Settings::get("nelkano_erasure_registry_directory", dirname(DRUPAL_ROOT, 2)."/nelkano-erasure-registry");')"
if [ -d "$erasure_registry" ]; then
  tar -czf "$backup_dir/erasure-register.tar.gz" -- "$erasure_registry"
  tar -tzf "$backup_dir/erasure-register.tar.gz" >/dev/null
fi
printf 'Respaldo guardado en: %s\n' "$backup_dir"
```

Guardar la ruta del respaldo. Si producción tiene cambios editoriales posteriores,
conciliarlos antes: importar configuración y libro aplica las versiones preparadas.
El registro de cuentas borradas se conserva además fuera de los archivos del sitio;
si se restaura una copia antigua, se debe mantener el registro vivo más reciente.

## 3. Aplicar la entrega

Continuar en la misma sesión SSH. El bloque vuelve a definir la revisión para
evitar el error de intentar fusionar una variable vacía:

```bash
set -euo pipefail
cd /home/u985217890/domains/nelkano.com
release_commit="$(git rev-parse origin/main)"
# La receta nueva se lee del commit ya descargado, sin ejecutar código del servidor remoto.
: "${backup_dir:?Ejecuta primero el bloque de respaldo en esta misma sesión SSH}"
test -s "$backup_dir/database.sql.gz"
test -s "$backup_dir/files-settings.tar.gz"
git show "$release_commit:scripts/prepare-deploy-update.sh" > "$backup_dir/prepare-deploy-update.sh"
bash "$backup_dir/prepare-deploy-update.sh" "$PWD" "$release_commit" "$backup_dir"
test "$(git rev-parse HEAD)" = "$release_commit"
bash scripts/deploy-hostinger.sh
./vendor/bin/drush --root=public_html updatedb:status
./vendor/bin/drush --root=public_html config:status
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml --dry-run
```

La transición previa conserva logs e instaladores históricos aunque estuvieran
modificados. También admite diferencias de finales de línea o texto ya incluido
en el commit preparado. Cualquier otro cambio de código se rechaza antes de tocar
archivos: conserva el listado para revisarlo y no hagas un commit de logs/binarios
en producción. Sus copias quedan en `checkout-state/files` dentro del respaldo.

El script reconstruye y verifica los archivos, instala con Composer desde el lock,
registra los instaladores, aplica updates/configuración y libro, activa la limpieza
periódica de datos caducados y comprueba el registro independiente de supresiones.
Sólo reabre la web cuando todo termina correctamente. Si falla, mantenerla en
mantenimiento y conservar el error; no ejecutar la reaplicación de borrados a ciegas.

La simulación del libro debe indicar 23 páginas `unchanged`; no deben quedar updates
ni diferencias de configuración inesperadas. No ejecutar `config:export` sobre
`config/sync` en producción después del pull: sobrescribiría esta entrega.

## 4. Comprobar la web y descargar

```bash
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/android-arm64
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/windows-x86_64
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/sdk-studio-windows-x86_64
curl --fail --silent --show-error --head https://nelkano.com/third-party-sources/nelkano-runtimes-2026-10-31.zip
NELKANO_PRIVACY_OPS_ACTION=status ./vendor/bin/drush --root=public_html php:script scripts/privacy-operations.php
```

Android debe devolver **3.0.5, código 15**, con bytes y SHA-256 del manifiesto.
Windows y SDK deben devolver también **3.0.5, código 15**, con formato `msi` y
`zip` respectivamente; `latest:null` supone que la reparación no está aplicada.
En `/versiones` y `/en/releases`, las tres descargas deben indicar 3.0.5; las notas
antiguas siguen visibles, pero sus rutas de descarga devuelven 404. Créditos y
privacidad deben abrir sin errores, y el ZIP de fuentes debe responder 200.
El estado de privacidad debe mostrar `maintenanceEnabled: true`.

En el Redmi, descargar esta primera versión desde la web e instalarla encima de
la anterior, **sin desinstalar ni borrar datos**. Así recibe la corrección del
actualizador antiguo. En el Redmi se reprodujo el fallo de permisos de la 3.0.4. Con el código corregido,
el instalador del sistema reconoce Nelkano y completa la instalación. Se verificó
el hash exacto del APK de producción instalado y firstInstallTime conservado.
La prueba del handoff parte del código corregido 3.0.5, sin desinstalar. No se puede reparar el código de una 3.0.4 ya instalada
cambiando solamente el APK del servidor.

Las clases de los clientes Windows/Studio instalados detectaron la oferta local y
validaron descarga, MSI y ZIP completos. Se utilizó una conexión de prueba al Drupal
local; no se instalaron las aplicaciones de escritorio ni se verificó el aviso
contra producción. Tras desplegar: Ajustes → Actualización → Comprobar en ambas.
No se ha realizado una prueba de gameplay.

Comprobar también un correo de verificación/recuperación propio: el transporte
real de correo de producción no se ha comprobado desde esta preparación local.

La sustitución del APK 3.0.5 sólo acepta el SHA anterior conocido y conserva una
copia privada en `private://nelkano-release-repairs/`. Una colisión distinta sigue
bloqueada. El script comprueba los tres canales antes de reabrir la web.
