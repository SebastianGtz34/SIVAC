<?php
/**
 * acciones_solicitante.php — Acciones del SOLICITANTE (JSON). Gate: solo sesión.
 *
 * La autorización NO es por departamento sino por PERTENENCIA: cada consulta
 * filtra por vacantes.no_empleado_solicitante = $noEmp (de la sesión, jamás de
 * un parámetro del cliente). Así un solicitante solo ve y actúa sobre SUS
 * vacantes aunque manipule ids en la petición.
 *
 * 'solicitar_vacante' es la vía por la que un jefe levanta su propia requisición:
 * nace en 'pendiente_vobo' y no existe hasta que RRHH le da el visto bueno
 * (acciones_vacantes.php → 'vobo'). El dueño se toma SIEMPRE de la sesión, así
 * que nadie puede levantar una requisición a nombre de otro.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/flujo.php';
require_once 'includes/catalogos.php';
require_once 'includes/vacantes.php';
require_once 'includes/notificaciones.php';

$noEmp = requiereSesionJson();
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

switch ($accion) {

    case 'catalogos': {
        // Catálogos para el formulario de requisición del jefe.
        // Solo se entregan a quien puede levantar una (evita exponer el
        // directorio de puestos a cualquier sesión).
        if (!puedeSolicitarVacante($conn, $noEmp)) {
            responder(false, 'No tienes permiso para levantar requisiciones.');
        }
        $puestos = [];
        foreach (catalogoPuestos($conn) as $id => $nombre) {
            $puestos[] = ['id' => $id, 'puesto' => $nombre];
        }
        $emp = obtenerDatosEmpleado($conn, $noEmp);
        responder(true, '', [
            'puestos' => $puestos,
            'departamento' => (int)($emp['departamento'] ?? 0),
        ]);
    }

    case 'solicitar_vacante': {
        // El jefe levanta la requisición. Queda pendiente del VoBo de RRHH.
        if (!puedeSolicitarVacante($conn, $noEmp)) {
            responder(false, 'Solo un jefe con personal a cargo puede levantar una requisición.');
        }

        $idPuesto    = (int)($_POST['id_puesto'] ?? 0);
        $tipo        = $_POST['tipo'] ?? 'permanente';
        $descripcion = trim($_POST['descripcion'] ?? '');
        $posiciones  = max(1, (int)($_POST['posiciones'] ?? 1));
        $justificacion = trim($_POST['justificacion'] ?? '');

        if (!in_array($tipo, SIVAC_TIPOS_VACANTE, true)) responder(false, 'Tipo de vacante inválido.');
        // Duración + motivo: obligatorios sólo si el tipo es 'temporal'.
        $temp = sanearTemporal($tipo, $_POST);
        if ($temp['error']) responder(false, $temp['error']);
        $duracion = $temp['duracion']; $motivoTmp = $temp['motivo'];
        if ($justificacion === '') responder(false, 'Explica por qué se necesita la vacante (lo revisa RRHH para el visto bueno).');

        // El puesto debe existir en el catálogo de RRHH (nunca texto libre).
        $cat = puestoDelCatalogo($conn, $idPuesto);
        if (!$cat) responder(false, 'Selecciona un puesto del catálogo.');

        // Departamento y región salen del propio jefe (sesión), no del cliente.
        $emp = obtenerDatosEmpleado($conn, $noEmp);
        if (!$emp) responder(false, 'No se encontró tu registro de empleado.');
        $departamento = (int)($emp['departamento'] ?? 0);
        if ($departamento <= 0) responder(false, 'Tu usuario no tiene departamento asignado; avisa a RRHH.');
        $region = obtenerRegionEmpleado($conn, $noEmp);

        // La justificación encabeza la descripción: es lo que RRHH lee al dar VoBo.
        $desc = 'JUSTIFICACIÓN: ' . $justificacion
              . ($descripcion !== '' ? "\n\n" . $descripcion : '');

        $folio  = generarFolioVacante($conn);
        $puesto = $cat['puesto'];
        $stmt = $conn->prepare(
            "INSERT INTO vacantes (folio, puesto, id_puesto, tipo, duracion_meses, motivo_temporal,
                                   departamento, region, no_empleado_solicitante, descripcion, posiciones,
                                   creador_por, origen, estatus)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'solicitante', 'pendiente_vobo')"
        );
        $stmt->bind_param('ssisisiiisii', $folio, $puesto, $idPuesto, $tipo, $duracion, $motivoTmp,
                          $departamento, $region, $noEmp, $desc, $posiciones, $noEmp);
        $ok = $stmt->execute();
        $idVac = (int)$conn->insert_id;
        $stmt->close();
        if (!$ok) responder(false, 'No se pudo registrar la requisición.');

        responder(true, 'Requisición enviada (' . $folio . '). Queda pendiente del visto bueno de RRHH.',
                  ['id' => $idVac, 'folio' => $folio]);
    }

    case 'mis_vacantes': {
        // Solo las vacantes cuyo dueño es la sesión.
        // Se incluye motivo_rechazo para que quien levantó una requisición vea
        // por qué RRHH se la negó sin tener que ir a preguntar.
        $stmt = $conn->prepare(
            "SELECT v.id, v.folio, v.puesto, v.estatus, v.tipo, v.motivo_rechazo,
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
        // Las citas se acotan a tipo 'jefe': al solicitante le importa SU
        // entrevista, no la que RRHH tenga agendada como filtro previo.
        // Se incluye la constancia de RRHH y el psicométrico (informativo, con su
        // veredicto): el jefe los ve para elegir a sus finalistas. Ahora el jefe SÍ
        // ve a los descartados (se hunden al final, atenuados en la UI).
        $stmt = $conn->prepare(
            "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.estatus, c.cv_archivo, v.id AS id_vacante, v.folio, v.puesto, c.motivo_descarte,
                    c.entrevista_rrhh_fecha, c.entrevista_rrhh_resultado, c.entrevista_rrhh_observaciones,
                    c.psicometrico_fecha, c.psicometrico_calificacion, c.psicometrico_resultado, c.psicometrico_observaciones,
                    (SELECT ci.estatus FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' ORDER BY ci.id DESC LIMIT 1) AS cita_estatus,
                    (SELECT ci.fecha_confirmada FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus IN ('confirmada','realizada') ORDER BY ci.id DESC LIMIT 1) AS cita_confirmada,
                    (SELECT ci.notas FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' ORDER BY ci.id DESC LIMIT 1) AS cita_notas
             FROM candidatos c
             INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE v.no_empleado_solicitante = ?
               AND c.estatus <> 'aspirante'
             ORDER BY (c.estatus = 'descartado') ASC, FIELD(c.estatus,'enviado_solicitante') DESC, c.id DESC"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        responder(true, '', ['data' => $data]);
    }

    case 'aprobar_cv': {
        $id    = (int)($_POST['id'] ?? 0);
        $op1   = trim($_POST['opcion1'] ?? '');
        $op2   = trim($_POST['opcion2'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
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

        // Crea la cita de SU entrevista (tipo 'jefe') con las 2 opciones y sus
        // comentarios, y avanza el estatus. La cita queda pendiente hasta que RRHH
        // confirme cuál de las dos fechas eligió el candidato (acciones_proceso.php).
        $f1 = date('Y-m-d H:i:s', $t1); $f2 = date('Y-m-d H:i:s', $t2);
        $notasVal = $notas !== '' ? $notas : null;
        $stmt = $conn->prepare("INSERT INTO citas (id_candidato, tipo, opcion1, opcion2, duracion_aprox, notas) VALUES (?, 'jefe', ?, ?, '', ?)");
        $stmt->bind_param('isss', $id, $f1, $f2, $notasVal);
        $stmt->execute(); $stmt->close();

        $r = cambiarEstatusCandidato($conn, $id, 'aprobado_jefe', $noEmp,
            'CV aprobado por el solicitante; disponibilidad registrada.'
            . ($notas !== '' ? ' Notas: ' . $notas : ''));
        if (!$r['ok']) responder(false, $r['message']);

        // Notifica a RRHH (creador de la vacante).
        $stmt = $conn->prepare(
            "SELECT TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.puesto, v.tipo, v.creador_por AS no_empleado_creador, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            $rrhh = obtenerDatosEmpleado($conn, (int)$info['no_empleado_creador']);
            // El jefe ya propuso las dos fechas; el siguiente paso de RRHH es
            // confirmar cuál eligió el candidato.
            $siguiente = 'confirmar la fecha de la entrevista con el jefe';
            notificarEvento($conn, 'cv_aprobado', [
                'destino_no_empleado' => (int)$info['no_empleado_creador'],
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => 'CV aprobado — ' . $info['nombre'],
                'mensaje' => $info['folio'] . ' · ' . $info['puesto'] . ': ' . $siguiente . '.',
                'url' => 'candidatos.php',
                'correos' => $rrhh && $rrhh['correo'] ? [$rrhh['correo']] : [],
                'correo_asunto' => 'SIVAC — CV aprobado por el solicitante (' . $info['folio'] . ')',
                'correo_titulo' => 'CV aprobado',
                'correo_html' => 'El solicitante aprobó el CV de <strong>' . htmlspecialchars($info['nombre'])
                    . '</strong> para la vacante <strong>' . htmlspecialchars($info['puesto'])
                    . '</strong> y registró su disponibilidad para entrevista.'
                    . ($notas !== '' ? '<br><br><strong>Comentarios del solicitante:</strong> ' . htmlspecialchars($notas) : '')
                    . '<br><br>Siguiente paso: ' . $siguiente . '.',
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

        $r = cambiarEstatusCandidato($conn, $id, 'descartado', $noEmp, 'CV descartado por el solicitante: ' . $motivo);
        if (!$r['ok']) responder(false, $r['message']);
        $etapa = 'solicitante';
        $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = ? WHERE id = ?");
        $stmt->bind_param('ssi', $etapa, $motivo, $id);
        $stmt->execute(); $stmt->close();

        // Notifica a RRHH.
        $stmt = $conn->prepare(
            "SELECT TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.creador_por AS no_empleado_creador, v.id AS id_vacante
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

    case 'registrar_resultado_entrevista': {
        // Punto 15 (retro PT2): el resultado de SU entrevista lo captura el JEFE,
        // no RRHH. Gate de propiedad: sólo el dueño de la vacante del candidato.
        // RRHH conserva la confirmación del horario (acciones_proceso.php).
        $id        = (int)($_POST['id'] ?? 0);
        $resultado = $_POST['resultado'] ?? '';  // 'aceptado' | 'descartado'
        $motivo    = trim($_POST['motivo'] ?? '');
        $notas     = trim($_POST['notas'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');

        // estatus + datos de la vacante (para avisar a RRHH del resultado).
        $stmt = $conn->prepare(
            "SELECT c.estatus, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.puesto, v.creador_por AS no_empleado_creador, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $c = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'entrevista_confirmada') {
            responder(false, 'Este candidato no tiene una entrevista contigo confirmada.');
        }

        // Cierra la cita del jefe y le anexa las notas del entrevistador.
        $notasVal = $notas !== '' ? $notas : null;
        $updCi = $conn->prepare(
            "UPDATE citas SET estatus = 'realizada', notas = TRIM(CONCAT_WS('\n', NULLIF(notas, ''), ?))
              WHERE id_candidato = ? AND tipo = 'jefe' AND estatus = 'confirmada'"
        );
        $updCi->bind_param('si', $notasVal, $id);
        $updCi->execute(); $updCi->close();

        $sufijoNotas = $notas !== '' ? ' Notas: ' . $notas : '';
        $rrhh = obtenerDatosEmpleado($conn, (int)$c['no_empleado_creador']);
        $correosRrhh = $rrhh && $rrhh['correo'] ? [$rrhh['correo']] : [];

        if ($resultado === 'aceptado') {
            $r = cambiarEstatusCandidato($conn, $id, 'entrevistado', $noEmp,
                'Entrevista con el jefe realizada: aprobado.' . $sufijoNotas);
            if (!$r['ok']) responder(false, $r['message']);
            notificarEvento($conn, 'entrevista_resultado', [
                'destino_no_empleado' => (int)$c['no_empleado_creador'],
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo' => 'Entrevistado por el jefe — ' . $c['nombre'],
                'mensaje' => $c['folio'] . ' · ' . $c['puesto'] . ': aprobado, continúa el cierre.',
                'url' => 'candidatos.php',
                'correos' => $correosRrhh,
                'correo_asunto' => 'SIVAC — El jefe entrevistó a un candidato (' . $c['folio'] . ')',
                'correo_titulo' => 'Resultado de la entrevista del jefe',
                'correo_html' => 'El solicitante marcó como <strong>aprobado</strong> a <strong>'
                    . htmlspecialchars($c['nombre']) . '</strong> tras la entrevista.'
                    . ($notas !== '' ? '<br><br><strong>Notas:</strong> ' . htmlspecialchars($notas) : '')
                    . '<br><br>Siguiente paso: continuar el cierre (propuesta o documentación).',
            ]);
            responder(true, 'Registraste al candidato como entrevistado.');
        }

        if ($resultado === 'descartado') {
            if ($motivo === '') responder(false, 'Indica el motivo del descarte.');
            $r = cambiarEstatusCandidato($conn, $id, 'descartado', $noEmp,
                'Descartado en la entrevista con el jefe: ' . $motivo);
            if (!$r['ok']) responder(false, $r['message']);
            $etapa = 'entrevista';
            $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = ? WHERE id = ?");
            $stmt->bind_param('ssi', $etapa, $motivo, $id);
            $stmt->execute(); $stmt->close();
            notificarEvento($conn, 'entrevista_resultado', [
                'destino_no_empleado' => (int)$c['no_empleado_creador'],
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo' => 'Descartado tras la entrevista — ' . $c['nombre'],
                'mensaje' => $c['folio'] . ': ' . $motivo,
                'url' => 'candidatos.php',
                'correos' => $correosRrhh,
                'correo_asunto' => 'SIVAC — Candidato descartado por el jefe (' . $c['folio'] . ')',
                'correo_titulo' => 'Descartado tras la entrevista del jefe',
                'correo_html' => 'El solicitante <strong>descartó</strong> a <strong>'
                    . htmlspecialchars($c['nombre']) . '</strong> tras la entrevista.'
                    . '<br><br><strong>Motivo:</strong> ' . htmlspecialchars($motivo),
            ]);
            responder(true, 'Candidato descartado.');
        }

        responder(false, 'Resultado inválido.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
