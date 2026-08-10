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
 *  - Jefe / gerente        → empleado con AL MENOS UN subordinado activo
 *    (mess_rrhh.usuarios.jefe apunta a él). Puede levantar requisiciones —que
 *    nacen pendientes de VoBo de RRHH— y ver el dashboard acotado a su equipo.
 *  - Consulta              → tabla accesos_consulta (vista read-only). RRHH la
 *    tiene implícitamente.
 *
 * POR QUÉ LA JERARQUÍA Y NO tipo_usr: mess_rrhh.usuarios tiene una etiqueta
 * tipo_usr con valores 'JEFE'/'GERENTE', pero está incompleta — hay 30 empleados
 * con subordinados activos y solo 21 etiquetados (p. ej. jefes reales con 13 y 11
 * subordinados figuran como 'ADMINISTRACION' y 'VENTAS'). Gatear por la etiqueta
 * dejaría fuera a jefes de facto, así que el rol se deriva de la relación real
 * usuarios.jefe → usuarios.noEmpleado.
 *
 * Reglas de oro:
 *  - Verificación SIEMPRE en backend antes de una acción protegida.
 *  - Nunca confiar en parámetros del cliente para decidir privilegios.
 */

// Único punto de configuración de los departamentos con acceso RRHH.
// 27 = Business Intelligence, 47 = Recursos Humanos (mess_rrhh.departamento).
define('SIVAC_DEPTS_RRHH', [27, 47]);

// Departamentos que RECIBEN los avisos internos. Es un subconjunto del anterior:
// BI entra a SIVAC como súper-usuario (soporte y desarrollo), pero no lleva el
// proceso de reclutamiento, así que no se le llena la campana. ACCESO y AVISOS
// son cosas distintas: no unificar estas dos constantes.
define('SIVAC_DEPTS_NOTIF', [47]);

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

    /**
     * Empleados que reciben los avisos internos: [['noEmpleado'=>int,'correo'=>string], …].
     * Son los de SIVAC_DEPTS_NOTIF (Recursos Humanos), NO todos los que tienen
     * acceso: BI entra como súper-usuario pero no lleva el proceso. Cacheado por
     * request.
     */
    function sivacEmpleadosRRHH(mysqli $conn): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $placeholders = implode(',', array_map('intval', SIVAC_DEPTS_NOTIF));
        $res = $conn->query(
            "SELECT noEmpleado, correo FROM mess_rrhh.usuarios
             WHERE departamento IN ($placeholders) AND estatus = 1"
        );
        $cache = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $cache[] = ['noEmpleado' => (int)$r['noEmpleado'], 'correo' => (string)$r['correo']];
            }
        }
        return $cache;
    }

    /**
     * noEmpleado del departamento de RRHH, para dirigir los avisos internos.
     *
     * Los avisos "para RRHH" van al DEPARTAMENTO, no a la persona que registró al
     * candidato: quien lo dio de alta puede estar de vacaciones, haber cambiado de
     * área o simplemente no ser quien da el siguiente paso. Es el mismo criterio
     * que ya usaba la requisición pendiente de VoBo.
     */
    function sivacDestinosRRHH(mysqli $conn): array {
        return array_column(sivacEmpleadosRRHH($conn), 'noEmpleado');
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
            "SELECT noEmpleado, nombre, correo, departamento, region, jefe
             FROM mess_rrhh.usuarios WHERE noEmpleado = ? LIMIT 1"
        );
        if (!$stmt) return $cache[$noEmpleado] = null;
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $cache[$noEmpleado] = ($row ?: null);
    }

    /**
     * Región (id de mess_rrhh.region) del empleado, o null si no tiene.
     * Se usa como snapshot al crear la vacante: si el empleado cambia de región
     * después, el histórico de la vacante no se altera.
     */
    function obtenerRegionEmpleado(mysqli $conn, int $noEmpleado): ?int {
        $emp = obtenerDatosEmpleado($conn, $noEmpleado);
        $r = isset($emp['region']) ? (int)$emp['region'] : 0;
        return $r > 0 ? $r : null;
    }

    /**
     * Subordinados directos ACTIVOS de un empleado (arreglo de noEmpleado).
     *
     * mess_rrhh.usuarios.jefe es VARCHAR(11) latin1 y guarda el noEmpleado del
     * jefe; al compararlo contra un INT, MySQL castea la cadena a número, así que
     * la comparación es numérica y no interviene ninguna collation. La tabla es
     * chica (~250 filas), el escaneo es irrelevante.
     */
    function sivacSubordinados(mysqli $conn, int $noEmpleado): array {
        static $cache = [];
        if (isset($cache[$noEmpleado])) return $cache[$noEmpleado];
        $stmt = $conn->prepare(
            "SELECT noEmpleado FROM mess_rrhh.usuarios
             WHERE jefe = ? AND estatus = 1 AND noEmpleado <> ?"
        );
        if (!$stmt) return $cache[$noEmpleado] = [];
        $stmt->bind_param('ii', $noEmpleado, $noEmpleado);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) $out[] = (int)$r['noEmpleado'];
        $stmt->close();
        return $cache[$noEmpleado] = $out;
    }

    /** ¿El empleado tiene equipo a cargo? (definición funcional de jefe/gerente). */
    function esJefe(mysqli $conn, int $noEmpleado): bool {
        return count(sivacSubordinados($conn, $noEmpleado)) > 0;
    }

    /**
     * Alcance de vacantes de un jefe: las que solicitó él mismo más las de sus
     * subordinados directos. Devuelve siempre al menos [$noEmpleado], de modo que
     * quien no tiene equipo solo se ve a sí mismo (nunca un alcance vacío, que en
     * un IN (...) dejaría pasar todo o reventaría la consulta).
     */
    function sivacAlcanceVacantes(mysqli $conn, int $noEmpleado): array {
        return array_values(array_unique(
            array_merge([$noEmpleado], sivacSubordinados($conn, $noEmpleado))
        ));
    }

    /** ¿Puede ver el dashboard? RRHH (todo) o un jefe (solo su equipo). */
    function tieneDashboard(mysqli $conn, int $noEmpleado): bool {
        return esRRHH($conn, $noEmpleado) || esJefe($conn, $noEmpleado);
    }

    /** Dashboard requerido (PÁGINAS): rebota al portal si no es RRHH ni jefe. */
    function requiereDashboardPage(mysqli $conn, int $noEmpleado): void {
        if (!tieneDashboard($conn, $noEmpleado)) {
            header('Location: ../loginMaster/inicio.php');
            exit;
        }
    }

    /**
     * ¿Puede levantar una requisición de vacante? Cualquier jefe con equipo.
     * RRHH también, pero por su vía normal (acciones_vacantes.php), que no pasa
     * por VoBo.
     */
    function puedeSolicitarVacante(mysqli $conn, int $noEmpleado): bool {
        return esJefe($conn, $noEmpleado) || esRRHH($conn, $noEmpleado);
    }
}
