<?php
/**
 * portal.php — Portal del candidato (Fase B). ⚠️ ÚNICA superficie pública de SIVAC.
 *
 * Autenticación por token del enlace (portal.php?t=<64 hex>), NUNCA por la cookie
 * de loginMaster: no incluye auth.php. Resuelve su propio contexto en el servidor
 * y sólo muestra/permite tocar AL candidato dueño del token. El candidato sube sus
 * documentos y teclea sus datos fiscales; validar/aceptar sigue del lado de RRHH.
 */
require_once 'conn.php';
require_once 'includes/accesos.php';
require_once 'includes/assets.php';
require_once 'includes/datos_alta.php';

$token  = $_GET['t'] ?? '';
$acceso = sivacResolverAcceso($conn, (string)$token);
$valido = (bool)$acceso;

$cand = null; $enDocumentacion = false; $tipos = []; $ultimoPorTipo = []; $datos = [];
if ($valido) {
    $idCandidato = (int)$acceso['id_candidato'];

    $stmt = $conn->prepare(
        "SELECT c.id, c.nombre, c.estatus, v.folio, v.puesto, ct.fecha_limite_documentos
         FROM candidatos c
         INNER JOIN vacantes v ON v.id = c.id_vacante
         LEFT JOIN contrataciones ct ON ct.id_candidato = c.id
         WHERE c.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $idCandidato);
    $stmt->execute();
    $cand = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $enDocumentacion = $cand && $cand['estatus'] === 'documentacion';

    // Catálogo de documentos requeridos.
    $res = $conn->query("SELECT id, nombre, obligatorio FROM documentos_tipos WHERE estatus = 1 ORDER BY obligatorio DESC, nombre");
    while ($r = $res->fetch_assoc()) $tipos[] = $r;

    // Último documento subido por tipo (para mostrar estado de validación).
    $stmt = $conn->prepare(
        "SELECT d.id_tipo, d.nombre_original, d.validacion, d.motivo_validacion, d.fecha_creacion
         FROM documentos d
         WHERE d.id_candidato = ?
         ORDER BY d.id DESC"
    );
    $stmt->bind_param('i', $idCandidato);
    $stmt->execute();
    $rd = $stmt->get_result();
    while ($r = $rd->fetch_assoc()) {
        if (!isset($ultimoPorTipo[$r['id_tipo']])) $ultimoPorTipo[$r['id_tipo']] = $r;
    }
    $stmt->close();

    // Datos ya capturados (para precargar el formulario).
    $datos = sivacDatosAlta($conn, $idCandidato);
}
$faltanDatos = $valido ? sivacDatosAltaFaltantes($datos) : [];

/** Escape corto para este archivo. */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Etiqueta + color del estado de validación de un documento. */
function estadoDoc(?array $doc): string {
    if (!$doc) return '<span class="badge badge-secondary">Falta subir</span>';
    switch ($doc['validacion']) {
        case 'validado':  return '<span class="badge badge-success">Validado</span>';
        case 'rechazado': return '<span class="badge badge-danger">Rechazado</span>';
        default:          return '<span class="badge badge-warning">En revisión</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Portal del candidato · SIVAC</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= sivacAsset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body class="embed">
<div class="container" style="max-width:820px">
<?php if (!$valido): ?>
    <div class="text-center" style="padding:64px 16px">
        <i class="fas fa-link-slash fa-3x text-muted mb-3"></i>
        <h4>Enlace no válido o expirado</h4>
        <p class="text-muted">Tu enlace no funciona o ya venció. Solicita uno nuevo a Recursos Humanos.</p>
    </div>
<?php elseif (!$enDocumentacion): ?>
    <div class="text-center" style="padding:64px 16px">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h4>Hola, <?= h($cand['nombre']) ?></h4>
        <p class="text-muted">Tu expediente no está en la etapa de captura de documentos en este momento.
        Si crees que es un error, comunícate con Recursos Humanos.</p>
    </div>
<?php else: ?>
    <div class="my-4">
        <h3 class="mb-1"><i class="fas fa-user-check text-primary mr-2"></i>Hola, <?= h($cand['nombre']) ?></h3>
        <p class="text-muted mb-1">Estás por completar tu expediente para la vacante
            <strong><?= h($cand['puesto']) ?></strong> (<?= h($cand['folio']) ?>).</p>
        <?php if ($cand['fecha_limite_documentos']): ?>
            <p class="small text-danger mb-0"><i class="fas fa-clock mr-1"></i>
                Fecha límite para entregar tus documentos:
                <strong><?= h(date('d/m/Y', strtotime($cand['fecha_limite_documentos']))) ?></strong></p>
        <?php endif; ?>
    </div>

    <!-- ── Datos del alta ── -->
    <div class="card mb-4">
        <div class="card-header"><i class="fas fa-id-card mr-2"></i>Tus datos</div>
        <div class="card-body">
            <p class="small text-muted">Captura tus datos tal como aparecen en tus documentos oficiales.
               Puedes guardarlos aunque aún no subas todos los archivos, pero
               <strong>no se guardan solos</strong>: usa el botón «Guardar mis datos».</p>
            <div id="avisoFaltan" class="alert alert-warning py-2 small <?= $faltanDatos ? '' : 'd-none' ?>">
                <i class="fas fa-exclamation-triangle mr-1"></i>
                Te falta capturar: <span id="listaFaltan"><?= h(implode(', ', $faltanDatos)) ?></span>.
            </div>
            <form id="formDatos">
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>CURP <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-uppercase" name="curp" maxlength="18"
                               value="<?= h($datos['curp'] ?? '') ?>" placeholder="18 caracteres">
                    </div>
                    <div class="form-group col-md-6">
                        <label>RFC <span class="text-danger">*</span></label>
                        <input type="text" class="form-control text-uppercase" name="rfc" maxlength="13"
                               value="<?= h($datos['rfc'] ?? '') ?>" placeholder="Con homoclave">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4">
                        <label>NSS <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="nss" maxlength="11"
                               value="<?= h($datos['nss'] ?? '') ?>" placeholder="11 dígitos">
                    </div>
                    <div class="form-group col-md-4">
                        <label>Sexo <span class="text-danger">*</span></label>
                        <select class="form-control" name="sexo">
                            <option value="">—</option>
                            <option value="M" <?= ($datos['sexo'] ?? '') === 'M' ? 'selected' : '' ?>>Masculino</option>
                            <option value="F" <?= ($datos['sexo'] ?? '') === 'F' ? 'selected' : '' ?>>Femenino</option>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label>Fecha de nacimiento <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="fecha_nacimiento"
                               value="<?= h($datos['fecha_nacimiento'] ?? '') ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-4 mb-0">
                        <label>Tipo de sangre</label>
                        <?php $sangreActual = (string)($datos['tipo_sangre'] ?? ''); ?>
                        <select class="form-control" name="tipo_sangre">
                            <option value="">Seleccionar…</option>
                            <?php // Un valor viejo fuera del catálogo se muestra igual, para no borrarlo
                                  // sin querer al volver a guardar el formulario.
                                  if ($sangreActual !== '' && !in_array($sangreActual, sivacTiposSangre(), true)): ?>
                                <option value="<?= h($sangreActual) ?>" selected><?= h($sangreActual) ?> (fuera de catálogo)</option>
                            <?php endif; ?>
                            <?php foreach (sivacTiposSangre() as $ts): ?>
                                <option value="<?= h($ts) ?>" <?= $sangreActual === $ts ? 'selected' : '' ?>><?= h($ts) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="text-right mt-3">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i>Guardar mis datos</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Documentos ── -->
    <div class="card mb-5">
        <div class="card-header"><i class="fas fa-folder-open mr-2"></i>Tus documentos</div>
        <div class="card-body">
            <p class="small text-muted">Formatos aceptados: PDF, JPG o PNG (máx. 10 MB). Recursos Humanos
               los revisará; si alguno se rechaza, verás el motivo y podrás volver a subirlo.
               Puedes elegir varios archivos y subirlos de una vez con el botón de abajo.</p>
            <?php foreach ($tipos as $t): $doc = $ultimoPorTipo[$t['id']] ?? null; ?>
                <div class="border rounded p-3 mb-2 docFila" data-tipo="<?= (int)$t['id'] ?>">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <strong><?= h($t['nombre']) ?></strong>
                            <?= $t['obligatorio'] ? '<span class="text-danger">*</span>' : '<span class="text-muted small">(opcional)</span>' ?>
                            <div class="small text-muted"><span class="docEstado"><?= estadoDoc($doc) ?></span><span class="docNombre"><?= $doc ? ' · ' . h($doc['nombre_original']) : '' ?></span></div>
                            <div class="small text-danger docMotivo <?= ($doc && $doc['validacion'] === 'rechazado' && $doc['motivo_validacion']) ? '' : 'd-none' ?>">
                                <i class="fas fa-exclamation-circle mr-1"></i><span class="docMotivoTxt"><?= h($doc['motivo_validacion'] ?? '') ?></span>
                            </div>
                        </div>
                        <form class="formDoc form-inline mt-2" data-tipo="<?= (int)$t['id'] ?>">
                            <input type="file" class="form-control-file mr-2" name="documento" accept=".pdf,.jpg,.jpeg,.png" required>
                            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-upload mr-1"></i>Subir</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="text-right mt-3">
                <button type="button" class="btn btn-primary" id="btnSubirTodos" disabled>
                    <i class="fas fa-cloud-upload-alt mr-1"></i>Subir seleccionados
                </button>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($valido && $enDocumentacion): ?>
<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= sivacAsset('js/funciones.js') ?>"></script>
<script>
$(function () {
    // El token viaja en cada petición: es la única credencial del portal.
    var TOKEN = <?= json_encode($token) ?>;
    var datosSucios = false;   // hay cambios tecleados sin guardar

    // ── Datos del candidato ────────────────────────────────────────────────
    $('#formDatos').on('input change', 'input, select', function () { datosSucios = true; });

    $('#formDatos').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'guardar_datos_fiscales' });
        data.push({ name: 't', value: TOKEN });
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        ajaxPost('acciones_portal.php', data, function (err, res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                datosSucios = false;
                var faltan = res.faltan || [];
                $('#avisoFaltan').toggleClass('d-none', faltan.length === 0);
                $('#listaFaltan').text(faltan.join(', '));
                mostrarToast(res.message, faltan.length ? 'warning' : 'success');
            } else { mostrarToast((res && res.message) || 'No se pudo guardar.', 'error'); }
        });
    });

    // Red de seguridad: cerrar la pestaña con datos tecleados y sin guardar era la
    // forma más fácil de perderlos (el portal no guarda solo).
    $(window).on('beforeunload', function () {
        if (datosSucios) return 'Tienes datos sin guardar.';
    });

    // ── Documentos ─────────────────────────────────────────────────────────
    /** Repinta el renglón de un tipo con el documento recién subido. */
    function pintarDoc(idTipo, doc) {
        var $fila = $('.docFila[data-tipo="' + idTipo + '"]');
        $fila.find('.docEstado').html('<span class="badge badge-warning">En revisión</span>');
        $fila.find('.docNombre').text(' · ' + (doc && doc.nombre_original ? doc.nombre_original : ''));
        $fila.find('.docMotivo').addClass('d-none').find('.docMotivoTxt').text('');
        // Sólo se limpia ESTE input: los archivos elegidos en los demás renglones
        // se conservan (antes la página se recargaba y se perdían todos).
        $fila.find('input[type=file]').val('');
        actualizarBtnTodos();
    }

    /** Sube el archivo de un renglón. cb(ok) al terminar. */
    function subirFila($form, cb) {
        var input = $form.find('input[type=file]')[0];
        if (!input || !input.files.length) { if (cb) cb(false); return; }
        var idTipo = $form.data('tipo');
        var fd = new FormData();
        fd.append('documento', input.files[0]);
        fd.append('accion', 'subir_documento');
        fd.append('t', TOKEN);
        fd.append('id_tipo', idTipo);
        var $btn = $form.find('button').prop('disabled', true);
        ajaxUpload('acciones_portal.php', fd, function (err, res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                pintarDoc(idTipo, res.documento);
                if (cb) cb(true, res.message);
            } else {
                mostrarToast((res && res.message) || 'No se pudo subir.', 'error');
                if (cb) cb(false);
            }
        });
    }

    /** Habilita «Subir seleccionados» y le pone cuántos hay pendientes. */
    function actualizarBtnTodos() {
        var n = $('.formDoc input[type=file]').filter(function () { return this.files.length > 0; }).length;
        $('#btnSubirTodos').prop('disabled', n === 0).html(
            '<i class="fas fa-cloud-upload-alt mr-1"></i>Subir seleccionados' + (n ? ' (' + n + ')' : '')
        );
    }
    $('.formDoc').on('change', 'input[type=file]', actualizarBtnTodos);

    $('.formDoc').on('submit', function (e) {
        e.preventDefault();
        subirFila($(this), function (ok, msg) { if (ok) mostrarToast(msg, 'success'); });
    });

    // Sube en serie todo lo que esté elegido; cada renglón se repinta al terminar.
    $('#btnSubirTodos').on('click', function () {
        var $forms = $('.formDoc').filter(function () {
            var i = $(this).find('input[type=file]')[0];
            return i && i.files.length > 0;
        });
        if (!$forms.length) return;
        var $btn = $(this).prop('disabled', true);
        var i = 0, subidos = 0;
        (function siguiente() {
            if (i >= $forms.length) {
                $btn.prop('disabled', false);
                actualizarBtnTodos();
                mostrarToast(subidos + ' de ' + $forms.length + ' documento(s) subidos. Recursos Humanos los revisará.',
                             subidos === $forms.length ? 'success' : 'warning');
                return;
            }
            var $f = $forms.eq(i++);
            $btn.html('<i class="fas fa-spinner fa-spin mr-1"></i>Subiendo ' + i + ' de ' + $forms.length + '…');
            subirFila($f, function (ok) { if (ok) subidos++; siguiente(); });
        })();
    });
});
</script>
<?php endif; ?>
</body>
</html>
