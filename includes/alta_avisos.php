<?php
/**
 * alta_avisos.php — Los correos que salen a las áreas al completar un alta.
 *
 * Antes era UN correo genérico a todas ("realicen las gestiones correspondientes").
 * Ahora es uno POR ÁREA, porque cada una pide datos distintos y hace algo
 * distinto con ellos: Nóminas asigna el número de empleado, Cuenta de gastos ve
 * si lleva tarjeta y celular, Sistemas los accesos, Marketing el correo
 * corporativo y Almacén sus herramientas. Mandarles a todos el mismo texto
 * obligaba a que cada quien preguntara por su parte.
 *
 * Quién recibe cada uno sale de `notificaciones_destinatarios.clave`, no del
 * código: RRHH edita los correos desde Configuración y un área puede tener
 * varias personas (Sistemas son dos). Un área sin correo cargado NO se manda y
 * se reporta como pendiente — nunca revienta el alta.
 *
 * Los datos que las áreas DEVUELVEN (número de empleado, celular asignado,
 * correo corporativo, accesos) no se capturan en SIVAC por decisión de producto:
 * se piden en el cuerpo del correo y el seguimiento vive fuera.
 */

if (!function_exists('sivacAvisosAlta')) {

    /** Las 5 áreas, en el orden en que se mandan. La clave es el contrato con la BD. */
    function sivacAreasAlta(): array {
        return [
            'nominas'   => 'Nóminas',
            'gastos'    => 'Cuenta de gastos',
            'marketing' => 'Marketing',
            'sistemas'  => 'Sistemas',
            'almacen'   => 'Almacén',
        ];
    }

    /** Escape corto para armar el HTML del correo. */
    function altaEsc($v): string {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }

    /** Fila "Etiqueta: valor" de la ficha. Un valor vacío se marca en gris. */
    function altaFila(string $etiqueta, $valor): string {
        $v = trim((string)$valor);
        $texto = $v !== ''
            ? '<strong>' . altaEsc($v) . '</strong>'
            : '<span style="color:#9ca3af;">— sin capturar —</span>';
        return '<tr>'
            . '<td style="padding:4px 12px 4px 0;color:#6b7280;white-space:nowrap;vertical-align:top;">'
            . altaEsc($etiqueta) . '</td>'
            . '<td style="padding:4px 0;">' . $texto . '</td>'
            . '</tr>';
    }

    /** Ficha del colaborador: las filas que reciba cada área. */
    function altaFicha(array $filas): string {
        return '<table cellpadding="0" cellspacing="0" border="0" style="width:100%;font-size:15px;">'
            . implode('', $filas) . '</table>';
    }

    /**
     * Cuerpo del correo de un área, en el orden en que se lee: primero POR QUÉ le
     * llega, luego QUÉ tiene que hacer, y al final los datos.
     *
     * El orden importa: antes la ficha iba arriba y la petición al final, así que
     * quien abría el correo veía una tabla de datos sin saber qué se esperaba de
     * él, y la acción —lo único que tenía que hacer— quedaba debajo de todo. Ahora
     * la acción va en un bloque destacado que se ve sin bajar.
     *
     * @param string $contexto Una línea: qué es este correo.
     * @param string $accion   Qué necesita el área hacer o devolver. Vacío = sólo
     *                         informativo, y entonces no se pinta el bloque.
     * @param array  $filas    Filas de la ficha (altaFila()).
     * @param string $nota     Advertencia opcional, en ámbar, bajo la acción.
     */
    function altaCuerpo(string $contexto, string $accion, array $filas, string $nota = ''): string {
        // Todo el texto va centrado; la tabla de datos NO, porque una lista de
        // "etiqueta: valor" centrada deja de leerse en columna.
        $h = '<p style="margin:0 0 16px;text-align:center;">' . $contexto . '</p>';

        if ($accion !== '') {
            // Cinta amarilla, el mismo tratamiento del aviso de modo pruebas: es lo
            // único del correo que exige hacer algo, así que resalta sobre el resto.
            $h .= '<div style="margin:0 0 16px;padding:14px 16px;border-radius:4px;'
                . 'background:#fef3c7;color:#92400e;text-align:center;">'
                . '<div style="font-size:13px;text-transform:uppercase;letter-spacing:.04em;'
                . 'font-weight:bold;margin-bottom:6px;">Qué necesitamos de ti</div>'
                . $accion . '</div>';
        }

        if ($nota !== '') {
            // La advertencia va en gris y no en ámbar: con la acción ya en amarillo,
            // dos cintas del mismo color competirían y ninguna resaltaría.
            $h .= '<p style="margin:0 0 16px;padding:10px 12px;border-radius:4px;'
                . 'background:#f3f4f6;color:#4b5563;font-size:14px;text-align:center;">' . $nota . '</p>';
        }

        return $h
            . '<p style="margin:0 0 10px;text-align:center;">'
            . 'A continuación te compartimos los datos del nuevo colaborador:</p>'
            . altaFicha($filas);
    }

    /** 'Sí' / 'No' para los requerimientos. */
    function altaSiNo($v): string {
        return !empty($v) ? 'Sí' : 'No';
    }

    /**
     * Arma los correos del alta. Devuelve una lista de
     * ['clave','area','correos','asunto','titulo','html'] — sólo de las áreas
     * que tienen al menos un correo activo cargado.
     *
     * @param array $d Ficha ya resuelta (nombres, no ids): nombre, fecha_ingreso,
     *   puesto, area, sede, jefe, correo_personal, cel_personal,
     *   req_viaticos, req_celular, req_equipo, herramientas_notificadas.
     * @param string[] $claves Áreas que RRHH marcó al completar el alta. No toda
     *   alta le toca a todas (un administrativo no pasa por Almacén), así que la
     *   decisión es suya, casilla por casilla, y no del código.
     */
    function sivacAvisosAlta(mysqli $conn, array $d, array $claves): array {
        $claves = array_intersect($claves, array_keys(sivacAreasAlta()));
        if (!$claves) return [];
        // Destinatarios por clave. Una fila sin correo es un área que RRHH aún no
        // configura: se ignora aquí y el llamador la reporta como pendiente.
        $porClave = [];
        $res = $conn->query(
            "SELECT clave, area, correo FROM notificaciones_destinatarios
              WHERE activo = 1 AND TRIM(correo) <> '' ORDER BY id"
        );
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $porClave[$r['clave']][] = $r['correo'];
            }
        }

        // Filas comunes a todas las áreas: quién entra, cuándo y de quién depende.
        $base = [
            altaFila('Nombre:',        $d['nombre']),
            altaFila('Fecha de ingreso:', $d['fecha_ingreso']),
            altaFila('Puesto:',        $d['puesto']),
            altaFila('Área:',          $d['area']),
            altaFila('Sede:',          $d['sede']),
            altaFila('Jefe directo:',  $d['jefe']),
        ];

        // Qué le toca a cada área: sus filas extra y qué se le pide de vuelta.
        // El orden de $base se respeta.
        $cuerpos = [];

        // El contexto es el mismo para todas: lo que cambia es qué se le pide a
        // cada área. Se arma una vez para no repetir la frase cinco veces.
        $contexto = 'Se completó el alta de un nuevo colaborador, su ingreso está programado para el '
            . '<strong>' . altaEsc($d['fecha_ingreso']) . '</strong>. '
            . 'Este correo te llega porque hay algo que necesitamos de tu área.';

        // ── Nóminas: es quien asigna el número de empleado. ──
        $nominas = $base;
        $nominas[] = altaFila('Correo personal:', $d['correo_personal']);
        $nominas[] = altaFila('Cel personal:',    $d['cel_personal']);
        $cuerpos['nominas'] = [
            'titulo' => 'Alta de colaborador — Nóminas',
            'html'   => altaCuerpo(
                $contexto,
                'Da de alta al colaborador y confirma el <strong>número de empleado</strong> asignado '
                . 'a Recursos Humanos, para registrarlo en el sistema <strong>MESSBOOK</strong>.',
                $nominas
            ),
        ];

        // ── Cuenta de gastos: tarjeta de viáticos y celular. ──
        $gastos = $base;
        $gastos[] = altaFila('¿Necesita tarjeta de viáticos?', altaSiNo($d['req_viaticos']));
        $gastos[] = altaFila('¿Necesita celular?',             altaSiNo($d['req_celular']));
        // Si no necesita ni tarjeta ni celular, el correo es informativo: no se le
        // pide nada y el bloque de acción no aparece.
        $accionGastos = [];
        if (!empty($d['req_viaticos'])) $accionGastos[] = 'Tramita su <strong>tarjeta de viáticos</strong>.';
        if (!empty($d['req_celular']))  $accionGastos[] = 'Asigna su <strong>celular</strong> y confirma el número a Recursos Humanos.';
        $cuerpos['gastos'] = [
            'titulo' => 'Alta de colaborador — Cuenta de gastos',
            'html'   => altaCuerpo(
                $contexto,
                $accionGastos ? implode('<br>', $accionGastos) : '',
                $gastos,
                $accionGastos ? '' : 'Este colaborador <strong>no requiere</strong> tarjeta de viáticos ni celular. Es sólo para tu conocimiento.'
            ),
        ];

        // ── Marketing: necesita el correo corporativo, que lo asigna Sistemas. ──
        $marketing = $base;
        $marketing[] = altaFila('Correo de MESS:', $d['correo_mess'] ?? '');
        $cuerpos['marketing'] = [
            'titulo' => 'Alta de colaborador — Marketing',
            'html'   => altaCuerpo(
                $contexto,
                'Da la <strong>bienvenida institucional</strong> al colaborador y agrégalo a los '
                . 'directorios y canales internos que correspondan.',
                $marketing,
                empty($d['correo_mess'])
                    ? 'El <strong>correo corporativo</strong> todavía no está asignado: lo crea Sistemas y en cuanto lo tengan te lo comparten.'
                    : ''
            ),
        ];

        // ── Sistemas: correo corporativo, accesos SCOT y equipo de cómputo. ──
        $sistemas = $base;
        $sistemas[] = altaFila('¿Necesita computadora o laptop?', altaSiNo($d['req_equipo']));
        $cuerpos['sistemas'] = [
            'titulo' => 'Alta de colaborador — Sistemas',
            'html'   => altaCuerpo(
                $contexto,
                // Sin viñetas: la lista con <ul> se alinea a la izquierda y rompía
                // el centrado del bloque.
                'Prepara para el colaborador su <strong>correo corporativo</strong>, '
                . 'sus <strong>accesos a SCOT</strong>'
                . (!empty($d['req_equipo']) ? ' y su <strong>computadora o laptop</strong>' : '')
                . '.<br>Cuando estén listos, confirma el <strong>correo electrónico asignado</strong> '
                . 'a Recursos Humanos.',
                $sistemas
            ),
        ];

        // ── Almacén: herramientas. La lista se la pasa el JEFE por su cuenta. ──
        $almacen = $base;
        $cuerpos['almacen'] = [
            'titulo' => 'Alta de colaborador — Almacén',
            'html'   => altaCuerpo(
                $contexto,
                'Entrega al colaborador las <strong>herramientas</strong> que le correspondan y '
                . 'confirma <strong>qué se le entregó</strong> a Recursos Humanos. La lista te la '
                . 'pasa el jefe directo.',
                $almacen,
                !empty($d['herramientas_notificadas'])
                    ? ''
                    : 'El jefe directo <strong>todavía no confirma</strong> haberte enviado la lista de herramientas. Si no te llega, solicítasela directamente.'
            ),
        ];

        // Sólo las áreas marcadas por RRHH que además tengan destinatario cargado.
        $avisos = [];
        foreach (sivacAreasAlta() as $clave => $area) {
            if (!in_array($clave, $claves, true)) continue;
            if (empty($porClave[$clave]) || !isset($cuerpos[$clave])) continue;
            $avisos[] = [
                'clave'   => $clave,
                'area'    => $area,
                'correos' => $porClave[$clave],
                'asunto'  => 'MESS — Alta de nuevo colaborador: ' . $d['nombre'] . ' (' . $area . ')',
                'titulo'  => $cuerpos[$clave]['titulo'],
                'html'    => $cuerpos[$clave]['html'],
            ];
        }
        return $avisos;
    }

    /**
     * Áreas marcadas por RRHH que NO se pudieron mandar por no tener correo
     * cargado. Se le reportan al completar el alta: es la única forma de que se
     * entere de que Nóminas no recibió nada.
     */
    function sivacAreasAltaSinCorreo(mysqli $conn, array $claves): array {
        $claves = array_intersect($claves, array_keys(sivacAreasAlta()));
        if (!$claves) return [];
        $conCorreo = [];
        $res = $conn->query(
            "SELECT DISTINCT clave FROM notificaciones_destinatarios
              WHERE activo = 1 AND TRIM(correo) <> ''"
        );
        if ($res) while ($r = $res->fetch_assoc()) $conCorreo[] = $r['clave'];

        $faltan = [];
        foreach (sivacAreasAlta() as $clave => $area) {
            if (in_array($clave, $claves, true) && !in_array($clave, $conCorreo, true)) {
                $faltan[] = $area;
            }
        }
        return $faltan;
    }
}
