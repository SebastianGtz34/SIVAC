<?php
/**
 * portal.php — Portal del candidato (Fase B). ⚠️ ÚNICA superficie pública de SIVAC.
 *
 * Autenticación en DOS pasos, NUNCA por la cookie de loginMaster (no incluye
 * auth.php): el token del enlace (portal.php?t=<64 hex>) dice de quién es el
 * expediente, y la contraseña que RRHH le dictó aparte prueba que es él. Hasta
 * que acierta, esta página NO revela ni su nombre — quien tenga sólo el enlace no
 * se entera de nada. Acertar abre una sesión de navegador (2 h de inactividad).
 *
 * Resuelve su propio contexto en el servidor y sólo muestra/permite tocar AL
 * candidato dueño del token. El candidato sube sus documentos y teclea sus datos
 * fiscales; validar/aceptar sigue del lado de RRHH.
 */
require_once 'conn.php';
require_once 'includes/accesos.php';
require_once 'includes/assets.php';
require_once 'includes/datos_alta.php';

$token  = $_GET['t'] ?? '';
$acceso = sivacResolverAcceso($conn, (string)$token);
$valido = (bool)$acceso;
$urlBase = 'portal.php?t=' . urlencode((string)$token);

// Cerrar sesión en este navegador (el enlace y la contraseña siguen sirviendo).
// Es para el celular prestado y el ciber, no para "salir" del expediente.
if ($valido && isset($_GET['salir'])) {
    sivacPortalCerrarSesion();
    header('Location: ' . $urlBase);
    exit;
}

// ¿Hay que pedir la contraseña? Los enlaces anteriores al 2026-08-14 no tienen
// (pass_hash NULL) y siguen abriendo directo: no se deja tirado a nadie a media
// documentación. RRHH puede ponerles contraseña sin cambiarles el enlace.
$errorPass = '';
$pideClave = $valido && sivacPortalRequiereClave($acceso) && !sivacPortalSesionValida($acceso);
if ($pideClave && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['pass'])) {
    $r = sivacPortalEntrar($conn, $acceso, (string)$_POST['pass']);
    if ($r['ok']) {
        // Redirección tras el POST: si no, recargar la página vuelve a mandar la
        // contraseña y el navegador saca su "¿reenviar formulario?".
        header('Location: ' . $urlBase);
        exit;
    }
    $errorPass = $r['mensaje'];
}

$cand = null; $enDocumentacion = false; $tipos = []; $ultimoPorTipo = []; $datos = [];
if ($valido && !$pideClave) {
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
$faltanDatos = ($valido && !$pideClave) ? sivacDatosAltaFaltantes($datos) : [];

// Avance del expediente, para la barra de progreso y los contadores de cada
// sección. Un documento RECHAZADO no cuenta como entregado: hay que volver a
// subirlo, y marcarlo como hecho sería mentirle al candidato.
$docsObligatorios = 0; $docsEntregados = 0;
foreach ($tipos as $t) {
    if (!$t['obligatorio']) continue;
    $docsObligatorios++;
    $d = $ultimoPorTipo[$t['id']] ?? null;
    if ($d && $d['validacion'] !== 'rechazado') $docsEntregados++;
}
// Los datos cuentan como un paso más: es lo otro que el candidato tiene que hacer.
$pasosTotal  = $docsObligatorios + 1;
$pasosHechos = $docsEntregados + (empty($faltanDatos) ? 1 : 0);
$avance      = $pasosTotal ? (int)round($pasosHechos * 100 / $pasosTotal) : 0;

// Se abre UNA sección: la de datos si falta capturarlos (es lo primero), si no
// la de documentos. Con las dos abiertas volvíamos a la página kilométrica.
$abrirDatos = !empty($faltanDatos);

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
    <title>Portal del candidato · NEST</title>
    <link rel="icon" type="image/png" href="<?= sivacAsset('img/NEST/nest-iso.png') ?>">
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= sivacAsset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body class="embed">
<div class="container" style="max-width:820px">

<!-- Membrete: es la única pantalla que ve alguien de fuera de la empresa, así que
     tiene que verse de MESS y no como un formulario anónimo pidiéndole su CURP. -->
<div class="portal-marca">
    <!-- El lockup con Grupo MESS, no el logo suelto de NEST: al candidato lo que
         le tiene que sonar es la empresa a la que está entrando, no el nombre
         interno del sistema. -->
    <img src="<?= sivacAsset('img/NEST/nest-logo-mess.png') ?>" alt="Grupo MESS — NEST">
    <span class="portal-marca-sistema">Reclutamiento y contratación</span>
</div>

<?php if (!$valido): ?>
    <div class="text-center" style="padding:64px 16px">
        <i class="fas fa-link-slash fa-3x text-muted mb-3"></i>
        <h4>Enlace no válido o expirado</h4>
        <p class="text-muted">Tu enlace no funciona o ya venció. Solicita uno nuevo a Recursos Humanos.</p>
    </div>
<?php elseif ($pideClave): ?>
    <!-- Puerta del portal. Aquí NO va nada del candidato —ni su nombre, ni la
         vacante—: quien llegue con el enlace y sin la contraseña no debe poder
         confirmar siquiera de quién es el expediente. -->
    <div class="portal-login">
        <div class="portal-login-caja">
            <i class="fas fa-lock fa-2x mb-3" style="color:var(--accent)"></i>
            <h5 class="mb-1">Escribe tu contraseña</h5>
            <p class="small text-muted mb-4">Recursos Humanos te la dio junto con este enlace,
               en un mensaje aparte. Son 8 caracteres.</p>
            <?php if ($errorPass !== ''): ?>
                <div class="alert alert-danger py-2 small text-left">
                    <i class="fas fa-exclamation-circle mr-1"></i><?= h($errorPass) ?>
                </div>
            <?php endif; ?>
            <form method="post" action="<?= h($urlBase) ?>" autocomplete="off">
                <!-- type=text y no password: el candidato la teclea de un mensaje,
                     en un celular, y esconderla sólo le hace equivocarse. -->
                <input type="text" name="pass" class="form-control form-control-lg text-center portal-login-campo"
                       maxlength="12" placeholder="XXXX-XXXX" autocomplete="one-time-code"
                       autocapitalize="characters" autocorrect="off" spellcheck="false" autofocus required>
                <button type="submit" class="btn btn-primary btn-block mt-3">
                    <i class="fas fa-arrow-right-to-bracket mr-1"></i>Entrar
                </button>
            </form>
            <p class="small text-muted mt-4 mb-0">¿La perdiste? Pídele a Recursos Humanos que
               te la restablezca: tu enlace y lo que ya llevas entregado no se pierden.</p>
        </div>
    </div>
<?php elseif (!$enDocumentacion): ?>
    <div class="text-center" style="padding:64px 16px">
        <i class="fas fa-info-circle fa-3x text-muted mb-3"></i>
        <h4>Hola, <?= h($cand['nombre']) ?></h4>
        <p class="text-muted">Tu expediente no está en la etapa de captura de documentos en este momento.
        Si crees que es un error, comunícate con Recursos Humanos.</p>
    </div>
<?php else: ?>
    <!-- Resumen: quién es, para qué vacante, cuánto lleva y hasta cuándo tiene.
         Todo lo que antes ocupaba media pantalla, en una sola tarjeta. -->
    <div class="portal-resumen mt-3">
        <div class="d-flex justify-content-between align-items-start flex-wrap mb-2" style="gap:.5rem 1rem">
            <div>
                <h5 class="mb-1">Hola, <?= h($cand['nombre']) ?></h5>
                <div class="small text-muted"><?= h($cand['puesto']) ?> · <?= h($cand['folio']) ?></div>
            </div>
            <?php if ($cand['fecha_limite_documentos']): ?>
                <div class="small text-danger text-right"><i class="fas fa-clock mr-1"></i>Entrega hasta el<br>
                    <strong><?= h(date('d/m/Y', strtotime($cand['fecha_limite_documentos']))) ?></strong></div>
            <?php endif; ?>
        </div>
        <div class="portal-progreso" role="progressbar" aria-valuemin="0" aria-valuemax="100"
             aria-valuenow="<?= $avance ?>"><div id="barraAvance" style="width:<?= $avance ?>%"></div></div>
        <div class="small text-muted mt-1 d-flex justify-content-between align-items-end flex-wrap" style="gap:.5rem">
            <span><span id="avanceTxt"><?= $pasosHechos ?> de <?= $pasosTotal ?></span>
                pasos completos. Puedes cerrar esta página y volver con el mismo enlace.</span>
            <?php if (sivacPortalRequiereClave($acceso)): ?>
                <!-- Para el celular prestado y el ciber: cierra la sesión de ESTE
                     navegador. El enlace y la contraseña se siguen usando después. -->
                <a href="<?= h($urlBase) ?>&amp;salir=1" class="text-muted text-nowrap"
                   title="Cierra la sesión en este navegador; tu enlace y tu avance no se pierden">
                    <i class="fas fa-right-from-bracket mr-1"></i>Cerrar sesión</a>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Datos del alta ── -->
    <div class="portal-sec">
        <button class="portal-sec-head" type="button" data-toggle="collapse" data-target="#secDatos"
                aria-expanded="<?= $abrirDatos ? 'true' : 'false' ?>">
            <i class="fas fa-id-card"></i>
            <span>Tus datos</span>
            <span class="badge <?= $faltanDatos ? 'badge-warning' : 'badge-success' ?>" id="badgeDatos">
                <?= $faltanDatos ? 'Faltan ' . count($faltanDatos) : 'Completos' ?>
            </span>
            <i class="fas fa-chevron-down portal-sec-chevron"></i>
        </button>
        <div class="collapse<?= $abrirDatos ? ' show' : '' ?>" id="secDatos">
        <div class="portal-sec-body pt-3">
            <p class="small text-muted">Captura tus datos tal como aparecen en tus documentos oficiales.
               <strong>No se guardan solos</strong>: usa el botón «Guardar mis datos».</p>
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
    </div>

    <!-- ── Documentos ── -->
    <div class="portal-sec">
        <button class="portal-sec-head" type="button" data-toggle="collapse" data-target="#secDocs"
                aria-expanded="<?= $abrirDatos ? 'false' : 'true' ?>">
            <i class="fas fa-folder-open"></i>
            <span>Tus documentos</span>
            <span class="badge <?= $docsEntregados >= $docsObligatorios ? 'badge-success' : 'badge-warning' ?>" id="badgeDocs">
                <span id="docsHechos"><?= $docsEntregados ?></span> de <?= $docsObligatorios ?>
            </span>
            <i class="fas fa-chevron-down portal-sec-chevron"></i>
        </button>
        <div class="collapse<?= $abrirDatos ? '' : ' show' ?>" id="secDocs">
        <div class="portal-sec-body pt-3">
            <p class="small text-muted">PDF, JPG o PNG (máx. 10 MB). Recursos Humanos los revisará; si
               alguno se rechaza verás el motivo y podrás volver a subirlo. Puedes elegir varios y
               subirlos de una vez.</p>
            <?php foreach ($tipos as $t): $doc = $ultimoPorTipo[$t['id']] ?? null; ?>
                <div class="portal-doc docFila" data-tipo="<?= (int)$t['id'] ?>" data-oblig="<?= $t['obligatorio'] ? 1 : 0 ?>">
                    <div class="portal-doc-nom">
                        <strong><?= h($t['nombre']) ?></strong><?= $t['obligatorio'] ? '<span class="text-danger">*</span>' : ' <span class="text-muted small">(opcional)</span>' ?>
                        <span class="docEstado ml-1"><?= estadoDoc($doc) ?></span><span class="docNombre small text-muted"><?= $doc ? ' · ' . h($doc['nombre_original']) : '' ?></span>
                        <div class="small text-danger docMotivo <?= ($doc && $doc['validacion'] === 'rechazado' && $doc['motivo_validacion']) ? '' : 'd-none' ?>">
                            <i class="fas fa-exclamation-circle mr-1"></i><span class="docMotivoTxt"><?= h($doc['motivo_validacion'] ?? '') ?></span>
                        </div>
                    </div>
                    <!-- El input nativo se recorta feo ("No se ha seleccionado archivo") y
                         no cabe en el renglón: se esconde dentro del label y el nombre del
                         archivo elegido lo pinta el JS. Sin `required`: un input oculto y
                         obligatorio bloquea el submit sin decir por qué. -->
                    <form class="formDoc portal-doc-archivo" data-tipo="<?= (int)$t['id'] ?>">
                        <label class="btn btn-sm btn-outline-secondary portal-file mb-0">
                            <i class="fas fa-paperclip mr-1"></i><span class="portal-file-txt">Elegir archivo</span>
                            <input type="file" name="documento" accept=".pdf,.jpg,.jpeg,.png" hidden>
                        </label>
                        <button type="submit" class="btn btn-sm btn-outline-primary" title="Subir este documento">
                            <i class="fas fa-upload"></i>
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php if ($valido && $enDocumentacion): ?>
<!-- Barra de envío: sólo aparece cuando hay archivos elegidos. Antes el botón
     vivía al final de la lista y había que recorrerla entera para llegar a él. -->
<div class="portal-barra" id="barraSubir">
    <span class="small text-muted d-none d-sm-inline" id="barraTexto"></span>
    <button type="button" class="btn btn-primary" id="btnSubirTodos" disabled>
        <i class="fas fa-cloud-upload-alt mr-1"></i>Subir seleccionados
    </button>
</div>
<?php endif; ?>

<?php if ($valido && $enDocumentacion): ?>
<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= sivacAsset('js/funciones.js') ?>"></script>
<script>
$(function () {
    // El token viaja en cada petición y dice de quién es el expediente; la
    // sesión (cookie propia, que el JS no puede leer) prueba que ya escribió la
    // contraseña. El servidor exige las dos cosas.
    var TOKEN = <?= json_encode($token) ?>;
    var DOCS_OBLIG = <?= (int)$docsObligatorios ?>;
    var datosSucios = false;   // hay cambios tecleados sin guardar

    /**
     * Vuelve a pedir la contraseña SIN recargar la página. Pasa cuando la sesión
     * vence por inactividad o cuando RRHH se la restablece con el portal abierto.
     * Recargar sería lo fácil, pero se llevaría lo que el candidato tenga tecleado
     * y sin guardar — el mismo error que costaba los datos antes del 27-jul.
     */
    function pedirClave(despues) {
        Swal.fire({
            title: 'Escribe otra vez tu contraseña',
            html: '<p class="small text-muted mb-0">Tu sesión venció por inactividad. '
                + 'No pierdes nada: lo que tienes escrito sigue en la página.</p>',
            input: 'text',
            inputPlaceholder: 'XXXX-XXXX',
            inputAttributes: { maxlength: 12, autocapitalize: 'characters', autocorrect: 'off', spellcheck: 'false' },
            showCancelButton: true, allowOutsideClick: false,
            confirmButtonText: 'Entrar', cancelButtonText: 'Ahora no',
            confirmButtonColor: messColor('accent'), background: messColor('card-bg'), color: messColor('text'),
            preConfirm: function (v) {
                return new Promise(function (resolve) {
                    ajaxPost('acciones_portal.php', { accion: 'entrar', t: TOKEN, pass: v || '' }, function (err, res) {
                        // Con showValidationMessage el cuadro se queda abierto para reintentar.
                        if (!res || !res.success) Swal.showValidationMessage((res && res.message) || 'No se pudo entrar. Revisa tu conexión.');
                        resolve(!!(res && res.success));
                    });
                });
            }
        }).then(function (r) { if (r.isConfirmed && typeof despues === 'function') despues(); });
    }

    /** Envía al portal y, si la sesión ya no vale, la reabre y REINTENTA lo mismo. */
    function portalEnviar(enviar, cb) {
        enviar(function (err, res) {
            if (res && res.sesion === 0) { pedirClave(function () { portalEnviar(enviar, cb); }); return; }
            cb(err, res);
        });
    }
    function portalPost(data, cb) {
        portalEnviar(function (done) { ajaxPost('acciones_portal.php', data, done); }, cb);
    }
    function portalUpload(fd, cb) {
        portalEnviar(function (done) { ajaxUpload('acciones_portal.php', fd, done); }, cb);
    }

    /**
     * Recalcula los contadores de las dos secciones y la barra de progreso.
     * Se hace leyendo el DOM (no llevando un contador aparte) para que no pueda
     * desincronizarse de lo que el candidato está viendo. Un documento cuenta
     * como entregado si su badge no es «Falta subir» ni «Rechazado».
     */
    function recalcularAvance() {
        var entregados = $('.docFila[data-oblig="1"]').filter(function () {
            var $b = $(this).find('.docEstado .badge');
            return $b.length && !$b.hasClass('badge-secondary') && !$b.hasClass('badge-danger');
        }).length;
        var datosOk = $('#avisoFaltan').hasClass('d-none');

        $('#docsHechos').text(entregados);
        $('#badgeDocs').toggleClass('badge-success', entregados >= DOCS_OBLIG)
                       .toggleClass('badge-warning', entregados < DOCS_OBLIG);

        var total  = DOCS_OBLIG + 1;
        var hechos = entregados + (datosOk ? 1 : 0);
        $('#avanceTxt').text(hechos + ' de ' + total);
        $('#barraAvance').css('width', Math.round(hechos * 100 / total) + '%');
    }

    // ── Datos del candidato ────────────────────────────────────────────────
    $('#formDatos').on('input change', 'input, select', function () { datosSucios = true; });

    $('#formDatos').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'guardar_datos_fiscales' });
        data.push({ name: 't', value: TOKEN });
        var $btn = $(this).find('button[type=submit]').prop('disabled', true);
        portalPost(data, function (err, res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                datosSucios = false;
                var faltan = res.faltan || [];
                $('#avisoFaltan').toggleClass('d-none', faltan.length === 0);
                $('#listaFaltan').text(faltan.join(', '));
                $('#badgeDatos').text(faltan.length ? 'Faltan ' + faltan.length : 'Completos')
                    .toggleClass('badge-success', faltan.length === 0)
                    .toggleClass('badge-warning', faltan.length > 0);
                recalcularAvance();
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
        pintarNombreElegido($fila.find('input[type=file]'));
        actualizarBtnTodos();
        recalcularAvance();
    }

    /** Pone en el botón el nombre del archivo elegido (o el texto por omisión). */
    function pintarNombreElegido($input) {
        var f = $input[0] && $input[0].files.length ? $input[0].files[0].name : '';
        var $label = $input.closest('.portal-file');
        $label.toggleClass('tiene-archivo', !!f)
              .find('.portal-file-txt').text(f || 'Elegir archivo').attr('title', f);
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
        portalUpload(fd, function (err, res) {
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

    /**
     * Habilita «Subir seleccionados» y muestra la barra de abajo sólo si hay algo
     * elegido; el padding del body evita que la barra tape el último renglón.
     */
    function actualizarBtnTodos() {
        var n = $('.formDoc input[type=file]').filter(function () { return this.files.length > 0; }).length;
        $('#btnSubirTodos').prop('disabled', n === 0).html(
            '<i class="fas fa-cloud-upload-alt mr-1"></i>Subir seleccionados' + (n ? ' (' + n + ')' : '')
        );
        $('#barraTexto').text(n === 1 ? '1 archivo listo para subir' : n + ' archivos listos para subir');
        $('#barraSubir').toggleClass('activa', n > 0);
        $('body').toggleClass('portal-con-barra', n > 0);
    }
    $('.formDoc').on('change', 'input[type=file]', function () {
        pintarNombreElegido($(this));
        actualizarBtnTodos();
    });

    $('.formDoc').on('submit', function (e) {
        e.preventDefault();
        var input = $(this).find('input[type=file]')[0];
        if (!input || !input.files.length) { mostrarToast('Elige un archivo primero.', 'warning'); return; }
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
