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
require_once 'includes/accesos.php';
require_once 'includes/datos_alta.php';
require_once 'includes/catalogos.php';
require_once 'includes/alta_avisos.php';

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
    // telefono/nave/herramientas_notificadas y v.departamento sólo los usa el
    // alta (los avisos por área), pero salen gratis en el mismo SELECT.
    $stmt = $conn->prepare(
        "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre,
                c.correo, c.telefono, c.estatus, c.psicometrico_fecha, c.psicometrico_resultado,
                c.nave, c.herramientas_notificadas,
                v.id AS id_vacante, v.folio, v.puesto, v.tipo, v.departamento, v.no_empleado_solicitante
         FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * ¿El candidato tiene el psicométrico registrado? Se exige sólo su EXISTENCIA
 * (fecha + resultado) para salir de 'entrevistado' hacia propuesta/documentación.
 * El veredicto no bloquea: un 'no_apto' registrado también pasa (el psicométrico
 * sigue siendo informativo; sólo su captura es obligatoria). Espera el arreglo de
 * ctxCandidato(), que ya trae psicometrico_fecha y psicometrico_resultado.
 */
function psicometricoRegistrado(array $c): bool {
    return !empty($c['psicometrico_fecha']) && trim((string)($c['psicometrico_resultado'] ?? '')) !== '';
}

/**
 * Aviso (texto vacío = todo en orden) cuando la fecha límite de documentos cae
 * DESPUÉS del ingreso: el expediente debería estar completo antes de que la
 * persona entre. No bloquea a propósito —hay altas urgentes que se cierran con
 * documentos en tránsito— pero RRHH tiene que verlo, porque las dos fechas se
 * capturan por separado y nada las relacionaba.
 */
function avisoFechaDocs(?string $limite, ?string $ingreso): string {
    if (!$limite || !$ingreso) return '';
    $tl = strtotime($limite);
    $ti = strtotime($ingreso);
    if (!$tl || !$ti || $tl <= $ti) return '';
    return 'Ojo: la entrega de documentos vence el ' . date('d/m/Y', $tl)
         . ', DESPUÉS del ingreso (' . date('d/m/Y', $ti) . ').';
}

switch ($accion) {

    case 'listar': {
        sivacExpirarPropuestas($conn);
        $sql = "SELECT c.id, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre, c.correo, c.estatus, v.folio, v.puesto, v.tipo AS tipo_vacante,
                       (SELECT p.fecha_caducidad FROM propuestas p WHERE p.id_candidato = c.id AND p.estatus = 'enviada' ORDER BY p.id DESC LIMIT 1) AS caducidad,
                       ct.fecha_ingreso, ct.fecha_limite_documentos, ct.prorrogas, ct.reglamento_enviado, ct.estatus AS contr_estatus
                FROM candidatos c
                INNER JOIN vacantes v ON v.id = c.id_vacante
                LEFT JOIN contrataciones ct ON ct.id_candidato = c.id
                WHERE c.estatus IN ('entrevistado','propuesta_enviada','propuesta_expirada','propuesta_aceptada','documentacion','contratado')
                ORDER BY FIELD(c.estatus,'propuesta_enviada','entrevistado','propuesta_expirada','propuesta_aceptada','documentacion','contratado'), c.id DESC";
        $res = $conn->query($sql);
        $data = [];
        while ($r = $res->fetch_assoc()) {
            // Prácticas no lleva propuesta económica: la UI debe ofrecerle
            // "pasar a documentación" en vez del formulario de propuesta.
            $r['requiere_propuesta'] = sivacRequierePropuesta($r['tipo_vacante']) ? 1 : 0;
            $data[] = $r;
        }
        responder(true, '', ['data' => $data]);
    }

    case 'areas_alta': {
        // Catálogo para las casillas del alta. `tiene_correo` distingue el área
        // que RRHH todavía no configura: la casilla se pinta deshabilitada en vez
        // de dejar creer que ese aviso salió.
        $conCorreo = [];
        $res = $conn->query(
            "SELECT DISTINCT clave FROM notificaciones_destinatarios
              WHERE activo = 1 AND TRIM(correo) <> ''"
        );
        if ($res) while ($r = $res->fetch_assoc()) $conCorreo[] = $r['clave'];

        $data = [];
        foreach (sivacAreasAlta() as $clave => $area) {
            $data[] = [
                'clave' => $clave,
                'area'  => $area,
                'tiene_correo' => in_array($clave, $conCorreo, true) ? 1 : 0,
            ];
        }
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
        // Se corta ANTES de insertar: la rama de prácticas no tiene la
        // transición entrevistado → propuesta_enviada, así que sin esto la
        // propuesta quedaría guardada y huérfana cuando el flujo la rechazara.
        if (!sivacRequierePropuesta($c['tipo'])) {
            responder(false, 'Las vacantes de prácticas no llevan propuesta económica; pasa al candidato directo a documentación.');
        }
        if (!in_array($c['estatus'], ['entrevistado', 'propuesta_expirada'], true)) {
            responder(false, 'El candidato no está listo para recibir una propuesta.');
        }
        // El psicométrico debe estar registrado antes de pasar a propuesta.
        if (!psicometricoRegistrado($c)) {
            responder(false, 'Registra el psicométrico del candidato (fecha y resultado) antes de enviar la propuesta.');
        }

        $fechaCad = date('Y-m-d', $tc);
        $stmt = $conn->prepare(
            "INSERT INTO propuestas (id_candidato, condiciones, fecha_caducidad, capturado_por, documento)
             VALUES (?, ?, ?, ?, '')"
        );
        $stmt->bind_param('issi', $id, $condiciones, $fechaCad, $noEmp);
        $stmt->execute(); $stmt->close();

        $r = cambiarEstatusCandidato($conn, $id, 'propuesta_enviada', $noEmp, 'Propuesta enviada (caduca ' . $fechaCad . ').');
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

        /**
         * Avisa a RRHH lo que contestó el candidato: es quien sigue el trámite
         * (arrancar la documentación si aceptó, o retomar la búsqueda si no).
         * Antes iba al solicitante, pero él no ejecuta ninguno de los dos pasos.
         */
        $avisarRrhhPropuesta = function (bool $acepto, string $detalle) use ($conn, $c, $id) {
            notificarEvento($conn, $acepto ? 'propuesta_aceptada' : 'propuesta_rechazada', [
                'destinos_no_empleado' => sivacDestinosRRHH($conn),
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo'  => ($acepto ? 'Propuesta aceptada — ' : 'Propuesta rechazada — ') . $c['nombre'],
                'mensaje' => $c['folio'] . ' · ' . $c['puesto'] . ' · ' . $detalle,
                'url'     => 'contrataciones.php',
            ]);
        };

        if ($respuesta === 'aceptada') {
            $updP = $conn->prepare("UPDATE propuestas SET estatus = 'aceptada', fecha_respuesta = NOW() WHERE id = ?");
            $updP->bind_param('i', $idProp);
            $updP->execute();
            $updP->close();
            $r = cambiarEstatusCandidato($conn, $id, 'propuesta_aceptada', $noEmp, 'Propuesta aceptada por el candidato.');
            if (!$r['ok']) responder(false, $r['message']);
            // Avance automático a documentación + creación de la contratación.
            cambiarEstatusCandidato($conn, $id, 'documentacion', $noEmp, 'Inicia proceso de documentación.');
            $limite = date('Y-m-d', strtotime('+15 days'));
            $stmt = $conn->prepare("INSERT IGNORE INTO contrataciones (id_candidato, fecha_limite_documentos) VALUES (?, ?)");
            $stmt->bind_param('is', $id, $limite);
            $stmt->execute(); $stmt->close();
            $avisarRrhhPropuesta(true, 'entra a documentación; entrega hasta el ' . date('d/m/Y', strtotime($limite)));
            responder(true, 'Propuesta aceptada. Candidato en documentación.');
        } elseif ($respuesta === 'rechazada') {
            $updP = $conn->prepare("UPDATE propuestas SET estatus = 'rechazada', fecha_respuesta = NOW() WHERE id = ?");
            $updP->bind_param('i', $idProp);
            $updP->execute();
            $updP->close();
            $r = cambiarEstatusCandidato($conn, $id, 'descartado', $noEmp, 'Propuesta rechazada por el candidato.');
            if (!$r['ok']) responder(false, $r['message']);
            $etapa = 'propuesta';
            $stmt = $conn->prepare("UPDATE candidatos SET etapa_descarte = ?, motivo_descarte = 'Rechazó la propuesta' WHERE id = ?");
            $stmt->bind_param('si', $etapa, $id);
            $stmt->execute(); $stmt->close();
            $avisarRrhhPropuesta(false, 'queda descartado; hay que retomar la búsqueda');
            responder(true, 'Propuesta rechazada; candidato descartado.');
        }
        responder(false, 'Respuesta inválida.');
    }

    case 'iniciar_documentacion': {
        // Atajo del flujo de prácticas: entrevistado → documentación, sin
        // propuesta económica de por medio. Es el equivalente a lo que
        // 'responder_propuesta' hace para una vacante estándar cuando el candidato acepta.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if (sivacRequierePropuesta($c['tipo'])) {
            responder(false, 'Esta vacante lleva propuesta económica: envíala en vez de saltar a documentación.');
        }
        if ($c['estatus'] !== 'entrevistado') {
            responder(false, 'El candidato debe estar entrevistado para pasar a documentación.');
        }
        // El psicométrico debe estar registrado antes de pasar a documentación.
        if (!psicometricoRegistrado($c)) {
            responder(false, 'Registra el psicométrico del candidato (fecha y resultado) antes de pasar a documentación.');
        }

        $r = cambiarEstatusCandidato($conn, $id, 'documentacion', $noEmp, 'Candidato de prácticas aceptado; inicia documentación.');
        if (!$r['ok']) responder(false, $r['message']);

        $limite = date('Y-m-d', strtotime('+15 days'));
        $stmt = $conn->prepare("INSERT IGNORE INTO contrataciones (id_candidato, fecha_limite_documentos) VALUES (?, ?)");
        $stmt->bind_param('is', $id, $limite);
        $stmt->execute(); $stmt->close();

        responder(true, 'Candidato en documentación.');
    }

    case 'expirar_propuestas': {
        $n = sivacExpirarPropuestas($conn);
        responder(true, $n . ' propuesta(s) expirada(s).');
    }

    // NOTA: RRHH ya NO sube documentos. Los sube el candidato desde su portal
    // (acciones_portal.php, origen='candidato'); aquí RRHH sólo los ve y valida.

    case 'enlace_portal': {
        // Enlace del portal del candidato, en dos modos:
        //   (sin modo) → devuelve el enlace VIGENTE si lo hay, sin tocar nada. Es
        //                el mismo que el candidato ya recibió: repetírselo no le
        //                rompe el que tiene.
        //   modo=nuevo → genera uno nuevo, lo que INVALIDA el anterior.
        // Antes esto era una sola acción que siempre regeneraba: pedirle a RRHH el
        // enlace por segunda vez dejaba tirado al candidato a media documentación.
        $id   = (int)($_POST['id'] ?? 0);
        $modo = $_POST['modo'] ?? '';
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if ($c['estatus'] !== 'documentacion') responder(false, 'El candidato no está en documentación.');

        if ($modo === 'nuevo') {
            $token = sivacGenerarAcceso($conn, $id, $noEmp);
            responder(true, 'Enlace nuevo generado (vigencia 15 días). El anterior quedó invalidado.', [
                'url'    => sivacUrlPortal($token),
                'nuevo'  => 1,
                'expira' => date('d/m/Y', strtotime('+15 days')),
            ]);
        }

        $acceso = sivacAccesoVigente($conn, $id);
        if (!$acceso) responder(true, 'Este candidato todavía no tiene enlace.', ['vigente' => 0]);
        if (empty($acceso['token'])) {
            // Acceso creado antes de que se guardara el token en claro: sigue
            // funcionando para el candidato, pero aquí ya no se puede mostrar.
            responder(true, 'El enlace vigente se generó antes de esta versión y ya no se puede volver a mostrar.', [
                'vigente' => 1, 'sin_token' => 1,
                'expira'  => date('d/m/Y', strtotime($acceso['fecha_expira'])),
            ]);
        }
        responder(true, 'Es el mismo enlace que ya tiene el candidato.', [
            'vigente' => 1,
            'url'     => sivacUrlPortal($acceso['token']),
            'expira'  => date('d/m/Y', strtotime($acceso['fecha_expira'])),
        ]);
    }

    case 'validar_documento': {
        // Revisión de RRHH sobre un documento subido: validar o rechazar (con motivo).
        // Al resolverse se avisa al candidato por correo (punto 12).
        $idDoc    = (int)($_POST['id_documento'] ?? 0);
        $decision = $_POST['decision'] ?? '';   // 'validar' | 'rechazar'
        $motivo   = trim($_POST['motivo'] ?? '');
        if ($idDoc <= 0) responder(false, 'Id inválido.');
        if (!in_array($decision, ['validar', 'rechazar'], true)) responder(false, 'Decisión inválida.');
        if ($decision === 'rechazar' && $motivo === '') responder(false, 'Indica el motivo del rechazo.');

        // Documento + su tipo + candidato: para nombrar el documento y avisar al
        // candidato (antes esta acción sólo hacía SELECT 1, sin contexto).
        $stmt = $conn->prepare(
            "SELECT d.id_candidato, t.nombre AS doc_tipo
             FROM documentos d INNER JOIN documentos_tipos t ON t.id = d.id_tipo
             WHERE d.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $idDoc); $stmt->execute();
        $doc = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$doc) responder(false, 'Documento no encontrado.');

        if ($decision === 'validar') {
            $stmt = $conn->prepare(
                "UPDATE documentos SET validacion = 'validado', validado_por = ?, validado_fecha = NOW(), motivo_validacion = NULL WHERE id = ?"
            );
            $stmt->bind_param('ii', $noEmp, $idDoc);
        } else {
            $stmt = $conn->prepare(
                "UPDATE documentos SET validacion = 'rechazado', validado_por = ?, validado_fecha = NOW(), motivo_validacion = ? WHERE id = ?"
            );
            $stmt->bind_param('isi', $noEmp, $motivo, $idDoc);
        }
        $ok = $stmt->execute(); $stmt->close();
        if (!$ok) responder(false, 'No se pudo actualizar el documento.');

        // Aviso al candidato (sin campana: no es empleado interno). El envío respeta
        // el switch global de correo; apagado, sólo queda la bitácora en notificaciones.
        //
        // Validar NO manda correo: son hasta 8 documentos por candidato y el
        // resultado ya se ve en su portal, con palomita por renglón. Rechazar SÍ,
        // porque le pide una acción (volver a subirlo) y puede no entrar solo.
        $c = ctxCandidato($conn, (int)$doc['id_candidato']);
        if ($c && $c['correo']) {
            if ($decision === 'validar') {
                notificarEvento($conn, 'documento_validado', [
                    'id_candidato' => (int)$doc['id_candidato'], 'id_vacante' => (int)$c['id_vacante'],
                    'titulo' => 'Documento validado — ' . $doc['doc_tipo'],
                ]);
            } else {
                notificarEvento($conn, 'documento_rechazado', [
                    'id_candidato' => (int)$doc['id_candidato'], 'id_vacante' => (int)$c['id_vacante'],
                    'titulo' => 'Documento rechazado — ' . $doc['doc_tipo'],
                    'correos' => [$c['correo']],
                    'correo_asunto' => 'SIVAC — Documento rechazado (' . $c['folio'] . ')',
                    'correo_titulo' => 'Documento rechazado',
                    'correo_html' => 'Hola ' . htmlspecialchars($c['nombre']) . ',<br><br>Tu documento <strong>'
                        . htmlspecialchars($doc['doc_tipo']) . '</strong> fue <strong>rechazado</strong>.<br><br>'
                        . '<strong>Motivo:</strong> ' . htmlspecialchars($motivo)
                        . '<br><br>Vuelve a subirlo corregido desde tu enlace.',
                ]);
            }
        }

        responder(true, $decision === 'validar' ? 'Documento validado.' : 'Documento rechazado.');
    }

    // NOTA: RRHH ya NO elimina documentos (sólo ve y valida). Un documento
    // incorrecto se maneja con 'validar_documento' → rechazar (con motivo), y el
    // candidato lo vuelve a subir desde su portal.

    case 'datos_alta': {
        // Lo que el candidato capturó en su portal. RRHH necesita verlo ANTES de
        // completar el alta: es lo que gestionPersonal va a jalar de mess_sivac.
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $datos = sivacDatosAlta($conn, $id);

        // Las dos fechas del trámite viajan aquí para que el modal las muestre
        // juntas: se capturan en campos distintos y hasta ahora no había dónde
        // ver que el límite de documentos había quedado después del ingreso.
        $stmt = $conn->prepare(
            "SELECT fecha_ingreso, fecha_limite_documentos, prorrogas FROM contrataciones WHERE id_candidato = ? LIMIT 1"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $ct = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $ct = $ct ?: ['fecha_ingreso' => null, 'fecha_limite_documentos' => null, 'prorrogas' => 0];
        $ct['aviso'] = avisoFechaDocs($ct['fecha_limite_documentos'], $ct['fecha_ingreso']);

        responder(true, '', [
            'datos'   => $datos,
            'faltan'  => sivacDatosAltaFaltantes($datos),
            'catalogo_sangre' => sivacTiposSangre(),
            'contratacion'    => $ct,
        ]);
    }

    case 'guardar_datos_alta': {
        // Captura/corrección por parte de RRHH (el candidato puede no haberlos
        // llenado, o haberse equivocado). Se permite mientras gestionPersonal no
        // haya aplicado el alta: después la fila ya se consumió allá.
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $c = ctxCandidato($conn, $id);
        if (!$c) responder(false, 'Candidato no encontrado.');
        if (!in_array($c['estatus'], ['documentacion', 'contratado'], true)) {
            responder(false, 'El candidato todavía no está en documentación.');
        }
        $previos = sivacDatosAlta($conn, $id);
        if ((int)($previos['alta_aplicada'] ?? 0) === 1) {
            responder(false, 'El alta ya se aplicó en gestionPersonal; estos datos ya no se pueden cambiar aquí.');
        }

        $d = sivacSanearDatosAlta($_POST);
        if ($d['error']) responder(false, $d['error']);
        if (!sivacGuardarDatosAlta($conn, $id, $d)) responder(false, 'No se pudieron guardar los datos.');

        $faltan = sivacDatosAltaFaltantes($d);
        responder(true, $faltan ? 'Datos guardados. Falta: ' . implode(', ', $faltan) . '.' : 'Datos guardados.',
                  ['faltan' => $faltan]);
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
        if (!$ok) responder(false, 'No se pudo registrar.');

        $stmt = $conn->prepare("SELECT fecha_limite_documentos FROM contrataciones WHERE id_candidato = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $ct = $stmt->get_result()->fetch_assoc(); $stmt->close();
        $aviso = avisoFechaDocs($ct['fecha_limite_documentos'] ?? null, $fechaVal);
        responder(true, $aviso ?: 'Fecha de ingreso registrada.', ['aviso' => $aviso]);
    }

    case 'prorroga_documentos': {
        $id    = (int)($_POST['id'] ?? 0);
        $fecha = trim($_POST['fecha_limite'] ?? '');
        if ($id <= 0) responder(false, 'Id inválido.');
        $tf = strtotime($fecha);
        if (!$tf) responder(false, 'Fecha inválida.');

        $stmt = $conn->prepare("SELECT fecha_limite_documentos, fecha_ingreso FROM contrataciones WHERE id_candidato = ? LIMIT 1");
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
        if (!$ok) responder(false, 'No se pudo registrar.');
        $aviso = avisoFechaDocs($fechaVal, $row['fecha_ingreso'] ?? null);
        responder(true, $aviso ?: 'Prórroga registrada.', ['aviso' => $aviso]);
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

        // Datos que sólo sirven para los avisos y que decide RRHH en este momento:
        // los tres requerimientos y A QUÉ ÁREAS se avisa. No toda alta le toca a
        // todas — un administrativo no pasa por Almacén—, así que las casillas
        // mandan sobre el catálogo.
        $viaticos = !empty($_POST['req_viaticos']) ? 1 : 0;
        $celular  = !empty($_POST['req_celular'])  ? 1 : 0;
        $equipo   = !empty($_POST['req_equipo'])   ? 1 : 0;
        $areas    = $_POST['areas'] ?? [];
        if (!is_array($areas)) $areas = array_filter(explode(',', (string)$areas));
        $areas    = array_values(array_intersect($areas, array_keys(sivacAreasAlta())));

        // Requisitos: fecha de ingreso + reglamento + documentos obligatorios completos.
        $stmt = $conn->prepare("SELECT fecha_ingreso, reglamento_enviado FROM contrataciones WHERE id_candidato = ? LIMIT 1");
        $stmt->bind_param('i', $id); $stmt->execute();
        $ct = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$ct || !$ct['fecha_ingreso']) responder(false, 'Registra la fecha de ingreso antes de completar el alta.');
        if (!$ct['reglamento_enviado']) responder(false, 'Envía el reglamento de ingreso antes de completar el alta.');

        // Los documentos obligatorios deben estar VALIDADOS por RRHH, no solo subidos.
        $stmt = $conn->prepare(
            "SELECT (SELECT COUNT(*) FROM documentos_tipos WHERE obligatorio = 1 AND estatus = 1) AS req,
                    (SELECT COUNT(DISTINCT d.id_tipo) FROM documentos d
                       INNER JOIN documentos_tipos t ON t.id = d.id_tipo
                       WHERE d.id_candidato = ? AND t.obligatorio = 1 AND t.estatus = 1
                         AND d.validacion = 'validado') AS validados"
        );
        $stmt->bind_param('i', $id); $stmt->execute();
        $rc = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ((int)$rc['validados'] < (int)$rc['req']) {
            responder(false, 'Faltan documentos obligatorios validados (' . (int)$rc['validados'] . '/' . (int)$rc['req'] . ').');
        }

        // Sin estos datos la fila que jala gestionPersonal llega vacía y el alta hay
        // que teclearla a mano allá: se corta aquí, no después de haber avisado a
        // medio mundo. Si el candidato no los capturó, RRHH los captura en el modal.
        $faltan = sivacDatosAltaFaltantes(sivacDatosAlta($conn, $id));
        if ($faltan) {
            responder(false, 'Faltan datos del candidato para el alta: ' . implode(', ', $faltan)
                . '. Captúralos en «Datos para el alta» antes de continuar.');
        }

        $r = cambiarEstatusCandidato($conn, $id, 'contratado', $noEmp, 'Alta completada. Fecha de ingreso ' . $ct['fecha_ingreso'] . '.');
        if (!$r['ok']) responder(false, $r['message']);
        $updCt = $conn->prepare(
            "UPDATE contrataciones
                SET estatus = 'completada', alta_notificada = NOW(),
                    req_viaticos = ?, req_celular = ?, req_equipo = ?
              WHERE id_candidato = ?"
        );
        $updCt->bind_param('iiii', $viaticos, $celular, $equipo, $id);
        $updCt->execute();
        $updCt->close();
        // Cierra la vacante SÓLO si ya se cubrieron todas sus posiciones. El
        // candidato recién quedó 'contratado', así que el conteo lo incluye. Con
        // posiciones = 1 (default) cierra en el primer contratado, como antes.
        $idVac = (int)$c['id_vacante'];
        $stmt = $conn->prepare(
            "SELECT v.posiciones,
                    (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'contratado') AS contratados
             FROM vacantes v WHERE v.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $idVac);
        $stmt->execute();
        $vc = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($vc && (int)$vc['contratados'] >= (int)$vc['posiciones']) {
            $updV = $conn->prepare("UPDATE vacantes SET estatus = 'cerrada', fecha_cierre = NOW() WHERE id = ? AND estatus IN ('abierta','en_proceso')");
            $updV->bind_param('i', $idVac);
            $updV->execute();
            $updV->close();
        }

        // Marca al candidato como LISTO PARA ALTA en gestionPersonal. SIVAC nunca
        // escribe en mess_rrhh: sólo levanta la bandera y gestionPersonal jala los
        // datos (curp/rfc/nss/… que tecleó el candidato) desde mess_sivac.
        $updDa = $conn->prepare(
            "INSERT INTO candidatos_datos_alta (id_candidato, listo_para_alta, fecha_listo)
             VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE listo_para_alta = 1, fecha_listo = NOW()"
        );
        $updDa->bind_param('i', $id);
        $updDa->execute();
        $updDa->close();

        // El expediente quedó cerrado: se invalidan los enlaces del portal.
        sivacRevocarAccesos($conn, $id);

        // Campana al solicitante: es SU vacante y ya se cubrió. Sin correo — él es
        // empleado y la trae en el portal (política de la retro del 2026-08-06).
        notificarEvento($conn, 'alta_completada', [
            'destino_no_empleado' => (int)$c['no_empleado_solicitante'],
            'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
            'titulo' => 'Alta completada — ' . $c['nombre'],
            'mensaje' => $c['puesto'] . ' · ingreso ' . date('d/m/Y', strtotime($ct['fecha_ingreso'])),
            'url' => 'contrataciones.php',
        ]);

        // Un correo POR ÁREA, cada uno con los datos que esa área pide. La ficha
        // se resuelve a nombres (departamento, nave y jefe son ids) porque quien
        // lo recibe no tiene forma de traducir un catálogo.
        $sol = obtenerDatosEmpleado($conn, (int)$c['no_empleado_solicitante']);
        $ficha = [
            'nombre'          => $c['nombre'],
            'fecha_ingreso'   => date('d/m/Y', strtotime($ct['fecha_ingreso'])),
            'puesto'          => $c['puesto'],
            'area'            => catalogoDepartamentos($conn)[(int)($c['departamento'] ?? 0)] ?? '',
            'sede'            => catalogoNaves($conn)[(int)($c['nave'] ?? 0)] ?? '',
            'jefe'            => $sol['nombre'] ?? ('#' . (int)$c['no_empleado_solicitante']),
            'correo_personal' => $c['correo'],
            'cel_personal'    => $c['telefono'],
            'req_viaticos'    => $viaticos,
            'req_celular'     => $celular,
            'req_equipo'      => $equipo,
            'correo_mess'     => '',   // lo asigna Sistemas; SIVAC no lo conoce
            'herramientas_notificadas' => (int)($c['herramientas_notificadas'] ?? 0),
        ];

        $enviadas = [];
        foreach (sivacAvisosAlta($conn, $ficha, $areas) as $aviso) {
            notificarEvento($conn, 'alta_aviso_' . $aviso['clave'], [
                'id_candidato' => $id, 'id_vacante' => (int)$c['id_vacante'],
                'titulo'  => $aviso['titulo'],
                'mensaje' => $c['nombre'] . ' · ingreso ' . $ficha['fecha_ingreso'],
                'correos' => $aviso['correos'],
                'correo_asunto' => $aviso['asunto'],
                'correo_titulo' => $aviso['titulo'],
                'correo_html'   => $aviso['html'],
            ]);
            $enviadas[] = $aviso['area'];
        }

        // Un área marcada sin correo cargado NO detiene el alta, pero RRHH tiene
        // que enterarse: si no, cree que Nóminas ya recibió su aviso.
        $sinCorreo = sivacAreasAltaSinCorreo($conn, $areas);
        $msg = 'Alta completada.';
        $msg .= $enviadas ? ' Avisos enviados a: ' . implode(', ', $enviadas) . '.' : ' No se envió ningún aviso.';
        if ($sinCorreo) {
            $msg .= ' ⚠️ Sin correo configurado (no se avisó): ' . implode(', ', $sinCorreo)
                . '. Cárgalos en Configuración → Destinatarios.';
        }
        responder(true, $msg);
    }

    default:
        responder(false, 'Acción no reconocida.');
}
