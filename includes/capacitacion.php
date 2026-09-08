<?php
/**
 * capacitacion.php — Alta de la cuenta del colaborador en la plataforma de
 * capacitación (WordPress + BuddyBoss + Masteriyo, en messbook.com.mx/capacitacion).
 *
 * POR QUÉ ESCRIBE DIRECTO EN LAS TABLAS: la plataforma no expone una API para
 * esto y vive en el mismo servidor, así que el alta se hace con INSERT cross-DB,
 * igual que gestionPersonal ya lee sus cursos. Lo que se escribe está copiado de
 * un usuario creado por el proceso normal (el 11602), no inventado.
 *
 * ⚠️ ESTO NO ES UNA CUENTA DE WORDPRESS "COMPLETA": es el mínimo con el que la
 * plataforma deja entrar y tomar cursos. Hacen falta las TRES partes —usuario,
 * meta y perfil—; con sólo la fila de wp_users el colaborador entra pero no ve
 * ningún curso, porque el rol vive en la meta.
 *
 * LA CONTRASEÑA VA EN MD5 A PROPÓSITO. Los usuarios actuales tienen el hash
 * moderno de WordPress 6.8 (prefijo `$wp$`: bcrypt sobre un pre-hash HMAC-SHA384),
 * que no se puede reproducir desde fuera sin copiar código interno del core que
 * cambia entre versiones. WordPress conserva una rama de compatibilidad: si el
 * hash guardado mide 32 caracteres lo compara como MD5 y, al primer login
 * correcto, lo re-guarda ya en su formato nuevo. Es decir, el MD5 sólo vive hasta
 * que la persona entra por primera vez.
 *
 * ⚠️ ESE FALLBACK NO ESTÁ VERIFICADO CONTRA LA VERSIÓN QUE CORRE EN PRODUCCIÓN.
 * Antes de usarlo con alguien real hay que crear una cuenta de prueba con esta
 * función e intentar entrar con ella. Si no deja entrar, el camino es la API REST
 * de WordPress (wp-json/wp/v2/users con Application Password), que hashea del
 * lado del core y es inmune a este problema.
 */

if (!function_exists('sivacCapacitacionCrearCuenta')) {

    // Contraseña inicial de toda cuenta nueva. La misma para todos a propósito:
    // RRHH se la dicta al colaborador y él la cambia al entrar.
    define('SIVAC_CAP_PASS_INICIAL', 'MessBook2026');

    // Rol con el que nacen las cuentas nuevas, copiado del último usuario creado
    // por el proceso normal. NO es 'usuario_mess' (ése lo traen cuentas viejas):
    // 'masteriyo_student' es lo que permite inscribirse a cursos y
    // 'bbp_participant' lo que deja participar en los foros.
    define('SIVAC_CAP_ROL', 'a:2:{s:17:"masteriyo_student";b:1;s:15:"bbp_participant";b:1;}');

    // Campos del perfil de BuddyBoss (wp_bp_xprofile_fields). Sin estos, la ficha
    // del colaborador sale vacía en el directorio de miembros.
    define('SIVAC_CAP_CAMPO_NOMBRE',   1);
    define('SIVAC_CAP_CAMPO_APELLIDOS', 3417);
    define('SIVAC_CAP_CAMPO_USUARIO',  3418);
    define('SIVAC_CAP_CAMPO_PUESTO',   3470);
    define('SIVAC_CAP_CAMPO_NAVE',     3419);

    /** Quita acentos y deja sólo lo que sirve en un user_login. */
    function capSlug(string $txt): string {
        $t = strtr($txt, [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
            'Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ü'=>'u','Ñ'=>'n',
        ]);
        $t = strtolower(preg_replace('/[^A-Za-z0-9]+/', '.', $t));
        return trim($t, '.');
    }

    /**
     * ¿Ya tiene cuenta? Se busca por correo, que es lo único que no se repite.
     * Devuelve el ID o null.
     */
    function sivacCapacitacionBuscar(mysqli $conn, string $correo): ?int {
        $stmt = $conn->prepare("SELECT ID FROM mess_capacitacion.wp_users WHERE user_email = ? LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('s', $correo);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['ID'] : null;
    }

    /** user_login libre a partir del nombre: 'ana.perez', 'ana.perez2', … */
    function capLoginLibre(mysqli $conn, string $base): string {
        $base = capSlug($base) ?: 'colaborador';
        $base = substr($base, 0, 50);
        $try = $base; $n = 1;
        while (true) {
            $stmt = $conn->prepare("SELECT 1 FROM mess_capacitacion.wp_users WHERE user_login = ? LIMIT 1");
            if (!$stmt) return $try;
            $stmt->bind_param('s', $try);
            $stmt->execute();
            $existe = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$existe) return $try;
            $try = $base . (++$n);
        }
    }

    /** Una fila de wp_usermeta. Se ignoran los fallos: son preferencias de UI. */
    function capMeta(mysqli $conn, int $userId, string $clave, string $valor): void {
        $stmt = $conn->prepare(
            "INSERT INTO mess_capacitacion.wp_usermeta (user_id, meta_key, meta_value) VALUES (?, ?, ?)"
        );
        if (!$stmt) return;
        $stmt->bind_param('iss', $userId, $clave, $valor);
        $stmt->execute();
        $stmt->close();
    }

    /** Un campo del perfil de BuddyBoss. */
    function capPerfil(mysqli $conn, int $userId, int $campo, string $valor): void {
        if (trim($valor) === '') return;
        $stmt = $conn->prepare(
            "INSERT INTO mess_capacitacion.wp_bp_xprofile_data (field_id, user_id, value, last_updated)
             VALUES (?, ?, ?, NOW())"
        );
        if (!$stmt) return;
        $stmt->bind_param('iis', $campo, $userId, $valor);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Crea la cuenta. Devuelve ['ok'=>bool, 'id'=>?int, 'usuario'=>?string,
     * 'mensaje'=>string, 'ya_existia'=>bool].
     *
     * No lanza excepciones: un fallo aquí NO debe tumbar el alta del colaborador,
     * que es la transacción de negocio. El llamador reporta el resultado.
     *
     * @param array $d nombre, apellidos, correo y —opcionales— puesto, nave, area.
     */
    function sivacCapacitacionCrearCuenta(mysqli $conn, array $d): array {
        $nombre    = trim((string)($d['nombre'] ?? ''));
        $apellidos = trim((string)($d['apellidos'] ?? ''));
        $correo    = trim((string)($d['correo'] ?? ''));
        $puesto    = trim((string)($d['puesto'] ?? ''));
        $nave      = trim((string)($d['nave'] ?? ''));
        $area      = trim((string)($d['area'] ?? ''));

        if ($nombre === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'id' => null, 'usuario' => null, 'ya_existia' => false,
                    'mensaje' => 'Faltan el nombre o un correo válido para crear la cuenta de capacitación.'];
        }

        // Idempotente: si ya tiene cuenta no se duplica. Dos cuentas con el mismo
        // correo dejan al colaborador sin saber con cuál entrar y parten su
        // historial de cursos en dos.
        $ya = sivacCapacitacionBuscar($conn, $correo);
        if ($ya) {
            return ['ok' => true, 'id' => $ya, 'usuario' => null, 'ya_existia' => true,
                    'mensaje' => 'Ya tenía cuenta en capacitación; no se creó otra.'];
        }

        $login   = capLoginLibre($conn, $nombre . ' ' . $apellidos);
        $display = trim($nombre . ' ' . $apellidos);
        $pass    = md5(SIVAC_CAP_PASS_INICIAL);   // ver el encabezado: es a propósito
        $passIni = SIVAC_CAP_PASS_INICIAL;

        $stmt = $conn->prepare(
            "INSERT INTO mess_capacitacion.wp_users
                (user_login, user_pass, user_nicename, user_email, user_url,
                 user_registered, user_activation_key, user_status, display_name,
                 empresa, puesto, pass_inicial)
             VALUES (?, ?, ?, ?, '', NOW(), '', 0, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return ['ok' => false, 'id' => null, 'usuario' => null, 'ya_existia' => false,
                    'mensaje' => 'No se pudo preparar el alta en capacitación: ' . $conn->error];
        }
        $nicename = substr(str_replace('.', '-', $login), 0, 50);
        $stmt->bind_param('ssssssss', $login, $pass, $nicename, $correo, $display, $area, $puesto, $passIni);
        $ok = $stmt->execute();
        $userId = $ok ? (int)$conn->insert_id : 0;
        $stmt->close();

        if (!$ok || !$userId) {
            return ['ok' => false, 'id' => null, 'usuario' => null, 'ya_existia' => false,
                    'mensaje' => 'No se pudo crear la cuenta de capacitación: ' . $conn->error];
        }

        // Meta mínima. El rol y el nivel son los que hacen usable la cuenta; el
        // resto son las preferencias que WordPress espera encontrar.
        $slug = substr(md5($login . $userId), 0, 8);
        foreach ([
            'nickname'      => $nombre,
            'first_name'    => $nombre,
            'last_name'     => $apellidos,
            'description'   => '',
            'rich_editing'  => 'true',
            'syntax_highlighting' => 'true',
            'comment_shortcuts'   => 'false',
            'admin_color'   => 'modern',
            'use_ssl'       => '0',
            'show_admin_bar_front' => 'true',
            'locale'        => '',
            'wp_capabilities' => SIVAC_CAP_ROL,
            'wp_user_level'   => '0',
            'bb_profile_slug' => $slug,
            'bb_profile_slug_' . $slug => (string)$userId,
            'bp_xprofile_visibility_levels' =>
                'a:5:{i:1;s:6:"public";i:3417;s:6:"public";i:3418;s:6:"public";i:3470;s:6:"public";i:3419;s:8:"loggedin";}',
        ] as $k => $v) {
            capMeta($conn, $userId, $k, (string)$v);
        }

        // Perfil de BuddyBoss.
        capPerfil($conn, $userId, SIVAC_CAP_CAMPO_NOMBRE,    $nombre);
        capPerfil($conn, $userId, SIVAC_CAP_CAMPO_APELLIDOS, $apellidos);
        capPerfil($conn, $userId, SIVAC_CAP_CAMPO_USUARIO,   $nombre);
        capPerfil($conn, $userId, SIVAC_CAP_CAMPO_PUESTO,    $puesto);
        capPerfil($conn, $userId, SIVAC_CAP_CAMPO_NAVE,      $nave);

        return ['ok' => true, 'id' => $userId, 'usuario' => $login, 'ya_existia' => false,
                'mensaje' => 'Cuenta de capacitación creada (usuario: ' . $login . ').'];
    }
}
