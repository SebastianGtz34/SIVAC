<?php
/**
 * acciones_vacantes.php — CRUD de vacantes (JSON). Gate: RRHH.
 * Todas las consultas usan sentencias preparadas con bind_param.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/flujo.php';

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// Transiciones de estatus de vacante permitidas (RRHH).
$TRANS_VAC = [
    'abierta'    => ['en_proceso', 'pausada', 'cancelada'],
    'en_proceso' => ['pausada', 'cerrada', 'cancelada'],
    'pausada'    => ['abierta', 'en_proceso', 'cancelada'],
    'cerrada'    => ['en_proceso'],
    'cancelada'  => ['abierta'],
];

/** Valida que un noEmpleado exista y esté activo en mess_rrhh.usuarios. */
function empleadoActivo(mysqli $conn, int $no): ?array {
    $stmt = $conn->prepare("SELECT noEmpleado, nombre, departamento FROM mess_rrhh.usuarios WHERE noEmpleado = ? AND estatus = 1 LIMIT 1");
    $stmt->bind_param('i', $no);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

switch ($accion) {

    case 'listar': {
        $filtroestatus = $_GET['estatus'] ?? $_POST['estatus'] ?? '';
        $estatussValidos = ['abierta', 'en_proceso', 'pausada', 'cerrada', 'cancelada'];

        // Contadores por vacante: candidatos estatus y entrevistados+ (no descartados).
        $sql = "SELECT v.id, v.folio, v.puesto, v.departamento, v.no_empleado_solicitante,
                       v.posiciones, v.estatus, v.publicada_en AS occ_publicada, v.url_publicacion AS occ_url, v.fecha_creacion,
                       (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id) AS total_candidatos,
                       (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                          AND c.estatus IN ('entrevista_confirmada','entrevistado','propuesta_enviada',
                                           'propuesta_expirada','propuesta_aceptada','documentacion','contratado')) AS total_entrevistados,
                       (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'contratado') AS total_contratados
                FROM vacantes v";
        $params = []; $tipos = '';
        if (in_array($filtroestatus, $estatussValidos, true)) {
            $sql .= " WHERE v.estatus = ?";
            $tipos .= 's'; $params[] = $filtroestatus;
        }
        $sql .= " ORDER BY v.id DESC";
        $stmt = $conn->prepare($sql);
        if ($tipos) $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) {
            $sol = obtenerDatosEmpleado($conn, (int)$r['no_empleado_solicitante']);
            $r['solicitante_nombre'] = $sol['nombre'] ?? ('#' . $r['no_empleado_solicitante']);
            $data[] = $r;
        }
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'detalle': {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare("SELECT *, publicada_en AS occ_publicada, fecha_publicada AS occ_fecha, url_publicacion AS occ_url FROM vacantes WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $vac = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$vac) responder(false, 'Vacante no encontrada.');
        $sol = obtenerDatosEmpleado($conn, (int)$vac['no_empleado_solicitante']);
        $vac['solicitante_nombre'] = $sol['nombre'] ?? ('#' . $vac['no_empleado_solicitante']);
        $vac['solicitante_correo'] = $sol['correo'] ?? '';
        responder(true, '', ['data' => $vac]);
    }

    case 'crear': {
        $puesto      = trim($_POST['puesto'] ?? '');
        $departamento= (int)($_POST['departamento'] ?? 0);
        $solicitante = (int)($_POST['no_empleado_solicitante'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $posiciones  = max(1, (int)($_POST['posiciones'] ?? 1));

        if ($puesto === '')       responder(false, 'El puesto es obligatorio.');
        if ($departamento <= 0)   responder(false, 'Selecciona el departamento.');
        $emp = empleadoActivo($conn, $solicitante);
        if (!$emp)                responder(false, 'El solicitante no existe o no está activo.');

        // Folio VAC-AAAA-#### secuencial por año.
        $anio = date('Y');
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM vacantes WHERE YEAR(fecha_creacion) = ?");
        $stmt->bind_param('i', $anio);
        $stmt->execute();
        $sec = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0) + 1;
        $stmt->close();
        $folio = sprintf('VAC-%s-%04d', $anio, $sec);

        $stmt = $conn->prepare(
            "INSERT INTO vacantes (folio, puesto, departamento, no_empleado_solicitante, descripcion, posiciones, creador_por)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssiisii', $folio, $puesto, $departamento, $solicitante, $descripcion, $posiciones, $noEmp);
        $ok = $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        responder($ok, $ok ? 'Vacante creada.' : 'No se pudo crear la vacante.', $ok ? ['id' => $id, 'folio' => $folio] : []);
    }

    case 'editar': {
        $id          = (int)($_POST['id'] ?? 0);
        $puesto      = trim($_POST['puesto'] ?? '');
        $departamento= (int)($_POST['departamento'] ?? 0);
        $solicitante = (int)($_POST['no_empleado_solicitante'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $posiciones  = max(1, (int)($_POST['posiciones'] ?? 1));

        if ($id <= 0)             responder(false, 'Id inválido.');
        if ($puesto === '')       responder(false, 'El puesto es obligatorio.');
        if ($departamento <= 0)   responder(false, 'Selecciona el departamento.');
        if (!empleadoActivo($conn, $solicitante)) responder(false, 'El solicitante no existe o no está activo.');

        $stmt = $conn->prepare(
            "UPDATE vacantes SET puesto = ?, departamento = ?, no_empleado_solicitante = ?, descripcion = ?, posiciones = ?
             WHERE id = ?"
        );
        $stmt->bind_param('siisii', $puesto, $departamento, $solicitante, $descripcion, $posiciones, $id);
        $ok = $stmt->execute();
        $stmt->close();
        responder($ok, $ok ? 'Vacante actualizada.' : 'No se pudo actualizar.');
    }

    case 'cambiar_estatus': {
        global $TRANS_VAC;
        $id     = (int)($_POST['id'] ?? 0);
        $nuevo  = $_POST['estatus'] ?? '';
        $motivo = trim($_POST['motivo'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');

        $stmt = $conn->prepare("SELECT estatus FROM vacantes WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) responder(false, 'Vacante no encontrada.');

        $actual = $row['estatus'];
        if (!in_array($nuevo, $TRANS_VAC[$actual] ?? [], true)) {
            responder(false, 'Cambio de estatus no permitido.');
        }
        if ($nuevo === 'cancelada' && $motivo === '') {
            responder(false, 'Indica el motivo de cancelación.');
        }

        if ($nuevo === 'cerrada') {
            $stmt = $conn->prepare("UPDATE vacantes SET estatus = 'cerrada', fecha_cierre = NOW() WHERE id = ? AND estatus = ?");
            $stmt->bind_param('is', $id, $actual);
        } elseif ($nuevo === 'cancelada') {
            $stmt = $conn->prepare("UPDATE vacantes SET estatus = 'cancelada', motivo_cancelacion = ? WHERE id = ? AND estatus = ?");
            $stmt->bind_param('sis', $motivo, $id, $actual);
        } else {
            $stmt = $conn->prepare("UPDATE vacantes SET estatus = ? WHERE id = ? AND estatus = ?");
            $stmt->bind_param('sis', $nuevo, $id, $actual);
        }
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        responder($ok, $ok ? 'estatus actualizado.' : 'El estatus cambió; recarga e inténtalo de nuevo.');
    }

    case 'marcar_occ': {
        $id        = (int)($_POST['id'] ?? 0);
        $publicada = !empty($_POST['occ_publicada']) ? 1 : 0;
        $url       = trim($_POST['occ_url'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            responder(false, 'La URL de OCC no es válida.');
        }
        $fecha = $publicada ? date('Y-m-d') : null;
        $urlVal = $url !== '' ? $url : null;
        $stmt = $conn->prepare("UPDATE vacantes SET publicada_en = ?, fecha_publicada = ?, url_publicacion = ? WHERE id = ?");
        $stmt->bind_param('issi', $publicada, $fecha, $urlVal, $id);
        $ok = $stmt->execute();
        $stmt->close();
        responder($ok, $ok ? 'Publicación en OCC actualizada.' : 'No se pudo actualizar.');
    }

    case 'empleados': {
        // Directorio para el selector de solicitante (empleados estatus).
        $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
        $like = '%' . $q . '%';
        $stmt = $conn->prepare(
            "SELECT noEmpleado, nombre, departamento FROM mess_rrhh.usuarios
             WHERE estatus = 1 AND (nombre LIKE ? OR noEmpleado LIKE ?)
             ORDER BY nombre LIMIT 50"
        );
        $stmt->bind_param('ss', $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'departamentos': {
        $res = $conn->query("SELECT id, departamento FROM mess_rrhh.departamento WHERE estatus = 1 ORDER BY departamento");
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }

    default:
        responder(false, 'Acción no reconocida.');
}
