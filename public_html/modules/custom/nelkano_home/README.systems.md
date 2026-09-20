# Fichas informativas de sistemas

## Uso

En el menú lateral **Páginas editables**, abre **Sistemas**
(`/admin/nelkano/sistemas`), junto a Home. El selector ES/EN mantiene los textos de cada idioma independientes.
El único checkbox **Visible** publica u oculta el sistema en ambos idiomas. Hay 16 fichas iniciales, incluidas las familias que ya
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
y footer se conserva, con el contraste y las imágenes optimizados. Cada ficha enlaza además a su tabla de compatibilidad.

## Compatibilidad por juegos y CSV

En `/admin/nelkano/sistemas`, abre un sistema y selecciona un archivo en
**Compatibilidad (CSV)**. Se sube y publica automáticamente, sin vista previa,
confirmación ni guardado adicional. Otro CSV sustituye completamente el catálogo
de ese sistema. La antigua página intermedia redirige a Sistemas.

Cada sistema mantiene su propio catálogo. ES y EN muestran el mismo conjunto de
pruebas con etiquetas traducidas. La página pública es
`/sistemas/{system}/compatibilidad` o `/en/systems/{system}/compatibility`.
Incluye búsqueda por nombre, filtro de estado y 50 filas por página. La tabla
muestra solo Nombre, Estado, FPS, Dispositivo y Fecha prueba, sin detalles
desplegables. Un sistema despublicado no expone su tabla.

Cabecera obligatoria (el orden puede variar):

```csv
nombre,estado,fps,dispositivo,fecha prueba
```

- `nombre` y `estado` requieren valor. El resto admite vacío.
- No hace falta ID. Nombre, dispositivo y fecha identifican cada prueba dentro
  del archivo; admite el mismo juego probado en dispositivos o fechas distintos.
- Estados: `sin_probar`, `arranque_confirmado`, `gameplay_confirmado`,
  `con_incidencias`, `no_arranca`. Gameplay no implica haber completado el juego.
- FPS entre 0 y 1000, hasta tres decimales; vacío significa no medido, nunca cero.
- `fecha prueba` (con espacio) es una fecha opcional válida `AAAA-MM-DD`.
  Texto: máximo 255 caracteres por campo.
- CSV UTF-8 con o sin BOM, coma o punto y coma. Admite campos entre comillas,
  comillas interiores duplicadas y saltos de línea dentro de campos citados.
  Máximo 5 MB por archivo y 10000 registros por catálogo; el límite efectivo de
  subida también depende de `upload_max_filesize` y `post_max_size` de PHP.

La validación rechaza todo el lote si hay errores o pruebas repetidas, conservando
los datos anteriores. Un archivo válido reemplaza todos los registros: las filas
ausentes desaparecen. La subida no guarda ni descarta los textos que se estén
editando en el formulario. No hay traducción automática de nombres.

La actualización `11027` convierte los catálogos anteriores a cinco campos.
Conserva FPS gameplay si estaba medido; si no, la media automática registrada
en observaciones o FPS intro. Los estados y las filas anteriores se conservan.
El importador exige ya la nueva cabecera; no acepta columnas adicionales.

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
