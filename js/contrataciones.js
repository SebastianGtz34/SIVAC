/* contrataciones.js — Cierre: propuesta, documentación y alta (RRHH). */
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var tiposDoc = [];
    var tabla = $('#tablaCierre').DataTable({
        language: dtIdioma(), order: [], columnDefs: [{ orderable: false, targets: [4] }]
    });

    function cargarTipos(cb) {
        ajaxPost('acciones_cierre.php', { accion: 'tipos_documento' }, function (err, res) {
            if (!err && res && res.success) {
                tiposDoc = res.data;
                var opts = '<option value="">Tipo de documento…</option>';
                res.data.forEach(function (t) {
                    opts += '<option value="' + t.id + '">' + escHtml(t.nombre) + (parseInt(t.obligatorio) ? ' *' : '') + '</option>';
                });
                $('#doc_tipo').html(opts);
            }
            if (cb) cb();
        });
    }

    function cargar() {
        ajaxPost('acciones_cierre.php', { accion: 'listar' }, function (err, res) {
            tabla.clear();
            if (err || !res || !res.success) { tabla.draw(); return; }
            res.data.forEach(function (c) {
                var detalle = '';
                if (c.estatus === 'propuesta_enviada' && c.caducidad) {
                    detalle = '<span class="text-warning">Caduca ' + formatearSoloFecha(c.caducidad) + '</span>';
                } else if (c.estatus === 'documentacion') {
                    detalle = 'Ingreso: ' + (c.fecha_ingreso ? formatearSoloFecha(c.fecha_ingreso) : '—')
                        + '<br>Límite docs: ' + (c.fecha_limite_documentos ? formatearSoloFecha(c.fecha_limite_documentos) : '—')
                        + (parseInt(c.prorrogas) ? ' <span class="badge badge-light">' + c.prorrogas + ' prórroga(s)</span>' : '');
                } else if (c.estatus === 'contratado') {
                    detalle = '<span class="text-success">Ingreso ' + (c.fecha_ingreso ? formatearSoloFecha(c.fecha_ingreso) : '') + '</span>';
                }

                var acc = '<div class="btn-group btn-group-sm">';
                if (c.estatus === 'entrevistado' || c.estatus === 'propuesta_expirada') {
                    acc += '<button class="btn btn-outline-primary btnPropuesta" data-id="' + c.id + '"><i class="fas fa-paper-plane mr-1"></i>Propuesta</button>';
                } else if (c.estatus === 'propuesta_enviada') {
                    acc += '<button class="btn btn-outline-success btnResp" data-id="' + c.id + '" data-r="aceptada"><i class="fas fa-check"></i></button>';
                    acc += '<button class="btn btn-outline-danger btnResp" data-id="' + c.id + '" data-r="rechazada"><i class="fas fa-times"></i></button>';
                } else if (c.estatus === 'documentacion') {
                    acc += '<button class="btn btn-outline-primary btnDocs" data-id="' + c.id + '" data-nombre="' + escHtml(c.nombre) + '"><i class="fas fa-folder-open mr-1"></i>Documentación</button>';
                }
                acc += '</div>';

                tabla.row.add([
                    escHtml(c.nombre) + '<div class="text-muted small">' + escHtml(c.correo) + '</div>',
                    escHtml(c.folio) + '<div class="text-muted small">' + escHtml(c.puesto) + '</div>',
                    badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus),
                    detalle, acc
                ]);
            });
            tabla.draw();
        });
    }

    $('#btnExpirar').on('click', function () {
        ajaxPost('acciones_cierre.php', { accion: 'expirar_propuestas' }, function (err, res) {
            if (res && res.success) { mostrarToast(res.message, 'info'); cargar(); }
        });
    });

    // Propuesta
    $('#tablaCierre tbody').on('click', '.btnPropuesta', function () {
        $('#formPropuesta')[0].reset(); $('#prop_id').val($(this).data('id')); $('#modalPropuesta').modal('show');
    });
    $('#formPropuesta').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray(); data.push({ name: 'accion', value: 'enviar_propuesta' });
        ajaxPost('acciones_cierre.php', data, function (err, res) {
            if (res && res.success) { $('#modalPropuesta').modal('hide'); mostrarToast(res.message, 'success'); cargar(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    // Respuesta de propuesta
    $('#tablaCierre tbody').on('click', '.btnResp', function () {
        var id = $(this).data('id'); var r = $(this).data('r');
        var txt = r === 'aceptada' ? 'Marcar la propuesta como ACEPTADA por el candidato.' : 'Marcar la propuesta como RECHAZADA (descarta al candidato).';
        confirmarAccion(txt, function () {
            ajaxPost('acciones_cierre.php', { accion: 'responder_propuesta', id: id, respuesta: r }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        }, { titulo: r === 'aceptada' ? 'Aceptar propuesta' : 'Rechazar propuesta', icon: 'question' });
    });

    // Documentación
    var docCandidato = 0;
    $('#tablaCierre tbody').on('click', '.btnDocs', function () {
        docCandidato = $(this).data('id');
        $('#docs_id').val(docCandidato);
        $('#docsTitulo').text('Documentación — ' + $(this).data('nombre'));
        $('#ingreso_fecha').val(''); $('#prorroga_fecha').val('');
        cargarFicha();
        $('#modalDocs').modal('show');
    });

    function cargarFicha() {
        ajaxPost('acciones_candidatos.php', { accion: 'detalle', id: docCandidato }, function (err, res) {
            if (err || !res || !res.success) return;
            var docs = res.documentos || [];
            var html = '';
            if (!docs.length) html = '<li class="list-group-item text-muted small">Sin documentos.</li>';
            docs.forEach(function (d) {
                html += '<li class="list-group-item d-flex justify-content-between align-items-center py-2">'
                    + '<div><div class="small fw-700">' + escHtml(d.tipo) + '</div>'
                    + '<a href="descargar.php?tipo=documento&id=' + d.id + '" target="_blank" class="small">' + escHtml(d.nombre_original) + '</a></div>'
                    + '<button class="btn btn-sm btn-outline-danger btnDelDoc" data-id="' + d.id + '"><i class="fas fa-trash"></i></button></li>';
            });
            $('#listaDocs').html(html);
            var ci = res.data;
            var info = [];
            if (ci.psicometrico_folio) info.push('Psicométrico: ' + escHtml(ci.psicometrico_folio));
            $('#cierreInfo').html(info.join(' · '));
        });
    }

    $('#formDoc').on('submit', function (e) {
        e.preventDefault();
        if (!$('#doc_tipo').val()) { mostrarToast('Selecciona el tipo de documento.', 'warning'); return; }
        var fd = new FormData();
        fd.append('accion', 'subir_documento');
        fd.append('id', docCandidato);
        fd.append('id_tipo', $('#doc_tipo').val());
        if ($('#doc_archivo')[0].files[0]) fd.append('documento', $('#doc_archivo')[0].files[0]);
        ajaxUpload('acciones_cierre.php', fd, function (err, res) {
            if (res && res.success) { mostrarToast('Documento subido.', 'success'); $('#formDoc')[0].reset(); cargarFicha(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    $('#listaDocs').on('click', '.btnDelDoc', function () {
        var idDoc = $(this).data('id');
        confirmarAccion('¿Eliminar este documento?', function () {
            ajaxPost('acciones_cierre.php', { accion: 'eliminar_documento', id_documento: idDoc }, function (err, res) {
                if (res && res.success) { cargarFicha(); } else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    $('#btnIngreso').on('click', function (e) {
        e.preventDefault();
        if (!$('#ingreso_fecha').val()) { mostrarToast('Selecciona la fecha.', 'warning'); return; }
        ajaxPost('acciones_cierre.php', { accion: 'registrar_fecha_ingreso', id: docCandidato, fecha_ingreso: $('#ingreso_fecha').val() }, function (err, res) {
            mostrarToast((res && res.message) || 'Error.', res && res.success ? 'success' : 'error');
        });
    });

    $('#btnProrroga').on('click', function (e) {
        e.preventDefault();
        if (!$('#prorroga_fecha').val()) { mostrarToast('Selecciona la fecha.', 'warning'); return; }
        ajaxPost('acciones_cierre.php', { accion: 'prorroga_documentos', id: docCandidato, fecha_limite: $('#prorroga_fecha').val() }, function (err, res) {
            mostrarToast((res && res.message) || 'Error.', res && res.success ? 'success' : 'error');
            if (res && res.success) cargar();
        });
    });

    $('#btnReglamento').on('click', function (e) {
        e.preventDefault();
        ajaxPost('acciones_cierre.php', { accion: 'enviar_reglamento', id: docCandidato }, function (err, res) {
            mostrarToast((res && res.message) || 'Error.', res && res.success ? 'success' : 'error');
        });
    });

    $('#btnCompletarAlta').on('click', function (e) {
        e.preventDefault();
        confirmarAccion('Se completará el alta, se cerrará la vacante y se enviarán los avisos a las áreas. ¿Continuar?', function () {
            ajaxPost('acciones_cierre.php', { accion: 'completar_alta', id: docCandidato }, function (err, res) {
                if (res && res.success) { $('#modalDocs').modal('hide'); mostrarToast(res.message, 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        }, { titulo: 'Completar alta', confirmar: 'Sí, dar de alta', icon: 'question' });
    });

    cargarTipos(cargar);
});
