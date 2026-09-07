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

    // PRUEBAS: con un correo aquí, TODO lo que manda NEST llega a esa dirección
    // en vez de a los destinatarios reales (candidatos, jefes y las cinco áreas
    // del alta). El asunto se marca con [PRUEBAS] y el cuerpo dice a quién iba.
    // Sirve para recorrer el proceso completo sin escribirle a nadie de verdad.
    //
    // ⚠️ EN PRODUCCIÓN VA VACÍO O SE OMITE. Si se queda puesto, los candidatos y
    // las áreas dejan de recibir sus correos y NADIE se entera: en la bitácora de
    // `notificaciones` aparecen como enviados, porque enviarse sí se enviaron —
    // a la dirección de pruebas. Este archivo no viaja por git justamente para
    // que la de local no pueda pisar la del servidor.
    'redirigir_a' => '',

    'host'        => 'smtp.gmail.com',
    'port'        => 465,
    'secure'      => 'ssl',
    'usuario'     => 'cuenta@gmail.com',
    'password'    => 'app-password-de-16-letras',
    'from_correo' => 'cuenta@gmail.com',
    'from_nombre' => 'NEST — Vacantes y Contratación',
];
