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
 * Si el jefe pide cambiar una fecha vigente, RRHH le da el VoBo aquí
 * ('vobo_cambio_fechas'); mientras esté pendiente no se confirma nada con el
 * candidato (retro 2026-10-29, ver includes/citas.php).
 *
 * El estatus destino nunca lo manda el cliente: se deriva del paso del proceso.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/respuesta.php';
require_once 'includes/flujo.php';
require_once 'includes/notificaciones.php';
require_once 'includes/citas.php';

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

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
                       (SELECT ci.notas FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus NOT IN ('por_aprobar','no_aprobada') ORDER BY ci.id DESC LIMIT 1) AS cita_jefe_notas
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
        $avisoCorreo = notificarCandidato($conn, 'entrevista_propuesta', [
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Opciones de entrevista enviadas — ' . $c['nombre'],
            'correos' => array_filter([$c['correo']]),
            'correo_asunto' => 'MESS — Opciones para tu entrevista (' . $c['folio'] . ')',
            'correo_titulo' => 'Entrevista con el jefe',
            'correo_html' => $cuerpo,
        ]);
        responder(true, 'Disponibilidad registrada.' . ($avisoCorreo ?: ' Se enviaron las opciones al candidato.'),
                  ['aviso' => $avisoCorreo ? 1 : 0]);
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
        // El candado: el jefe ya pidió mover estas fechas.
        if (sivacCitaPorAprobar($conn, $id)) {
            responder(false, 'El jefe pidió cambiar estas fechas: primero dale el VoBo o recházalo.');
        }

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

        $avisoCorreo = notificarCandidato($conn, 'entrevista_confirmada', [
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
        responder(true, 'Entrevista con el jefe confirmada para ' . $fechaFmt . '.'
            . ($avisoCorreo ?: ' Se le avisó al candidato por correo.'),
            ['aviso' => $avisoCorreo ? 1 : 0]);
    }

    case 'sin_fecha_candidato': {
        // El candidato no puede en NINGUNA de las dos fechas del jefe (retro
        // 2026-10-06). RRHH no inventa una fecha: se le devuelve la pelota al jefe,
        // que es dueño de su agenda, con la fecha que pidió el candidato si dio una.
        // El candidato sigue en 'aprobado_jefe'; la cita queda 'rechazada' y el
        // jefe propone otras con el mismo «reagendar» de siempre.
        $id      = (int)($_POST['id'] ?? 0);
        $pedida  = trim($_POST['fecha_candidato'] ?? '');
        $notas   = trim($_POST['notas'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $tp = null;
        if ($pedida !== '') {
            $tp = strtotime($pedida);
            if (!$tp) responder(false, 'La fecha que pidió el candidato no es válida.');
            if ($tp <= time()) responder(false, 'La fecha que pidió el candidato debe ser futura.');
        }

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'aprobado_jefe') {
            responder(false, 'El candidato no está esperando confirmar la entrevista con el jefe.');
        }
        $cita = citaPendiente($conn, $id);
        if (!$cita) responder(false, 'No hay fechas del jefe pendientes de confirmar.');
        if (sivacCitaPorAprobar($conn, $id)) {
            responder(false, 'El jefe pidió cambiar estas fechas: primero dale el VoBo o recházalo.');
        }

        $fechaVal = $tp ? date('Y-m-d H:i:s', $tp) : null;
        $nota = 'El candidato no puede en ninguna de las dos fechas.'
            . ($tp ? ' Disponibilidad del candidato: ' . date('d/m/Y H:i', $tp) . '.' : '')
            . ($notas !== '' ? ' ' . $notas : '');
        $stmt = $conn->prepare(
            "UPDATE citas
                SET estatus = 'rechazada', fecha_candidato = ?,
                    notas = TRIM(CONCAT_WS('\n', NULLIF(notas, ''), ?))
              WHERE id = ? AND estatus = 'pendiente'"
        );
        $stmt->bind_param('ssi', $fechaVal, $nota, $cita['id']);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0; $stmt->close();
        if (!$ok) responder(false, 'La cita cambió; recarga e inténtalo de nuevo.');

        // Constancia en el historial: el estatus no cambia, pero sin esta fila la
        // ficha no dice por qué las fechas del jefe desaparecieron.
        $hist = $conn->prepare(
            "INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario)
             VALUES (?, 'aprobado_jefe', 'aprobado_jefe', ?, ?)"
        );
        $hist->bind_param('iis', $id, $noEmp, $nota);
        $hist->execute();
        $hist->close();

        // Sólo campana al jefe: es interno. El candidato se entera por correo
        // cuando RRHH confirme la fecha nueva, como en el resto del flujo.
        notificarEvento($conn, 'entrevista_otra_fecha', [
            'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'El candidato no puede en tus fechas — ' . $c['nombre'],
            'mensaje' => $c['folio'] . ' · '
                . ($tp ? 'disponibilidad del candidato: ' . date('d/m/Y H:i', $tp) . '; ' : '')
                . 'propón nuevas fechas de entrevista',
            'url' => 'embed_solicitante.php',
        ]);
        responder(true, 'Se le pidió al jefe que proponga nuevas fechas.');
    }

    case 'vobo_cambio_fechas': {
        // VoBo de RRHH al cambio de fechas que pidió el jefe (retro 2026-10-29).
        //   aprobar  → «Enterado»: la fecha vigente se cancela y la nueva queda
        //              pendiente de que el candidato elija; si ya estaba
        //              confirmada, el candidato vuelve a 'aprobado_jefe'.
        //   rechazar → con motivo: la propuesta queda 'no_aprobada' y sigue la vigente.
        // El correo al candidato sale, como siempre, cuando RRHH confirme la fecha.
        $id       = (int)($_POST['id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        $motivo   = trim($_POST['motivo'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!in_array($decision, ['aprobar', 'rechazar'], true)) responder(false, 'Decisión inválida.');

        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'aprobado_jefe' && $c['estatus'] !== 'entrevista_confirmada') {
            responder(false, 'La entrevista de este candidato ya no se puede mover.');
        }
        $prop = sivacCitaPorAprobar($conn, $id);
        if (!$prop) responder(false, 'No hay un cambio de fechas esperando VoBo; recarga la lista.');

        // La fecha vigente: es lo que se cancela al aprobar o lo que sigue al rechazar.
        $stmt = $conn->prepare(
            "SELECT estatus, opcion1, opcion2, fecha_confirmada FROM citas
              WHERE id_candidato = ? AND tipo = 'jefe' AND estatus IN ('pendiente','confirmada')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $vig = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
        $vigenteTxt = '';
        if (($vig['estatus'] ?? '') === 'confirmada' && !empty($vig['fecha_confirmada'])) {
            $vigenteTxt = date('d/m/Y H:i', strtotime($vig['fecha_confirmada'])) . ', ya confirmada con el candidato';
        } elseif (($vig['estatus'] ?? '') === 'pendiente') {
            $vigenteTxt = date('d/m/Y H:i', strtotime($vig['opcion1'])) . ' o ' . date('d/m/Y H:i', strtotime($vig['opcion2']));
        }
        $nuevasTxt = date('d/m/Y H:i', strtotime($prop['opcion1'])) . ' o ' . date('d/m/Y H:i', strtotime($prop['opcion2']));

        if ($decision === 'rechazar') {
            if ($motivo === '') responder(false, 'Indica por qué no se aprueba el cambio: se le dice al jefe.');
            $motivo = mb_substr($motivo, 0, 500);
            $stmt = $conn->prepare(
                "UPDATE citas SET estatus = 'no_aprobada', vobo_por = ?, vobo_fecha = NOW(), vobo_motivo = ?
                  WHERE id = ? AND estatus = 'por_aprobar'"
            );
            $stmt->bind_param('isi', $noEmp, $motivo, $prop['id']);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0; $stmt->close();
            if (!$ok) responder(false, 'El cambio de fechas cambió; recarga e inténtalo de nuevo.');

            $avisoCorreo = notificarConCorreo($conn, 'cambio_fechas_no_aprobado', [
                'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo' => 'RRHH no aprobó tu cambio de fechas — ' . $c['nombre'],
                'mensaje' => $c['folio'] . ' · ' . $motivo
                    . ($vigenteTxt !== '' ? ' · sigue en pie: ' . $vigenteTxt : ''),
                'url' => 'embed_solicitante.php',
            ] + sivacCorreoCambioFechas($conn, (int)$c['no_empleado_solicitante'], 'Cambio de fechas no aprobado', $c['folio'], [
                'Candidato'           => $c['nombre'],
                'Vacante'             => $c['folio'] . ' · ' . $c['puesto'],
                'Fechas que se pidieron' => $nuevasTxt,
                'Sigue en pie'        => $vigenteTxt,
                'Motivo de RRHH'      => $motivo,
                'Decidió'             => obtenerDatosEmpleado($conn, $noEmp)['nombre'] ?? ('#' . $noEmp),
            ]), 'del cambio de fechas', 'no hay correos registrados');
            responder(true, 'Cambio no aprobado; se le avisó al jefe. Sigue la fecha anterior.'
                . ($avisoCorreo !== '' ? $avisoCorreo . ' Al jefe sí le llegó la campana.' : ''),
                ['aviso' => $avisoCorreo !== '' ? 1 : 0]);
        }

        // Aprobar. Las fechas pudieron pasar mientras esperaban el VoBo.
        if (strtotime($prop['opcion1']) <= time() || strtotime($prop['opcion2']) <= time()) {
            responder(false, 'Alguna de las fechas que pidió el jefe ya pasó: no lo apruebes, para que proponga otras.');
        }
        // Primero se toma la propuesta (anti-carrera) y sólo entonces se cancela la
        // vigente: al revés, un doble clic dejaría al candidato sin ninguna cita.
        $stmt = $conn->prepare(
            "UPDATE citas SET estatus = 'pendiente', vobo_por = ?, vobo_fecha = NOW()
              WHERE id = ? AND estatus = 'por_aprobar'"
        );
        $stmt->bind_param('ii', $noEmp, $prop['id']);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0; $stmt->close();
        if (!$ok) responder(false, 'El cambio de fechas cambió; recarga e inténtalo de nuevo.');

        $upd = $conn->prepare(
            "UPDATE citas SET estatus = 'cancelada'
              WHERE id_candidato = ? AND tipo = 'jefe' AND estatus IN ('pendiente','confirmada') AND id <> ?"
        );
        $upd->bind_param('ii', $id, $prop['id']); $upd->execute(); $upd->close();

        // Ya confirmada: el candidato vuelve a estar pendiente de elegir fecha.
        if ($c['estatus'] === 'entrevista_confirmada') {
            $r = cambiarEstatusCandidato($conn, $id, 'aprobado_jefe', $noEmp,
                'VoBo de RRHH al cambio de fechas del solicitante: se cancela la entrevista'
                . (!empty($vig['fecha_confirmada']) ? ' confirmada para ' . date('d/m/Y H:i', strtotime($vig['fecha_confirmada'])) : '')
                . '. Nuevas opciones: ' . $nuevasTxt . '.'
                . (!empty($prop['notas']) ? ' Motivo: ' . $prop['notas'] : ''));
            if (!$r['ok']) responder(false, $r['message']);
        }

        $avisoCorreo = notificarConCorreo($conn, 'cambio_fechas_aprobado', [
            'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'RRHH dio el VoBo a tu cambio de fechas — ' . $c['nombre'],
            'mensaje' => $c['folio'] . ' · nuevas opciones: ' . $nuevasTxt . '; RRHH confirmará con el candidato',
            'url' => 'embed_solicitante.php',
        ] + sivacCorreoCambioFechas($conn, (int)$c['no_empleado_solicitante'], 'Cambio de fechas aprobado', $c['folio'], [
            'Candidato'       => $c['nombre'],
            'Vacante'         => $c['folio'] . ' · ' . $c['puesto'],
            'Se cancela'      => $vigenteTxt,
            'Nuevas opciones' => $nuevasTxt,
            'Motivo del jefe' => (string)($prop['notas'] ?? ''),
            'Dio el VoBo'     => obtenerDatosEmpleado($conn, $noEmp)['nombre'] ?? ('#' . $noEmp),
            'Siguiente paso'  => 'RRHH confirma con el candidato cuál de las fechas nuevas le queda.',
        ]), 'del cambio de fechas', 'no hay correos registrados');
        responder(true, 'VoBo registrado. Ahora confirma con el candidato cuál de las fechas nuevas le queda.'
            . (($vig['estatus'] ?? '') === 'confirmada' && !empty($vig['fecha_confirmada'])
                ? ' Avísale que la entrevista del ' . date('d/m/Y H:i', strtotime($vig['fecha_confirmada'])) . ' se canceló.' : '')
            . ($avisoCorreo !== '' ? $avisoCorreo . ' Al jefe sí le llegó la campana.' : ''),
            ['aviso' => $avisoCorreo !== '' ? 1 : 0]);
    }

    // 'registrar_resultado_entrevista' se movió a acciones_solicitante.php: el
    // resultado de la entrevista del jefe ahora lo captura el propio jefe (punto 15
    // de la retro PT2), no RRHH. RRHH conserva la confirmación del horario, arriba.

    default:
        responder(false, 'Acción no reconocida.');
}
