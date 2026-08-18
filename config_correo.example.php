<?php
// ============================================================================
// PLANTILLA de configuración SMTP — copiar como config_correo.php (gitignored)
// y poner las credenciales reales. NUNCA commitear el app password.
// Patrón del ecosistema: cuenta Gmail con app password, SSL puerto 465
// (mismo esquema que ControlVehicular/includes/enviar_notificacion.php).
// ============================================================================
return [
    // Interruptor de envío de correo. Con 'activo' => false NO se envía nada
    // (útil en local/staging); todo queda registrado en la tabla notificaciones.
    // En producción ponerlo en true. Si se omite la clave, el envío está activo.
    'activo'      => true,
    'host'        => 'smtp.gmail.com',
    'port'        => 465,
    'secure'      => 'ssl',
    'usuario'     => 'cuenta@gmail.com',
    'password'    => 'app-password-de-16-letras',
    'from_correo' => 'cuenta@gmail.com',
    'from_nombre' => 'NEST — Vacantes y Contratación',
];
