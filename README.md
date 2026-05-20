# Copia Estática Local

Un plugin de WordPress que convierte tu blog en un sitio HTML estático y autocontenido: posts, páginas, imágenes y enlaces internos resueltos a rutas locales relativas.

La copia resultante se puede servir desde cualquier hosting plano (incluso desde una memoria USB), sin PHP, sin base de datos y sin dependencias. Es ideal para archivar un blog antes de migrarlo, mantener un respaldo navegable independiente de WordPress, o publicar un espejo congelado en GitHub Pages, Netlify, Cloudflare Pages o un subdominio del propio cPanel.

## De dónde viene

Este plugin nació como una herramienta ad-hoc para archivar [Blogpocket](https://blogpocket.com) después de años de publicaciones. Lo que empezó como una tarde de exportación acabó siendo un viaje con varias paradas: imágenes enterradas que reventaban la memoria de PHP, JPEGs antiguos en espacio CMYK que ninguna librería de conversión aceptaba, enlaces hardcodeados a un dominio que el blog tuvo en el pasado... cada bache se convirtió en una mejora del código.

El resultado: un plugin que no se limita a volcar HTML, sino que entiende cómo está construido WordPress, qué peculiaridades arrastran los contenidos antiguos, y cómo dejar una copia que se pueda servir tal cual desde cualquier sitio.

Si quieres la crónica completa del proceso (incluida la parte de optimizar a WebP para que la copia entre en GitHub Pages), está en el post original: [Cómo generé una copia estática de Blogpocket](https://blogpocket.com/copia-estatica-local/) *(reemplaza por tu URL real)*.

## Qué hace

- Genera un archivo `.html` por cada post y cada página, organizados en `año/mes/slug.html` y `pages/slug.html`.
- Descarga las imágenes referenciadas en el contenido a una carpeta `/img/` local, renombradas con hash MD5 para evitar colisiones.
- Reescribe los enlaces internos entre posts y páginas a rutas locales relativas. Si tu blog cambió de dominio en algún momento, los enlaces hardcodeados al dominio antiguo también se reescriben (declarándolos en una constante).
- Genera índices automáticos: raíz, por año, por mes, y de páginas.
- Aplica un CSS minimalista común a todo el archivo.
- Procesa los posts de forma defensiva: si uno falla, los demás siguen y se reporta cuál falló.
- Descarga las imágenes en streaming directo a disco, sin pasarlas por RAM: las imágenes grandes ya no son un problema.

La copia se deposita en `/wp-content/uploads/copia-estatica-html/` de tu propio servidor.

## Instalación

1. Descarga este repositorio como ZIP o `git clone`.
2. Sube la carpeta `copia-estatica` al directorio `/wp-content/plugins/` de tu WordPress.
3. Activa el plugin desde el panel de administración.
4. Encontrarás un nuevo menú **Copia Estática** en la barra lateral.

## Uso básico

En el menú **Copia Estática** tienes dos secciones:

- **Exportar Entradas (Blog)**: eliges un año y los meses que quieras incluir. Genera un `.html` por cada post publicado de ese rango.
- **Exportar Páginas (Estáticas)**: exporta de un golpe todas las páginas publicadas (Quiénes somos, Contacto, etc.).

Recomendación: si tu blog tiene años con muchos posts (o con imágenes pesadas), exporta mes a mes en lugar del año completo. El plugin va imprimiendo en `wp-content/debug.log` por dónde va, lo que ayuda a diagnosticar si algo falla.

## Constantes opcionales

Se declaran en `wp-config.php`, antes de la línea `/* That's all, stop editing! */`.

### `CEL_MAX_IMG_SIZE`

Tamaño máximo aceptable por imagen, en bytes. Las que excedan este límite se saltan y quedan registradas en el log. Por defecto, 30 MB.

```php
define( 'CEL_MAX_IMG_SIZE', 50 * 1024 * 1024 ); // 50 MB
```

### `CEL_EXTRA_INTERNAL_HOSTS`

Lista de dominios históricos del blog, separados por comas. Si tu sitio ha vivido en otros dominios antes del actual y dentro del contenido de posts antiguos hay enlaces hardcodeados a esos dominios, declararlos aquí permite al plugin reconocerlos como "internos" y reescribirlos a rutas locales.

```php
define( 'CEL_EXTRA_INTERNAL_HOSTS', 'midominio-viejo.com,otro-historico.es' );
```

Si tu blog ha vivido siempre en el mismo dominio, ignora esta constante. El plugin detecta el dominio actual automáticamente.

## Herramientas auxiliares (carpeta `tools/`)

Dos scripts opcionales que cubren tareas posteriores a la generación:

- **`tools/convertir-img-a-webp.sh`** — script bash para macOS/Linux que convierte la carpeta `/img/` entera a WebP y reescribe las referencias en los HTML. En blogs con muchas imágenes la reducción de tamaño ronda el 60-80%, suficiente para hacer la copia desplegable a GitHub Pages (1 GB de límite duro).
- **`tools/reescribir-enlaces-internos.py`** — script Python para reescribir enlaces internos directamente sobre una copia ya generada, sin tener que regenerarla en WordPress. Útil cuando se han añadido dominios históricos al `CEL_EXTRA_INTERNAL_HOSTS` después de exportar.

Más detalles en [`tools/README.md`](tools/README.md).

## Requisitos

- WordPress 5.6 o superior.
- PHP 7.4 o superior.
- Probado hasta WordPress 6.9.

## Compatibilidad con WordPress.org

El plugin pasa el [Plugin Check oficial de WordPress.org](https://wordpress.org/plugins/plugin-check/) sin errores en su versión 1.8. Incluye los archivos requeridos (`readme.txt` en inglés, cabecera con licencia, sanitización y escape estándar) por si en algún momento decides subirlo al directorio oficial.

## Licencia

[GPL v2 o superior](LICENSE). El mismo modelo que WordPress.

## Contribuciones

Issues y pull requests son bienvenidos. Si encuentras una casuística que no contempla (un permalink especial, una estructura de contenido inusual, un caso de fallo nuevo), abre un issue con el detalle y los logs si los tienes.
