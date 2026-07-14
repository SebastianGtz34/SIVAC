<?php
// ============================================================================
// PLANTILLA de configuración SMTP — copiar como config_correo.php (gitignored)
// y poner las credenciales reales. NUNCA commitear el app password.
// Patrón del ecosistema: cuenta Gmail con app password, SSL puerto 465
// (mismo esquema que ControlVehicular/includes/enviar_notificacion.php).
// ============================================================================
return [
    'host'        => 'smtp.gmail.com',
    'port'        => 465,
    'secure'      => 'ssl',
    'usuario'     => 'cuenta@gmail.com',
    'password'    => 'app-password-de-16-letras',
    'from_correo' => 'cuenta@gmail.com',
    'from_nombre' => 'SIVAC — Vacantes y Contratación',
];
