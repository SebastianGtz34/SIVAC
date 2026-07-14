<?php
/**
 * index.php — Punto de entrada. Si hay sesión válida del portal va al dashboard;
 * si no, rebota al login de loginMaster.
 */
require_once 'auth.php';

$noEmp = sivacAuthNoEmpleado();
if (!$noEmp) {
    header('Location: ../loginMaster/index.php');
    exit;
}
header('Location: inicio.php');
exit;
