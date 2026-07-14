<?php
/**
 * acciones_notificaciones.php — Campana de notificaciones (JSON).
 * Gate: solo sesión (cada quien ve las suyas). marcar_leida valida propiedad.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';

$noEmp = requiereSesionJson();
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

switch ($accion) {

    case 'listar': {
        // Últimas 20 del empleado + contador de no leídas.
        $stmt = $conn->prepare(
            "SELECT id, evento, titulo, mensaje, url, leida, fecha_creacion
             FROM notificaciones
             WHERE no_empleado_destino = ?
             ORDER BY id DESC LIMIT 20"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS n FROM notificaciones WHERE no_empleado_destino = ? AND leida = 0"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $noLeidas = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();

        responder(true, '', ['data' => $data, 'no_leidas' => $noLeidas]);
    }

    case 'marcar_leida': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        // Filtra por dueño: solo puede marcar sus propias notificaciones.
        $stmt = $conn->prepare(
            "UPDATE notificaciones SET leida = 1 WHERE id = ? AND no_empleado_destino = ?"
        );
        $stmt->bind_param('ii', $id, $noEmp);
        $stmt->execute();
        $stmt->close();
        responder(true);
    }

    case 'marcar_todas': {
        $stmt = $conn->prepare(
            "UPDATE notificaciones SET leida = 1 WHERE no_empleado_destino = ? AND leida = 0"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $stmt->close();
        responder(true);
    }

    default:
        responder(false, 'Acción no reconocida.');
}
