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

    function cargarCatalogos() {
        // Naves y regiones (mess_rrhh) para el form de candidato.
        ajaxPost('acciones_candidatos.php', { accion: 'catalogos' }, function (err, res) {
            if (err || !res || !res.success) return;
            var optNave = '<option value="">Sin asignar</option>';
            (res.naves || []).forEach(function (n) {
                optNave += '<option value="' + n.id + '">' + escHtml(n.nave) + '</option>';
            });
            $('#cand_nave').html(optNave);
            var optReg = '<option value="">Sin asignar</option>';
            (res.regiones || []).forEach(function (r) {
                optReg += '<option value="' + r.id + '">' + escHtml(r.region) + '</option>';
            });
            $('#cand_region').html(optReg);
        });
    }

    /**
     * Etiqueta de contexto: deja claro de QUÉ vacante son los candidatos de la
     * tabla. Sin ella el único indicio era el select de la esquina, que se
     * perdía al llegar desde Vacantes con ?vacante=N.
     */
    function pintarEtiquetaVacante() {
        var idv = $('#filtroVacante').val() || '';
        var $et = $('#etiquetaVacante');
        if (!idv) {
            $et.addClass('d-none');
            $('#tituloTablaCandidatos').text('Candidatos');
            return;
        }
        var v = vacantesCache.filter(function (x) { return String(x.id) === String(idv); })[0];
        if (!v) { $et.addClass('d-none'); return; }   // catálogo aún sin cargar
        var estatusS_VAC = window.SIVAC_estatusS_VAC || {};
        $('#etiquetaVacantePuesto').text(v.puesto);
        $('#etiquetaVacanteBadge').html(badgeestatusVacante(v.estatus, estatusS_VAC[v.estatus] || v.estatus));
        $('#etiquetaVacanteFolio').text(
            v.folio + ' · ' + ((window.SIVAC_TIPOS_VACANTE && window.SIVAC_TIPOS_VACANTE[v.tipo]) || v.tipo)
        );
        $('#tituloTablaCandidatos').text('Candidatos de ' + v.folio);
        $et.removeClass('d-none');
    }

    function cargar() {
        var idv = $('#filtroVacante').val() || '';
        pintarEtiquetaVacante();
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
                        // Las dos fechas viajan en el botón para que el diálogo de
                        // confirmación muestre horarios, no «Opción 1 / Opción 2».
                        acc += '<button class="btn btn-outline-primary btnConfirmar" data-id="' + c.id + '"'
                            + ' data-op1="' + escHtml(c.cita_jefe_op1 || '') + '"'
                            + ' data-op2="' + escHtml(c.cita_jefe_op2 || '') + '"'
                            + ' title="Confirmar entrevista"><i class="fas fa-calendar-check"></i></button>';
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
    $('#btnTodasVacantes').on('click', function () {
        $('#filtroVacante').val('');
        cargar();
    });
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

    /** Modal de registro en blanco, con la vacante del contexto ya puesta si la hay. */
    function abrirModalNuevo() {
        $('#formCandidato')[0].reset();
        $('#cand_id').val('');
        setResultadoRrhh('');
        $('#grupoVacante,#grupoCv').show();
        $('#cand_cv').prop('required', true);
        $('#cand_vacante').prop('required', true);
        $('#modalCandidatoTitulo').text('Nuevo candidato');
        if (window.VACANTE_PRE) $('#cand_vacante').val(window.VACANTE_PRE);
        $('#modalCandidato').modal('show');
    }

    $('#btnNuevoCandidato').on('click', abrirModalNuevo);

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
            $('#cand_nave').val(c.nave || '');
            $('#cand_region').val(c.region || '');
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
        var id  = $(this).data('id');
        // Fechas que registró el jefe: se muestran tal cual para que RRHH elija
        // por horario y no tenga que adivinar cuál era la «opción 1».
        var op1 = $(this).data('op1');
        var op2 = $(this).data('op2');
        Swal.fire({
            title: 'Confirmar entrevista con el jefe',
            html: '<p class="small text-muted mb-2">¿Cuál de las dos fechas aceptó el candidato?</p>'
                + '<select id="sw_opcion" class="swal2-select">'
                + '<option value="">Selecciona la fecha</option>'
                + '<option value="1">' + escHtml(formatearFecha(op1)) + '</option>'
                + '<option value="2">' + escHtml(formatearFecha(op2)) + '</option></select>'
                + '<textarea id="sw_notas" class="swal2-textarea" placeholder="Comentarios (opcional)"></textarea>',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                var op = document.getElementById('sw_opcion').value;
                if (!op) { Swal.showValidationMessage('Selecciona la fecha que aceptó el candidato.'); return false; }
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

    cargarVacantes(function () {
        cargar();
        // Llegar desde «Ver candidatos» es ir a registrar a alguien para ESA
        // vacante: el modal se abre solo, ya filtrado y con la vacante puesta.
        // Sólo si sigue admitiendo candidatos —una vacante cerrada o pausada no
        // está en el select del formulario y abriría el modal sin vacante—.
        if (window.VACANTE_PRE && $('#cand_vacante option[value="' + window.VACANTE_PRE + '"]').length) {
            abrirModalNuevo();
        }
    });
    cargarCatalogos();
});
