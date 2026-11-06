# API de Nelkan IA

Beta de texto para cuentas con rol `tester`. Backend Drupal en el repositorio
hermano `nelkano-web`, módulo `public_html/modules/custom/nelkano_ai`.
El modelo se ejecuta en `C:/Users/ivanp/Desktop/Nelkan-AI`, nunca en Hostinger.
Relacionado: [[Android/Asistente por voz]], [[API de modulos]] y
[[Android/Amigos SDK]]. No incluye voz, guías, entrenamiento ni Gold.

## Acceso y destino

Base de producción: `https://nelkano.com/api/nelkano/v1/ai`.
Base local: `http://localhost:8088/api/nelkano/v1/ai`.
Jugadores: Bearer v2 `nkat_…` de la sesión nativa, cuenta activa y rol Tester
comprobados en cada operación. El SDK no recibe el token. No se admiten cookies
como sustituto, ni se considera suficiente tener instalado el módulo.
PC: credencial exclusiva `nkaw_…`, separada de jugadores/reportes/backlog,
emitida desde `/admin/config/services/nelkan-ai` por un administrador.
Caduca a los 90 días; emitir otra para el mismo PC revoca la anterior.
No copiar claves a documentación, chat, paquete del módulo ni logs.

## Llamadas del módulo

| Método | Ruta relativa | Resultado |
| --- | --- | --- |
| GET | `/status` | `online`, `model`, `model_revision` |
| POST | `/questions` | Crea o recupera una consulta con `request_id`, `question`, `history` |
| GET | `/questions/{id}` | Estado y respuesta exclusivamente del propietario |
| POST | `/questions/{id}/cancel` | Cancela con cuerpo `{}`; descarta entregas posteriores |

Ejemplo de entrada: `{"request_id":"example-question-0001","question":"Hola, ¿quién eres?","history":[]}`.
`request_id`: 16–64 letras, números, `_` o `-`, único por pregunta/cuenta.
Repetirlo con la misma entrada devuelve la misma consulta; cambiar su entrada
produce 409. No reintentar con otro identificador por un fallo de red.
Pregunta: 1–4000 caracteres. Historial opcional: hasta ocho mensajes alternos
usuario/asistente, por parejas, 8000 caracteres en total. No admite `system`.

Respuesta: `api_version:1`, `ok:true`, `job:{id,state,model,model_revision,answer,error,expires_at}`.
Estados: `queued`, `running`, `complete`, `cancelled`, `failed`.
Una pendiente por usuario, ocho globales y una ejecución global.
Plazo de respuesta: 180 s; retención máxima: 600 s desde la creación.
Al terminar/cancelar/fallar se retiran pregunta e historial; respuesta hasta su
caducidad. Limpieza al operar y mediante cron. Datos privados, sin caché.

## Llamadas del PC

| Método | Ruta relativa | Uso |
| --- | --- | --- |
| POST | `/worker/heartbeat` | `{ready,model,model_revision}`; disponibilidad durante 30 s |
| POST | `/worker/claim` | `{}`; devuelve `job:null` o pregunta/historial/id/lease/model_revision/deadline |
| GET | `/worker/questions/{id}` | Comprueba cancelación o retirada de permiso durante la generación |
| POST | `/worker/questions/{id}/result` | `{lease,answer,model_revision,error?}` |

El PC consulta cada cinco segundos. La concesión privada vincula la entrega
al trabajo reclamado. El servidor no acepta respuestas de otro trabajador ni
de una revisión distinta. La retirada del rol impide recoger/entregar datos.
Una entrega ya completada puede repetirse exactamente; cambiarla produce 409.
La cancelación y el vencimiento descartan respuestas tardías. No se repiten
automáticamente generaciones abandonadas. `error`: vacío, `compute-busy` o
`model-unavailable`; respuesta de hasta 12000 caracteres.

## Errores

Errores JSON: `api_version:1`, `ok:false`, `code`.
400 entrada inválida; 401 sesión/clave inválida; 403 falta Tester/concesión;
404 inexistente, ajena o caducada; 409 conflicto; 413 cuerpo >48 KiB;
415 formato distinto de JSON; 429 cola/límite; 503 `pc-offline`; 500 fallo interno.
Límite por identidad: 90 solicitudes/minuto para jugadores, 120 para PC.
No hay consultas continuas desde el módulo sin una pregunta pendiente.

## Estado y pruebas

2026-11-05: sintaxis PHP y 35 comprobaciones Drupal
correctas con usuarios efímeros y PC simulado, revertidas al terminar.
Receta: `NELKANO_LOCAL_AI_TEST=1 vendor/bin/drush --uri=http://localhost:8088 php:script web/modules/custom/nelkano_ai/tests/api-smoke.php`.
Cubren acceso, aislamiento, duplicados, cola, concesiones, cancelación,
revocación, cambio de modelo, vencimiento y limpieza.
API publicada en `https://nelkano.com/api/nelkano/v1/ai`, commits web
`a2f00bd12e8858f8b91bbb46aec073b69dbf370f` y
`23d9e1f45c3a55b52b80403f986657d4445037b9`. El despliegue general se detuvo
antes de mantenimiento por un ZIP SteamOS previo ausente; se desplegó sólo
la API con `bash scripts/deploy-hostinger.sh --nelkan-ai-only`, sin importar
versiones ni publicar aplicaciones. Recibo: `outputs/nelkan-ai/web-deploy-api-only.log`.

Recorrido real PC → HTTPS producción → Qwen3.5-9B Q4_K_M → web → PC correcto:
pregunta `24e8c99a-a60e-45e9-9270-c80858fada61`, revisión `qwen35-9b-initial`,
respuesta `NELKAN_PRODUCCION_OK_75291`, 13,053 s en este caso aislado.
También se rechazó una cuenta sin Tester. Usuarios temporales y sus preguntas
retirados. Recibo: `C:/Users/ivanp/Desktop/Nelkan-AI/outputs/production-real-model.json`.
No mide calidad de conversación ni equivale a la prueba física Android.

PC consulta cada cinco segundos sin puertos de entrada. Clave PC cifrada con
DPAPI del usuario, separada de las sesiones de la app. Cambio de revisión y
vuelta al anterior comprobados con dos nombres de los mismos pesos, sin tocar
el módulo: `C:/Users/ivanp/Desktop/Nelkan-AI/outputs/model-switch.json`.
La conservación de una generación en curso al cambiar revisión se prueba en
`C:/Users/ivanp/Desktop/Nelkan-AI/test/relay.test.mjs`; no se compararon dos
modelos diferentes. Estado de la entrega y Redmi en [[Android/Asistente por voz]].

## Despliegue acotado

La API se activa con `bash scripts/deploy-hostinger.sh --nelkan-ai-only`.
Esta opción instala sólo `nelkano_ai` y comprueba su tabla y rutas.
No importa configuración global ni toca descargas o registros de versiones.
El despliegue general sin argumentos conserva su flujo.
## actions-v1

Protocolo opcional negociado mediante heartbeat/status.protocols. Mantiene los clientes de texto. Contexto y recibos temporales en actions_data; awaiting_tools libera el generador. POST questions/{id}/tool-results valida propietario, ronda y llamadas, con reintentos idempotentes. Máximo cuatro generaciones, 180 s por generación y TTL total de 600 s. La web nunca ejecuta acciones de un dispositivo. Migración: nelkano_ai_update_10001. Pruebas locales: tests/api-actions-smoke.php (incluye el contrato anterior).

Las preguntas de acciones conservan el modelo de su primera generación durante las rondas. La espera en cola usa el TTL de diez minutos; el plazo de 180 segundos comienza al recoger cada generación. Un timeout se distingue de un modelo ausente.
