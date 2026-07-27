<?php
/**
 * encabezado.php — Cabecera común de las páginas internas (RRHH).
 *
 * Requisitos previos del archivo que lo incluye:
 *   require_once 'conn.php'; require_once 'auth.php';
 *   $noEmpSesion = requiereSesionPage();  requiereRRHHPage($conn, $noEmpSesion);
 *   $pageTitle = 'Título';  (opcional)  $menuActivo = 'vacantes'; (opcional)
 *
 * Todos los assets son LOCALES (requisito del proyecto: cero CDN).
 */
require_once __DIR__ . '/includes/assets.php';
if (!isset($noEmpSesion)) { $noEmpSesion = requiereSesionPage(); }
$pageTitle  = $pageTitle  ?? 'SIVAC';
$menuActivo = $menuActivo ?? '';
$datosUsuario = obtenerDatosEmpleado($conn, $noEmpSesion);
$nombreUsuario = $datosUsuario['nombre'] ?? ('Empleado #' . $noEmpSesion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title><?= htmlspecialchars($pageTitle) ?> · SIVAC</title>

    <!-- Anti-FOUC: aplica el tema guardado antes de pintar -->
    <script>
        (function () {
            try { if (localStorage.getItem('sivac-theme') === 'dark') document.documentElement.classList.add('pre-dark'); } catch (e) {}
        })();
    </script>

    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/datatables/dataTables.bootstrap4.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= sivacAsset('css/estilos.css') ?>" rel="stylesheet">
    <script>if (document.documentElement.classList.contains('pre-dark')) document.addEventListener('DOMContentLoaded', function(){ document.body.classList.add('theme-dark'); });</script>
</head>
<body id="page-top">
<div id="wrapper">
    <?php if (empty($embed)) include 'menu.php'; ?>

    <div id="content-wrapper" class="d-flex flex-column">
        <div id="content">
            <?php if (empty($embed)): ?>
            <nav class="navbar navbar-expand navbar-light topbar mb-4 static-top shadow">
                <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                    <i class="fa fa-bars"></i>
                </button>
                <span class="navbar-brand d-md-none text-primary font-weight-bold">SIVAC</span>

                <ul class="navbar-nav ml-auto align-items-center">
                    <!-- Campana de notificaciones -->
                    <li class="nav-item dropdown no-arrow mx-1">
                        <a class="nav-link dropdown-toggle" href="#" id="campanaDropdown" role="button"
                           data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <i class="fas fa-bell fa-fw"></i>
                            <span class="badge badge-danger badge-counter" id="campanaBadge" style="display:none">0</span>
                        </a>
                        <div class="dropdown-list dropdown-menu dropdown-menu-right shadow" style="min-width:20rem"
                             aria-labelledby="campanaDropdown" id="campanaLista">
                            <h6 class="dropdown-header" style="background:var(--accent)">Notificaciones</h6>
                            <div class="text-center text-muted small p-3" id="campanaVacia">Sin notificaciones.</div>
                        </div>
                    </li>
                    <div class="topbar-divider d-none d-sm-block"></div>

                    <!-- Tema claro/oscuro -->
                    <li class="nav-item mx-1">
                        <a class="nav-link" href="#" id="btnTema" title="Cambiar tema"><i class="fas fa-moon fa-fw"></i></a>
                    </li>

                    <!-- Usuario -->
                    <li class="nav-item dropdown no-arrow">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button"
                           data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                            <span class="mr-2 d-none d-lg-inline small" id="nombreUsuario"><?= htmlspecialchars($nombreUsuario) ?></span>
                            <i class="fas fa-user-circle fa-lg"></i>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right shadow" aria-labelledby="userDropdown">
                            <a class="dropdown-item" href="../loginMaster/inicio.php">
                                <i class="fas fa-th-large fa-sm fa-fw mr-2 text-gray-400"></i> Portal MESS
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="logout.php">
                                <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i> Salir
                            </a>
                        </div>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>

            <div class="container-fluid">
