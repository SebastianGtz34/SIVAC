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
        if ($texto === '') return 'Escribe el comentario.';
        if (mb_strlen($texto) > SIVAC_COMENTARIO_MAX) {
            return 'El comentario es demasiado largo (máximo ' . SIVAC_COMENTARIO_MAX . ' caracteres).';
        }
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

    /** Recorte del comentario para la campana («…» si se cortó). */
    function sivacComentarioResumen(string $texto, int $max = 140): string {
        // Con UTF-8 inválido preg_replace devuelve null: se queda el texto tal cual.
        $texto = preg_replace('/\s+/u', ' ', trim($texto)) ?? trim($texto);
        return mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1) . '…' : $texto;
    }
}
