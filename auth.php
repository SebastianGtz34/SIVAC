<?php
/**
 * auth.php — Autenticación y autorización de SIVAC.
 *
 * Sesión: cookie del portal loginMaster. Se prefiere la cookie propia
 * `noEmpleadoSVC` (por si en el futuro loginMaster la emite) y se cae a la
 * cookie global `noEmpleadoL` (path=/), que es la que hoy siempre llega.
 *
 * ENTRAR A NEST LO DECIDE mess_rrhh.accesos. Ninguna página del sistema
 * (dashboard, vacantes, candidatos, contrataciones, configuración) se abre a
 * quien no tenga una fila activa con sistema = SIVAC_SISTEMA_CARD ('divNest'),
 * que es la tabla del modal «Acceso a sistemas» del portal. Se valida en el
 * backend, así que pegar la URL directa no sirve de nada.
 *
 * Todo lo que usa el resto de la empresa son VISTAS EMBEBIDAS que Messbook
 * enseña como pestañas —«Mis Vacantes», documentos—: viven en este repo pero no
 * son acceso al sistema y tienen su propio permiso.
 *
 * Roles:
 *  - Acceso a NEST → fila activa en mess_rrhh.accesos ('divNest'). Acceso total:
 *    las páginas y los endpoints de gestión. Lo concede quien administra el modal
 *    de sistemas, no SIVAC. La función se llama esRRHH() por historia; hoy
 *    significa «tiene acceso a NEST».
 *  - Solicitante           → dueño de una vacante (no_empleado_solicitante).
 *    No requiere departamento; su permiso se valida por PERTENENCIA en cada
 *    consulta (JOIN), nunca por un parámetro del cliente.
 *  - Jefe / gerente        → puesto en tipo_usr (SIVAC_TIPOS_USR_JEFE) o equipo a
 *    cargo real (mess_rrhh.usuarios.jefe). Levanta requisiciones —que nacen
 *    pendientes de VoBo— desde su pestaña del portal. NO entra a NEST.
 *  - Documentos            → mess_rrhh.accesos_especiales, sistema 'NEST',
 *    opción 'verDocumentos'. Vista embebida de solo lectura con los expedientes
 *    ya validados, para el alta de nómina; RRHH la tiene implícitamente. Se
 *    concede en Messbook, NO aquí: SIVAC no tiene pantalla para dar accesos.
 *
 * tipo_usr Y JERARQUÍA, NO UNA U OTRA: la etiqueta tipo_usr está incompleta —hay
 * jefes de facto con equipo grande registrados como 'ADMINISTRACION' o 'VENTAS'—,
 * así que sola dejaría fuera a quien manda de verdad. Pero la jerarquía sola
 * también falla, por el lado contrario: el jefe recién nombrado no tiene a nadie a
 * su cargo todavía y es justo quien necesita pedir su primera contratación. Por
 * eso las puertas aceptan las dos vías (ver puedeSolicitarVacante()).
 *
 * Dónde manda cada una: la ETIQUETA sirve de puerta (¿puede o no?) porque es con
 * la que Messbook decide qué pestañas enseña, y dejar el permiso corto respecto
 * del portal deja botones muertos. La JERARQUÍA es la única que sirve para filtrar
 * DATOS (sivacSubordinados / sivacAlcanceVacantes): la etiqueta dice que alguien
 * es jefe, no A QUIÉN manda. No se pueden intercambiar.
 *
 * Reglas de oro:
 *  - Verificación SIEMPRE en backend antes de una acción protegida.
 *  - Nunca confiar en parámetros del cliente para decidir privilegios.
 */

// Nombre de NEST en mess_rrhh.accesos, la tabla con la que el modal «Acceso a
// sistemas» de Messbook decide qué cards ve cada empleado. El valor es el id del
// div de la card, igual que 'divIncidencias' o 'divCapacitacion'.
//
// Es la ÚNICA llave para entrar a NEST: sin una fila activa aquí, ninguna página
// ni endpoint del sistema responde, ni siquiera pegando la URL directa.
define('SIVAC_SISTEMA_CARD', 'divNest');

// Etiquetas de mess_rrhh.usuarios.tipo_usr que cuentan como jefe.
//
// Es la MISMA lista con la que el portal Messbook decide quién ve la pestaña
// «Mis Vacantes» (loginMaster/inicio.php). Vive aquí para que el permiso de
// levantar requisición no le quede corto a quien el portal ya dejó entrar: si
// allá se agrega o quita una etiqueta, hay que moverla también acá.
//
// NO da acceso a NEST. Sólo habilita la vista embebida del solicitante.
define('SIVAC_TIPOS_USR_JEFE', ['SUPER_USUARIO', 'JEFE', 'GERENTE', 'JEFE_LAB', 'JEFE_ENCARGADO']);

// Nombre del sistema en mess_rrhh.accesos_especiales, la tabla de permisos
// puntuales que comparte todo el ecosistema (activos, entradasEq, ctrlVehicular,
// kpis, incidencias, gestionPersonal…) con la forma
// (sistema, opcion, noEmpleado, estatus). Los accesos se conceden ahí, no aquí:
// esto sólo los lee.
//
// Va 'NEST' y no 'SIVAC' porque es el nombre con el que se dieron de alta los
// registros: la carpeta sigue llamándose SIVAC pero el sistema ya es NEST. OJO,
// la comparación de MySQL no distingue mayúsculas con estas collations, pero el
// valor tiene que coincidir en TEXTO con el que capture quien concede el acceso.
define('SIVAC_SISTEMA_ACCESOS', 'NEST');

// Departamentos que RECIBEN los avisos internos (47 = Recursos Humanos).
//
// Esto SÍ sigue siendo el departamento del catálogo, y a propósito: ACCESO y
// AVISOS son cosas distintas. Quien entra a NEST lo decide mess_rrhh.accesos y
// ahí puede haber gente de soporte o de BI que no lleva el proceso de
// reclutamiento; llenarle la campana a esa gente sería ruido. Los avisos van al
// ÁREA responsable, no a quien tenga la llave.
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
     * ¿Tiene acceso a NEST? Es la ÚNICA puerta del sistema.
     *
     * Sale de mess_rrhh.accesos —la tabla con la que el modal «Acceso a sistemas»
     * de Messbook administra qué cards ve cada quien—, buscando el mismo `sistema`
     * que las demás: el id del div de la card ('divNest').
     *
     * Hasta el 2026-09-07 esto era el departamento (27 BI / 47 RRHH). Se cambió
     * para que dar y quitar el acceso a NEST sea el mismo trámite que para los
     * otros 16 sistemas del portal, en vez de depender de a qué departamento está
     * asignado alguien en el catálogo.
     *
     * SE VALIDA AQUÍ, EN EL BACKEND, no sólo escondiendo la card: quien pegue la
     * URL directa sin tener el acceso rebota igual, porque cada página y cada
     * endpoint pasan por requiereRRHHPage()/requiereRRHHJson().
     *
     * OJO: si nadie tiene 'divNest' dado de alta en esa tabla, NADIE entra. Al
     * desplegar hay que poblarla (ver DESPLIEGUE.md).
     */
    function tieneAccesoNest(mysqli $conn, int $noEmpleado): bool {
        static $cache = [];
        if (isset($cache[$noEmpleado])) return $cache[$noEmpleado];

        $sistema = SIVAC_SISTEMA_CARD;
        $stmt = $conn->prepare(
            "SELECT 1 FROM mess_rrhh.accesos
              WHERE noEmpleado = ? AND sistema = ? AND estatus = 1 LIMIT 1"
        );
        if (!$stmt) return $cache[$noEmpleado] = false;
        $stmt->bind_param('is', $noEmpleado, $sistema);
        $stmt->execute();
        $tiene = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$noEmpleado] = $tiene;
    }

    /**
     * Alias histórico de tieneAccesoNest().
     *
     * Se conserva porque lo llaman una decena de sitios y renombrarlos no cambia
     * nada del comportamiento. OJO CON EL NOMBRE: desde que el acceso lo decide la
     * tabla `accesos`, esto ya NO significa «pertenece a Recursos Humanos» sino
     * «tiene acceso a NEST». Para saber quién es realmente de RRHH —a quién le
     * llegan los avisos internos— está SIVAC_DEPTS_NOTIF y sivacEmpleadosRRHH().
     */
    function esRRHH(mysqli $conn, int $noEmpleado): bool {
        return tieneAccesoNest($conn, $noEmpleado);
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

    // tieneConsulta() y embed_consulta.php SE RETIRARON el 2026-09-07. Era una
    // vista de solo lectura con el avance de las vacantes (conteos por etapa, sin
    // datos personales), pero nunca llegó a enlazarse en el portal y no la usaba
    // nadie. Lo único que se ve desde fuera de RRHH es embed_documentos.php.

    /**
     * ¿Tiene concedida esta opción en mess_rrhh.accesos_especiales?
     *
     * Es la MISMA tabla y el mismo patrón que usan los demás sistemas
     * (`sistema` + `opcion` + `estatus = 1`); SIVAC sólo la lee. Conceder o quitar
     * un acceso se hace donde ya se administra esa tabla, no desde aquí: duplicar
     * el CRUD daría dos lugares donde revocar y uno se quedaría sin revocar.
     *
     * RRHH la tiene por default, igual que en la vista de consulta: si ya entra al
     * sistema completo, no tiene sentido pedirle además un permiso puntual.
     */
    function tieneAccesoEspecial(mysqli $conn, int $noEmpleado, string $opcion): bool {
        if (esRRHH($conn, $noEmpleado)) return true;

        static $cache = [];
        $llave = $noEmpleado . '|' . $opcion;
        if (isset($cache[$llave])) return $cache[$llave];

        $sistema = SIVAC_SISTEMA_ACCESOS;
        $stmt = $conn->prepare(
            "SELECT 1 FROM mess_rrhh.accesos_especiales
              WHERE noEmpleado = ? AND sistema = ? AND opcion = ? AND estatus = 1 LIMIT 1"
        );
        if (!$stmt) return $cache[$llave] = false;
        $stmt->bind_param('iss', $noEmpleado, $sistema, $opcion);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$llave] = $ok;
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
     * ¿Su puesto (mess_rrhh.usuarios.tipo_usr) es de jefe?
     *
     * Es la etiqueta del catálogo, NO la jerarquía: dice que alguien es jefe, no a
     * quién manda. Por eso sirve como PUERTA (¿puede levantar una requisición?) y
     * nunca como filtro de datos —eso sigue saliendo de sivacSubordinados()—.
     *
     * Existe para no dejarle el botón muerto a quien el portal ya dejó pasar: la
     * pestaña «Mis Vacantes» de Messbook se decide con esta misma lista. Cubre
     * además al jefe recién nombrado, que todavía no tiene a nadie a su cargo y
     * justamente por eso necesita pedir su primera contratación.
     *
     * Las etiquetas se interpolan sólo desde la constante (jamás desde input).
     */
    function tieneTipoUsrJefe(mysqli $conn, int $noEmpleado): bool {
        static $cache = [];
        if (isset($cache[$noEmpleado])) return $cache[$noEmpleado];

        $huecos = implode(',', array_fill(0, count(SIVAC_TIPOS_USR_JEFE), '?'));
        $stmt = $conn->prepare(
            "SELECT 1 FROM mess_rrhh.usuarios
              WHERE noEmpleado = ? AND estatus = 1 AND tipo_usr IN ($huecos) LIMIT 1"
        );
        if (!$stmt) return $cache[$noEmpleado] = false;
        $params = array_merge([$noEmpleado], SIVAC_TIPOS_USR_JEFE);
        $stmt->bind_param('i' . str_repeat('s', count(SIVAC_TIPOS_USR_JEFE)), ...$params);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$noEmpleado] = $ok;
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

    /**
     * ¿Puede ver el dashboard? SÓLO RRHH/BI.
     *
     * Hasta el 2026-09-07 esto era `esRRHH() || esJefe()`, y era la única página
     * de NEST abierta a alguien sin acceso en mess_rrhh.accesos: un jefe con equipo
     * entraba y veía el dashboard recortado a sus vacantes y las de su gente. Se
     * cerró porque la regla quedó en que a NEST no entra nadie que no sea de RRHH
     * o BI; los jefes siguen su proceso desde la pestaña «Mis Vacantes» del portal
     * Messbook, que es una vista embebida y no da acceso al sistema.
     *
     * sivacAlcanceVacantes() se conserva y inicio.php sigue aplicándola cuando
     * quien entra no es RRHH: hoy esa rama no se alcanza, pero es la salvaguarda
     * que impide que reabrir esta puerta destape datos de otras áreas.
     */
    function tieneDashboard(mysqli $conn, int $noEmpleado): bool {
        return esRRHH($conn, $noEmpleado);
    }

    /** Dashboard requerido (PÁGINAS): rebota al portal si no es RRHH. */
    function requiereDashboardPage(mysqli $conn, int $noEmpleado): void {
        if (!tieneDashboard($conn, $noEmpleado)) {
            header('Location: ../loginMaster/inicio.php');
            exit;
        }
    }

    /** ¿Es dueño de alguna vacante? (en cualquier estatus, igual que el portal). */
    function esSolicitanteDeAlguna(mysqli $conn, int $noEmpleado): bool {
        static $cache = [];
        if (isset($cache[$noEmpleado])) return $cache[$noEmpleado];
        $stmt = $conn->prepare("SELECT 1 FROM vacantes WHERE no_empleado_solicitante = ? LIMIT 1");
        if (!$stmt) return $cache[$noEmpleado] = false;
        $stmt->bind_param('i', $noEmpleado);
        $stmt->execute();
        $tiene = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $cache[$noEmpleado] = $tiene;
    }

    /**
     * ¿Puede levantar una requisición de vacante?
     *
     * OJO: esto NO es acceso a NEST. Sólo habilita la vista embebida del
     * solicitante (embed_solicitante.php) y sus endpoints, que es lo que el portal
     * Messbook enseña como pestaña «Mis Vacantes». Entrar al sistema sigue siendo
     * exclusivo de quien tiene acceso en mess_rrhh.accesos.
     *
     * Cuatro vías, y cada una tapa un hueco de la anterior:
     *  - Puesto de jefe (tipo_usr) → la MISMA lista con la que Messbook decide
     *    quién ve la pestaña. Va primero porque es la que evita el botón muerto:
     *    sin ella, un GERENTE recién nombrado veía la ventana y el backend le
     *    rebotaba. Cubre al jefe que aún no tiene equipo y necesita pedir su
     *    primera contratación.
     *  - Jefe con equipo (usuarios.jefe) → la jerarquía real. Hace falta porque la
     *    etiqueta está incompleta: hay jefes de facto con equipo grande
     *    registrados como 'ADMINISTRACION' o 'VENTAS'. Es la misma segunda vía del
     *    selector de solicitante en acciones_vacantes.php, y las dos poblaciones se
     *    mantienen iguales a propósito: a quien RRHH puede nombrar solicitante,
     *    el sistema tiene que dejarlo trabajar.
     *  - RRHH → levanta además por su vía normal (acciones_vacantes.php), que no
     *    pasa por VoBo.
     *  - Dueño de alguna vacante → conserva el permiso quien ya tiene un proceso
     *    abierto aunque no cumpla ninguna de las anteriores; si no, se le
     *    congelaría una requisición a medias.
     *
     * Las dos últimas son redes de rescate y NO tienen puerta propia en el portal:
     * quien sólo califique por ellas tendrá el permiso sin ver la pestaña. Hoy sólo
     * le pasa a gente de RRHH, que entra por sus propias pantallas.
     */
    function puedeSolicitarVacante(mysqli $conn, int $noEmpleado): bool {
        return tieneTipoUsrJefe($conn, $noEmpleado)
            || esJefe($conn, $noEmpleado)
            || esRRHH($conn, $noEmpleado)
            || esSolicitanteDeAlguna($conn, $noEmpleado);
    }
}
