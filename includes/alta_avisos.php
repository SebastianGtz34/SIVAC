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

        // ── Nóminas: es quien asigna el número de empleado. ──
        $nominas = $base;
        $nominas[] = altaFila('Correo personal:', $d['correo_personal']);
        $nominas[] = altaFila('Cel personal:',    $d['cel_personal']);
        $cuerpos['nominas'] = [
            'titulo' => 'Alta de colaborador — Nóminas',
            'html'   => altaFicha($nominas)
                . '<p style="margin:20px 0 0;">Por favor confírmanos el <strong>número de empleado</strong> '
                . 'que le asignes: es el que necesita Recursos Humanos para darlo de alta en el sistema.</p>',
        ];

        // ── Cuenta de gastos: tarjeta de viáticos y celular. ──
        $gastos = $base;
        $gastos[] = altaFila('¿Necesita tarjeta de viáticos?', altaSiNo($d['req_viaticos']));
        $gastos[] = altaFila('¿Necesita celular?',             altaSiNo($d['req_celular']));
        $cuerpos['gastos'] = [
            'titulo' => 'Alta de colaborador — Cuenta de gastos',
            'html'   => altaFicha($gastos)
                . (!empty($d['req_celular'])
                    ? '<p style="margin:20px 0 0;">Al asignarle el equipo, por favor confírmanos el '
                      . '<strong>número de celular</strong>.</p>'
                    : ''),
        ];

        // ── Marketing: necesita el correo corporativo, que lo asigna Sistemas. ──
        $marketing = $base;
        $marketing[] = altaFila('Correo de MESS:', $d['correo_mess'] ?? '');
        $cuerpos['marketing'] = [
            'titulo' => 'Alta de colaborador — Marketing',
            'html'   => altaFicha($marketing)
                . (empty($d['correo_mess'])
                    ? '<p style="margin:20px 0 0;color:#92400e;background:#fef3c7;padding:10px 12px;border-radius:4px;">'
                      . 'El <strong>correo corporativo</strong> lo asigna Sistemas; en cuanto lo tengan te lo comparten.</p>'
                    : ''),
        ];

        // ── Sistemas: correo corporativo, accesos SCOT y equipo de cómputo. ──
        $sistemas = $base;
        $sistemas[] = altaFila('¿Necesita computadora o laptop?', altaSiNo($d['req_equipo']));
        $cuerpos['sistemas'] = [
            'titulo' => 'Alta de colaborador — Sistemas',
            'html'   => altaFicha($sistemas)
                . '<p style="margin:20px 0 8px;">Se solicita:</p>'
                . '<ul style="margin:0;padding-left:20px;">'
                . '<li><strong>Correo corporativo</strong></li>'
                . '<li><strong>Accesos a SCOT</strong></li>'
                . (!empty($d['req_equipo']) ? '<li><strong>Computadora o laptop</strong></li>' : '')
                . '</ul>'
                . '<p style="margin:16px 0 0;">Al quedar listos, por favor confírmanos el '
                . '<strong>correo electrónico asignado</strong>.</p>',
        ];

        // ── Almacén: herramientas. La lista se la pasa el JEFE por su cuenta. ──
        $almacen = $base;
        $cuerpos['almacen'] = [
            'titulo' => 'Alta de colaborador — Almacén',
            'html'   => altaFicha($almacen)
                . (!empty($d['herramientas_notificadas'])
                    ? '<p style="margin:20px 0 0;">El jefe directo indicó que ya te envió la '
                      . '<strong>lista de herramientas</strong> que necesita esta persona. '
                      . 'Por favor confírmanos qué se le entregó.</p>'
                    : '<p style="margin:20px 0 0;color:#92400e;background:#fef3c7;padding:10px 12px;border-radius:4px;">'
                      . 'El jefe directo <strong>todavía no confirma</strong> haberte enviado la lista de '
                      . 'herramientas. Si no te llega, solicítasela directamente.</p>'),
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
