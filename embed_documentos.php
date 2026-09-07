<?php
/**
 * embed_documentos.php — Expedientes VALIDADOS, para el alta de nómina.
 *
 * Gate: sesión + la opción 'verDocumentos' en mess_rrhh.accesos_especiales
 * (sistema 'sivac'), la tabla de permisos que ya comparte el ecosistema. RRHH la
 * tiene implícita. El acceso se concede donde se administra esa tabla, no aquí.
 *
 * Qué enseña y qué NO, y por qué:
 *  - Sólo candidatos en 'documentacion' o 'contratado': son los que van al alta.
 *    Un aspirante o un descartado no le sirve a nómina y su expediente no tiene
 *    por qué estar a la vista.
 *  - Sólo documentos con validacion='validado'. Los pendientes y los rechazados
 *    se quedan fuera: si nómina trabaja con un documento que RRHH todavía no
 *    revisó —o que rechazó—, da de alta con un dato malo.
 *  - SIN descarga. Esta vista dice QUÉ hay y si ya está validado; los archivos en
 *    sí son datos personales del candidato y no salen de RRHH y su solicitante.
 *    descargar.php no reconoce este acceso, así que tampoco sirve poner el id a
 *    mano en la URL.
 *
 * Se renderiza server-side, igual que embed_consulta.php: es una vista de lectura,
 * no necesita su propio endpoint JSON.
 */
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/assets.php';
$noEmpSesion = requiereSesionPage();
$puede = tieneAccesoEspecial($conn, $noEmpSesion, 'verDocumentos');
$embed = true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documentos para alta · NEST</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="<?= sivacAsset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body class="embed">
<div class="container-fluid">
<?php if (!$puede): ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-lock fa-2x mb-3"></i>
        <p>No tienes acceso a los expedientes.<br>Solicítalo a Recursos Humanos.</p>
    </div>
<?php else:
    // Un renglón por documento validado. Se ordena por persona para que el
    // expediente de cada quien quede junto, y los más recientes primero.
    $sql = "SELECT c.id AS id_candidato,
                   TRIM(CONCAT_WS(' ', c.nombre, NULLIF(c.apellidos,''))) AS candidato,
                   c.estatus, v.folio, v.puesto,
                   d.nombre_original, d.tamano, d.validado_fecha,
                   t.nombre AS tipo
              FROM documentos d
              INNER JOIN documentos_tipos t ON t.id = d.id_tipo
              INNER JOIN candidatos c       ON c.id = d.id_candidato
              INNER JOIN vacantes v         ON v.id = c.id_vacante
             WHERE d.validacion = 'validado'
               AND c.estatus IN ('documentacion','contratado')
             ORDER BY c.id DESC, t.nombre";
    $res = $conn->query($sql);

    // Se agrupan en PHP y no con GROUP_CONCAT: así cada documento conserva su id
    // para el enlace de descarga.
    $porCandidato = [];
    while ($res && $r = $res->fetch_assoc()) {
        $porCandidato[$r['id_candidato']]['datos'] = $r;
        $porCandidato[$r['id_candidato']]['docs'][] = $r;
    }

    /** Tamaño legible; el mismo criterio que formatearTamano() del JS. */
    function docTamano($bytes): string {
        $b = (int)$bytes;
        if ($b < 1024)    return $b . ' B';
        if ($b < 1048576) return round($b / 1024, 1) . ' KB';
        return round($b / 1048576, 1) . ' MB';
    }
?>
    <h5 class="mb-1"><i class="fas fa-folder-open mr-2 text-primary"></i>Documentos para alta</h5>
    <p class="text-muted small mb-3">
        Expedientes ya <strong>validados por Recursos Humanos</strong> de quienes están en
        documentación o ya contratados. Los documentos pendientes o rechazados no aparecen.
    </p>

    <?php if (!$porCandidato): ?>
        <div class="text-center text-muted py-5">
            <i class="fas fa-inbox fa-2x mb-3"></i>
            <p>Todavía no hay documentos validados.</p>
        </div>
    <?php else: ?>
        <?php foreach ($porCandidato as $idCand => $grupo):
            $d = $grupo['datos']; ?>
            <div class="card mb-3 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <div class="fw-700" style="font-size:1.05rem"><?= htmlspecialchars($d['candidato']) ?></div>
                            <div class="text-muted small">
                                <?= htmlspecialchars($d['folio']) ?> · <?= htmlspecialchars($d['puesto']) ?>
                            </div>
                        </div>
                        <span class="badge badge-estatus badge-<?= htmlspecialchars($d['estatus']) ?>">
                            <?= $d['estatus'] === 'contratado' ? 'Contratado' : 'En documentación' ?>
                        </span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead><tr>
                                <th>Documento</th><th>Archivo</th>
                                <th class="text-center">Tamaño</th><th class="text-center">Validado</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($grupo['docs'] as $doc): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-check-circle text-success mr-1"></i>
                                        <?= htmlspecialchars($doc['tipo']) ?>
                                    </td>
                                    <td class="text-muted small"><?= htmlspecialchars($doc['nombre_original']) ?></td>
                                    <td class="text-center text-muted small"><?= docTamano($doc['tamano']) ?></td>
                                    <td class="text-center text-muted small">
                                        <?= $doc['validado_fecha'] ? date('d/m/Y', strtotime($doc['validado_fecha'])) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
