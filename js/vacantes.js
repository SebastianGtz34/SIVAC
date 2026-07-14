/* vacantes.js — Módulo de vacantes (RRHH). */
$(function () {
    var estatusS_VAC = {
        abierta: 'Abierta', en_proceso: 'En proceso', pausada: 'Pausada',
        cerrada: 'Cerrada', cancelada: 'Cancelada'
    };
    var tabla = $('#tablaVacantes').DataTable({
        language: dtIdioma(),
        order: [[0, 'desc']],
        columnDefs: [{ orderable: false, targets: [7] }]
    });
    var deptosCache = [];

    function cargarCatalogos(cb) {
        ajaxPost('acciones_vacantes.php', { accion: 'departamentos' }, function (err, res) {
            if (!err && res && res.success) {
                deptosCache = res.data;
                var opts = '<option value="">Seleccionar…</option>';
                res.data.forEach(function (d) { opts += '<option value="' + d.id + '">' + escHtml(d.departamento) + '</option>'; });
                $('#vac_departamento').html(opts);
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
    }

    function cargar() {
        var estatus = $('#filtroestatus').val();
        ajaxPost('acciones_vacantes.php', { accion: 'listar', estatus: estatus }, function (err, res) {
            tabla.clear();
            if (err || !res || !res.success) { tabla.draw(); return; }
            res.data.forEach(function (v) {
                var occ = parseInt(v.occ_publicada)
                    ? '<span class="badge badge-success">Sí</span>'
                    : '<span class="badge badge-light">No</span>';
                var acciones = '<div class="btn-group btn-group-sm">'
                    + '<button class="btn btn-outline-secondary btnEditar" data-id="' + v.id + '" title="Editar"><i class="fas fa-edit"></i></button>'
                    + '<button class="btn btn-outline-secondary btnOcc" data-id="' + v.id + '" title="OCC"><i class="fas fa-bullhorn"></i></button>'
                    + '<button class="btn btn-outline-secondary btnestatus" data-id="' + v.id + '" data-estatus="' + v.estatus + '" title="estatus"><i class="fas fa-random"></i></button>'
                    + '<a class="btn btn-outline-primary" href="candidatos.php?vacante=' + v.id + '" title="Candidatos"><i class="fas fa-users"></i></a>'
                    + '</div>';
                tabla.row.add([
                    escHtml(v.folio),
                    escHtml(v.puesto),
                    escHtml(v.solicitante_nombre),
                    v.total_candidatos,
                    v.total_entrevistados,
                    occ,
                    badgeestatusVacante(v.estatus, estatusS_VAC[v.estatus]),
                    acciones
                ]);
            });
            tabla.draw();
        });
    }

    $('#filtroestatus').on('change', cargar);

    $('#btnNuevaVacante').on('click', function () {
        $('#formVacante')[0].reset();
        $('#vac_id').val('');
        $('#modalVacanteTitulo').text('Nueva vacante');
        $('#modalVacante').modal('show');
    });

    $('#tablaVacantes tbody').on('click', '.btnEditar', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_vacantes.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) { mostrarToast('No se pudo cargar la vacante.', 'error'); return; }
            var v = res.data;
            $('#vac_id').val(v.id);
            $('#vac_puesto').val(v.puesto);
            $('#vac_posiciones').val(v.posiciones);
            $('#vac_departamento').val(v.departamento);
            $('#vac_solicitante').val(v.no_empleado_solicitante);
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

    $('#tablaVacantes tbody').on('click', '.btnOcc', function () {
        var id = $(this).data('id');
        ajaxPost('acciones_vacantes.php', { accion: 'detalle', id: id }, function (err, res) {
            if (err || !res || !res.success) return;
            var v = res.data;
            $('#occ_id').val(v.id);
            $('#occ_publicada').prop('checked', parseInt(v.occ_publicada) === 1);
            $('#occ_url').val(v.occ_url || '');
            $('#modalOcc').modal('show');
        });
    });

    $('#formOcc').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'marcar_occ' });
        ajaxPost('acciones_vacantes.php', data, function (err, res) {
            if (res && res.success) {
                $('#modalOcc').modal('hide');
                mostrarToast('Publicación actualizada.', 'success');
                cargar();
            } else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    $('#tablaVacantes tbody').on('click', '.btnestatus', function () {
        var id = $(this).data('id');
        var actual = $(this).data('estatus');
        var opciones = {
            abierta: { en_proceso: 'En proceso', pausada: 'Pausar', cancelada: 'Cancelar' },
            en_proceso: { pausada: 'Pausar', cerrada: 'Cerrar', cancelada: 'Cancelar' },
            pausada: { abierta: 'Reabrir', en_proceso: 'En proceso', cancelada: 'Cancelar' },
            cerrada: { en_proceso: 'Reabrir' },
            cancelada: { abierta: 'Reabrir' }
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
