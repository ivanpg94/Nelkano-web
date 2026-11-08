# Fichas informativas de sistemas

## Uso

En el menú lateral **Páginas editables**, abre **Sistemas**
(`/admin/nelkano/sistemas`), junto a Home. El selector ES/EN mantiene los textos de cada idioma independientes.
El único checkbox **Visible** publica u oculta el sistema en ambos idiomas. Hay 15 fichas iniciales, incluidas las familias que ya
figuraban en la home. Cada ficha tiene una ruta fija y un enlace de vista previa.

- **Nombre en la home, estado y descripción de la tarjeta** alimentan Estado actual.
- **Título, presentación, descripción, funciones, limitaciones, pasos y FAQ**
  alimentan la ficha pública. Las listas admiten un punto por línea.
- **Textos comunes y botones** permite editar títulos de sección, etiquetas y
  «Más información» para todas las fichas del idioma seleccionado.
- **Visible** controla la ficha, su enlace y su presencia en el sitemap en ES/EN.
  Una ficha despublicada devuelve 404.
- **Mostrar tarjeta en Estado actual** permite retirar solo la tarjeta sin
  despublicar su página. Los campos opcionales vacíos se omiten en el front.

Las rutas son `/sistemas/{id}` y `/en/systems/{id}`. El acceso se realiza desde
«Más información» en las tarjetas de la home. El breadcrumb y «Todos los
sistemas» vuelven a esa sección. Los sistemas relacionados se seleccionan en el editor y excluyen páginas
despublicadas. **Añadir sistema** crea una ficha vacía para ambos idiomas, oculta
hasta publicarla. Ocultar conserva los textos, imágenes y catálogos CSV.

## Implementación y activación

El 2026-10-12 se añadieron **PlayStation Portable** (`playstation-portable`) y
**Nintendo GameCube** (`nintendo-gamecube`), con textos completos ES/EN, pasos,
FAQ, requisitos de formato y limitaciones contrastadas con los adaptadores.
Ambas tienen `enabled: false` y `show_on_home: false` en los dos idiomas. Las 16
fichas existentes, incluidas las ocultas, conservan sus valores.
La actualización **11040** añade únicamente las traducciones que falten y no
sobrescribe fichas existentes ni su visibilidad. Utilizan las rutas y el editor
habituales; la ilustración genérica se puede sustituir desde la pestaña Imagen.
Actualizaciones 11040 y 11041 aplicadas y exportadas en Drupal local el
2026-10-12. Por indicación posterior de Ivan, 3DS, Switch, SNES, PSP y GameCube
quedan ocultos; se retiran las fichas separadas GBC, MV/MZ y 2000/2003. Las
demás fichas conservan sus valores exactos. 11041 conserva los textos de las
fichas ocultadas; las fichas retiradas no se recrean al migrar tarjetas antiguas.
Quedan 15 fichas ES/EN, cinco ocultas. Las fichas ocultas/retiradas devuelven 404;
115 checks de páginas correctos. Sin despliegue en producción.

`nelkano_home.systems` es la única fuente de las tarjetas y fichas. Se edita
mediante su propio formulario Sistemas, con los mismos permisos y protección CSRF
que las demás páginas editables. Home ya no contiene este editor. Ambos
formularios guardan configuraciones independientes; mover el editor no cambia los textos.
`status_items` queda como dato legado para la migración, sin segundo editor.
Los textos se escapan con Twig y no admiten HTML ejecutable.

La actualización **11026** crea la configuración una sola vez. Conserva el orden
y los textos de las tarjetas existentes y añade los sistemas restantes. Puede
ejecutarse otra vez sin sobrescribir textos editados. En una instalación nueva
se utiliza `config/install/nelkano_home.systems.yml`.

Activación en Docker local:

```sh
docker exec nelkano-drupal vendor/bin/drush updatedb -y
docker exec nelkano-drupal vendor/bin/drush cache:rebuild
```

Como las demás páginas editables del proyecto, el contenido vive en configuración
Drupal. Antes de desplegar con importación de configuración hay que conciliar la
copia `config/sync` con los textos editados en el sitio de destino: importar una
copia antigua puede sobrescribirlos. No exportar toda la configuración de una
instalación diferente para actualizar estas fichas.

La home y las fichas responden con `no-store, private` para que guardar un texto
se refleje en la siguiente carga, incluso para visitantes anónimos, y para no
compartir la cabecera de una sesión autenticada. La presentación pública usa `redesign.css` y las ilustraciones SVG extraídas
de Figma, con imágenes opcionales editables por sistema. El diseño de cabecera
y footer se conserva, con el contraste y las imágenes optimizados. El botón de
compatibilidad y su descripción sólo aparecen en la ficha cuando el sistema tiene
al menos un catálogo CSV con registros o experiencias públicas, tanto en ES
como en EN. Se muestran automáticamente tras la primera importación válida o
la primera valoración publicada.

## Compatibilidad por juegos y CSV

En `/admin/nelkano/sistemas`, abre un sistema y la pestaña **Compatibilidad**.
**Añadir CSV** permite seleccionar uno o varios archivos. Cada uno crea una lista
con título editable: por ejemplo Game Boy y Game Boy Color, NES y Famicom,
Master System y Game Gear, o Neo Geo Pocket y Neo Geo Pocket Color.
Las flechas cambian el orden; la primera lista se abre por defecto. Puedes
sustituir el archivo de una lista o eliminarla conservando al menos una.
**Guardar compatibilidad** publica todos los cambios juntos, después de validar
todos los CSV. Un archivo incorrecto o una edición concurrente no modifica ninguna
lista. Hasta guardar, los cambios permanecen en el navegador y se avisa al salir.
La antigua página intermedia redirige a Sistemas.

Cada ficha mantiene hasta 20 listas independientes, con títulos compartidos
entre ES y EN. Los catálogos anteriores se conservan completos como una sola lista
«Catálogo actual», renombrable; no se atribuyen sus juegos automáticamente a una
consola. ES y EN muestran las mismas pruebas con etiquetas de estado traducidas. La página pública es
`/sistemas/{system}/compatibilidad` o `/en/systems/{system}/compatibility`.
Con más de una lista aparecen pestañas; con una sola se omiten.
`?lista={id}` selecciona una lista; búsqueda, limpieza de filtros y paginación
conservan esa selección. Al cambiar de pestaña se reinician filtros y página.
Incluye búsqueda por nombre, filtro de estado y ocho resultados por página.
Todos los sistemas comparten una lista compacta de Juego, Estado y Velocidad.
Cada fila se despliega con teclado o ratón para consultar las observaciones,
dispositivo, fecha y versión originales. No hay vista de fichas ni carrusel.
El resumen cuenta juegos únicos, pruebas realizadas y registros por estado;
No probado no cuenta como prueba. La cabecera no muestra el bloque global de
dispositivo, versión y última prueba; esos datos permanecen en cada prueba.
La etiqueta corta del sistema tiene ancho automático y no se divide en líneas.

### Comunidad y módulo Valorar

El módulo público opcional `nelkano.ratings` envía experiencias autenticadas por
cuenta a `/api/nelkano/v1/compatibility/resolve` y `/rate` (POST JSON, Bearer nativo
v2, sin cookies como alternativa ni rol Tester). Declara completado/en curso/
bloqueado, problemas y comentario; dispositivo, plataforma y versión proceden
del host. Los reintentos idénticos son idempotentes y una edición obsoleta da 409.
El contador de completados cuenta usuarios distintos, no versiones o envíos.

`nelkano_home_update_11048` crea las tablas `nelkano_compat_game`,
`nelkano_compat_variant` y `nelkano_compat_rating`. Son contenido de BD y no se
exportan con configuración. El borrado de cuenta retira las valoraciones y las
identidades provisionales sin uso; el catálogo editorial se conserva.

Las columnas CSV opcionales `sistema_id`, `juego_id` y `rom_sha256` añaden
identidad permanente. `juego_id` admite minúsculas, dígitos y guiones, hasta 80
caracteres; no se cambia al corregir el nombre comercial. `rom_sha256` es la
huella hexadecimal de 64 caracteres de la ROM descomprimida y requiere los otros
dos campos. Una huella identifica contenido dentro de su familia de sistemas,
también cuando un core compartido presenta GB/GBC o NGP/Color bajo un mismo ID.
Los CSV anteriores siguen funcionando; sin huella no se garantiza reconocer
automáticamente un nombre de archivo diferente. No se reescriben ni enriquecen
los CSV del usuario automáticamente.

Cada fila de la página existente agrupa el juego, sus pruebas CSV y la comunidad.
Las valoraciones sobreviven a una sustitución o retirada de CSV. Un juego nuevo
aparece en la misma lista, con Sin prueba de IA cuando sólo tiene comunidad.
El botón de acceso de la ficha se muestra si hay CSV o experiencias públicas.
Se cargan comentarios de hasta 20 experiencias sólo para los ocho juegos de la
página visible. No hay nuevas páginas públicas por valoración o juego.

Las huellas desconocidas permiten elegir un candidato o crear una ficha
provisional. Esa elección no establece una asociación global verificada.
Sistemas → Compatibilidad CSV → Experiencias de la comunidad permite confirmar
la asociación de la variante y ocultar o restaurar experiencias. La ruta de
moderación conserva permiso editorial, sesión y CSRF, con revisión esperada.
Los comentarios se muestran como texto escapado y no publican correos ni UID.

Pruebas locales: `scripts/test-compatibility-community.php` y
`scripts/test-compatibility.php`, bajo el bloqueo compartido de cómputo y con
restauración de datos. `scripts/register-ratings-local.php` registra el paquete
firmado con acceso Público únicamente en el Drupal Docker local.
Las filas sin metadatos no inventan dispositivo, fecha, velocidad ni versión.
Un sistema despublicado no expone su catálogo.

Cabecera completa recomendada (el orden puede variar):

```csv
nombre,estado,velocidad_porcentaje,dispositivo,fecha prueba,observaciones,version_nelkano
```

- Las primeras cinco columnas siguen siendo obligatorias; se aceptan también
  los CSV anteriores de cinco columnas. `observaciones` y `version_nelkano` son
  columnas opcionales e independientes: se puede incluir ninguna, una o ambas.
- `nombre` requiere valor. Un `estado` vacío anterior se normaliza a No probado. El resto admite vacío.
- `observaciones`: texto plano de hasta 4096 caracteres, con el alcance de la
  prueba y los problemas encontrados. Admite comas, comillas y saltos de línea
  citados según CSV; se muestra como texto escapado, sin interpretar HTML.
- `version_nelkano`: texto de hasta 64 caracteres con la versión de la app
  utilizada en esa prueba. No se rellena automáticamente con la versión actual.
- No hace falta ID. Nombre, dispositivo, fecha y versión de Nelkano identifican
  cada prueba; permite comparar distintas versiones en el mismo dispositivo y
  fecha. Cambiar solo las observaciones no crea otra prueba. Sin versión se
  mantiene la identidad de los CSV anteriores.
- Los mismos seis estados se usan en todos los sistemas:
  - `perfecto`: **Perfecto**.
  - `jugable`: **Jugable**.
  - `arranque_confirmado`: **Arranque confirmado**.
  - `con_incidencias`: **Con incidencias**.
  - `no_arranca`: **No arranca**.
  - `no_probado`: **No probado**.
  Se aceptan también estas etiquetas legibles al importar. Los CSV y catálogos
  anteriores se normalizan: estado vacío/sin_probar → no_probado;
  gameplay_confirmado → jugable; con_incidencias se conserva.
  Bloqueo/fallo_grafico pasan a con_incidencias; demo_grafica_observada y
  funcion_confirmada pasan a arranque_confirmado. No se asigna perfecto a partir de velocidad o ausencia
  de errores en una muestra. Las observaciones y demás datos se conservan.
  Jugable describe el recorrido probado, no una partida completa.
- `velocidad_porcentaje`: número entre 0 y 1000, hasta tres decimales, sin signo `%`;
  admite coma decimal entre comillas o con delimitador punto y coma. Vacío significa
  no medido, nunca cero. 100 es la velocidad original; 80 es el 80 % del ritmo
  normal. No es un porcentaje de compatibilidad ni garantiza fluidez o fidelidad.
  Registrar una medida de gameplay a velocidad normal, excluyendo pausas; no usar
  el pico instantáneo del indicador ni introducir FPS en este campo.
- `fecha prueba` (con espacio) es una fecha opcional válida `AAAA-MM-DD`.
  Los otros campos de texto mantienen el máximo de 255 caracteres.
- CSV UTF-8 con o sin BOM, coma o punto y coma. Admite campos entre comillas,
  comillas interiores duplicadas y saltos de línea dentro de campos citados.
  Máximo 5 MB por archivo y 10000 registros por catálogo; el límite efectivo de
  subida también depende de `upload_max_filesize` y `post_max_size` de PHP.

La validación rechaza todo el lote si hay errores o pruebas repetidas dentro de
un CSV, conservando los datos anteriores. Un archivo válido reemplaza los registros
de su lista: las filas ausentes desaparecen. Un mismo juego puede aparecer en
listas distintas. El guardado no modifica los textos que se estén editando en el
formulario. No hay traducción automática de nombres.

Las listas se almacenan en key-value `nelkano_home.compatibility_lists`, con
identificador estable, título, nombre del archivo y registros. La colección
anterior `nelkano_home.compatibility` se lee como alternativa hasta el primer
guardado y se conserva intacta. El endpoint POST `/admin/nelkano/sistemas/{system}/csv`
mantiene permiso editorial, sesión y CSRF; valida los archivos antes de escribir,
usa el bloqueo por sistema y compara la revisión completa para evitar pérdidas
entre editores. No requiere actualizaciones de BD ni importación de configuración.

La actualización `11046` sustituye FPS por velocidad. Conserva cada juego,
estado, dispositivo y fecha, deja la velocidad histórica sin medir y archiva
el catálogo original con FPS en la colección privada `key_value`
`nelkano_home.compatibility_legacy_fps`, una copia por sistema, sin sobrescribirla
al repetir la migración. No convierte FPS a porcentajes. La normalización de
lectura aplica la misma regla a catálogos sin migrar. La actualización anterior
`11027` utiliza también esta normalización y conserva el archivo de FPS.
La actualización `11047` aplica los seis estados a los catálogos existentes,
sin borrar filas ni sustituir observaciones. La lectura normaliza también un
catálogo anterior sin necesidad de volver a cargar su CSV.
El importador exige las cinco columnas base de velocidad y admite las dos
columnas opcionales. Rechaza archivos con `fps`, cabeceras repetidas o columnas
desconocidas; un rechazo no modifica el catálogo existente. Los registros ya
guardados reciben los campos nuevos vacíos al leerse, sin inventar observaciones
ni versiones. No se recuperan automáticamente metadatos del archivo histórico.

La página calcula automáticamente juegos únicos, número de pruebas y última
fecha registrada a partir de las pruebas publicables, independientemente de los filtros.
El resumen omite la última fecha si todos los registros carecen de ella.
Estado y velocidad se muestran por separado. La barra de distribución cuenta
registros por estado; no es un porcentaje de compatibilidad del core. El formato público de velocidad usa un decimal y el signo %.

Los catálogos se guardan como contenido en la colección `key_value`
`nelkano_home.compatibility`, con una clave por sistema, separada de la
configuración exportable. Se incluyen en la copia de seguridad de la base de
datos. Una importación usa bloqueo por sistema y una sola escritura para evitar
actualizaciones parciales. El CSV subido no se almacena como archivo público.
El endpoint POST de subida exige sesión autenticada, el permiso de edición
existente y un token CSRF válido en la cabecera X-CSRF-Token.

El CSV de ejemplo está en `assets/compatibilidad-ejemplo.csv`. Su generador
`scripts/build-compatibility-example.mjs` utiliza `@oai/artifact-tool` del runtime
de dependencias de Codex (no requiere instalarlo en la aplicación Drupal).

Pruebas con restauración de datos al finalizar, únicamente en Docker local:

```sh
docker exec nelkano-drupal vendor/bin/drush php:script scripts/test-compatibility.php
```

## Contenido inicial y comprobación

La estructura sigue la ficha de Game Boy del Figma Make facilitado por Ivan:
breadcrumb, presentación, formatos, funciones, limitaciones, pasos, FAQ y ficha
lateral. Se han usado textos prudentes basados en `brain/Cores` del repositorio
Nelkano-emulator, consultado el 2026-09-17. Los estados anteriores de las tarjetas
se conservan; no se recalculan automáticamente a partir de resultados de QA.

Prueba de integración, **solo Docker local**, con restauración de contenido:

```sh
docker exec nelkano-drupal vendor/bin/drush php:script scripts/test-system-pages.php
```

Verifica las 32 rutas, enlaces de la home, idiomas, cambios visibles para
visitantes anónimos, escape de HTML, listas/FAQ, migración repetible y
despublicación. Se comprobó además un guardado real desde el navegador y su
reflejo en home/ficha; el texto temporal se restauró. Diseño revisado en escritorio
y a 390 px, incluyendo desplegables de FAQ.

## SEO y formularios

Los metadatos se editan en **Páginas editables → SEO**, incluida cada ficha de
sistema y el índice. La configuración se guarda en `nelkano_home.seo` y utiliza
los valores anteriores como fallback cuando no hay una personalización.
Las secciones administrativas comienzan plegadas y agrupan campos cortos en
columnas; en móvil pasan a una columna. Los cambios de despliegue y su ensayo
están documentados en el README del repositorio.
