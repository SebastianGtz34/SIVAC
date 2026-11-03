<?php
/**
 * comentarios.php — Comentarios sobre un candidato (retro 2026-10-29).
 *
 * Funcionan como los de ticketsBI: un hilo por ficha donde escriben RRHH y el
 * jefe dueño de la vacante. RRHH elige si su comentario es INTERNO (sólo RRHH) o
 * PARA EL JEFE; lo que escribe el jefe siempre lo ve RRHH. La decisión de quién
 * puede marcar «interno» y quién ve qué la toman los endpoints con la sesión
 * (acciones_candidatos.php para RRHH, acciones_solicitante.php para el jefe),
 * nunca el cliente.
 *
 * Avisan sólo por campana —son conversación de trabajo, el correo se reserva
 * para el candidato— y con dedup: un ida y vuelta de cinco mensajes deja un aviso
 * sin leer, no cinco.
 */

// Tope del texto: lo bastante para una explicación, no para pegar un CV.
const SIVAC_COMENTARIO_MAX = 2000;

if (!function_exists('sivacComentarios')) {

    /**
     * Comentarios de la ficha, del más viejo al más nuevo (se leen como chat).
     * $conInternos = false para el jefe: las notas internas no salen del servidor.
     */
    function sivacComentarios(mysqli $conn, int $idCandidato, bool $conInternos): array {
        $stmt = $conn->prepare(
            "SELECT cc.id, cc.no_empleado, cc.comentario, cc.es_interno, cc.fecha_creacion,
                    COALESCE(u.nombre, CONCAT('Empleado #', cc.no_empleado)) AS nombre
               FROM candidatos_comentarios cc
               LEFT JOIN mess_rrhh.usuarios u ON u.noEmpleado = cc.no_empleado
              WHERE cc.id_candidato = ?" . ($conInternos ? '' : ' AND cc.es_interno = 0') . "
              ORDER BY cc.id ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        return $data;
    }

    /**
     * Valida y guarda un comentario. Devuelve '' si se guardó o el mensaje de error.
     */
    function sivacComentar(mysqli $conn, int $idCandidato, int $noEmpleado, string $texto, bool $interno): string {
        $texto = trim($texto);
        $error = sivacValidarComentario($texto);
        if ($error !== '') return $error;
        $esInterno = $interno ? 1 : 0;
        $stmt = $conn->prepare(
            "INSERT INTO candidatos_comentarios (id_candidato, no_empleado, comentario, es_interno)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) return 'No se pudo guardar el comentario.';
        $stmt->bind_param('iisi', $idCandidato, $noEmpleado, $texto, $esInterno);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? '' : 'No se pudo guardar el comentario.';
    }

    /** '' si el texto (ya recortado) se puede guardar, o el mensaje de error. */
    function sivacValidarComentario(string $texto): string {
        if ($texto === '') return 'Escribe el comentario.';
        if (mb_strlen($texto) > SIVAC_COMENTARIO_MAX) {
            return 'El comentario es demasiado largo (máximo ' . SIVAC_COMENTARIO_MAX . ' caracteres).';
        }
        return '';
    }

    /**
     * Notas generales del puesto (vacantes_comentarios, pedido de RRHH 2026-11-03).
     * Mismo hilo que el del candidato pero por vacante y SIN notas internas: las
     * leen RRHH y el jefe dueño de la vacante, y los dos escriben. Salen con la
     * misma forma que sivacComentarios() (es_interno = 0) para que la UI las pinte
     * con sivacHtmlComentarios().
     */
    function sivacComentariosVacante(mysqli $conn, int $idVacante): array {
        $stmt = $conn->prepare(
            "SELECT vc.id, vc.no_empleado, vc.comentario, 0 AS es_interno, vc.fecha_creacion,
                    COALESCE(u.nombre, CONCAT('Empleado #', vc.no_empleado)) AS nombre
               FROM vacantes_comentarios vc
               LEFT JOIN mess_rrhh.usuarios u ON u.noEmpleado = vc.no_empleado
              WHERE vc.id_vacante = ?
              ORDER BY vc.id ASC"
        );
        if (!$stmt) return [];
        $stmt->bind_param('i', $idVacante);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        return $data;
    }

    /**
     * id_vacante => número de notas. Va en una consulta aparte (y no como
     * subconsulta de los listados) para que, si al servidor le falta la tabla,
     * las vacantes sigan cargando: sólo se pierde el contador.
     * Con $idSolicitante, sólo las vacantes de ese jefe.
     */
    function sivacConteoNotasVacante(mysqli $conn, ?int $idSolicitante = null): array {
        $sql = "SELECT vc.id_vacante, COUNT(*) AS n FROM vacantes_comentarios vc"
             . ($idSolicitante !== null
                 ? " INNER JOIN vacantes v ON v.id = vc.id_vacante WHERE v.no_empleado_solicitante = ?"
                 : '')
             . " GROUP BY vc.id_vacante";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($idSolicitante !== null) $stmt->bind_param('i', $idSolicitante);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) $data[(int)$r['id_vacante']] = (int)$r['n'];
        $stmt->close();
        return $data;
    }

    /** Valida y guarda una nota del puesto. '' si se guardó o el mensaje de error. */
    function sivacComentarVacante(mysqli $conn, int $idVacante, int $noEmpleado, string $texto): string {
        $texto = trim($texto);
        $error = sivacValidarComentario($texto);
        if ($error !== '') return $error;
        $stmt = $conn->prepare(
            "INSERT INTO vacantes_comentarios (id_vacante, no_empleado, comentario) VALUES (?, ?, ?)"
        );
        if (!$stmt) return 'No se pudo guardar la nota.';
        $stmt->bind_param('iis', $idVacante, $noEmpleado, $texto);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok ? '' : 'No se pudo guardar la nota.';
    }

    /** Recorte del comentario para la campana («…» si se cortó). */
    function sivacComentarioResumen(string $texto, int $max = 140): string {
        // Con UTF-8 inválido preg_replace devuelve null: se queda el texto tal cual.
        $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? trim($texto);
        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto;
    }
}
