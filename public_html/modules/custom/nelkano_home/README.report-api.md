# API de reportes para el PC (v1)

Entrega contenido `nelkano_error_report` y sus adjuntos privados y permite cambiar
su estado y observaciones. No descarga ROMs, no ejecuta agentes y no implementa un cliente PC.

Estados: `new` (Nuevo), `in_progress` (En proceso), `resolved` (Terminado),
`rejected` (Descartado) y `blocked` (Bloqueado). Se conserva la clave `resolved`
para compatibilidad con los clientes y datos existentes; cambia su etiqueta.
La API lista solo los nuevos. Tras guardar y verificar
la descarga, el PC confirma la recepción y la web pasa el reporte a En proceso.
Al finalizar el examen de **cada reporte**, el cliente actualiza ese ID sin
esperar a que terminen los demás: Terminado si corrigió y validó el error,
Descartado si verificó que no era un error, o Bloqueado si no pudo reproducirlo
o completar su resolución, con el motivo en Observaciones. La API ya admite
estas transiciones individuales; este cambio no modifica ni ejecuta el cliente.

## Aplicar en una instalación existente

Desde PowerShell, en `C:\Users\ivanp\Desktop\nelkano-web`:

```powershell
docker compose exec -T drupal vendor/bin/drush updatedb -y
docker compose exec -T drupal vendor/bin/drush cr
```

La nueva actualización **11019** añade Bloqueado, cambia la etiqueta Resuelto a
Terminado sin alterar su valor `resolved`, y crea el campo de texto plano
`field_report_observations` (Observaciones). Lo expone en formulario, detalle,
listado administrativo y la vista de exportación existente, sin generar archivos.
El motivo es obligatorio para Bloqueado tanto en la API como en el formulario.
La actualización modifica sólo los componentes del flujo, sin reemplazar las
personalizaciones restantes de la vista ni los datos de los reportes.
Los YAML correspondientes están incluidos en `config/sync`; no exportar toda
la base local sobre la configuración de producción.

Para subir manualmente: copiar el código del módulo y los YAML modificados;
ejecutar `vendor/bin/drush updatedb -y`, la importación de configuración habitual
si se utiliza (`vendor/bin/drush config:import -y`) y `vendor/bin/drush cr` desde
la raíz del proyecto del servidor. No es necesario emitir otra credencial.
No se ha realizado despliegue desde esta modificación.

Histórico: la actualización 11018 configuraba cuatro estados y migraba el antiguo
`reviewing` a `in_progress`, también en las revisiones existentes.
La actualización 11017 activa los 25 campos en el formulario y la presentación
predeterminados del contenido. Slot/captura se muestran como enlaces protegidos.
Los archivos siguen siendo archivos privados referenciados por URI, no entidades
`file` gestionadas. Se conservan los datos y adjuntos de los reportes existentes.

## Credencial exclusiva para el PC

La API no acepta la contraseña del administrador, cookies de Drupal ni tokens
de jugadores. Cada credencial permite leer **todos** los reportes y confirmar su
recepción para ese PC y cambiar el estado y observaciones; no permite editar otros campos ni
borrar reportes. El recolector y la automatización pueden tener claves diferentes.

Crear una credencial (caduca en 90 días):

```powershell
docker compose exec -T -e NELKANO_REPORT_CLIENT=ivan-pc drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/scripts/report-api-token.php
```

El comando muestra el secreto una sola vez. Guardarlo en el almacén de secretos
del PC; no versionarlo, ponerlo en URLs, pegarlo en reportes ni enviarlo al agente.
En Drupal solo se guarda SHA-256 del token aleatorio de 256 bits, cliente y caducidad
en la colección key/value `nelkano_report_api_tokens` (no en configuración exportada).
`NELKANO_REPORT_DAYS` permite elegir entre 1 y 365 días. Repetir la emisión para el
mismo cliente rota la clave e invalida inmediatamente la anterior.

Revocar:

```powershell
docker compose exec -T -e NELKANO_REPORT_CLIENT=ivan-pc -e NELKANO_REPORT_ACTION=revoke drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/scripts/report-api-token.php
```

En hosting sin Docker, ejecutar el mismo script con Drush y esas variables de
entorno; ajustar `web/` al docroot real (`public_html/` en producción).

## Rutas

Base local: `http://localhost:8088`. **En producción usar exclusivamente HTTPS**
y configurar el servidor/proxy para rechazar acceso HTTP a la API antes de enviar
credenciales. Esta implementación permite HTTP para las pruebas locales.

Todas las peticiones requieren `Authorization: Bearer <token>`.
Respuestas y archivos llevan `Cache-Control: private, no-store`.

| Método | Ruta | Resultado |
| --- | --- | --- |
| GET | `/api/nelkano/v1/error-reports?after_id=0&limit=25` | Solo nuevos, ascendente por ID, máximo 100 |
| GET | `/api/nelkano/v1/error-reports/{id}` | Manifiesto completo y recepción del PC |
| GET | `/api/nelkano/v1/error-reports/{id}/files/state` | Slot binario |
| GET | `/api/nelkano/v1/error-reports/{id}/files/screenshot` | Captura, si existe |
| POST | `/api/nelkano/v1/error-reports/{id}/receipt` | Confirma descarga completa y pasa a En proceso |
| PATCH | `/api/nelkano/v1/error-reports/{id}/status` | Guarda estado y observaciones de ese reporte en una transacción |

El listado devuelve `items`, `next_after_id` y `has_more`. Cada elemento incluye
ID, UUID, título, creación, modificación, estado, `observations`, sistema y `detail_url`.
Los enlaces son relativos al mismo servidor, sin claves ni rutas de disco.

El detalle devuelve:

- Identidad, revisión, título, pasos, fechas y usuario (`uid`, email).
- `metadata`: todos los campos `field_report_*` salvo las dos URIs privadas;
  se elimina el prefijo del nombre. Incluye `settings` como texto JSON original,
  logs, ROM, core, versión/build, dispositivo, resultados y slot.
  `metadata.observations` contiene las observaciones de revisión, o `""` si no hay.
- `attachments.state` y `attachments.screenshot`: nombre, tamaño en bytes,
  SHA-256 calculado sobre el archivo real y URL de descarga. Captura ausente: `null`.
- `manifest_sha256`: identificador opaco de esta versión del manifiesto, a devolver
  sin recalcularlo. Cambia cuando cambian los datos o los adjuntos; excluye estado, observaciones,
  ID de revisión y fecha de modificación para permitir reintentos de recepción
  después de los cambios de estado automáticos.
- `receipt`: recepción de esa versión por ese PC, o `null`.

Los campos vacíos pueden ser `null`; la API no inventa GPU, logs u otros datos
no recopilados por la aplicación. La identidad de ROM es la que envió la app:
no equivale necesariamente a un SHA-256 completo de la ROM.

## Protocolo de descarga del futuro cliente

1. En cada sondeo empezar con `after_id=0`; solo se reciben reportes nuevos.
   Usar el UUID como identidad local del reporte.
2. Obtener el detalle y guardar su JSON. Descargar cada adjunto presente a un
   archivo temporal, sin ejecutar nada recibido.
3. Comprobar tamaño y SHA-256 **de los bytes descargados** contra `attachments`.
   Estos valores son autoritativos para transferencia, no el checksum editable
   que pueda aparecer dentro de `metadata`.
4. Guardar de forma duradera JSON y archivos; entonces enviar la recepción:

```json
{
  "manifest_sha256": "<64 caracteres hexadecimales del detalle>",
  "state_sha256": "<SHA-256 verificado del slot>",
  "screenshot_sha256": "<SHA-256 verificado de la captura, si existe>"
}
```

Enviar `Content-Type: application/json`. Si se pierde la respuesta se puede
reintentar: para el mismo PC y manifiesto se devuelve la misma recepción y el
estado actual. Un reintento nunca devuelve un Terminado/Descartado/Bloqueado a En proceso.
La recepción guarda fecha, cliente y hashes en `nelkano_report_api_receipts`;
cambia el estado del nodo a `in_progress`, sin borrar archivos. Ambos cambios
se guardan en una transacción. Es una declaración del cliente, no una
prueba de que el servidor pueda inspeccionar el disco del PC.

5. Recorrer las páginas usando `next_after_id` y `has_more`. El cursor solo se
   usa dentro de ese recorrido, **no se guarda como punto inicial del próximo
   sondeo**. Así se reintentan descargas fallidas y se recogen reportes antiguos
   que vuelvan al estado Nuevo. Confirmar recepción los saca de la lista.

Un fallo de descarga o verificación deja el reporte Nuevo. Leer el detalle o
hacer GET de un archivo no cambia el estado: el servidor no puede saber si el
PC terminó de guardarlo. La confirmación debe enviarse inmediatamente después
de guardar y verificar todos los archivos. Las rutas individuales siguen
accesibles para recuperar datos de un reporte en proceso o finalizado.

Si dos PCs descargan el mismo Nuevo a la vez, solo la primera confirmación lo
acepta; la otra recibe `409`. No hay reserva previa a la descarga ni garantía de
evitar transferencias duplicadas entre PCs. Empezar con un único recolector.

## Finalizar cada reporte desde la automatización

```http
PATCH /api/nelkano/v1/error-reports/3/status
Authorization: Bearer <credencial de la automatización>
Content-Type: application/json

{"status":"resolved","observations":"Corrección validada con el caso reportado."}
```

Para descartar porque no es un error:

```json
{"status":"rejected","observations":"El comportamiento coincide con el funcionamiento esperado del juego."}
```

Para bloquear, con el motivo obligatorio:

```json
{"status":"blocked","observations":"No se ha podido reproducir: falta la ROM exacta indicada en el reporte."}
```

`status` sigue siendo obligatorio. `observations` es texto plano de hasta
4000 caracteres Unicode, permite saltos de línea y es obligatorio y no vacío
para `blocked`. En los demás estados es opcional: omitirlo conserva el texto;
enviar `""` lo borra. No se admiten `null`, listas, números ni campos adicionales.
El endpoint admite cuerpos de hasta 65536 bytes para soportar JSON con escapes
Unicode; `/receipt` mantiene su límite de 4096 bytes. El texto se muestra escapado
en la web, nunca como HTML ejecutable.

También admite `new` para devolverlo a la cola e `in_progress` para ajustarlo
manualmente. Puede cambiarse asimismo desde el formulario de edición Drupal.

Respuesta: `{"api_version":1,"id":3,"uuid":"…","status":"blocked","observations":"No se ha podido reproducir: falta la ROM exacta indicada en el reporte."}`.
Guardar estado y observaciones es atómico y afecta sólo a ese ID. Repetir el
mismo estado y texto no crea otra revisión. Cambiar únicamente el texto sí crea
una revisión. Los recibos previos conservan su validez después de esos cambios.
Cada cambio efectivo por API
crea una revisión con cliente y estado en el mensaje. La API serializa los cambios
con un bloqueo por reporte para evitar que dos confirmaciones lo acepten a la vez.
No detecta si una corrección es correcta: eso corresponde a la automatización.
Tras cada examen, el cliente debe enviar PATCH, comprobar HTTP 200 y los valores
devueltos, y sólo entonces considerar sincronizado ese reporte. Si falla la
petición, conservar el resultado y reintentar ese ID: no declararlo actualizado
ni esperar a que termine el lote. Este repositorio proporciona el backend; el
envío automático desde el PC debe adaptarse por separado.

## Cambio masivo desde la administración

En `/admin/nelkano/error-reports`, los administradores disponen de casillas
por fila y de un selector **Nuevo estado** bajo la tabla, seguido del botón
**Aplicar a los seleccionados**. La casilla de cabecera marca la página visible;
la selección no se conserva al cambiar de página. Se admiten hasta 50 reportes
por operación y se mantienen los resultados/paginación de la View existente.

Al elegir Bloqueado aparece **Motivo del bloqueo**, obligatorio. Su contenido
se guarda en Observaciones de todos los seleccionados. Para los demás estados
se conservan las observaciones que ya tenía cada reporte. Se muestra cuántos
reportes se seleccionaron y cuántos cambiaron efectivamente.

Se requiere el nuevo permiso **Cambiar estados de reportes de Nelkano**
(`change nelkano error report status`) además del permiso de consulta. El usuario
1 y los roles administradores lo tienen automáticamente; los roles de sólo
consulta mantienen la tabla sin controles de modificación. Si se desea delegar
esta operación, conceder expresamente ambos permisos.

El formulario usa protección CSRF de Drupal y verifica los IDs contra la página
mostrada. El servicio vuelve a comprobar permisos, cantidad y tipo de contenido,
toma los mismos bloqueos por reporte que la API y guarda el lote en una
transacción. Si un reporte está ocupado o falla el guardado, no se actualiza
parcialmente la selección. Cada cambio real crea una revisión atribuida al
administrador; repetir el mismo estado/texto no genera revisiones adicionales.
No se cambian adjuntos, usuarios, descripciones originales ni recibos de descarga.

Para activar este cambio, subir el código del módulo y ejecutar
`vendor/bin/drush cr` (reconstruye servicios y permisos). No requiere otra
migración de datos; la actualización 11019 de estados/observaciones debe estar
aplicada previamente. No necesita instalar Views Bulk Operations.

Prueba de integración sólo en Drupal local, con tres reportes sintéticos que
se eliminan al finalizar, sin credenciales de API ni modificaciones a reportes
reales:

```sh
vendor/bin/drush --uri=http://localhost php:script public_html/modules/custom/nelkano_home/tests/report-bulk-smoke.php
```

En Docker local, sustituir `public_html/` por `web/` y ejecutar mediante
`docker compose exec -T drupal`. Comprueba selección, permisos, motivos,
persistencia, revisiones, idempotencia, lote incompleto, formulario y token CSRF.

## Errores y protección

- `401`: falta credencial PC, es inválida, ha caducado o fue revocada.
- `400`: parámetros fuera de rango, JSON incorrecto o hashes incompletos.
- `404`: reporte/adjunto inexistente, ilegible o fuera del directorio autorizado.
- `409`: hashes/manifiesto distintos, reporte ya no nuevo para ese PC, o cambio
  simultáneo en curso. Consultar el detalle antes de decidir si reintentar.
- `405`: método no permitido.

Los controladores autentican antes de consultar nodos o archivos; las cookies
no conceden acceso. No se aceptan rutas de archivo aportadas por el cliente.
Los adjuntos se limitan al directorio privado `nelkano-error-reports`, incluso
si un administrador modifica un campo URI. Los textos/slots son datos no fiables:
el futuro consumidor debe aislar la reproducción y no obedecer instrucciones
incluidas en el reporte. Se calculan hashes en cada detalle/descarga/recepción;
con muchos slots grandes habrá que medir y optimizar esa lectura antes de escalar.

## Prueba reproducible local

```powershell
docker compose exec -T drupal vendor/bin/drush php:script web/modules/custom/nelkano_home/tests/report-api-smoke.php
```

Crea credenciales y un reporte sintético sin ROM; los elimina en `finally`.
Comprueba widgets/formatters, auth, paginación, bytes y hashes, recepción,
ediciones, cambios de estado, cola de nuevos, reintentos, aislamiento por cliente,
rotación, revocación y rutas inseguras.
Incluye Bloqueado con motivo, límites/tipos de observaciones, persistencia,
actualización sólo del texto, atomicidad de los rechazos e idempotencia.
No modifica los reportes reales ni deja un PC consumidor configurado.

Prueba de contrato sin Drupal, red ni credenciales (PHP con mbstring):

```sh
php public_html/modules/custom/nelkano_home/tests/report-workflow-unit.php
```
