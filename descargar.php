<?php
/**
 * descargar.php — Único punto de descarga de archivos (CV y documentos).
 *
 * Los archivos viven bajo uploads/ (bloqueado por .htaccess): jamás se enlazan
 * por URL directa. Aquí se exige sesión y permiso por recurso:
 *   - CV / documentos: RRHH, o el solicitante DUEÑO de la vacante del candidato.
 *   - La vista de consulta NO descarga (los CV son datos personales).
 *
 * Uso: descargar.php?tipo=cv|documento&id=<id>
 *   - tipo=cv:        id = id del candidato (su CV).
 *   - tipo=documento: id = id de la fila en documentos.
 */

require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/candidatos.php';
require_once 'includes/archivos.php'; // constantes SIVAC_DIR_CV / SIVAC_DIR_DOC

$noEmp = sivacAuthNoEmpleado();
if (!$noEmp) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Sesión no válida.';
    exit;
}

$tipo = $_GET['tipo'] ?? '';
$id   = (int)($_GET['id'] ?? 0);
if ($id <= 0 || !in_array($tipo, ['cv', 'documento'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Solicitud inválida.';
    exit;
}

$esRRHH = esRRHH($conn, $noEmp);

if ($tipo === 'cv') {
    // El id es el del candidato; el archivo está en la propia fila.
    $stmt = $conn->prepare(
        "SELECT cv_archivo, cv_nombre_original FROM candidatos WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || !$row['cv_archivo']) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Archivo no encontrado.';
        exit;
    }
    // Permiso: RRHH o solicitante dueño del candidato.
    if (!$esRRHH && !esSolicitanteDeCandidato($conn, $noEmp, $id)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Sin permiso para descargar este archivo.';
        exit;
    }
    $rutaBase = SIVAC_DIR_CV;
    $archivo  = $row['cv_archivo'];
    $original = $row['cv_nombre_original'] ?: 'cv.pdf';
    $mime     = 'application/pdf';
} else {
    // documento: se resuelve el candidato dueño para validar permiso.
    $stmt = $conn->prepare(
        "SELECT nombre_archivo, nombre_original, mime, id_candidato, validacion
         FROM documentos WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Archivo no encontrado.';
        exit;
    }
    // Quién puede abrir un documento:
    //  - Quien tiene acceso a NEST, siempre.
    //  - El solicitante, POR PERSONA y no por ficha: el documento cuelga de la
    //    ficha en la que se subió, pero si esa persona también es candidata en su
    //    vacante, esa documentación es parte de su proceso. Comprobar sólo la ficha
    //    dueña dejaba el documento visible en la lista y en 403 al abrirlo.
    //  - El acceso especial 'verDocumentos' (alta de nómina), pero SÓLO sobre los
    //    ya validados: es exactamente lo que lista embed_documentos.php, y sin esa
    //    condición un id a mano en la URL destaparía los pendientes y rechazados,
    //    que son justo los que RRHH todavía no aprueba.
    $puedeDoc = $esRRHH
        || esSolicitanteDePersona($conn, $noEmp, (int)$row['id_candidato'])
        || ($row['validacion'] === 'validado' && tieneAccesoEspecial($conn, $noEmp, 'verDocumentos'));
    if (!$puedeDoc) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Sin permiso para descargar este archivo.';
        exit;
    }
    $rutaBase = SIVAC_DIR_DOC;
    $archivo  = $row['nombre_archivo'];
    $original = $row['nombre_original'] ?: 'documento';
    $mime     = $row['mime'] ?: 'application/octet-stream';
}

// basename() como defensa en profundidad (el nombre viene de BD, no del cliente).
$ruta = rtrim($rutaBase, '/\\') . DIRECTORY_SEPARATOR . basename($archivo);
if (!is_file($ruta)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Archivo no encontrado en el servidor.';
    exit;
}

// Nombre de descarga saneado (sin comillas ni saltos que rompan el header).
$nombreDescarga = preg_replace('/[^\w.\- ]+/u', '_', $original);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline; filename="' . $nombreDescarga . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($ruta);
exit;
