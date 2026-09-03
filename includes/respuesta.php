<?php
/**
 * respuesta.php — Respuesta JSON única de los endpoints de SIVAC.
 *
 * POR QUÉ EXISTE: los ocho acciones_*.php tenían cada uno su propia responder()
 * con un `echo json_encode(...)` a secas. json_encode devuelve FALSE cuando el
 * arreglo trae bytes que no son UTF-8 válido, y `echo false` imprime CADENA
 * VACÍA: el endpoint contesta 200 con el cuerpo vacío, el $.ajax con
 * dataType:'json' no puede parsearlo y cae en su callback de error. En pantalla
 * eso sale como un «No se pudo cargar.» que no dice nada — el mismo síntoma que
 * deja un esquema desfasado, y por eso cuesta tanto distinguir uno del otro.
 *
 * DE DÓNDE SALEN ESOS BYTES: de cualquier texto que escriba una persona y que
 * después vuelva de la BD —motivo de descarte, comentarios del historial,
 * observaciones del psicométrico—, sobre todo si se pegó desde Word/Excel o si
 * el conn.php de ese entorno no fijó utf8mb4 (se crea a mano en cada servidor y
 * no viaja por git). Un solo carácter roto en UNA fila del historial tumbaba la
 * ficha completa, y como el historial sólo crece al descartar, el fallo parecía
 * exclusivo de los candidatos descartados.
 *
 * QUÉ HACE AHORA: si el encode falla, reintenta sustituyendo los bytes inválidos.
 * Preferimos una ficha que abre con un carácter feo a una ficha que no abre: lo
 * que se degrada es un campo de texto, no la información que RRHH necesita ver.
 */

if (!function_exists('sivacEmitirJson')) {

    /**
     * Emite el payload como JSON y termina. NUNCA deja el cuerpo vacío: si algo
     * sale mal, contesta 500 con un mensaje que se pueda leer en pantalla, en vez
     * de un 200 mudo que el front traduce a «No se pudo cargar.».
     */
    function sivacEmitirJson(array $payload): void {
        $json = json_encode($payload);

        if ($json === false) {
            // Los bytes que no son UTF-8 válido se sustituyen en vez de tumbar la
            // respuesta entera. Se deja rastro en la bitácora: el dato de origen
            // sigue podrido en la BD y alguien tendrá que limpiarlo.
            error_log('SIVAC responder: json_encode falló (' . json_last_error_msg() . '); se reintenta sustituyendo UTF-8 inválido.');
            $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        }

        if ($json === false) {
            // Ya no es cosa del texto (referencias circulares, INF/NAN…). Un error
            // explícito le dice a quien lo vea qué preguntar; el 200 vacío no.
            error_log('SIVAC responder: json_encode falló definitivamente — ' . json_last_error_msg());
            http_response_code(500);
            $json = '{"success":false,"message":"El servidor no pudo preparar la respuesta (datos con codificación inválida)."}';
        }

        echo $json;
        exit;
    }
}

if (!function_exists('responder')) {

    /** Respuesta estándar de los endpoints: {success, message, …extra}. */
    function responder(bool $success, string $message = '', array $extra = []): void {
        sivacEmitirJson(array_merge(['success' => $success, 'message' => $message], $extra));
    }
}
