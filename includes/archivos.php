<?php
/**
 * archivos.php — Validación y almacenamiento seguro de archivos subidos.
 *
 * Estrategia (regla de oro #4): validar por FIRMA DE BYTES, no solo por
 * extensión o por fileinfo; detectar desbordamiento de post_max_size con un
 * mensaje claro; nombre en disco aleatorio; los archivos viven bajo uploads/
 * (bloqueado por .htaccess) y solo se sirven vía descargar.php con permiso.
 */

const SIVAC_DIR_CV  = __DIR__ . '/../uploads/cv/';
const SIVAC_DIR_DOC = __DIR__ . '/../uploads/documentos/';

const SIVAC_MAX_CV  = 5  * 1024 * 1024;   // 5 MB
const SIVAC_MAX_DOC = 10 * 1024 * 1024;   // 10 MB

// Firmas (magic numbers) de los tipos aceptados.
const SIVAC_FIRMAS = [
    'pdf'  => ["\x25\x50\x44\x46\x2D"],                 // %PDF-
    'jpg'  => ["\xFF\xD8\xFF"],                          // JPEG
    'jpeg' => ["\xFF\xD8\xFF"],
    'png'  => ["\x89\x50\x4E\x47\x0D\x0A\x1A\x0A"],      // PNG
];

const SIVAC_MIME_POR_EXT = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];

if (!function_exists('sivacPostDesbordado')) {

    /**
     * Detecta un POST que excedió post_max_size: PHP descarta $_POST y $_FILES
     * pero CONTENT_LENGTH llega con el tamaño enviado. Sin esto el endpoint
     * vería "acción vacía" y respondería un error confuso.
     */
    function sivacPostDesbordado(): bool {
        return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
            && empty($_POST)
            && empty($_FILES)
            && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
    }

    /** Mensaje en español para un código UPLOAD_ERR_*. */
    function sivacErrorSubida(int $code): string {
        switch ($code) {
            case UPLOAD_ERR_OK:         return '';
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:  return 'El archivo excede el tamaño máximo permitido.';
            case UPLOAD_ERR_PARTIAL:    return 'La subida se interrumpió; inténtalo de nuevo.';
            case UPLOAD_ERR_NO_FILE:    return 'No se recibió ningún archivo.';
            case UPLOAD_ERR_NO_TMP_DIR: return 'Falta la carpeta temporal en el servidor.';
            case UPLOAD_ERR_CANT_WRITE: return 'No se pudo escribir el archivo en el servidor.';
            case UPLOAD_ERR_EXTENSION:  return 'Una extensión de PHP bloqueó la subida.';
            default:                    return 'Error desconocido al subir el archivo.';
        }
    }

    /** Extensión normalizada (minúsculas) de un nombre de archivo. */
    function sivacExtension(string $nombre): string {
        return strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    }

    /** ¿Los primeros bytes coinciden con alguna firma de la extensión dada? */
    function sivacFirmaValida(string $rutaTmp, string $ext): bool {
        $firmas = SIVAC_FIRMAS[$ext] ?? null;
        if (!$firmas) return false;
        $fh = @fopen($rutaTmp, 'rb');
        if (!$fh) return false;
        $cabecera = fread($fh, 16);
        fclose($fh);
        foreach ($firmas as $firma) {
            if (strncmp($cabecera, $firma, strlen($firma)) === 0) return true;
        }
        return false;
    }

    /**
     * Valida y guarda un archivo subido.
     *
     * @param array  $file        Entrada de $_FILES.
     * @param array  $extensiones Extensiones permitidas (p. ej. ['pdf']).
     * @param int    $maxBytes    Límite de tamaño.
     * @param string $destinoDir  SIVAC_DIR_CV o SIVAC_DIR_DOC.
     * @return array{ok:bool, message:string, nombre?:string, original?:string, mime?:string, tamano?:int}
     */
    function sivacGuardarArchivo(array $file, array $extensiones, int $maxBytes, string $destinoDir): array {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['ok' => false, 'message' => 'Parámetro de archivo inválido.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => sivacErrorSubida((int)$file['error'])];
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            return ['ok' => false, 'message' => 'El archivo no proviene de una subida válida.'];
        }
        if ($file['size'] <= 0) {
            return ['ok' => false, 'message' => 'El archivo está vacío.'];
        }
        if ($file['size'] > $maxBytes) {
            return ['ok' => false, 'message' => 'El archivo supera el límite de ' . round($maxBytes / 1048576) . ' MB.'];
        }

        $ext = sivacExtension($file['name']);
        if (!in_array($ext, $extensiones, true)) {
            return ['ok' => false, 'message' => 'Tipo de archivo no permitido. Se acepta: ' . implode(', ', $extensiones) . '.'];
        }
        if (!sivacFirmaValida($file['tmp_name'], $ext)) {
            return ['ok' => false, 'message' => 'El contenido del archivo no coincide con su extensión (posible archivo alterado).'];
        }

        // Refuerzo con fileinfo si está disponible (no se depende de él).
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeReal = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            $esperado = SIVAC_MIME_POR_EXT[$ext] ?? null;
            if ($esperado && $mimeReal && $mimeReal !== $esperado
                && !($esperado === 'image/jpeg' && $mimeReal === 'image/jpg')) {
                return ['ok' => false, 'message' => 'El tipo real del archivo no es válido.'];
            }
        }

        if (!is_dir($destinoDir) && !@mkdir($destinoDir, 0755, true)) {
            return ['ok' => false, 'message' => 'No se pudo preparar la carpeta de destino.'];
        }

        $nombre = bin2hex(random_bytes(16)) . '.' . $ext;
        $destino = rtrim($destinoDir, '/\\') . DIRECTORY_SEPARATOR . $nombre;
        if (!move_uploaded_file($file['tmp_name'], $destino)) {
            return ['ok' => false, 'message' => 'No se pudo guardar el archivo.'];
        }

        return [
            'ok'       => true,
            'message'  => '',
            'nombre'   => $nombre,
            'original' => mb_substr($file['name'], 0, 255),
            'mime'     => SIVAC_MIME_POR_EXT[$ext] ?? 'application/octet-stream',
            'tamano'   => (int)$file['size'],
        ];
    }
}
