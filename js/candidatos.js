/* candidatos.js — Módulo de candidatos (RRHH). */
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var tabla = dtAutoAjustar($('#tablaCandidatos').DataTable({
        language: dtIdioma(),
        order: [[1, 'asc']],
        columnDefs: [{ orderable: false, targets: [0, 5, 6] }]
    }));
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
                // Acción contextual del pipeline (la que antes vivía en Seguimiento).
                var acc = '<div class="btn-group btn-group-sm">';
                if (c.estatus === 'aprobado_jefe') {
                    if (c.cita_jefe_pendiente) {
                        acc += '<button class="btn btn-outline-primary btnConfirmar" data-id="' + c.id + '" title="Confirmar entrevista"><i class="fas fa-calendar-check"></i></button>';
                    }
                    acc += '<button class="btn btn-outline-secondary btnCita" data-id="' + c.id + '" title="Agendar/reprogramar entrevista"><i class="fas fa-calendar-plus"></i></button>';
                } else if (c.estatus === 'entrevistado' || c.estatus === 'propuesta_enviada'
                        || c.estatus === 'propuesta_expirada' || c.estatus === 'propuesta_aceptada'
                        || c.estatus === 'documentacion') {
                    acc += '<a class="btn btn-outline-primary" href="contrataciones.php" title="Ir a cierre (propuesta/documentación)"><i class="fas fa-file-signature"></i></a>';
                }
                acc += '<button class="btn btn-outline-secondary btnDetalle" data-id="' + c.id + '" title="Ver ficha"><i class="fas fa-eye"></i></button>'
                    + '<button class="btn btn-outline-secondary btnEditar" data-id="' + c.id + '" title="Editar"><i class="fas fa-edit"></i></button>';
                // Psicométrico (informativo): disponible desde que se envió al jefe en adelante.
                if (c.estatus !== 'aspirante' && c.estatus !== 'descartado') {
                    acc += '<button class="btn btn-outline-info btnPsico" data-id="' + c.id + '" title="Psicométrico"><i class="fas fa-brain"></i></button>';
                }
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
            tabla.columns.adjust();   // recomputa anchos tras poblar por AJAX
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

    /** Fija el toggle de resultado (apto/no_apto) y el input oculto.
     *  El botón seleccionado se rellena sólido (verde/rojo) para que sea obvio. */
    function setResultadoRrhh(val) {
        $('#cand_ent_rrhh_res').val(val || '');
        $('#cand_ent_rrhh_res_group .btn').each(function () {
            var v  = $(this).data('val');
            var on = v === val;
            $(this).toggleClass('active', on);
            if (v === 'apto') {
                $(this).toggleClass('btn-success', on).toggleClass('btn-outline-success', !on);
            } else {
                $(this).toggleClass('btn-danger', on).toggleClass('btn-outline-danger', !on);
            }
            // Círculo lleno (check/cross) en el seleccionado; vacío en el otro.
            $(this).find('.marca-sel').toggleClass('d-none', !on);
            $(this).find('.marca-off').toggleClass('d-none', on);
        });
    }
    // Botón check/cross del resultado de la entrevista de RRHH.
    $('#cand_ent_rrhh_res_group').on('click', '.btn', function () {
        // Volver a tocar el botón activo lo deselecciona (resultado vacío).
        setResultadoRrhh($(this).hasClass('active') ? '' : $(this).data('val'));
    });

    /** Mismo toggle apto/no_apto que el de RRHH, para el modal del psicométrico. */
    function setResultadoPsico(val) {
        $('#ps_res').val(val || '');
        $('#ps_res_group .btn').each(function () {
            var v  = $(this).data('val');
            var on = v === val;
            $(this).toggleClass('active', on);
            if (v === 'apto') {
                $(this).toggleClass('btn-success', on).toggleClass('btn-outline-success', !on);
            } else {
                $(this).toggleClass('btn-danger', on).toggleClass('btn-outline-danger', !on);
            }
            $(this).find('.marca-sel').toggleClass('d-none', !on);
            $(this).find('.marca-off').toggleClass('d-none', on);
        });
    }
    $('#ps_res_group').on('click', '.btn', function () {
        setResultadoPsico($(this).hasClass('active') ? '' : $(this).data('val'));
    });

    $('#btnNuevoCandidato').on('click', function () {
        $('#formCandidato')[0].reset();
        $('#cand_id').val('');
        setResultadoRrhh('');
        $('#grupoVacante,#grupoCv').show();
        $('#cand_cv').prop('required', true);
        $('#cand_vacante').prop('required', true);
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
            $('#cand_apellidos').val(c.apellidos);
            $('#cand_correo').val(c.correo);
            $('#cand_telefono').val(c.telefono);
            $('#cand_ent_rrhh_fecha').val(c.entrevista_rrhh_fecha || '');
            setResultadoRrhh(c.entrevista_rrhh_resultado || '');
            $('#cand_ent_rrhh_obs').val(c.entrevista_rrhh_observaciones || '');
            $('#grupoVacante').hide();
            $('#grupoCv').hide();
            $('#cand_cv').prop('required', false);
            $('#cand_vacante').prop('required', false);   // oculto: un required oculto rompe el submit
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
            $('#detalleTitulo').text((res.data.nombre + ' ' + (res.data.apellidos || '')).trim() + ' — ' + res.data.folio);
            $('#detalleBody').html(construirDetalle(res));
            $('#modalDetalle').modal('show');
        });
    });

    // ── Psicométrico (informativo) ──
    $('#tablaCandidatos tbody').on('click', '.btnPsico', function () {
        var id = $(this).data('id');
        // Se precarga con la ficha (detalle trae c.* con el bloque psicometrico_*).
        ajaxPost('acciones_candidatos.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) { mostrarToast('No se pudo cargar.', 'error'); return; }
            var c = res.data;
            $('#formPsicometrico')[0].reset();
            $('#ps_id').val(c.id);
            $('#ps_fecha').val(c.psicometrico_fecha || '');
            $('#ps_calificacion').val(c.psicometrico_calificacion || '');
            setResultadoPsico(c.psicometrico_resultado || '');
            $('#ps_obs').val(c.psicometrico_observaciones || '');
            $('#modalPsicometrico').modal('show');
        });
    });
    $('#formPsicometrico').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'registrar_psicometrico' });
        ajaxPost('acciones_candidatos.php', data, function (err, res) {
            if (res && res.success) { $('#modalPsicometrico').modal('hide'); mostrarToast(res.message, 'success'); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
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
        // Timeline unificado y ORDENADO por fecha: filas del historial + constancia
        // de RRHH + psicométrico. Las constancias son DATE (sin hora); se les da las
        // 12:00 como llave neutra para desempatar contra los DATETIME del historial.
        var items = [];

        (res.historial || []).forEach(function (x) {
            var actor = (String(x.no_empleado) === '0') ? 'Sistema'
                      : (x.actor_nombre || ('#' + x.no_empleado));
            items.push({
                key: x.fecha_creacion,
                html: '<li><div class="t-fecha">' + formatearFecha(x.fecha_creacion) + ' · ' + escHtml(actor) + '</div>'
                    + escHtml((estatusS[x.estatus_anterior] || x.estatus_anterior) + ' → ' + (estatusS[x.estatus_nuevo] || x.estatus_nuevo))
                    + (x.comentario ? '<div class="text-muted small">' + escHtml(x.comentario) + '</div>' : '') + '</li>'
            });
        });

        // Constancia de la entrevista de RRHH (ocurre fuera del sistema).
        if (c.entrevista_rrhh_fecha || c.entrevista_rrhh_resultado) {
            var resTxt = c.entrevista_rrhh_resultado === 'apto' ? 'Apto'
                       : c.entrevista_rrhh_resultado === 'no_apto' ? 'No apto' : '—';
            items.push({
                key: c.entrevista_rrhh_fecha ? c.entrevista_rrhh_fecha + ' 12:00:00' : '0000-01-01 00:00:00',
                html: '<li><div class="t-fecha">'
                    + (c.entrevista_rrhh_fecha ? formatearSoloFecha(c.entrevista_rrhh_fecha) : 'sin fecha')
                    + ' · Entrevista de RRHH</div>'
                    + 'Resultado: ' + escHtml(resTxt)
                    + (c.entrevista_rrhh_observaciones ? '<div class="text-muted small">' + escHtml(c.entrevista_rrhh_observaciones) + '</div>' : '')
                    + '</li>'
            });
        }

        // Psicométrico (informativo).
        if (c.psicometrico_fecha || c.psicometrico_resultado || c.psicometrico_calificacion) {
            var psTxt = c.psicometrico_resultado === 'apto' ? 'Apto'
                      : c.psicometrico_resultado === 'no_apto' ? 'No apto' : '—';
            items.push({
                key: c.psicometrico_fecha ? c.psicometrico_fecha + ' 12:00:00' : '0000-01-01 00:00:00',
                html: '<li><div class="t-fecha">'
                    + (c.psicometrico_fecha ? formatearSoloFecha(c.psicometrico_fecha) : 'sin fecha')
                    + ' · Psicométrico</div>'
                    + 'Resultado: ' + escHtml(psTxt)
                    + (c.psicometrico_calificacion ? ' · Calificación: ' + escHtml(c.psicometrico_calificacion) : '')
                    + (c.psicometrico_observaciones ? '<div class="text-muted small">' + escHtml(c.psicometrico_observaciones) + '</div>' : '')
                    + '</li>'
            });
        }

        // Orden descendente (lo más reciente arriba). Las llaves son 'YYYY-MM-DD HH:MM:SS'
        // → la comparación lexicográfica coincide con la cronológica.
        items.sort(function (a, b) { return a.key < b.key ? 1 : (a.key > b.key ? -1 : 0); });

        h += '<h6 class="mt-3">Historial</h6><ul class="timeline">';
        items.forEach(function (it) { h += it.html; });
        h += '</ul>';
        return h;
    }

    // ── Entrevista del jefe (acciones que antes vivían en Seguimiento) ──
    $('#tablaCandidatos tbody').on('click', '.btnCita', function () {
        $('#formCita')[0].reset();
        $('#cita_id').val($(this).data('id'));
        $('#modalCita').modal('show');
    });
    $('#formCita').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray(); data.push({ name: 'accion', value: 'nueva_cita' });
        ajaxPost('acciones_proceso.php', data, function (err, res) {
            if (res && res.success) { $('#modalCita').modal('hide'); mostrarToast(res.message, 'success'); cargar(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    $('#tablaCandidatos tbody').on('click', '.btnConfirmar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Confirmar entrevista con el jefe',
            html: '<p class="small text-muted mb-2">¿Cuál de las dos opciones aceptó el candidato?</p>'
                + '<select id="sw_opcion" class="swal2-select">'
                + '<option value="">Selecciona la fecha</option>'
                + '<option value="1">Opción 1</option><option value="2">Opción 2</option></select>'
                + '<textarea id="sw_notas" class="swal2-textarea" placeholder="Comentarios (opcional)"></textarea>',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                var op = document.getElementById('sw_opcion').value;
                if (!op) { Swal.showValidationMessage('Selecciona una opción.'); return false; }
                return { opcion: op, notas: document.getElementById('sw_notas').value };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_proceso.php', {
                accion: 'confirmar_entrevista', id: id, opcion: r.value.opcion, notas: r.value.notas
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // El resultado de la entrevista del jefe lo captura ahora el propio jefe en su
    // portal (embed_solicitante.php → acciones_solicitante.php). RRHH ya no lo hace.

    cargarVacantes(cargar);
});
