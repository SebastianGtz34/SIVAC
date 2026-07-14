<?php
/**
 * flujo.php — Máquina de estatuss del pipeline de candidatos (SIVAC).
 *
 * ÚNICA vía autorizada para cambiar el estatus de un candidato. Cada acción de
 * escritura debe llamar a cambiarestatusCandidato(); nunca se hace UPDATE directo
 * a candidatos.estatus desde los endpoints. El cliente jamás manda el estatus
 * destino sin que aquí se valide contra el mapa de transiciones.
 *
 * Reglas duras de negocio (además del mapa) las validan los endpoints antes de
 * llamar a la transición (p. ej. no hay entrevista sin psicométrico presentado).
 */

// estatuss finales del candidato (sin transiciones de salida).
const SIVAC_estatusS_FINALES = ['contratado', 'descartado'];

// estatuss en los que el candidato sigue "activo" en el pipeline.
const SIVAC_estatusS_estatus = [
    'aspirante', 'enviado_solicitante', 'aprobado_jefe',
    'psicometrico_asignado', 'psicometrico_presentado',
    'entrevista_confirmada', 'entrevistado',
    'propuesta_enviada', 'propuesta_expirada', 'propuesta_aceptada',
    'documentacion',
];

// Mapa de transiciones permitidas: estatus_actual => [estatuss_destino_validos].
const SIVAC_TRANSICIONES = [
    'aspirante'               => ['enviado_solicitante', 'descartado'],
    'enviado_solicitante'     => ['aprobado_jefe', 'descartado'],
    'aprobado_jefe'           => ['psicometrico_asignado', 'descartado'],
    'psicometrico_asignado'   => ['psicometrico_presentado', 'descartado'],
    'psicometrico_presentado' => ['entrevista_confirmada', 'descartado'],
    'entrevista_confirmada'   => ['entrevistado', 'descartado'],
    'entrevistado'            => ['propuesta_enviada', 'descartado'],
    'propuesta_enviada'       => ['propuesta_aceptada', 'propuesta_expirada', 'descartado'],
    'propuesta_expirada'      => ['propuesta_enviada', 'descartado'],
    'propuesta_aceptada'      => ['documentacion'],
    'documentacion'           => ['contratado', 'descartado'],
    'contratado'              => [],
    'descartado'              => [],
];

// Etiquetas legibles para UI y correos.
const SIVAC_estatus_LABEL = [
    'aspirante'               => 'Capturado',
    'enviado_solicitante'     => 'Enviado al solicitante',
    'aprobado_jefe'           => 'Aprobado por solicitante',
    'psicometrico_asignado'   => 'Psicométrico asignado',
    'psicometrico_presentado' => 'Psicométrico presentado',
    'entrevista_confirmada'   => 'Entrevista confirmada',
    'entrevistado'            => 'Entrevistado',
    'propuesta_enviada'       => 'Propuesta enviada',
    'propuesta_expirada'      => 'Propuesta expirada',
    'propuesta_aceptada'      => 'Propuesta aceptada',
    'documentacion'           => 'En documentación',
    'contratado'              => 'Contratado',
    'descartado'              => 'Descartado',
];

if (!function_exists('sivacestatusLabel')) {

    /** Etiqueta legible de un estatus de candidato. */
    function sivacestatusLabel(string $estatus): string {
        return SIVAC_estatus_LABEL[$estatus] ?? $estatus;
    }

    /** ¿La transición estatus_actual → nuevo está permitida por el mapa? */
    function sivacTransicionValida(string $actual, string $nuevo): bool {
        return in_array($nuevo, SIVAC_TRANSICIONES[$actual] ?? [], true);
    }

    /**
     * Cambia el estatus de un candidato de forma atómica y auditada.
     *
     * - Relee el estatus actual del servidor (no confía en el cliente).
     * - Valida la transición contra el mapa.
     * - El UPDATE filtra por `id AND estatus = actual` para evitar carreras.
     * - Escribe candidatos_historial con el actor.
     *
     * Devuelve ['ok'=>bool, 'message'=>string, 'anterior'=>?string].
     * NO envía notificaciones: eso lo decide el endpoint (evita acoplar el flujo
     * a plantillas de correo). El endpoint llama a notificarEvento() tras el ok.
     */
    function cambiarestatusCandidato(
        mysqli $conn,
        int $idCandidato,
        string $nuevo,
        int $noEmpleadoActor,
        ?string $comentario = null
    ): array {
        // estatus actual (fuente de verdad = BD).
        $stmt = $conn->prepare("SELECT estatus FROM candidatos WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['ok' => false, 'message' => 'El candidato no existe.', 'anterior' => null];
        }
        $actual = $row['estatus'];

        if ($actual === $nuevo) {
            return ['ok' => false, 'message' => 'El candidato ya se encuentra en ese estatus.', 'anterior' => $actual];
        }
        if (!sivacTransicionValida($actual, $nuevo)) {
            return [
                'ok' => false,
                'message' => 'Transición no permitida (' . sivacestatusLabel($actual) . ' → ' . sivacestatusLabel($nuevo) . ').',
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

        // Sincronizar cada candidato vía la máquina de estatuss (actor 0 = sistema).
        $n = 0;
        foreach ($candidatos as $idc) {
            $r = cambiarestatusCandidato($conn, $idc, 'propuesta_expirada', 0, 'Propuesta expirada automáticamente por vencimiento.');
            if ($r['ok']) $n++;
        }
        return $n;
    }
}
