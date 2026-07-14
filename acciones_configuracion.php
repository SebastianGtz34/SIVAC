<?php
/**
 * acciones_configuracion.php — Catálogos y accesos (JSON). Gate: RRHH.
 * CRUD de documentos_tipos, notificaciones_destinatarios y accesos_consulta.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

switch ($accion) {

    /* ---- Tipos de documento ---- */
    case 'listar_tipos': {
        $res = $conn->query("SELECT id, nombre, obligatorio, estatus AS activo FROM documentos_tipos ORDER BY nombre");
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }
    case 'guardar_tipo': {
        $id          = (int)($_POST['id'] ?? 0);
        $nombre      = trim($_POST['nombre'] ?? '');
        $obligatorio = !empty($_POST['obligatorio']) ? 1 : 0;
        $activo      = !empty($_POST['activo']) ? 1 : 0;
        if ($nombre === '') responder(false, 'El nombre es obligatorio.');
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE documentos_tipos SET nombre = ?, obligatorio = ?, estatus = ? WHERE id = ?");
            $stmt->bind_param('siii', $nombre, $obligatorio, $activo, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO documentos_tipos (nombre, obligatorio, estatus) VALUES (?, ?, ?)");
            $stmt->bind_param('sii', $nombre, $obligatorio, $activo);
        }
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Tipo guardado.' : 'No se pudo guardar (¿nombre duplicado?).');
    }

    /* ---- Destinatarios de aviso de alta ---- */
    case 'listar_destinatarios': {
        $res = $conn->query("SELECT id, area, correo, activo FROM notificaciones_destinatarios ORDER BY area");
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }
    case 'guardar_destinatario': {
        $id     = (int)($_POST['id'] ?? 0);
        $area   = trim($_POST['area'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $activo = !empty($_POST['activo']) ? 1 : 0;
        if ($area === '') responder(false, 'El área es obligatoria.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) responder(false, 'Correo inválido.');
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE notificaciones_destinatarios SET area = ?, correo = ?, activo = ? WHERE id = ?");
            $stmt->bind_param('ssii', $area, $correo, $activo, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO notificaciones_destinatarios (area, correo, activo) VALUES (?, ?, ?)");
            $stmt->bind_param('ssi', $area, $correo, $activo);
        }
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Destinatario guardado.' : 'No se pudo guardar.');
    }
    case 'eliminar_destinatario': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare("DELETE FROM notificaciones_destinatarios WHERE id = ?");
        $stmt->bind_param('i', $id); $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Destinatario eliminado.' : 'No se pudo eliminar.');
    }

    /* ---- Accesos de consulta ---- */
    case 'listar_consulta': {
        $res = $conn->query(
            "SELECT ac.id, ac.no_empleado, ac.comentario, ac.activo,
                    IFNULL(u.nombre, ac.no_empleado) AS nombre
             FROM accesos_consulta ac
             LEFT JOIN mess_rrhh.usuarios u ON u.noEmpleado = ac.no_empleado
             ORDER BY nombre"
        );
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }
    case 'guardar_consulta': {
        $noEmpleado = (int)($_POST['no_empleado'] ?? 0);
        $comentario = trim($_POST['comentario'] ?? '');
        if ($noEmpleado <= 0) responder(false, 'Número de empleado inválido.');
        // Verifica que exista y esté activo en RRHH.
        $stmt = $conn->prepare("SELECT nombre FROM mess_rrhh.usuarios WHERE noEmpleado = ? AND estatus = 1 LIMIT 1");
        $stmt->bind_param('i', $noEmpleado); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) responder(false, 'El empleado no existe o no está activo.');
        // Upsert por UNIQUE(no_empleado).
        $stmt = $conn->prepare(
            "INSERT INTO accesos_consulta (no_empleado, comentario, activo) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE comentario = VALUES(comentario), activo = 1"
        );
        $stmt->bind_param('is', $noEmpleado, $comentario);
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Acceso concedido a ' . $row['nombre'] . '.' : 'No se pudo guardar.');
    }
    case 'toggle_consulta': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare("UPDATE accesos_consulta SET activo = 1 - activo WHERE id = ?");
        $stmt->bind_param('i', $id); $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Acceso actualizado.' : 'No se pudo actualizar.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
