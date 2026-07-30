/* vacantes.js — Módulo de vacantes (RRHH). */
$(function () {
    var estatusS_VAC = window.SIVAC_estatusS_VAC || {};
    var TIPOS = window.SIVAC_TIPOS_VACANTE || {};
    var tabla = dtAutoAjustar($('#tablaVacantes').DataTable({
        language: dtIdioma(),
        order: [[0, 'desc']],
        // 7 = "Registrada" (fecha formateada, no se ordena como texto), 8 = acciones.
        columnDefs: [{ orderable: false, targets: [7, 8] }]
    }));

    // Muestra/oculta duración + motivo según el tipo. Alterna `required` junto al
    // show/hide: un `required` oculto rompe la validación nativa del submit. Se
    // llama a mano tras cada reset y en la precarga de edición (reset no dispara change).
    function toggleTemporal() {
        var esTemporal = $('#vac_tipo').val() === 'temporal';
        $('#vac_temporal_fields').toggle(esTemporal);
        $('#vac_duracion, #vac_motivo_temporal').prop('required', esTemporal);
        if (!esTemporal) { $('#vac_duracion').val(''); $('#vac_motivo_temporal').val(''); }
    }
    $('#vac_tipo').on('change', toggleTemporal);

    function cargarCatalogos(cb) {
        ajaxPost('acciones_vacantes.php', { accion: 'departamentos' }, function (err, res) {
            if (!err && res && res.success) {
                var opts = '<option value="">Seleccionar…</option>';
                res.data.forEach(function (d) { opts += '<option value="' + d.id + '">' + escHtml(d.departamento) + '</option>'; });
                $('#vac_departamento').html(opts);
            }
            // Puestos del catálogo de RRHH: el campo dejó de ser texto libre.
            ajaxPost('acciones_vacantes.php', { accion: 'puestos' }, function (errP, resP) {
                if (!errP && resP && resP.success) {
                    var op = '<option value="">Seleccionar…</option>';
                    resP.data.forEach(function (p) { op += '<option value="' + p.id + '">' + escHtml(p.puesto) + '</option>'; });
                    $('#vac_puesto').html(op);
                }
                ajaxPost('acciones_vacantes.php', { accion: 'empleados', q: '' }, function (err2, res2) {
                    if (!err2 && res2 && res2.success) {
                        var o = '<option value="">Seleccionar…</option>';
                        res2.data.forEach(function (e) { o += '<option value="' + e.noEmpleado + '">' + escHtml(e.nombre) + ' (#' + e.noEmpleado + ')</option>'; });
                        $('#vac_solicitante').html(o);
                    }
                    if (cb) cb();
                });
            });
        });
    }

    function cargar() {
        var estatus = $('#filtroestatus').val();
        ajaxPost('acciones_vacantes.php', { accion: 'listar', estatus: estatus }, function (err, res) {
            tabla.clear();
            if (err || !res || !res.success) { tabla.draw(); return; }
            res.data.forEach(function (v) {
                var acciones = '<div class="btn-group btn-group-sm">';
                // Una requisición pendiente de VoBo todavía no es una vacante:
                // lo único que aplica es revisarla.
                if (v.estatus === 'pendiente_vobo') {
                    acciones += '<button class="btn btn-outline-success btnVobo" data-id="' + v.id + '" title="Revisar requisición"><i class="fas fa-stamp"></i></button>';
                }
                acciones += '<button class="btn btn-outline-secondary btnEditar" data-id="' + v.id + '" title="Editar"><i class="fas fa-edit"></i></button>'
                    + '<button class="btn btn-outline-secondary btnestatus" data-id="' + v.id + '" data-estatus="' + v.estatus + '" title="estatus"><i class="fas fa-random"></i></button>'
                    + '<a class="btn btn-outline-primary" href="candidatos.php?vacante=' + v.id + '" title="Candidatos"><i class="fas fa-users"></i></a>'
                    + '</div>';

                // El puesto lleva debajo el tipo y, si la levantó un jefe, de dónde vino.
                var puesto = escHtml(v.puesto)
                    + '<div class="text-muted small">' + escHtml(TIPOS[v.tipo] || v.tipo)
                    + (v.origen === 'solicitante' ? ' · <i class="fas fa-user-tie"></i> la pidió el jefe' : '')
                    + '</div>';

                // Debajo del folio, sólo en Temporal: duración y motivo (texto pequeño).
                var folio = escHtml(v.folio);
                if (v.tipo === 'temporal' && (v.duracion_meses || v.motivo_temporal)) {
                    var sub = [];
                    if (v.duracion_meses) sub.push(v.duracion_meses + (Number(v.duracion_meses) === 1 ? ' mes' : ' meses'));
                    if (v.motivo_temporal) sub.push(escHtml(v.motivo_temporal));
                    folio += '<div class="text-muted small">' + sub.join(' · ') + '</div>';
                }

                tabla.row.add([
                    folio,
                    puesto,
                    escHtml(v.solicitante_nombre),
                    escHtml(v.region_nombre || '—'),
                    v.total_candidatos,
                    v.total_entrevistados,
                    badgeestatusVacante(v.estatus, estatusS_VAC[v.estatus] || v.estatus),
                    formatearFecha(v.fecha_creacion),
                    acciones
                ]);
            });
            tabla.draw();
            tabla.columns.adjust();   // recomputa anchos tras poblar por AJAX
        });
    }

    $('#filtroestatus').on('change', cargar);

    $('#btnNuevaVacante').on('click', function () {
        $('#formVacante')[0].reset();
        $('#vac_id').val('');
        $('#vac_tipo').val('permanente');   // reset() no elige el default de negocio
        toggleTemporal();                    // reset() no dispara change
        $('#modalVacanteTitulo').text('Nueva vacante');
        $('#modalVacante').modal('show');
    });

    $('#tablaVacantes tbody').on('click', '.btnEditar', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_vacantes.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) { mostrarToast('No se pudo cargar la vacante.', 'error'); return; }
            var v = res.data;
            $('#vac_id').val(v.id);
            // Las vacantes viejas pueden traer id_puesto NULL (el backfill de la
            // migración no encontró su nombre en el catálogo): el select queda
            // vacío y obliga a elegir uno, que es justo lo que se busca.
            $('#vac_puesto').val(v.id_puesto || '');
            $('#vac_tipo').val(v.tipo || 'permanente');
            $('#vac_duracion').val(v.duracion_meses || '');
            $('#vac_motivo_temporal').val(v.motivo_temporal || '');
            toggleTemporal();   // muestra/oculta según el tipo precargado
            $('#vac_posiciones').val(v.posiciones);
            $('#vac_departamento').val(v.departamento);
            // El selector sólo lista jefes (usuarios.tipo_usr). Una vacante
            // anterior a ese filtro puede tener un solicitante que ya no aparece:
            // se le reinyecta su opción para no borrarle el dueño al guardar.
            var $sol = $('#vac_solicitante');
            $sol.find('option.solicitante-fuera-de-lista').remove();
            $sol.val(v.no_empleado_solicitante);
            if ($sol.val() === null && v.no_empleado_solicitante) {
                $sol.append('<option class="solicitante-fuera-de-lista" value="' + v.no_empleado_solicitante + '">'
                    + escHtml(v.solicitante_nombre || ('#' + v.no_empleado_solicitante))
                    + ' (#' + v.no_empleado_solicitante + ')</option>');
                $sol.val(v.no_empleado_solicitante);
            }
            $('#vac_descripcion').val(v.descripcion);
            $('#modalVacanteTitulo').text('Editar vacante ' + v.folio);
            $('#modalVacante').modal('show');
        });
    });

    $('#formVacante').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: $('#vac_id').val() ? 'editar' : 'crear' });
        ajaxPost('acciones_vacantes.php', data, function (err, res) {
            if (err || !res) { mostrarToast('Error de comunicación.', 'error'); return; }
            if (res.success) {
                $('#modalVacante').modal('hide');
                mostrarToast(res.message || 'Guardado.', 'success');
                cargar();
            } else { mostrarToast(res.message || 'No se pudo guardar.', 'error'); }
        });
    });

    // ── VoBo de RRHH sobre una requisición levantada por un jefe ──
    $('#tablaVacantes tbody').on('click', '.btnVobo', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_vacantes.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) { mostrarToast('No se pudo cargar la requisición.', 'error'); return; }
            var v = res.data;
            $('#vobo_id').val(v.id);
            $('#vobo_folio').text(v.folio);
            $('#vobo_puesto').text(v.puesto);
            $('#vobo_tipo').text(v.tipo_label || v.tipo);
            $('#vobo_solicitante').text(v.solicitante_nombre);
            $('#vobo_region').text(v.region_nombre || '—');
            $('#vobo_posiciones').text(v.posiciones);
            // .text() para que la justificación del jefe no se interprete como HTML.
            $('#vobo_descripcion').text(v.descripcion || '(Sin descripción)');
            $('#vobo_motivo').val('');
            $('#modalVobo').modal('show');
        });
    });

    function enviarVobo(decision, motivo) {
        ajaxPost('acciones_vacantes.php', {
            accion: 'vobo', id: $('#vobo_id').val(), decision: decision, motivo: motivo || ''
        }, function (err, res) {
            if (res && res.success) {
                $('#modalVobo').modal('hide');
                mostrarToast(res.message, 'success');
                cargar();
            } else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    }

    $('#btnVoboAprobar').on('click', function () {
        confirmarAccion('La vacante quedará abierta y podrás capturarle candidatos.',
            function () { enviarVobo('aprobar'); },
            { titulo: '¿Dar visto bueno?', confirmar: 'Sí, aprobar', icon: 'question' });
    });

    $('#btnVoboRechazar').on('click', function () {
        var motivo = $.trim($('#vobo_motivo').val());
        // El motivo se exige aquí y también en el backend; esto solo evita el
        // viaje al servidor para decir lo obvio.
        if (!motivo) {
            mostrarToast('Escribe el motivo del rechazo antes de rechazar.', 'warning');
            $('#vobo_motivo').focus();
            return;
        }
        enviarVobo('rechazar', motivo);
    });

    $('#tablaVacantes tbody').on('click', '.btnestatus', function () {
        var id = $(this).data('id');
        var actual = $(this).data('estatus');
        // Espejo de $TRANS_VAC en acciones_vacantes.php (que es quien decide).
        // 'pendiente_vobo' solo sale por el botón de VoBo —para que vobo_por y
        // vobo_fecha queden siempre registrados—, así que por aquí únicamente se
        // puede cancelar.
        var opciones = {
            pendiente_vobo: { cancelada: 'Cancelar' },
            abierta: { en_proceso: 'En proceso', pausada: 'Pausar', cancelada: 'Cancelar' },
            en_proceso: { pausada: 'Pausar', cerrada: 'Cerrar', cancelada: 'Cancelar' },
            pausada: { abierta: 'Reabrir', en_proceso: 'En proceso', cancelada: 'Cancelar' },
            cerrada: { en_proceso: 'Reabrir' },
            cancelada: { abierta: 'Reabrir' },
            rechazada: { pendiente_vobo: 'Regresar a revisión' }
        }[actual] || {};
        if (Object.keys(opciones).length === 0) { mostrarToast('Sin cambios de estatus disponibles.', 'info'); return; }
        Swal.fire({
            title: 'Cambiar estatus',
            input: 'select',
            inputOptions: opciones,
            inputPlaceholder: 'Selecciona…',
            showCancelButton: true,
            confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Continuar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            var payload = { accion: 'cambiar_estatus', id: id, estatus: r.value };
            if (r.value === 'cancelada') {
                Swal.fire({
                    title: 'Motivo de cancelación', input: 'textarea', showCancelButton: true,
                    confirmButtonColor: messColor('accent'), background: messColor('card-bg'), color: messColor('text'),
                    confirmButtonText: 'Cancelar vacante', cancelButtonText: 'Volver'
                }).then(function (r2) {
                    if (!r2.isConfirmed || !r2.value) return;
                    payload.motivo = r2.value;
                    enviarestatus(payload);
                });
            } else { enviarestatus(payload); }
        });
    });

    function enviarestatus(payload) {
        ajaxPost('acciones_vacantes.php', payload, function (err, res) {
            if (res && res.success) { mostrarToast('estatus actualizado.', 'success'); cargar(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    }

    cargarCatalogos(cargar);
});
