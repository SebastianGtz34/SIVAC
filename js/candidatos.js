/* candidatos.js — Módulo de candidatos (RRHH). */
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var tabla = dtAutoAjustar($('#tablaCandidatos').DataTable({
        language: dtIdioma(),
        order: [[0, 'asc']],
        columnDefs: [{ orderable: false, targets: [5, 6] }]
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
            if (err || !res || !res.success) { tabla.draw(); return; }
            res.data.forEach(function (c) {

                var sugerenciasDisponibles = [c.cita_jefe_op1, c.cita_jefe_op2].filter(Boolean).join(' ó ');

                var htmlEntrevista = c.cita_jefe_confirmada
                    ? '<br><b>Cita confirmada: <span class="badge badge-success">' + c.cita_jefe_confirmada + '</span>'
                    : (sugerenciasDisponibles ? '<br><b>Opciones Entrevista: <span class="badge badge-dark">' + sugerenciasDisponibles + '</span>' : '');
                // El candidato no pudo en ninguna: no hay nada que confirmar hasta
                // que el jefe proponga otras. Sin esta etiqueta la fila se quedaba
                // sin botón y sin explicación.
                if (c.estatus === 'aprobado_jefe' && !c.cita_jefe_pendiente && c.cita_jefe_ultima === 'rechazada') {
                    htmlEntrevista = '<br><span class="badge badge-warning">Esperando fechas nuevas del jefe</span>';
                }
                // El jefe pidió mover la fecha vigente: hasta el VoBo no se confirma
                // nada con el candidato (retro 2026-10-29).
                var porAprobar = !!c.cita_jefe_por_aprobar
                    && (c.estatus === 'aprobado_jefe' || c.estatus === 'entrevista_confirmada');
                if (porAprobar) {
                    htmlEntrevista += '<br><span class="badge badge-warning"><i class="fas fa-lock mr-1"></i>'
                        + 'El jefe pide cambiar a: ' + escHtml(c.cita_jefe_por_aprobar_ops || '') + '</span>';
                }

                // ¿El jefe ya abrió el CV? Lo marca descargar.php la primera vez.
                var cv = c.cv_archivo
                    ? '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fas fa-file-pdf"></i></a>'
                        + (c.cv_abierto_jefe
                            ? '<div class="small text-success mt-1" title="El jefe lo abrió por primera vez el ' + escHtml(formatearFecha(c.cv_abierto_jefe)) + '">'
                                + '<i class="fas fa-eye mr-1"></i>' + escHtml(formatearSoloFecha(c.cv_abierto_jefe)) + '</div>'
                            : '<div class="small text-muted mt-1" title="El jefe todavía no abre el CV"><i class="fas fa-eye-slash mr-1"></i>Sin abrir</div>')
                    : '<span class="text-muted">—</span>';
                // Acción contextual del pipeline (la que antes vivía en Seguimiento).
                var acc = '<div class="btn-group btn-group-sm">';
                if (porAprobar) {
                    // El candado: en lugar de «Confirmar», el VoBo del cambio.
                    acc += '<button class="btn btn-sm btn-warning btnVoboFechas" data-id="' + c.id + '"'
                        + ' data-ops="' + escHtml(c.cita_jefe_por_aprobar_ops || '') + '"'
                        + ' data-motivo="' + escHtml(c.cita_jefe_por_aprobar_motivo || '') + '"'
                        + ' data-vigente="' + escHtml(c.cita_jefe_confirmada ? formatearFecha(c.cita_jefe_confirmada) + ' (confirmada con el candidato)'
                            : [c.cita_jefe_op1, c.cita_jefe_op2].filter(Boolean).join(' ó ')) + '"'
                        + ' title="El jefe pidió cambiar las fechas"><i class="fas fa-lock"></i> VoBo cambio de fechas</button>';
                } else if (c.estatus === 'aprobado_jefe') {
                    if (c.cita_jefe_pendiente) {
                        // Las dos fechas viajan en el botón para que el diálogo de
                        // confirmación muestre horarios, no «Opción 1 / Opción 2».
                        acc += '<button class="btn btn-sm btn-outline-primary btnConfirmar" data-id="' + c.id + '"'
                            + ' data-op1="' + escHtml(c.cita_jefe_op1 || '') + '"'
                            + ' data-op2="' + escHtml(c.cita_jefe_op2 || '') + '"'
                            + ' title="Confirmar entrevista"><i class="fas fa-calendar-check"></i> Confirmar entrevista</button>';
                    }
                    //acc += '<button class="btn btn-outline-secondary btnCita" data-id="' + c.id + '" title="Agendar/reprogramar entrevista"><i class="fas fa-calendar-plus"></i> Agendar/reprogramar entrevista</button>';
                } else if (c.estatus === 'entrevistado' || c.estatus === 'propuesta_enviada'
                        || c.estatus === 'propuesta_expirada' || c.estatus === 'propuesta_aceptada'
                        || c.estatus === 'documentacion') {
                    acc += '<a class="btn btn-outline-primary" href="contrataciones.php" title="Ir a cierre (propuesta/documentación)"><i class="fas fa-file-signature"></i></a>';
                }
                acc += '<button class="btn btn-outline-secondary btnDetalle" data-id="' + c.id + '" title="Ver ficha"><i class="fas fa-eye"></i> Ver Ficha</button>'
                    + '<button class="btn btn-outline-secondary btnEditar" data-id="' + c.id + '" title="Editar"><i class="fas fa-edit"></i> Editar</button>';
                // Psicométrico (informativo): disponible mientras el proceso siga vivo.
                // ('aspirante' ya no se crea; queda sólo en fichas viejas.)
                if (c.estatus !== 'aspirante' && c.estatus !== 'descartado') {
                    acc += '<button class="btn btn-outline-info btnPsico" data-id="' + c.id + '" title="Psicométrico"><i class="fas fa-brain"></i>psicometrico/Otras pruebas</button>';
                }
                // Postular a otra vacante: disponible incluso si está descartado —ése
                // es justo el caso, «aquí no quedó pero sirve para la otra»—. No para
                // los ya contratados.
                if (c.estatus !== 'contratado') {
                    acc += '<button class="btn btn-outline-secondary btnCopiar" data-id="' + c.id + '" title="Postular a otra vacante"><i class="fas fa-share"></i> Otra vacante</button>';
                }
                if (c.estatus !== 'contratado' && c.estatus !== 'descartado') {
                    acc += '<button class="btn btn-sm btn-outline-danger btnDescartar" data-id="' + c.id + '" title="Descartar"><i class="fas fa-times"></i> Descartar</button>';
                }
                acc += '</div>';
                tabla.row.add([
                    escHtml(c.nombre),
                    escHtml(c.folio),
                    c.area ? escHtml(c.area) : '<span class="text-muted">—</span>',
                    '<div>' + escHtml(c.correo) + '</div><div class="text-muted small">' + escHtml(c.telefono || '') + '</div>',
                    badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus) + htmlEntrevista,
                    cv,
                    acc
                ]);
            });
            tabla.draw();
            tabla.columns.adjust();   // recomputa anchos tras poblar por AJAX
        });
    }

    $('#filtroVacante').on('change', cargar);
    $('#btnTodasVacantes').on('click', function () {
        $('#filtroVacante').val('');
        cargar();
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

    // ── Postular a la misma persona en otra vacante ──
    // Crea una ficha nueva (proceso aparte) reutilizando su CV y los documentos que
    // ya entregó. Sólo se ofrecen las vacantes que admiten candidatos.
    $('#tablaCandidatos tbody').on('click', '.btnCopiar', function () {
        var id = $(this).data('id');
        var opciones = vacantesCache.filter(function (v) {
            return v.estatus === 'abierta' || v.estatus === 'en_proceso';
        });
        if (!opciones.length) {
            mostrarToast('No hay vacantes abiertas a las que postularlo.', 'warning');
            return;
        }
        var sel = '<select id="sw_vac" class="swal2-select"><option value="">Selecciona la vacante…</option>';
        opciones.forEach(function (v) {
            sel += '<option value="' + v.id + '">' + escHtml(v.folio + ' — ' + v.puesto) + '</option>';
        });
        sel += '</select>';

        Swal.fire({
            title: 'Postular a otra vacante',
            html: '<p class="small text-muted mb-2">Se creará una ficha nueva para esta persona en la vacante que elijas. '
                + 'Su CV y los documentos que ya entregó <strong>se reutilizan</strong>: no se le vuelven a pedir. '
                + 'El proceso de cada vacante avanza por separado.</p>' + sel,
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Postular', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                var v = document.getElementById('sw_vac').value;
                if (!v) { Swal.showValidationMessage('Selecciona la vacante.'); return false; }
                return v;
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_candidatos.php', { accion: 'copiar_a_vacante', id: id, id_vacante: r.value },
                function (err, res) {
                    if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                    else { mostrarToast((res && res.message) || 'No se pudo postular.', 'error'); }
                });
        });
    });

    $('#tablaCandidatos tbody').on('click', '.btnDetalle', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_candidatos.php', { accion: 'detalle', id: id }, function (err, res) {
            // El mensaje del servidor se muestra tal cual: un «No se pudo cargar.»
            // a secas no distingue entre un id malo, un candidato borrado y una
            // columna que le falta al servidor, y esos se diagnostican distinto.
            if (err || !res || !res.success) {
                mostrarToast((res && res.message) || 'No se pudo cargar la ficha.', 'error');
                return;
            }
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
            if (err || !res || !res.success) {
                mostrarToast((res && res.message) || 'No se pudo cargar el psicométrico.', 'error');
                return;
            }
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
        // Descarte: el motivo y la etapa en que ocurrió. Es lo primero que se busca
        // al abrir la ficha de alguien que ya no sigue en el proceso, así que va
        // arriba y no enterrado al final del historial.
        if (c.estatus === 'descartado') {
            h += '<div class="alert alert-warning py-2 px-3 mb-3">'
                + '<div class="text-muted small">Descartado'
                + (c.etapa_descarte ? ' en la etapa: ' + escHtml(estatusS[c.etapa_descarte] || c.etapa_descarte) : '')
                + '</div>'
                + escHtml(c.motivo_descarte || 'Sin motivo registrado.')
                + '</div>';
        }
        if (c.cv_archivo) {
            h += '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-outline-primary mb-3"><i class="fas fa-file-pdf mr-1"></i> Ver CV</a>';
        }
        // Comentarios (como los de ticketsBI): internos o para el jefe. Van antes del
        // historial porque son lo que se viene a leer o a escribir.
        h += '<h6 class="mt-3">Comentarios</h6><div id="comentFicha" data-id="' + c.id + '">'
            + sivacHtmlComentarios(res.comentarios) + sivacFormComentario(true) + '</div>';

        // Timeline unificado y ORDENADO por fecha: filas del historial + constancia
        // de RRHH + psicométrico + fechas de la entrevista del jefe + cuándo abrió
        // el CV. Las constancias son DATE (sin hora); se les da las 12:00 como llave
        // neutra para desempatar contra los DATETIME del historial.
        var items = sivacItemsHistorial(res.historial, estatusS);

        if (c.cv_abierto_jefe) {
            items.push({
                key: c.cv_abierto_jefe,
                html: '<li><div class="t-fecha">' + formatearFecha(c.cv_abierto_jefe) + ' · Solicitante</div>'
                    + 'Abrió el CV por primera vez.</li>'
            });
        }

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

        // Fechas de la entrevista del jefe (retro 2026-10-26), con el VoBo de cada
        // cambio (retro 2026-10-29). Las pinta funciones.js: el jefe ve lo mismo.
        items = items.concat(sivacItemsCitas(res.citas, c.estatus));

        h += '<h6 class="mt-3">Historial</h6>' + sivacPintarTimeline(items);
        return h;
    }

    // Comentar desde la ficha. La respuesta trae el hilo actualizado: se repinta
    // sólo la lista, sin perder la posición del modal.
    $('#detalleBody').on('click', '.btnComentar', function () {
        var $box = $('#comentFicha');
        var texto = $box.find('.txtComentario').val().trim();
        if (!texto) { mostrarToast('Escribe el comentario.', 'warning'); return; }
        var interno = $box.find('input[name="coment_interno"]:checked').val() || '1';
        var $btn = $(this).prop('disabled', true);
        ajaxPost('acciones_candidatos.php', {
            accion: 'comentar', id: $box.data('id'), comentario: texto, es_interno: interno
        }, function (err, res) {
            $btn.prop('disabled', false);
            if (res && res.success) {
                $box.children('.coment-lista, p.text-muted').remove();
                $box.prepend(sivacHtmlComentarios(res.comentarios));
                $box.find('.txtComentario').val('');
                mostrarToast(res.message, 'success');
            } else { mostrarToast((res && res.message) || 'No se pudo guardar el comentario.', 'error'); }
        });
    });

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
            if (res && res.success) { $('#modalCita').modal('hide'); mostrarToast(res.message, res.aviso ? 'warning' : 'success'); cargar(); }
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
                + '<option value="2">' + escHtml(formatearFecha(op2)) + '</option>'
                + '<option value="otra">Ninguna le queda — pedir otras fechas al jefe</option></select>'
                // Sólo con «Ninguna»: la fecha que pidió el candidato, si dio una.
                + '<div id="sw_otra" style="display:none">'
                + '<p class="small text-muted mt-3 mb-1">¿Qué fecha pidió el candidato? (opcional; se le pasa al jefe)</p>'
                + '<input type="datetime-local" id="sw_fecha_cand" class="swal2-input">'
                + '</div>'
                + '<textarea id="sw_notas" class="swal2-textarea" placeholder="Comentarios (opcional)"></textarea>',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar',
            didOpen: function () {
                var sel = document.getElementById('sw_opcion');
                sel.addEventListener('change', function () {
                    var otra = sel.value === 'otra';
                    document.getElementById('sw_otra').style.display = otra ? '' : 'none';
                    Swal.getConfirmButton().textContent = otra ? 'Pedir al jefe' : 'Confirmar';
                });
            },
            preConfirm: function () {
                var op = document.getElementById('sw_opcion').value;
                if (!op) { Swal.showValidationMessage('Selecciona la fecha que aceptó el candidato.'); return false; }
                return { opcion: op, notas: document.getElementById('sw_notas').value,
                         fecha_candidato: document.getElementById('sw_fecha_cand').value };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            var datos = r.value.opcion === 'otra'
                ? { accion: 'sin_fecha_candidato', id: id, fecha_candidato: r.value.fecha_candidato, notas: r.value.notas }
                : { accion: 'confirmar_entrevista', id: id, opcion: r.value.opcion, notas: r.value.notas };
            ajaxPost('acciones_proceso.php', datos, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, res.aviso ? 'warning' : 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // ── VoBo al cambio de fechas que pidió el jefe (retro 2026-10-29) ──
    // «Enterado» aplica el cambio (la fecha vigente se cancela y las nuevas quedan
    // por confirmar con el candidato); «No aprobar» lo rechaza con motivo y sigue
    // la vigente. Hasta entonces la fila no ofrece «Confirmar entrevista».
    $('#tablaCandidatos tbody').on('click', '.btnVoboFechas', function () {
        var id = $(this).data('id');
        var ops = String($(this).data('ops') || '');
        var motivo = String($(this).data('motivo') || '');
        var vigente = String($(this).data('vigente') || '');
        Swal.fire({
            title: 'Cambio de fechas del jefe',
            html: '<div class="text-left small">'
                + (vigente ? '<p class="mb-1"><strong>Vigente:</strong> ' + escHtml(vigente) + '</p>' : '')
                + '<p class="mb-1"><strong>Pide cambiar a:</strong> ' + escHtml(ops) + '</p>'
                + (motivo ? '<p class="mb-2"><strong>Motivo:</strong> ' + escHtml(motivo) + '</p>' : '')
                + '<p class="text-muted mb-1">Con «Enterado» se cancela la fecha vigente y confirmas con el candidato '
                + 'una de las nuevas. Si no lo apruebas, sigue la vigente y el jefe ve tu motivo.</p></div>'
                + '<textarea id="sw_vobo_motivo" class="swal2-textarea" placeholder="Motivo (obligatorio si no lo apruebas)"></textarea>',
            showCancelButton: true, showDenyButton: true,
            confirmButtonColor: messColor('accent'), denyButtonColor: messColor('danger'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Enterado · dar VoBo', denyButtonText: 'No aprobar', cancelButtonText: 'Cancelar',
            preDeny: function () {
                var m = document.getElementById('sw_vobo_motivo').value.trim();
                if (!m) { Swal.showValidationMessage('Indica por qué no se aprueba: se le dice al jefe.'); return false; }
                return m;
            }
        }).then(function (r) {
            if (!r.isConfirmed && !r.isDenied) return;
            ajaxPost('acciones_proceso.php', {
                accion: 'vobo_cambio_fechas', id: id,
                decision: r.isConfirmed ? 'aprobar' : 'rechazar',
                motivo: r.isDenied ? r.value : ''
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
