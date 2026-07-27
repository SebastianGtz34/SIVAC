<?php
/**
 * assets.php — Cache-busting de los assets propios (js/ y css/).
 *
 * POR QUÉ EXISTE: el navegador cachea js/*.js y css/*.css agresivamente. Cuando
 * una versión cambia a la vez el HTML de una página y su JS —por ejemplo al
 * agregarle una columna a una tabla— el usuario que llega con el JS viejo en
 * caché combina el HTML nuevo con el script viejo y la pantalla revienta
 * ("DataTables warning: Requested unknown parameter"). Pedirle a cada usuario un
 * Ctrl+F5 después de cada despliegue no es una solución.
 *
 * Se le cuelga a la URL la fecha de modificación del archivo: mientras el
 * archivo no cambie la URL es idéntica (y el navegador la sigue cacheando), y en
 * cuanto cambia la URL es otra y el navegador la vuelve a bajar sola.
 *
 * Solo aplica a los assets PROPIOS. Los de vendor/ se versionan con su release y
 * no se tocan.
 */

if (!function_exists('sivacAsset')) {

    /**
     * Ruta del asset con ?v=<filemtime>. $ruta es relativa a la raíz de SIVAC,
     * tal como se escribe en el HTML (p. ej. 'js/vacantes.js').
     */
    function sivacAsset(string $ruta): string {
        static $cache = [];
        if (isset($cache[$ruta])) return $cache[$ruta];
        // basename() por segmento: la ruta siempre viene del código, nunca de
        // input, pero mantenerla dentro del proyecto es gratis.
        $abs = __DIR__ . '/../' . ltrim($ruta, '/');
        $mt  = @filemtime($abs);
        // Sin filemtime (archivo movido o permisos) se cae a no versionar: es
        // preferible un asset cacheado a una URL que cambia en cada request y
        // tira el caché por completo.
        return $cache[$ruta] = $mt ? ($ruta . '?v=' . $mt) : $ruta;
    }
}
