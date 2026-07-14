<?php
/**
 * acciones_proceso.php — Psicométrico y entrevista (JSON). Gate: RRHH.
 * Regla dura clave: NO hay entrevista sin psicométrico presentado.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/flujo.php';
require_once 'includes/notificaciones.php';

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

/** Contexto del candidato (estatus + datos + vacante + solicitante). */
function ctxCandidato(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare(
        "SELECT c.id, c.nombre, c.correo, c.estatus, c.psicometrico_folio,
                v.id AS id_vacante, v.folio, v.puesto, v.no_empleado_solicitante
         FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

switch ($accion) {

    case 'listar': {
        // Candidatos en etapas de proceso (aprobado → entrevistado).
        $sql = "SELECT c.id, c.nombre, c.correo, c.estatus, c.psicometrico_folio,
                       c.psicometrico_fecha_presentado, v.folio, v.puesto,
                       (SELECT ci.id FROM citas ci WHERE ci.id_candidato = c.id AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_pendiente,
                       (SELECT ci.fecha_confirmada FROM citas ci WHERE ci.id_candidato = c.id AND ci.estatus = 'confirmada' ORDER BY ci.id DESC LIMIT 1) AS cita_confirmada
                FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
                WHERE c.estatus IN ('aprobado_jefe','psicometrico_asignado','psicometrico_presentado','entrevista_confirmada','entrevistado')
                ORDER BY FIELD(c.estatus,'entrevista_confirmada','psicometrico_presentado','psicometrico_asignado','aprobado_jefe','entrevistado'), c.id DESC";
        $res = $conn->query($sql);
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }

    case 'registrar_psicometrico': {
        $id     = (int)($_POST['id'] ?? 0);
        $correo = trim($_POST['correo'] ?? '');
        $folio  = trim($_POST['folio'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) responder(false, 'Correo del examen inválido.');
        if ($folio === '') responder(false, 'El folio es obligatorio.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'aprobado_jefe') {
            responder(false, 'El candidato debe estar aprobado por el solicitante.');
        }

        // Guarda folio/correo y avanza estatus.
        $stmt = $conn->prepare("UPDATE candidatos SET psicometrico_correo = ?, psicometrico_folio = ? WHERE id = ?");
        $stmt->bind_param('ssi', $correo, $folio, $id);
        $stmt->execute(); $stmt->close();

        $r = cambiarestatusCandidato($conn, $id, 'psicometrico_asignado', $noEmp, 'Psicométrico asignado (folio ' . $folio . ').');
        if (!$r['ok']) responder(false, $r['message']);

        // Correo al candidato con instrucciones.
        $cuerpo = 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Como parte del proceso de selección para la vacante '
            . '<strong>' . htmlspecialchars($c['puesto']) . '</strong> debes presentar un examen psicométrico.<br><br>'
            . '<strong>Folio:</strong> ' . htmlspecialchars($folio) . '<br>'
            . '<strong>Correo de acceso:</strong> ' . htmlspecialchars($correo) . '<br><br>'
            . 'Presenta el examen antes de tu entrevista. Te contactaremos para agendar la cita.';
        notificarEvento($conn, 'psicometrico_asignado', [
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Psicométrico asignado a ' . $c['nombre'],
            'correos' => [$correo, $c['correo']],
            'correo_asunto' => 'MESS — Examen psicométrico (folio ' . $folio . ')',
            'correo_titulo' => 'Examen psicométrico',
            'correo_html' => $cuerpo,
        ]);
        responder(true, 'Psicométrico registrado y notificado.');
    }

    case 'marcar_presentado': {
        $id        = (int)($_POST['id'] ?? 0);
        $resultado = trim($_POST['resultado'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'psicometrico_asignado') {
            responder(false, 'El candidato no tiene un psicométrico asignado pendiente.');
        }
        $stmt = $conn->prepare("UPDATE candidatos SET psicometrico_fecha_presentado = NOW(), psicometrico_resultado = ? WHERE id = ?");
        $stmt->bind_param('si', $resultado, $id);
        $stmt->execute(); $stmt->close();

        $r = cambiarestatusCandidato($conn, $id, 'psicometrico_presentado', $noEmp, 'Psicométrico presentado' . ($resultado ? ' (' . $resultado . ').' : '.'));
        if (!$r['ok']) responder(false, $r['message']);
        responder(true, 'Psicométrico marcado como presentado.');
    }

    case 'confirmar_entrevista': {
        $id     = (int)($_POST['id'] ?? 0);
        $opcion = (int)($_POST['opcion'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!in_array($opcion, [1, 2], true)) responder(false, 'Selecciona una de las dos opciones de fecha.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        // REGLA DURA: solo se confirma entrevista si el psicométrico está presentado.
        if ($c['estatus'] !== 'psicometrico_presentado') {
            responder(false, 'El candidato no puede pasar a entrevista sin el psicométrico presentado.');
        }

        // Cita vigente pendiente.
        $stmt = $conn->prepare("SELECT id, opcion1, opcion2 FROM citas WHERE id_candidato = ? AND estatus = 'pendiente' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $cita = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$cita) responder(false, 'No hay disponibilidad registrada por el solicitante todavía.');

        $fecha = $opcion === 1 ? $cita['opcion1'] : $cita['opcion2'];
        $stmt = $conn->prepare(
            "UPDATE citas SET estatus = 'confirmada', opcion_confirmada = ?, fecha_confirmada = ?, confirmada_por = ?
             WHERE id = ? AND estatus = 'pendiente'"
        );
        $stmt->bind_param('isii', $opcion, $fecha, $noEmp, $cita['id']);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0; $stmt->close();
        if (!$ok) responder(false, 'La cita cambió; recarga e inténtalo de nuevo.');

        $r = cambiarestatusCandidato($conn, $id, 'entrevista_confirmada', $noEmp, 'Entrevista confirmada para ' . $fecha . '.');
        if (!$r['ok']) responder(false, $r['message']);

        // Correos: solicitante y candidato.
        $sol = obtenerDatosEmpleado($conn, (int)$c['no_empleado_solicitante']);
        $fechaFmt = date('d/m/Y H:i', strtotime($fecha));
        $cuerpoCand = 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Tu entrevista para la vacante <strong>'
            . htmlspecialchars($c['puesto']) . '</strong> quedó confirmada para el <strong>' . $fechaFmt . '</strong>.';
        notificarEvento($conn, 'entrevista_confirmada', [
            'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Entrevista confirmada — ' . $c['nombre'],
            'mensaje' => $c['puesto'] . ' · ' . $fechaFmt,
            'url' => '../loginMaster/inicio.php',
            'correos' => array_filter([$c['correo'], $sol['correo'] ?? '']),
            'correo_asunto' => 'MESS — Entrevista confirmada (' . $c['folio'] . ')',
            'correo_titulo' => 'Entrevista confirmada',
            'correo_html' => $cuerpoCand,
        ]);
        responder(true, 'Entrevista confirmada para ' . $fechaFmt . '.');
    }

    case 'nueva_cita': {
        // Reprogramación por RRHH: cancela la vigente y crea otra con 2 opciones.
        $id  = (int)($_POST['id'] ?? 0);
        $op1 = trim($_POST['opcion1'] ?? '');
        $op2 = trim($_POST['opcion2'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $t1 = strtotime($op1); $t2 = strtotime($op2);
        if (!$t1 || !$t2) responder(false, 'Fechas inválidas.');
        if ($t1 <= time() || $t2 <= time()) responder(false, 'Las fechas deben ser futuras.');
        if ($t1 === $t2) responder(false, 'Las dos opciones deben ser distintas.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');

        $updCi = $conn->prepare("UPDATE citas SET estatus = 'cancelada' WHERE id_candidato = ? AND estatus = 'pendiente'");
        $updCi->bind_param('i', $id);
        $updCi->execute();
        $updCi->close();
        $f1 = date('Y-m-d H:i:s', $t1); $f2 = date('Y-m-d H:i:s', $t2);
        $stmt = $conn->prepare("INSERT INTO citas (id_candidato, opcion1, opcion2, duracion_aprox) VALUES (?, ?, ?, '')");
        $stmt->bind_param('iss', $id, $f1, $f2);
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Nueva disponibilidad registrada.' : 'No se pudo registrar.');
    }

    case 'registrar_resultado_entrevista': {
        $id        = (int)($_POST['id'] ?? 0);
        $resultado = $_POST['resultado'] ?? '';  // 'aceptado' | 'descartado'
        $motivo    = trim($_POST['motivo'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'entrevista_confirmada') {
            responder(false, 'El candidato no tiene una entrevista confirmada.');
        }
        $updCi = $conn->prepare("UPDATE citas SET estatus = 'realizada' WHERE id_candidato = ? AND estatus = 'confirmada'");
        $updCi->bind_param('i', $id);
        $updCi->execute();
        $updCi->close();

        if ($resultado === 'aceptado') {
            $r = cambiarestatusCandidato($conn, $id, 'entrevistado', $noEmp, 'Entrevista realizada: aprobado para propuesta.');
            if (!$r['ok']) responder(false, $r['message']);
            responder(true, 'Candidato marcado como entrevistado (listo para propuesta).');
        } elseif ($resultado === 'descartado') {
            if ($motivo === '') responder(false, 'Indica el motivo del descarte.');
            $r = cambiarestatusCandidato($conn, $id, 'descartado', $noEmp, 'Descartado en entrevista: ' . $motivo);
            if (!$r['ok']) responder(false, $r['message']);
            $etapa = 'entrevista';
            $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = ? WHERE id = ?");
            $stmt->bind_param('ssi', $etapa, $motivo, $id);
            $stmt->execute(); $stmt->close();
            responder(true, 'Candidato descartado.');
        }
        responder(false, 'Resultado inválido.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
