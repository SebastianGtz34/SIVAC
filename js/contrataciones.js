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
                    acc += '<button class="btn btn-outline-primary btnDocDirecto" data-id="' + c.id + '"><i class="fas fa-forward mr-1"></i> Pasar a documentación</button>';
                } else if (c.estatus === 'entrevistado' || c.estatus === 'propuesta_expirada') {
                    acc += '<button class="btn btn-outline-primary btnPropuesta" data-id="' + c.id + '"><i class="fas fa-paper-plane mr-1"></i> Propuesta</button>';
                } else if (c.estatus === 'propuesta_enviada') {
                    acc += '<button class="btn btn-outline-success btnResp" data-id="' + c.id + '" data-r="aceptada"><i class="fas fa-check"></i></button>';
                    acc += '<button class="btn btn-outline-danger btnResp" data-id="' + c.id + '" data-r="rechazada"><i class="fas fa-times"></i></button>';
                } else if (c.estatus === 'documentacion') {
                    acc += '<button class="btn btn-outline-primary btnDocs" data-id="' + c.id + '" data-nombre="' + escHtml(c.nombre) + '"><i class="fas fa-folder-open mr-1"></i> Documentación</button>';
                    acc += '<button class="btn btn-outline-secondary btnEnlace" data-id="' + c.id + '" title="Ver o generar el enlace del portal del candidato"><i class="fas fa-link"></i> Enlace Portal Candidato</button>';
                } else if (c.estatus === 'contratado') {
                    // Ya contratado: sólo consulta del expediente y de los datos que
                    // jala gestionPersonal (siguen siendo corregibles hasta que allá
                    // se aplique el alta).
                    acc += '<button class="btn btn-outline-secondary btnDocs" data-id="' + c.id + '" data-nombre="' + escHtml(c.nombre) + '" data-solo="1" title="Ver expediente y datos del alta"><i class="fas fa-id-card"></i> Ver expediente</button>';
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
    var docSoloDatos = false;   // candidato ya contratado: modo consulta + reenvío
    $('#tablaCierre tbody').on('click', '.btnDocs', function () {
        docCandidato = $(this).data('id');
        // Modo consulta (candidato ya contratado): se esconde todo lo que mueve el
        // proceso; queda el expediente, los datos del alta y el REENVÍO de avisos
        // —lo único que sigue teniendo sentido hacer sobre un alta ya cerrada—.
        docSoloDatos = String($(this).data('solo') || '') === '1';
        $('#bloqueGestionDocs').toggleClass('d-none', docSoloDatos);
        $('#btnCompletarAlta').toggleClass('d-none', docSoloDatos);
        $('#btnReenviarAvisos').toggleClass('d-none', !docSoloDatos);
        $('#avisoReenvio').toggleClass('d-none', !docSoloDatos);
        // Los requerimientos se decidieron al completar el alta: se ven, no se tocan.
        $('#bloqueRequerimientos').find('input').prop('disabled', docSoloDatos);
        $('#docs_id').val(docCandidato);
        $('#docsTitulo').text((docSoloDatos ? 'Expediente — ' : 'Documentación — ') + $(this).data('nombre'));
        $('#ingreso_fecha').val(''); 
        $('#prorroga_fecha').val('');
        $('#resumenFechas, #limiteActual').empty();   // no dejar a la vista las fechas del candidato anterior
        $('#prorroga_fecha').removeAttr('min');
        limiteDocs = null;
        $('#formAvisosAlta').removeClass('d-none');
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
        // En modo consulta los requerimientos los pinta cargarDatosAlta() con lo
        // que quedó guardado; limpiarlos aquí sería una carrera entre dos AJAX.
        if (!docSoloDatos) $('#alta_viaticos, #alta_celular, #alta_equipo').prop('checked', false);
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

    /** Copia al portapapeles avisando por toast (el navegador puede negarlo). */
    function copiarEnlace(url) {
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { mostrarToast('Enlace copiado.', 'success'); }); }
        else { mostrarToast('Copia el enlace manualmente.', 'info'); }
    }

    /** Pide un enlace nuevo (invalida el anterior) y lo muestra para copiarlo. */
    function generarEnlaceNuevo(id) {
        ajaxPost('acciones_cierre.php', { accion: 'enlace_portal', id: id, modo: 'nuevo' }, function (err, res) {
            if (!res || !res.success) { mostrarToast((res && res.message) || 'No se pudo generar el enlace.', 'error'); return; }
            mostrarEnlace(id, res.url, res.message, res.expira, false, res.pass, null, res.limite_docs);
        });
    }

    /**
     * Enlace del portal del candidato. El botón muestra el que YA tiene (repetirlo
     * no le rompe nada) y deja generar uno nuevo aparte, que sí invalida el viejo:
     * antes cualquier clic regeneraba y el candidato se quedaba a medias.
     */
    /**
     * Contraseña nueva para el MISMO enlace. Se cierra el diálogo antes de
     * confirmar para no anidar dos SweetAlert, y al terminar se vuelve a abrir
     * ya con la clave nueva a la vista.
     */
    function restablecerPass(id, url, expira) {
        Swal.close();
        confirmarAccion('Se le genera una contraseña nueva. El enlace y lo que ya entregó '
            + '<strong>no se tocan</strong>, pero si tiene el portal abierto se le cerrará la sesión.',
            function () {
                ajaxPost('acciones_cierre.php', { accion: 'restablecer_pass', id: id }, function (err, res) {
                    if (!res || !res.success) { mostrarToast((res && res.message) || 'No se pudo restablecer.', 'error'); return; }
                    mostrarEnlace(id, url, res.message, res.expira || expira, true, res.pass, null, res.limite_docs);
                });
            },
            { titulo: 'Restablecer contraseña', confirmar: 'Sí, restablecer', icon: 'warning' });
    }

    function mostrarEnlace(id, url, mensaje, expira, esVigente, pass, tienePass, limite) {
        // La fecha límite de documentos va aquí porque RRHH se la dice al
        // candidato en el mismo mensaje que el enlace. No confundir con `expira`,
        // que es hasta cuándo sirve el enlace.
        var bloqueLimite = '<div class="alert alert-info small py-2 mx-2 mb-2">'
            + '<i class="fas fa-calendar-check mr-1"></i>Fecha límite para entregar documentos: '
            + (limite ? '<strong>' + escHtml(limite) + '</strong>' : '<em>sin registrar</em>')
            + '</div>';

        // Desde la retro del 2026-10-06 la contraseña se puede volver a consultar,
        // igual que el enlace. Se sigue insistiendo en que viaje en un mensaje
        // APARTE del enlace — son dos factores y mandarlos juntos los convierte en uno.
        var bloquePass = '';
        if (pass) {
            bloquePass = '<div class="sw-pass">'
                + '<div class="sw-pass-etq">Contraseña del candidato</div>'
                + '<div class="sw-pass-valor">' + escHtml(pass) + '</div>'
                + '<div class="sw-pass-nota">Mándasela en un <strong>mensaje aparte</strong> del enlace. '
                + 'La puedes volver a consultar aquí mientras el enlace esté vigente.</div>'
                + '</div>';
        } else if (esVigente) {
            // Contraseñas anteriores al 2026-10-06: sólo existe su hash.
            bloquePass = parseInt(tienePass, 10)
                ? '<p class="small text-muted mt-2 mb-0"><i class="fas fa-lock mr-1"></i>'
                + 'Esta contraseña es de antes de que se guardaran y no se puede mostrar. '
                + 'Restablécela para poder consultarla de aquí en adelante.</p>'
                : '<p class="small text-muted mt-2 mb-0"><i class="fas fa-lock-open mr-1"></i>'
                + 'Este enlace es anterior a la contraseña: abre sin ella.</p>';
        }

        var botonReset = '<div class="mt-3"><button type="button" id="sw_reset" '
            + 'class="btn btn-sm btn-outline-secondary"><i class="fas fa-key mr-1"></i>'
            + 'Restablecer contraseña</button></div>';

        // Input-group ancho con botón de copiar integrado al lado derecho
        var inputConBoton = '<div class="input-group my-3 px-2">'
            + '<input id="sw_enlace" type="text" class="form-control" readonly value="' + escHtml(url) + '" style="font-size:.85rem">'
            + '<div class="input-group-append">'
            + '<button class="btn btn-primary" type="button" id="sw_copiar"><i class="fas fa-copy mr-1"></i>Copiar</button>'
            + '</div>'
            + '</div>';

        Swal.fire({
            title: 'Enlace del portal del candidato',
            html: '<p class="small text-muted mb-2">' + escHtml(mensaje)
                + (expira ? ' El enlace vence el <strong>' + escHtml(expira) + '</strong>.' : '') + '</p>'
                + inputConBoton
                + bloqueLimite
                + bloquePass + botonReset,
            showCancelButton: true,
            showDenyButton: esVigente,
            showConfirmButton: false, // Ocultamos el confirm ya que la acción copiar está inline
            denyButtonText: 'Generar uno nuevo',
            cancelButtonText: 'Cerrar',
            background: messColor('card-bg'), color: messColor('text'),
            didOpen: function () {
                var el = document.getElementById('sw_enlace'); 
                if (el) el.select();

                var btnCopiar = document.getElementById('sw_copiar');
                if (btnCopiar) {
                    btnCopiar.addEventListener('click', function () {
                        copiarEnlace(url);
                    });
                }

                var b = document.getElementById('sw_reset');
                if (b) b.addEventListener('click', function () { restablecerPass(id, url, expira); });
            }
        }).then(function (r) {
            if (r.isDenied) {
                confirmarAccion('El enlace que ya tiene el candidato dejará de funcionar y habrá que mandarle el nuevo.',
                    function () { generarEnlaceNuevo(id); },
                    { titulo: '¿Generar un enlace nuevo?', confirmar: 'Sí, generar', icon: 'warning' });
            }
        });
    }

    $('#tablaCierre tbody').on('click', '.btnEnlace', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_cierre.php', { accion: 'enlace_portal', id: id }, function (err, res) {
            if (!res || !res.success) { mostrarToast((res && res.message) || 'No se pudo consultar el enlace.', 'error'); return; }
            // Sin enlace vigente (o con uno viejo que ya no se puede mostrar): lo
            // único que queda es generar. Se pregunta sólo cuando hay algo que romper.
            if (!parseInt(res.vigente, 10)) { generarEnlaceNuevo(id); return; }
            if (parseInt(res.sin_token, 10)) {
                // El mensaje viene del servidor y es texto, no formato: se escapa
                // porque confirmarAccion pinta HTML.
                confirmarAccion(escHtml(res.message) + ' Generar uno nuevo invalidará el que tenga el candidato.',
                    function () { generarEnlaceNuevo(id); },
                    { titulo: 'Generar un enlace nuevo', confirmar: 'Sí, generar', icon: 'warning' });
                return;
            }
            mostrarEnlace(id, res.url, res.message, res.expira, true, res.pass, res.tiene_pass, res.limite_docs);
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
            pintarFechas(res.contratacion || {});

            // Reenvío: los requerimientos son los que se mandaron en su momento.
            if (docSoloDatos) {
                var ct = res.contratacion || {};
                $('#alta_viaticos').prop('checked', parseInt(ct.req_viaticos, 10) === 1);
                $('#alta_celular').prop('checked',  parseInt(ct.req_celular, 10)  === 1);
                $('#alta_equipo').prop('checked',   parseInt(ct.req_equipo, 10)   === 1);
            }

            // Aplicada el alta allá, la fila ya se consumió: sólo lectura.
            var aplicada = parseInt(d.alta_aplicada || 0) === 1;
            $('#formDatosAlta').find('input, select, button').prop('disabled', aplicada);
            if (aplicada) {
                $('#datosAltaAviso').removeClass('d-none alert-warning').addClass('alert-success')
                    .html('<i class="fas fa-check mr-1"></i>El alta ya se aplicó en gestionPersonal.');
            }
        });
    }

    /**
     * Las dos fechas del trámite, juntas y con el aviso cuando la entrega de
     * documentos vence después del ingreso. Además precarga la fecha de ingreso
     * ya registrada: el campo salía vacío y parecía que no se había guardado.
     */
    function pintarFechas(ct) {
        if (ct.fecha_ingreso) $('#ingreso_fecha').val(ct.fecha_ingreso);
        var partes = 'Ingreso: <strong>' + (ct.fecha_ingreso ? formatearSoloFecha(ct.fecha_ingreso) : '—') + '</strong>'
            + ' · Documentos hasta: <strong>' + (ct.fecha_limite_documentos ? formatearSoloFecha(ct.fecha_limite_documentos) : '—') + '</strong>'
            + (parseInt(ct.prorrogas, 10) ? ' (' + ct.prorrogas + ' prórroga(s))' : '');
        $('#resumenFechas').html(
            '<div class="text-muted">' + partes + '</div>'
            + (ct.aviso ? '<div class="text-warning mt-1"><i class="fas fa-exclamation-triangle mr-1"></i>' + escHtml(ct.aviso) + '</div>' : '')
        );
        pintarLimiteActual(ct);
    }

    /** Fecha límite vigente, encima del campo de prórroga. */
    var limiteDocs = null;   // 'YYYY-MM-DD' vigente, para el diálogo de confirmación
    function pintarLimiteActual(ct) {
        limiteDocs = ct.fecha_limite_documentos ? String(ct.fecha_limite_documentos).substring(0, 10) : null;
        var $p = $('#prorroga_fecha');
        if (!limiteDocs) {
            $('#limiteActual').html('<span class="text-muted">Sin fecha límite registrada.</span>');
            $p.removeAttr('min');
            return;
        }
        // Días contra HOY en hora local: se arma la fecha por partes porque
        // new Date('YYYY-MM-DD') la toma como UTC y en México resta un día.
        var p = limiteDocs.split('-');
        var limite = new Date(+p[0], +p[1] - 1, +p[2]);
        var hoy = new Date(); hoy.setHours(0, 0, 0, 0);
        var dias = Math.round((limite - hoy) / 86400000);
        var estado = dias > 1 ? 'faltan ' + dias + ' días'
            : dias === 1 ? 'vence mañana'
            : dias === 0 ? 'vence hoy'
            : 'venció hace ' + (-dias) + (dias === -1 ? ' día' : ' días');
        var clase = dias < 0 ? 'text-danger' : (dias <= 2 ? 'text-warning' : 'text-muted');
        var prorrogas = parseInt(ct.prorrogas, 10);
        $('#limiteActual').html(
            '<span class="' + clase + '">Vigente: <strong>' + formatearSoloFecha(limiteDocs) + '</strong> · ' + estado + '</span>'
            + (prorrogas ? ' <span class="badge badge-light">' + prorrogas + ' prórroga(s)</span>' : '')
        );
        // El backend rechaza una fecha igual o anterior; el calendario ya no la ofrece.
        var minimo = new Date(limite); minimo.setDate(minimo.getDate() + 1);
        var pad = function (n) { return String(n).padStart(2, '0'); };
        $p.attr('min', minimo.getFullYear() + '-' + pad(minimo.getMonth() + 1) + '-' + pad(minimo.getDate()));
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

    /** Toast del resultado: amarillo si el backend devolvió aviso (guardó igual). */
    function toastFechas(res) {
        if (!res || !res.success) { mostrarToast((res && res.message) || 'Error.', 'error'); return; }
        mostrarToast(res.message, res.aviso ? 'warning' : 'success');
    }

    $('#btnIngreso').on('click', function (e) {
        e.preventDefault();
        if (!$('#ingreso_fecha').val()) { mostrarToast('Selecciona la fecha.', 'warning'); return; }
        ajaxPost('acciones_cierre.php', { accion: 'registrar_fecha_ingreso', id: docCandidato, fecha_ingreso: $('#ingreso_fecha').val() }, function (err, res) {
            toastFechas(res);
            if (res && res.success) { cargarDatosAlta(); cargar(); }
        });
    });

    $('#btnProrroga').on('click', function (e) {
        e.preventDefault();
        var nueva = $('#prorroga_fecha').val();
        if (!nueva) { mostrarToast('Elige en el calendario la nueva fecha límite.', 'warning'); return; }
        confirmarAccion(
            'La fecha límite para entregar documentos pasa '
            + (limiteDocs ? 'del <strong>' + formatearSoloFecha(limiteDocs) + '</strong> ' : '')
            + 'al <strong>' + formatearSoloFecha(nueva) + '</strong>.<br>Al candidato se le avisa por correo.',
            function () {
                ajaxPost('acciones_cierre.php', { accion: 'prorroga_documentos', id: docCandidato, fecha_limite: nueva }, function (err, res) {
                    toastFechas(res);
                    if (res && res.success) { $('#prorroga_fecha').val(''); cargarDatosAlta(); cargar(); }
                });
            },
            { titulo: '¿Ampliar el plazo?', confirmar: 'Sí, ampliar', icon: 'question' });
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
                if (!res || !res.success) { mostrarToast((res && res.message) || 'Error.', 'error'); return; }
                $('#modalDocs').modal('hide');
                // El alta quedó hecha, pero si algún correo no salió el toast va en
                // ámbar: en verde nadie se enteraba y las áreas se quedaban esperando.
                mostrarToast(res.message, res.aviso ? 'warning' : 'success');
                cargar();
            });
        }, { titulo: 'Completar alta', confirmar: 'Sí, dar de alta', icon: 'question' });
    });

    /**
     * Reenvía los avisos de un alta YA completada. Es el reintento que no existía:
     * si el correo fallaba (SMTP caído, config de correo ausente en el servidor),
     * las áreas no se enteraban del ingreso y no había forma de volver a mandarlo.
     */
    $('#btnReenviarAvisos').on('click', function (e) {
        e.preventDefault();
        var areas = $('.chkAreaAlta:checked').map(function () { return this.value; }).get();
        if (!areas.length) { mostrarToast('Marca al menos un área.', 'warning'); return; }
        var nombres = $('.chkAreaAlta:checked').map(function () {
            return $('label[for="' + this.id + '"]').text().trim();
        }).get();

        confirmarAccion('Se volverá a enviar la notificación de alta a: <strong>' + escHtml(nombres.join(', '))
            + '</strong>. Si ya la habían recibido, les llegará repetida.', function () {
            ajaxPost('acciones_cierre.php', {
                accion: 'reenviar_avisos_alta', id: docCandidato, areas: areas.join(',')
            }, function (err, res) {
                if (!res) { mostrarToast('Error.', 'error'); return; }
                mostrarToast(res.message, res.success ? (res.aviso ? 'warning' : 'success') : 'error');
            });
        }, { titulo: 'Reenviar notificaciones', confirmar: 'Sí, reenviar', icon: 'question' });
    });

    cargar();
});
