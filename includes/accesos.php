<?php
/**
 * accesos.php — Entrada del candidato a su portal (Fase B).
 *
 * Única autenticación de SIVAC fuera de la cookie de loginMaster, y son DOS
 * factores separados a propósito:
 *
 *   1. El ENLACE  → token de 64 hex. Se resuelve por su hash SHA-256; el claro se
 *      guarda ADEMÁS en la misma fila para poder repetirle a RRHH el enlace que ya
 *      compartió (decisión de la retro 4; ver el comentario de la tabla en
 *      database.sql). Regenerar un enlace revoca el anterior, así que "repetir" y
 *      "generar nuevo" son dos operaciones distintas: la primera no toca nada, la
 *      segunda deja al candidato con el enlace viejo inservible.
 *   2. La CONTRASEÑA → 8 caracteres que genera el sistema y dicta RRHH. De ésta
 *      SÓLO se guarda el hash (password_hash), nunca el claro: quien lea la base
 *      ve el enlace pero no puede entrar, y si el candidato la pierde se
 *      RESTABLECE —enlace y avance intactos—, no se recupera.
 *
 * Por qué las dos: el enlace viaja por WhatsApp, se reenvía, queda en el historial
 * del navegador y en los logs del servidor. Detrás de él está la CURP, el RFC y el
 * NSS del candidato. El enlace solo dejó de ser suficiente.
 *
 * Acertar la contraseña abre una SESIÓN de navegador (cookie propia, ver
 * sivacPortalSesion): el candidato no la teclea en cada subida. La sesión sólo
 * dice "ya me identifiqué aquí" — el expediente vive en la base, así que cerrarla
 * o invalidarla NO pierde documentos ni datos ya guardados.
 */

// Vigencias que RRHH puede elegir al generar un enlace. Lista blanca porque el
// valor llega del cliente: cualquier otro número cae en la de siempre.
define('SIVAC_PORTAL_VIGENCIAS',     [7, 15, 30]);
define('SIVAC_PORTAL_VIGENCIA_DEF',  15);
// Inactividad que tumba la sesión del portal. 2 h: el candidato llena su
// expediente de una sentada, y el enlace se abre en celulares prestados.
define('SIVAC_PORTAL_INACTIVIDAD',   2 * 3600);
define('SIVAC_PORTAL_MAX_INTENTOS',  5);
define('SIVAC_PORTAL_BLOQUEO_MIN',   15);
// Sin letras ni dígitos que se confundan al dictar por teléfono (nada de O/0,
// I/1/L, S/5). 31 caracteres ^ 8 posiciones ≈ 8.5e11 combinaciones, con 5
// intentos por cuarto de hora.
define('SIVAC_PORTAL_ABC',           'ABCDEFGHJKMNPQRTUVWXYZ23456789');
define('SIVAC_PORTAL_PASS_LARGO',    8);

if (!function_exists('sivacGenerarAcceso')) {

    /**
     * Genera un acceso nuevo (enlace + contraseña) para un candidato e invalida
     * los anteriores activos. Devuelve ['token','pass','expira'] con los dos
     * secretos EN CLARO: es la única vez que la contraseña se puede leer.
     */
    function sivacGenerarAcceso(mysqli $conn, int $idCandidato, int $creadoPor, int $diasValidez = SIVAC_PORTAL_VIGENCIA_DEF): array {
        // Revoca los enlaces previos del candidato (regenerar = invalidar el anterior).
        // El claro se borra al revocar: en la BD sólo vive el del enlace que hoy sirve.
        $upd = $conn->prepare("UPDATE candidato_accesos SET activo = 0, token = NULL WHERE id_candidato = ? AND activo = 1");
        $upd->bind_param('i', $idCandidato);
        $upd->execute();
        $upd->close();

        if (!in_array($diasValidez, SIVAC_PORTAL_VIGENCIAS, true)) $diasValidez = SIVAC_PORTAL_VIGENCIA_DEF;
        $token  = bin2hex(random_bytes(32));   // 64 hex
        $hash   = hash('sha256', $token);
        $pass   = sivacPortalPassNueva();
        $ph     = password_hash($pass, PASSWORD_DEFAULT);
        $expira = date('Y-m-d H:i:s', strtotime('+' . $diasValidez . ' days'));

        $stmt = $conn->prepare(
            "INSERT INTO candidato_accesos (id_candidato, token_hash, token, pass_hash, fecha_expira, creado_por)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issssi', $idCandidato, $hash, $token, $ph, $expira, $creadoPor);
        $stmt->execute();
        $stmt->close();
        return ['token' => $token, 'pass' => $pass, 'expira' => $expira];
    }

    /**
     * Acceso vigente de un candidato (activo y sin vencer) o null. Sirve para
     * REPETIR el enlace en vez de generar uno nuevo.
     *
     * `token` puede venir NULL en los accesos creados antes de que se guardara el
     * claro: en ese caso hay acceso vigente pero no se puede volver a mostrar, y
     * quien llama debe decirlo en vez de fingir que no existe. `pass_hash` viene
     * NULL en los enlaces anteriores a la contraseña: siguen abriendo sin ella
     * (no se deja tirado a nadie a media documentación) y RRHH puede ponérsela.
     */
    function sivacAccesoVigente(mysqli $conn, int $idCandidato): ?array {
        $stmt = $conn->prepare(
            "SELECT id, token, pass_hash, fecha_expira FROM candidato_accesos
             WHERE id_candidato = ? AND activo = 1 AND fecha_expira > NOW()
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Resuelve un token EN CLARO a su acceso vigente (activo y no vencido) o null.
     * Registra el uso (usos++, ultimo_uso). La búsqueda es por hash sobre un índice
     * único: el valor guardado es el hash, no el secreto, así que no hay fuga por
     * comparación. Un token válido son exactamente 64 hex; se rechaza antes de tocar la BD.
     *
     * OJO: resolver el token NO es entrar. Si el acceso tiene contraseña, quien
     * llama tiene que exigir además sesión válida (sivacPortalSesionValida).
     */
    function sivacResolverAcceso(mysqli $conn, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $hash = hash('sha256', $token);
        // `vigente` lo decide MySQL y no PHP: `fecha_expira` la escribió MySQL, y
        // los dos relojes NO tienen por qué coincidir (en el WAMP local MySQL va
        // en hora local y PHP en UTC, seis horas de diferencia). Comparando en
        // PHP el enlace caducaba seis horas antes de tiempo. Ver el mismo motivo
        // en sivacPortalBloqueoRestante().
        $stmt = $conn->prepare(
            "SELECT id, id_candidato, fecha_expira, activo, pass_hash, intentos, bloqueado_hasta,
                    (fecha_expira > NOW()) AS vigente
             FROM candidato_accesos WHERE token_hash = ? LIMIT 1"
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        if ((int)$row['activo'] !== 1) return null;
        if ((int)$row['vigente'] !== 1) return null;

        $upd = $conn->prepare("UPDATE candidato_accesos SET usos = usos + 1, ultimo_uso = NOW() WHERE id = ?");
        $upd->bind_param('i', $row['id']);
        $upd->execute();
        $upd->close();
        return $row;
    }

    /** Invalida todos los accesos activos de un candidato (p. ej. al completar el alta). */
    function sivacRevocarAccesos(mysqli $conn, int $idCandidato): void {
        $upd = $conn->prepare("UPDATE candidato_accesos SET activo = 0, token = NULL WHERE id_candidato = ? AND activo = 1");
        $upd->bind_param('i', $idCandidato);
        $upd->execute();
        $upd->close();
    }

    /** ¿La petición viene por HTTPS? (para la URL del portal y la cookie segura) */
    function sivacEsHttps(): bool {
        return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? '') == 443)
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /** Directorio web de SIVAC a partir del script actual (…/SIVAC/x.php → /SIVAC). */
    function sivacDirWeb(): string {
        return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    }

    /** URL absoluta del portal a partir del token en claro (para copiar/compartir). */
    function sivacUrlPortal(string $token): string {
        $scheme = sivacEsHttps() ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host . sivacDirWeb() . '/portal.php?t=' . $token;
    }

    // ── Contraseña del portal ────────────────────────────────────────────────

    /** Contraseña nueva: 8 caracteres del alfabeto sin ambigüedades. */
    function sivacPortalPassNueva(): string {
        $n = strlen(SIVAC_PORTAL_ABC);
        $out = '';
        for ($i = 0; $i < SIVAC_PORTAL_PASS_LARGO; $i++) $out .= SIVAC_PORTAL_ABC[random_int(0, $n - 1)];
        return $out;
    }

    /**
     * Cómo se le muestra a RRHH para dictarla: en dos bloques de cuatro
     * (K7RM-4XQP). El guion es sólo de adorno — al entrar se normaliza.
     */
    function sivacPortalPassBonita(string $pass): string {
        return strlen($pass) === 8 ? substr($pass, 0, 4) . '-' . substr($pass, 4) : $pass;
    }

    /**
     * Normaliza lo que teclea el candidato: mayúsculas y sin nada que no sea
     * letra o dígito. Así entra igual si copia el guion, deja un espacio o
     * escribe en minúsculas — que es la mitad de los "no me funciona la clave".
     */
    function sivacPortalNormalizaPass(string $p): string {
        return strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $p));
    }

    /** ¿Este acceso exige contraseña? (los de antes de la contraseña, no). */
    function sivacPortalRequiereClave(array $acceso): bool {
        return trim((string)($acceso['pass_hash'] ?? '')) !== '';
    }

    /**
     * Pone una contraseña nueva a un acceso EXISTENTE, sin tocar el enlace ni el
     * avance del candidato. Es lo que hace «Restablecer contraseña» y también lo
     * que le pone clave a un enlace viejo que no la tenía. Devuelve el claro (la
     * única vez que se puede leer). Las sesiones abiertas con la clave anterior
     * dejan de valer, porque la huella de la sesión se saca del hash.
     */
    function sivacPortalAsignarPass(mysqli $conn, int $idAcceso): string {
        $pass = sivacPortalPassNueva();
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conn->prepare(
            "UPDATE candidato_accesos SET pass_hash = ?, intentos = 0, bloqueado_hasta = NULL WHERE id = ?"
        );
        $stmt->bind_param('si', $hash, $idAcceso);
        $stmt->execute();
        $stmt->close();
        return $pass;
    }

    /**
     * Segundos que faltan para que se levante el bloqueo por intentos (0 si no hay).
     *
     * La cuenta la hace MySQL, no PHP. `bloqueado_hasta` se escribe con
     * `DATE_ADD(NOW(), …)` —reloj de MySQL— y compararlo con `time()` de PHP da
     * basura en cuanto los dos relojes no coinciden: en el WAMP local MySQL va en
     * hora local y PHP en UTC, seis horas de diferencia, y el resultado salía
     * SIEMPRE negativo. Es decir, el bloqueo de 5 intentos no bloqueaba nada.
     * Se relee de la fila (y no del arreglo que traiga el llamador) para que no
     * dependa de qué SELECT lo haya construido: aquí fallar significa dejar
     * pasar a quien está tanteando la contraseña.
     */
    function sivacPortalBloqueoRestante(mysqli $conn, int $idAcceso): int {
        $stmt = $conn->prepare(
            "SELECT GREATEST(0, COALESCE(TIMESTAMPDIFF(SECOND, NOW(), bloqueado_hasta), 0)) AS seg
             FROM candidato_accesos WHERE id = ? LIMIT 1"
        );
        if (!$stmt) return 0;
        $stmt->bind_param('i', $idAcceso);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['seg'] ?? 0);
    }

    /**
     * Verifica la contraseña y, si es correcta, abre la sesión del portal.
     * Devuelve ['ok' => bool, 'mensaje' => string, 'espera' => segundos].
     *
     * Cuenta los intentos fallidos EN LA FILA del acceso (no en la sesión ni por
     * IP): el que está tanteando controla su navegador y su IP, no la fila. A los
     * 5 seguidos se bloquea el acceso un cuarto de hora; acertar los borra.
     */
    function sivacPortalEntrar(mysqli $conn, array $acceso, string $pass): array {
        $espera = sivacPortalBloqueoRestante($conn, (int)$acceso['id']);
        if ($espera > 0) {
            return ['ok' => false, 'espera' => $espera, 'mensaje' =>
                'Demasiados intentos fallidos. Vuelve a intentarlo en ' . max(1, (int)ceil($espera / 60)) . ' minuto(s).'];
        }
        if (!sivacPortalRequiereClave($acceso)) {   // enlace viejo sin contraseña
            sivacPortalAbrirSesion($acceso);
            return ['ok' => true, 'espera' => 0, 'mensaje' => 'Listo.'];
        }

        $limpia = sivacPortalNormalizaPass($pass);
        if ($limpia !== '' && password_verify($limpia, (string)$acceso['pass_hash'])) {
            $upd = $conn->prepare("UPDATE candidato_accesos SET intentos = 0, bloqueado_hasta = NULL WHERE id = ?");
            $upd->bind_param('i', $acceso['id']);
            $upd->execute();
            $upd->close();
            sivacPortalAbrirSesion($acceso);
            return ['ok' => true, 'espera' => 0, 'mensaje' => 'Listo.'];
        }

        $intentos = (int)($acceso['intentos'] ?? 0) + 1;
        if ($intentos >= SIVAC_PORTAL_MAX_INTENTOS) {
            $upd = $conn->prepare(
                "UPDATE candidato_accesos SET intentos = 0, bloqueado_hasta = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?"
            );
            $min = SIVAC_PORTAL_BLOQUEO_MIN;
            $upd->bind_param('ii', $min, $acceso['id']);
            $upd->execute();
            $upd->close();
            return ['ok' => false, 'espera' => SIVAC_PORTAL_BLOQUEO_MIN * 60, 'mensaje' =>
                'Demasiados intentos fallidos. Vuelve a intentarlo en ' . SIVAC_PORTAL_BLOQUEO_MIN . ' minutos o pide ayuda a Recursos Humanos.'];
        }
        $upd = $conn->prepare("UPDATE candidato_accesos SET intentos = ? WHERE id = ?");
        $upd->bind_param('ii', $intentos, $acceso['id']);
        $upd->execute();
        $upd->close();
        $quedan = SIVAC_PORTAL_MAX_INTENTOS - $intentos;
        return ['ok' => false, 'espera' => 0, 'mensaje' =>
            'La contraseña no es correcta. Te quedan ' . $quedan . ' intento(s).'];
    }

    // ── Sesión del portal ────────────────────────────────────────────────────

    /**
     * Arranca la sesión del candidato. Cookie CON NOMBRE PROPIO y acotada al
     * directorio de SIVAC: en el mismo dominio vive loginMaster con su PHPSESSID
     * y dos aplicaciones compartiendo la cookie de sesión se pisan los datos.
     */
    function sivacPortalSesion(): void {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name('SIVACPORTAL');
        session_set_cookie_params([
            'lifetime' => 0,                       // muere al cerrar el navegador
            'path'     => sivacDirWeb() . '/',
            'secure'   => sivacEsHttps(),
            'httponly' => true,                    // el JS no la puede leer
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * Huella de la contraseña con la que se abrió la sesión. Al restablecerla el
     * hash cambia (bcrypt lleva sal, cambia hasta con la misma clave) y la huella
     * deja de cuadrar: las sesiones abiertas se caen solas, sin ir a buscarlas.
     */
    function sivacPortalHuella(array $acceso): string {
        return substr(hash('sha256', (string)($acceso['pass_hash'] ?? '')), 0, 32);
    }

    /** Marca este navegador como identificado para ESTE acceso. */
    function sivacPortalAbrirSesion(array $acceso): void {
        sivacPortalSesion();
        session_regenerate_id(true);   // id nuevo al entrar (contra fijación de sesión)
        $_SESSION['portal'] = [
            'acceso' => (int)$acceso['id'],
            'huella' => sivacPortalHuella($acceso),
            'visto'  => time(),
        ];
    }

    /**
     * ¿Este navegador ya escribió la contraseña de ESTE acceso y sigue vigente?
     * Refresca la marca de actividad, así que la inactividad cuenta desde la
     * última petición y no desde que entró.
     */
    function sivacPortalSesionValida(array $acceso): bool {
        sivacPortalSesion();
        $s = $_SESSION['portal'] ?? null;
        if (!is_array($s)) return false;
        if ((int)($s['acceso'] ?? 0) !== (int)$acceso['id']) return false;          // sesión de otro enlace
        if (!hash_equals(sivacPortalHuella($acceso), (string)($s['huella'] ?? ''))) return false;  // le restablecieron la clave
        if (time() - (int)($s['visto'] ?? 0) > SIVAC_PORTAL_INACTIVIDAD) return false;
        $_SESSION['portal']['visto'] = time();
        return true;
    }

    /** Cierra la sesión del portal en este navegador (el enlace sigue sirviendo). */
    function sivacPortalCerrarSesion(): void {
        sivacPortalSesion();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }
}
