<?php
/**
 * validaLoginNot.php — Endpoint de validación al hacer clic en una notificación.
 *
 * Lo invoca loginMaster (funcionesGlobales.js → construirUrlNotificacion) tras
 * marcar leída la notificación: recibe el `archivo` de la fila de
 * `notificacion_historial` y responde con la URL destino; el front-end redirige.
 * Mismo contrato que `Tickets/validaLoginNot.php`.
 *
 * SIVAC no mantiene sesión PHP propia: usa las cookies globales del portal. Aquí
 * sólo se valida que el empleado exista y esté activo en mess_rrhh; el gate real
 * (RRHH / ownership) lo aplica la página destino con requiereRRHHPage() o el
 * JOIN de pertenencia. `archivo` se resuelve contra una LISTA BLANCA: nunca se
 * arma la ruta con lo que llegue del cliente.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require_once 'conn.php';
require_once 'includes/notificaciones.php';

$noEmpleado = (int)($_POST['noEmpleado'] ?? 0);
$sistema    = $_POST['sistema'] ?? '';
$archivo    = $_POST['archivo'] ?? '';
$idRegistro = (int)($_POST['idRegistro'] ?? 0);

function responder(array $payload): void {
    echo json_encode($payload);
    exit;
}

if ($sistema !== SIVAC_NOTIF_SISTEMA) {
    responder(['success' => false, 'status' => 'error', 'mensaje' => 'Sistema no corresponde a SIVAC.']);
}
if ($noEmpleado <= 0) {
    responder(['success' => false, 'status' => 'error', 'mensaje' => 'noEmpleado invalido.']);
}

$stmt = $conn->prepare("SELECT 1 FROM mess_rrhh.usuarios WHERE noEmpleado = ? AND estatus = 1 LIMIT 1");
if (!$stmt) {
    responder(['success' => false, 'status' => 'error', 'mensaje' => 'No se pudo preparar la validacion.']);
}
$stmt->bind_param('i', $noEmpleado);
$stmt->execute();
$valido = $stmt->get_result()->num_rows > 0;
$stmt->close();

if (!$valido) {
    responder(['success' => false, 'status' => 'error', 'mensaje' => 'Usuario no valido o inactivo.']);
}

// Directorio real de SIVAC dentro del host (…/SIVAC/validaLoginNot.php → /SIVAC).
// Se deriva del propio script para no romperse si el despliegue cuelga de otra ruta.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/SIVAC/x.php')), '/');

if (!in_array($archivo, sivacNotifArchivos(), true)) {
    responder([
        'success' => false, 'status' => 'error',
        'mensaje' => 'Archivo no mapeado para SIVAC.', 'archivo' => $archivo
    ]);
}

responder([
    'success'    => true,
    'status'     => 'success',
    'mensaje'    => 'Validacion correcta.',
    'sistema'    => $sistema,
    'archivo'    => $archivo,
    'idRegistro' => $idRegistro,
    'urlDestino' => $base . '/' . $archivo . '.php',
]);
