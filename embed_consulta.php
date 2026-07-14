<?php
/**
 * embed_consulta.php — Vista de consulta (solo lectura) del avance de vacantes,
 * para iframe en loginMaster. Gate: sesión + tieneConsulta (RRHH o alta en
 * accesos_consulta). Sin datos personales de candidatos ni descargas: solo
 * conteos por etapa. Se renderiza server-side.
 */
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
$puede = tieneConsulta($conn, $noEmpSesion);
$embed = true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Consulta de vacantes · SIVAC</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="css/estilos.css" rel="stylesheet">
</head>
<body class="embed">
<div class="container-fluid">
<?php if (!$puede): ?>
    <div class="text-center text-muted py-5">
        <i class="fas fa-lock fa-2x mb-3"></i>
        <p>No tienes acceso a esta vista de consulta.<br>Solicítalo a Recursos Humanos.</p>
    </div>
<?php else:
    // estatuss que cuentan como "en proceso avanzado".
    $sql = "SELECT v.folio, v.puesto, v.estatus, v.departamento,
                   (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id) AS total,
                   (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'psicometrico_presentado') AS psico,
                   (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                      AND c.estatus IN ('entrevista_confirmada','entrevistado')) AS entrevista,
                   (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                      AND c.estatus IN ('propuesta_enviada','propuesta_expirada','propuesta_aceptada','documentacion')) AS propuesta,
                   (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'contratado') AS contratados
            FROM vacantes v
            ORDER BY FIELD(v.estatus,'abierta','en_proceso','pausada','cerrada','cancelada'), v.id DESC";
    $res = $conn->query($sql);
    $labelestatus = ['abierta'=>'Abierta','en_proceso'=>'En proceso','pausada'=>'Pausada','cerrada'=>'Cerrada','cancelada'=>'Cancelada'];
?>
    <h5 class="mb-3"><i class="fas fa-chart-line mr-2 text-primary"></i>Avance de vacantes</h5>
    <div class="card"><div class="card-body p-0"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>
                <th>Folio</th><th>Puesto</th><th class="text-center">estatus</th>
                <th class="text-center">Candidatos</th><th class="text-center">Psicométrico</th>
                <th class="text-center">Entrevista</th><th class="text-center">Propuesta</th><th class="text-center">Contratados</th>
            </tr></thead>
            <tbody>
            <?php while ($r = $res->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($r['folio']) ?></td>
                    <td><?= htmlspecialchars($r['puesto']) ?></td>
                    <td class="text-center"><span class="badge badge-estatus badge-vac-<?= htmlspecialchars($r['estatus']) ?>"><?= htmlspecialchars($labelestatus[$r['estatus']] ?? $r['estatus']) ?></span></td>
                    <td class="text-center"><?= (int)$r['total'] ?></td>
                    <td class="text-center"><?= (int)$r['psico'] ?></td>
                    <td class="text-center"><?= (int)$r['entrevista'] ?></td>
                    <td class="text-center"><?= (int)$r['propuesta'] ?></td>
                    <td class="text-center"><?= (int)$r['contratados'] ?></td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div></div></div>
<?php endif; ?>
</div>
</body>
</html>
