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
}
