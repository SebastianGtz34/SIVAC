<?php
/**
 * acciones_candidatos.php — Captura y gestión de candidatos (JSON). Gate: RRHH.
 * Subidas de CV validadas por firma de bytes. El cambio de estatus pasa SIEMPRE
 * por la máquina de estatus (includes/flujo.php); las notificaciones por
 * includes/notificaciones.php.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/archivos.php';
require_once 'includes/flujo.php';
require_once 'includes/notificaciones.php';
require_once 'includes/catalogos.php';

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

/**
 * Sanea la constancia de la entrevista de RRHH de un request.
 * Devuelve ['error'=>?string, 'fecha'=>?string, 'resultado'=>?string, 'observaciones'=>?string].
 * Todo es opcional al guardar; la obligatoriedad se exige al enviar al jefe.
 */
/** id de catálogo (nave/region) del POST: entero positivo o null si viene vacío. */
function idCatalogoOpcional(array $post, string $campo): ?int {
    $v = (int)($post[$campo] ?? 0);
    return $v > 0 ? $v : null;
}

function sanearConstanciaRrhh(array $post): array {
    $fechaRaw = trim($post['entrevista_rrhh_fecha'] ?? '');
    $fecha = $fechaRaw !== '' && strtotime($fechaRaw) ? date('Y-m-d', strtotime($fechaRaw)) : null;
    if ($fechaRaw !== '' && $fecha === null) {
        return ['error' => 'La fecha de la entrevista de RRHH no es válida.'];
    }
    $res = trim($post['entrevista_rrhh_resultado'] ?? '');
    if ($res !== '' && !in_array($res, ['apto', 'no_apto'], true)) {
        return ['error' => 'Resultado de la entrevista de RRHH inválido.'];
    }
    $obs = trim($post['entrevista_rrhh_observaciones'] ?? '');
    return [
        'error' => null,
        'fecha' => $fecha,
        'resultado' => $res !== '' ? $res : null,
        'observaciones' => $obs !== '' ? $obs : null,
    ];
}

/**
 * Sanea el psicométrico (informativo) de un request. Espeja sanearConstanciaRrhh
 * más un campo: la calificación (VARCHAR libre, sin validación numérica: aún está
 * pendiente cómo se evalúa). NO descarta ni bloquea nada — sólo deja constancia.
 * Devuelve ['error'=>?string, 'fecha'=>?, 'calificacion'=>?, 'resultado'=>?, 'observaciones'=>?].
 */
function sanearPsicometrico(array $post): array {
    $fechaRaw = trim($post['psicometrico_fecha'] ?? '');
    $fecha = $fechaRaw !== '' && strtotime($fechaRaw) ? date('Y-m-d', strtotime($fechaRaw)) : null;
    if ($fechaRaw !== '' && $fecha === null) {
        return ['error' => 'La fecha del psicométrico no es válida.'];
    }
    $res = trim($post['psicometrico_resultado'] ?? '');
    if ($res !== '' && !in_array($res, ['apto', 'no_apto'], true)) {
        return ['error' => 'Resultado del psicométrico inválido.'];
    }
    $cal = trim($post['psicometrico_calificacion'] ?? '');
    $obs = trim($post['psicometrico_observaciones'] ?? '');
    return [
        'error' => null,
        'fecha' => $fecha,
        'calificacion' => $cal !== '' ? mb_substr($cal, 0, 30) : null,
        'resultado' => $res !== '' ? $res : null,
        'observaciones' => $obs !== '' ? $obs : null,
    ];
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
        // Tablero único del pipeline: además de los datos del candidato trae la
        // cita de la entrevista del jefe, para que la UI ofrezca inline la acción
        // que toca (confirmar / agendar / resultado) sin una pantalla aparte.
        $idVacante = (int)($_POST['id_vacante'] ?? $_GET['id_vacante'] ?? 0);
        $sql = "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.correo, c.telefono, c.estatus, c.cv_archivo,
                       c.fecha_creacion, v.folio, v.puesto, v.id AS id_vacante,
                       (SELECT ci.id FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_pendiente,
                       (SELECT DATE_FORMAT(ci.opcion1, '%d/%m/%Y %H:%i') FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_op1,
                       (SELECT DATE_FORMAT(ci.opcion2, '%d/%m/%Y %H:%i') FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_op2,
                       (SELECT ci.fecha_confirmada FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus IN ('confirmada','realizada') ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_confirmada,
                       (SELECT ci.notas FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_notas
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

        // Historial (con el nombre del actor resuelto; no_empleado = 0 es el sistema).
        $stmt = $conn->prepare(
            "SELECT h.estatus_anterior, h.estatus_nuevo, h.no_empleado, h.comentario, h.fecha_creacion,
                    u.nombre AS actor_nombre
             FROM candidatos_historial h
             LEFT JOIN mess_rrhh.usuarios u ON u.noEmpleado = h.no_empleado
             WHERE h.id_candidato = ? ORDER BY h.id DESC"
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
            "SELECT d.id, d.nombre_original, d.tamano, d.fecha_creacion, t.nombre AS tipo,
                    d.validacion, d.validado_fecha, d.motivo_validacion
             FROM documentos d INNER JOIN documentos_tipos t ON t.id = d.id_tipo
             WHERE d.id_candidato = ? ORDER BY d.id DESC"
        );
        $stmt->bind_param('i', $id); $stmt->execute(); $rd = $stmt->get_result();
        while ($r = $rd->fetch_assoc()) $docs[] = $r; $stmt->close();

        responder(true, '', ['data' => $cand, 'historial' => $hist, 'citas' => $citas, 'propuestas' => $props, 'documentos' => $docs]);
    }

    case 'catalogos': {
        // Naves y regiones (viven en mess_rrhh) para los selects del form de candidato.
        $naves = [];
        foreach (catalogoNaves($conn) as $id => $nombre) { $naves[] = ['id' => $id, 'nave' => $nombre]; }
        $regiones = [];
        foreach (catalogoRegiones($conn) as $id => $nombre) { $regiones[] = ['id' => $id, 'region' => $nombre]; }
        responder(true, '', ['naves' => $naves, 'regiones' => $regiones]);
    }

    case 'crear': {
        $idVacante = (int)($_POST['id_vacante'] ?? 0);
        $nombre    = trim($_POST['nombre'] ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $nave      = idCatalogoOpcional($_POST, 'nave');
        $region    = idCatalogoOpcional($_POST, 'region');
        $correo    = trim($_POST['correo'] ?? '');
        $telefono  = trim($_POST['telefono'] ?? '');
        // Constancia de la entrevista de RRHH (fuera del sistema). Opcional al
        // crear, pero obligatoria para poder enviar el candidato al jefe.
        $entRrhh = sanearConstanciaRrhh($_POST);
        if ($entRrhh['error']) responder(false, $entRrhh['error']);
        [$entRrhhFecha, $entRrhhResVal, $entRrhhObsVal] = [$entRrhh['fecha'], $entRrhh['resultado'], $entRrhh['observaciones']];

        if ($nombre === '') responder(false, 'El nombre es obligatorio.');
        if ($apellidos === '') responder(false, 'Los apellidos son obligatorios.');
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
            "INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, entrevista_rrhh_fecha, entrevista_rrhh_resultado, entrevista_rrhh_observaciones, nave, region, creador_por)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssssisssiii', $idVacante, $nombre, $apellidos, $correo, $telefono, $cv['nombre'], $cv['original'], $cv['tamano'], $entRrhhFecha, $entRrhhResVal, $entRrhhObsVal, $nave, $region, $noEmp);
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

        // El veredicto de RRHH (incluido "No apto") ya NO descarta solo: nadie
        // se descarta sin un botón explícito.
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
        $apellidos= trim($_POST['apellidos'] ?? '');
        $correo   = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $nave     = idCatalogoOpcional($_POST, 'nave');
        $region   = idCatalogoOpcional($_POST, 'region');
        $entRrhh = sanearConstanciaRrhh($_POST);
        if ($id <= 0) responder(false, 'Id inválido.');
        if ($nombre === '') responder(false, 'El nombre es obligatorio.');
        if ($apellidos === '') responder(false, 'Los apellidos son obligatorios.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) responder(false, 'Correo inválido.');
        if ($entRrhh['error']) responder(false, $entRrhh['error']);
        [$entRrhhFecha, $entRrhhResVal, $entRrhhObsVal] = [$entRrhh['fecha'], $entRrhh['resultado'], $entRrhh['observaciones']];
        $stmt = $conn->prepare("UPDATE candidatos SET nombre = ?, apellidos = ?, correo = ?, telefono = ?, entrevista_rrhh_fecha = ?, entrevista_rrhh_resultado = ?, entrevista_rrhh_observaciones = ?, nave = ?, region = ? WHERE id = ?");
        $stmt->bind_param('sssssssiii', $nombre, $apellidos, $correo, $telefono, $entRrhhFecha, $entRrhhResVal, $entRrhhObsVal, $nave, $region, $id);
        $ok = $stmt->execute(); $stmt->close();
        if (!$ok) responder(false, 'No se pudo actualizar.');
        // Un "No apto" ya NO descarta solo (nadie se descarta sin botón explícito).
        responder(true, 'Candidato actualizado.');
    }

    case 'registrar_psicometrico': {
        // Psicométrico PURAMENTE INFORMATIVO: no cambia estatus, no descarta y un
        // "no apto" no bloquea nada. Sólo actualiza los 4 campos en candidatos.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $ps = sanearPsicometrico($_POST);
        if ($ps['error']) responder(false, $ps['error']);

        $stmt = $conn->prepare("SELECT id FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $existe = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$existe) responder(false, 'Candidato no encontrado.');

        $stmt = $conn->prepare(
            "UPDATE candidatos SET psicometrico_fecha = ?, psicometrico_calificacion = ?,
                    psicometrico_resultado = ?, psicometrico_observaciones = ? WHERE id = ?"
        );
        $stmt->bind_param('ssssi', $ps['fecha'], $ps['calificacion'], $ps['resultado'], $ps['observaciones'], $id);
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Psicométrico guardado.' : 'No se pudo guardar el psicométrico.');
    }

    case 'enviar_solicitante': {
        // Batch: "mandar candidatos" al solicitante. ids[] = lista de candidatos.
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || !$ids) responder(false, 'Selecciona al menos un candidato.');

        $enviados = 0; $errores = [];
        foreach ($ids as $raw) {
            $idc = (int)$raw;
            if ($idc <= 0) continue;
            // Relee estatus + CV + constancia RRHH + vacante.
            $stmt = $conn->prepare(
                "SELECT c.estatus, c.cv_archivo, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre,
                        c.entrevista_rrhh_fecha, c.entrevista_rrhh_resultado,
                        v.id AS id_vacante, v.folio, v.puesto, v.no_empleado_solicitante
                 FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
            );
            $stmt->bind_param('i', $idc); $stmt->execute();
            $c = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$c) { $errores[] = "#$idc: no existe"; continue; }
            if ($c['estatus'] !== 'aspirante') { $errores[] = "#$idc: no está en captura"; continue; }
            if (!$c['cv_archivo']) { $errores[] = "#$idc: sin CV"; continue; }
            // La constancia de la entrevista de RRHH es obligatoria antes de
            // pasar el candidato al jefe (la de RRHH va primero, fuera del sistema).
            if (empty($c['entrevista_rrhh_fecha']) || trim((string)$c['entrevista_rrhh_resultado']) === '') {
                $errores[] = "#$idc: falta la constancia de la entrevista de RRHH (fecha y resultado)";
                continue;
            }

            $r = cambiarEstatusCandidato($conn, $idc, 'enviado_solicitante', $noEmp, 'Enviado al solicitante para revisión.');
            if (!$r['ok']) { $errores[] = "#$idc: " . $r['message']; continue; }
            $enviados++;

            // Vacante abierta → en_proceso.
            $idVac = (int)$c['id_vacante'];
            $updVac = $conn->prepare("UPDATE vacantes SET estatus = 'en_proceso' WHERE id = ? AND estatus = 'abierta'");
            $updVac->bind_param('i', $idVac);
            $updVac->execute();
            $updVac->close();

            // Notifica al solicitante. Sólo campana: enviar 10 candidatos ya no son
            // 10 correos al jefe, que además los ve juntos en su pestaña.
            notificarEvento($conn, 'candidato_enviado', [
                'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
                'id_vacante' => (int)$c['id_vacante'], 'id_candidato' => $idc,
                'titulo' => 'Nuevo candidato para revisar — ' . $c['nombre'],
                'mensaje' => $c['folio'] . ' · ' . $c['puesto'],
                // Su vista de solicitante, aunque además sea de RRHH: aquí actúa
                // como dueño de la vacante, y es donde aprueba o descarta el CV.
                'url' => 'embed_solicitante.php',
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

        $r = cambiarEstatusCandidato($conn, $id, 'descartado', $noEmp, 'Descartado: ' . $motivo);
        if (!$r['ok']) responder(false, $r['message']);
        $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = ? WHERE id = ?");
        $stmt->bind_param('ssi', $etapa, $motivo, $id);
        $stmt->execute(); $stmt->close();
        responder(true, 'Candidato descartado.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
