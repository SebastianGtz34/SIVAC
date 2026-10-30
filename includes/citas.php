<?php
/**
 * citas.php — Reglas de las fechas que propone el jefe para SU entrevista.
 *
 * Retro 2026-10-26: un jefe reagendó un viernes a las 13:40 para el lunes a las
 * 9:00. RRHH no alcanzó a localizar al candidato y la culpa le cayó a RRHH. Desde
 * entonces toda propuesta del jefe (aprobar el CV, cambiar fechas, reagendar)
 * pasa por aquí:
 *   - Anticipación: entre el aviso y la entrevista debe quedar al menos UN día
 *     hábil completo, o sea, a partir del 2.º día hábil (viernes → martes).
 *   - Las dos opciones caen en días distintos: dos horas del mismo día no le dan
 *     al candidato una alternativa real (antes se permitía, commit 522e47b).
 * La única excepción es la fecha que pidió el propio candidato al decir que no
 * podía en ninguna: ésa ya se habló con él.
 *
 * Hábil = lunes a viernes. No hay catálogo de feriados: un feriado cuenta como
 * hábil. Las opciones en sí pueden caer en fin de semana; sólo se mide el aviso.
 *
 * VoBo de los cambios (retro 2026-10-29): si ya hay una fecha VIGENTE (cita
 * 'pendiente' o 'confirmada'), el jefe no la cambia directo: su propuesta nace
 * 'por_aprobar' y la vigente sigue en pie hasta que RRHH da «Enterado» (se
 * aplica) o la rechaza con motivo ('no_aprobada'). Mientras tanto RRHH no puede
 * confirmar la vigente con el candidato: es el candado. Ninguno de los dos
 * estados es «la cita» del candidato, así que toda consulta de «la última cita»
 * los excluye. Tras «Ninguna le queda» no hay vigente y no hace falta VoBo.
 */

// Días hábiles completos que deben quedar entre el aviso y la entrevista.
const SIVAC_CITA_DIAS_HABILES_AVISO = 1;

if (!function_exists('sivacEsDiaHabil')) {

    /** ¿El día de $t es hábil (lunes a viernes)? */
    function sivacEsDiaHabil(int $t): bool {
        return (int)date('N', $t) <= 5;
    }

    /**
     * Primer instante en que el jefe puede poner su entrevista: las 00:00 del día
     * hábil que deja SIVAC_CITA_DIAS_HABILES_AVISO días hábiles completos de por
     * medio. Viernes o sábado → martes; lunes → miércoles.
     */
    function sivacFechaMinimaEntrevista(?int $desde = null): int {
        $d = strtotime('today', $desde ?? time());
        $faltan = SIVAC_CITA_DIAS_HABILES_AVISO + 1;
        while ($faltan > 0) {
            $d = strtotime('+1 day', $d);
            if (sivacEsDiaHabil($d)) $faltan--;
        }
        return $d;
    }

    /** «mar 27/10/2026»: date('D') sale en inglés y esto lo lee el jefe. */
    function sivacFechaMinimaTexto(int $t): string {
        $dias = ['', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
        return $dias[(int)date('N', $t)] . ' ' . date('d/m/Y', $t);
    }

    /**
     * Días hábiles completos entre el aviso y la entrevista, sin contar ninguno de
     * los dos días. Viernes 13:40 → lunes 9:00 = 0. Lo pinta la ficha de RRHH para
     * que se vea con cuánto aviso llegó cada propuesta, también las de antes de
     * la regla.
     */
    function sivacDiasHabilesEntre(int $desde, int $hasta): int {
        $n = 0;
        $fin = strtotime('today', $hasta);
        for ($d = strtotime('tomorrow', $desde); $d < $fin; $d = strtotime('+1 day', $d)) {
            if (sivacEsDiaHabil($d)) $n++;
        }
        return $n;
    }

    /**
     * Valida las dos opciones que propone el jefe. Devuelve '' si están bien o el
     * mensaje para mostrarle. $pedida = la fecha que pidió el candidato (cita
     * 'rechazada'); una opción igual a ella, al minuto, no se bloquea por aviso.
     */
    function sivacValidarOpcionesEntrevista(int $t1, int $t2, ?string $pedida = null): string {
        if (!$t1 || !$t2) return 'Indica dos fechas válidas para la entrevista.';
        if ($t1 <= time() || $t2 <= time()) return 'Las fechas deben ser futuras.';
        if (date('Y-m-d', $t1) === date('Y-m-d', $t2)) {
            return 'Las dos opciones deben caer en días distintos, para que el candidato tenga una alternativa real.';
        }
        $min = sivacFechaMinimaEntrevista();
        $tp = $pedida ? strtotime($pedida) : false;
        foreach ([$t1, $t2] as $t) {
            if ($t >= $min) continue;
            if ($tp && date('Y-m-d H:i', $t) === date('Y-m-d H:i', $tp)) continue;
            return 'La entrevista debe ser a partir del ' . sivacFechaMinimaTexto($min)
                . ': RRHH necesita al menos un día hábil completo para localizar al candidato.';
        }
        return '';
    }

    /** El cambio de fechas del jefe que espera el VoBo de RRHH, o null. */
    function sivacCitaPorAprobar(mysqli $conn, int $idCandidato): ?array {
        $stmt = $conn->prepare(
            "SELECT id, opcion1, opcion2, notas FROM citas
              WHERE id_candidato = ? AND tipo = 'jefe' AND estatus = 'por_aprobar'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Historial de fechas de la entrevista del jefe, de la más nueva a la más
     * vieja, con quién propuso, quién confirmó, quién dio el VoBo y con cuántos
     * días hábiles de aviso llegó cada propuesta. Lo pintan la ficha de RRHH y el
     * seguimiento del jefe (js/funciones.js → sivacItemsCitas). Si la consulta
     * falla devuelve [] en vez de tumbar la respuesta: es un anexo de la ficha.
     */
    function sivacHistorialCitas(mysqli $conn, int $idCandidato): array {
        $citas = [];
        if ($stmt = $conn->prepare(
            "SELECT ci.*, uc.nombre AS confirmada_por_nombre, us.nombre AS solicitante_nombre,
                    uv.nombre AS vobo_por_nombre
               FROM citas ci
              INNER JOIN candidatos c ON c.id = ci.id_candidato
              INNER JOIN vacantes v ON v.id = c.id_vacante
               LEFT JOIN mess_rrhh.usuarios uc ON uc.noEmpleado = ci.confirmada_por
               LEFT JOIN mess_rrhh.usuarios us ON us.noEmpleado = v.no_empleado_solicitante
               LEFT JOIN mess_rrhh.usuarios uv ON uv.noEmpleado = ci.vobo_por
              WHERE ci.id_candidato = ? ORDER BY ci.id DESC"
        )) {
            $stmt->bind_param('i', $idCandidato); $stmt->execute(); $rc = $stmt->get_result();
            while ($r = $rc->fetch_assoc()) $citas[] = $r; $stmt->close();
        }
        // Aviso = días hábiles completos entre la propuesta y su opción más próxima.
        // La fecha que pidió el propio candidato (cita anterior 'rechazada') no
        // cuenta: ésa ya estaba hablada con él y no es un aviso corto del jefe.
        foreach ($citas as $i => $ci) {
            $previa = $citas[$i + 1] ?? null;  // DESC: la siguiente es la anterior
            $pedida = ($previa && $previa['estatus'] === 'rechazada') ? (string)$previa['fecha_candidato'] : '';
            $ts = [];
            foreach ([$ci['opcion1'], $ci['opcion2']] as $op) {
                if ($pedida !== '' && substr($op, 0, 16) === substr($pedida, 0, 16)) continue;
                $ts[] = strtotime($op);
            }
            $dias = $ts ? sivacDiasHabilesEntre(strtotime($ci['fecha_creacion']), min($ts)) : null;
            $citas[$i]['dias_aviso']  = $dias;
            $citas[$i]['aviso_corto'] = ($dias !== null && $dias < SIVAC_CITA_DIAS_HABILES_AVISO) ? 1 : 0;
        }
        return $citas;
    }

    /**
     * Correo del cambio de fechas (retro 2026-10-30): en cada paso —el jefe lo
     * pide o lo corrige, RRHH le da el VoBo o no— le llega a los DOS lados, al jefe
     * dueño de la vacante y a RRHH (los mismos de la campana, SIVAC_DEPTS_NOTIF).
     * Devuelve los campos de correo para notificarEvento(); $filas = etiqueta →
     * texto plano (se escapa aquí) y las vacías no se pintan.
     */
    function sivacCorreoCambioFechas(mysqli $conn, int $noEmpleadoJefe, string $titulo, string $folio, array $filas): array {
        $correos = array_column(sivacEmpleadosRRHH($conn), 'correo');
        $jefe = obtenerDatosEmpleado($conn, $noEmpleadoJefe);
        if (!empty($jefe['correo'])) $correos[] = $jefe['correo'];

        $html = '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">';
        foreach ($filas as $etiqueta => $texto) {
            if ((string)$texto === '') continue;
            $html .= '<tr><td style="padding:6px 12px 6px 0;color:#6b7280;vertical-align:top;white-space:nowrap;">'
                . htmlspecialchars($etiqueta) . '</td>'
                . '<td style="padding:6px 0;">' . nl2br(htmlspecialchars((string)$texto)) . '</td></tr>';
        }
        $html .= '</table>';

        return [
            'correos'       => array_values(array_unique(array_filter($correos))),
            'correo_asunto' => 'NEST — ' . $titulo . ' (' . $folio . ')',
            'correo_titulo' => $titulo,
            'correo_html'   => $html,
        ];
    }
}
