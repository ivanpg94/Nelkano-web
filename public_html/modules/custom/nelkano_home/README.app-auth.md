# Sesiones y roles de la app (API v2)

La app se autentica con un **access token** corto y un **refresh token** que rota.
Las funciones del servidor comprueban el **permiso de Drupal** en cada petición.
La app recibe además sus *capabilities* **firmadas con Ed25519** y solo las usa
para decidir qué interfaz mostrar.

## Endpoints

| Método | Ruta | Uso |
|---|---|---|
| POST | `/api/nelkano/v2/auth/login` | `{email, password, device:{id, platform, name}}` → tokens + roles + capabilities + `entitlements` |
| POST | `/api/nelkano/v2/auth/refresh` | `{refresh_token, device_id}` → pareja nueva (el refresh anterior queda invalidado) |
| POST | `/api/nelkano/v2/auth/logout` | `Authorization: Bearer <access>` y/o `{refresh_token}` → revoca la sesión en el servidor |
| GET | `/api/nelkano/v2/auth/me` | `Authorization: Bearer <access>` → usuario, roles, capabilities, `entitlements` |

- Access token `nkat_…`: 1 hora. Refresh token `nkrt_…`: 30 días desde el último refresh.
- Todos los endpoints existentes (`/api/nelkano/friends`, `stream/*`, `activity`…) aceptan el access token v2.
- Los errores llevan un `code` estable. La app debe decidir por el `code` o por el estado HTTP, nunca por el texto:
  `invalid_token` y `session_expired` (401), `invalid_credentials` (401 en v2), `forbidden` (403),
  `refresh_in_progress` (409: reintentar con el token más reciente), `rate_limited` (429).
- **Reutilizar un refresh ya rotado** pasados 30 s, o desde otro `device_id`, revoca toda la sesión (detección de robo).
- Cambiar la contraseña, bloquear o borrar la cuenta revoca todas sus sesiones, también las antiguas.
- El login (v1 y v2) tiene límite de intentos (`user.flood`: 5 por cuenta+IP en 6 h, 50 por IP en 1 h) y
  devuelve el mismo error para cuenta inexistente, sin verificar o contraseña incorrecta.

La tabla `nelkano_app_session` guarda solo hashes SHA-256. El cron borra las generaciones caducadas.

## Permisos → capabilities

| Permiso de Drupal | Roles (config/sync) | Capabilities |
|---|---|---|
| `use nelkano app features` | authenticated | `settings_full`, `collections`, `friends`, `drive`, `controls_config`, `streaming`, `multiplayer`, `activity_sync` |
| `send nelkano error reports` | tester (y administrator) | `error_reports` |
| `use nelkano debug tools` | tester (y administrator) | `fps_overlay_default` |
| `nelkano premium` | premium | `premium` (todavía no desbloquea nada) |

`administrator` es `is_admin`, así que tiene todos los permisos. Para cambiar lo que da un rol,
edita sus permisos en Drupal (y exporta a `config/sync`). No hace falta publicar una versión de la app.

El servidor aplica los permisos a los endpoints: los reportes exigen `send nelkano error reports`, y amigos,
streaming, multijugador y actividad exigen `use nelkano app features`. Perfil y `me` solo exigen sesión.

## Documento `entitlements`

`base64url(JSON) + "." + base64url(firma Ed25519 del JSON)`. El JSON contiene:
`v, kid, sub (uid), device_id, roles, capabilities, iat, exp` (7 días, para que la app funcione sin conexión).

La app lleva **fijada** la clave pública. Debe descartar el documento si la firma no es válida, si ha caducado,
si `device_id` no es el suyo o si `sub` no coincide. En cualquiera de esos casos se comporta como **invitado**.

## Clave de firma

Orden de lectura de la semilla (32 bytes en base64):

1. `$settings['nelkano_app_signing_seed']` en `settings.php` (recomendado en Hostinger).
2. Variable de entorno `NELKANO_APP_SIGNING_SEED`.
3. `state` (base de datos): se genera automáticamente la primera vez. El informe de estado lo marca como aviso.

Informe de estado (`/admin/reports/status`, «Nelkano: firma de la app»): muestra la clave pública y su `kid`.

```bash
# Ver la clave pública que debe llevar la app
drush php:script web/modules/custom/nelkano_home/scripts/app-signing-key.php
# Pasar la semilla auto-generada a settings.php SIN cambiarla (imprime la línea a pegar)
NELKANO_SIGNING_ACTION=export-settings drush php:script web/modules/custom/nelkano_home/scripts/app-signing-key.php
# Cuando settings.php ya la tenga, borrar la copia de la base de datos
NELKANO_SIGNING_ACTION=forget-state drush php:script web/modules/custom/nelkano_home/scripts/app-signing-key.php
```

En producción la ruta es `public_html/modules/custom/...` con `drush --root=public_html`.
**Si la semilla cambia, las versiones de la app con la clave anterior dejarán de aceptar el documento**
y funcionarán como invitado hasta que se actualicen. No la rotes sin publicar antes una app con la clave nueva.

## Compatibilidad

Los endpoints v1 (`/api/nelkano/auth/login`, token `uid.secreto` de 30 días) siguen funcionando para las
versiones actuales de la app. Ahora renuevan la caducidad como mucho una vez por hora, en lugar de escribir
en la base de datos en cada petición. Se retirarán en la Fase 3, cuando la app v2 esté distribuida.

Todas las rutas `/api/nelkano/*` se marcan como `no_cache`, porque Drupal ve como anónimas las peticiones
autenticadas con Bearer y la page cache podría compartir respuestas entre usuarios.
