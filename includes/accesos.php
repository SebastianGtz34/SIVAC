<?php
/**
 * accesos.php — Tokens de acceso del portal del candidato (Fase B).
 *
 * Única autenticación de SIVAC fuera de la cookie de loginMaster. La entrada se
 * resuelve por el hash SHA-256 del token; el token en claro se guarda ADEMÁS en
 * la misma fila para poder repetirle a RRHH el enlace que ya compartió (ver el
 * comentario de la tabla en database.sql). Regenerar un enlace revoca el anterior,
 * así que "repetir" y "generar nuevo" son dos operaciones distintas: la primera
 * no toca nada, la segunda deja al candidato con el enlace viejo inservible.
 */

if (!function_exists('sivacGenerarAcceso')) {

    /**
     * Genera un acceso nuevo para un candidato e invalida los anteriores activos.
     * Devuelve el token EN CLARO (64 hex); NO se puede recuperar después.
     */
    function sivacGenerarAcceso(mysqli $conn, int $idCandidato, int $creadoPor, int $diasValidez = 15): string {
        // Revoca los enlaces previos del candidato (regenerar = invalidar el anterior).
        // El claro se borra al revocar: en la BD sólo vive el del enlace que hoy sirve.
        $upd = $conn->prepare("UPDATE candidato_accesos SET activo = 0, token = NULL WHERE id_candidato = ? AND activo = 1");
        $upd->bind_param('i', $idCandidato);
        $upd->execute();
        $upd->close();

        $token  = bin2hex(random_bytes(32));   // 64 hex
        $hash   = hash('sha256', $token);
        $expira = date('Y-m-d H:i:s', strtotime('+' . max(1, $diasValidez) . ' days'));

        $stmt = $conn->prepare(
            "INSERT INTO candidato_accesos (id_candidato, token_hash, token, fecha_expira, creado_por)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssi', $idCandidato, $hash, $token, $expira, $creadoPor);
        $stmt->execute();
        $stmt->close();
        return $token;
    }

    /**
     * Acceso vigente de un candidato (activo y sin vencer) o null. Sirve para
     * REPETIR el enlace en vez de generar uno nuevo.
     *
     * `token` puede venir NULL en los accesos creados antes de que se guardara el
     * claro: en ese caso hay acceso vigente pero no se puede volver a mostrar, y
     * quien llama debe decirlo en vez de fingir que no existe.
     */
    function sivacAccesoVigente(mysqli $conn, int $idCandidato): ?array {
        $stmt = $conn->prepare(
            "SELECT id, token, fecha_expira FROM candidato_accesos
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
     */
    function sivacResolverAcceso(mysqli $conn, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare(
            "SELECT id, id_candidato, fecha_expira, activo FROM candidato_accesos
             WHERE token_hash = ? LIMIT 1"
        );
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) return null;
        if ((int)$row['activo'] !== 1) return null;
        if (strtotime($row['fecha_expira']) < time()) return null;

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

    /** URL absoluta del portal a partir del token en claro (para copiar/compartir). */
    function sivacUrlPortal(string $token): string {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') == 443);
        $scheme = $https ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Directorio de SIVAC a partir del script actual (…/SIVAC/x.php → …/SIVAC/).
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        return $scheme . '://' . $host . $dir . '/portal.php?t=' . $token;
    }
}
