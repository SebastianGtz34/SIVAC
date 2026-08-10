<?php
/**
 * catalogos.php — Lectura de los catálogos que viven en mess_rrhh.
 *
 * SIVAC no duplica catálogos que RRHH ya administra en su propio sistema
 * (puestos, regiones, departamentos): los lee cross-DB con prefijo explícito.
 * Las vacantes guardan el id del catálogo MÁS un snapshot del nombre, para que
 * el histórico no cambie si el catálogo se renombra.
 *
 * OJO con las collations al comparar TEXTO cross-DB: mess_sivac.vacantes.puesto
 * es utf8mb4_0900_ai_ci y mess_rrhh.puesto.puesto es utf8mb4_unicode_ci, así que
 * un JOIN por nombre exige COLLATE explícito o aborta con "Illegal mix of
 * collations". Estos helpers evitan el problema resolviendo siempre por id.
 */

if (!function_exists('catalogoRegiones')) {

    /** Regiones activas: [id => nombre]. Cacheado por request. */
    function catalogoRegiones(mysqli $conn): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $res = $conn->query("SELECT id, region FROM mess_rrhh.region ORDER BY region");
        if ($res) {
            while ($r = $res->fetch_assoc()) $cache[(int)$r['id']] = $r['region'];
        }
        return $cache;
    }

    /** Naves del catálogo de RRHH: [idNave => nombre]. Cacheado por request. */
    function catalogoNaves(mysqli $conn): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $res = $conn->query("SELECT idNave, nave FROM mess_rrhh.nave ORDER BY nave");
        if ($res) {
            while ($r = $res->fetch_assoc()) $cache[(int)$r['idNave']] = $r['nave'];
        }
        return $cache;
    }

    /** Departamentos del catálogo de RRHH: [id => nombre]. Cacheado por request. */
    function catalogoDepartamentos(mysqli $conn): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $res = $conn->query("SELECT id, departamento FROM mess_rrhh.departamento ORDER BY departamento");
        if ($res) {
            while ($r = $res->fetch_assoc()) $cache[(int)$r['id']] = $r['departamento'];
        }
        return $cache;
    }

    /** Puestos activos del catálogo: [id => nombre]. Cacheado por request. */
    function catalogoPuestos(mysqli $conn): array {
        static $cache = null;
        if ($cache !== null) return $cache;
        $cache = [];
        $res = $conn->query("SELECT id, puesto FROM mess_rrhh.puesto WHERE estatus = 1 ORDER BY puesto");
        if ($res) {
            while ($r = $res->fetch_assoc()) $cache[(int)$r['id']] = $r['puesto'];
        }
        return $cache;
    }

}
