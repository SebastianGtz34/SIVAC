<?php
/**
 * acciones_cierre.php — Propuesta, documentación y alta (JSON). Gate: RRHH.
 * Ejecuta expiración lazy de propuestas al listar. Documentos validados por
 * firma de bytes. El alta notifica a las áreas del catálogo.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/archivos.php';
require_once 'includes/flujo.php';
require_once 'includes/notificaciones.php';

if (sivacPostDesbordado()) {
    echo json_encode(['success' => false, 'message' => 'El archivo excede el tamaño máximo permitido por el servidor.']);
    exit;
}

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function ctxCandidato(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare(
        "SELECT c.id, c.nombre, c.correo, c.estatus,
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
        sivacExpirarPropuestas($conn);
        $sql = "SELECT c.id, c.nombre, c.correo, c.estatus, v.folio, v.puesto,
                       (SELECT p.fecha_caducidad FROM propuestas p WHERE p.id_candidato = c.id AND p.estatus = 'enviada' ORDER BY p.id DESC LIMIT 1) AS caducidad,
                       ct.fecha_ingreso, ct.fecha_limite_documentos, ct.prorrogas, ct.reglamento_enviado, ct.estatus AS contr_estatus
                FROM candidatos c
                INNER JOIN vacantes v ON v.id = c.id_vacante
                LEFT JOIN contrataciones ct ON ct.id_candidato = c.id
                WHERE c.estatus IN ('entrevistado','propuesta_enviada','propuesta_expirada','propuesta_aceptada','documentacion','contratado')
                ORDER BY FIELD(c.estatus,'propuesta_enviada','entrevistado','propuesta_expirada','propuesta_aceptada','documentacion','contratado'), c.id DESC";
        $res = $conn->query($sql);
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }

    case 'tipos_documento': {
        $res = $conn->query("SELECT id, nombre, obligatorio FROM documentos_tipos WHERE estatus = 1 ORDER BY obligatorio DESC, nombre");
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }

    case 'enviar_propuesta': {
        $id          = (int)($_POST['id'] ?? 0);
        $caducidad   = trim($_POST['fecha_caducidad'] ?? '');
        $condiciones = trim($_POST['condiciones'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $tc = strtotime($caducidad);
        if (!$tc || $tc < strtotime('today')) responder(false, 'La fecha de caducidad debe ser futura.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if (!in_array($c['estatus'], ['entrevistado', 'propuesta_expirada'], true)) {
            responder(false, 'El candidato no está listo para recibir una propuesta.');
        }

        $fechaCad = date('Y-m-d', $tc);
        $stmt = $conn->prepare(
            "INSERT INTO propuestas (id_candidato, condiciones, fecha_caducidad, capturado_por, documento, sueldo_propuesto)
             VALUES (?, ?, ?, ?, '', '')"
        );
        $stmt->bind_param('issi', $id, $condiciones, $fechaCad, $noEmp);
        $stmt->execute(); $stmt->close();

        $r = cambiarestatusCandidato($conn, $id, 'propuesta_enviada', $noEmp, 'Propuesta enviada (caduca ' . $fechaCad . ').');
        if (!$r['ok']) responder(false, $r['message']);

        $cuerpo = 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Nos complace enviarte una propuesta para la vacante <strong>'
            . htmlspecialchars($c['puesto']) . '</strong>.<br><br>'
            . ($condiciones ? '<strong>Condiciones:</strong><br>' . nl2br(htmlspecialchars($condiciones)) . '<br><br>' : '')
            . 'Esta propuesta es válida hasta el <strong>' . date('d/m/Y', $tc) . '</strong>. '
            . 'Por favor responde antes de esa fecha.';
        notificarEvento($conn, 'propuesta_enviada', [
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Propuesta enviada a ' . $c['nombre'],
            'correos' => [$c['correo']],
            'correo_asunto' => 'MESS — Propuesta laboral (' . $c['folio'] . ')',
            'correo_titulo' => 'Propuesta laboral',
            'correo_html' => $cuerpo,
        ]);
        responder(true, 'Propuesta enviada.');
    }

    case 'responder_propuesta': {
        $id        = (int)($_POST['id'] ?? 0);
        $respuesta = $_POST['respuesta'] ?? '';  // 'aceptada' | 'rechazada'
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');

        // Propuesta vigente 'enviada'. Si caducó, se expira y se rechaza la acción.
        $stmt = $conn->prepare("SELECT id, fecha_caducidad FROM propuestas WHERE id_candidato = ? AND estatus = 'enviada' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $prop = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$prop) responder(false, 'No hay una propuesta vigente.');
        if (strtotime($prop['fecha_caducidad']) < strtotime('today')) {
            sivacExpirarPropuestas($conn);
            responder(false, 'La propuesta ya expiró; envía una nueva.');
        }

        $idProp = (int)$prop['id'];
        if ($respuesta === 'aceptada') {
            $updP = $conn->prepare("UPDATE propuestas SET estatus = 'aceptada', fecha_respuesta = NOW() WHERE id = ?");
            $updP->bind_param('i', $idProp);
            $updP->execute();
            $updP->close();
            $r = cambiarestatusCandidato($conn, $id, 'propuesta_aceptada', $noEmp, 'Propuesta aceptada por el candidato.');
            if (!$r['ok']) responder(false, $r['message']);
            // Avance automático a documentación + creación de la contratación.
            cambiarestatusCandidato($conn, $id, 'documentacion', $noEmp, 'Inicia proceso de documentación.');
            $limite = date('Y-m-d', strtotime('+15 days'));
            $stmt = $conn->prepare("INSERT IGNORE INTO contrataciones (id_candidato, fecha_limite_documentos) VALUES (?, ?)");
            $stmt->bind_param('is', $id, $limite);
            $stmt->execute(); $stmt->close();
            responder(true, 'Propuesta aceptada. Candidato en documentación.');
        } elseif ($respuesta === 'rechazada') {
            $updP = $conn->prepare("UPDATE propuestas SET estatus = 'rechazada', fecha_respuesta = NOW() WHERE id = ?");
            $updP->bind_param('i', $idProp);
            $updP->execute();
            $updP->close();
            $r = cambiarestatusCandidato($conn, $id, 'descartado', $noEmp, 'Propuesta rechazada por el candidato.');
            if (!$r['ok']) responder(false, $r['message']);
            $etapa = 'propuesta';
            $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = 'Rechazó la propuesta' WHERE id = ?");
            $stmt->bind_param('si', $etapa, $id);
            $stmt->execute(); $stmt->close();
            responder(true, 'Propuesta rechazada; candidato descartado.');
        }
        responder(false, 'Respuesta inválida.');
    }

    case 'expirar_propuestas': {
        $n = sivacExpirarPropuestas($conn);
        responder(true, $n . ' propuesta(s) expirada(s).');
    }

    case 'subir_documento': {
        $id     = (int)($_POST['id'] ?? 0);
        $idTipo = (int)($_POST['id_tipo'] ?? 0);
        if ($id <= 0 || $idTipo <= 0) responder(false, 'Parámetros inválidos.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'documentacion') responder(false, 'El candidato no está en documentación.');

        // Tipo activo
        $stmt = $conn->prepare("SELECT 1 FROM documentos_tipos WHERE id = ? AND estatus = 1 LIMIT 1");
        $stmt->bind_param('i', $idTipo); $stmt->execute();
        $tipoOk = $stmt->get_result()->num_rows > 0; $stmt->close();
        if (!$tipoOk) responder(false, 'Tipo de documento inválido.');

        if (empty($_FILES['documento'])) responder(false, 'Adjunta el documento.');
        $doc = sivacGuardarArchivo($_FILES['documento'], ['pdf', 'jpg', 'jpeg', 'png'], SIVAC_MAX_DOC, SIVAC_DIR_DOC);
        if (!$doc['ok']) responder(false, $doc['message']);

        $stmt = $conn->prepare(
            "INSERT INTO documentos (id_candidato, id_tipo, nombre_archivo, nombre_original, mime, tamano, subido_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisssii', $id, $idTipo, $doc['nombre'], $doc['original'], $doc['mime'], $doc['tamano'], $noEmp);
        $ok = $stmt->execute(); $idDoc = (int)$conn->insert_id; $stmt->close();
        if (!$ok) { @unlink(SIVAC_DIR_DOC . $doc['nombre']); responder(false, 'No se pudo guardar el documento.'); }
        responder(true, 'Documento subido.', ['id' => $idDoc]);
    }

    case 'eliminar_documento': {
        $idDoc = (int)($_POST['id_documento'] ?? 0);
        if ($idDoc <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare("SELECT nombre_archivo FROM documentos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $idDoc); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) responder(false, 'Documento no encontrado.');
        $stmt = $conn->prepare("DELETE FROM documentos WHERE id = ?");
        $stmt->bind_param('i', $idDoc); $ok = $stmt->execute(); $stmt->close();
        if ($ok) @unlink(SIVAC_DIR_DOC . basename($row['nombre_archivo']));
        responder($ok, $ok ? 'Documento eliminado.' : 'No se pudo eliminar.');
    }

    case 'registrar_fecha_ingreso': {
        $id    = (int)($_POST['id'] ?? 0);
        $fecha = trim($_POST['fecha_ingreso'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!strtotime($fecha)) responder(false, 'Fecha inválida.');
        $fechaVal = date('Y-m-d', strtotime($fecha));
        $stmt = $conn->prepare("UPDATE contrataciones SET fecha_ingreso = ? WHERE id_candidato = ?");
        $stmt->bind_param('si', $fechaVal, $id);
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Fecha de ingreso registrada.' : 'No se pudo registrar.');
    }

    case 'prorroga_documentos': {
        $id    = (int)($_POST['id'] ?? 0);
        $fecha = trim($_POST['fecha_limite'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $tf = strtotime($fecha);
        if (!$tf) responder(false, 'Fecha inválida.');

        $stmt = $conn->prepare("SELECT fecha_limite_documentos FROM contrataciones WHERE id_candidato = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) responder(false, 'No hay contratación en curso.');
        if ($row['fecha_limite_documentos'] && $tf <= strtotime($row['fecha_limite_documentos'])) {
            responder(false, 'La prórroga debe ser posterior a la fecha límite actual.');
        }
        $fechaVal = date('Y-m-d', $tf);
        $stmt = $conn->prepare("UPDATE contrataciones SET fecha_limite_documentos = ?, prorrogas = prorrogas + 1 WHERE id_candidato = ?");
        $stmt->bind_param('si', $fechaVal, $id);
        $ok = $stmt->execute(); $stmt->close();

        $c = ctxCandidato($conn, $id);
        if ($ok && $c) {
            notificarEvento($conn, 'prorroga_documentos', [
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo' => 'Prórroga de documentos — ' . $c['nombre'],
                'correos' => [$c['correo']],
                'correo_asunto' => 'MESS — Prórroga de entrega de documentos',
                'correo_titulo' => 'Prórroga de documentos',
                'correo_html' => 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Se amplió la fecha límite para entregar tu documentación hasta el <strong>' . date('d/m/Y', $tf) . '</strong>.',
            ]);
        }
        responder($ok, $ok ? 'Prórroga registrada.' : 'No se pudo registrar.');
    }

    case 'enviar_reglamento': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'documentacion') responder(false, 'El candidato no está en documentación.');

        $stmt = $conn->prepare("UPDATE contrataciones SET reglamento_enviado = NOW() WHERE id_candidato = ?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();

        notificarEvento($conn, 'reglamento', [
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Reglamento de ingreso enviado — ' . $c['nombre'],
            'correos' => [$c['correo']],
            'correo_asunto' => 'MESS — Reglamento de ingreso',
            'correo_titulo' => 'Reglamento de ingreso',
            'correo_html' => 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Adjunto encontrarás el reglamento de ingreso. Por favor confirma su lectura con el área de Recursos Humanos.',
        ]);
        responder(true, 'Reglamento enviado.');
    }

    case 'completar_alta': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'documentacion') responder(false, 'El candidato no está en documentación.');

        // Requisitos: fecha de ingreso + reglamento + documentos obligatorios completos.
        $stmt = $conn->prepare("SELECT fecha_ingreso, reglamento_enviado FROM contrataciones WHERE id_candidato = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $ct = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$ct || !$ct['fecha_ingreso']) responder(false, 'Registra la fecha de ingreso antes de completar el alta.');
        if (!$ct['reglamento_enviado']) responder(false, 'Envía el reglamento de ingreso antes de completar el alta.');

        $stmt = $conn->prepare(
            "SELECT (SELECT COUNT(*) FROM documentos_tipos WHERE obligatorio = 1 AND estatus = 1) AS req,
                    (SELECT COUNT(DISTINCT d.id_tipo) FROM documentos d
                       INNER JOIN documentos_tipos t ON t.id = d.id_tipo
                       WHERE d.id_candidato = ? AND t.obligatorio = 1 AND t.estatus = 1) AS subidos"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $rc = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ((int)$rc['subidos'] < (int)$rc['req']) {
            responder(false, 'Faltan documentos obligatorios (' . (int)$rc['subidos'] . '/' . (int)$rc['req'] . ').');
        }

        $r = cambiarestatusCandidato($conn, $id, 'contratado', $noEmp, 'Alta completada. Fecha de ingreso ' . $ct['fecha_ingreso'] . '.');
        if (!$r['ok']) responder(false, $r['message']);
        $updCt = $conn->prepare("UPDATE contrataciones SET estatus = 'completada', alta_notificada = NOW() WHERE id_candidato = ?");
        $updCt->bind_param('i', $id);
        $updCt->execute();
        $updCt->close();
        // Cierra la vacante (asumiendo 1 posición).
        $idVac = (int)$c['id_vacante'];
        $updV = $conn->prepare("UPDATE vacantes SET estatus = 'cerrada', fecha_cierre = NOW() WHERE id = ? AND estatus IN ('abierta','en_proceso')");
        $updV->bind_param('i', $idVac);
        $updV->execute();
        $updV->close();

        // Avisos a las áreas del catálogo (TI, viáticos, teléfono, marketing) + solicitante.
        $res = $conn->query("SELECT area, correo FROM notificaciones_destinatarios WHERE activo = 1");
        $correos = []; $areas = [];
        while ($x = $res->fetch_assoc()) { $correos[] = $x['correo']; $areas[] = $x['area']; }
        $sol = obtenerDatosEmpleado($conn, (int)$c['no_empleado_solicitante']);
        if ($sol && $sol['correo']) $correos[] = $sol['correo'];

        $cuerpo = 'Se dio de alta a un nuevo colaborador:<br><br>'
            . '<strong>Nombre:</strong> ' . htmlspecialchars($c['nombre']) . '<br>'
            . '<strong>Puesto:</strong> ' . htmlspecialchars($c['puesto']) . '<br>'
            . '<strong>Vacante:</strong> ' . htmlspecialchars($c['folio']) . '<br>'
            . '<strong>Fecha de ingreso:</strong> ' . date('d/m/Y', strtotime($ct['fecha_ingreso'])) . '<br><br>'
            . 'Favor de realizar las gestiones correspondientes (correo, viáticos, teléfono, difusión).';
        notificarEvento($conn, 'alta_completada', [
            'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Alta completada — ' . $c['nombre'],
            'mensaje' => $c['puesto'] . ' · ingreso ' . date('d/m/Y', strtotime($ct['fecha_ingreso'])),
            'url' => 'contrataciones.php',
            'correos' => $correos,
            'correo_asunto' => 'MESS — Alta de nuevo colaborador (' . $c['puesto'] . ')',
            'correo_titulo' => 'Alta de nuevo colaborador',
            'correo_html' => $cuerpo,
        ]);
        responder(true, 'Alta completada. Avisos enviados a: ' . implode(', ', $areas) . '.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
