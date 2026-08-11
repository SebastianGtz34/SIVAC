/* contrataciones.js — Cierre: propuesta, documentación y alta (RRHH). */
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var tabla = dtAutoAjustar($('#tablaCierre').DataTable({
        language: dtIdioma(), order: [], columnDefs: [{ orderable: false, targets: [4] }]
    }));

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
                if (c.estatus === 'entrevistado' && parseInt(c.requiere_propuesta) === 0) {
                    // Prácticas: se salta la propuesta económica y pasa directo
                    // a documentación.
                    acc += '<button class="btn btn-outline-primary btnDocDirecto" data-id="' + c.id + '"><i class="fas fa-forward mr-1"></i>Pasar a documentación</button>';
                } else if (c.estatus === 'entrevistado' || c.estatus === 'propuesta_expirada') {
                    acc += '<button class="btn btn-outline-primary btnPropuesta" data-id="' + c.id + '"><i class="fas fa-paper-plane mr-1"></i>Propuesta</button>';
                } else if (c.estatus === 'propuesta_enviada') {
                    acc += '<button class="btn btn-outline-success btnResp" data-id="' + c.id + '" data-r="aceptada"><i class="fas fa-check"></i></button>';
                    acc += '<button class="btn btn-outline-danger btnResp" data-id="' + c.id + '" data-r="rechazada"><i class="fas fa-times"></i></button>';
                } else if (c.estatus === 'documentacion') {
                    acc += '<button class="btn btn-outline-primary btnDocs" data-id="' + c.id + '" data-nombre="' + escHtml(c.nombre) + '"><i class="fas fa-folder-open mr-1"></i>Documentación</button>';
                    acc += '<button class="btn btn-outline-secondary btnEnlace" data-id="' + c.id + '" title="Copiar enlace del portal del candidato"><i class="fas fa-link"></i></button>';
                } else if (c.estatus === 'contratado') {
                    // Ya contratado: sólo consulta del expediente y de los datos que
                    // jala gestionPersonal (siguen siendo corregibles hasta que allá
                    // se aplique el alta).
                    acc += '<button class="btn btn-outline-secondary btnDocs" data-id="' + c.id + '" data-nombre="' + escHtml(c.nombre) + '" data-solo="1" title="Ver expediente y datos del alta"><i class="fas fa-id-card"></i></button>';
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
            tabla.columns.adjust();   // recomputa anchos tras poblar por AJAX
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

    // Prácticas: entrevistado → documentación (sin propuesta de por medio).
    $('#tablaCierre tbody').on('click', '.btnDocDirecto', function () {
        var id = $(this).data('id');
        confirmarAccion('El candidato de prácticas pasará a documentación con 15 días para entregar sus documentos.',
            function () {
                ajaxPost('acciones_cierre.php', { accion: 'iniciar_documentacion', id: id }, function (err, res) {
                    if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                    else { mostrarToast((res && res.message) || 'Error.', 'error'); }
                });
            },
            { titulo: '¿Pasar a documentación?', confirmar: 'Sí, continuar', icon: 'question' });
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
        // Modo consulta (candidato ya contratado): se esconde todo lo que mueve el
        // proceso; queda el expediente y los datos del alta.
        var soloDatos = String($(this).data('solo') || '') === '1';
        $('#bloqueGestionDocs').toggleClass('d-none', soloDatos);
        $('#btnCompletarAlta').toggleClass('d-none', soloDatos);
        $('#docs_id').val(docCandidato);
        $('#docsTitulo').text((soloDatos ? 'Expediente — ' : 'Documentación — ') + $(this).data('nombre'));
        $('#ingreso_fecha').val(''); $('#prorroga_fecha').val('');
        $('#formAvisosAlta').toggleClass('d-none', soloDatos);
        cargarFicha();
        cargarDatosAlta();
        cargarAreasAlta();
        $('#modalDocs').modal('show');
    });

    /**
     * Casillas de a qué áreas se avisa al completar el alta. Vienen del catálogo
     * (Configuración → Destinatarios), no del código. Un área sin correo cargado
     * se muestra deshabilitada y avisando por qué: si no, RRHH marca la casilla y
     * se queda creyendo que Nóminas recibió su correo.
     */
    function cargarAreasAlta() {
        $('#alta_viaticos, #alta_celular, #alta_equipo').prop('checked', false);
        ajaxPost('acciones_cierre.php', { accion: 'areas_alta' }, function (err, res) {
            var $c = $('#alta_areas').empty();
            if (err || !res || !res.success || !res.data.length) {
                $c.html('<span class="text-muted">No hay áreas configuradas.</span>'); return;
            }
            res.data.forEach(function (a) {
                var sinCorreo = !parseInt(a.tiene_correo, 10);
                $c.append(
                    '<div class="form-check form-check-inline">'
                    + '<input class="form-check-input chkAreaAlta" type="checkbox" value="' + escHtml(a.clave) + '"'
                    + ' id="area_' + escHtml(a.clave) + '"' + (sinCorreo ? ' disabled' : ' checked') + '>'
                    + '<label class="form-check-label' + (sinCorreo ? ' text-muted' : '') + '" for="area_' + escHtml(a.clave) + '">'
                    + escHtml(a.area) + (sinCorreo ? ' <em>(sin correo)</em>' : '') + '</label>'
                    + '</div>'
                );
            });
        });
    }

    // Genera y muestra el enlace del portal del candidato para copiarlo (el correo
    // está apagado en pruebas). Regenerar invalida el anterior.
    $('#tablaCierre tbody').on('click', '.btnEnlace', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_cierre.php', { accion: 'generar_enlace_portal', id: id }, function (err, res) {
            if (!res || !res.success) { mostrarToast((res && res.message) || 'No se pudo generar el enlace.', 'error'); return; }
            var url = res.url;
            Swal.fire({
                title: 'Enlace del portal del candidato',
                html: '<p class="small text-muted mb-2">' + escHtml(res.message) + '</p>'
                    + '<input id="sw_enlace" class="swal2-input" readonly value="' + escHtml(url) + '" style="font-size:.8rem">',
                showCancelButton: true, confirmButtonText: 'Copiar', cancelButtonText: 'Cerrar',
                confirmButtonColor: messColor('accent'), background: messColor('card-bg'), color: messColor('text'),
                didOpen: function () { var el = document.getElementById('sw_enlace'); if (el) el.select(); }
            }).then(function (r) {
                if (!r.isConfirmed) return;
                if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { mostrarToast('Enlace copiado.', 'success'); }); }
                else { mostrarToast('Copia el enlace manualmente.', 'info'); }
            });
        });
    });

    /** Badge del estado de validación de un documento. */
    function badgeValidacion(v) {
        if (v === 'validado')  return '<span class="badge badge-success">Validado</span>';
        if (v === 'rechazado') return '<span class="badge badge-danger">Rechazado</span>';
        return '<span class="badge badge-light">Pendiente</span>';
    }

    function cargarFicha() {
        ajaxPost('acciones_candidatos.php', { accion: 'detalle', id: docCandidato }, function (err, res) {
            if (err || !res || !res.success) return;
            var docs = res.documentos || [];
            var html = '';
            if (!docs.length) html = '<li class="list-group-item text-muted small">Sin documentos.</li>';
            docs.forEach(function (d) {
                var val = d.validacion || 'pendiente';
                var acc = '';
                if (val !== 'validado') acc += '<button class="btn btn-sm btn-outline-success btnValDoc" data-id="' + d.id + '" title="Validar"><i class="fas fa-check"></i></button> ';
                if (val !== 'rechazado') acc += '<button class="btn btn-sm btn-outline-warning btnRecDoc" data-id="' + d.id + '" title="Rechazar"><i class="fas fa-ban"></i></button> ';
                html += '<li class="list-group-item py-2">'
                    + '<div class="d-flex justify-content-between align-items-center">'
                    + '<div><div class="small fw-700">' + escHtml(d.tipo) + ' ' + badgeValidacion(val) + '</div>'
                    + '<a href="descargar.php?tipo=documento&id=' + d.id + '" target="_blank" class="small">' + escHtml(d.nombre_original) + '</a></div>'
                    + '<div class="btn-group btn-group-sm">' + acc + '</div></div>'
                    + (val === 'rechazado' && d.motivo_validacion
                        ? '<div class="text-danger small mt-1"><i class="fas fa-exclamation-circle mr-1"></i>' + escHtml(d.motivo_validacion) + '</div>'
                        : '')
                    + '</li>';
            });
            $('#listaDocs').html(html);
            $('#cierreInfo').html('');
        });
    }

    /** Trae lo que el candidato capturó en su portal y pinta qué falta. */
    function cargarDatosAlta() {
        ajaxPost('acciones_cierre.php', { accion: 'datos_alta', id: docCandidato }, function (err, res) {
            if (err || !res || !res.success) return;
            var d = res.datos || {};
            // El catálogo de tipo de sangre viene del servidor: es el de gestionPersonal.
            var catalogo = res.catalogo_sangre || [];
            var opts = '<option value="">Seleccionar…</option>';
            // Un valor viejo fuera del catálogo se conserva como opción, para no
            // borrarlo sin querer al volver a guardar.
            if (d.tipo_sangre && catalogo.indexOf(d.tipo_sangre) === -1) {
                opts += '<option value="' + escHtml(d.tipo_sangre) + '">' + escHtml(d.tipo_sangre) + ' (fuera de catálogo)</option>';
            }
            catalogo.forEach(function (s) {
                opts += '<option value="' + escHtml(s) + '">' + escHtml(s) + '</option>';
            });
            $('#da_sangre').html(opts);

            $('#da_curp').val(d.curp || '');
            $('#da_rfc').val(d.rfc || '');
            $('#da_nss').val(d.nss || '');
            $('#da_sexo').val(d.sexo || '');
            $('#da_fnac').val(d.fecha_nacimiento || '');
            $('#da_sangre').val(d.tipo_sangre || '');
            pintarFaltan(res.faltan || []);

            // Aplicada el alta allá, la fila ya se consumió: sólo lectura.
            var aplicada = parseInt(d.alta_aplicada || 0) === 1;
            $('#formDatosAlta').find('input, select, button').prop('disabled', aplicada);
            if (aplicada) {
                $('#datosAltaAviso').removeClass('d-none alert-warning').addClass('alert-success')
                    .html('<i class="fas fa-check mr-1"></i>El alta ya se aplicó en gestionPersonal.');
            }
        });
    }

    function pintarFaltan(faltan) {
        var $a = $('#datosAltaAviso').removeClass('alert-success').addClass('alert-warning');
        if (!faltan.length) {
            $a.removeClass('d-none').html('<i class="fas fa-check mr-1"></i>Datos completos para el alta.');
        } else {
            $a.removeClass('d-none').html('<i class="fas fa-exclamation-triangle mr-1"></i>Falta capturar: '
                + escHtml(faltan.join(', ')) + '.');
        }
    }

    $('#formDatosAlta').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'guardar_datos_alta' });
        data.push({ name: 'id', value: docCandidato });
        ajaxPost('acciones_cierre.php', data, function (err, res) {
            if (res && res.success) {
                pintarFaltan(res.faltan || []);
                mostrarToast(res.message, (res.faltan || []).length ? 'warning' : 'success');
            } else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    $('#listaDocs').on('click', '.btnValDoc', function () {
        var idDoc = $(this).data('id');
        ajaxPost('acciones_cierre.php', { accion: 'validar_documento', id_documento: idDoc, decision: 'validar' }, function (err, res) {
            if (res && res.success) { mostrarToast(res.message, 'success'); cargarFicha(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    $('#listaDocs').on('click', '.btnRecDoc', function () {
        var idDoc = $(this).data('id');
        Swal.fire({
            title: 'Rechazar documento', input: 'textarea', inputLabel: 'Motivo del rechazo',
            inputPlaceholder: 'Escribe el motivo…', showCancelButton: true,
            confirmButtonColor: messColor('danger'), background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Rechazar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_cierre.php', { accion: 'validar_documento', id_documento: idDoc, decision: 'rechazar', motivo: r.value }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'info'); cargarFicha(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
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
        var areas = $('.chkAreaAlta:checked').map(function () { return this.value; }).get();
        var nombres = $('.chkAreaAlta:checked').map(function () {
            return $('label[for="' + this.id + '"]').text().trim();
        }).get();

        var aviso = areas.length
            ? 'Se avisará a: <strong>' + escHtml(nombres.join(', ')) + '</strong>.'
            : '<strong>No se avisará a ninguna área.</strong>';
        confirmarAccion('Se completará el alta. ' + aviso
            + ' La vacante se cerrará sólo si con esta alta se cubren todas sus posiciones. ¿Continuar?', function () {
            ajaxPost('acciones_cierre.php', {
                accion: 'completar_alta', id: docCandidato,
                req_viaticos: $('#alta_viaticos').prop('checked') ? 1 : 0,
                req_celular:  $('#alta_celular').prop('checked')  ? 1 : 0,
                req_equipo:   $('#alta_equipo').prop('checked')   ? 1 : 0,
                areas: areas.join(',')
            }, function (err, res) {
                if (res && res.success) { $('#modalDocs').modal('hide'); mostrarToast(res.message, 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        }, { titulo: 'Completar alta', confirmar: 'Sí, dar de alta', icon: 'question' });
    });

    cargar();
});
