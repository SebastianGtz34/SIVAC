<?php
/**
 * notificaciones.php — Notificaciones del proceso (campana + correo).
 *
 * notificarEvento() centraliza el "quién se entera de qué": publica la campana
 * del portal, dispara el correo (a empleados y/o externos como el candidato o
 * las áreas de alta) y deja bitácora en `mess_sivac.notificaciones` con el
 * resultado del envío. Un fallo de correo NUNCA aborta la transacción: se
 * registra en correo_error y el flujo continúa.
 *
 * LA CAMPANA ES LA DEL PORTAL, NO UNA PROPIA. Todos los sistemas de MESS
 * (ticketsBI, entradasEq, vacaciones, incidencias, planeacion…) escriben en
 * `mess_rrhh.notificacion_historial`, que es lo que lee el badge de
 * loginMaster. SIVAC hace lo mismo con `sistema = 'sivac'`: así el empleado ve
 * el aviso en el portal igual que los de los demás sistemas, y marcarlo leído
 * en un lado lo marca en el otro (es la MISMA fila). `mess_sivac.notificaciones`
 * se conserva como bitácora del correo y de los avisos a externos (candidatos),
 * que no tienen `noEmpleado` y por lo tanto no caben en la campana del portal.
 *
 * Al hacer clic, loginMaster llama a `SIVAC/validaLoginNot.php` con el `archivo`
 * de la fila y ese endpoint responde a qué pantalla ir; por eso `archivo` sólo
 * puede ser uno de sivacNotifArchivos().
 */

require_once __DIR__ . '/correo.php';
require_once __DIR__ . '/../auth.php';

if (!function_exists('notificarEvento')) {

    /** Nombre de SIVAC dentro de notificacion_historial.sistema. */
    define('SIVAC_NOTIF_SISTEMA', 'sivac');

    /**
     * Pantallas a las que puede apuntar una notificación (`archivo`). Es una
     * lista blanca: validaLoginNot.php se niega a redirigir a cualquier otra.
     */
    function sivacNotifArchivos(): array {
        return ['inicio', 'vacantes', 'candidatos', 'contrataciones', 'embed_solicitante'];
    }

    /** 'alta_completada' → 'AltaCompletada' (estilo del resto del portal). */
    function sivacNotifAccion(string $evento): string {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', $evento)));
    }

    /**
     * Pantalla destino según QUIÉN recibe: RRHH abre las pantallas internas; el
     * solicitante (un jefe sin acceso a SIVAC) sólo puede abrir su vista embed,
     * o caería en el redirect de requiereRRHHPage.
     */
    function sivacNotifArchivo(mysqli $conn, int $destino, ?string $url): string {
        if (!esRRHH($conn, $destino)) return 'embed_solicitante';
        $slug = 'inicio';
        if ($url) {
            $base = preg_replace('/\.php$/', '', basename((string)parse_url($url, PHP_URL_PATH)));
            if (in_array($base, sivacNotifArchivos(), true)) $slug = $base;
        }
        return $slug;
    }

    /**
     * Publica la campana del portal (mess_rrhh.notificacion_historial).
     * No se notifica a quien provocó el evento. Devuelve true si insertó.
     *
     * Con $dedup no inserta si el empleado ya tiene una SIN LEER del mismo evento
     * y el mismo registro. Es para los avisos que se repiten (el candidato sube 8
     * documentos, uno por uno): con eso ve un solo aviso hasta que lo atienda, en
     * vez de ocho. Mismo recurso que usan los crons de ticketsBI.
     */
    function sivacNotifPortal(
        mysqli $conn,
        int $destino,
        int $origen,
        string $accion,
        string $archivo,
        int $idRegistro,
        string $recordar,
        bool $dedup = false
    ): bool {
        if ($destino <= 0 || $destino === $origen) return false;

        if ($dedup) {
            $chk = $conn->prepare(
                "SELECT 1 FROM mess_rrhh.notificacion_historial
                  WHERE id_usuario_destino = ? AND sistema = '" . SIVAC_NOTIF_SISTEMA . "'
                    AND accion = ? AND id_registro_referencia = ? AND estatus = 'NoLeida'
                  LIMIT 1"
            );
            if (!$chk) return false;
            $chk->bind_param('isi', $destino, $accion, $idRegistro);
            $chk->execute();
            $existe = $chk->get_result()->num_rows > 0;
            $chk->close();
            if ($existe) return false;
        }

        $texto = mb_substr($recordar, 0, 500);
        $stmt = $conn->prepare(
            "INSERT INTO mess_rrhh.notificacion_historial
                (id_usuario_actualiza, id_usuario_destino, accion, sistema, archivo,
                 id_registro_referencia, fecha_creacion, fecha_atencion, recordar, estatus)
             VALUES (?, ?, ?, '" . SIVAC_NOTIF_SISTEMA . "', ?, ?, NOW(), NULL, ?, 'NoLeida')"
        );
        if (!$stmt) return false;
        $stmt->bind_param('iissis', $origen, $destino, $accion, $archivo, $idRegistro, $texto);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /**
     * Registra una notificación (campana y/o correo) y deja bitácora.
     *
     * @param array $datos Claves usadas:
     *   - destino_no_empleado ?int  → empleado que verá la campana (null = solo correo)
     *   - destinos_no_empleado ?int[] → varios destinatarios de campana (p. ej. todo
     *                                 RRHH). La bitácora y el correo siguen siendo uno.
     *   - origen_no_empleado ?int   → quién lo provocó (default: la sesión; 0 cuando
     *                                 fue el candidato). No se notifica a sí mismo.
     *   - dedup ?bool               → no repetir la campana si el destinatario ya
     *                                 tiene una sin leer del mismo evento/registro.
     *   - id_vacante ?int, id_candidato ?int
     *   - titulo string, mensaje string, url ?string  → contenido de la campana
     *   - correos string[]           → destinatarios de correo (externos o internos)
     *   - correo_asunto ?string, correo_titulo ?string, correo_html ?string
     *     Si falta correo_html, no se envía correo (solo campana).
     * @return int id de la fila de notificaciones insertada.
     */
    function notificarEvento(mysqli $conn, string $evento, array $datos): int {
        // Un evento puede tener VARIOS destinatarios de campana (p. ej. una
        // requisición pendiente de VoBo: todavía no es de nadie en particular, la
        // ve todo RRHH). La bitácora y el correo siguen siendo uno solo.
        $destinos = array_values(array_unique(array_filter(array_map(
            'intval', $datos['destinos_no_empleado'] ?? []
        ))));
        $destino    = isset($datos['destino_no_empleado']) ? (int)$datos['destino_no_empleado'] : ($destinos[0] ?? null);
        if (!$destinos && $destino) $destinos = [$destino];
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

        // Campana del portal: misma tabla que el resto de los sistemas de MESS.
        // Sólo para empleados internos; el candidato se entera por correo.
        if ($destinos) {
            $origen = isset($datos['origen_no_empleado'])
                ? (int)$datos['origen_no_empleado']
                : (int)(sivacAuthNoEmpleado() ?? 0);
            $recordar = $mensaje !== '' ? $titulo . ' · ' . $mensaje : $titulo;
            $accion   = sivacNotifAccion($evento);
            $registro = (int)($idCandidato ?: $idVacante ?: 0);
            $dedup    = !empty($datos['dedup']);
            foreach ($destinos as $d) {
                // El archivo se resuelve POR DESTINATARIO: RRHH y el solicitante
                // no abren la misma pantalla.
                sivacNotifPortal($conn, $d, $origen, $accion,
                    sivacNotifArchivo($conn, $d, $url), $registro, $recordar, $dedup);
            }
        }
        return $id;
    }

    /** Contador de notificaciones de SIVAC no leídas de un empleado (campana del portal). */
    function sivacNoLeidas(mysqli $conn, int $noEmpleado): int {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM mess_rrhh.notificacion_historial
             WHERE sistema = '" . SIVAC_NOTIF_SISTEMA . "' AND id_usuario_destino = ? AND estatus = 'NoLeida'"
        );
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        return $n;
    }
}
