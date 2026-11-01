# Analítica de visitas y descargas

## Alcance y fuentes

GA4 usa el identificador web `G-2VCPFJW0PT`. La configuración compartida vive en
`src/Controller/NelkanoPageContextTrait.php::analyticsSettings()`; `HomeController`
y el contexto de Módulos usan la misma configuración. La carga de GA y los clics
se controlan en `templates/nelkano-analytics.html.twig`.

## Visitas propias y consentimiento

La lista predeterminada de cuentas propias de producción es `[1, 3, 10, 12, 13]`.
Sólo esas cuentas autenticadas activan `exclude_internal`; el resto sigue siendo
medible con consentimiento. No se envían UID, correos ni nombres a Analytics.
La lista puede sustituirse en la configuración local del sitio, sin versionar
credenciales ni copiar `settings.php`:

```php
$settings['nelkano_analytics_excluded_uids'] = [1, 3, 10, 12, 13];
```

Al abrir una página pública con una cuenta excluida, el navegador guarda
`localStorage.nelkano_analytics_internal = '1'` antes de cargar GA. Esa exclusión
persiste después de cerrar sesión y se comunica a otras pestañas del mismo
origen. Cada navegador/dispositivo debe acceder con una cuenta propia al menos
una vez; borrar sus datos o usar otro perfil elimina esa identificación. Retirar
un UID de la lista no borra el indicador que ya guardaron sus navegadores.

El consentimiento usa `localStorage.nelkano_analytics_consent`: sólo `granted`
permite cargar GA y emitir eventos. Sin aceptación, en entornos locales o con
exclusión interna, la propiedad queda desactivada con `ga-disable-G-2VCPFJW0PT`.
Reabrir la decisión o rechazarla detiene los envíos posteriores aunque GA se
hubiera cargado antes. Esto no elimina ni corrige los datos históricos de GA.

## Contrato del clic de descarga

Evento: `nelkano_download_click`, una emisión por clic reconocido en un enlace.
El delegado lee el `href` actual, incluida la plataforma elegida en la portada.
Sólo reconoce rutas del mismo origen:

- `/descargar/{es|en}/{android|windows|steamos|sdk}/{version}`.
- La misma ruta con sufijo `/instalador`.
- Legado Android: `/descargar/{es|en}/{version}`.

| Parámetro | Valores o significado |
|---|---|
| `download_product` | `nelkano` o `sdk_studio` |
| `download_platform` | `android`, `windows` o `steamos`; catálogo `sdk` corresponde a SDK Studio para `windows` |
| `download_version` | Versión exacta del enlace, incluida la etiqueta de beta/alpha |
| `link_url` | Origen y ruta, sin parámetros de consulta ni fragmento |

El evento mide intención de descarga: no acredita descarga terminada, instalación
ni primera ejecución. No cubre enlaces directos abiertos fuera de una página con
este script, las APIs ni el actualizador. Las visitas rechazadas o excluidas no se
cuentan. Los handlers históricos `data-analytics-event` se conservan como respaldo.

## Configuración en GA4 y despliegue

Registrar `download_product`, `download_platform` y `download_version` como dimensiones
personalizadas de alcance **Evento**, con el nombre exacto del parámetro. Estos
ajustes viven en GA4 y no forman parte de `config/sync` de Drupal. Las dimensiones
pueden tardar 24–48 horas en estar disponibles en informes después de crearlas y
recibir datos; no reconstruyen clics antiguos que no se midieron.
[Referencia oficial de Google](https://support.google.com/analytics/answer/14239696).

Los tres archivos PHP/Twig anteriores son fuentes del módulo y deben conservarse
en la siguiente entrega versionada. Si producción tiene ediciones manuales,
conciliarlas con Git antes de la receta de despliegue: un árbol remoto modificado
no se debe sobrescribir ni descartar. Reconstruir la caché de Drupal después de
publicar cambios de plantilla. Las respuestas personalizadas de Home, documentos
y páginas legales usan `no-store, private` para evitar compartir el indicador de
una cuenta entre visitantes.
