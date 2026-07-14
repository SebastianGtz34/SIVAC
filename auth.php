<?php
/**
 * auth.php — Autenticación y autorización de SIVAC.
 *
 * Sesión: cookie del portal loginMaster. Se prefiere la cookie propia
 * `noEmpleadoSVC` (por si en el futuro loginMaster la emite) y se cae a la
 * cookie global `noEmpleadoL` (path=/), que es la que hoy siempre llega.
 *
 * Roles:
 *  - RRHH / Reclutamiento  → departamentos SIVAC_DEPTS_RRHH en mess_rrhh.usuarios.
 *    Acceso total al sistema (páginas y endpoints de gestión).
 *  - Solicitante           → dueño de una vacante (no_empleado_solicitante).
 *    No requiere departamento; su permiso se valida por PERTENENCIA en cada
 *    consulta (JOIN), nunca por un parámetro del cliente.
 *  - Consulta              → tabla accesos_consulta (vista read-only). RRHH la
 *    tiene implícitamente.
 *
 * Reglas de oro:
 *  - Verificación SIEMPRE en backend antes de una acción protegida.
 *  - Nunca confiar en parámetros del cliente para decidir privilegios.
 */

// Único punto de configuración de los departamentos con acceso RRHH.
// 26 = Business Intelligence, 27 = Recursos Humanos (mess_rrhh.departamento).
define('SIVAC_DEPTS_RRHH', [26, 27]);

if (!function_exists('sivacAuthNoEmpleado')) {

    /** noEmpleado de la sesión (cookie propia o global del portal) o null. */
    function sivacAuthNoEmpleado(): ?int {
        $v = $_COOKIE['noEmpleadoSVC'] ?? $_COOKIE['noEmpleadoL'] ?? null;
        if ($v === null || $v === '') return null;
        $i = (int)$v;
        return $i > 0 ? $i : null;
    }

    /** Sesión requerida (PÁGINAS): redirige al login del portal. */
    function requiereSesionPage(): int {
        $no = sivacAuthNoEmpleado();
        if (!$no) {
            header('Location: ../loginMaster/index.php');
            exit;
        }
        return $no;
    }

    /** Sesión requerida (ENDPOINTS JSON): responde 401. */
    function requiereSesionJson(): int {
        $no = sivacAuthNoEmpleado();
        if (!$no) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión no válida.']);
            exit;
        }
        return $no;
    }

    /**
     * ¿El empleado pertenece a RRHH/Reclutamiento? (depto permitido y activo).
     * Cachea el resultado por request. Los departamentos se interpolan solo
     * desde la constante (nunca desde input) para el IN (...).
     */
    function esRRHH(mysqli $conn, int $noEmpleado): bool {
        static $cache = [];
        if (isset($cache[$noEmpleado])) return $cache[$noEmpleado];

        $placeholders = implode(',', array_map('intval', SIVAC_DEPTS_RRHH));
        $stmt = $conn->prepare(
            "SELECT 1 FROM mess_rrhh.usuarios
             WHERE noEmpleado = ? AND departamento IN ($placeholders) AND estatus = 1
             LIMIT 1"
        );
        if (!$stmt) return $cache[$noEmpleado] = false;
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $tiene = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$noEmpleado] = $tiene;
    }

    /** Rol RRHH requerido (PÁGINAS): rebota al portal si no lo tiene. */
    function requiereRRHHPage(mysqli $conn, int $noEmpleado): void {
        if (!esRRHH($conn, $noEmpleado)) {
            header('Location: ../loginMaster/inicio.php');
            exit;
        }
    }

    /** Rol RRHH requerido (ENDPOINTS JSON): responde 403. */
    function requiereRRHHJson(mysqli $conn, int $noEmpleado): void {
        if (!esRRHH($conn, $noEmpleado)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'No tienes permiso para esta acción.']);
            exit;
        }
    }

    /** ¿El empleado es el solicitante (dueño) de esta vacante? */
    function esSolicitanteDeVacante(mysqli $conn, int $noEmpleado, int $idVacante): bool {
        $stmt = $conn->prepare(
            "SELECT 1 FROM vacantes WHERE id = ? AND no_empleado_solicitante = ? LIMIT 1"
        );
        $stmt->bind_param('ii', $idVacante, $noEmpleado);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /** ¿El empleado es el solicitante de la vacante de este candidato? */
    function esSolicitanteDeCandidato(mysqli $conn, int $noEmpleado, int $idCandidato): bool {
        $stmt = $conn->prepare(
            "SELECT 1
             FROM candidatos c
             INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE c.id = ? AND v.no_empleado_solicitante = ?
             LIMIT 1"
        );
        $stmt->bind_param('ii', $idCandidato, $noEmpleado);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /** ¿Tiene acceso a la vista de consulta? (RRHH o alta en accesos_consulta). */
    function tieneConsulta(mysqli $conn, int $noEmpleado): bool {
        if (esRRHH($conn, $noEmpleado)) return true;
        $stmt = $conn->prepare(
            "SELECT 1 FROM accesos_consulta WHERE no_empleado = ? AND activo = 1 LIMIT 1"
        );
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $ok;
    }

    /** Datos básicos del empleado desde mess_rrhh (nombre/correo) o null. */
    function obtenerDatosEmpleado(mysqli $conn, int $noEmpleado): ?array {
        static $cache = [];
        if (array_key_exists($noEmpleado, $cache)) return $cache[$noEmpleado];
        $stmt = $conn->prepare(
            "SELECT noEmpleado, nombre, correo, departamento
             FROM mess_rrhh.usuarios WHERE noEmpleado = ? LIMIT 1"
        );
        if (!$stmt) return $cache[$noEmpleado] = null;
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$noEmpleado] = ($row ?: null);
    }
}
