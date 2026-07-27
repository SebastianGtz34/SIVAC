<?php
/**
 * inicio.php — Dashboard. KPIs + embudo del pipeline + candidatos por vacante.
 * Ejecuta la expiración lazy de propuestas al cargar.
 *
 * Lo ven RRHH y los gerentes, pero no ven lo mismo:
 *   - RRHH     → todas las vacantes; puede filtrar por región y por gerente.
 *   - Gerente  → SOLO las vacantes de su gente (las suyas y las de sus
 *                subordinados directos). El acotamiento no es un filtro que el
 *                usuario elija: es un AND fijo que se aplica antes que nada y
 *                que ningún parámetro de la URL puede ensanchar.
 *
 * Todo indicador se calcula sobre el MISMO alcance (una sola cláusula WHERE
 * construida en un lugar), para que los KPIs, el embudo y la tabla no puedan
 * contradecirse entre sí.
 */
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/flujo.php';
require_once 'includes/catalogos.php';

$noEmpSesion = requiereSesionPage();
requiereDashboardPage($conn, $noEmpSesion);

$esRRHHSesion = esRRHH($conn, $noEmpSesion);

// Expira propuestas vencidas antes de calcular indicadores.
sivacExpirarPropuestas($conn);

// ---------------------------------------------------------------------------
// Alcance + filtros. Se arma una sola vez y se reutiliza en cada consulta.
// $alcanceSql siempre habla de la tabla vacantes con alias `v`.
// ---------------------------------------------------------------------------
$cond = []; $params = []; $tipos = '';

// Acotamiento duro por rol (antes de cualquier filtro del usuario).
if (!$esRRHHSesion) {
    $alcance = sivacAlcanceVacantes($conn, $noEmpSesion);
    // Los ids salen de la BD (nunca del cliente) y se castean, así que
    // interpolarlos en el IN (...) es seguro y evita un bind dinámico.
    $cond[] = 'v.no_empleado_solicitante IN (' . implode(',', array_map('intval', $alcance)) . ')';
}

// Filtro de región (opcional, ambos roles).
$filtroRegion = (int)($_GET['region'] ?? 0);
if ($filtroRegion > 0) {
    $cond[] = 'v.region = ?';
    $tipos .= 'i'; $params[] = $filtroRegion;
}

// Filtro de gerente/solicitante (opcional). Para un gerente esto solo puede
// estrechar su alcance, nunca salirse: el IN de arriba sigue aplicando.
$filtroGerente = (int)($_GET['gerente'] ?? 0);
if ($filtroGerente > 0) {
    $cond[] = 'v.no_empleado_solicitante = ?';
    $tipos .= 'i'; $params[] = $filtroGerente;
}

$alcanceSql = $cond ? (' AND ' . implode(' AND ', $cond)) : '';

/**
 * Cuenta con el alcance ya aplicado. $sql debe traer `vacantes v` y terminar en
 * una condición, porque aquí se le concatena el AND del alcance.
 */
function contarAlcance(mysqli $conn, string $sql): int {
    global $alcanceSql, $tipos, $params;
    $stmt = $conn->prepare($sql . $alcanceSql);
    if ($tipos) $stmt->bind_param($tipos, ...$params);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    $stmt->close();
    return $n;
}

$kpiVacantesAbiertas = contarAlcance($conn,
    "SELECT COUNT(*) AS n FROM vacantes v WHERE v.estatus IN ('abierta','en_proceso')");

$kpiCandidatosActivos = contarAlcance($conn,
    "SELECT COUNT(*) AS n FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
     WHERE c.estatus NOT IN ('contratado','descartado')");

// Candidatos rechazados (descartados) dentro del alcance, espeja a los activos.
$kpiCandidatosRechazados = contarAlcance($conn,
    "SELECT COUNT(*) AS n FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
     WHERE c.estatus = 'descartado'");

$kpiEntrevistasProximas = contarAlcance($conn,
    "SELECT COUNT(*) AS n FROM citas ci
       INNER JOIN candidatos c ON c.id = ci.id_candidato
       INNER JOIN vacantes v ON v.id = c.id_vacante
     WHERE ci.estatus = 'confirmada' AND ci.fecha_confirmada >= NOW()");

$kpiContratadosMes = contarAlcance($conn,
    "SELECT COUNT(*) AS n FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
     WHERE c.estatus = 'contratado'
       AND YEAR(c.fecha_actualizacion) = YEAR(CURDATE())
       AND MONTH(c.fecha_actualizacion) = MONTH(CURDATE())");

// ---------------------------------------------------------------------------
// Embudo del pipeline: cada etapa cuenta a quienes llegaron AL MENOS hasta ahí.
// El orden lo manda flujo.php (SIVAC_ORDEN_PIPELINE) para que el embudo no se
// desincronice del pipeline real cuando este cambie.
// ---------------------------------------------------------------------------
$etapas = [
    'aspirante'                  => 'Capturados',
    'enviado_solicitante'        => 'Con solicitante',
    'aprobado_jefe'              => 'CV aprobado',
    'entrevista_confirmada'      => 'Entrev. agendada',
    'entrevistado'               => 'Entrev. jefe',
    'propuesta_enviada'          => 'Propuesta',
    'contratado'                 => 'Contratados',
];

$stmt = $conn->prepare(
    "SELECT c.estatus, COUNT(*) AS n
       FROM candidatos c INNER JOIN vacantes v ON v.id = c.id_vacante
      WHERE 1 = 1" . $alcanceSql . "
      GROUP BY c.estatus"
);
if ($tipos) $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$porEstatus = [];
while ($r = $res->fetch_assoc()) $porEstatus[$r['estatus']] = (int)$r['n'];
$stmt->close();

$posEtapa = array_flip(SIVAC_ORDEN_PIPELINE);
$conteoEtapas = [];
foreach ($etapas as $clave => $lbl) {
    $min = $posEtapa[$clave];
    $suma = 0;
    foreach ($porEstatus as $est => $n) {
        // 'descartado' no está en el orden del pipeline: no suma a ninguna etapa.
        if (isset($posEtapa[$est]) && $posEtapa[$est] >= $min) $suma += $n;
    }
    $conteoEtapas[$clave] = $suma;
}

// ---------------------------------------------------------------------------
// Candidatos vs entrevistados por vacante activa (top 8 por candidatos).
// 'entrevistados' cuenta desde la entrevista con el jefe confirmada en adelante,
// igual que en el listado de vacantes.
// ---------------------------------------------------------------------------
$sqlVac = "SELECT v.folio, v.puesto,
             (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id) AS cand,
             (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id
                AND c.estatus IN ('entrevista_confirmada','entrevistado','propuesta_enviada',
                                 'propuesta_expirada','propuesta_aceptada','documentacion','contratado')) AS entr
           FROM vacantes v
           WHERE v.estatus IN ('abierta','en_proceso')" . $alcanceSql . "
           ORDER BY cand DESC LIMIT 8";
$stmt = $conn->prepare($sqlVac);
if ($tipos) $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$resVac = $stmt->get_result();
$vacLabels = []; $vacCand = []; $vacEntr = [];
while ($r = $resVac->fetch_assoc()) {
    $vacLabels[] = $r['folio'];
    $vacCand[] = (int)$r['cand'];
    $vacEntr[] = (int)$r['entr'];
}
$stmt->close();

// ---------------------------------------------------------------------------
// Rechazados por etapa (punto 8). Fuente correcta: candidatos_historial, la etapa
// DESDE la que se descartó (estatus_anterior con estatus_nuevo = 'descartado'). No
// se usa etapa_descarte porque mezcla dos vocabularios y partiría una etapa en dos.
// ---------------------------------------------------------------------------
$stmt = $conn->prepare(
    "SELECT h.estatus_anterior AS etapa, COUNT(*) AS n
       FROM candidatos_historial h
       INNER JOIN candidatos c ON c.id = h.id_candidato
       INNER JOIN vacantes v ON v.id = c.id_vacante
      WHERE h.estatus_nuevo = 'descartado'" . $alcanceSql . "
      GROUP BY h.estatus_anterior"
);
if ($tipos) $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$resR = $stmt->get_result();
$rechazosRaw = [];
while ($r = $resR->fetch_assoc()) $rechazosRaw[$r['etapa']] = (int)$r['n'];
$stmt->close();
// Orden por pipeline; sólo las etapas con rechazos.
$rechLabels = []; $rechData = [];
foreach (SIVAC_ORDEN_PIPELINE as $est) {
    if (!empty($rechazosRaw[$est])) { $rechLabels[] = sivacEstatusLabel($est); $rechData[] = $rechazosRaw[$est]; }
}

// ---------------------------------------------------------------------------
// Tiempo promedio por etapa (días). Se calcula en PHP a partir del historial
// (portable, sin window functions): para cada candidato, el tiempo en una etapa
// es la diferencia entre la transición que sale de ella y la que entró. La fila
// sintética 'aspirante → aspirante' es sólo la línea base (no se mide como etapa).
// ---------------------------------------------------------------------------
$stmt = $conn->prepare(
    "SELECT h.id_candidato, h.estatus_anterior, h.fecha_creacion
       FROM candidatos_historial h
       INNER JOIN candidatos c ON c.id = h.id_candidato
       INNER JOIN vacantes v ON v.id = c.id_vacante
      WHERE 1 = 1" . $alcanceSql . "
      ORDER BY h.id_candidato, h.id"
);
if ($tipos) $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$resH = $stmt->get_result();
$acHoras = []; $acN = []; $prevCand = null; $prevFecha = null;
while ($r = $resH->fetch_assoc()) {
    if ($r['id_candidato'] !== $prevCand) { $prevCand = $r['id_candidato']; $prevFecha = $r['fecha_creacion']; continue; }
    $et = $r['estatus_anterior'];
    $horas = (strtotime($r['fecha_creacion']) - strtotime($prevFecha)) / 3600;
    if ($horas >= 0) { $acHoras[$et] = ($acHoras[$et] ?? 0) + $horas; $acN[$et] = ($acN[$et] ?? 0) + 1; }
    $prevFecha = $r['fecha_creacion'];
}
$stmt->close();
$tiempoLabels = []; $tiempoData = [];
foreach (SIVAC_ORDEN_PIPELINE as $est) {
    if (!empty($acN[$est])) {
        $tiempoLabels[] = sivacEstatusLabel($est);
        $tiempoData[]   = round(($acHoras[$est] / $acN[$est]) / 24, 1);
    }
}

// ---------------------------------------------------------------------------
// Detalle por vacante (respaldo): folio, puesto, días abierta y candidatos.
// Días abierta = fecha_cierre − fecha_creacion (o los transcurridos si sigue abierta).
// ---------------------------------------------------------------------------
$stmt = $conn->prepare(
    "SELECT v.folio, v.puesto, v.estatus, v.fecha_cierre,
            DATEDIFF(COALESCE(v.fecha_cierre, NOW()), v.fecha_creacion) AS dias,
            (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id) AS cand,
            (SELECT COUNT(*) FROM candidatos c WHERE c.id_vacante = v.id AND c.estatus = 'contratado') AS contr
       FROM vacantes v
      WHERE 1 = 1" . $alcanceSql . "
      ORDER BY (v.fecha_cierre IS NULL) ASC, v.fecha_cierre DESC, v.id DESC
      LIMIT 30"
);
if ($tipos) $stmt->bind_param($tipos, ...$params);
$stmt->execute();
$resD = $stmt->get_result();
$detalleVac = []; $sumDiasCerradas = 0; $nCerradas = 0;
while ($r = $resD->fetch_assoc()) {
    $detalleVac[] = $r;
    if ($r['estatus'] === 'cerrada' && $r['fecha_cierre']) { $sumDiasCerradas += (int)$r['dias']; $nCerradas++; }
}
$stmt->close();
$promDiasLlenado = $nCerradas > 0 ? round($sumDiasCerradas / $nCerradas, 1) : null;

// ---------------------------------------------------------------------------
// Opciones de los selects. Solo se ofrecen valores que existen DENTRO del
// alcance del usuario: a un gerente no se le listan gerentes ajenos ni regiones
// donde no tiene vacantes (además de que el filtro no le serviría de nada).
// El alcance se aplica sin los filtros activos para que las listas no se vacíen
// al elegir una opción.
// ---------------------------------------------------------------------------
$condBase = [];
if (!$esRRHHSesion) {
    $condBase[] = 'v.no_empleado_solicitante IN (' . implode(',', array_map('intval', sivacAlcanceVacantes($conn, $noEmpSesion))) . ')';
}
$baseSql = $condBase ? (' AND ' . implode(' AND ', $condBase)) : '';

$regiones = catalogoRegiones($conn);
$regionesDisp = [];
$res = $conn->query("SELECT DISTINCT v.region FROM vacantes v WHERE v.region IS NOT NULL" . $baseSql);
while ($r = $res->fetch_assoc()) {
    $rid = (int)$r['region'];
    if (isset($regiones[$rid])) $regionesDisp[$rid] = $regiones[$rid];
}
asort($regionesDisp);

$gerentesDisp = [];
$res = $conn->query("SELECT DISTINCT v.no_empleado_solicitante FROM vacantes v WHERE 1 = 1" . $baseSql);
while ($r = $res->fetch_assoc()) {
    $g = (int)$r['no_empleado_solicitante'];
    $emp = obtenerDatosEmpleado($conn, $g);
    $gerentesDisp[$g] = $emp['nombre'] ?? ('#' . $g);
}
asort($gerentesDisp);

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
    'rechazos' => [
        'labels' => $rechLabels,
        'data'   => $rechData,
    ],
    'tiempos' => [
        'labels' => $tiempoLabels,
        'data'   => $tiempoData,
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

<?php if (!$esRRHHSesion): ?>
<div class="alert alert-info py-2 small">
    <i class="fas fa-info-circle mr-1"></i>
    Estás viendo las vacantes de tu equipo: las que solicitaste tú y las de las personas a tu cargo.
</div>
<?php endif; ?>

<form class="card mb-4" method="get" id="formFiltros">
    <div class="card-body py-3">
        <div class="form-row align-items-end">
            <div class="form-group col-md-4 mb-0">
                <label class="small text-muted mb-1">Región</label>
                <select class="form-control form-control-sm" name="region" id="filtroRegion">
                    <option value="">Todas</option>
                    <?php foreach ($regionesDisp as $rid => $rnombre): ?>
                    <option value="<?= $rid ?>" <?= $filtroRegion === $rid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rnombre) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-5 mb-0">
                <label class="small text-muted mb-1"><?= $esRRHHSesion ? 'Gerente / solicitante' : 'Persona de mi equipo' ?></label>
                <select class="form-control form-control-sm" name="gerente" id="filtroGerente">
                    <option value="">Todos</option>
                    <?php foreach ($gerentesDisp as $gid => $gnombre): ?>
                    <option value="<?= $gid ?>" <?= $filtroGerente === $gid ? 'selected' : '' ?>>
                        <?= htmlspecialchars($gnombre) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-3 mb-0 text-right">
                <?php if ($filtroRegion || $filtroGerente): ?>
                <a href="inicio.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times mr-1"></i>Limpiar
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<div class="row">
    <?php
    $tarjetas = [
        ['Vacantes abiertas', $kpiVacantesAbiertas, 'fa-briefcase', ''],
        ['Candidatos activos', $kpiCandidatosActivos, 'fa-users', 'stat-info'],
        ['Candidatos rechazados', $kpiCandidatosRechazados, 'fa-user-slash', 'stat-danger'],
        ['Entrevistas próximas', $kpiEntrevistasProximas, 'fa-calendar-check', 'stat-success'],
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

<!-- Histórico y respaldo (punto 8): rechazos por etapa, tiempos y detalle por vacante. -->
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Rechazados por etapa</div>
            <div class="card-body">
                <canvas id="chartRechazos" height="260"></canvas>
                <p class="text-muted small mt-2 mb-0" id="rechVacio" style="display:none">Aún no hay descartes registrados.</p>
            </div>
        </div>
    </div>
    <div class="col-lg-6 mb-4">
        <div class="card h-100">
            <div class="card-header">Tiempo promedio por etapa (días)</div>
            <div class="card-body">
                <canvas id="chartTiempos" height="260"></canvas>
                <p class="text-muted small mt-2 mb-0" id="tiempoVacio" style="display:none">Aún no hay suficientes transiciones para medir tiempos.</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-12 mb-4">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>Detalle por vacante</span>
                <?php if ($promDiasLlenado !== null): ?>
                <span class="small text-muted">Promedio para llenar una vacante:
                    <strong><?= $promDiasLlenado ?> días</strong></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr>
                            <th>Folio</th><th>Puesto</th><th class="text-center">Estatus</th>
                            <th class="text-center">Días abierta</th><th class="text-center">Candidatos</th>
                            <th class="text-center">Contratados</th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$detalleVac): ?>
                            <tr><td colspan="6" class="text-muted small">Sin vacantes en tu alcance.</td></tr>
                        <?php else: foreach ($detalleVac as $d): ?>
                            <tr>
                                <td><?= htmlspecialchars($d['folio']) ?></td>
                                <td><?= htmlspecialchars($d['puesto']) ?></td>
                                <td class="text-center"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $d['estatus']))) ?></td>
                                <td class="text-center"><?= (int)$d['dias'] ?><?= $d['fecha_cierre'] ? '' : ' <span class="text-muted small">(en curso)</span>' ?></td>
                                <td class="text-center"><?= (int)$d['cand'] ?></td>
                                <td class="text-center"><?= (int)$d['contr'] ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>window.SIVAC_CHART = <?= json_encode($chartData) ?>;</script>
<?php include 'pie.php'; ?>
<script src="<?= sivacAsset('js/dashboard.js') ?>"></script>
