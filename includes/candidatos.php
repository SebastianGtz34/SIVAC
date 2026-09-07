<?php
/**
 * candidatos.php — Lógica compartida de candidatos.
 *
 * LA PERSONA Y SUS POSTULACIONES: un mismo candidato puede estar en varias
 * vacantes. Cada postulación es una fila propia de `candidatos` —con su estatus,
 * su historial y sus citas independientes— y se hermanan por `id_origen`: NULL en
 * la primera, el id de esa primera en cada copia.
 *
 * Se hizo así, y no volviendo el estatus un par (candidato, vacante), porque el
 * pipeline entero cuelga de `candidatos.estatus`: cambiarlo obligaría a reescribir
 * listados, cierre, portal y dashboard, y a migrar producción. Con fichas
 * separadas, alguien puede ir descartado en una vacante y contratado en otra sin
 * que ninguna consulta existente se entere.
 *
 * Lo ÚNICO que se comparte entre las fichas de una persona son sus DOCUMENTOS: lo
 * que ya entregó no se le vuelve a pedir porque se postule a otra vacante.
 */

if (!function_exists('sivacIdPersona')) {

    /**
     * Llave de persona de una ficha: su `id_origen` si es copia, o su propio id.
     * Devuelve 0 si el candidato no existe.
     */
    function sivacIdPersona(mysqli $conn, int $idCandidato): int {
        static $cache = [];
        if (isset($cache[$idCandidato])) return $cache[$idCandidato];
        $stmt = $conn->prepare("SELECT COALESCE(id_origen, id) AS persona FROM candidatos WHERE id = ? LIMIT 1");
        if (!$stmt) return $cache[$idCandidato] = 0;
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$idCandidato] = (int)($row['persona'] ?? 0);
    }

    /**
     * Todas las fichas de la misma persona (incluida la que se pasa), como ids.
     *
     * Devuelve siempre al menos [$idCandidato] para que un IN (...) nunca quede
     * vacío —que dejaría pasar todo o reventaría la consulta—, incluso si la ficha
     * ya no existiera.
     */
    function sivacFichasDePersona(mysqli $conn, int $idCandidato): array {
        $persona = sivacIdPersona($conn, $idCandidato);
        if ($persona <= 0) return [$idCandidato];

        $stmt = $conn->prepare(
            "SELECT id FROM candidatos WHERE id = ? OR id_origen = ? ORDER BY id"
        );
        if (!$stmt) return [$idCandidato];
        $stmt->bind_param('ii', $persona, $persona);
        $stmt->execute();
        $res = $stmt->get_result();
        $ids = [];
        while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
        $stmt->close();

        if (!in_array($idCandidato, $ids, true)) $ids[] = $idCandidato;
        return $ids ?: [$idCandidato];
    }

    /**
     * Fragmento `IN (...)` con las fichas de la persona, listo para interpolar.
     *
     * Los ids salen de la BD y se castean a int, así que no hay inyección posible;
     * va interpolado porque un IN de longitud variable con bind_param obliga a
     * armar el string de tipos a mano y aquí no aporta nada.
     */
    function sivacInFichasDePersona(mysqli $conn, int $idCandidato): string {
        return implode(',', array_map('intval', sivacFichasDePersona($conn, $idCandidato)));
    }

    /**
     * ¿El empleado es solicitante de ALGUNA vacante en la que esté esta persona?
     *
     * Es la versión «por persona» de esSolicitanteDeCandidato(), y hace falta desde
     * que los documentos se comparten entre fichas: un documento vive colgado de la
     * ficha en la que se subió, así que el jefe de la OTRA vacante lo ve listado
     * pero, comprobando sólo esa ficha, no podría abrirlo. Si la persona es
     * candidata suya, su documentación es parte de su proceso.
     */
    function esSolicitanteDePersona(mysqli $conn, int $noEmpleado, int $idCandidato): bool {
        $enFichas = sivacInFichasDePersona($conn, $idCandidato);
        $stmt = $conn->prepare(
            "SELECT 1
               FROM candidatos c
               INNER JOIN vacantes v ON v.id = c.id_vacante
              WHERE c.id IN ($enFichas) AND v.no_empleado_solicitante = ?
              LIMIT 1"
        );
        if (!$stmt) return false;
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }
}
