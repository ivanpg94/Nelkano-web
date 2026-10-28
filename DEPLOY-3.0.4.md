# Desplegar Nelkano 3.0.4 (14)

Preparación local del 28/10/2026. Este procedimiento no se ha ejecutado en producción.
La entrega contiene Android ARM64, Desktop Windows x64 y SDK Studio Windows x64.
Desktop y SDK tienen instaladores EXE para descarga manual; no se añaden MSI/ZIP
ni se activan sus canales de actualización automática. Se conserva el historial.
Los hashes están en `release-artifacts/manifest.json` y el plan en
`release-artifacts/release-3.0.4.json`.

La web incorpora también el soporte de guardados originales RPG Maker en Reportes
y la documentación actualizada del SDK. Reportes 0.1.1 se entrega por separado:
Ivan lo sube después desde la ficha del módulo; no se publica con este commit.

## 1. Subir el commit web desde el PC

Revisar el SHA indicado en la entrega y ejecutar en PowerShell:

```powershell
Set-Location 'C:\Users\ivanp\Desktop\nelkano-web'
git status --short
git log -1 --oneline
git push origin main
```

Este commit pertenece a la web. Las fuentes de la aplicación están en el
repositorio vecino y conservan cambios locales; no se presentan como incluidas
en este commit. Los instaladores se transportan por Git normal en fragmentos,
que el script del servidor reconstruye y verifica automáticamente.

## 2. Respaldo y mantenimiento en Hostinger

Abrir la sesión SSH habitual y ejecutar esta fase completa. Parar ante cualquier
error. Si `git status` indica cambios, conservarlos y conciliarlos antes de seguir.
No usar `reset --hard`, `site:install`, una base de Docker ni `composer update`.

```bash
set -euo pipefail
umask 077
cd /home/u985217890/domains/nelkano.com
test "$(git branch --show-current)" = main
git status --short
test -z "$(git status --porcelain)"
git fetch origin main
read -r -p 'SHA completo del commit web entregado por Codex: ' release_commit
[[ "$release_commit" =~ ^[a-f0-9]{40}$ ]]
git cat-file -e "$release_commit^{commit}"
git merge-base --is-ancestor "$release_commit" origin/main
git merge-base --is-ancestor HEAD "$release_commit"
backup_dir="$HOME/nelkano-backups/before-3.0.4-$(date +%Y%m%d-%H%M%S)"
test ! -e "$backup_dir"
mkdir -p "$backup_dir"
git rev-parse HEAD > "$backup_dir/revision.txt"
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
printf 'Respaldo: %s\n' "$backup_dir"
```

Conservar la ruta del respaldo. La configuración y el libro versionados se
aplicarán al desplegar; conciliar antes cualquier edición nueva de producción.
Estos respaldos no se añaden a Git.

## 3. Aplicar el commit y desplegar

Continuar en la misma sesión SSH. El script instala los artefactos, ejecuta
Composer desde el lock, aplica updates/configuración con Drush e importa el libro.
No basta ejecutar solamente `drush deploy`.

```bash
git merge --ff-only "$release_commit"
test "$(git rev-parse HEAD)" = "$release_commit"
bash scripts/deploy-hostinger.sh
./vendor/bin/drush --root=public_html updatedb:status
./vendor/bin/drush --root=public_html config:status
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml --dry-run
```

El libro debe indicar 23 páginas `unchanged`. No deben quedar actualizaciones
pendientes ni diferencias de configuración inesperadas. Si falla algo, mantener
el mantenimiento activo y conservar el mensaje de error. No exportar a
`config/sync` en producción: sobrescribiría la entrega recién importada.

## 4. Abrir la web y comprobar las descargas

Sólo cuando la fase anterior termine correctamente:

```bash
./vendor/bin/drush --root=public_html state:set system.maintenance_mode 0 --input-format=integer
./vendor/bin/drush --root=public_html cache:rebuild
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/android-arm64
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/windows-x86_64
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/sdk-studio-windows-x86_64
```

Android debe devolver versión **3.0.4**, código **14**, tamaño y hash del
manifiesto. Windows y SDK deben seguir con `latest: null` porque la entrega es
EXE manual. En `/versiones` y `/en/releases`, las descargas nuevas de las tres
plataformas deben indicar 3.0.4 y coincidir con el manifiesto. SteamOS conserva su
visibilidad anterior. No se han ejecutado pruebas visuales ni de gameplay para
esta preparación; la revisión de la aplicación la realiza Ivan.

## 5. Publicar Reportes 0.1.1 manualmente

1. Desplegar primero la web y distribuir Android 3.0.4.
2. Abrir `https://nelkano.com/modulos/66` con la cuenta administradora.
3. Pulsar **Añadir versión** y subir `nelkano.error-reports-0.1.1.nelkmod`.
4. Conservar la versión 0.1.0. El ID y la firma del autor deben seguir siendo los
   mismos; la versión 0.1.1 se obtiene del paquete firmado.
5. Como notas: «Permite adjuntar guardados originales de RPG Maker y actualiza el
   formulario de reportes. Requiere el soporte incluido en Nelkano 3.0.4».
6. En Android, con la cuenta Tester autorizada: Ajustes → Módulos → Instalados →
   **Comprobar actualizaciones**. Con 0.1.0 instalada debe ofrecer 0.1.1.

Huella del autor de Reportes:
`d678dfc43349f1cf71d3bd98019c94655768685ff2b6163e5163d1b3a69df4a1`.
El SHA-256 del paquete entregado está en su recibo local; no confundir el hash del
archivo con la huella del autor. La comprobación local del detector realizada
antes de esta entrega usó metadatos simulados; la recepción desde producción
queda pendiente de esta publicación manual.
