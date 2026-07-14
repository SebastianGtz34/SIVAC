/* candidatos.js — Módulo de candidatos (RRHH). */
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var tabla = $('#tablaCandidatos').DataTable({
        language: dtIdioma(),
        order: [[1, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, 5, 6] }]
    });
    var vacantesCache = [];

    function cargarVacantes(cb) {
        ajaxPost('acciones_vacantes.php', { accion: 'listar' }, function (err, res) {
            if (!err && res && res.success) {
                vacantesCache = res.data;
                var optFiltro = '<option value="">Todas las vacantes</option>';
                var optForm = '<option value="">Seleccionar…</option>';
                res.data.forEach(function (v) {
                    var txt = escHtml(v.folio + ' — ' + v.puesto);
                    optFiltro += '<option value="' + v.id + '">' + txt + '</option>';
                    if (v.estatus === 'abierta' || v.estatus === 'en_proceso') {
                        optForm += '<option value="' + v.id + '">' + txt + '</option>';
                    }
                });
                $('#filtroVacante').html(optFiltro);
                $('#cand_vacante').html(optForm);
                if (window.VACANTE_PRE) $('#filtroVacante').val(window.VACANTE_PRE);
            }
            if (cb) cb();
        });
    }

    function cargar() {
        var idv = $('#filtroVacante').val() || '';
        ajaxPost('acciones_candidatos.php', { accion: 'listar', id_vacante: idv }, function (err, res) {
            tabla.clear();
            $('#chkTodos').prop('checked', false);
            actualizarBtnEnviar();
            if (err || !res || !res.success) { tabla.draw(); return; }
            res.data.forEach(function (c) {
                var chk = (c.estatus === 'aspirante' && c.cv_archivo)
                    ? '<input type="checkbox" class="chkCand" value="' + c.id + '">'
                    : '';
                var cv = c.cv_archivo
                    ? '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fas fa-file-pdf"></i></a>'
                    : '<span class="text-muted">—</span>';
                var acc = '<div class="btn-group btn-group-sm">'
                    + '<button class="btn btn-outline-secondary btnDetalle" data-id="' + c.id + '" title="Ver ficha"><i class="fas fa-eye"></i></button>'
                    + '<button class="btn btn-outline-secondary btnEditar" data-id="' + c.id + '" title="Editar"><i class="fas fa-edit"></i></button>';
                if (c.estatus !== 'contratado' && c.estatus !== 'descartado') {
                    acc += '<button class="btn btn-outline-danger btnDescartar" data-id="' + c.id + '" title="Descartar"><i class="fas fa-times"></i></button>';
                }
                acc += '</div>';
                tabla.row.add([
                    chk,
                    escHtml(c.nombre),
                    escHtml(c.folio),
                    '<div>' + escHtml(c.correo) + '</div><div class="text-muted small">' + escHtml(c.telefono || '') + '</div>',
                    badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus),
                    cv,
                    acc
                ]);
            });
            tabla.draw();
        });
    }

    function actualizarBtnEnviar() {
        var n = $('.chkCand:checked').length;
        $('#btnEnviarSel').prop('disabled', n === 0).html(
            '<i class="fas fa-paper-plane mr-1"></i> Enviar seleccionados al solicitante' + (n ? ' (' + n + ')' : '')
        );
    }

    $('#filtroVacante').on('change', cargar);
    $('#tablaCandidatos tbody').on('change', '.chkCand', actualizarBtnEnviar);
    $('#chkTodos').on('change', function () {
        $('.chkCand').prop('checked', $(this).prop('checked'));
        actualizarBtnEnviar();
    });

    $('#btnNuevoCandidato').on('click', function () {
        $('#formCandidato')[0].reset();
        $('#cand_id').val('');
        $('#grupoVacante,#grupoCv').show();
        $('#cand_cv').prop('required', true);
        $('#modalCandidatoTitulo').text('Nuevo candidato');
        if (window.VACANTE_PRE) $('#cand_vacante').val(window.VACANTE_PRE);
        $('#modalCandidato').modal('show');
    });

    $('#tablaCandidatos tbody').on('click', '.btnEditar', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_candidatos.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) return;
            var c = res.data;
            $('#formCandidato')[0].reset();
            $('#cand_id').val(c.id);
            $('#cand_nombre').val(c.nombre);
            $('#cand_correo').val(c.correo);
            $('#cand_telefono').val(c.telefono);
            $('#grupoVacante').hide();
            $('#grupoCv').hide();
            $('#cand_cv').prop('required', false);
            $('#modalCandidatoTitulo').text('Editar candidato');
            $('#modalCandidato').modal('show');
        });
    });

    $('#formCandidato').on('submit', function (e) {
        e.preventDefault();
        var esEdicion = !!$('#cand_id').val();
        var fd = new FormData(this);
        fd.append('accion', esEdicion ? 'editar' : 'crear');
        var $btn = $('#btnGuardarCandidato').prop('disabled', true);
        ajaxUpload('acciones_candidatos.php', fd, function (err, res) {
            $btn.prop('disabled', false);
            if (err || !res) { mostrarToast('Error de comunicación.', 'error'); return; }
            if (res.success) {
                $('#modalCandidato').modal('hide');
                mostrarToast(res.message || 'Guardado.', 'success');
                cargar();
            } else { mostrarToast(res.message || 'No se pudo guardar.', 'error'); }
        });
    });

    $('#btnEnviarSel').on('click', function () {
        var ids = $('.chkCand:checked').map(function () { return this.value; }).get();
        if (!ids.length) return;
        confirmarAccion('Se enviarán ' + ids.length + ' candidato(s) al solicitante para su revisión.', function () {
            ajaxPost('acciones_candidatos.php', { accion: 'enviar_solicitante', 'ids[]': ids }, function (err, res) {
                if (res && res.success) {
                    mostrarToast(res.message, 'success');
                    if (res.errores && res.errores.length) mostrarToast(res.errores.join(' · '), 'warning');
                    cargar();
                } else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        }, { titulo: 'Enviar al solicitante', confirmar: 'Sí, enviar', icon: 'question' });
    });

    $('#tablaCandidatos tbody').on('click', '.btnDescartar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Descartar candidato', input: 'textarea', inputLabel: 'Motivo del descarte',
            inputPlaceholder: 'Escribe el motivo…', showCancelButton: true,
            confirmButtonColor: messColor('danger'), background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Descartar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_candidatos.php', { accion: 'descartar', id: id, motivo: r.value }, function (err, res) {
                if (res && res.success) { mostrarToast('Candidato descartado.', 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    $('#tablaCandidatos tbody').on('click', '.btnDetalle', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_candidatos.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) { mostrarToast('No se pudo cargar.', 'error'); return; }
            $('#detalleTitulo').text(res.data.nombre + ' — ' + res.data.folio);
            $('#detalleBody').html(construirDetalle(res));
            $('#modalDetalle').modal('show');
        });
    });

    function construirDetalle(res) {
        var c = res.data;
        var h = '<div class="row mb-3">'
            + '<div class="col-md-6"><div class="text-muted small">Puesto</div>' + escHtml(c.puesto) + '</div>'
            + '<div class="col-md-6"><div class="text-muted small">estatus</div>' + badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus) + '</div>'
            + '</div>'
            + '<div class="row mb-3">'
            + '<div class="col-md-6"><div class="text-muted small">Correo</div>' + escHtml(c.correo) + '</div>'
            + '<div class="col-md-6"><div class="text-muted small">Teléfono</div>' + escHtml(c.telefono || '—') + '</div>'
            + '</div>';
        if (c.cv_archivo) {
            h += '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-outline-primary mb-3"><i class="fas fa-file-pdf mr-1"></i> Ver CV</a>';
        }
        if (c.psicometrico_folio) {
            h += '<div class="card mb-3"><div class="card-body py-2 small">'
                + '<strong>Psicométrico:</strong> folio ' + escHtml(c.psicometrico_folio)
                + (c.psicometrico_correo ? ' · ' + escHtml(c.psicometrico_correo) : '')
                + (c.psicometrico_fecha_presentado ? ' · presentado ' + formatearFecha(c.psicometrico_fecha_presentado) : ' · pendiente')
                + '</div></div>';
        }
        h += '<h6 class="mt-3">Historial</h6><ul class="timeline">';
        (res.historial || []).forEach(function (x) {
            h += '<li><div class="t-fecha">' + formatearFecha(x.fecha_creacion) + ' · #' + escHtml(x.no_empleado) + '</div>'
                + escHtml((estatusS[x.estatus_anterior] || x.estatus_anterior) + ' → ' + (estatusS[x.estatus_nuevo] || x.estatus_nuevo))
                + (x.comentario ? '<div class="text-muted small">' + escHtml(x.comentario) + '</div>' : '') + '</li>';
        });
        h += '</ul>';
        return h;
    }

    cargarVacantes(cargar);
});
