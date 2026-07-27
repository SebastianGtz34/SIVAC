<?php
/**
 * menu.php — Sidebar SB Admin 2. El item activo lo marca la variable $menuActivo
 * que fija cada página antes de incluir encabezado.php.
 *
 * El dashboard lo comparten RRHH y los gerentes (que lo ven acotado a su equipo),
 * pero el resto de las páginas siguen siendo RRHH-only. A un gerente se le
 * muestra solo lo que puede abrir: pintarle enlaces que lo rebotan al portal es
 * una invitación a un callejón sin salida. Esto es cosmético —el gate real lo
 * hace requiereRRHHPage() en cada página.
 */
$menuActivo = $menuActivo ?? '';
$menuEsRRHH = isset($conn, $noEmpSesion) ? esRRHH($conn, $noEmpSesion) : true;
function sivacActivo($nombre) {
    global $menuActivo;
    return $menuActivo === $nombre ? ' active' : '';
}
?>
<ul class="navbar-nav sidebar sidebar-dark accordion bg-gradient-primary" id="accordionSidebar">

    <a class="sidebar-brand d-flex align-items-center justify-content-center" href="inicio.php">
        <div class="sidebar-brand-icon"><i class="fas fa-user-tie"></i></div>
        <div class="sidebar-brand-text mx-2">SIVAC</div>
    </a>

    <hr class="sidebar-divider my-0">

    <li class="nav-item<?= sivacActivo('inicio') ?>">
        <a class="nav-link" href="inicio.php"><i class="fas fa-fw fa-tachometer-alt"></i><span>Dashboard</span></a>
    </li>

    <?php if ($menuEsRRHH): ?>
    <hr class="sidebar-divider">
    <div class="sidebar-heading">Proceso</div>

    <li class="nav-item<?= sivacActivo('vacantes') ?>">
        <a class="nav-link" href="vacantes.php"><i class="fas fa-fw fa-briefcase"></i><span>Vacantes</span></a>
    </li>
    <li class="nav-item<?= sivacActivo('candidatos') ?>">
        <a class="nav-link" href="candidatos.php"><i class="fas fa-fw fa-users"></i><span>Candidatos</span></a>
    </li>
    <li class="nav-item<?= sivacActivo('contrataciones') ?>">
        <a class="nav-link" href="contrataciones.php"><i class="fas fa-fw fa-file-signature"></i><span>Contrataciones</span></a>
    </li>

    <hr class="sidebar-divider">
    <div class="sidebar-heading">Administración</div>

    <li class="nav-item<?= sivacActivo('configuracion') ?>">
        <a class="nav-link" href="configuracion.php"><i class="fas fa-fw fa-cog"></i><span>Configuración</span></a>
    </li>
    <?php else: ?>
    <hr class="sidebar-divider">

    <li class="nav-item">
        <a class="nav-link" href="../loginMaster/inicio.php"><i class="fas fa-fw fa-th-large"></i><span>Portal MESS</span></a>
    </li>
    <?php endif; ?>

    <hr class="sidebar-divider d-none d-md-block">
    <div class="text-center d-none d-md-inline">
        <button class="rounded-circle border-0" id="sidebarToggle"></button>
    </div>
</ul>
