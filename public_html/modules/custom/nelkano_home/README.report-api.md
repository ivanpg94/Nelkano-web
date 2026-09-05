# API de reportes para el PC (v1)

Entrega contenido `nelkano_error_report` y sus adjuntos privados y permite cambiar
su estado. No descarga ROMs, no ejecuta agentes y no implementa un cliente PC.

Estados: `new` (Nuevo), `in_progress` (En proceso), `resolved` (Resuelto) y
`rejected` (Descartado). La API lista solo los nuevos. Tras guardar y verificar
la descarga, el PC confirma la recepción y la web pasa el reporte a En proceso.
La automatización lo marca posteriormente como Resuelto o Descartado.

## Aplicar en una instalación existente

Desde PowerShell, en `C:\Users\ivanp\Desktop\nelkano-web`:

```powershell
docker compose exec -T drupal vendor/bin/drush updatedb -y
docker compose exec -T drupal vendor/bin/drush cr
```

La actualización 11018 configura los cuatro estados y migra el antiguo
`reviewing` a `in_progress`, también en las revisiones existentes.
La actualización 11017 activa los 25 campos en el formulario y la presentación
predeterminados del contenido. Slot/captura se muestran como enlaces protegidos.
Los archivos siguen siendo archivos privados referenciados por URI, no entidades
`file` gestionadas. Se conservan los datos y adjuntos de los reportes existentes.

## Credencial exclusiva para el PC

La API no acepta la contraseña del administrador, cookies de Drupal ni tokens
de jugadores. Cada credencial permite leer **todos** los reportes y confirmar su
recepción para ese PC y cambiar el estado; no permite editar otros campos ni
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
| PATCH | `/api/nelkano/v1/error-reports/{id}/status` | Cambia únicamente el estado |

El listado devuelve `items`, `next_after_id` y `has_more`. Cada elemento incluye
ID, UUID, título, creación, modificación, estado, sistema y `detail_url`.
Los enlaces son relativos al mismo servidor, sin claves ni rutas de disco.

El detalle devuelve:

- Identidad, revisión, título, pasos, fechas y usuario (`uid`, email).
- `metadata`: todos los campos `field_report_*` salvo las dos URIs privadas;
  se elimina el prefijo del nombre. Incluye `settings` como texto JSON original,
  logs, ROM, core, versión/build, dispositivo, resultados y slot.
- `attachments.state` y `attachments.screenshot`: nombre, tamaño en bytes,
  SHA-256 calculado sobre el archivo real y URL de descarga. Captura ausente: `null`.
- `manifest_sha256`: identificador opaco de esta versión del manifiesto, a devolver
  sin recalcularlo. Cambia cuando cambian los datos o los adjuntos; excluye estado,
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
estado actual. Un reintento nunca devuelve un Resuelto/Descartado a En proceso.
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

## Cambiar el estado desde la automatización

```http
PATCH /api/nelkano/v1/error-reports/3/status
Authorization: Bearer <credencial de la automatización>
Content-Type: application/json

{"status":"resolved"}
```

Para descartar: `{"status":"rejected"}`. También admite `new` para devolverlo
a la cola y `in_progress` para ajustarlo manualmente. Rechaza otros estados y
otros campos. Puede cambiarse asimismo desde el formulario de edición Drupal.

Respuesta: `{"api_version":1,"id":3,"uuid":"…","status":"resolved"}`.
Repetir el mismo estado no crea otra revisión. Cada cambio efectivo por API
crea una revisión con cliente y estado en el mensaje. La API serializa los cambios
con un bloqueo por reporte para evitar que dos confirmaciones lo acepten a la vez.
No detecta si una corrección es correcta: eso corresponde a la automatización.

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
No modifica los reportes reales ni deja un PC consumidor configurado.
