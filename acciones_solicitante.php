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
require_once 'includes/respuesta.php';
require_once 'includes/flujo.php';
require_once 'includes/catalogos.php';
require_once 'includes/vacantes.php';
require_once 'includes/notificaciones.php';
require_once 'includes/citas.php';
require_once 'includes/candidatos.php';
require_once 'includes/comentarios.php';

$noEmp = requiereSesionJson();
$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

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
            responder(false, 'No tienes permiso para levantar requisiciones.');
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

        $folio  = generarFolioVacante($conn, $departamento);
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

        // Avisa a TODO RRHH: la requisición está parada hasta que alguien le dé
        // VoBo y todavía no tiene dueño, así que no hay a quién dirigirla.
        $rrhh = sivacEmpleadosRRHH($conn);
        $jefe = obtenerDatosEmpleado($conn, $noEmp);
        $nombreJefe = $jefe['nombre'] ?? ('Empleado #' . $noEmp);
        // Sólo campana: es un aviso interno y el correo se reserva para el
        // candidato, que no tiene otra vía de enterarse (ver includes/notificaciones.php).
        notificarEvento($conn, 'requisicion_pendiente', [
            'destinos_no_empleado' => array_column($rrhh, 'noEmpleado'),
            'id_vacante' => $idVac,
            'titulo'  => 'Requisición pendiente de VoBo — ' . $folio,
            'mensaje' => $puesto . ' · la levantó ' . $nombreJefe
                . ' · ' . (int)$posiciones . ' posición(es), ' . $tipo,
            'url'     => 'vacantes.php',
        ]);

        responder(true, 'Requisición enviada (' . $folio . '). Queda pendiente del visto bueno de RRHH.',
                  ['id' => $idVac, 'folio' => $folio]);
    }

    case 'mis_vacantes': {
        // Solo las vacantes cuyo dueño es la sesión.
        // Se incluye motivo_rechazo para que quien levantó una requisición vea
        // por qué RRHH se la negó sin tener que ir a preguntar.
        // 'total' cuenta SÓLO lo que el jefe puede abrir (mismo filtro que
        // mis_candidatos): una ficha vieja en 'aspirante' mostraba «3 cand.» en
        // una vacante que al abrirla salía vacía (ticket 120).
        // Las canceladas o rechazadas hace más de un mes ya no salen (retro
        // 2026-10-29); `ocultas` las cuenta para que una pestaña vacía no diga
        // que nunca hubo ninguna.
        $stmt = $conn->prepare(
            "SELECT v.id, v.folio, v.puesto, v.estatus, v.tipo, v.motivo_rechazo,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus <> 'aspirante'
                        AND NOT " . SIVAC_SQL_DESCARTE_OCULTO . ") AS total,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'enviado_solicitante') AS por_revisar,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                       AND c.estatus IN ('entrevista_confirmada','entrevistado','propuesta_enviada',
                                        'propuesta_expirada','propuesta_aceptada','documentacion','contratado')) AS entrevistados
             FROM vacantes v
             WHERE v.no_empleado_solicitante = ? AND NOT " . SIVAC_SQL_VACANTE_DESCARTE_VIEJO . "
             ORDER BY v.id DESC"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM vacantes v
                                 WHERE v.no_empleado_solicitante = ? AND " . SIVAC_SQL_VACANTE_DESCARTE_VIEJO);
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $ocultas = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
        $stmt->close();
        responder(true, '', ['data' => $data, 'ocultas' => $ocultas]);
    }

    case 'mis_candidatos': {
        // Candidatos de MIS vacantes, desde 'enviado_solicitante' en adelante.
        // Las citas se acotan a tipo 'jefe': al solicitante le importa SU
        // entrevista, no la que RRHH tenga agendada como filtro previo.
        // Se incluye la constancia de RRHH y el psicométrico (informativo, con su
        // veredicto): el jefe los ve para elegir a sus finalistas. Ahora el jefe SÍ
        // ve a los descartados (se hunden al final, atenuados en la UI), pero sólo
        // durante un mes después del descarte, o hasta que él lo oculte (retro
        // 2026-10-29); y las vacantes canceladas o rechazadas, también un mes.
        // «La última cita» excluye su cambio de fechas que espera o no tuvo VoBo
        // (ver includes/citas.php): ése viaja aparte, en cita_por_aprobar y
        // cita_no_aprobada_motivo (éste sólo si fue lo último que pasó).
        $stmt = $conn->prepare(
            "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.estatus, c.cv_archivo, c.cv_abierto_jefe, v.id AS id_vacante, v.folio, v.puesto, c.motivo_descarte,
                    c.entrevista_rrhh_fecha, c.entrevista_rrhh_resultado, c.entrevista_rrhh_observaciones,
                    c.psicometrico_fecha, c.psicometrico_calificacion, c.psicometrico_resultado, c.psicometrico_observaciones,
                    (SELECT ci.estatus FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus NOT IN ('por_aprobar','no_aprobada') ORDER BY ci.id DESC LIMIT 1) AS cita_estatus,
                    IFNULL(
                        (SELECT CONCAT_WS(' ó ', DATE_FORMAT(ci.opcion1, '%d/%m/%Y %H:%i'), DATE_FORMAT(ci.opcion2, '%d/%m/%Y %H:%i')                                )
                        FROM citas ci WHERE ci.id_candidato = c.id 
                        AND ci.tipo = 'jefe' AND ci.estatus = 'pendiente' 
                        ORDER BY ci.id DESC LIMIT 1), 
                        'Sin sugerencias'
                    ) AS cita_sugerida,
                    (SELECT ci.fecha_confirmada FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus IN ('confirmada','realizada') ORDER BY ci.id DESC LIMIT 1) AS cita_confirmada,
                    (SELECT ci.notas FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus NOT IN ('por_aprobar','no_aprobada') ORDER BY ci.id DESC LIMIT 1) AS cita_notas,
                    (SELECT ci.fecha_candidato FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus NOT IN ('por_aprobar','no_aprobada') ORDER BY ci.id DESC LIMIT 1) AS cita_fecha_candidato,
                    (SELECT COUNT(*) FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'cancelada') AS citas_reagendadas,
                    (SELECT CONCAT_WS(' ó ', DATE_FORMAT(ci.opcion1, '%d/%m/%Y %H:%i'), DATE_FORMAT(ci.opcion2, '%d/%m/%Y %H:%i'))
                       FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' AND ci.estatus = 'por_aprobar' ORDER BY ci.id DESC LIMIT 1) AS cita_por_aprobar,
                    (SELECT IF(ci.estatus = 'no_aprobada', ci.vobo_motivo, NULL)
                       FROM citas ci WHERE ci.id_candidato = c.id AND ci.tipo = 'jefe' ORDER BY ci.id DESC LIMIT 1) AS cita_no_aprobada_motivo,
                    (SELECT COUNT(*) FROM candidatos_comentarios cc WHERE cc.id_candidato = c.id AND cc.es_interno = 0) AS comentarios
             FROM candidatos c
             INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE v.no_empleado_solicitante = ?
               AND c.estatus <> 'aspirante'
               AND NOT " . SIVAC_SQL_DESCARTE_OCULTO . "
               AND NOT " . SIVAC_SQL_VACANTE_DESCARTE_VIEJO . "
             ORDER BY EXISTS (
                            SELECT 1 FROM citas ci 
                            WHERE ci.id_candidato = c.id 
                            AND ci.tipo = 'jefe' 
                            AND ci.estatus IN ('confirmada', 'realizada')
                        ) DESC, (c.estatus = 'descartado') ASC, FIELD(c.estatus,'enviado_solicitante') DESC, c.id DESC"
        );
        $stmt->bind_param('i', $noEmp);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        $stmt->close();
        // La fecha mínima viaja con el listado para que los diálogos de fechas la
        // pongan como `min` y la digan; la regla vive sólo en includes/citas.php.
        $min = sivacFechaMinimaEntrevista();
        responder(true, '', ['data' => $data,
            'fecha_minima' => date('Y-m-d\TH:i', $min), 'fecha_minima_txt' => sivacFechaMinimaTexto($min)]);
    }

    case 'aprobar_cv': {
        $id    = (int)($_POST['id'] ?? 0);
        $op1   = trim($_POST['opcion1'] ?? '');
        $op2   = trim($_POST['opcion2'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        // Ownership: el candidato debe pertenecer a una vacante de la sesión.
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');

        // Anticipación y días distintos (retro 2026-10-26): ver includes/citas.php.
        $t1 = (int)strtotime($op1); $t2 = (int)strtotime($op2);
        $error = sivacValidarOpcionesEntrevista($t1, $t2);
        if ($error !== '') responder(false, $error);

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
            "SELECT TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.puesto, v.tipo, c.creador_por AS no_empleado_creador, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            // El jefe ya propuso las dos fechas; el siguiente paso de RRHH es
            // confirmar cuál eligió el candidato. Las fechas van EN el aviso: es lo
            // que RRHH necesita para avisarle al candidato, sin abrir la ficha.
            $fmt1 = date('d/m/Y H:i', $t1); $fmt2 = date('d/m/Y H:i', $t2);
            $siguiente = 'confirmar cuál de las dos fechas eligió el candidato';
            notificarEvento($conn, 'entrevista_disponibilidad', [
                'destinos_no_empleado' => sivacDestinosRRHH($conn),
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => 'CV aprobado con fechas de entrevista — ' . $info['nombre'],
                'mensaje' => $info['folio'] . ' · ' . $fmt1 . ' o ' . $fmt2 . '; ' . $siguiente
                    . ($notas !== '' ? ' · ' . $notas : ''),
                'url' => 'candidatos.php',
            ]);
        }
        responder(true, 'CV aprobado y disponibilidad registrada.');
    }

    case 'reagendar_entrevista': {
        // El jefe mueve SU entrevista desde su pestaña. Hasta la retro del 26-oct
        // reprogramar sólo estaba en acciones_proceso.php (gate RRHH), así que un
        // cambio de agenda del jefe obligaba a un correo o una llamada.
        // Desde la del 29-oct, si hay una fecha vigente el cambio no se aplica solo:
        // espera el VoBo de RRHH (ver includes/citas.php).
        $id    = (int)($_POST['id'] ?? 0);
        $op1   = trim($_POST['opcion1'] ?? '');
        $op2   = trim($_POST['opcion2'] ?? '');
        $notas = trim($_POST['notas'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');

        $stmt = $conn->prepare("SELECT estatus FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$row) responder(false, 'Candidato no encontrado.');
        $actual = $row['estatus'];
        if ($actual !== 'aprobado_jefe' && $actual !== 'entrevista_confirmada') {
            responder(false, 'Sólo se puede reagendar mientras la entrevista sigue por hacerse.');
        }

        // La cita en vigor (sin contar un cambio que ya espere VoBo): de ella sale si
        // hace falta VoBo, la fecha pedida tras «Ninguna le queda» y el «antes» que
        // ve RRHH, que es lo que le tendría que cancelar al candidato.
        $stmt = $conn->prepare(
            "SELECT estatus, opcion1, opcion2, fecha_confirmada, fecha_candidato
               FROM citas WHERE id_candidato = ? AND tipo = 'jefe' AND estatus NOT IN ('por_aprobar','no_aprobada')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $ultima = $stmt->get_result()->fetch_assoc() ?: []; $stmt->close();
        // ¿Responde a un «el candidato no puede en ninguna»? Entonces no exige
        // motivo y deja pasar la fecha que pidió el candidato aunque no cumpla el aviso.
        $trasRechazo = ($ultima['estatus'] ?? '') === 'rechazada';
        // Hay fecha vigente → el cambio espera el VoBo de RRHH. Sin vigente (tras
        // «Ninguna le queda») no hay nada que cambiar: RRHH pidió estas fechas.
        $conVobo = in_array($ultima['estatus'] ?? '', ['pendiente', 'confirmada'], true);
        // Si lo último que pasó con las fechas es que RRHH le negó un cambio, la
        // vigente se queda: no se acepta otro. Lo destraba una cita nueva, que ya sería
        // la última: hoy sólo la que el jefe propone tras un «Ninguna le queda» de RRHH,
        // y eso sólo cabe si la vigente seguía pendiente. Con la entrevista ya
        // confirmada no hay salida en el sistema (el «Reprogramar» de RRHH está apagado).
        if ($conVobo) {
            $stmt = $conn->prepare("SELECT estatus FROM citas WHERE id_candidato = ? AND tipo = 'jefe' ORDER BY id DESC LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute();
            $ultEstatus = $stmt->get_result()->fetch_assoc()['estatus'] ?? ''; $stmt->close();
            if ($ultEstatus === 'no_aprobada') {
                responder(false, 'RRHH no aprobó tu cambio de fechas, así que sigue en pie la fecha vigente. '
                    . 'Si necesitas cambiarla, escríbele a RRHH en «Seguimiento».');
            }
        }

        // Anticipación y días distintos (retro 2026-10-26): ver includes/citas.php.
        $t1 = (int)strtotime($op1); $t2 = (int)strtotime($op2);
        $error = sivacValidarOpcionesEntrevista($t1, $t2, $trasRechazo ? ($ultima['fecha_candidato'] ?? null) : null);
        if ($error !== '') responder(false, $error);
        // El motivo queda en las notas de la cita nueva y es lo que la ficha de RRHH
        // enseña junto al cambio: sin él, el historial dice que se movió pero no por qué.
        if (!$trasRechazo && $notas === '') responder(false, 'Indica el motivo del cambio de fechas.');

        $antes = '';
        if (($ultima['estatus'] ?? '') === 'confirmada' && !empty($ultima['fecha_confirmada'])) {
            $antes = date('d/m/Y H:i', strtotime($ultima['fecha_confirmada'])) . ', ya confirmada con el candidato';
        } elseif (($ultima['estatus'] ?? '') === 'pendiente') {
            $antes = date('d/m/Y H:i', strtotime($ultima['opcion1'])) . ' o ' . date('d/m/Y H:i', strtotime($ultima['opcion2']));
        }

        $f1 = date('Y-m-d H:i:s', $t1); $f2 = date('Y-m-d H:i:s', $t2);
        $notasVal = $notas !== '' ? $notas : null;
        $corrige = false;
        if ($conVobo) {
            // No se toca la vigente: sigue en pie hasta el VoBo. Si ya había un cambio
            // esperándolo, se corrige ese mismo —nunca entró en vigor— y su aviso se
            // vuelve a medir desde ahora.
            $prev = sivacCitaPorAprobar($conn, $id);
            if ($prev) {
                $corrige = true;
                $stmt = $conn->prepare(
                    "UPDATE citas SET opcion1 = ?, opcion2 = ?, notas = ?, fecha_creacion = NOW()
                      WHERE id = ? AND estatus = 'por_aprobar'"
                );
                $stmt->bind_param('sssi', $f1, $f2, $notasVal, $prev['id']);
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO citas (id_candidato, tipo, opcion1, opcion2, duracion_aprox, notas, estatus)
                     VALUES (?, 'jefe', ?, ?, '', ?, 'por_aprobar')"
                );
                $stmt->bind_param('isss', $id, $f1, $f2, $notasVal);
            }
            $ok = $stmt->execute(); $stmt->close();
            if (!$ok) responder(false, 'No se pudo registrar el cambio de fechas.');
        } else {
            // Sin fecha vigente se registra directo, pendiente de que el candidato elija.
            $stmt = $conn->prepare("INSERT INTO citas (id_candidato, tipo, opcion1, opcion2, duracion_aprox, notas) VALUES (?, 'jefe', ?, ?, '', ?)");
            $stmt->bind_param('isss', $id, $f1, $f2, $notasVal);
            $ok = $stmt->execute(); $stmt->close();
            if (!$ok) responder(false, 'No se pudo registrar la nueva disponibilidad.');
        }

        $stmt = $conn->prepare(
            "SELECT TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            // Sólo campana para RRHH: es quien da el VoBo y vuelve a cuadrar la fecha
            // con el candidato. El correo al candidato sale cuando RRHH confirme,
            // igual que en el alta de la cita: no se le mandan dos mensajes seguidos.
            $fmt1 = date('d/m/Y H:i', $t1); $fmt2 = date('d/m/Y H:i', $t2);
            if ($conVobo) {
                $titulo = ($corrige ? 'El solicitante corrigió su cambio de fechas — ' : 'Cambio de fechas por aprobar — ');
                $sigue  = '; dale el VoBo o recházalo en Candidatos';
            } else {
                $titulo = 'Nuevas fechas de entrevista del solicitante — ';
                $sigue  = '; confirmar cuál elige el candidato';
            }
            notificarEvento($conn, 'entrevista_disponibilidad', [
                'destinos_no_empleado' => sivacDestinosRRHH($conn),
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => $titulo . $info['nombre'],
                'mensaje' => $info['folio'] . ' · nuevas opciones: ' . $fmt1 . ' o ' . $fmt2
                    . ($antes !== '' ? ' (vigente: ' . $antes . ')' : '')
                    . $sigue
                    . ($notas !== '' ? ($trasRechazo ? ' · ' : ' · Motivo: ') . $notas : ''),
                'url' => 'candidatos.php',
            ]);
        }
        if ($conVobo) {
            responder(true, 'Cambio de fechas enviado. Está pendiente del VoBo de RRHH; mientras tanto sigue en pie: ' . $antes . '.');
        }
        responder(true, 'Fechas enviadas. RRHH confirmará la fecha con el candidato.');
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
            "SELECT TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, c.creador_por AS no_empleado_creador, v.id AS id_vacante
             FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            notificarEvento($conn, 'cv_descartado', [
                'destinos_no_empleado' => sivacDestinosRRHH($conn),
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => 'CV descartado — ' . $info['nombre'],
                'mensaje' => $info['folio'] . ': ' . $motivo,
                'url' => 'candidatos.php',
            ]);
        }
        responder(true, 'Candidato descartado.');
    }

    case 'ocultar_descartado': {
        // «Ocultar» (retro 2026-10-29): el jefe quita de su pestaña a un descartado
        // antes del mes en que saldría solo (SIVAC_SQL_DESCARTE_OCULTO). Sólo su
        // pestaña: RRHH lo sigue viendo, y no es una transición, así que no va a la
        // bitácora. fecha_actualizacion se deja igual, como al abrir el CV.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');
        $stmt = $conn->prepare(
            "UPDATE candidatos SET oculto_jefe = NOW(), fecha_actualizacion = fecha_actualizacion
              WHERE id = ? AND estatus = 'descartado' AND oculto_jefe IS NULL"
        );
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute(); $n = $stmt->affected_rows; $stmt->close();
        if (!$ok) responder(false, 'No se pudo ocultar al candidato.');
        if ($n === 0) {
            $stmt = $conn->prepare("SELECT estatus, oculto_jefe FROM candidatos WHERE id = ? LIMIT 1");
            $stmt->bind_param('i', $id); $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$row || $row['estatus'] !== 'descartado') responder(false, 'Sólo se pueden ocultar candidatos descartados.');
        }
        responder(true, 'Candidato oculto de tu pestaña.');
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
            "SELECT c.estatus, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.puesto, c.creador_por AS no_empleado_creador, v.id AS id_vacante
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
        $destinosRrhh = sivacDestinosRRHH($conn);

        if ($resultado === 'aceptado') {
            // La lista de herramientas se la pasa el jefe a Almacén por su cuenta;
            // SIVAC sólo guarda si ya lo hizo, para decírselo a Almacén en el aviso
            // del alta. Se pregunta aquí porque es cuando el jefe ya sabe que esta
            // persona entra y qué va a necesitar.
            $herramientas = !empty($_POST['herramientas']) ? 1 : 0;
            $updH = $conn->prepare("UPDATE candidatos SET herramientas_notificadas = ? WHERE id = ?");
            $updH->bind_param('ii', $herramientas, $id);
            $updH->execute(); $updH->close();

            $r = cambiarEstatusCandidato($conn, $id, 'entrevistado', $noEmp,
                'Entrevista con el jefe realizada: aprobado.' . $sufijoNotas);
            if (!$r['ok']) responder(false, $r['message']);
            notificarEvento($conn, 'entrevista_resultado', [
                'destinos_no_empleado' => $destinosRrhh,
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo' => 'Entrevistado por el jefe — ' . $c['nombre'],
                'mensaje' => $c['folio'] . ' · ' . $c['puesto'] . ': aprobado, continúa el cierre.'
                    . $sufijoNotas,
                'url' => 'candidatos.php',
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
                'destinos_no_empleado' => $destinosRrhh,
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo' => 'Descartado tras la entrevista — ' . $c['nombre'],
                'mensaje' => $c['folio'] . ': ' . $motivo,
                'url' => 'candidatos.php',
            ]);
            responder(true, 'Candidato descartado.');
        }

        responder(false, 'Resultado inválido.');
    }

    case 'seguimiento': {
        // Lo que el jefe ve de la ficha (retro 2026-10-29): su versión del
        // historial de fechas —qué propuso, qué se confirmó, qué aprobó o no RRHH—,
        // la bitácora de RRHH y los comentarios que NO son internos. Es la misma
        // información que la ficha de RRHH menos las notas internas.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');

        $stmt = $conn->prepare(
            "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.estatus, c.cv_abierto_jefe,
                    v.folio, v.puesto
               FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $cand = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$cand) responder(false, 'Candidato no encontrado.');

        $hist = sivacHistorialCandidato($conn, $id);
        if ($hist === null) responder(false, 'No se pudo leer el historial.');
        responder(true, '', [
            'data' => $cand, 'historial' => $hist,
            'citas' => sivacHistorialCitas($conn, $id),
            'comentarios' => sivacComentarios($conn, $id, false),
        ]);
    }

    case 'comentar': {
        // El jefe le escribe a RRHH sobre su candidato. Lo suyo nunca es interno:
        // es justo para que RRHH lo lea.
        $id    = (int)($_POST['id'] ?? 0);
        $texto = (string)($_POST['comentario'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        if (!esSolicitanteDeCandidato($conn, $noEmp, $id)) responder(false, 'No tienes permiso sobre este candidato.');

        $error = sivacComentar($conn, $id, $noEmp, $texto, false);
        if ($error !== '') responder(false, $error);

        $stmt = $conn->prepare(
            "SELECT TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, v.folio, v.id AS id_vacante
               FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($info) {
            notificarEvento($conn, 'comentario_jefe', [
                'destinos_no_empleado' => sivacDestinosRRHH($conn),
                'id_candidato' => $id, 'id_vacante' => (int)$info['id_vacante'],
                'titulo' => 'Comentario del solicitante — ' . $info['nombre'],
                'mensaje' => $info['folio'] . ' · ' . sivacComentarioResumen($texto),
                'url' => 'candidatos.php',
                'dedup' => true,
            ]);
        }
        responder(true, 'Comentario enviado a RRHH.', ['comentarios' => sivacComentarios($conn, $id, false)]);
    }

// =========================================================================
    // CREAR SOLICITUD DE PUESTO (INSERT ÚNICO EN solicitudes_puesto)
    // =========================================================================
    case 'crear_solicitud_posicion': {
        // 1. Campos obligatorios principales
        $nombre_puesto     = trim($_POST['nombre_puesto'] ?? '');
        $fecha_solicitud   = !empty($_POST['fecha_solicitud']) ? trim($_POST['fecha_solicitud']) : date('Y-m-d');
        $numero_vacantes   = max(1, (int)($_POST['numero_vacantes'] ?? 1));
        $area              = trim($_POST['area'] ?? '');
        $jefe_solicitante  = trim($_POST['jefe_solicitante'] ?? '');
        $sede              = trim($_POST['sede'] ?? '');
        $tipo_contratacion = trim($_POST['tipo_contratacion'] ?? '');

        if (empty($nombre_puesto) || empty($area) || empty($jefe_solicitante) || empty($tipo_contratacion)) {
            responder(false, 'Por favor completa los campos obligatorios (*).');
        }

        // 2. Esquema de contratación y detalles opcionales
        $especificacion_temporal  = trim($_POST['especificacion_temporal'] ?? '');
        $proyecto_nombre          = trim($_POST['proyecto_nombre'] ?? '');
        $carreras_solicitadas      = trim($_POST['carreras_solicitadas'] ?? '');
        $proyecto_objetivo        = trim($_POST['proyecto_objetivo'] ?? '');
        $practicante_actividades  = trim($_POST['practicante_actividades'] ?? '');
        $periodo_estimado         = trim($_POST['periodo_estimado'] ?? '');
        $horario_solicitado       = trim($_POST['horario_solicitado'] ?? '');
        $horas_requeridas         = (int)($_POST['horas_requeridas'] ?? 0);
        $posibilidad_contratacion = trim($_POST['posibilidad_contratacion'] ?? '');

        // 3. Justificación Operativa
        $motivo_necesidad             = trim($_POST['motivo_necesidad'] ?? '');
        $evaluo_redistribucion        = trim($_POST['evaluo_redistribucion'] ?? '');
        $justificacion_redistribucion = trim($_POST['justificacion_redistribucion'] ?? '');
        $problema_resuelve            = trim($_POST['problema_resuelve'] ?? '');
        $quien_realiza_actualmente    = trim($_POST['quien_realiza_actualmente'] ?? '');
        $funciones_principales        = trim($_POST['funciones_principales'] ?? '');
        $riesgos_no_autorizacion      = trim($_POST['riesgos_no_autorizacion'] ?? '');
        $impacto_kpis                 = trim($_POST['impacto_kpis'] ?? '');

        // 4. Perfil Deseado
        $escolaridad            = trim($_POST['escolaridad'] ?? '');
        $carrera                = trim($_POST['carrera'] ?? '');
        $experiencia            = trim($_POST['experiencia'] ?? '');
        $conocimientos_tecnicos = trim($_POST['conocimientos_tecnicos'] ?? '');
        $software_requerido     = trim($_POST['software_requerido'] ?? '');

        // 5. Presupuesto y Recursos
        $sueldo_mensual_propuesto = (float)($_POST['sueldo_mensual_propuesto'] ?? 0);
        $accede_comisiones_bonos  = trim($_POST['accede_comisiones_bonos'] ?? 'No');
        $fecha_ideal_ingreso      = !empty($_POST['fecha_ideal_ingreso']) ? trim($_POST['fecha_ideal_ingreso']) : date('Y-m-d');
        $cuenta_estacion_trabajo  = trim($_POST['cuenta_estacion_trabajo'] ?? 'No');

        // Convierte el array de checkboxes a string formateado para la columna SET
        $equipo_array = $_POST['equipo_requerido'] ?? [];
        $equipo_requerido = is_array($equipo_array) ? implode(',', $equipo_array) : '';

        // Query INSERT parametrizada de 34 campos
        $sql = "INSERT INTO solicitudes_puesto (
            nombre_puesto, fecha_solicitud, numero_vacantes, area, jefe_solicitante, sede, tipo_contratacion,
            especificacion_temporal, proyecto_nombre, carreras_solicitadas, proyecto_objetivo, practicante_actividades,
            periodo_estimado, horario_solicitado, horas_requeridas, posibilidad_contratacion,
            motivo_necesidad, evaluo_redistribucion, justificacion_redistribucion, problema_resuelve,
            quien_realiza_actualmente, funciones_principales, riesgos_no_autorizacion, impacto_kpis,
            escolaridad, carrera, experiencia, conocimientos_tecnicos, software_requerido,
            sueldo_mensual_propuesto, accede_comisiones_bonos, fecha_ideal_ingreso, cuenta_estacion_trabajo, equipo_requerido
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            responder(false, 'Error al preparar la consulta: ' . $conn->error);
        }

        // Arreglo de los 34 parámetros exactos
        $params = [
            $nombre_puesto, $fecha_solicitud, $numero_vacantes, $area, $jefe_solicitante, $sede, $tipo_contratacion,
            $especificacion_temporal, $proyecto_nombre, $carreras_solicitadas, $proyecto_objetivo, $practicante_actividades,
            $periodo_estimado, $horario_solicitado, $horas_requeridas, $posibilidad_contratacion,
            $motivo_necesidad, $evaluo_redistribucion, $justificacion_redistribucion, $problema_resuelve,
            $quien_realiza_actualmente, $funciones_principales, $riesgos_no_autorizacion, $impacto_kpis,
            $escolaridad, $carrera, $experiencia, $conocimientos_tecnicos, $software_requerido,
            $sueldo_mensual_propuesto, $accede_comisiones_bonos, $fecha_ideal_ingreso, $cuenta_estacion_trabajo, $equipo_requerido
        ];

        // Construcción dinámica de la cadena de tipos (garantiza exactitud en la longitud)
        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        // Vincular parámetros y ejecutar
        $stmt->bind_param($types, ...$params);

        $ok = $stmt->execute();
        if (!$ok) {
            $err = $stmt->error;
            $stmt->close();
            responder(false, 'Error MySQL: ' . $err);
        }

        $stmt->close();
        responder(true, 'La solicitud del nuevo puesto ha sido enviada correctamente.');
    }

    // =========================================================================
    // CONSULTA DE SEGUIMIENTO (SELECT DESDE solicitudes_puesto)
    // =========================================================================
    case 'mis_solicitudes_posicion': {
        // Obtener el número de empleado actual desde la sesión/cookie
        $noEmpActual = (int)($_COOKIE['noEmpleado'] ?? 0);

        // Si es Dirección (19 o 403), ve absolutamente TODAS las solicitudes.
        // Si no, ve las que él creó O las que le toca autorizar como Jefe/Gerencia.
        if (in_array($noEmpActual, [19, 403])) {
            $sql = "SELECT s.id, s.nombre_puesto, s.fecha_solicitud, s.numero_vacantes, s.tipo_contratacion, 
                            s.estado_gerencia, s.estado_direccion, s.estado_general, s.comentarios_gerencia, 
                            s.comentarios_direccion, s.jefe_solicitante,
                            u.nombres, u.apellidos,
                            j.nombres as nombre_J, j.apellidos as apellidos_J, j.noEmpleado as noEmpleado_J
                    FROM solicitudes_puesto s
                    INNER JOIN mess_rrhh.usuarios u ON s.jefe_solicitante = u.noEmpleado
                    LEFT JOIN mess_rrhh.usuarios j ON u.jefe = j.noEmpleado
                    ORDER BY s.id DESC";
            $stmt = $conn->prepare($sql);
        } else {
            $sql = "SELECT s.id, s.nombre_puesto, s.fecha_solicitud, s.numero_vacantes, s.tipo_contratacion, 
                            s.estado_gerencia, s.estado_direccion, s.estado_general, s.comentarios_gerencia, 
                            s.comentarios_direccion, s.jefe_solicitante,
                            u.nombres, u.apellidos,
                            j.nombres as nombre_J, j.apellidos as apellidos_J, j.noEmpleado as noEmpleado_J
                    FROM solicitudes_puesto s
                    INNER JOIN mess_rrhh.usuarios u ON s.jefe_solicitante = u.noEmpleado
                    LEFT JOIN mess_rrhh.usuarios j ON u.jefe = j.noEmpleado
                    WHERE s.jefe_solicitante = ? OR u.jefe = ?
                    ORDER BY s.id DESC";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $noEmpActual, $noEmpActual);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = $row;
        }
        $stmt->close();

        responder(true, '', $data);
    }

    // =========================================================================
    // OBTENER INFORMACIÓN COMPLETA DE UNA SOLICITUD POR ID
    // =========================================================================
    case 'obtener_solicitud_posicion': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'ID de solicitud inválido.');

        $stmt = $conn->prepare("SELECT * FROM solicitudes_puesto WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $data = $res->fetch_assoc();
        $stmt->close();

        if (!$data) {
            responder(false, 'Solicitud no encontrada.');
        }

        responder(true, '', $data);
    }

    // =========================================================================
    // ACTUALIZAR REGISTRO EXISTENTE (UPDATE EN solicitudes_puesto)
    // =========================================================================
    case 'actualizar_solicitud_posicion': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'ID de solicitud inválido.');

        // Validar que NINGUNA de las dos áreas haya emitido dictamen aún
        $stmtCheck = $conn->prepare("SELECT estado_gerencia, estado_direccion FROM solicitudes_puesto WHERE id = ?");
        $stmtCheck->bind_param('i', $id);
        $stmtCheck->execute();
        $solicitudActual = $stmtCheck->get_result()->fetch_assoc();
        $stmtCheck->close();

        if (!$solicitudActual) {
            responder(false, 'La solicitud no existe.');
        }

        if ($solicitudActual['estado_gerencia'] !== 'Pendiente' || $solicitudActual['estado_direccion'] !== 'Pendiente') {
            responder(false, 'No es posible editar la solicitud porque ya cuenta con una resolución registrada (Gerencia o Dirección).');
        }

        $nombre_puesto     = trim($_POST['nombre_puesto'] ?? '');
        $numero_vacantes   = max(1, (int)($_POST['numero_vacantes'] ?? 1));
        $area              = trim($_POST['area'] ?? '');
        $sede              = trim($_POST['sede'] ?? '');
        $tipo_contratacion = trim($_POST['tipo_contratacion'] ?? '');

        if (empty($nombre_puesto) || empty($area) || empty($tipo_contratacion)) {
            responder(false, 'Por favor completa los campos obligatorios (*).');
        }

        $especificacion_temporal  = trim($_POST['especificacion_temporal'] ?? '');
        $proyecto_nombre          = trim($_POST['proyecto_nombre'] ?? '');
        $carreras_solicitadas      = trim($_POST['carreras_solicitadas'] ?? '');
        $proyecto_objetivo        = trim($_POST['proyecto_objetivo'] ?? '');
        $practicante_actividades  = trim($_POST['practicante_actividades'] ?? '');
        $periodo_estimado         = trim($_POST['periodo_estimado'] ?? '');
        $horario_solicitado       = trim($_POST['horario_solicitado'] ?? '');
        $horas_requeridas         = (int)($_POST['horas_requeridas'] ?? 0);
        $posibilidad_contratacion = trim($_POST['posibilidad_contratacion'] ?? '');

        $motivo_necesidad             = trim($_POST['motivo_necesidad'] ?? '');
        $evaluo_redistribucion        = trim($_POST['evaluo_redistribucion'] ?? '');
        $justificacion_redistribucion = trim($_POST['justificacion_redistribucion'] ?? '');
        $problema_resuelve            = trim($_POST['problema_resuelve'] ?? '');
        $quien_realiza_actualmente    = trim($_POST['quien_realiza_actualmente'] ?? '');
        $funciones_principales        = trim($_POST['funciones_principales'] ?? '');
        $riesgos_no_autorizacion      = trim($_POST['riesgos_no_autorizacion'] ?? '');
        $impacto_kpis                 = trim($_POST['impacto_kpis'] ?? '');

        $escolaridad            = trim($_POST['escolaridad'] ?? '');
        $carrera                = trim($_POST['carrera'] ?? '');
        $experiencia            = trim($_POST['experiencia'] ?? '');
        $conocimientos_tecnicos = trim($_POST['conocimientos_tecnicos'] ?? '');
        $software_requerido     = trim($_POST['software_requerido'] ?? '');

        $sueldo_mensual_propuesto = (float)($_POST['sueldo_mensual_propuesto'] ?? 0);
        $accede_comisiones_bonos  = trim($_POST['accede_comisiones_bonos'] ?? 'No');
        $fecha_ideal_ingreso      = !empty($_POST['fecha_ideal_ingreso']) ? trim($_POST['fecha_ideal_ingreso']) : date('Y-m-d');
        $cuenta_estacion_trabajo  = trim($_POST['cuenta_estacion_trabajo'] ?? 'No');

        $equipo_array = $_POST['equipo_requerido'] ?? [];
        $equipo_requerido = is_array($equipo_array) ? implode(',', $equipo_array) : '';

        $sql = "UPDATE solicitudes_puesto SET
            nombre_puesto = ?, numero_vacantes = ?, area = ?, sede = ?, tipo_contratacion = ?,
            especificacion_temporal = ?, proyecto_nombre = ?, carreras_solicitadas = ?, proyecto_objetivo = ?, practicante_actividades = ?,
            periodo_estimado = ?, horario_solicitado = ?, horas_requeridas = ?, posibilidad_contratacion = ?,
            motivo_necesidad = ?, evaluo_redistribucion = ?, justificacion_redistribucion = ?, problema_resuelve = ?,
            quien_realiza_actualmente = ?, funciones_principales = ?, riesgos_no_autorizacion = ?, impacto_kpis = ?,
            escolaridad = ?, carrera = ?, experiencia = ?, conocimientos_tecnicos = ?, software_requerido = ?,
            sueldo_mensual_propuesto = ?, me_accede_bonos = ?, fecha_ideal_ingreso = ?, cuenta_estacion_trabajo = ?, equipo_requerido = ?
            WHERE id = ?";

        // Corregido: 'accede_comisiones_bonos' en SQL
        $sql = str_replace('me_accede_bonos', 'accede_comisiones_bonos', $sql);

        $params = [
            $nombre_puesto, $numero_vacantes, $area, $sede, $tipo_contratacion,
            $especificacion_temporal, $proyecto_nombre, $carreras_solicitadas, $proyecto_objetivo, $practicante_actividades,
            $periodo_estimado, $horario_solicitado, $horas_requeridas, $posibilidad_contratacion,
            $motivo_necesidad, $evaluo_redistribucion, $justificacion_redistribucion, $problema_resuelve,
            $quien_realiza_actualmente, $funciones_principales, $riesgos_no_autorizacion, $impacto_kpis,
            $escolaridad, $carrera, $experiencia, $conocimientos_tecnicos, $software_requerido,
            $sueldo_mensual_propuesto, $accede_comisiones_bonos, $fecha_ideal_ingreso, $cuenta_estacion_trabajo, $equipo_requerido,
            $id
        ];

        $types = '';
        foreach ($params as $param) {
            if (is_int($param)) {
                $types .= 'i';
            } elseif (is_float($param)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            responder(false, 'Error al preparar la consulta de actualización: ' . $conn->error);
        }

        $stmt->bind_param($types, ...$params);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            responder(false, 'No se pudo actualizar la solicitud.');
        }

        responder(true, 'La solicitud ha sido actualizada con éxito.');
    }

    // =========================================================================
    // DICTAMINAR SOLICITUD DE PUESTO (GERENCIA / DIRECCIÓN / AUTORIZACIÓN ÚNICA)
    // =========================================================================
    case 'dictaminar_solicitud_posicion': {
        $id          = (int)($_POST['id'] ?? 0);
        $rol         = trim($_POST['rol'] ?? '');         // 'gerencia', 'direccion', 'unica'
        $dictamen    = trim($_POST['dictamen'] ?? '');    // 'Aprobado', 'Rechazado'
        $comentarios = trim($_POST['comentarios'] ?? '');

        // noEmpleado desde la cookie o sesión
        $noEmpActual = (int)($_COOKIE['noEmpleado'] ?? 0);

        if ($id <= 0 || empty($rol) || empty($dictamen)) {
            responder(false, 'Parámetros inválidos para procesar el dictamen.');
        }

        if (empty($comentarios)) {
            responder(false, 'La retroalimentación es obligatoria.');
        }

        // 1. Consultar el estado actual de la solicitud
        $stmt = $conn->prepare("SELECT id, nombre_puesto, estado_gerencia, estado_direccion FROM solicitudes_puesto WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $solicitud = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$solicitud) {
            responder(false, 'La solicitud especificada no existe.');
        }

        $estadoGerenciaFinal  = $solicitud['estado_gerencia'];
        $estadoDireccionFinal = $solicitud['estado_direccion'];
        $fechaAhora           = date('Y-m-d H:i:s');

        // 2. Aplicar actualización según el rol de dictamen
        if ($rol === 'unica') {
            $estadoGerenciaFinal  = $dictamen;
            $estadoDireccionFinal = $dictamen;
            $estadoGeneralFinal   = ($dictamen === 'Aprobado') ? 'Aprobada' : 'Rechazada';

            $sql = "UPDATE solicitudes_puesto SET 
                    estado_gerencia = ?, usuario_gerencia = ?, fecha_aut_gerencia = ?, comentarios_gerencia = ?,
                    estado_direccion = ?, usuario_direccion = ?, fecha_aut_direccion = ?, comentarios_direccion = ?,
                    estado_general = ? WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sisssisssi', 
                $dictamen, $noEmpActual, $fechaAhora, $comentarios,
                $dictamen, $noEmpActual, $fechaAhora, $comentarios,
                $estadoGeneralFinal, $id
            );
            $ok = $stmt->execute();
            $stmt->close();

        } elseif ($rol === 'gerencia') {
            $estadoGerenciaFinal = $dictamen;
            
            // Si Gerencia Aprueba -> Pendiente Dirección. Si Gerencia Rechaza -> Rechazada totalmente.
            if ($dictamen === 'Aprobado') {
                $estadoGeneralFinal = ($solicitud['estado_direccion'] === 'Aprobado') ? 'Aprobada' : 'Pendiente Dirección';
            } else {
                $estadoGeneralFinal = 'Rechazada';
            }

            $sql = "UPDATE solicitudes_puesto SET 
                    estado_gerencia = ?, usuario_gerencia = ?, fecha_aut_gerencia = ?, comentarios_gerencia = ?,
                    estado_general = ? WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sisssi', $dictamen, $noEmpActual, $fechaAhora, $comentarios, $estadoGeneralFinal, $id);
            $ok = $stmt->execute();
            $stmt->close();

        } elseif ($rol === 'direccion') {
            $estadoDireccionFinal = $dictamen;
            
            // Si Dirección Aprueba -> Si Gerencia aprobó es 'Aprobada', si no sigue pendiente Gerencia.
            if ($dictamen === 'Aprobado') {
                $estadoGeneralFinal = ($solicitud['estado_gerencia'] === 'Aprobado') ? 'Aprobada' : 'Pendiente Gerencia';
            } else {
                $estadoGeneralFinal = 'Rechazada';
            }

            $sql = "UPDATE solicitudes_puesto SET 
                    estado_direccion = ?, usuario_direccion = ?, fecha_aut_direccion = ?, comentarios_direccion = ?,
                    estado_general = ? WHERE id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('sisssi', $dictamen, $noEmpActual, $fechaAhora, $comentarios, $estadoGeneralFinal, $id);
            $ok = $stmt->execute();
            $stmt->close();
        }

        if (!$ok) {
            responder(false, 'No se pudo registrar la actualización en la base de datos.');
        }

        // 3. REGLA FINAL: Si ambas autorizaciones están Aprobadas, insertar nuevo puesto en la tabla `mess_rrhh.puesto`
        if ($estadoGerenciaFinal === 'Aprobado' && $estadoDireccionFinal === 'Aprobado') {
            $nombrePuesto  = $solicitud['nombre_puesto'];
            $estatusPuesto = 1; // 1 = Activo

            // Verificar si el puesto ya existe previamente en mess_rrhh.puesto para evitar duplicados
            $stmtCheck = $conn->prepare("SELECT id FROM mess_rrhh.puesto WHERE puesto = ?");
            $stmtCheck->bind_param('s', $nombrePuesto);
            $stmtCheck->execute();
            $resCheck = $stmtCheck->get_result();

            if ($resCheck->num_rows === 0) {
                $stmtInsert = $conn->prepare("INSERT INTO mess_rrhh.puesto (puesto, estatus) VALUES (?, ?)");
                $stmtInsert->bind_param('si', $nombrePuesto, $estatusPuesto);
                $stmtInsert->execute();
                $stmtInsert->close();
            }
            $stmtCheck->close();
        }

        responder(true, 'Dictamen registrado exitosamente.');
    }

    // =========================================================================
    // OBTENER CATÁLOGOS ACTIVOS (ÁREAS Y SEDES)
    // =========================================================================
case 'obtener_catalogos_solicitud': {
        // 1. Áreas (departamento)
        $sqlAreas = "SELECT id, departamento FROM mess_rrhh.departamento WHERE estatus = 1 ORDER BY departamento ASC";
        $resAreas = $conn->query($sqlAreas);
        $areas = [];
        if ($resAreas) {
            while ($row = $resAreas->fetch_assoc()) {
                $areas[] = [
                    'id'           => (int)$row['id'],
                    'departamento' => $row['departamento']
                ];
            }
        }

        // 2. Sedes (region)
        $sqlSedes = "SELECT id, region FROM mess_rrhh.region WHERE estatus = 1 ORDER BY region ASC";
        $resSedes = $conn->query($sqlSedes);
        $sedes = [];
        if ($resSedes) {
            while ($row = $resSedes->fetch_assoc()) {
                $sedes[] = [
                    'id'     => (int)$row['id'],
                    'region' => $row['region']
                ];
            }
        }

        // Garantizar el envío con 'data'
        responder(true, 'Catálogos obtenidos', [
            'areas' => $areas,
            'sedes' => $sedes
        ]);
    }

    default:
        responder(false, 'Acción no reconocida.');
}
