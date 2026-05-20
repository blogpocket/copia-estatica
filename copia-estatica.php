<?php
/**
 * Plugin Name:       Copia Estática Local
 * Description:       Genera posts y páginas en HTML plano, descarga imágenes y convierte enlaces internos a rutas locales relativas.
 * Version:           1.8
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Antonio Cambronero Sánchez
 * Author URI:        https://blogpocket.es
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       copia-estatica
 *
 * @package CopiaEstaticaLocal
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
CHANGELOG 1.8:
- Cumplimiento de Plugin Check oficial de WordPress.org:
  * Cabecera con License y License URI (GPL v2+).
  * Comprobación de acceso directo (ABSPATH) inmediatamente después de la cabecera.
  * Validación y sanitización de $_POST con isset, absint y wp_unslash.
  * Escape de todo output con esc_attr / esc_html / wp_kses_post.
  * parse_url() -> wp_parse_url().
  * date() -> gmdate() (para no depender de timezone de runtime).
  * unlink() -> wp_delete_file().
  * rename() -> copy() + wp_delete_file() (rename no permitido por estándar WP).
  * Caché con transients para la consulta de años publicados y el slug index.
  * error_log() encapsulado en cel_log(): solo loguea si WP_DEBUG y WP_DEBUG_LOG.
  * set_time_limit / ini_set: marcados con phpcs:ignore + justificación
    (son necesarios para batches largos; sustituirlos por Action Scheduler
    sería una refactorización mayor fuera del alcance de esta versión).
  * wp_enqueue_style: phpcs:ignore en cel_plantilla_html (falso positivo:
    el CSS no es para una página de WP, es HTML estático escrito a disco).
- Text Domain unificado a "copia-estatica" (coincide con el slug del archivo).

CHANGELOG 1.7:
- Reescritura robusta de enlaces internos: ahora detecta enlaces a CUALQUIER
  dominio interno (no solo el de get_site_url()), incluyendo dominios históricos
  del blog. Útil cuando un sitio ha cambiado de dominio: los enlaces antiguos
  con el dominio anterior dentro del contenido de los posts se reescriben igual.
- Doble estrategia de resolución: primero intenta con url_to_postid de WordPress
  normalizando el dominio al actual; si falla (por cambio de permalink, etc.),
  recurre a un índice precalculado de slugs de todos los posts y páginas.
- Constante CEL_EXTRA_INTERNAL_HOSTS para declarar dominios históricos extra
  en wp-config.php. Ejemplo:
    define('CEL_EXTRA_INTERNAL_HOSTS', 'midominio-viejo.com,otro-historico.es');
- Saltado explícito de URLs no-contenido (categorías, tags, autores, feeds,
  trackbacks, paginación de archivo, wp-* internos) para no reescribirlas
  por error a un post con slug coincidente.
- Preservación del fragmento (#anchor) al reescribir.

CHANGELOG 1.6:
- Descarga de imágenes en streaming directo a disco (stream + filename) para
  evitar errores fatales de memoria (Allowed memory size exhausted) cuando
  hay imágenes muy grandes subidas al sitio. wp_remote_get cargaba la
  respuesta entera en RAM y reventaba con archivos de decenas/cientos de MB.
- Antes de descargar, comprobación de Content-Length con HEAD request: si la
  imagen supera CEL_MAX_IMG_SIZE (30 MB por defecto), se salta y se loguea.
- Archivos descargados se escriben primero a .part y se renombran solo si la
  descarga termina OK, evitando dejar archivos corruptos a medio bajar.

CHANGELOG 1.5:
- Procesamiento defensivo por post: si uno falla, los demás siguen.
- Logging detallado en error_log con el post que se está procesando, para
  identificar cuál causa un error fatal (OOM/timeout).
- Carga de posts por ID en vez de objeto completo (más eficiente en memoria).
- Cache estático de URLs de imágenes que han fallado: no se reintentan dentro
  de la misma ejecución.
- Timeout de descarga reducido a 15s para evitar acumular esperas.
- El aviso del admin muestra cuántos posts se procesaron y cuáles fallaron.

CHANGELOG 1.4:
- Detección de imágenes robusta: ahora captura URLs absolutas (http/https),
  protocol-relative (//) y relativas (/wp-content/uploads/...).
- Soporte para atributos de lazy-loading (data-src, data-lazy-src, data-original).
- El reemplazo a la ruta local /img/ solo se hace si la descarga fue correcta.
- Logging de errores de descarga en el error_log de PHP.
*/

// Tamaño máximo aceptable por imagen (bytes). Por encima, se salta.
if ( ! defined( 'CEL_MAX_IMG_SIZE' ) ) {
    define( 'CEL_MAX_IMG_SIZE', 30 * 1024 * 1024 ); // 30 MB
}

/**
 * Logger interno: solo escribe en el log si WP_DEBUG y WP_DEBUG_LOG están activos.
 * Encapsula error_log() para que las llamadas habituales no disparen avisos del
 * Plugin Check, manteniendo la utilidad de diagnóstico cuando el usuario lo activa.
 *
 * @param string $msg Mensaje a registrar.
 * @return void
 */
function cel_log( $msg ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- logging intencional para diagnóstico.
        error_log( $msg );
    }
}

// 1. Crear Menú
add_action('admin_menu', 'cel_agregar_menu');
function cel_agregar_menu() {
    add_menu_page('Copia Estática', 'Copia Estática', 'manage_options', 'copia-estatica', 'cel_pagina_admin', 'dashicons-media-archive');
}

// 2. Interfaz de Administración
function cel_pagina_admin() {
    global $wpdb;

    // --- Lógica: Generar POSTS ---
    if ( isset( $_POST['cel_generar_posts'] ) && check_admin_referer( 'cel_accion_generar' ) ) {
        $year      = isset( $_POST['cel_year'] ) ? absint( wp_unslash( $_POST['cel_year'] ) ) : 0;
        $meses_raw = isset( $_POST['cel_months'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['cel_months'] ) ) : array();
        if ( empty( $meses_raw ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Error: Selecciona al menos un mes.', 'copia-estatica' ) . '</p></div>';
        } else {
            $meses = array_filter( $meses_raw );
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- necesario para procesar lotes largos de posts/imágenes.
            set_time_limit( 600 );
            wp_raise_memory_limit( 'admin' );
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- complemento a wp_raise_memory_limit cuando 256 MB no bastan.
            @ini_set( 'memory_limit', '512M' );

            $result = cel_procesar_posts( $year, $meses );

            $tipo = empty( $result['failed'] ) ? 'notice-success' : 'notice-warning';
            $msg  = sprintf(
                /* translators: 1: año, 2: posts procesados ok, 3: total. */
                esc_html__( 'Año %1$d: procesados %2$d de %3$d posts.', 'copia-estatica' ),
                $year,
                (int) $result['ok'],
                (int) $result['total']
            );
            if ( ! empty( $result['failed'] ) ) {
                $msg .= '<br><br><strong>' . esc_html__( 'Posts con error:', 'copia-estatica' ) . '</strong><ul style="margin-left:20px;">';
                foreach ( $result['failed'] as $f ) {
                    $msg .= '<li>' . esc_html( $f ) . '</li>';
                }
                $msg .= '</ul>' . esc_html__( 'Revisa wp-content/debug.log para más detalle.', 'copia-estatica' );
            }
            echo '<div class="notice ' . esc_attr( $tipo ) . '"><p>' . wp_kses_post( $msg ) . '</p></div>';
        }
    }

    // --- Lógica: Generar PÁGINAS ---
    if ( isset( $_POST['cel_generar_pages'] ) && check_admin_referer( 'cel_accion_generar' ) ) {
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- necesario para procesar lotes largos.
        set_time_limit( 600 );
        wp_raise_memory_limit( 'admin' );
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- complemento a wp_raise_memory_limit cuando 256 MB no bastan.
        @ini_set( 'memory_limit', '512M' );

        $result = cel_procesar_paginas();
        $tipo   = empty( $result['failed'] ) ? 'notice-success' : 'notice-warning';
        $msg    = sprintf(
            /* translators: 1: páginas procesadas ok, 2: total. */
            esc_html__( 'Páginas: procesadas %1$d de %2$d.', 'copia-estatica' ),
            (int) $result['ok'],
            (int) $result['total']
        );
        if ( ! empty( $result['failed'] ) ) {
            $msg .= '<br><br><strong>' . esc_html__( 'Páginas con error:', 'copia-estatica' ) . '</strong><ul style="margin-left:20px;">';
            foreach ( $result['failed'] as $f ) {
                $msg .= '<li>' . esc_html( $f ) . '</li>';
            }
            $msg .= '</ul>' . esc_html__( 'Revisa wp-content/debug.log para más detalle.', 'copia-estatica' );
        }
        echo '<div class="notice ' . esc_attr( $tipo ) . '"><p>' . wp_kses_post( $msg ) . '</p></div>';
    }

    // Datos para el formulario: lista de años con posts publicados (cacheado 1h).
    $years = get_transient( 'cel_publish_years' );
    if ( false === $years ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- consulta de meta-tabla, resultado pequeño, cacheado vía transient.
        $years = $wpdb->get_col( "SELECT DISTINCT YEAR(post_date) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type = 'post' ORDER BY post_date DESC" );
        set_transient( 'cel_publish_years', $years, HOUR_IN_SECONDS );
    }
    $nombres_meses = array( 1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre' );

    $upload_dir = wp_upload_dir();
    $ruta_base  = trailingslashit( $upload_dir['basedir'] ) . 'copia-estatica-html/';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Generador de Sitio Estático Local', 'copia-estatica' ); ?></h1>
        <p><?php esc_html_e( 'Ruta de salida:', 'copia-estatica' ); ?> <code><?php echo esc_html( $ruta_base ); ?></code></p>
        <p><em><?php esc_html_e( 'Consejo: si un año grande da error, prueba mes a mes para aislar el problema. El progreso queda en wp-content/debug.log si tienes WP_DEBUG_LOG activado.', 'copia-estatica' ); ?></em></p>

        <div style="background:#fff; padding:20px; border:1px solid #ccc; margin-bottom:20px;">
            <h2><?php esc_html_e( '1. Exportar Entradas (Blog)', 'copia-estatica' ); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field( 'cel_accion_generar' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Año', 'copia-estatica' ); ?></th>
                        <td>
                            <select name="cel_year">
                                <?php
                                foreach ( $years as $y ) {
                                    echo "<option value='" . esc_attr( $y ) . "'>" . esc_html( $y ) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Meses', 'copia-estatica' ); ?></th>
                        <td>
                            <button type="button" class="button" id="btn_toggle_months"><?php esc_html_e( 'Marcar/Desmarcar Todos', 'copia-estatica' ); ?></button>
                            <br><br>
                            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; max-width: 600px;">
                                <?php foreach ( $nombres_meses as $num => $nombre ) : ?>
                                    <label style="padding:5px; border:1px solid #eee;">
                                        <input type="checkbox" name="cel_months[]" value="<?php echo esc_attr( $num ); ?>" class="chk-month">
                                        <?php echo esc_html( $nombre ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <br>
                <?php submit_button( __( 'Generar Posts Seleccionados', 'copia-estatica' ), 'primary', 'cel_generar_posts' ); ?>
            </form>
        </div>

        <div style="background:#fff; padding:20px; border:1px solid #ccc;">
            <h2><?php esc_html_e( '2. Exportar Páginas (Estáticas)', 'copia-estatica' ); ?></h2>
            <p><?php esc_html_e( 'Esto exportará todas las páginas publicadas (ej: "Quiénes somos", "Contacto") a la carpeta /pages/.', 'copia-estatica' ); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field( 'cel_accion_generar' ); ?>
                <?php submit_button( __( 'Generar Todas las Páginas', 'copia-estatica' ), 'secondary', 'cel_generar_pages' ); ?>
            </form>
        </div>

        <script>
        document.getElementById('btn_toggle_months').addEventListener('click', function() {
            var chk = document.querySelectorAll('.chk-month');
            var status = !chk[0].checked;
            chk.forEach(function(c){ c.checked = status; });
        });
        </script>
    </div>
    <?php
}

// 3. Procesador de POSTS (Blog) — defensivo
function cel_procesar_posts($year, $meses_array) {
    $base_path = wp_upload_dir()['basedir'] . '/copia-estatica-html';
    cel_init_dirs($base_path);

    $failed = [];
    $ok     = 0;
    $total  = 0;

    foreach ($meses_array as $m) {
        $post_ids = get_posts([
            'year'           => $year,
            'monthnum'       => $m,
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ]);

        if (count($post_ids) === 0) continue;

        $m_padded = str_pad($m, 2, '0', STR_PAD_LEFT);
        $month_path = "$base_path/$year/$m_padded";
        if (!file_exists($month_path)) wp_mkdir_p($month_path);

        cel_log("[Copia Estática] === Procesando $year/$m_padded (" . count($post_ids) . " posts) ===");

        $list_items = "";
        foreach ($post_ids as $post_id) {
            $total++;
            $p = get_post($post_id);
            if (!$p) {
                cel_log("[Copia Estática] WARN: post #$post_id no se pudo cargar, saltando.");
                continue;
            }

            $slug_log = $p->post_name ?: '(sin-slug)';
            cel_log("[Copia Estática] [$total] Procesando post #{$p->ID}: $slug_log");

            try {
                $filename  = cel_limpiar_nombre($p->post_name) . '.html';
                $contenido = cel_procesar_contenido_completo($p->post_content, $base_path, 3);
                $html      = cel_plantilla_html($p->post_title, $contenido, '../../style.css', 'post');
                file_put_contents("$month_path/$filename", $html);
                $list_items .= "<li><a href='$filename'>{$p->post_title}</a></li>";
                $ok++;
            } catch (\Throwable $e) {
                $err = "post #{$p->ID} ($slug_log): " . $e->getMessage();
                cel_log("[Copia Estática] ERROR en $err");
                $failed[] = $err;
            }

            unset($p);
            wp_cache_flush();
        }

        $html_idx = cel_plantilla_html("Archivo: $m_padded/$year", "<ul>$list_items</ul>", '../../style.css', 'list');
        file_put_contents("$month_path/index.html", $html_idx);
    }

    cel_regenerar_indices_superiores($base_path);

    return ['ok' => $ok, 'total' => $total, 'failed' => $failed];
}

// 4. Procesador de PÁGINAS — defensivo
function cel_procesar_paginas() {
    $base_path = wp_upload_dir()['basedir'] . '/copia-estatica-html';
    cel_init_dirs($base_path);

    $pages_dir = $base_path . '/pages';
    if (!file_exists($pages_dir)) wp_mkdir_p($pages_dir);

    $pages = get_pages(['post_status' => 'publish']);
    $page_ids = is_array($pages) ? wp_list_pluck($pages, 'ID') : [];
    unset($pages);

    $failed = [];
    $ok     = 0;
    $total  = 0;
    $list_items = "";

    foreach ($page_ids as $pid) {
        $total++;
        $p = get_post($pid);
        if (!$p) continue;

        $slug_log = $p->post_name ?: '(sin-slug)';
        cel_log("[Copia Estática] [Page $total] Procesando página #{$p->ID}: $slug_log");

        try {
            $filename  = cel_limpiar_nombre($p->post_name) . '.html';
            $contenido = cel_procesar_contenido_completo($p->post_content, $base_path, 2);
            $html      = cel_plantilla_html($p->post_title, $contenido, '../style.css', 'page');
            file_put_contents("$pages_dir/$filename", $html);
            $list_items .= "<li><a href='$filename'>{$p->post_title}</a></li>";
            $ok++;
        } catch (\Throwable $e) {
            $err = "página #{$p->ID} ($slug_log): " . $e->getMessage();
            cel_log("[Copia Estática] ERROR en $err");
            $failed[] = $err;
        }

        unset($p);
        wp_cache_flush();
    }

    $html_idx = cel_plantilla_html("Páginas del Sitio", "<ul>$list_items</ul>", '../style.css', 'list');
    file_put_contents("$pages_dir/index.html", $html_idx);

    cel_regenerar_indices_superiores($base_path);

    return ['ok' => $ok, 'total' => $total, 'failed' => $failed];
}

// 5. Motor de Procesamiento de Contenido (Imágenes + Enlaces)
function cel_procesar_contenido_completo($content, $base_path, $depth) {
    // Cache estático de URLs que ya fallaron en esta ejecución
    static $failed_urls = [];

    $prefix = str_repeat('../', $depth - 1);

    // --- A. PROCESAR IMÁGENES ---
    $upload_dir       = wp_upload_dir();
    $upload_url_base  = $upload_dir['baseurl'];
    $upload_path_part = wp_parse_url($upload_url_base, PHP_URL_PATH);
    $site_url         = get_site_url();

    $content = preg_replace('/\s(srcset|sizes)=["\'][^"\']*["\']/i', '', $content);

    $img_pattern = '/["\']([^"\']*' . preg_quote($upload_path_part, '/') . '\/[^"\']+?\.(?:jpe?g|png|gif|webp|svg|bmp|ico|avif)[^"\']*)["\']/i';
    preg_match_all($img_pattern, $content, $matches);
    $imgs = array_unique($matches[1]);

    foreach ($imgs as $url_found) {
        $url_online = $url_found;
        if (strpos($url_online, '//') === 0) {
            $url_online = (is_ssl() ? 'https:' : 'http:') . $url_online;
        } elseif (strpos($url_online, '/') === 0) {
            $url_online = rtrim($site_url, '/') . $url_online;
        }

        if (isset($failed_urls[$url_online])) continue;

        $path_only = wp_parse_url($url_online, PHP_URL_PATH);
        $ext = strtolower(pathinfo($path_only, PATHINFO_EXTENSION));
        if (!$ext) $ext = 'jpg';
        $nuevo_nombre = md5($url_online) . '.' . $ext;
        $ruta_local   = $base_path . '/img/' . $nuevo_nombre;

        if (!file_exists($ruta_local)) {
            // Comprobación previa de tamaño con HEAD: si la imagen es enorme,
            // saltarla en vez de intentar descargarla y reventar el proceso.
            $head = wp_remote_head($url_online, [
                'sslverify'   => false,
                'timeout'     => 10,
                'redirection' => 5,
            ]);
            if (!is_wp_error($head) && wp_remote_retrieve_response_code($head) == 200) {
                $clen = (int) wp_remote_retrieve_header($head, 'content-length');
                if ($clen > 0 && $clen > CEL_MAX_IMG_SIZE) {
                    $size_mb = round($clen / 1048576, 1);
                    cel_log("[Copia Estática] Imagen demasiado grande ({$size_mb} MB), saltando: $url_online");
                    $failed_urls[$url_online] = true;
                    continue;
                }
            }

            // Descarga en streaming directo a disco para evitar OOM con imágenes grandes.
            // wp_remote_get con stream=true escribe la respuesta al archivo indicado
            // sin cargarla entera en memoria.
            $ruta_tmp = $ruta_local . '.part';
            $resp = wp_remote_get($url_online, [
                'sslverify' => false,
                'timeout'   => 30,
                'stream'    => true,
                'filename'  => $ruta_tmp,
            ]);

            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) == 200
                && file_exists($ruta_tmp) && filesize($ruta_tmp) > 0) {
                // Mover al nombre final solo si la descarga fue completa.
                // Usamos copy + wp_delete_file en lugar de rename() porque
                // este último está desaconsejado por los estándares de WP.
                if ( copy( $ruta_tmp, $ruta_local ) ) {
                    wp_delete_file( $ruta_tmp );
                }
            } else {
                // Limpiar archivo parcial y registrar fallo
                if (file_exists($ruta_tmp)) wp_delete_file($ruta_tmp);
                $err_msg = is_wp_error($resp)
                    ? $resp->get_error_message()
                    : 'HTTP ' . wp_remote_retrieve_response_code($resp);
                cel_log('[Copia Estática] Error descargando: ' . $url_online . ' - ' . $err_msg);
                $failed_urls[$url_online] = true;
            }
        }

        if (file_exists($ruta_local)) {
            $content = str_replace($url_found, $prefix . 'img/' . $nuevo_nombre, $content);
        }
    }

    // -----------------------------------------------------------------
    // B. PROCESAR ENLACES INTERNOS (versión robusta)
    // -----------------------------------------------------------------
    // Estrategia:
    //   1. Detectar TODO href absoluto (http/https) hacia cualquier host
    //      considerado "interno": el actual de get_site_url() + los extra
    //      declarados en CEL_EXTRA_INTERNAL_HOSTS.
    //   2. Saltar URLs que claramente NO son contenido (wp-*, category,
    //      tag, author, feed, etc.) para no reescribirlas por error.
    //   3. Intentar primero con url_to_postid normalizando el dominio al
    //      actual: cubre la mayoría de casos.
    //   4. Si lo anterior falla (cambios de permalink, dominios viejos
    //      con estructura distinta...), recurrir a un índice precalculado
    //      slug -> ruta_local con todos los posts y páginas publicados.
    //   5. Preservar el fragmento (#anchor) en el href reescrito.

    static $slug_index    = null;
    static $internal_hosts = null;
    if ($slug_index === null)    $slug_index    = cel_build_slug_index();
    if ($internal_hosts === null) $internal_hosts = cel_build_internal_hosts();

    $current_site_url = rtrim($site_url, '/');

    $skip_segments = [
        'wp-content', 'wp-admin', 'wp-includes', 'wp-json',
        'category', 'categoria', 'tag', 'etiqueta',
        'author', 'autor', 'feed', 'comments', 'trackback', 'embed',
        'page', // paginación /page/N/ del archivo
    ];

    $content = preg_replace_callback(
        '/(href\s*=\s*["\'])(https?:\/\/[^"\']+)(["\'])/i',
        function ($m) use ($slug_index, $internal_hosts, $current_site_url, $prefix, $skip_segments) {
            $original = $m[0];
            $url      = $m[2];

            $parsed = wp_parse_url($url);
            if (empty($parsed['host'])) return $original;

            // Normalizar host: lowercase y sin www.
            $host = strtolower($parsed['host']);
            if (strpos($host, 'www.') === 0) $host = substr($host, 4);

            // Solo procesar si el host es interno
            if (!isset($internal_hosts[$host])) return $original;

            // Saltar segmentos no-contenido (categorías, tags, feeds, etc.)
            $path = isset($parsed['path']) ? trim($parsed['path'], '/') : '';
            if ($path !== '') {
                foreach (explode('/', $path) as $seg) {
                    if (in_array(strtolower($seg), $skip_segments, true)) {
                        return $original;
                    }
                }
            } else {
                // URL a la raíz del dominio; no hay nada que reescribir
                return $original;
            }

            $new_href = null;

            // 1) Intentar con url_to_postid normalizando el dominio
            $canonical = preg_replace('#^https?://[^/]+#i', $current_site_url, $url);
            // Eliminar query y fragment para que url_to_postid no falle por eso
            $canonical_clean = preg_replace('#[?#].*$#', '', $canonical);
            $post_id = url_to_postid($canonical_clean);

            if ($post_id) {
                $p_type = get_post_type($post_id);
                $slug   = get_post_field('post_name', $post_id);
                if ($p_type === 'page') {
                    $new_href = $prefix . 'pages/' . $slug . '.html';
                } elseif ($p_type === 'post') {
                    $p_date = get_post_field('post_date', $post_id);
                    $y  = gmdate('Y', strtotime($p_date));
                    $mo = gmdate('m', strtotime($p_date));
                    $new_href = $prefix . "$y/$mo/$slug.html";
                }
            }

            // 2) Fallback: matchear por slug (último segmento del path)
            if ($new_href === null) {
                $segments = explode('/', $path);
                $slug = end($segments);
                if ($slug !== false && $slug !== '' && isset($slug_index[$slug])) {
                    $new_href = $prefix . $slug_index[$slug];
                }
            }

            if ($new_href === null) return $original;

            // Preservar fragmento si lo había
            if (!empty($parsed['fragment'])) {
                $new_href .= '#' . $parsed['fragment'];
            }

            return $m[1] . $new_href . $m[3];
        },
        $content
    );

    return $content;
}

// Construye un índice slug -> ruta_local_relativa de todos los posts y
// páginas publicados. Se cachea estáticamente para no consultar la BD
// por cada post procesado, y vía transient para reaprovechar entre ejecuciones.
function cel_build_slug_index() {
    global $wpdb;

    // Caché entre ejecuciones (10 minutos): el índice cambia poco entre invocaciones.
    $cached = get_transient( 'cel_slug_index' );
    if ( false !== $cached && is_array( $cached ) ) {
        return $cached;
    }

    $index = array();

    // Posts publicados.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- query agregada de slugs+fecha, sin API equivalente; cacheada arriba vía transient.
    $posts = $wpdb->get_results(
        "SELECT post_name, YEAR(post_date) AS y, LPAD(MONTH(post_date), 2, '0') AS mo
         FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type = 'post' AND post_name != ''"
    );
    foreach ( $posts as $p ) {
        $index[ $p->post_name ] = $p->y . '/' . $p->mo . '/' . $p->post_name . '.html';
    }

    // Páginas publicadas (no sobrescribir un post si hay slug coincidente).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- query simple de slugs; cacheada arriba vía transient.
    $pages = $wpdb->get_results(
        "SELECT post_name FROM {$wpdb->posts}
         WHERE post_status = 'publish' AND post_type = 'page' AND post_name != ''"
    );
    foreach ( $pages as $p ) {
        if ( ! isset( $index[ $p->post_name ] ) ) {
            $index[ $p->post_name ] = 'pages/' . $p->post_name . '.html';
        }
    }

    set_transient( 'cel_slug_index', $index, 10 * MINUTE_IN_SECONDS );

    return $index;
}

// Devuelve un conjunto (asoc array) de hosts considerados "internos":
// el dominio actual del sitio + los declarados en CEL_EXTRA_INTERNAL_HOSTS.
// Todos normalizados (lowercase, sin "www.").
function cel_build_internal_hosts() {
    $hosts = [];

    // Host actual
    $site_host = wp_parse_url(get_site_url(), PHP_URL_HOST);
    if ($site_host) {
        $h = strtolower($site_host);
        if (strpos($h, 'www.') === 0) $h = substr($h, 4);
        $hosts[$h] = true;
    }

    // Hosts adicionales vía constante en wp-config.php
    if (defined('CEL_EXTRA_INTERNAL_HOSTS')) {
        foreach (explode(',', CEL_EXTRA_INTERNAL_HOSTS) as $e) {
            $h = strtolower(trim($e));
            if (strpos($h, 'www.') === 0) $h = substr($h, 4);
            if (!empty($h)) $hosts[$h] = true;
        }
    }

    return $hosts;
}

// 6. Helpers y Plantillas
function cel_init_dirs($base) {
    if (!file_exists($base)) wp_mkdir_p($base);
    if (!file_exists($base.'/img')) wp_mkdir_p($base.'/img');
    cel_crear_css($base);
}

function cel_limpiar_nombre($str) {
    return sanitize_title($str) ?: 'sin-titulo';
}

function cel_regenerar_indices_superiores($base_path) {
    $html_pages_link = "";
    if (file_exists($base_path . '/pages/index.html')) {
        $html_pages_link = "<li><strong><a href='pages/index.html'>📂 Ver Páginas Estáticas (Quiénes somos, etc.)</a></strong></li><hr>";
    }

    $years = glob($base_path . '/*', GLOB_ONLYDIR);
    $list_years = "";
    $map_meses = ['01'=>'Enero', '02'=>'Febrero', '03'=>'Marzo', '04'=>'Abril', '05'=>'Mayo', '06'=>'Junio', '07'=>'Julio', '08'=>'Agosto', '09'=>'Septiembre', '10'=>'Octubre', '11'=>'Noviembre', '12'=>'Diciembre'];

    foreach($years as $y_path) {
        $year_num = basename($y_path);
        if(!is_numeric($year_num)) continue;

        $list_years .= "<li><a href='$year_num/index.html'>Año $year_num</a></li>";

        $months = glob($y_path . '/*', GLOB_ONLYDIR);
        $list_months = "";
        if($months) {
            foreach($months as $m_path) {
                $month_num = basename($m_path);
                $nombre_mes = isset($map_meses[$month_num]) ? $map_meses[$month_num] : $month_num;
                $list_months .= "<li><a href='$month_num/index.html'>$nombre_mes</a></li>";
            }
        }
        $html_year = cel_plantilla_html("Año $year_num", "<h3>Meses disponibles:</h3><ul>$list_months</ul>", '../style.css', 'list');
        file_put_contents("$y_path/index.html", $html_year);
    }

    $content_root = "<ul>$html_pages_link $list_years</ul>";
    $html_root    = cel_plantilla_html("Archivo del Sitio", "<h3>Contenido disponible:</h3>$content_root", 'style.css', 'root');
    file_put_contents("$base_path/index.html", $html_root);
}

// El HTML que genera esta función NO es para servirse por WordPress, sino para
// escribirse a disco como fichero estático destinado a hosting plano. Por eso
// los wp_enqueue_style no aplican aquí: las directivas <link rel="stylesheet">
// son parte del payload HTML que se entrega offline.
// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
function cel_plantilla_html($title, $content, $css, $type) {
    $nav = '';
    if ($type == 'post') $nav = '<p><a href="index.html">⬅ Volver al mes</a> | <a href="../../index.html">🏠 Inicio</a></p>';
    if ($type == 'page') $nav = '<p><a href="index.html">⬅ Volver a Páginas</a> | <a href="../index.html">🏠 Inicio</a></p>';
    if ($type == 'list' && strpos($title, 'Año') !== false) $nav = '<p><a href="../index.html">⬅ Volver al Inicio</a></p>';
    if ($type == 'list' && strpos($title, 'Archivo:') !== false) $nav = '<p><a href="../index.html">⬅ Volver al Año</a></p>';

    return "<!DOCTYPE html>
    <html lang='es'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>$title</title>
        <link rel='stylesheet' href='$css'>
    </head>
    <body>
        <div class='container'>
            $nav
            <h1>$title</h1>
            <hr>
            <div class='content'>$content</div>
        </div>
    </body>
    </html>";
}
// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet

function cel_crear_css($path) {
    $css = "body{font-family:-apple-system,system-ui,sans-serif;line-height:1.6;color:#333;background:#f4f4f4;margin:0;padding:20px}.container{max-width:800px;margin:0 auto;background:#fff;padding:40px;box-shadow:0 2px 5px rgba(0,0,0,0.1);border-radius:8px}h1{color:#2c3e50;margin-top:0}a{color:#0073aa;text-decoration:none}a:hover{text-decoration:underline}img{max-width:100%;height:auto;display:block;margin:20px auto}ul{padding-left:20px}li{margin-bottom:8px}.content{margin-top:20px}";
    if (!file_exists("$path/style.css")) file_put_contents("$path/style.css", $css);
}
