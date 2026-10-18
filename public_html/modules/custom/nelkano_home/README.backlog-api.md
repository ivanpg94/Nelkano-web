# API privada del backlog para Codex (v1)

Preparada para subir; no desplegada por esta tarea. La API amplía el backlog
existente de HU y reutiliza HuWorkflow para crear, editar, asignar sprints y mover
estados con la misma numeración, privacidad y revisión del tablero.

La guía de uso por otros chats está en
`Nelkano-emulator/brain/API del backlog.md`; el MCP local está en
`Nelkano-emulator/scripts/backlog/`. No requiere cambiar el conector de reportes.

## Rutas

Base de producción: `https://nelkano.com/api/nelkano/v1/backlog/hu`.
Para pruebas Docker: `http://localhost:8088/api/nelkano/v1/backlog/hu`.

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | base | Lista todas las HU paginadas; after_id=0, limit=25 (1–100), status y sprint opcionales. |
| POST | base | Crea una HU; 201, Location, ETag y detalle completo. |
| GET | base/{id} | Lee la HU y su ETag de revisión. |
| PATCH | base/{id} | Actualiza los campos enviados; 200, ETag y detalle actualizado. |

`id` es el ID del nodo Drupal, distinto del número de HU. El listado devuelve
api_version, capabilities, statuses, items, next_after_id y has_more. Recorrer
el cursor hasta has_more=false. sprint=none selecciona HU sin sprint; sprint=0
selecciona Sprint 0; status acepta solo los cuatro estados de HU.

POST requiere title (1–255 caracteres) y description (1–20000 caracteres de texto
plano). Los campos no admiten solo espacios. Opcionales: status (new por defecto)
y sprint (null por defecto). Estados: new, in_progress, blocked, resolved.
Sprint admite todo el rango entero con signo de 32 bits, incluido cero.

PATCH requiere al menos uno de title, description, status o sprint. Omitir
conserva el valor; sprint:null desasigna. Puede combinar edición, sprint y estado
atómicamente. No admite propiedades desconocidas ni establecer número, autor,
publicación o referencias de taxonomía. No hay borrado mediante esta API.

Enviar Content-Type: application/json, objeto JSON y como máximo 262144 bytes.
PATCH exige If-Match con el ETag obtenido del último GET, por ejemplo:

```http
PATCH /api/nelkano/v1/backlog/hu/123
Authorization: Bearer <credencial privada del conector>
Content-Type: application/json
If-Match: "456"

{"sprint": 2, "status": "in_progress"}
```

Cada detalle devuelve api_version, id, uuid, number (texto como 00042), revision_id,
title, description, status, sprint, created y changed. Las modificaciones efectivas
crean revisiones con la cuenta de la credencial como autora. Un PATCH idéntico no
crea revisiones. La API comparte el bloqueo de HuWorkflow con el tablero propio;
los formularios genéricos de nodos y scripts externos están fuera de ese bloqueo.

## Acceso exclusivo del conector

Todas las rutas autentican Authorization: Bearer con un token dedicado nkb_.
No aceptan cookies Drupal, tokens Android, credenciales nkr_ de reportes ni token
en la URL. Las respuestas usan private, no-store, Vary: Authorization y nosniff.
No se añaden permisos públicos ni acceso para los roles de edición o jugadores.

BacklogApiCredentials::issue(client, uid, days=90) exige una cuenta Drupal activa
con access content y administer nelkano backlog. days admite 1–365; client admite
1–64 letras, dígitos, guion o guion bajo. La vigencia y ambos permisos se vuelven a
comprobar en cada solicitud. Bloquear/borrar la cuenta o revocar permisos retira
el acceso. El servidor conserva solo SHA-256 del token en key/value separado.

El operador emite la credencial mediante Drush en un canal privado, utilizando
la clase Drupal\nelkano_home\Service\BacklogApiCredentials y el UID verificado de
la cuenta dedicada a Codex. No ejecutar una emisión que exponga el resultado en
salidas de herramientas del agente, chats, capturas o logs compartidos.
Emitir para el mismo client rota su credencial; revoke(client) la revoca.

En Windows, Save-Credential.ps1 del conector recibe el secreto interactivamente
y lo guarda con DPAPI, bajo LOCALAPPDATA y separado de reportes. Su helper interno
lo entrega solo al proceso MCP. No copiarlo al repositorio, config.toml, argumentos
ni variables de herramientas. El MCP ofrece check_connection, list_hu, get_hu,
create_hu y update_hu sobre un origen fijo HTTPS y sin redirecciones.
La exclusividad depende de custodiar ese token: una cabecera Bearer no permite
identificar por sí sola qué programa la presenta.

## Subida

En una instalación donde el backlog ya funciona no hace falta update_N, cambiar
esquema, importar configuración global ni guardar de nuevo HU existentes.
Subir src/Controller/BacklogApiController.php, src/Service/BacklogApiCredentials.php
y las cuatro entradas backlog_api_* nuevas de nelkano_home.routing.yml. Conservar
los demás cambios/rutas del servidor y reconstruir la caché:

```sh
vendor/bin/drush --root=public_html cache:rebuild
```

Activar después la credencial y el MCP según brain. Comprobar check_connection
(solo GET) y consultar una HU existente. No ejecutar fixtures de escritura ni
crear HU sintéticas en producción para comprobar el despliegue.

## QA local

```powershell
docker compose exec -T -e NELKANO_LOCAL_BACKLOG_API_TEST=1 drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/backlog-api-smoke.php
```

El test exige loopback y la BD Docker nelkano_drupal. Crea únicamente entidades y
credenciales sintéticas, las limpia en finally y compara hashes de los datos y
revisiones preexistentes. La secuencia HU permanece monotónica y consume números.
Cubre scopes, permisos, caducidad, revocación, tipos JSON, límites Unicode,
privacidad, filtros, paginación, sprints, estados, revisiones y bloqueos.

Errores: 400 petición inválida; 401 credencial no válida; 404 HU ausente u otro
bundle; 409 bloqueo; 412 revisión obsoleta; 413 cuerpo excesivo; 415 Content-Type;
428 falta If-Match. Ante 409/412 releer y conciliar, sin forzar. No repetir POST
tras una respuesta perdida sin verificar primero si se creó la HU.
