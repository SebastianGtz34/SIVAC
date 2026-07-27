<?php
/**
 * acciones_portal.php — Acciones del PORTAL DEL CANDIDATO (JSON).
 *
 * ⚠️ Superficie PÚBLICA. NO incluye auth.php ni ningún gate de RRHH. La única
 * credencial es el token del enlace: se resuelve a un id_candidato en el SERVIDOR
 * y TODAS las consultas usan ESE id. El cliente NUNCA manda id_candidato; aunque
 * lo mande, se ignora. Así un candidato jamás puede tocar el expediente de otro.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'includes/archivos.php';
require_once 'includes/accesos.php';
require_once 'includes/datos_alta.php';
require_once 'includes/notificaciones.php';

// POST desbordado (archivo mayor que post_max_size) antes de tocar $_POST.
if (sivacPostDesbordado()) {
    echo json_encode(['success' => false, 'message' => 'El archivo excede el tamaño máximo permitido por el servidor.']);
    exit;
}

function responder(bool $success, string $message = '', array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

// ── Autenticación por token ──────────────────────────────────────────────────
$token  = $_POST['t'] ?? $_GET['t'] ?? '';
$acceso = sivacResolverAcceso($conn, (string)$token);
if (!$acceso) {
    responder(false, 'Tu enlace no es válido o expiró. Solicita uno nuevo a Recursos Humanos.');
}
// id del SERVIDOR: la única fuente de verdad de a quién pertenece la sesión.
$idCandidato = (int)$acceso['id_candidato'];

// El candidato sólo puede operar mientras está en documentación. Se trae además
// el contexto para avisarle a RRHH lo que haga aquí (creador_por = la persona de
// RRHH que registró al candidato; el endpoint que lo crea es RRHH-gated).
$stmt = $conn->prepare(
    "SELECT c.estatus, TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS nombre,
            c.creador_por, c.id_vacante, v.folio
     FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
     WHERE c.id = ? LIMIT 1"
);
$stmt->bind_param('i', $idCandidato);
$stmt->execute();
$cand = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cand) responder(false, 'Expediente no encontrado.');
if ($cand['estatus'] !== 'documentacion') {
    responder(false, 'Tu expediente ya no está en la etapa de documentación.');
}

// La validación y el UPSERT de los datos del alta viven en includes/datos_alta.php:
// los comparte con el cierre de RRHH, que escribe la misma fila.

/**
 * Avisa a RRHH de algo que hizo el CANDIDATO. Va a la campana del portal (misma
 * de loginMaster) sin correo: son avisos de trabajo, no de trámite. `dedup` evita
 * ocho avisos cuando sube sus ocho documentos; origen 0 = no lo hizo un empleado.
 */
function avisarRrhh(mysqli $conn, array $cand, int $idCandidato, string $evento, string $titulo): void {
    $destino = (int)($cand['creador_por'] ?? 0);
    if ($destino <= 0) return;
    notificarEvento($conn, $evento, [
        'destino_no_empleado' => $destino,
        'origen_no_empleado'  => 0,
        'id_candidato' => $idCandidato,
        'id_vacante'   => (int)$cand['id_vacante'],
        'titulo'  => $titulo,
        'mensaje' => (string)$cand['folio'],
        'url'     => 'contrataciones.php',
        'correos' => [],
        'dedup'   => true,
    ]);
}

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

switch ($accion) {

    case 'subir_documento': {
        // Sube UN documento del catálogo. El archivo se valida por firma de bytes
        // (mismo motor que RRHH) y nace con validacion='pendiente', origen='candidato'.
        $idTipo = (int)($_POST['id_tipo'] ?? 0);
        if ($idTipo <= 0) responder(false, 'Selecciona el tipo de documento.');

        $stmt = $conn->prepare("SELECT 1 FROM documentos_tipos WHERE id = ? AND estatus = 1 LIMIT 1");
        $stmt->bind_param('i', $idTipo); $stmt->execute();
        $tipoOk = $stmt->get_result()->num_rows > 0; $stmt->close();
        if (!$tipoOk) responder(false, 'Tipo de documento inválido.');

        if (empty($_FILES['documento'])) responder(false, 'Adjunta el documento.');
        $doc = sivacGuardarArchivo($_FILES['documento'], ['pdf', 'jpg', 'jpeg', 'png'], SIVAC_MAX_DOC, SIVAC_DIR_DOC);
        if (!$doc['ok']) responder(false, $doc['message']);

        // subido_por = 0 → lo subió el candidato (no es empleado); origen lo confirma.
        $subidoPor = 0; $origen = 'candidato';
        $stmt = $conn->prepare(
            "INSERT INTO documentos (id_candidato, id_tipo, nombre_archivo, nombre_original, mime, tamano, subido_por, origen)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iisssiis', $idCandidato, $idTipo, $doc['nombre'], $doc['original'], $doc['mime'], $doc['tamano'], $subidoPor, $origen);
        $ok = $stmt->execute(); $stmt->close();
        if (!$ok) { @unlink(SIVAC_DIR_DOC . $doc['nombre']); responder(false, 'No se pudo guardar el documento.'); }
        avisarRrhh($conn, $cand, $idCandidato, 'documentos_recibidos',
            'Documentación por revisar — ' . $cand['nombre']);

        // Se devuelve el estado nuevo del renglón para que la página lo repinte SIN
        // recargar: recargar borraba los archivos ya elegidos en los otros renglones
        // y lo que el candidato llevara tecleado (y no guardado) en sus datos.
        responder(true, 'Documento subido. Recursos Humanos lo revisará.', ['documento' => [
            'id_tipo'         => $idTipo,
            'nombre_original' => $doc['original'],
            'validacion'      => 'pendiente',
        ]]);
    }

    case 'guardar_datos_fiscales': {
        $d = sivacSanearDatosAlta($_POST);
        if ($d['error']) responder(false, $d['error']);

        $ok = sivacGuardarDatosAlta($conn, $idCandidato, $d);
        // Se le dice al candidato qué le falta todavía: es la única señal que tiene
        // de que su expediente aún no está completo para el alta.
        $faltan = $ok ? sivacDatosAltaFaltantes($d) : [];
        // A RRHH se le avisa sólo cuando ya están COMPLETOS: es el momento en que
        // puede cerrar el alta. Los guardados parciales no son noticia.
        if ($ok && !$faltan) {
            avisarRrhh($conn, $cand, $idCandidato, 'datos_alta_completos',
                'Datos del alta completos — ' . $cand['nombre']);
        }
        $msg = $ok
            ? ($faltan ? 'Tus datos se guardaron. Te falta capturar: ' . implode(', ', $faltan) . '.' : 'Tus datos se guardaron.')
            : 'No se pudieron guardar tus datos.';
        responder($ok, $msg, ['faltan' => $faltan]);
    }

    default:
        responder(false, 'Acción no reconocida.');
}
