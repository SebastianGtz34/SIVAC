<?php
/**
 * vacantes.php — Lógica compartida de requisiciones.
 *
 * Vive aquí lo que necesitan por igual las dos vías de alta de una vacante:
 *   - acciones_vacantes.php     → la captura RRHH (nace 'abierta').
 *   - acciones_solicitante.php  → la levanta el jefe (nace 'pendiente_vobo').
 * Así el folio y la validación del puesto no se bifurcan entre ambas.
 */

if (!function_exists('generarFolioVacante')) {

    /** Folio VAC-AAAA-#### secuencial por año. */
    function generarFolioVacante(mysqli $conn): string {
        $anio = date('Y');
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM vacantes WHERE YEAR(fecha_creacion) = ?");
        $stmt->bind_param('i', $anio);
        $stmt->execute();
        $sec = (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0) + 1;
        $stmt->close();
        return sprintf('VAC-%s-%04d', $anio, $sec);
    }

    /**
     * Puesto del catálogo mess_rrhh.puesto (activo) o null.
     * El nombre se copia a vacantes.puesto como snapshot: si RRHH renombra el
     * puesto en el catálogo, el histórico de las vacantes ya creadas no cambia.
     */
    function puestoDelCatalogo(mysqli $conn, int $idPuesto): ?array {
        $stmt = $conn->prepare("SELECT id, puesto FROM mess_rrhh.puesto WHERE id = ? AND estatus = 1 LIMIT 1");
        $stmt->bind_param('i', $idPuesto);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Campos condicionales del tipo 'temporal': duración (meses) + motivo.
     * Sólo se exigen y sólo se guardan cuando el tipo es 'temporal'; para el
     * resto de tipos se fuerzan a NULL (así cambiar de temporal a otro tipo
     * limpia los datos que ya no aplican).
     * Devuelve ['error'=>?string, 'duracion'=>?int, 'motivo'=>?string].
     */
    function sanearTemporal(string $tipo, array $post): array {
        if ($tipo !== 'temporal') {
            return ['error' => null, 'duracion' => null, 'motivo' => null];
        }
        $duracion = (int)($post['duracion_meses'] ?? 0);
        if ($duracion < 1)   return ['error' => 'Indica la duración en meses de la contratación temporal.'];
        if ($duracion > 600) return ['error' => 'La duración en meses no es válida (máximo 600).'];
        $motivo = trim($post['motivo_temporal'] ?? '');
        if ($motivo === '')  return ['error' => 'Indica el motivo de la contratación temporal.'];
        return ['error' => null, 'duracion' => $duracion, 'motivo' => mb_substr($motivo, 0, 255)];
    }
}
