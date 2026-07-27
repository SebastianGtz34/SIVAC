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

// El candidato sólo puede operar mientras está en documentación.
$stmt = $conn->prepare("SELECT estatus FROM candidatos WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $idCandidato);
$stmt->execute();
$cand = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$cand) responder(false, 'Expediente no encontrado.');
if ($cand['estatus'] !== 'documentacion') {
    responder(false, 'Tu expediente ya no está en la etapa de documentación.');
}

/**
 * Sanea los datos fiscales. Todo es opcional al guardar (se puede capturar por
 * partes); si un campo viene, se valida su formato. CURP/RFC/NSS se normalizan a
 * mayúsculas. Devuelve ['error'=>?string, ...campos].
 */
function sanearDatosFiscales(array $post): array {
    $curp = strtoupper(trim($post['curp'] ?? ''));
    if ($curp !== '' && !preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[0-9A-Z]\d$/', $curp)) {
        return ['error' => 'La CURP no tiene un formato válido (18 caracteres).'];
    }
    $rfc = strtoupper(trim($post['rfc'] ?? ''));
    if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[0-9A-Z]{2,3}$/', $rfc)) {
        return ['error' => 'El RFC no tiene un formato válido.'];
    }
    $nss = trim($post['nss'] ?? '');
    if ($nss !== '' && !preg_match('/^\d{11}$/', $nss)) {
        return ['error' => 'El NSS debe tener 11 dígitos.'];
    }
    $sexo = trim($post['sexo'] ?? '');
    if ($sexo !== '' && !in_array($sexo, ['M', 'F'], true)) {
        return ['error' => 'Sexo inválido.'];
    }
    $fnRaw = trim($post['fecha_nacimiento'] ?? '');
    $fn = null;
    if ($fnRaw !== '') {
        $t = strtotime($fnRaw);
        if (!$t || $t >= time()) return ['error' => 'La fecha de nacimiento no es válida.'];
        $fn = date('Y-m-d', $t);
    }
    $sangre = trim($post['tipo_sangre'] ?? '');
    if ($sangre !== '' && !preg_match('/^(A|B|AB|O)[+-]$/', strtoupper(str_replace(' ', '', $sangre)))) {
        return ['error' => 'El tipo de sangre no es válido (p. ej. O+, A-).'];
    }
    return [
        'error' => null,
        'curp'  => $curp !== '' ? $curp : null,
        'rfc'   => $rfc !== '' ? $rfc : null,
        'nss'   => $nss !== '' ? $nss : null,
        'sexo'  => $sexo !== '' ? $sexo : null,
        'fecha_nacimiento' => $fn,
        'tipo_sangre' => $sangre !== '' ? strtoupper(str_replace(' ', '', $sangre)) : null,
    ];
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
        responder(true, 'Documento subido. Recursos Humanos lo revisará.');
    }

    case 'guardar_datos_fiscales': {
        $d = sanearDatosFiscales($_POST);
        if ($d['error']) responder(false, $d['error']);

        // UPSERT 1:1. No se tocan las columnas que decide RRHH/TI ni las banderas de alta.
        $stmt = $conn->prepare(
            "INSERT INTO candidatos_datos_alta (id_candidato, curp, rfc, nss, sexo, fecha_nacimiento, tipo_sangre)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE curp = VALUES(curp), rfc = VALUES(rfc), nss = VALUES(nss),
                                     sexo = VALUES(sexo), fecha_nacimiento = VALUES(fecha_nacimiento),
                                     tipo_sangre = VALUES(tipo_sangre)"
        );
        $stmt->bind_param('issssss', $idCandidato, $d['curp'], $d['rfc'], $d['nss'], $d['sexo'], $d['fecha_nacimiento'], $d['tipo_sangre']);
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Tus datos se guardaron.' : 'No se pudieron guardar tus datos.');
    }

    default:
        responder(false, 'Acción no reconocida.');
}
