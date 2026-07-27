<?php
/**
 * flujo.php — Máquina de estatus del pipeline de candidatos (SIVAC).
 *
 * ÚNICA vía autorizada para cambiar el estatus de un candidato. Cada acción de
 * escritura debe llamar a cambiarEstatusCandidato(); nunca se hace UPDATE directo
 * a candidatos.estatus desde los endpoints. El cliente jamás manda el estatus
 * destino sin que aquí se valide contra el mapa de transiciones.
 *
 * El pipeline tiene DOS ramas según vacantes.tipo:
 *   - 'temporal' | 'permanente' → rama estándar: lleva propuesta económica
 *                     antes de documentación.
 *   - 'practicas'   → flujo corto: se salta la propuesta (entrevistado pasa
 *                     directo a documentación).
 * La rama NO la elige el cliente: se lee de la vacante del candidato en cada
 * transición.
 *
 * Hay UNA sola entrevista agendada en el sistema: la del jefe
 *   (aprobado_jefe → entrevista_confirmada → entrevistado).
 * La entrevista de RRHH ocurre FUERA del sistema y solo deja constancia (fecha y
 * resultado en la tabla candidatos), que es obligatoria para enviar el candidato
 * al jefe (lo valida acciones_candidatos.php, no el mapa).
 *
 * Reglas duras de negocio (además del mapa) las validan los endpoints antes de
 * llamar a la transición.
 */

// Orden del pipeline para embudos y reportes (de la etapa más temprana a la
// más avanzada). Incluye la unión de ambas ramas.
const SIVAC_ORDEN_PIPELINE = [
    'aspirante', 'enviado_solicitante', 'aprobado_jefe',
    'entrevista_confirmada', 'entrevistado',
    'propuesta_enviada', 'propuesta_expirada', 'propuesta_aceptada',
    'documentacion', 'contratado',
];

// Rama estándar (temporal/permanente): mapa de transiciones permitidas
// estatus_actual => [destinos].
const SIVAC_TRANSICIONES_ESTANDAR = [
    'aspirante'                  => ['enviado_solicitante', 'descartado'],
    'enviado_solicitante'        => ['aprobado_jefe', 'descartado'],
    'aprobado_jefe'              => ['entrevista_confirmada', 'descartado'],
    'entrevista_confirmada'      => ['entrevistado', 'descartado'],
    'entrevistado'               => ['propuesta_enviada', 'descartado'],
    'propuesta_enviada'          => ['propuesta_aceptada', 'propuesta_expirada', 'descartado'],
    'propuesta_expirada'         => ['propuesta_enviada', 'descartado'],
    'propuesta_aceptada'         => ['documentacion'],
    'documentacion'              => ['contratado', 'descartado'],
    'contratado'                 => [],
    'descartado'                 => [],
];

// Rama 'practicas': igual que la estándar salvo un atajo —
//   entrevistado → documentación (sin propuesta económica).
const SIVAC_TRANSICIONES_PRACTICAS = [
    'aspirante'                  => ['enviado_solicitante', 'descartado'],
    'enviado_solicitante'        => ['aprobado_jefe', 'descartado'],
    'aprobado_jefe'              => ['entrevista_confirmada', 'descartado'],
    'entrevista_confirmada'      => ['entrevistado', 'descartado'],
    'entrevistado'               => ['documentacion', 'descartado'],
    'documentacion'              => ['contratado', 'descartado'],
    'contratado'                 => [],
    'descartado'                 => [],
];

// Etiquetas legibles para UI y correos.
const SIVAC_ESTATUS_LABEL = [
    'aspirante'                  => 'Capturado',
    'enviado_solicitante'        => 'Enviado al solicitante',
    'aprobado_jefe'              => 'Aprobado por solicitante',
    'entrevista_confirmada'      => 'Entrevista con jefe confirmada',
    'entrevistado'               => 'Entrevistado por el jefe',
    'propuesta_enviada'          => 'Propuesta enviada',
    'propuesta_expirada'         => 'Propuesta expirada',
    'propuesta_aceptada'         => 'Propuesta aceptada',
    'documentacion'              => 'En documentación',
    'contratado'                 => 'Contratado',
    'descartado'                 => 'Descartado',
];

// Etiquetas del tipo de vacante.
const SIVAC_TIPO_VACANTE_LABEL = [
    'temporal'   => 'Temporal',
    'permanente' => 'Permanente',
    'practicas'  => 'Prácticas',
];

// Tipos de contratación válidos (claves del ENUM vacantes.tipo). Whitelist única
// para las dos vías de alta (acciones_vacantes.php y acciones_solicitante.php).
const SIVAC_TIPOS_VACANTE = ['temporal', 'permanente', 'practicas'];

if (!function_exists('sivacEstatusLabel')) {

    /** Etiqueta legible de un estatus de candidato. */
    function sivacEstatusLabel(string $estatus): string {
        return SIVAC_ESTATUS_LABEL[$estatus] ?? $estatus;
    }

    /** Etiqueta legible del tipo de vacante ('temporal' | 'permanente' | 'practicas'). */
    function sivacTipoVacanteLabel(string $tipo): string {
        return SIVAC_TIPO_VACANTE_LABEL[$tipo] ?? $tipo;
    }

    /** Mapa de transiciones de la rama que corresponde al tipo de vacante. */
    function sivacTransiciones(string $tipoVacante): array {
        return $tipoVacante === 'practicas'
            ? SIVAC_TRANSICIONES_PRACTICAS
            : SIVAC_TRANSICIONES_ESTANDAR;
    }

    /** ¿La transición actual → nuevo está permitida en la rama del tipo dado? */
    function sivacTransicionValida(string $actual, string $nuevo, string $tipoVacante = 'permanente'): bool {
        $mapa = sivacTransiciones($tipoVacante);
        return in_array($nuevo, $mapa[$actual] ?? [], true);
    }

    /**
     * ¿Este tipo de vacante exige propuesta económica con caducidad?
     * Sólo 'practicas' se salta la propuesta (pasa de entrevistado a documentación).
     */
    function sivacRequierePropuesta(string $tipoVacante): bool {
        return $tipoVacante !== 'practicas';
    }

    /** Tipo de la vacante a la que pertenece un candidato ('permanente' si no existe). */
    function sivacTipoVacanteDeCandidato(mysqli $conn, int $idCandidato): string {
        $stmt = $conn->prepare(
            "SELECT v.tipo FROM candidatos c
             INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row['tipo'] ?? 'permanente';
    }

    /**
     * Cambia el estatus de un candidato de forma atómica y auditada.
     *
     * - Relee el estatus actual y el tipo de vacante del servidor (no confía en
     *   el cliente ni para el estatus ni para la rama del pipeline).
     * - Valida la transición contra el mapa de la rama correspondiente.
     * - El UPDATE filtra por `id AND estatus = actual` para evitar carreras.
     * - Escribe candidatos_historial con el actor.
     *
     * Devuelve ['ok'=>bool, 'message'=>string, 'anterior'=>?string].
     * NO envía notificaciones: eso lo decide el endpoint (evita acoplar el flujo
     * a plantillas de correo). El endpoint llama a notificarEvento() tras el ok.
     */
    function cambiarEstatusCandidato(
        mysqli $conn,
        int $idCandidato,
        string $nuevo,
        int $noEmpleadoActor,
        ?string $comentario = null
    ): array {
        // estatus actual y rama del pipeline (fuente de verdad = BD).
        $stmt = $conn->prepare(
            "SELECT c.estatus, v.tipo
             FROM candidatos c
             INNER JOIN vacantes v ON v.id = c.id_vacante
             WHERE c.id = ? LIMIT 1"
        );
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'message' => 'El candidato no existe.', 'anterior' => null];
        }
        $actual = $row['estatus'];
        $tipo   = $row['tipo'];

        if ($actual === $nuevo) {
            return ['ok' => false, 'message' => 'El candidato ya se encuentra en ese estatus.', 'anterior' => $actual];
        }
        if (!sivacTransicionValida($actual, $nuevo, $tipo)) {
            return [
                'ok' => false,
                'message' => 'Transición no permitida para una vacante de tipo '
                    . sivacTipoVacanteLabel($tipo) . ' ('
                    . sivacEstatusLabel($actual) . ' → ' . sivacEstatusLabel($nuevo) . ').',
                'anterior' => $actual,
            ];
        }

        // UPDATE condicionado al estatus leído: si otro proceso lo movió, no aplica.
        $upd = $conn->prepare("UPDATE candidatos SET estatus = ? WHERE id = ? AND estatus = ?");
        $upd->bind_param('sis', $nuevo, $idCandidato, $actual);
        $upd->execute();
        $afectadas = $upd->affected_rows;
        $upd->close();

        if ($afectadas < 1) {
            return ['ok' => false, 'message' => 'El estatus del candidato cambió; recarga e inténtalo de nuevo.', 'anterior' => $actual];
        }

        // Auditoría.
        $hist = $conn->prepare(
            "INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario)
             VALUES (?, ?, ?, ?, ?)"
        );
        $hist->bind_param('issis', $idCandidato, $actual, $nuevo, $noEmpleadoActor, $comentario);
        $hist->execute();
        $hist->close();

        return ['ok' => true, 'message' => '', 'anterior' => $actual];
    }

    /**
     * Expiración lazy de propuestas vencidas. Se invoca al inicio de los listados
     * de cierre y del dashboard. Marca las propuestas 'enviada' con fecha_caducidad
     * pasada como 'expirada' y sincroniza el candidato (propuesta_enviada →
     * propuesta_expirada) dejando rastro en el historial. Devuelve cuántas expiró.
     */
    function sivacExpirarPropuestas(mysqli $conn): int {
        // Candidatos afectados (antes de tocar propuestas) para sincronizar estatus.
        $sql = "SELECT DISTINCT p.id_candidato
                FROM propuestas p
                INNER JOIN candidatos c ON c.id = p.id_candidato
                WHERE p.estatus = 'enviada'
                  AND p.fecha_caducidad < CURDATE()
                  AND c.estatus = 'propuesta_enviada'";
        $res = $conn->query($sql);
        $candidatos = [];
        while ($r = $res->fetch_assoc()) {
            $candidatos[] = (int)$r['id_candidato'];
        }

        // Marcar propuestas vencidas.
        $conn->query("UPDATE propuestas SET estatus = 'expirada'
                      WHERE estatus = 'enviada' AND fecha_caducidad < CURDATE()");

        // Sincronizar cada candidato vía la máquina de estatus (actor 0 = sistema).
        $n = 0;
        foreach ($candidatos as $idc) {
            $r = cambiarEstatusCandidato($conn, $idc, 'propuesta_expirada', 0, 'Propuesta expirada automáticamente por vencimiento.');
            if ($r['ok']) $n++;
        }
        return $n;
    }
}
