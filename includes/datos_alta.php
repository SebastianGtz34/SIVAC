<?php
/**
 * datos_alta.php — Datos personales que SIVAC entrega a gestionPersonal.
 *
 * Viven en `candidatos_datos_alta` (una fila por candidato) y los captura el
 * propio candidato en su portal; RRHH puede verlos y corregirlos desde el modal
 * de documentación. Como hay DOS superficies que escriben la misma fila (portal
 * público y cierre de RRHH), la validación y el UPSERT viven aquí una sola vez:
 * así no se puede colar por un lado un valor que el otro rechazaría.
 *
 * El catálogo de tipo de sangre es EL DE gestionPersonal (ARH+, ORH-, …), no la
 * notación clínica (A+, O-): estos valores se copian tal cual a
 * `mess_rrhh.usuarios.tipoSangre`, así que tienen que coincidir con su select.
 */

if (!function_exists('sivacTiposSangre')) {

    /** Catálogo de tipo de sangre, idéntico al del alta de gestionPersonal. */
    function sivacTiposSangre(): array {
        return ['ARH+', 'ARH-', 'BRH+', 'BRH-', 'ABRH+', 'ABRH-', 'ORH+', 'ORH-'];
    }

    /**
     * Campos que gestionPersonal espera recibir llenos. `tipo_sangre` queda fuera
     * a propósito: en `mess_rrhh.usuarios` la mayoría de los registros reales lo
     * tienen vacío y su propio formulario no lo exige.
     */
    function sivacDatosAltaRequeridos(): array {
        return [
            'curp'             => 'CURP',
            'rfc'              => 'RFC',
            'nss'              => 'NSS',
            'sexo'             => 'sexo',
            'fecha_nacimiento' => 'fecha de nacimiento',
        ];
    }

    /**
     * Sanea los datos del alta. Todo es opcional al guardar (se puede capturar por
     * partes); si un campo viene, se valida su formato. CURP/RFC se normalizan a
     * mayúsculas. Devuelve ['error' => ?string, ...campos].
     */
    function sivacSanearDatosAlta(array $post): array {
        $curp = strtoupper(trim($post['curp'] ?? ''));
        if ($curp !== '' && !preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[0-9A-Z]\d$/', $curp)) {
            return ['error' => 'La CURP no tiene un formato válido (18 caracteres).'];
        }
        $rfc = strtoupper(trim($post['rfc'] ?? ''));
        if ($rfc !== '' && !preg_match('/^[A-ZÑ&]{3,4}\d{6}[0-9A-Z]{2,3}$/', $rfc)) {
            return ['error' => 'El RFC no tiene un formato válido.'];
        }
        $nss = trim($post['nss'] ?? '');
        if ($nss !== '' && !preg_match('/^\d{11}$/', $nss)) {
            return ['error' => 'El NSS debe tener 11 dígitos.'];
        }
        $sexo = trim($post['sexo'] ?? '');
        if ($sexo !== '' && !in_array($sexo, ['M', 'F'], true)) {
            return ['error' => 'Sexo inválido.'];
        }
        $fnRaw = trim($post['fecha_nacimiento'] ?? '');
        $fn = null;
        if ($fnRaw !== '') {
            $t = strtotime($fnRaw);
            if (!$t || $t >= time()) return ['error' => 'La fecha de nacimiento no es válida.'];
            $fn = date('Y-m-d', $t);
        }
        $sangre = strtoupper(str_replace(' ', '', trim($post['tipo_sangre'] ?? '')));
        if ($sangre !== '' && !in_array($sangre, sivacTiposSangre(), true)) {
            return ['error' => 'Tipo de sangre inválido: usa el catálogo de gestionPersonal ('
                . implode(', ', sivacTiposSangre()) . ').'];
        }
        return [
            'error' => null,
            'curp'  => $curp !== '' ? $curp : null,
            'rfc'   => $rfc !== '' ? $rfc : null,
            'nss'   => $nss !== '' ? $nss : null,
            'sexo'  => $sexo !== '' ? $sexo : null,
            'fecha_nacimiento' => $fn,
            'tipo_sangre' => $sangre !== '' ? $sangre : null,
        ];
    }

    /**
     * UPSERT 1:1 de los datos del candidato. NO toca las columnas que decide
     * RRHH/TI (`no_empleado_asignado`, `correo_corporativo`) ni las banderas de
     * alta (`listo_para_alta`, `alta_aplicada`), que son de otra máquina de estados.
     */
    function sivacGuardarDatosAlta(mysqli $conn, int $idCandidato, array $d): bool {
        $stmt = $conn->prepare(
            "INSERT INTO candidatos_datos_alta (id_candidato, curp, rfc, nss, sexo, fecha_nacimiento, tipo_sangre)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE curp = VALUES(curp), rfc = VALUES(rfc), nss = VALUES(nss),
                                     sexo = VALUES(sexo), fecha_nacimiento = VALUES(fecha_nacimiento),
                                     tipo_sangre = VALUES(tipo_sangre)"
        );
        if (!$stmt) return false;
        $stmt->bind_param('issssss', $idCandidato, $d['curp'], $d['rfc'], $d['nss'],
                          $d['sexo'], $d['fecha_nacimiento'], $d['tipo_sangre']);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }

    /** Datos capturados del candidato (arreglo vacío si nunca se guardó nada). */
    function sivacDatosAlta(mysqli $conn, int $idCandidato): array {
        $stmt = $conn->prepare(
            "SELECT curp, rfc, nss, sexo, fecha_nacimiento, tipo_sangre,
                    listo_para_alta, alta_aplicada, fecha_actualizacion
             FROM candidatos_datos_alta WHERE id_candidato = ? LIMIT 1"
        );
        $stmt->bind_param('i', $idCandidato);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: [];
    }

    /** Etiquetas de los campos requeridos que siguen vacíos (vacío = completo). */
    function sivacDatosAltaFaltantes(array $datos): array {
        $faltan = [];
        foreach (sivacDatosAltaRequeridos() as $campo => $etiqueta) {
            if (trim((string)($datos[$campo] ?? '')) === '') $faltan[] = $etiqueta;
        }
        return $faltan;
    }
}
