<?php
/**
 * notificaciones.php — Notificaciones del proceso (campana in-system + correo).
 *
 * notificarEvento() centraliza el "quién se entera de qué": inserta la campana
 * para empleados internos, dispara el correo (a empleados y/o externos como el
 * candidato o las áreas de alta) y deja bitácora en la tabla notificaciones con
 * el resultado del envío. Un fallo de correo NUNCA aborta la transacción: se
 * registra en correo_error y el flujo continúa.
 */

require_once __DIR__ . '/correo.php';

if (!function_exists('notificarEvento')) {

    /**
     * Registra una notificación (campana y/o correo) y deja bitácora.
     *
     * @param array $datos Claves usadas:
     *   - destino_no_empleado ?int  → empleado que verá la campana (null = solo correo)
     *   - id_vacante ?int, id_candidato ?int
     *   - titulo string, mensaje string, url ?string  → contenido de la campana
     *   - correos string[]           → destinatarios de correo (externos o internos)
     *   - correo_asunto ?string, correo_titulo ?string, correo_html ?string
     *     Si falta correo_html, no se envía correo (solo campana).
     * @return int id de la fila de notificaciones insertada.
     */
    function notificarEvento(mysqli $conn, string $evento, array $datos): int {
        $destino    = isset($datos['destino_no_empleado']) ? (int)$datos['destino_no_empleado'] : null;
        $idVacante  = isset($datos['id_vacante'])   ? (int)$datos['id_vacante']   : null;
        $idCandidato= isset($datos['id_candidato']) ? (int)$datos['id_candidato'] : null;
        $titulo     = (string)($datos['titulo'] ?? $evento);
        $mensaje    = (string)($datos['mensaje'] ?? '');
        $url        = $datos['url'] ?? null;

        $correos    = array_values(array_filter(array_map('trim', $datos['correos'] ?? [])));
        $correoHtml = $datos['correo_html'] ?? null;

        $correoEnviado = 0;
        $correoDestinatarios = null;
        $correoError = null;

        if ($correoHtml !== null && $correos) {
            $res = enviarCorreoSivac(
                $correos,
                (string)($datos['correo_asunto'] ?? $titulo),
                (string)($datos['correo_titulo'] ?? $titulo),
                $correoHtml
            );
            $correoEnviado = $res['ok'] ? 1 : 0;
            $correoDestinatarios = mb_substr($res['para'], 0, 500);
            $correoError = $res['ok'] ? null : mb_substr((string)$res['error'], 0, 250);
        }

        $stmt = $conn->prepare(
            "INSERT INTO notificaciones
                (no_empleado_destino, id_vacante, id_candidato, evento, titulo, mensaje, url,
                 correo_enviado, correo_destinatarios, correo_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'iiissssiss',
            $destino, $idVacante, $idCandidato, $evento, $titulo, $mensaje, $url,
            $correoEnviado, $correoDestinatarios, $correoError
        );
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** Contador de notificaciones no leídas de un empleado. */
    function sivacNoLeidas(mysqli $conn, int $noEmpleado): int {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM notificaciones WHERE no_empleado_destino = ? AND leida = 0"
        );
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }
}
