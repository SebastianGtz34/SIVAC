<?php
/**
 * acciones_solicitante.php — Acciones del SOLICITANTE (JSON). Gate: solo sesión.
 *
 * La autorización NO es por departamento sino por PERTENENCIA: cada consulta
 * filtra por vacantes.no_empleado_solicitante = $noEmp (de la sesión, jamás de
 * un parámetro del cliente). Así un solicitante solo ve y actúa sobre SUS
 * vacantes aunque manipule ids en la petición.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/flujo.php';
require_once 'includes/notificaciones.php';

$noEmp = requiereSesionJson();
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

switch ($accion) {

    case 'mis_vacantes': {
        // Solo las vacantes cuyo dueño es la sesión.
        $stmt = $conn->prepare(
            "SELECT v.id, v.folio, v.puesto, v.estatus,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id) AS total,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'enviado_solicitante') AS por_revisar,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                       AND c.estatus IN ('entrevista_confirmada','entrevistado','propuesta_enviada',
                                        'propuesta_expirada','propuesta_aceptada','documentacion','contratado')) AS entrevistados
             FROM vacantes v
             WHERE v.no_empleado_solicitante = ?
             ORDER BY v.id DESC"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'mis_candidatos': {
        // Candidatos de MIS vacantes, desde 'enviado_solicitante' en adelante.
        $stmt = $conn->prepare(
            "SELECT c.id, c.nombre, c.estatus, c.cv_archivo, v.folio, v.puesto,
                    (SELECT ci.estatus FROM citas ci WHERE ci.id_candidato = c.id ORDER BY ci.id DESC LIMIT 1) AS cita_estatus,
                    (SELECT ci.fecha_confirmada FROM citas ci WHERE ci.id_candidato = c.id AND ci.estatus='confirmada' ORDER BY ci.id DESC LIMIT 1) AS cita_confirmada
             FROM candidatos c
             INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE v.no_empleado_solicitante = ?
               AND c.estatus <> 'aspirante'
             ORDER BY FIELD(c.estatus,'enviado_solicitante') DESC, c.id DESC"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'aprobar_cv': {
        $id  = (int)($_POST['id'] ?? 0);
        $op1 = trim($_POST['opcion1'] ?? '');
        $op2 = trim($_POST['opcion2'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        // Ownership: el candidato debe pertenecer a una vacante de la sesión.
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');

        $t1 = strtotime($op1); $t2 = strtotime($op2);
        if (!$t1 || !$t2) responder(false, 'Indica dos fechas válidas para la entrevista.');
        if ($t1 <= time() || $t2 <= time()) responder(false, 'Las fechas deben ser futuras.');
        if ($t1 === $t2) responder(false, 'Las dos opciones deben ser distintas.');

        // estatus correcto
        $stmt = $conn->prepare("SELECT estatus FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row || $row['estatus'] !== 'enviado_solicitante') responder(false, 'El candidato no está pendiente de tu revisión.');

        // Crea la cita con las 2 opciones y avanza el estatus.
        $f1 = date('Y-m-d H:i:s', $t1); $f2 = date('Y-m-d H:i:s', $t2);
        $stmt = $conn->prepare("INSERT INTO citas (id_candidato, opcion1, opcion2, duracion_aprox) VALUES (?, ?, ?, '')");
        $stmt->bind_param('iss', $id, $f1, $f2);
        $stmt->execute(); $stmt->close();

        $r = cambiarestatusCandidato($conn, $id, 'aprobado_jefe', $noEmp, 'CV aprobado por el solicitante; disponibilidad registrada.');
        if (!$r['ok']) responder(false, $r['message']);

        // Notifica a RRHH (creador de la vacante).
        $stmt = $conn->prepare(
            "SELECT c.nombre, v.folio, v.puesto, v.creador_por AS no_empleado_creador, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            $rrhh = obtenerDatosEmpleado($conn, (int)$info['no_empleado_creador']);
            notificarEvento($conn, 'cv_aprobado', [
                'destino_no_empleado' => (int)$info['no_empleado_creador'],
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => 'CV aprobado — ' . $info['nombre'],
                'mensaje' => $info['folio'] . ' · ' . $info['puesto'] . ': registra el psicométrico.',
                'url' => 'seguimiento.php',
                'correos' => $rrhh && $rrhh['correo'] ? [$rrhh['correo']] : [],
                'correo_asunto' => 'SIVAC — CV aprobado por el solicitante (' . $info['folio'] . ')',
                'correo_titulo' => 'CV aprobado',
                'correo_html' => 'El solicitante aprobó el CV de <strong>' . htmlspecialchars($info['nombre'])
                    . '</strong> para la vacante <strong>' . htmlspecialchars($info['puesto'])
                    . '</strong> y registró su disponibilidad para entrevista.<br><br>Siguiente paso: asignar el examen psicométrico.',
            ]);
        }
        responder(true, 'CV aprobado y disponibilidad registrada.');
    }

    case 'descartar_cv': {
        $id     = (int)($_POST['id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');
        if ($motivo === '') responder(false, 'Indica el motivo del descarte.');

        $stmt = $conn->prepare("SELECT estatus FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row || $row['estatus'] !== 'enviado_solicitante') responder(false, 'El candidato no está pendiente de tu revisión.');

        $r = cambiarestatusCandidato($conn, $id, 'descartado', $noEmp, 'CV descartado por el solicitante: ' . $motivo);
        if (!$r['ok']) responder(false, $r['message']);
        $etapa = 'solicitante';
        $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = ? WHERE id = ?");
        $stmt->bind_param('ssi', $etapa, $motivo, $id);
        $stmt->execute(); $stmt->close();

        // Notifica a RRHH.
        $stmt = $conn->prepare(
            "SELECT c.nombre, v.folio, v.creador_por AS no_empleado_creador, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            $rrhh = obtenerDatosEmpleado($conn, (int)$info['no_empleado_creador']);
            notificarEvento($conn, 'cv_descartado', [
                'destino_no_empleado' => (int)$info['no_empleado_creador'],
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => 'CV descartado — ' . $info['nombre'],
                'mensaje' => $info['folio'] . ': ' . $motivo,
                'url' => 'candidatos.php',
                'correos' => $rrhh && $rrhh['correo'] ? [$rrhh['correo']] : [],
                'correo_asunto' => 'SIVAC — CV descartado por el solicitante (' . $info['folio'] . ')',
                'correo_titulo' => 'CV descartado',
                'correo_html' => 'El solicitante descartó el CV de <strong>' . htmlspecialchars($info['nombre'])
                    . '</strong>.<br><br><strong>Motivo:</strong> ' . htmlspecialchars($motivo),
            ]);
        }
        responder(true, 'Candidato descartado.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
