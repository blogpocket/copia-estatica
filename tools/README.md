# Herramientas auxiliares

Estos dos scripts son **opcionales** y cubren tareas que se hacen *después* de generar la copia estática con el plugin. Funcionan fuera de WordPress, sobre la carpeta de salida descargada a tu máquina local.

## `convertir-img-a-webp.sh`

Convierte todas las imágenes JPG/PNG/GIF/BMP de la carpeta `/img/` de la copia estática a formato WebP, y actualiza las referencias en los archivos `.html` para que apunten a los nuevos `.webp`.

### Por qué

WebP comprime entre un 40% y un 70% mejor que JPEG/PNG manteniendo calidad visual equivalente. En blogs con muchas imágenes esto puede ser la diferencia entre una copia que cabe en GitHub Pages (límite duro de 1 GB) y una que no.

### Requisitos

- macOS o Linux con bash.
- `cwebp` (preferido) o ImageMagick (`convert`).

Instalación:

```bash
# macOS (requiere Homebrew)
brew install webp

# Ubuntu/Debian
sudo apt install webp
```

Si no quieres instalar nada, también funciona con `convert` de ImageMagick (que suele venir preinstalado en macOS y Linux).

### Uso

```bash
chmod +x convertir-img-a-webp.sh
./convertir-img-a-webp.sh /ruta/a/copia-estatica-html
```

La calidad por defecto es 82 (buen equilibrio tamaño/calidad). Puedes ajustarla:

```bash
./convertir-img-a-webp.sh /ruta/a/copia-estatica-html --quality 75
```

El script va imprimiendo progreso cada 50 archivos, borra el original solo si la conversión fue correcta, y al final muestra un resumen con el ahorro total.

### Limitaciones

- Imágenes JPG en espacio CMYK no se convierten directamente. Si ves errores tipo `libjpeg error: Unsupported color conversion request`, conviértelas primero a sRGB con `sips` (incluido en macOS) o ImageMagick:

  ```bash
  sips -s format jpeg \
       --matchTo "/System/Library/ColorSync/Profiles/sRGB Profile.icc" \
       imagen.jpg --out /tmp/fixed.jpg
  cwebp -q 82 /tmp/fixed.jpg -o imagen.webp
  ```

---

## `reescribir-enlaces-internos.py`

Reescribe los enlaces internos de una copia estática ya generada, sin tener que volver a ejecutar el plugin en WordPress.

### Por qué

A partir de la versión 1.7 del plugin, los enlaces internos se resuelven correctamente durante la generación (incluyendo dominios históricos vía `CEL_EXTRA_INTERNAL_HOSTS`). Pero si ya tienes una copia estática generada con una versión anterior, o si has descubierto dominios históricos que olvidaste declarar, regenerar todo desde WordPress es costoso. Este script reescribe los enlaces directamente sobre los HTML existentes, sin tocar las imágenes ni la estructura.

### Requisitos

- Python 3.6 o superior. Viene preinstalado en macOS y en la mayoría de distribuciones Linux.

### Uso

```bash
python3 reescribir-enlaces-internos.py /ruta/a/copia-estatica-html
```

Por defecto reescribe enlaces a `lanzatu.blog` y `blogpocket.com`. Para usar otros dominios, edita la constante `DEFAULT_DOMAINS` al principio del script, o pásalos como argumentos:

```bash
python3 reescribir-enlaces-internos.py /ruta/a/copia midominio.com otro-historico.es
```

### Cómo funciona

1. Indexa todos los `.html` de la copia construyendo un mapa `slug → ruta_local`.
2. Recorre cada HTML buscando enlaces `href="..."` a los dominios declarados.
3. Para cada uno, intenta resolver el último segmento del path (el slug) en el índice.
4. Si no resuelve, prueba con el penúltimo segmento (cubre URLs de attachment de WordPress tipo `/post/imagen/`).
5. Si encuentra coincidencia, calcula la ruta relativa correcta desde el HTML que está modificando hasta el destino y reescribe el enlace.
6. Preserva fragmentos `#anchor` al reescribir.
7. Salta automáticamente URLs no-contenido (`/category/`, `/tag/`, `/feed/`, paths `wp-*`, etc.).

Al terminar imprime un resumen con cuántos enlaces se reescribieron y cuántos no resolubles (típicamente: feeds, tags, contenido eliminado, slugs renombrados).

### Idempotencia

El script es seguro de re-ejecutar. Los enlaces ya reescritos en pasadas anteriores son rutas relativas, no URLs absolutas, así que no entran en el patrón y no se tocan.

### Backup recomendado

Antes de ejecutar, conviene tener una copia de seguridad por si quieres revertir:

```bash
cp -r copia-estatica-html copia-estatica-html.bak
```
