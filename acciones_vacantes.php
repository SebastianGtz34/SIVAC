<?php
/**
 * acciones_vacantes.php — CRUD de vacantes (JSON). Gate: RRHH.
 * Todas las consultas usan sentencias preparadas con bind_param.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/respuesta.php';
require_once 'includes/flujo.php';
require_once 'includes/catalogos.php';
require_once 'includes/vacantes.php';
require_once 'includes/notificaciones.php';

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

// Transiciones de estatus de vacante permitidas (RRHH).
// 'pendiente_vobo' solo sale por la acción 'vobo' (así vobo_por/vobo_fecha
// quedan siempre registrados); por aquí únicamente se puede cancelar.
// 'rechazada' puede volver a la bandeja de VoBo si RRHH reconsidera.
$TRANS_VAC = [
    'pendiente_vobo' => ['cancelada'],
    'abierta'    => ['en_proceso', 'pausada', 'cancelada'],
    'en_proceso' => ['pausada', 'cerrada', 'cancelada'],
    'pausada'    => ['abierta', 'en_proceso', 'cancelada'],
    'cerrada'    => ['en_proceso'],
    'cancelada'  => ['abierta'],
    'rechazada'  => ['pendiente_vobo'],
];

// Tipos de vacante válidos: whitelist única en includes/flujo.php
// (SIVAC_TIPOS_VACANTE = temporal/permanente/practicas).

// Etiquetas de mess_rrhh.usuarios.tipo_usr que pueden ser dueños de una vacante:
// el selector de solicitante ofrece a estos empleados, no al directorio completo.
// Es un filtro de UI (acción 'empleados'); crear/editar siguen aceptando
// cualquier empleado activo, para no perder al dueño de las vacantes anteriores
// al filtro (el select les reinyecta su opción al editar, ver js/vacantes.js).
// OJO: tipo_usr NO decide permisos —eso lo deriva auth.php de la jerarquía
// usuarios.jefe—; aquí es sólo el criterio de a quién se le puede asignar.
const SIVAC_TIPOS_SOLICITANTE = ['JEFE', 'JEFE_LAB', 'JEFE_ENCARGADO'];

/** Valida que un noEmpleado exista y esté activo en mess_rrhh.usuarios. */
function empleadoActivo(mysqli $conn, int $no): ?array {
    $stmt = $conn->prepare("SELECT noEmpleado, nombre, departamento, region FROM mess_rrhh.usuarios WHERE noEmpleado = ? AND estatus = 1 LIMIT 1");
    $stmt->bind_param('i', $no);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}


switch ($accion) {

    case 'listar': {
        $filtroestatus = $_GET['estatus'] ?? $_POST['estatus'] ?? '';
        $estatussValidos = ['pendiente_vobo', 'abierta', 'en_proceso', 'pausada',
                            'cerrada', 'cancelada', 'rechazada'];

        // Contadores por vacante: candidatos estatus y entrevistados+ (no descartados).
        // 'entrevistados' cuenta desde la entrevista con el jefe confirmada en adelante.
        $sql = "SELECT v.id, v.folio, v.puesto, v.id_puesto, v.tipo, v.duracion_meses, v.motivo_temporal,
                       v.departamento, v.region,
                       v.no_empleado_solicitante, v.posiciones, v.estatus, v.origen, v.fecha_creacion,
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
        // Las pendientes de VoBo primero: son la bandeja de trabajo de RRHH.
        $sql .= " ORDER BY (v.estatus = 'pendiente_vobo') DESC, v.id DESC";
        $stmt = $conn->prepare($sql);
        if ($tipos) $stmt->bind_param($tipos, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $regiones = catalogoRegiones($conn);
        $data = [];
        while ($r = $res->fetch_assoc()) {
            $sol = obtenerDatosEmpleado($conn, (int)$r['no_empleado_solicitante']);
            $r['solicitante_nombre'] = $sol['nombre'] ?? ('#' . $r['no_empleado_solicitante']);
            $r['region_nombre'] = $regiones[(int)$r['region']] ?? '';
            $r['tipo_label']    = sivacTipoVacanteLabel($r['tipo']);
            $data[] = $r;
        }
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'vobo': {
        // Visto bueno de RRHH sobre una requisición levantada por un jefe.
        // Es la ÚNICA vía de salida de 'pendiente_vobo' hacia abierta/rechazada,
        // para que vobo_por/vobo_fecha queden siempre registrados.
        $id       = (int)($_POST['id'] ?? 0);
        $decision = $_POST['decision'] ?? '';   // 'aprobar' | 'rechazar'
        $motivo   = trim($_POST['motivo'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!in_array($decision, ['aprobar', 'rechazar'], true)) responder(false, 'Decisión inválida.');
        if ($decision === 'rechazar' && $motivo === '') responder(false, 'Indica el motivo del rechazo.');

        $stmt = $conn->prepare(
            "SELECT estatus, folio, puesto, no_empleado_solicitante FROM vacantes WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $vac = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$vac) responder(false, 'Vacante no encontrada.');
        if ($vac['estatus'] !== 'pendiente_vobo') {
            responder(false, 'Esta requisición no está pendiente de visto bueno.');
        }

        if ($decision === 'aprobar') {
            $stmt = $conn->prepare(
                "UPDATE vacantes SET estatus = 'abierta', vobo_por = ?, vobo_fecha = NOW(), motivo_rechazo = NULL
                 WHERE id = ? AND estatus = 'pendiente_vobo'"
            );
            $stmt->bind_param('ii', $noEmp, $id);
        } else {
            $stmt = $conn->prepare(
                "UPDATE vacantes SET estatus = 'rechazada', vobo_por = ?, vobo_fecha = NOW(), motivo_rechazo = ?
                 WHERE id = ? AND estatus = 'pendiente_vobo'"
            );
            $stmt->bind_param('isi', $noEmp, $motivo, $id);
        }
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        if (!$ok) responder(false, 'El estatus cambió; recarga e inténtalo de nuevo.');

        // El jefe que la levantó tiene que enterarse del veredicto: hasta ahora el
        // motivo del rechazo sólo se veía entrando a "Mis Vacantes". Sólo campana:
        // el jefe es empleado y ya la trae en el portal.
        $aprobada = $decision === 'aprobar';
        notificarEvento($conn, $aprobada ? 'requisicion_aprobada' : 'requisicion_rechazada', [
            'destino_no_empleado' => (int)$vac['no_empleado_solicitante'],
            'id_vacante' => $id,
            'titulo'  => ($aprobada ? 'Requisición aprobada — ' : 'Requisición rechazada — ') . $vac['folio'],
            'mensaje' => $aprobada ? $vac['puesto'] . ' · la vacante quedó abierta' : $motivo,
            'url'     => 'vacantes.php',
        ]);

        responder(true, $decision === 'aprobar'
            ? 'Requisición aprobada; la vacante quedó abierta.'
            : 'Requisición rechazada.');
    }

    case 'puestos': {
        // Catálogo de puestos para el select (el puesto dejó de ser texto libre).
        $data = [];
        foreach (catalogoPuestos($conn) as $id => $nombre) {
            $data[] = ['id' => $id, 'puesto' => $nombre];
        }
        responder(true, '', ['data' => $data]);
    }

    case 'regiones': {
        $data = [];
        foreach (catalogoRegiones($conn) as $id => $nombre) {
            $data[] = ['id' => $id, 'region' => $nombre];
        }
        responder(true, '', ['data' => $data]);
    }

    case 'detalle': {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare("SELECT * FROM vacantes WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $vac = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$vac) responder(false, 'Vacante no encontrada.');
        $sol = obtenerDatosEmpleado($conn, (int)$vac['no_empleado_solicitante']);
        $vac['solicitante_nombre'] = $sol['nombre'] ?? ('#' . $vac['no_empleado_solicitante']);
        $vac['solicitante_correo'] = $sol['correo'] ?? '';
        $vac['region_nombre'] = catalogoRegiones($conn)[(int)$vac['region']] ?? '';
        $vac['tipo_label']    = sivacTipoVacanteLabel($vac['tipo']);
        if ((int)$vac['vobo_por'] > 0) {
            $vb = obtenerDatosEmpleado($conn, (int)$vac['vobo_por']);
            $vac['vobo_nombre'] = $vb['nombre'] ?? ('#' . $vac['vobo_por']);
        }
        responder(true, '', ['data' => $vac]);
    }

    case 'crear': {
        // El puesto ya no es texto libre: viene del catálogo mess_rrhh.puesto.
        $idPuesto    = (int)($_POST['id_puesto'] ?? 0);
        $tipo        = $_POST['tipo'] ?? 'permanente';
        $departamento= (int)($_POST['departamento'] ?? 0);
        $solicitante = (int)($_POST['no_empleado_solicitante'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $posiciones  = max(1, (int)($_POST['posiciones'] ?? 1));

        if (!in_array($tipo, SIVAC_TIPOS_VACANTE, true)) responder(false, 'Tipo de vacante inválido.');
        // Duración + motivo: obligatorios sólo si el tipo es 'temporal'.
        $temp = sanearTemporal($tipo, $_POST);
        if ($temp['error']) responder(false, $temp['error']);
        $duracion = $temp['duracion']; $motivoTmp = $temp['motivo'];
        $cat = puestoDelCatalogo($conn, $idPuesto);
        if (!$cat)                responder(false, 'Selecciona un puesto del catálogo.');
        if ($departamento <= 0)   responder(false, 'Selecciona el departamento.');
        $emp = empleadoActivo($conn, $solicitante);
        if (!$emp)                responder(false, 'El solicitante no existe o no está activo.');

        $puesto = $cat['puesto'];
        $folio  = generarFolioVacante($conn);
        // Región: snapshot del solicitante al momento de crear.
        $region = ((int)($emp['region'] ?? 0)) > 0 ? (int)$emp['region'] : null;

        // Capturada por RRHH ⇒ origen 'rrhh' y VoBo implícito: nace 'abierta'.
        $stmt = $conn->prepare(
            "INSERT INTO vacantes (folio, puesto, id_puesto, tipo, duracion_meses, motivo_temporal,
                                   departamento, region, no_empleado_solicitante, descripcion, posiciones,
                                   creador_por, origen, estatus)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'rrhh', 'abierta')"
        );
        $stmt->bind_param('ssisisiiisii', $folio, $puesto, $idPuesto, $tipo, $duracion, $motivoTmp,
                          $departamento, $region, $solicitante, $descripcion, $posiciones, $noEmp);
        $ok = $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        responder($ok, $ok ? 'Vacante creada.' : 'No se pudo crear la vacante.', $ok ? ['id' => $id, 'folio' => $folio] : []);
    }

    case 'editar': {
        $id          = (int)($_POST['id'] ?? 0);
        $idPuesto    = (int)($_POST['id_puesto'] ?? 0);
        $tipo        = $_POST['tipo'] ?? 'permanente';
        $departamento= (int)($_POST['departamento'] ?? 0);
        $solicitante = (int)($_POST['no_empleado_solicitante'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $posiciones  = max(1, (int)($_POST['posiciones'] ?? 1));

        if ($id <= 0)             responder(false, 'Id inválido.');
        if (!in_array($tipo, SIVAC_TIPOS_VACANTE, true)) responder(false, 'Tipo de vacante inválido.');
        $temp = sanearTemporal($tipo, $_POST);
        if ($temp['error']) responder(false, $temp['error']);
        $duracion = $temp['duracion']; $motivoTmp = $temp['motivo'];
        $cat = puestoDelCatalogo($conn, $idPuesto);
        if (!$cat)                responder(false, 'Selecciona un puesto del catálogo.');
        if ($departamento <= 0)   responder(false, 'Selecciona el departamento.');
        $emp = empleadoActivo($conn, $solicitante);
        if (!$emp) responder(false, 'El solicitante no existe o no está activo.');

        // Cambiar el tipo con el proceso ya avanzado movería la rama del pipeline
        // bajo los pies de los candidatos vivos (prácticas no lleva propuesta
        // económica). Solo se permite si nadie ha arrancado.
        $stmt = $conn->prepare(
            "SELECT v.tipo,
                    (SELECT COUNT(*) FROM candidatos c
                      WHERE c.id_vacante = v.id AND c.estatus <> 'aspirante') AS avanzados
             FROM vacantes v WHERE v.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $vac = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$vac) responder(false, 'Vacante no encontrada.');
        if ($vac['tipo'] !== $tipo && (int)$vac['avanzados'] > 0) {
            responder(false, 'No puedes cambiar el tipo de vacante: ya hay candidatos avanzados en el proceso.');
        }

        $puesto = $cat['puesto'];
        $region = ((int)($emp['region'] ?? 0)) > 0 ? (int)$emp['region'] : null;

        $stmt = $conn->prepare(
            "UPDATE vacantes SET puesto = ?, id_puesto = ?, tipo = ?, duracion_meses = ?, motivo_temporal = ?,
                    departamento = ?, region = ?, no_empleado_solicitante = ?, descripcion = ?, posiciones = ?
             WHERE id = ?"
        );
        $stmt->bind_param('sisisiiisii', $puesto, $idPuesto, $tipo, $duracion, $motivoTmp, $departamento, $region,
                          $solicitante, $descripcion, $posiciones, $id);
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

    case 'empleados': {
        // Directorio para el selector de solicitante: empleados activos cuya
        // etiqueta tipo_usr está en SIVAC_TIPOS_SOLICITANTE (los jefes).
        $q = trim($_POST['q'] ?? $_GET['q'] ?? '');
        $like = '%' . $q . '%';
        $huecos = implode(',', array_fill(0, count(SIVAC_TIPOS_SOLICITANTE), '?'));
        $stmt = $conn->prepare(
            "SELECT noEmpleado, nombre, departamento FROM mess_rrhh.usuarios
             WHERE estatus = 1 AND tipo_usr IN ($huecos) AND (nombre LIKE ? OR noEmpleado LIKE ?)
             ORDER BY nombre LIMIT 50"
        );
        $params = array_merge(SIVAC_TIPOS_SOLICITANTE, [$like, $like]);
        $stmt->bind_param(str_repeat('s', count(SIVAC_TIPOS_SOLICITANTE)) . 'ss', ...$params);
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
