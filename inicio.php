<?php
/**
 * inicio.php — Dashboard de RRHH. KPIs + embudo del pipeline + candidatos por
 * vacante. Ejecuta la expiración lazy de propuestas al cargar.
 */
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/flujo.php';

$noEmpSesion = requiereSesionPage();
requiereRRHHPage($conn, $noEmpSesion);

// Expira propuestas vencidas antes de calcular indicadores.
sivacExpirarPropuestas($conn);

/** Helper: cuenta simple con consulta preparada sin parámetros. */
function contar(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    return (int)($res->fetch_assoc()['n'] ?? 0);
}

$kpiVacantesAbiertas = contar($conn, "SELECT COUNT(*) AS n FROM vacantes WHERE estatus IN ('abierta','en_proceso')");
$kpiCandidatosestatus = contar($conn, "SELECT COUNT(*) AS n FROM candidatos WHERE estatus NOT IN ('contratado','descartado')");
$kpiEntrevistasProximas = contar($conn, "SELECT COUNT(*) AS n FROM citas WHERE estatus = 'confirmada' AND fecha_confirmada >= NOW()");
$kpiPropuestasPorVencer = contar($conn, "SELECT COUNT(*) AS n FROM propuestas WHERE estatus = 'enviada' AND fecha_caducidad BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
$kpiContratadosMes = contar($conn, "SELECT COUNT(*) AS n FROM candidatos WHERE estatus = 'contratado' AND YEAR(fecha_actualizacion) = YEAR(CURDATE()) AND MONTH(fecha_actualizacion) = MONTH(CURDATE())");

// Embudo del pipeline: conteo por etapa (candidatos vivos + los que ya pasaron).
$etapas = [
    'aspirante'               => 'Capturados',
    'enviado_solicitante'     => 'Con solicitante',
    'aprobado_jefe'           => 'CV aprobado',
    'psicometrico_presentado' => 'Psicométrico',
    'entrevistado'            => 'Entrevistados',
    'propuesta_enviada'       => 'Propuesta',
    'contratado'              => 'Contratados',
];
$conteoEtapas = array_fill_keys(array_keys($etapas), 0);
$res = $conn->query("SELECT estatus, COUNT(*) AS n FROM candidatos GROUP BY estatus");
$porestatus = [];
while ($r = $res->fetch_assoc()) $porestatus[$r['estatus']] = (int)$r['n'];
// Embudo acumulado: cada etapa cuenta a quienes llegaron al menos hasta ahí.
$orden = ['aspirante','enviado_solicitante','aprobado_jefe','psicometrico_asignado',
          'psicometrico_presentado','entrevista_confirmada','entrevistado','propuesta_enviada',
          'propuesta_expirada','propuesta_aceptada','documentacion','contratado'];
$posEtapa = array_flip($orden);
foreach ($etapas as $clave => $lbl) {
    $min = $posEtapa[$clave];
    $suma = 0;
    foreach ($porestatus as $est => $n) {
        if (isset($posEtapa[$est]) && $posEtapa[$est] >= $min) $suma += $n;
    }
    $conteoEtapas[$clave] = $suma;
}

// Candidatos vs entrevistados por vacante activa (top 8 por candidatos).
$sqlVac = "SELECT v.folio, v.puesto,
             (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id) AS cand,
             (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                AND c.estatus IN ('entrevista_confirmada','entrevistado','propuesta_enviada',
                                 'propuesta_expirada','propuesta_aceptada','documentacion','contratado')) AS entr
           FROM vacantes v
           WHERE v.estatus IN ('abierta','en_proceso')
           ORDER BY cand DESC LIMIT 8";
$resVac = $conn->query($sqlVac);
$vacLabels = []; $vacCand = []; $vacEntr = [];
while ($r = $resVac->fetch_assoc()) {
    $vacLabels[] = $r['folio'];
    $vacCand[] = (int)$r['cand'];
    $vacEntr[] = (int)$r['entr'];
}

$chartData = [
    'embudo' => [
        'labels' => array_values($etapas),
        'data'   => array_values($conteoEtapas),
    ],
    'vacantes' => [
        'labels' => $vacLabels,
        'cand'   => $vacCand,
        'entr'   => $vacEntr,
    ],
];

$pageTitle  = 'Dashboard';
$menuActivo = 'inicio';
include 'encabezado.php';
?>
<div class="page-header">
    <h1><i class="fas fa-tachometer-alt mr-2 text-primary"></i>Dashboard</h1>
    <span class="text-muted small"><?= date('d/m/Y') ?></span>
</div>

<div class="row">
    <?php
    $tarjetas = [
        ['Vacantes abiertas', $kpiVacantesAbiertas, 'fa-briefcase', ''],
        ['Candidatos estatus', $kpiCandidatosestatus, 'fa-users', 'stat-info'],
        ['Entrevistas próximas', $kpiEntrevistasProximas, 'fa-calendar-check', 'stat-success'],
        ['Propuestas por vencer', $kpiPropuestasPorVencer, 'fa-hourglass-half', 'stat-warning'],
        ['Contratados del mes', $kpiContratadosMes, 'fa-user-check', 'stat-success'],
    ];
    foreach ($tarjetas as $t): ?>
    <div class="col-xl col-md-6 mb-4">
        <div class="card stat-card <?= $t[3] ?> h-100 py-2">
            <div class="card-body"><div class="row no-gutters align-items-center">
                <div class="col mr-2">
                    <div class="stat-label"><?= htmlspecialchars($t[0]) ?></div>
                    <div class="stat-value"><?= (int)$t[1] ?></div>
                </div>
                <div class="col-auto"><i class="fas <?= $t[2] ?> stat-icon"></i></div>
            </div></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row">
    <div class="col-lg-5 mb-4">
        <div class="card h-100">
            <div class="card-header">Embudo del proceso</div>
            <div class="card-body"><canvas id="chartEmbudo" height="260"></canvas></div>
        </div>
    </div>
    <div class="col-lg-7 mb-4">
        <div class="card h-100">
            <div class="card-header">Candidatos por vacante activa</div>
            <div class="card-body">
                <canvas id="chartVacantes" height="260"></canvas>
                <p class="text-muted small mt-2 mb-0" id="vacVacio" style="display:none">Aún no hay vacantes activas con candidatos.</p>
            </div>
        </div>
    </div>
</div>

<script>window.SIVAC_CHART = <?= json_encode($chartData) ?>;</script>
<?php include 'pie.php'; ?>
<script src="js/dashboard.js"></script>
