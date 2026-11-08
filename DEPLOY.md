# Despliegue de Nelkano web

## Entrega 3.0.7: ejecutables y CSV subidos por Ivan

Los ejecutables se generan fuera del repositorio web. Ivan sube Android (APK),
Windows (EXE y MSI) y SteamOS (ZIP) desde Administración → Versiones, con
versión **3.0.7** y código **17**. El MSI va en Archivo de descarga y el EXE en
Instalador de Windows; el actualizador no consume el EXE. El ZIP de SteamOS
sirve tanto para la descarga inicial como para actualizar. SDK Studio sigue
oculto y no se publica ni se exige una nueva entrega suya.

El despliegue de código conserva las filas ES/EN y los canales de actualización
que ya existen en producción: los captura antes de importar configuración y los
restaura después, también en la salida por error. No copia ejecutables del
repositorio ni sustituye los subidos desde administración. Sigue comprobando
los canales publicados antes de reabrir la web.

Ivan también sube los CSV desde Sistemas. No se importan ni publican durante el
despliegue. Los 18 CSV de `Nelkano-emulator/pruebas/` tienen versión 3.0.7 y
1.315 registros; esa carpeta está ignorada por Git. Su validación utiliza el
importador real, sin escribir catálogos en la BD. Se conservan alias de ROM,
observaciones de hasta 4.096 caracteres y la ficha de Switch permanece oculta.

Comprobaciones: `scripts/test-release-version-guard.php`,
`scripts/test-manual-release-catalog.php`, `scripts/test-app-updates.php` y
`scripts/check-release-csv-files.php`. Son comprobaciones locales; no acreditan
que los ejecutables o CSV de esta entrega estén publicados en producción.


La receta de 3.0.5 (15) en [DEPLOY-3.0.5.md](DEPLOY-3.0.5.md) es histórica.
El resto de este documento conserva la entrega anterior del 18/10/2026.
Las instrucciones antiguas del README describen entregas anteriores.
Preparar el commit no hace push ni modifica producción.

## Procedimiento vigente

Para esta entrega se mantiene el bloque de tres comandos: git fetch, merge
--ff-only con el SHA preparado y bash scripts/deploy-hostinger.sh. No añadir
pasos manuales de copias de BD, archivos o settings. El script conserva las
subidas del administrador y comprueba el repositorio antes de reabrir. No
reanudar un despliegue fallido quitando mantenimiento manualmente.

Los apartados siguientes son referencia histórica de 3.0.3-beta; sus comandos
no sustituyen la receta vigente ni sus comprobaciones de los tres canales.

## Contenido de esta entrega

- Navegación compartida, menú de cuenta y sección «Amplía Nelkano».
- Guía ES/EN con instrucciones agrupadas y nueve capturas Android.
- Libro SDK canónico, capturas/diagramas versionados y transporte de imágenes.
- Referencia pública del SDK para Studio: /api/nelkano/v1/sdk/reference.
- API privada de HU para Codex: /api/nelkano/v1/backlog/hu.
- Android, Windows y SDK Studio 3.0.3-beta/código13, visibles en el catálogo local.
- Instaladores EXE manuales para Windows y SDK Studio, sin paquetes MSI/ZIP de
  actualización de esta versión. Android conserva su APK ARM64 y actualizador.
- Configuración editorial, libro y displays actualizados; notas ES/EN y manifiesto
  de los tres archivos en release-artifacts.

El update11042 publica únicamente la portada española del SDK y añade los valores
faltantes de «Amplía Nelkano». El YAML canónico también publica esa portada.
Los capítulos de Reportes y Asistente continúan ocultos. SteamOS sigue oculto.
Las tres versiones nuevas están activas solo en local hasta ejecutar un despliegue autorizado.

## 1. Publicar el commit desde el PC

Desde PowerShell, después de revisar el commit web indicado en la conversación:

```powershell
Set-Location 'C:\Users\ivanp\Desktop\nelkano-web'
git status --short
git log -1 --oneline
git push origin main
```

El conector local y brain pertenecen al repositorio `Nelkano-emulator`, con su
commit complementario. No se suben al hosting; si quieres publicarlo también,
ejecuta git push origin HEAD desde ese repositorio, revisando su rama/destino.

## 2. Preparar el servidor y el respaldo

Abrir una sesión SSH del hosting con el usuario habitual. Se necesita PHP CLI,
Composer y acceso de Drush a la BD. Usar la instalación existente y su settings.php.
Nunca site:install, composer update, reset --hard ni una BD/config de Docker.

No editar contenido mientras se prepara la copia. Antes de importar, revisar los
cambios editoriales de producción que no estén en el commit: config:import y el
Book canónico van a aplicar los valores versionados. Conciliar primero cualquier
edición nueva del servidor; el respaldo permite conservarla, no la fusiona.

Ejecutar cada fase por separado y detenerse ante el primer error. El modo
mantenimiento debe seguir activo hasta que el despliegue termine correctamente.

```bash
set -euo pipefail
umask 077
cd /home/u985217890/domains/nelkano.com
test -f composer.json
test -f public_html/sites/default/settings.php
test "$(git branch --show-current)" = main
git status --short
test -z "$(git status --porcelain)"
git fetch origin main
read -r -p 'Pega el SHA completo del commit WEB preparado: ' release_commit
[[ "$release_commit" =~ ^[a-f0-9]{40}$ ]]
test "$(git rev-parse origin/main)" = "$release_commit"
git merge-base --is-ancestor HEAD "$release_commit"

backup_dir="$HOME/nelkano-backups/web-$(date +%Y%m%d-%H%M%S)"
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
case "$private_files" in "$PWD/public_html"|"$PWD/public_html/"*) echo 'private:// debe estar fuera del docroot'; exit 1;; esac
tar -czf "$backup_dir/files-settings.tar.gz" -- public_html/sites/default/settings.php "$public_files" "$private_files"
tar -tzf "$backup_dir/files-settings.tar.gz" >/dev/null
printf 'Respaldo: %s\n' "$backup_dir"
```

Guardar la ubicación del respaldo. No exportar configuración a config/sync después
del pull: sobrescribiría la entrega. No copiar settings.php ni credenciales al Git.
Si producción no tiene todavía Book o la portada, comprobarlo antes de ejecutar
la línea book:export del bloque anterior y omitir solo esa línea. Conservar la copia
SQL/archivos y omitir solo el book:export fallido tras comprobar su ausencia.

## 3. Actualizar y ejecutar el despliegue completo

```bash
git merge --ff-only "$release_commit"
test "$(git rev-parse HEAD)" = "$release_commit"
# El script reconstruye los EXE desde los fragmentos antes de comprobar la entrega.
bash scripts/deploy-hostinger.sh
./vendor/bin/drush --root=public_html updatedb:status
./vendor/bin/drush --root=public_html config:status
./vendor/bin/drush --root=public_html book:import content/books/nelkano-sdk.yml --dry-run
```

El script comprueba los artefactos publicados y sus SHA-256, ejecuta Composer
install desde el lock, registra los APK, aplica updates/configuración/cachés con
Drush deploy e importa el Book por UUID, con simulación previa. El dry-run final
debe indicar 23 unchanged; no deben quedar updates pendientes ni diferencias
inesperadas de configuración. No basta ejecutar únicamente drush deploy.

El APK y los fragmentos de los EXE viajan por Git normal. Cada fragmento pesa
como máximo 40 MiB. El script reconstruye los instaladores automáticamente,
comprueba sus SHA-256 y conserva exactamente los EXE originales. Solo necesita
el PHP ya utilizado por Drupal; no requiere Git LFS ni transferencias manuales.
Los EXE ensamblados están ignorados por Git y pueden regenerarse al desplegar.

No ejecutar seeds, scripts de ilustración, pruebas ni exportadores QA en el
servidor. Las HU, reportes, usuarios y módulos son contenido de BD: no vienen en
config/sync ni deben copiarse desde Docker. Los assets docs sí van en este commit.

## 4. Abrir el sitio y comprobar HTTPS

Solo después de completar la fase anterior:

```bash
./vendor/bin/drush --root=public_html state:set system.maintenance_mode 0 --input-format=integer
./vendor/bin/drush --root=public_html cache:rebuild
curl --fail --silent --show-error --output "$backup_dir/sdk-reference.json" https://nelkano.com/api/nelkano/v1/sdk/reference
test "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' https://nelkano.com/api/nelkano/v1/backlog/hu)" = 401
curl --fail --silent --show-error https://nelkano.com/api/nelkano/v1/app/releases/android-arm64
```

Revisar en escritorio y móvil /, /guia, /en/guide, /modulos, /versiones y el menú
de cuenta. El SDK español debe ser público en /es/sdk-de-nelkano, con imágenes
cargadas. Reportes/Asistente no deben aparecer en su índice ni referencia pública.
La API backlog anónima debe devolver401 y el tablero administrativo conservar sus
HU. La credencial se comprueba por GET en la siguiente fase, sin crear HU de prueba.

El canal Android debe ofrecer3.0.3-beta/código13. Los canales de actualización
Windows y SDK Studio deben devolver latest=null: esta entrega no incluye sus
paquetes MSI/ZIP. Sus descargas manuales deben ser EXE. Comprobar los tres
tamaños y SHA-256 contra release-artifacts/manifest.json.
Comprobar también las APIs de reportes/módulos con sus conectores habituales y la
redirección301 de www.nelkano.com. SteamOS permanece oculto.

## 5. Activar el backlog en Codex

Este paso lo realiza el operador en su consola privada, sin grabación/transcript.
La emisión imprime un secreto; no ejecutarla como herramienta de un chat ni
adjuntar su salida. La cuenta elegida debe estar activa y tener access content
y administer nelkano backlog. Verificar su UID real antes de emitir.

En SSH, desde la misma raíz del sitio:

```bash
set +x
read -r -p 'UID verificado de la cuenta dedicada a Codex: ' NELKANO_BACKLOG_UID
export NELKANO_BACKLOG_UID
./vendor/bin/drush --root=public_html php:eval '$uid = filter_var(getenv("NELKANO_BACKLOG_UID"), FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]); if ($uid === FALSE) { throw new \RuntimeException("UID invalido"); } echo \Drupal\nelkano_home\Service\BacklogApiCredentials::issue("codex-backlog", $uid, 90), PHP_EOL;'
unset NELKANO_BACKLOG_UID
```

Copiar el resultado directamente al prompt oculto del siguiente comando Windows,
sin guardarlo en texto plano ni enviarlo al chat. Emitir otra vez para el mismo
cliente revoca el token anterior. En PowerShell, como propietario de la sesión Codex:

```powershell
Set-Location 'C:\Users\ivanp\Desktop\Nelkano-emulator'
powershell -NoProfile -File scripts/backlog/Save-Credential.ps1
$backlogPython = Join-Path $env:USERPROFILE '.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe'
& $backlogPython -B scripts/backlog/install_connector.py
& $backlogPython -B scripts/backlog/probe_connector.py
```

Reiniciar Codex para cargar el MCP y después comprobar, en esa misma sesión Windows:

```powershell
& $backlogPython -B scripts/backlog/probe_connector.py --online
```

El probe solo consulta. Debe mostrar connection_ok=true. Los otros chats encontrarán
el procedimiento en brain/API del backlog.md y usarán check_connection, list_hu,
get_hu, create_hu y update_hu. El token queda protegido con DPAPI fuera del repo,
separado de reportes. No cambiar ni reutilizar las credenciales de reportes.

## Recuperación

Ante un fallo mantener mantenimiento y conservar el respaldo. Un rollback de Git
por sí solo no revierte updates, configuración o Book. Con la web sin escrituras,
restaurar coherentemente revisión anotada, dependencias del lock, SQL y archivos/
settings del mismo respaldo; después reconstruir cachés y verificar antes de abrir.
No importar un YAML viejo para fingir un rollback completo: el importador conserva
páginas omitidas. No borrar archivos ni forzar un pull para resolver conflictos.

No se ha ejecutado ninguna de estas fases en producción al preparar esta entrega.

