<?php
/**
 * vacantes.php — Lógica compartida de requisiciones.
 *
 * Vive aquí lo que necesitan por igual las dos vías de alta de una vacante:
 *   - acciones_vacantes.php     → la captura RRHH (nace 'abierta').
 *   - acciones_solicitante.php  → la levanta el jefe (nace 'pendiente_vobo').
 * Así el folio y la validación del puesto no se bifurcan entre ambas.
 */

if (!function_exists('generarFolioVacante')) {

    /**
     * Sigla del área para el folio, derivada del nombre del departamento.
     *
     * mess_rrhh.departamento NO tiene columna de abreviatura y es catálogo de otro
     * sistema (no lo tocamos desde aquí), así que la sigla se calcula del nombre:
     *   - Una sola palabra      → sus primeras 3 letras   (Ventas → VEN)
     *   - Varias palabras       → la inicial de cada una  (Recursos Humanos → RH)
     *   - Las que YA son siglas en el catálogo (SLP, MT, BI, TI) se respetan
     *     completas, porque abreviarlas a su inicial las volvería irreconocibles
     *     (Ventas SLP → VSLP, no VS).
     * Se ignoran las palabras de enlace (de/del/y/a/la/el/en) y se quitan acentos:
     * el folio viaja en asuntos de correo y URLs, mejor ASCII puro.
     *
     * OJO: la sigla es INFORMATIVA, no identifica. Áreas distintas pueden caer en
     * la misma sigla (hay dos «Capacitación» en el catálogo); lo que hace único al
     * folio es el consecutivo, que sigue siendo global por año.
     *
     * Máximo 4 caracteres a propósito: vacantes.folio es VARCHAR(20) y
     * 'VAC-2026-' + 4 + '-' + '0044' son 18. Con más, no cabría.
     */
    function siglaDepartamento(mysqli $conn, int $idDepartamento): string {
        static $cache = [];
        if (isset($cache[$idDepartamento])) return $cache[$idDepartamento];

        $stmt = $conn->prepare("SELECT departamento FROM mess_rrhh.departamento WHERE id = ? LIMIT 1");
        if (!$stmt) return $cache[$idDepartamento] = 'GRAL';
        $stmt->bind_param('i', $idDepartamento);
        $stmt->execute();
        $nombre = (string)($stmt->get_result()->fetch_assoc()['departamento'] ?? '');
        $stmt->close();

        $nombre = trim($nombre);
        if ($nombre === '') return $cache[$idDepartamento] = 'GRAL';

        // Acentos fuera con un mapa EXPLÍCITO, no con iconv //TRANSLIT: éste
        // depende de la locale del servidor y en Windows traduce 'ó' como "'o",
        // metiendo una comilla que parte la palabra en dos ('Administración'
        // acababa dando 'AO' en vez de 'ADM'). El mapa da el mismo resultado en el
        // WAMP local y en el cPanel de producción, que es lo que importa aquí.
        $ascii = strtr($nombre, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        ]);
        // La puntuación pasa a espacio ('Lab. Calibraciones' → 'Lab Calibraciones').
        $ascii = preg_replace('/[^A-Za-z0-9 ]+/', ' ', $ascii);

        $vacias  = ['DE', 'DEL', 'Y', 'A', 'LA', 'EL', 'EN', 'LOS', 'LAS'];
        $piezas  = [];
        foreach (preg_split('/\s+/', trim($ascii)) as $p) {
            if ($p === '') continue;
            if (in_array(strtoupper($p), $vacias, true)) continue;
            $piezas[] = $p;
        }
        if (!$piezas) return $cache[$idDepartamento] = 'GRAL';

        if (count($piezas) === 1) {
            $sigla = strtoupper(substr($piezas[0], 0, 3));
        } else {
            $sigla = '';
            foreach ($piezas as $p) {
                // Ya venía en mayúsculas y es corta: es un acrónimo, va completo.
                $sigla .= (strtoupper($p) === $p && strlen($p) <= 4) ? $p : substr($p, 0, 1);
            }
            $sigla = strtoupper($sigla);
        }

        return $cache[$idDepartamento] = substr($sigla, 0, 4);
    }

    /**
     * Folio VAC-AAAA-AREA-#### (p. ej. VAC-2026-VEN-0044).
     *
     * El consecutivo sigue siendo GLOBAL por año, no por área: así el folio no
     * depende de la sigla para ser único y los emitidos antes de este cambio
     * —con el formato viejo VAC-AAAA-####— siguen siendo válidos y no colisionan.
     * Los folios anteriores NO se regeneran: son el identificador que la gente ya
     * tiene en sus correos.
     */
    function generarFolioVacante(mysqli $conn, int $idDepartamento): string {
        $anio = date('Y');
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM vacantes WHERE YEAR(fecha_creacion) = ?");
        $stmt->bind_param('i', $anio);
        $stmt->execute();
        $sec = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0) + 1;
        $stmt->close();
        return sprintf('VAC-%s-%s-%04d', $anio, siglaDepartamento($conn, $idDepartamento), $sec);
    }

    /**
     * Puesto del catálogo mess_rrhh.puesto (activo) o null.
     * El nombre se copia a vacantes.puesto como snapshot: si RRHH renombra el
     * puesto en el catálogo, el histórico de las vacantes ya creadas no cambia.
     */
    function puestoDelCatalogo(mysqli $conn, int $idPuesto): ?array {
        $stmt = $conn->prepare("SELECT id, puesto FROM mess_rrhh.puesto WHERE id = ? AND estatus = 1 LIMIT 1");
        $stmt->bind_param('i', $idPuesto);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Campos condicionales del tipo 'temporal': duración (meses) + motivo.
     * Sólo se exigen y sólo se guardan cuando el tipo es 'temporal'; para el
     * resto de tipos se fuerzan a NULL (así cambiar de temporal a otro tipo
     * limpia los datos que ya no aplican).
     * Devuelve ['error'=>?string, 'duracion'=>?int, 'motivo'=>?string].
     */
    function sanearTemporal(string $tipo, array $post): array {
        if ($tipo !== 'temporal') {
            return ['error' => null, 'duracion' => null, 'motivo' => null];
        }
        $duracion = (int)($post['duracion_meses'] ?? 0);
        if ($duracion < 1)   return ['error' => 'Indica la duración en meses de la contratación temporal.'];
        if ($duracion > 600) return ['error' => 'La duración en meses no es válida (máximo 600).'];
        $motivo = trim($post['motivo_temporal'] ?? '');
        if ($motivo === '')  return ['error' => 'Indica el motivo de la contratación temporal.'];
        return ['error' => null, 'duracion' => $duracion, 'motivo' => mb_substr($motivo, 0, 255)];
    }
}
