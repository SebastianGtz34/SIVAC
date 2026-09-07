<?php
/**
 * correo.php — Envío de correo por SMTP (PHPMailer local).
 *
 * Patrón del ecosistema (ControlVehicular): PHPMailer local, Gmail SSL:465.
 * Las credenciales viven en config_correo.php (gitignored), NUNCA en código
 * trackeado. Un fallo de SMTP jamás debe abortar la transacción de negocio:
 * el llamador registra el error en la bitácora de notificaciones.
 */

require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';
require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';

if (!function_exists('sivacConfigCorreo')) {

    /** Carga (y cachea) la configuración SMTP, o null si falta el archivo. */
    function sivacConfigCorreo(): ?array {
        static $cfg = false;
        if ($cfg !== false) return $cfg;
        $ruta = __DIR__ . '/../config_correo.php';
        $cfg = is_file($ruta) ? (require $ruta) : null;
        return $cfg;
    }

    // Contacto de RRHH que va en el pie de TODOS los correos. A diferencia del
    // resto del portal, aquí escribe gente de FUERA (los candidatos): decirles
    // "no responda a este correo" y nada más los deja sin a quién preguntarle.
    // define() y no const: esto vive dentro de un if (!function_exists(...)).
    define('SIVAC_RRHH_CORREOS',  ['fernanda.hernandez@mess.com.mx', 'rh.aux@mess.com.mx']);
    define('SIVAC_RRHH_TELEFONO', '442 290 8635 ext. 805');
    define('SIVAC_RRHH_CELULAR',  '442 105 8778');

    /**
     * Envuelve un contenido en la plantilla HTML institucional de SIVAC.
     * El contenido ya debe venir escapado por el llamador donde corresponda.
     *
     * Logo: el del ecosistema (messbook.com.mx), el mismo que usan los correos de
     * entradas de equipo y logística. Va sobre fondo BLANCO porque es un JPEG sin
     * transparencia; por eso el título tiene su propia banda azul debajo. Los
     * clientes de correo bloquean imágenes remotas por defecto, así que el
     * mensaje tiene que leerse completo sin verlo (de ahí el alt y el título).
     */
    function sivacPlantillaCorreo(string $titulo, string $cuerpoHtml): string {
        // Dos logos separados, no el lockup: NEST encabeza el correo (es quien
        // manda el mensaje) y Grupo MESS firma al pie (es la empresa). Van por URL
        // ABSOLUTA porque un correo se lee fuera del servidor; la ruta es la del
        // despliegue (la carpeta sigue llamándose SIVAC aunque el sistema ya sea
        // NEST). Los dos son PNG de tinta azul/gris sobre fondo claro, así que
        // ambas bandas donde viven son blancas.
        $logoNest = 'https://messbook.com.mx/SIVAC/img/NEST/nest-logo.png';
        $logoMess = 'https://messbook.com.mx/SIVAC/img/logo_mess.png';

        $tel  = htmlspecialchars(SIVAC_RRHH_TELEFONO);
        $cel  = htmlspecialchars(SIVAC_RRHH_CELULAR);
        $mails = [];
        foreach (SIVAC_RRHH_CORREOS as $m) {
            $mails[] = '<a href="mailto:' . htmlspecialchars($m) . '" style="color:#074480;">'
                . htmlspecialchars($m) . '</a>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;background:#f2f4f8;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
            . '<div style="max-width:600px;margin:24px auto;background:#ffffff;border-radius:8px;overflow:hidden;'
            . 'box-shadow:0 2px 8px rgba(0,0,0,.08);">'
            // NEST arriba, en banda blanca y compacta, justo encima del título: el
            // alt lleva el nombre completo porque la mayoría de los clientes de
            // correo bloquea las imágenes por defecto y el encabezado tiene que
            // leerse igual sin ellas.
            . '<div style="padding:18px 24px 12px;text-align:center;">'
            // El atributo width= NO es decorativo ni redundante: buena parte de los
            // clientes de correo ignora el CSS inline de las imágenes, y sin él la
            // pintan a su tamaño natural (650 px, el ancho entero del correo). El
            // style se queda para los que sí lo respetan y para las pantallas HiDPI.
            . '<img src="' . $logoNest . '" alt="NEST — Núcleo de Evaluación y Selección de Talento"'
            . ' width="245" style="width:245px;max-width:245px;height:auto;display:block;margin:0 auto;border:0;">'
            . '</div>'
            . '<div style="background:#074480;padding:14px 24px;text-align:center;color:#ffffff;'
            . 'font-size:17px;font-weight:bold;">' . htmlspecialchars($titulo) . '</div>'
            . '<div style="padding:28px 32px;font-size:15px;line-height:1.6;">' . $cuerpoHtml . '</div>'
            . '<div style="padding:20px 32px;background:#f8f9fc;border-top:1px solid #e5e7eb;'
            . 'font-size:12px;color:#6c757d;text-align:center;line-height:1.7;">'
            // Grupo MESS firma al pie: la empresa detrás del sistema. Con width=
            // por lo mismo que el de arriba.
            //
            // El logo va dentro de su PROPIO div y no suelto con display:block:
            // Outlook (y algún otro) ignora el display de las imágenes, la deja en
            // línea y el texto del pie se le encimaba. El div sí lo respetan todos,
            // y su padding es lo que garantiza la separación.
            . '<div style="padding:0 0 14px;">'
            . '<img src="' . $logoMess . '" alt="Grupo MESS"'
            . ' width="187" style="width:187px;max-width:187px;height:auto;display:block;margin:0 auto;border:0;">'
            . '</div>'
            . '<strong style="color:#4b5563;">NEST — Núcleo de Evaluación y Selección de Talento</strong><br>'
            . '¿Dudas? Escríbele a Recursos Humanos: ' . implode(' · ', $mails) . '<br>'
            . 'Tel. ' . $tel . ' · Cel. ' . $cel . '<br>'
            . '<span style="color:#9ca3af;">Este buzón es automático: no respondas a esta dirección.</span>'
            . '</div></div></body></html>';
    }

    /**
     * Envía un correo HTML. Devuelve ['ok'=>bool, 'error'=>?string, 'para'=>string].
     * No lanza excepciones: cualquier fallo se reporta en el arreglo.
     *
     * @param string[] $para Lista de destinatarios (correos).
     * @param string[] $cc   Lista opcional de copias.
     */
    function enviarCorreoSivac(array $para, string $asunto, string $tituloPlantilla, string $cuerpoHtml, array $cc = []): array {
        $cfg = sivacConfigCorreo();
        $destinos = array_values(array_filter(array_map('trim', $para), function ($c) {
            return filter_var($c, FILTER_VALIDATE_EMAIL);
        }));
        $copias = array_values(array_filter(array_map('trim', $cc), function ($c) {
            return filter_var($c, FILTER_VALIDATE_EMAIL);
        }));
        $listaStr = implode(', ', array_merge($destinos, $copias));

        if (!$cfg) {
            return ['ok' => false, 'error' => 'Falta config_correo.php en el servidor.', 'para' => $listaStr];
        }
        // Interruptor de entorno: en local/staging se deja 'activo' => false para
        // no enviar correos reales; en producción (cPanel) se pone en true.
        if (array_key_exists('activo', $cfg) && !$cfg['activo']) {
            return ['ok' => false, 'error' => 'Envío de correo deshabilitado (config_correo.activo=false).', 'para' => $listaStr];
        }
        if (!$destinos) {
            return ['ok' => false, 'error' => 'Sin destinatarios válidos.', 'para' => $listaStr];
        }

        // ── Redirección de PRUEBAS ────────────────────────────────────────────
        // Con 'redirigir_a' puesto en config_correo.php, TODO el correo va a esa
        // dirección en vez de a los destinatarios reales. Es lo que permite recorrer
        // el proceso completo —propuesta, reglamento, las cinco notificaciones de
        // alta— sin escribirle a un candidato ni a las áreas.
        //
        // Vive en config_correo.php a propósito, que es gitignored y se crea a mano
        // en cada entorno: así NO hay forma de que un commit lo active en producción.
        // La comprobación de destinatarios reales queda ARRIBA de esto, para que un
        // correo sin destinatario siga fallando igual que sin redirección y las
        // pruebas no escondan ese error.
        $redirigir = trim((string)($cfg['redirigir_a'] ?? ''));
        $pruebas   = $redirigir !== '' && filter_var($redirigir, FILTER_VALIDATE_EMAIL);
        if ($pruebas) {
            // El banner va al principio del cuerpo y el asunto lleva [PRUEBAS]: al
            // recibirlos todos en un mismo buzón, lo primero que se necesita saber
            // es a quién le habría llegado de verdad.
            $cuerpoHtml = '<div style="margin:0 0 18px;padding:10px 12px;border-radius:4px;'
                . 'background:#fef3c7;color:#92400e;font-size:13px;line-height:1.5;">'
                . '<strong>⚠️ MODO PRUEBAS.</strong> Este correo NO se envió a sus destinatarios.<br>'
                . 'Iba para: <strong>' . htmlspecialchars($listaStr) . '</strong>'
                . '</div>' . $cuerpoHtml;
            $asunto   = '[PRUEBAS] ' . $asunto;
            // La bitácora guarda a dónde llegó Y a quién iba: si sólo guardara el
            // destinatario real, parecería que el correo sí salió a las áreas.
            $listaStr = $redirigir . ' [PRUEBAS; iba para: ' . $listaStr . ']';
            $destinos = [$redirigir];
            $copias   = [];
        }

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->SMTPDebug  = 0;
            $mail->SMTPAuth   = true;
            $mail->SMTPSecure = $cfg['secure'] ?? 'ssl';
            $mail->Host       = $cfg['host'];
            $mail->Port       = (int)$cfg['port'];
            $mail->CharSet    = 'UTF-8';
            $mail->Username   = $cfg['usuario'];
            $mail->Password   = $cfg['password'];
            $mail->setFrom($cfg['from_correo'] ?? $cfg['usuario'], $cfg['from_nombre'] ?? 'NEST');
            $mail->isHTML(true);
            $mail->Subject = $asunto;
            $mail->Body    = sivacPlantillaCorreo($tituloPlantilla, $cuerpoHtml);
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $cuerpoHtml)));

            foreach ($destinos as $c) $mail->addAddress($c);
            foreach ($copias as $c)  $mail->addCC($c);

            $mail->send();
            return ['ok' => true, 'error' => null, 'para' => $listaStr];
        } catch (\Throwable $e) {
            error_log('SIVAC correo: ' . $e->getMessage());
            return ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 250), 'para' => $listaStr];
        }
    }
}
