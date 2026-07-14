<?php
// ============================================================================
// PLANTILLA de conexión — copiar como conn.php y poner credenciales reales.
// conn.php está gitignored: cada entorno (WAMP local / cPanel) crea el suyo.
//
// DB por defecto: mess_sivac (minúsculas — Linux/cPanel es case-sensitive).
// Las consultas cross-DB referencian mess_rrhh.* con prefijo explícito, por lo
// que el usuario MySQL necesita acceso a mess_sivac Y a mess_rrhh (el usuario
// mess_incidencias del resto de los sistemas ya tiene esos grants).
// ============================================================================
$conn = new mysqli("localhost", "usuario_mysql", "password", "mess_sivac");
if ($conn->connect_error) {
    die("Error en la conexión: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');
