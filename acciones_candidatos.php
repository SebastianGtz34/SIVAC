<?php
/**
 * acciones_candidatos.php — Captura y gestión de candidatos (JSON). Gate: RRHH.
 * Subidas de CV validadas por firma de bytes. El cambio de estatus pasa SIEMPRE
 * por la máquina de estatuss (includes/flujo.php); las notificaciones por
 * includes/notificaciones.php.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/archivos.php';
require_once 'includes/flujo.php';
require_once 'includes/notificaciones.php';

// Detecta POST que excedió post_max_size antes de tocar $_POST.
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

/** Datos de la vacante (o null). */
function vacanteDe(mysqli $conn, int $id): ?array {
    $stmt = $conn->prepare("SELECT id, folio, puesto, estatus, no_empleado_solicitante FROM vacantes WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

switch ($accion) {

    case 'listar': {
        $idVacante = (int)($_POST['id_vacante'] ?? $_GET['id_vacante'] ?? 0);
        $sql = "SELECT c.id, c.nombre, c.correo, c.telefono, c.estatus, c.cv_archivo,
                       c.fecha_creacion, v.folio, v.puesto, v.id AS id_vacante
                FROM candidatos c
                INNER JOIN vacantes v ON v.id = c.id_vacante";
        $params = []; $tipos = '';
        if ($idVacante > 0) { $sql .= " WHERE c.id_vacante = ?"; $tipos .= 'i'; $params[] = $idVacante; }
        $sql .= " ORDER BY c.id DESC";
        $stmt = $conn->prepare($sql);
        if ($tipos) $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'detalle': {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare(
            "SELECT c.*, v.folio, v.puesto, v.no_empleado_solicitante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cand = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$cand) responder(false, 'Candidato no encontrado.');

        // Historial
        $stmt = $conn->prepare(
            "SELECT estatus_anterior, estatus_nuevo, no_empleado, comentario, fecha_creacion
             FROM candidatos_historial WHERE id_candidato = ? ORDER BY id DESC"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $hist = []; $rh = $stmt->get_result();
        while ($r = $rh->fetch_assoc()) $hist[] = $r;
        $stmt->close();

        // Citas, propuestas y documentos (para la ficha completa)
        $citas = [];
        $stmt = $conn->prepare("SELECT * FROM citas WHERE id_candidato = ? ORDER BY id DESC");
        $stmt->bind_param('i', $id); $stmt->execute(); $rc = $stmt->get_result();
        while ($r = $rc->fetch_assoc()) $citas[] = $r; $stmt->close();

        $props = [];
        $stmt = $conn->prepare("SELECT * FROM propuestas WHERE id_candidato = ? ORDER BY id DESC");
        $stmt->bind_param('i', $id); $stmt->execute(); $rp = $stmt->get_result();
        while ($r = $rp->fetch_assoc()) $props[] = $r; $stmt->close();

        $docs = [];
        $stmt = $conn->prepare(
            "SELECT d.id, d.nombre_original, d.tamano, d.fecha_creacion, t.nombre AS tipo
             FROM documentos d INNER JOIN documentos_tipos t ON t.id = d.id_tipo
             WHERE d.id_candidato = ? ORDER BY d.id DESC"
        );
        $stmt->bind_param('i', $id); $stmt->execute(); $rd = $stmt->get_result();
        while ($r = $rd->fetch_assoc()) $docs[] = $r; $stmt->close();

        responder(true, '', ['data' => $cand, 'historial' => $hist, 'citas' => $citas, 'propuestas' => $props, 'documentos' => $docs]);
    }

    case 'crear': {
        $idVacante = (int)($_POST['id_vacante'] ?? 0);
        $nombre    = trim($_POST['nombre'] ?? '');
        $correo    = trim($_POST['correo'] ?? '');
        $telefono  = trim($_POST['telefono'] ?? '');

        if ($nombre === '') responder(false, 'El nombre es obligatorio.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) responder(false, 'Correo inválido.');
        $vac = vacanteDe($conn, $idVacante);
        if (!$vac) responder(false, 'Vacante inválida.');
        if (!in_array($vac['estatus'], ['abierta', 'en_proceso'], true)) {
            responder(false, 'La vacante no admite nuevos candidatos (estatus ' . $vac['estatus'] . ').');
        }
        if (empty($_FILES['cv']) || ($_FILES['cv']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            responder(false, 'Adjunta el CV en PDF.');
        }
        $cv = sivacGuardarArchivo($_FILES['cv'], ['pdf'], SIVAC_MAX_CV, SIVAC_DIR_CV);
        if (!$cv['ok']) responder(false, $cv['message']);

        $stmt = $conn->prepare(
            "INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, creador_por)
             VALUES (?, ?, '', ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssssii', $idVacante, $nombre, $correo, $telefono, $cv['nombre'], $cv['original'], $cv['tamano'], $noEmp);
        $ok = $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        if (!$ok) { @unlink(SIVAC_DIR_CV . $cv['nombre']); responder(false, 'No se pudo registrar el candidato.'); }

        // Historial inicial
        $hist = $conn->prepare(
            "INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario)
             VALUES (?, 'aspirante', 'aspirante', ?, 'Candidato capturado')"
        );
        $hist->bind_param('ii', $id, $noEmp);
        $hist->execute();
        $hist->close();
        responder(true, 'Candidato registrado.', ['id' => $id]);
    }

    case 'reemplazar_cv': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        if (empty($_FILES['cv'])) responder(false, 'Adjunta el nuevo CV.');
        $stmt = $conn->prepare("SELECT cv_archivo FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) responder(false, 'Candidato no encontrado.');

        $cv = sivacGuardarArchivo($_FILES['cv'], ['pdf'], SIVAC_MAX_CV, SIVAC_DIR_CV);
        if (!$cv['ok']) responder(false, $cv['message']);

        $stmt = $conn->prepare("UPDATE candidatos SET cv_archivo = ?, cv_nombre_original = ?, cv_tamano = ? WHERE id = ?");
        $stmt->bind_param('ssii', $cv['nombre'], $cv['original'], $cv['tamano'], $id);
        $ok = $stmt->execute(); $stmt->close();
        if ($ok && $row['cv_archivo']) @unlink(SIVAC_DIR_CV . basename($row['cv_archivo']));
        responder($ok, $ok ? 'CV actualizado.' : 'No se pudo actualizar el CV.');
    }

    case 'editar': {
        $id       = (int)($_POST['id'] ?? 0);
        $nombre   = trim($_POST['nombre'] ?? '');
        $correo   = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if ($nombre === '') responder(false, 'El nombre es obligatorio.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) responder(false, 'Correo inválido.');
        $stmt = $conn->prepare("UPDATE candidatos SET nombre = ?, correo = ?, telefono = ? WHERE id = ?");
        $stmt->bind_param('sssi', $nombre, $correo, $telefono, $id);
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Candidato actualizado.' : 'No se pudo actualizar.');
    }

    case 'enviar_solicitante': {
        // Batch: "mandar candidatos" al solicitante. ids[] = lista de candidatos.
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || !$ids) responder(false, 'Selecciona al menos un candidato.');

        $enviados = 0; $errores = [];
        foreach ($ids as $raw) {
            $idc = (int)$raw;
            if ($idc <= 0) continue;
            // Relee estatus + CV + vacante.
            $stmt = $conn->prepare(
                "SELECT c.estatus, c.cv_archivo, c.nombre, v.id AS id_vacante, v.folio, v.puesto, v.no_empleado_solicitante
                 FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
            );
            $stmt->bind_param('i', $idc); $stmt->execute();
            $c = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$c) { $errores[] = "#$idc: no existe"; continue; }
            if ($c['estatus'] !== 'aspirante') { $errores[] = "#$idc: no está en captura"; continue; }
            if (!$c['cv_archivo']) { $errores[] = "#$idc: sin CV"; continue; }

            $r = cambiarestatusCandidato($conn, $idc, 'enviado_solicitante', $noEmp, 'Enviado al solicitante para revisión.');
            if (!$r['ok']) { $errores[] = "#$idc: " . $r['message']; continue; }
            $enviados++;

            // Vacante abierta → en_proceso.
            $idVac = (int)$c['id_vacante'];
            $updVac = $conn->prepare("UPDATE vacantes SET estatus = 'en_proceso' WHERE id = ? AND estatus = 'abierta'");
            $updVac->bind_param('i', $idVac);
            $updVac->execute();
            $updVac->close();

            // Notifica al solicitante (campana + correo).
            $sol = obtenerDatosEmpleado($conn, (int)$c['no_empleado_solicitante']);
            $cuerpo = 'Se te envió un candidato para revisar en la vacante <strong>' . htmlspecialchars($c['folio'] . ' — ' . $c['puesto'])
                . '</strong>.<br><br>Ingresa al portal MESS, pestaña <em>Mis Vacantes</em>, para revisar el CV y aprobar o descartar al candidato.';
            notificarEvento($conn, 'candidato_enviado', [
                'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
                'id_vacante' => (int)$c['id_vacante'], 'id_candidato' => $idc,
                'titulo' => 'Nuevo candidato para revisar — ' . $c['folio'],
                'mensaje' => 'Vacante ' . $c['puesto'],
                'url' => '../loginMaster/inicio.php',
                'correos' => $sol && $sol['correo'] ? [$sol['correo']] : [],
                'correo_asunto' => 'SIVAC — Candidato por revisar (' . $c['folio'] . ')',
                'correo_titulo' => 'Candidato por revisar',
                'correo_html' => $cuerpo,
            ]);
        }
        responder($enviados > 0, $enviados > 0 ? "$enviados candidato(s) enviado(s)." : 'No se envió ninguno.', ['errores' => $errores]);
    }

    case 'descartar': {
        $id     = (int)($_POST['id'] ?? 0);
        $motivo = trim($_POST['motivo'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if ($motivo === '') responder(false, 'Indica el motivo del descarte.');

        // estatus actual para registrar la etapa de descarte.
        $stmt = $conn->prepare("SELECT estatus FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) responder(false, 'Candidato no encontrado.');
        $etapa = $row['estatus'];

        $r = cambiarestatusCandidato($conn, $id, 'descartado', $noEmp, 'Descartado: ' . $motivo);
        if (!$r['ok']) responder(false, $r['message']);
        $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = ? WHERE id = ?");
        $stmt->bind_param('ssi', $etapa, $motivo, $id);
        $stmt->execute(); $stmt->close();
        responder(true, 'Candidato descartado.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
