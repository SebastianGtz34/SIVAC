<?php
/**
 * logout.php — Cierra la sesión de SIVAC. Borra solo la cookie propia (si
 * existiera) y devuelve al login del portal; la cookie global del portal la
 * gestiona loginMaster/logout.php.
 */
setcookie('noEmpleadoSVC', '', time() - 3600, '/');
header('Location: ../loginMaster/inicio.php');
exit;
