# Capturas de la guía de uso — 18 de octubre de 2026

Capturas directas de Nelkano para Android **3.0.2-beta (código 12)** ejecutándose
en un emulador Android 14 (API 34), AVD x86_64 con traducción ARM. Resolución
720 × 1600 y densidad 280 dpi. Los menús de Nelkano están en español; el selector
de archivos de Android conserva algunas etiquetas en inglés.

La captura se obtuvo mediante ADB. Los WebP son conversiones sin pérdida del
PNG, con las mismas dimensiones y píxeles. Las imágenes corresponden a la app
Android en un emulador. La instalación de captura contiene los módulos incluidos
con Nelkano y juegos de demostración propios.

APK utilizado: SHA-256
`9472ce1c77f023025f18bfdafb30427991fddc18b64191373dc2e1c0afa3c191`.
Las fuentes de la app están en el repositorio `Nelkano-emulator`, en `src/nelkano/`.

## Imágenes

| Archivo | Pantalla |
| --- | --- |
| `01-importar-juegos.webp` | Selector de Android para importar el paquete de demos |
| `02-biblioteca.webp` | Biblioteca con los juegos importados |
| `03-ficha-juego.webp` | Ficha del juego y estados guardados |
| `04-partida-gba.webp` | Demo GBA ejecutándose con los controles de Nelkano |
| `05-guardados-rapidos.webp` | Espacios de guardado en los ajustes de la partida |
| `06-editor-controles.webp` | Editor de controles |
| `07-modulos.webp` | Módulos incluidos |
| `08-apariencia.webp` | Idioma y tema |
| `09-seleccionar-caratula.webp` | Elección de una captura como carátula |

## Procedencia de los juegos

El código y las recetas de construcción están en el repositorio
`Nelkano-emulator`. Estas rutas son relativas a la raíz de ese repositorio:

| Nombre en la biblioteca | Fuentes y receta |
| --- | --- |
| Pocket Signal | `test/demo/gb/NelkanoGbRomBuilder.java`, `test/demo/gb/build-trailer-demo.ps1` |
| Chroma Link | `test/demo/gbc/NelkanoGbcRomBuilder.java`, `test/demo/gbc/build-trailer-demo.ps1` |
| Nelkano Demo GBA | `test/demo/gba/main.c`, `test/demo/gba/build-trailer-demo.ps1` |
| Twin Signal | `test/demo/nds/NelkanoNdsRomBuilder.java`, `test/demo/nds/build-trailer-demo.ps1` |

Cada carpeta incluye un `README.md` con la construcción y la autoría de la demo.
Código, textos, formas y gráficos son originales de Nelkano. Las imágenes y sus
rutas versionadas son recursos de la web; las salidas de una sesión de QA no son
necesarias para servirlas.

La configuración ES/EN vive en `nelkano_home.guide`, exportada en
`config/sync/nelkano_home.guide.yml` y en los valores iniciales del módulo.
La receta `scripts/illustrate-usage-guide.php` concilia las capturas con la guía
actual mediante Drush y permite simular antes de guardar.
