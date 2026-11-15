# Despliegue de Nelkano web

Procedimiento vigente desde el 08/11/2026, por instrucción expresa de Ivan.
Desde el repositorio de la web en producción:

```bash
git pull --ff-only origin main &&
composer install --no-dev --optimize-autoloader --no-interaction &&
./vendor/bin/drush --root=public_html deploy --yes
```

Si el código ya está actualizado, sólo hacen falta Composer y Drush. También
puede usarse `bash scripts/deploy-hostinger.sh`, que ejecuta exactamente esos
dos comandos; no se ejecuta además del bloque anterior.

No ejecutar copias de BD, archivos o configuración, exportaciones, SQL dumps,
restauraciones, empaquetadores, activaciones auxiliares ni mantenimiento manual
como parte del despliegue habitual. No usar `composer update`.

`drush deploy` ejecuta las actualizaciones pendientes, importa configuración,
reconstruye cachés y ejecuta los hooks de despliegue. La documentación del libro
que cambie se aplica mediante esos hooks, sin comandos Book separados.

Las versiones y ejecutables subidos desde administración, sus canales y la
visibilidad del SDK se conservan durante la importación mediante una
transformación en memoria. No se crean snapshots ni copias para conservarlos.
Los CSV y los ejecutables los sube Ivan directamente a producción; no se
transportan ni se publican con el despliegue de código. SDK Studio sigue oculto.

Detenerse si un comando falla y corregir la causa concreta. Añadir únicamente
el paso que requiera ese error o un cambio específico, sin recuperar las recetas
antiguas. El despliegue no reinstala Drupal ni sustituye settings.php.

Despliegue comprobado el 08/11/2026: Composer y Drush finalizaron correctamente,
esquema de nelkano_home 11048 y documentación SDK conciliada mediante deploy hook.
Se verificaron home, Versiones, compatibilidad y canales de actualización.
Las aplicaciones publicadas siguen en 3.0.6/16 hasta que Ivan suba la entrega 3.0.7.
No se hicieron copias de la BD ni se subieron ejecutables o CSV.

## Activación inicial de Comunidad

JSON:API habilita sólo las publicaciones, los comentarios de Comunidad y sus
archivos. Las rutas habituales de la web siguen gestionando el libro del SDK,
las cuentas y las descargas de módulos.

Comunidad usa el directorio privado que Drupal ya tiene configurado para las
capturas de publicaciones y conserva el acceso por audiencia de cada
publicación. Comprueba que dicho directorio vive fuera de `public_html` y que
PHP puede escribir en él.

Después del despliegue de código y configuración, genera una sola vez las
claves de Simple OAuth en un directorio absoluto fuera de `public_html` y fuera
del directorio de archivos privados de Drupal. El usuario que ejecuta PHP debe
poder leer ambas claves. Comprueba si ya existen antes de invocar el comando,
porque sustituirlas invalidaría los tokens emitidos.

```bash
umask 077
mkdir -p "$nelkano_oauth_key_dir"
./vendor/bin/drush --root=public_html simple-oauth:generate-keys "$nelkano_oauth_key_dir"
```

En el `settings.php` de ese entorno, configura las rutas absolutas a
`private.key` y `public.key` mediante las claves de configuración
`$config['simple_oauth.settings']['private_key']` y
`$config['simple_oauth.settings']['public_key']`. Protege los permisos del
directorio y deja esta configuración sólo en el entorno que usa las claves.

En Drupal, crea el consumidor **Nelkano Android Comunidad** con identificador
`nelkano-android-community`. Configúralo como cliente público, activa PKCE,
habilita los grants `authorization_code` y `refresh_token` y selecciona los
alcances `openid` y `community` para el código de autorización. Registra
`com.ivanp.nelkano.modular://community/oauth` como única redirección y deja el
consentimiento del usuario activado. Drupal conserva el consumidor como
contenido entre importaciones de configuración.

Después de reconstruir la caché, comprueba que `/oauth/jwks` sirve la clave
pública, que la autorización del cliente acepta PKCE S256 y que
`/oauth/userinfo` devuelve el UID Drupal en `sub`. Con dos cuentas, crea una
publicación con captura para amigos y verifica que abrir su URL directa exige
una amistad aceptada. Repite la lectura después de retirar la amistad para
verificar que la autorización cambia aunque el token siga vigente.

Drupal almacena las publicaciones y las capturas publicadas. Para el chat,
Drupal conserva la oferta, respuesta y candidatos ICE de cada pareja durante
120 segundos. El canal directo entre dispositivos transporta los mensajes y
los adjuntos del chat.
