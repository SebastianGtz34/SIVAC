<?php
/**
 * acciones_notificaciones.php — Campana de notificaciones (JSON).
 *
 * Lee y escribe la MISMA tabla que la campana de loginMaster
 * (`mess_rrhh.notificacion_historial`, filtrada a `sistema = 'sivac'`), no una
 * propia: así el badge de SIVAC y el del portal muestran lo mismo y marcar una
 * como leída en cualquiera de los dos la marca en ambos.
 *
 * Gate: solo sesión (cada quien ve las suyas). Marcar leída valida propiedad
 * (`id_usuario_destino = <sesión>`), nunca por un id suelto del cliente.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/respuesta.php';
require_once 'includes/notificaciones.php';

$noEmp = requiereSesionJson();
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

switch ($accion) {

    case 'listar': {
        // Últimas 20 del empleado + contador de no leídas.
        $stmt = $conn->prepare(
            "SELECT id, accion, archivo, id_registro_referencia, recordar, fecha_creacion, estatus
             FROM mess_rrhh.notificacion_historial
             WHERE sistema = ? AND id_usuario_destino = ?
             ORDER BY id DESC LIMIT 20"
        );
        $sistema = SIVAC_NOTIF_SISTEMA;
        $stmt->bind_param('si', $sistema, $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        $archivos = sivacNotifArchivos();
        while ($r = $res->fetch_assoc()) {
            // Dentro de SIVAC el destino es una ruta relativa; loginMaster resuelve
            // la suya (absoluta) por su cuenta con validaLoginNot.php.
            $archivo = in_array($r['archivo'], $archivos, true) ? $r['archivo'] : 'inicio';
            $data[] = [
                'id'             => (int)$r['id'],
                'accion'         => $r['accion'],
                'texto'          => $r['recordar'],
                'url'            => $archivo . '.php',
                'fecha_creacion' => $r['fecha_creacion'],
                'leida'          => strcasecmp((string)$r['estatus'], 'Leida') === 0 ? 1 : 0,
            ];
        }
        $stmt->close();

        responder(true, '', ['data' => $data, 'no_leidas' => sivacNoLeidas($conn, $noEmp)]);
    }

    case 'marcar_leida': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        // Filtra por dueño: solo puede marcar sus propias notificaciones.
        $stmt = $conn->prepare(
            "UPDATE mess_rrhh.notificacion_historial
                SET estatus = 'Leida', fecha_atencion = NOW()
              WHERE id = ? AND sistema = ? AND id_usuario_destino = ?"
        );
        $sistema = SIVAC_NOTIF_SISTEMA;
        $stmt->bind_param('isi', $id, $sistema, $noEmp);
        $stmt->execute();
        $stmt->close();
        responder(true);
    }

    case 'marcar_todas': {
        $stmt = $conn->prepare(
            "UPDATE mess_rrhh.notificacion_historial
                SET estatus = 'Leida', fecha_atencion = NOW()
              WHERE sistema = ? AND id_usuario_destino = ? AND estatus = 'NoLeida'"
        );
        $sistema = SIVAC_NOTIF_SISTEMA;
        $stmt->bind_param('si', $sistema, $noEmp);
        $stmt->execute();
        $stmt->close();
        responder(true);
    }

    default:
        responder(false, 'Acción no reconocida.');
}
