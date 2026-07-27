<?php
/**
 * embed_solicitante.php — Vista "Mis Vacantes" del solicitante, para iframe
 * dentro de loginMaster. Autocontenida (sin sidebar/topbar). Gate: solo sesión;
 * la seguridad real (ownership) vive en acciones_solicitante.php.
 */
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/assets.php';
$noEmpSesion = requiereSesionPage();
$embed = true;
// Solo a un jefe con equipo se le pinta el botón de levantar requisición. El
// gate real está en acciones_solicitante.php (puedeSolicitarVacante); esto solo
// evita ofrecer un formulario que el backend va a rechazar.
$puedeSolicitar = puedeSolicitarVacante($conn, $noEmpSesion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis Vacantes · SIVAC</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= sivacAsset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body class="embed">
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="mb-0"><i class="fas fa-briefcase mr-2 text-primary"></i>Mis vacantes</h5>
        <?php if ($puedeSolicitar): ?>
        <button class="btn btn-sm btn-primary" id="btnSolicitar">
            <i class="fas fa-plus mr-1"></i>Solicitar vacante
        </button>
        <?php endif; ?>
    </div>
    <div class="row" id="misVacantes"></div>

    <h5 class="mt-4 mb-3"><i class="fas fa-user-clock mr-2 text-primary"></i>Candidatos por revisar</h5>
    <p class="text-muted small mb-3">Revisa, aprueba a los que te interesen o descártalos.</p>
    <div class="row" id="misCandidatos"></div>
</div>

<?php if ($puedeSolicitar): ?>
<!-- Modal: el jefe levanta su propia requisición. Nace 'pendiente_vobo' y no es
     vacante hasta que RRHH le da el visto bueno. -->
<div class="modal fade" id="modalSolicitar" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <form id="formSolicitar">
      <div class="modal-header">
        <h5 class="modal-title">Solicitar una vacante</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">
          RRHH revisará tu solicitud antes de abrir la vacante. El departamento y la
          región se toman de tu registro de empleado.
        </p>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Puesto *</label>
            <select class="form-control" name="id_puesto" id="sol_puesto" required></select>
          </div>
          <div class="form-group col-md-3">
            <label>Tipo *</label>
            <select class="form-control" name="tipo" id="sol_tipo" required>
              <option value="temporal">Temporal</option>
              <option value="permanente">Permanente</option>
              <option value="practicas">Prácticas</option>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Posiciones</label>
            <input type="number" class="form-control" name="posiciones" id="sol_posiciones" min="1" value="1">
          </div>
        </div>
        <!-- Sólo Temporal: duración + motivo (el toggle lo maneja el script de abajo). -->
        <div class="form-row" id="sol_temporal_fields" style="display:none">
          <div class="form-group col-md-4">
            <label>Duración (meses) *</label>
            <input type="number" class="form-control" name="duracion_meses" id="sol_duracion" min="1" max="600">
          </div>
          <div class="form-group col-md-8">
            <label>Motivo de la contratación temporal *</label>
            <input type="text" class="form-control" name="motivo_temporal" id="sol_motivo_temporal" maxlength="255">
          </div>
        </div>
        <div class="form-group">
          <label>¿Por qué se necesita? *</label>
          <textarea class="form-control" name="justificacion" id="sol_justificacion" rows="3"
                    placeholder="Es lo que RRHH lee para dar el visto bueno." required></textarea>
        </div>
        <div class="form-group mb-0">
          <label>Descripción y requisitos</label>
          <textarea class="form-control" name="descripcion" id="sol_descripcion" rows="3" maxlength="4000"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Enviar solicitud</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= sivacAsset('js/funciones.js') ?>"></script>
<script>
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var estatusS_VAC = window.SIVAC_estatusS_VAC || {};
    var puedeSolicitar = <?= $puedeSolicitar ? 'true' : 'false' ?>;
    var candidatosData = [];   // caché de mis_candidatos (se filtra por vacante)
    var vacSel = 0;            // vacante seleccionada (master-detalle)

    function cargarVacantes() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_vacantes' }, function (err, res) {
            var $c = $('#misVacantes').empty();
            if (err || !res || !res.success || !res.data.length) {
                $c.html('<div class="col-12 text-muted small">No tienes vacantes asignadas.</div>'); return;
            }
            res.data.forEach(function (v) {
                // Una requisición pendiente o rechazada aún no tiene candidatos:
                // en vez de tres ceros se muestra en qué va.
                var pie;
                if (v.estatus === 'pendiente_vobo') {
                    pie = '<div class="small text-muted"><i class="fas fa-clock mr-1"></i>Esperando el visto bueno de RRHH.</div>';
                } else if (v.estatus === 'rechazada') {
                    pie = '<div class="small text-danger"><i class="fas fa-times-circle mr-1"></i>'
                        + escHtml(v.motivo_rechazo || 'RRHH rechazó la requisición.') + '</div>';
                } else {
                    pie = '<div class="d-flex justify-content-between small">'
                        + '<span><i class="fas fa-users mr-1"></i>' + v.total + ' cand.</span>'
                        + '<span class="text-warning"><i class="fas fa-eye mr-1"></i>' + v.por_revisar + ' por revisar</span>'
                        + '<span class="text-success"><i class="fas fa-user-check mr-1"></i>' + v.entrevistados + '</span>'
                        + '</div>';
                }
                $c.append(
                    '<div class="col-md-4 mb-3"><div class="card h-100 vac-card" data-id="' + v.id + '" style="cursor:pointer"><div class="card-body">'
                    + '<div class="d-flex justify-content-between align-items-start">'
                    + '<div class="fw-700">' + escHtml(v.puesto) + '</div>'
                    + badgeestatusVacante(v.estatus, estatusS_VAC[v.estatus] || v.estatus) + '</div>'
                    + '<div class="text-muted small mb-2">' + escHtml(v.folio)
                    + ' · ' + escHtml((window.SIVAC_TIPOS_VACANTE && window.SIVAC_TIPOS_VACANTE[v.tipo]) || v.tipo) + '</div>'
                    + pie
                    + '</div></div></div>'
                );
            });
            resaltarVac();   // mantiene el resaltado de la vacante seleccionada
        });
    }

    // Resalta la tarjeta de la vacante seleccionada (master-detalle).
    function resaltarVac() {
        $('#misVacantes .vac-card').removeClass('border-primary shadow');
        if (vacSel) $('#misVacantes .vac-card[data-id="' + vacSel + '"]').addClass('border-primary shadow');
    }

    // Al hacer clic en una vacante, abajo se muestran SOLO sus candidatos.
    $('#misVacantes').on('click', '.vac-card', function () {
        vacSel = $(this).data('id');
        resaltarVac();
        renderCandidatos();
    });

    /** Número de candidato con formato #CAN-000N. */
    function folioCandidato(id) {
        return '#CAN-' + String(id).padStart(4, '0');
    }

    function cargarCandidatos() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_candidatos' }, function (err, res) {
            candidatosData = (!err && res && res.success && res.data) ? res.data : [];
            renderCandidatos();
        });
    }

    // Pinta SOLO los candidatos de la vacante seleccionada (o un aviso si no hay).
    function renderCandidatos() {
        var $c = $('#misCandidatos').empty();
        if (!vacSel) {
            $c.html('<div class="col-12 text-muted small">Selecciona una vacante de arriba para ver sus candidatos.</div>'); return;
        }
        var lista = candidatosData.filter(function (x) { return String(x.id_vacante) === String(vacSel); });
        if (!lista.length) {
            $c.html('<div class="col-12 text-muted small">Esta vacante no tiene candidatos por revisar.</div>'); return;
        }
        lista.forEach(function (c) {
                var esDescartado = c.estatus === 'descartado';

                // Constancia de la entrevista de RRHH (el filtro previo). No se
                // muestra el veredicto: al jefe solo le llegan los aptos.
                var rrhh = '<div class="small"><i class="fas fa-user-check text-success mr-1"></i>'
                    + '<strong>Entrevista RRHH:</strong> '
                    + (c.entrevista_rrhh_fecha ? formatearSoloFecha(c.entrevista_rrhh_fecha) : 'sin fecha')
                    + '</div>';
                if (c.entrevista_rrhh_observaciones) {
                    rrhh += '<div class="small text-muted"><i class="fas fa-comment-dots mr-1"></i>'
                        + escHtml(c.entrevista_rrhh_observaciones) + '</div>';
                }

                // Psicométrico (informativo): a diferencia de la constancia de RRHH,
                // aquí SÍ se muestra el veredicto y la calificación — es el punto de
                // la retro: el jefe ve todo, incluidos los no aptos, y decide.
                var psico = '';
                if (c.psicometrico_fecha || c.psicometrico_resultado || c.psicometrico_calificacion) {
                    var psRes = c.psicometrico_resultado === 'apto' ? '<span class="text-success">Apto</span>'
                              : c.psicometrico_resultado === 'no_apto' ? '<span class="text-danger">No apto</span>' : '—';
                    psico = '<div class="small"><i class="fas fa-brain text-info mr-1"></i><strong>Psicométrico:</strong> ' + psRes
                          + (c.psicometrico_calificacion ? ' · ' + escHtml(c.psicometrico_calificacion) : '') + '</div>';
                    if (c.psicometrico_observaciones) {
                        psico += '<div class="small text-muted"><i class="fas fa-comment-dots mr-1"></i>'
                            + escHtml(c.psicometrico_observaciones) + '</div>';
                    }
                }

                // Estado de MI entrevista (la del jefe), si ya está confirmada.
                var miEnt = c.cita_confirmada
                    ? '<div class="small"><i class="fas fa-calendar-check text-primary mr-1"></i>'
                        + '<strong>Tu entrevista:</strong> ' + formatearFecha(c.cita_confirmada) + '</div>'
                    : '';

                // Motivo del descarte (sólo en tarjetas descartadas).
                var motivo = (esDescartado && c.motivo_descarte)
                    ? '<div class="small text-danger"><i class="fas fa-ban mr-1"></i>' + escHtml(c.motivo_descarte) + '</div>'
                    : '';

                var cv = c.cv_archivo
                    ? '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-primary mr-2"><i class="fas fa-folder-open mr-1"></i>Ver expediente</a>'
                    : '';
                // Acciones según la etapa: aprobar/descartar el CV cuando está por
                // revisar; aprobar/descartar el resultado de TU entrevista cuando ya
                // se confirmó el horario (punto 15: lo captura el jefe).
                var acc = '';
                if (c.estatus === 'enviado_solicitante') {
                    acc = '<button class="btn btn-sm btn-success btnAprobar mr-1" data-id="' + c.id + '"><i class="fas fa-check mr-1"></i>Aprobar</button>'
                        + '<button class="btn btn-sm btn-outline-danger btnDescartar" data-id="' + c.id + '"><i class="fas fa-times mr-1"></i>Descartar</button>';
                } else if (c.estatus === 'entrevista_confirmada') {
                    acc = '<button class="btn btn-sm btn-success btnResultadoOk mr-1" data-id="' + c.id + '"><i class="fas fa-check mr-1"></i>Aprobó</button>'
                        + '<button class="btn btn-sm btn-outline-danger btnResultadoNo" data-id="' + c.id + '"><i class="fas fa-times mr-1"></i>Descartó</button>';
                }

                $c.append(
                    '<div class="col-md-4 mb-3"><div class="card h-100 shadow-sm"' + (esDescartado ? ' style="opacity:.6"' : '') + '><div class="card-body d-flex flex-column">'
                    + '<div class="d-flex justify-content-between align-items-start mb-1">'
                    + '<span class="text-muted small">' + folioCandidato(c.id) + '</span>'
                    + badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus) + '</div>'
                    + '<div class="fw-700" style="font-size:1.05rem">' + escHtml(c.nombre) + '</div>'
                    + '<div class="text-muted small mb-2">' + escHtml(c.folio) + ' · ' + escHtml(c.puesto) + '</div>'
                    + '<hr class="my-2">'
                    + rrhh + psico + miEnt + motivo
                    + '<div class="mt-auto pt-3">' + cv + acc + '</div>'
                    + '</div></div></div>'
                );
            });
    }

    $('#misCandidatos').on('click', '.btnAprobar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Aprobar y proponer entrevista',
            html: '<p class="small text-muted mb-2">Ofrece dos opciones de fecha y hora para <strong>tu</strong> entrevista. '
                + '<input type="datetime-local" id="sw_op1" class="swal2-input">'
                + '<input type="datetime-local" id="sw_op2" class="swal2-input">'
                + '<textarea id="sw_notas" class="swal2-textarea" placeholder="Comentarios para RRHH (opcional)."></textarea>',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Aprobar', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                var o1 = document.getElementById('sw_op1').value;
                var o2 = document.getElementById('sw_op2').value;
                if (!o1 || !o2) { Swal.showValidationMessage('Indica las dos fechas.'); return false; }
                return { opcion1: o1, opcion2: o2, notas: document.getElementById('sw_notas').value };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_solicitante.php', {
                accion: 'aprobar_cv', id: id,
                opcion1: r.value.opcion1, opcion2: r.value.opcion2, notas: r.value.notas
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // ── Levantar una requisición (punto 2: el jefe la pide) ──
    if (puedeSolicitar) {
        var puestosCargados = false;

        // Muestra/oculta duración + motivo según el tipo. Alterna `required` con el
        // show/hide (un `required` oculto rompe el submit) y se llama a mano tras el
        // reset (que no dispara change).
        function toggleTemporalSol() {
            var esTemporal = $('#sol_tipo').val() === 'temporal';
            $('#sol_temporal_fields').toggle(esTemporal);
            $('#sol_duracion, #sol_motivo_temporal').prop('required', esTemporal);
            if (!esTemporal) { $('#sol_duracion').val(''); $('#sol_motivo_temporal').val(''); }
        }
        $('#sol_tipo').on('change', toggleTemporalSol);

        $('#btnSolicitar').on('click', function () {
            $('#formSolicitar')[0].reset();
            $('#sol_tipo').val('permanente');
            toggleTemporalSol();
            if (puestosCargados) { $('#modalSolicitar').modal('show'); return; }
            // El catálogo se pide una sola vez y solo cuando hace falta.
            ajaxPost('acciones_solicitante.php', { accion: 'catalogos' }, function (err, res) {
                if (err || !res || !res.success) {
                    mostrarToast((res && res.message) || 'No se pudo cargar el catálogo de puestos.', 'error');
                    return;
                }
                var o = '<option value="">Seleccionar…</option>';
                res.puestos.forEach(function (p) { o += '<option value="' + p.id + '">' + escHtml(p.puesto) + '</option>'; });
                $('#sol_puesto').html(o);
                puestosCargados = true;
                $('#modalSolicitar').modal('show');
            });
        });

        $('#formSolicitar').on('submit', function (e) {
            e.preventDefault();
            var data = $(this).serializeArray();
            data.push({ name: 'accion', value: 'solicitar_vacante' });
            ajaxPost('acciones_solicitante.php', data, function (err, res) {
                if (res && res.success) {
                    $('#modalSolicitar').modal('hide');
                    mostrarToast(res.message, 'success');
                    cargarVacantes();
                } else { mostrarToast((res && res.message) || 'No se pudo enviar.', 'error'); }
            });
        });
    }

    $('#misCandidatos').on('click', '.btnDescartar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Descartar candidato', input: 'textarea', inputLabel: 'Motivo',
            showCancelButton: true, confirmButtonColor: messColor('danger'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Descartar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_solicitante.php', { accion: 'descartar_cv', id: id, motivo: r.value }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // ── Resultado de la entrevista del jefe (punto 15: lo captura el propio jefe) ──
    $('#misCandidatos').on('click', '.btnResultadoOk', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: '¿Aprobar tras la entrevista?', input: 'textarea',
            inputLabel: 'Notas de la entrevista (opcional)',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Sí, aprobar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_solicitante.php', {
                accion: 'registrar_resultado_entrevista', id: id, resultado: 'aceptado', notas: r.value || ''
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });
    $('#misCandidatos').on('click', '.btnResultadoNo', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Descartar tras la entrevista', input: 'textarea', inputLabel: 'Motivo',
            showCancelButton: true, confirmButtonColor: messColor('danger'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Descartar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_solicitante.php', {
                accion: 'registrar_resultado_entrevista', id: id, resultado: 'descartado', motivo: r.value
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    cargarVacantes();
    cargarCandidatos();
});
</script>
</body>
</html>
