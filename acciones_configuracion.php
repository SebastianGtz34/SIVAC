<?php
/**
 * acciones_configuracion.php — Catálogos y accesos (JSON). Gate: RRHH.
 * CRUD de documentos_tipos y notificaciones_destinatarios.
 */
header('Content-Type: application/json; charset=utf-8');
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/respuesta.php';
require_once 'includes/alta_avisos.php';   // sivacAreasAlta(): claves válidas

$noEmp = requiereSesionJson();
requiereRRHHJson($conn, $noEmp);

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

switch ($accion) {

    /* ---- Tipos de documento ---- */
    case 'listar_tipos': {
        $res = $conn->query("SELECT id, nombre, obligatorio, estatus AS activo FROM documentos_tipos ORDER BY nombre");
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }
    case 'guardar_tipo': {
        $id          = (int)($_POST['id'] ?? 0);
        $nombre      = trim($_POST['nombre'] ?? '');
        $obligatorio = !empty($_POST['obligatorio']) ? 1 : 0;
        $activo      = !empty($_POST['activo']) ? 1 : 0;
        if ($nombre === '') responder(false, 'El nombre es obligatorio.');
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE documentos_tipos SET nombre = ?, obligatorio = ?, estatus = ? WHERE id = ?");
            $stmt->bind_param('siii', $nombre, $obligatorio, $activo, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO documentos_tipos (nombre, obligatorio, estatus) VALUES (?, ?, ?)");
            $stmt->bind_param('sii', $nombre, $obligatorio, $activo);
        }
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Tipo guardado.' : 'No se pudo guardar (¿nombre duplicado?).');
    }

    /* ---- Destinatarios de aviso de alta ---- */
    case 'listar_destinatarios': {
        $res = $conn->query("SELECT id, clave, area, correo, activo FROM notificaciones_destinatarios ORDER BY clave, area");
        $data = []; while ($r = $res->fetch_assoc()) $data[] = $r;
        responder(true, '', ['data' => $data]);
    }
    case 'guardar_destinatario': {
        $id     = (int)($_POST['id'] ?? 0);
        $clave  = trim($_POST['clave'] ?? '');
        $area   = trim($_POST['area'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $activo = !empty($_POST['activo']) ? 1 : 0;
        if ($area === '') responder(false, 'El área es obligatoria.');
        // La clave decide qué cuerpo de correo recibe: si no es una de las
        // conocidas, esa fila nunca recibiría nada y nadie se enteraría.
        if (!array_key_exists($clave, sivacAreasAlta())) responder(false, 'Selecciona qué notificación recibe.');
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) responder(false, 'Correo inválido.');
        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE notificaciones_destinatarios SET clave = ?, area = ?, correo = ?, activo = ? WHERE id = ?");
            $stmt->bind_param('sssii', $clave, $area, $correo, $activo, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO notificaciones_destinatarios (clave, area, correo, activo) VALUES (?, ?, ?, ?)");
            $stmt->bind_param('sssi', $clave, $area, $correo, $activo);
        }
        $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Destinatario guardado.' : 'No se pudo guardar.');
    }
    case 'eliminar_destinatario': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) responder(false, 'Id inválido.');
        $stmt = $conn->prepare("DELETE FROM notificaciones_destinatarios WHERE id = ?");
        $stmt->bind_param('i', $id); $ok = $stmt->execute(); $stmt->close();
        responder($ok, $ok ? 'Destinatario eliminado.' : 'No se pudo eliminar.');
    }

    // El acceso a la vista de DOCUMENTOS ya no se administra aquí: vive en
    // mess_rrhh.accesos_especiales (sistema 'NEST', opción 'verDocumentos'), que
    // es donde el ecosistema concede sus permisos puntuales. Se retiró el CRUD
    // propio para no tener dos lugares donde conceder —y, sobre todo, dos donde
    // revocar—.

    default:
        responder(false, 'Acción no reconocida.');
}
