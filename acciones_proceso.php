<?php
/**
 * acciones_proceso.php — Entrevista del jefe (JSON). Gate: RRHH.
 *
 * El proceso lleva UNA sola entrevista agendada: la del jefe/solicitante.
 *   aprobado_jefe → entrevista_confirmada → entrevistado
 * La cita la crea el propio jefe al aprobar el CV (acciones_solicitante.php),
 * proponiendo dos horarios; el candidato elige uno (fuera del sistema) y RRHH
 * confirma la opción aquí. La entrevista de RRHH ya no se agenda: ocurre fuera
 * del sistema y solo deja constancia en la tabla candidatos.
 *
 * El estatus destino nunca lo manda el cliente: se deriva del paso del proceso.
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
        "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.correo, c.estatus,
                v.id AS id_vacante, v.folio, v.puesto, v.tipo, v.no_empleado_solicitante
         FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/** Cita vigente (pendiente) del candidato para la entrevista del jefe. */
function citaPendiente(mysqli $conn, int $idCandidato): ?array {
    $stmt = $conn->prepare(
        "SELECT id, opcion1, opcion2 FROM citas
         WHERE id_candidato = ? AND tipo = 'jefe' AND estatus = 'pendiente'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->bind_param('i', $idCandidato);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

switch ($accion) {

    case 'listar': {
        // Candidatos en etapas de entrevista con el jefe (aprobado por el jefe →
        // entrevistado). La cita del jefe se trae para que la UI sepa qué toca.
        $sql = "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.correo, c.estatus, v.folio, v.puesto, v.tipo AS tipo_vacante,
                       (SELECT ci.id FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_pendiente,
                       (SELECT ci.opcion1 FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_op1,
                       (SELECT ci.opcion2 FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_op2,
                       (SELECT ci.fecha_confirmada FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus IN ('confirmada','realizada') ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_confirmada,
                       (SELECT ci.notas FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_notas
                FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
                WHERE c.estatus IN ('aprobado_jefe','entrevista_confirmada','entrevistado')
                ORDER BY FIELD(c.estatus,'entrevista_confirmada','aprobado_jefe','entrevistado'), c.id DESC";
        $res = $conn->query($sql);
        $data = [];
        while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }

    case 'nueva_cita': {
        // Alta o reprogramación de la entrevista del jefe: dos opciones de horario
        // que el candidato elegirá. Normalmente la crea el jefe al aprobar el CV;
        // esto sirve para reprogramarla.
        $id    = (int)($_POST['id'] ?? 0);
        $op1   = trim($_POST['opcion1'] ?? '');
        $op2   = trim($_POST['opcion2'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $t1 = strtotime($op1); $t2 = strtotime($op2);
        if (!$t1 || !$t2) responder(false, 'Fechas inválidas.');
        if ($t1 <= time() || $t2 <= time()) responder(false, 'Las fechas deben ser futuras.');
        if ($t1 === $t2) responder(false, 'Las dos opciones deben ser distintas.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');

        // Agendar tiene la misma precondición que confirmar: el candidato debe
        // estar aprobado por el jefe. Se permite además cuando ya está confirmada
        // (reprogramación).
        if ($c['estatus'] !== 'aprobado_jefe' && $c['estatus'] !== 'entrevista_confirmada') {
            responder(false, 'El candidato todavía no puede pasar a la entrevista con el jefe '
                . '(debe estar en «' . sivacEstatusLabel('aprobado_jefe') . '»).');
        }

        // Reprogramar = cancelar la vigente y crear otra.
        $updCi = $conn->prepare("UPDATE citas SET estatus = 'cancelada' WHERE id_candidato = ? AND tipo = 'jefe' AND estatus = 'pendiente'");
        $updCi->bind_param('i', $id);
        $updCi->execute();
        $updCi->close();

        $f1 = date('Y-m-d H:i:s', $t1); $f2 = date('Y-m-d H:i:s', $t2);
        $notasVal = $notas !== '' ? $notas : null;
        $stmt = $conn->prepare("INSERT INTO citas (id_candidato, tipo, opcion1, opcion2, duracion_aprox, notas) VALUES (?, 'jefe', ?, ?, '', ?)");
        $stmt->bind_param('isss', $id, $f1, $f2, $notasVal);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) responder(false, 'No se pudo registrar la disponibilidad.');

        // Se avisa al candidato las dos opciones para que elija.
        $fmt1 = date('d/m/Y H:i', $t1); $fmt2 = date('d/m/Y H:i', $t2);
        $cuerpo = 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Queremos agendar tu <strong>'
            . 'entrevista con el jefe</strong> para la vacante <strong>'
            . htmlspecialchars($c['puesto']) . '</strong>.<br><br>Estas son las opciones disponibles:<br>'
            . '<strong>Opción 1:</strong> ' . $fmt1 . '<br>'
            . '<strong>Opción 2:</strong> ' . $fmt2 . '<br><br>'
            . ($notas !== '' ? 'Notas: ' . htmlspecialchars($notas) . '<br><br>' : '')
            . 'Responde este correo indicando cuál te queda mejor.';
        notificarEvento($conn, 'entrevista_propuesta', [
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Opciones de entrevista enviadas — ' . $c['nombre'],
            'correos' => array_filter([$c['correo']]),
            'correo_asunto' => 'MESS — Opciones para tu entrevista (' . $c['folio'] . ')',
            'correo_titulo' => 'Entrevista con el jefe',
            'correo_html' => $cuerpo,
        ]);
        responder(true, 'Disponibilidad registrada; se enviaron las opciones al candidato.');
    }

    case 'confirmar_entrevista': {
        // El candidato ya eligió (por fuera): RRHH deja constancia de cuál.
        $id     = (int)($_POST['id'] ?? 0);
        $opcion = (int)($_POST['opcion'] ?? 0);
        $notas  = trim($_POST['notas'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!in_array($opcion, [1, 2], true)) responder(false, 'Selecciona una de las dos opciones de fecha.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'aprobado_jefe') {
            responder(false, 'El candidato no está listo para confirmar la entrevista con el jefe.');
        }

        $cita = citaPendiente($conn, $id);
        if (!$cita) responder(false, 'No hay disponibilidad registrada por el solicitante todavía.');

        $fecha = $opcion === 1 ? $cita['opcion1'] : $cita['opcion2'];
        // Las notas de la confirmación se agregan a las que ya traía la cita.
        $stmt = $conn->prepare(
            "UPDATE citas
                SET estatus = 'confirmada', opcion_confirmada = ?, fecha_confirmada = ?, confirmada_por = ?,
                    notas = TRIM(CONCAT_WS('\n', NULLIF(notas, ''), ?))
              WHERE id = ? AND estatus = 'pendiente'"
        );
        $notasVal = $notas !== '' ? $notas : null;
        $stmt->bind_param('isisi', $opcion, $fecha, $noEmp, $notasVal, $cita['id']);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0; $stmt->close();
        if (!$ok) responder(false, 'La cita cambió; recarga e inténtalo de nuevo.');

        $r = cambiarEstatusCandidato($conn, $id, 'entrevista_confirmada', $noEmp,
            'Entrevista con el jefe confirmada para ' . $fecha . '.'
            . ($notas !== '' ? ' Notas: ' . $notas : ''));
        if (!$r['ok']) responder(false, $r['message']);

        // Correo SÓLO al candidato: es el único que no tiene campana. El jefe se
        // entera por la suya, con la fecha y el siguiente paso. Antes se le mandaba
        // a él la misma carta, que iba dirigida al candidato ("Hola <nombre>…").
        $fechaFmt = date('d/m/Y H:i', strtotime($fecha));
        $cuerpoCand = 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Tu <strong>'
            . 'entrevista con el jefe</strong> para la vacante <strong>'
            . htmlspecialchars($c['puesto']) . '</strong> quedó confirmada para el <strong>' . $fechaFmt . '</strong>.'
            . ($notas !== '' ? '<br><br>Notas: ' . htmlspecialchars($notas) : '');
        $correos = array_filter([$c['correo']]);

        notificarEvento($conn, 'entrevista_confirmada', [
            'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Entrevista con el jefe confirmada — ' . $c['nombre'],
            'mensaje' => $c['folio'] . ' · ' . $fechaFmt . '; después registra el resultado',
            // Su vista de solicitante: ahí mismo captura el resultado al terminar.
            'url' => 'embed_solicitante.php',
            'correos' => $correos,
            'correo_asunto' => 'MESS — Entrevista confirmada (' . $c['folio'] . ')',
            'correo_titulo' => 'Entrevista con el jefe confirmada',
            'correo_html' => $cuerpoCand,
        ]);
        responder(true, 'Entrevista con el jefe confirmada para ' . $fechaFmt . '.');
    }

    // 'registrar_resultado_entrevista' se movió a acciones_solicitante.php: el
    // resultado de la entrevista del jefe ahora lo captura el propio jefe (punto 15
    // de la retro PT2), no RRHH. RRHH conserva la confirmación del horario, arriba.

    default:
        responder(false, 'Acción no reconocida.');
}
