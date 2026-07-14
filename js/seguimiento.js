/* seguimiento.js — Psicométrico y entrevista (RRHH). */
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var tabla = $('#tablaProceso').DataTable({
        language: dtIdioma(), order: [], columnDefs: [{ orderable: false, targets: [5] }]
    });

    function cargar() {
        ajaxPost('acciones_proceso.php', { accion: 'listar' }, function (err, res) {
            tabla.clear();
            if (err || !res || !res.success) { tabla.draw(); return; }
            res.data.forEach(function (c) {
                var psico = c.psicometrico_fecha_presentado
                    ? '<span class="text-success"><i class="fas fa-check"></i> ' + formatearFecha(c.psicometrico_fecha_presentado) + '</span>'
                    : (c.psicometrico_folio ? '<span class="text-warning">Folio ' + escHtml(c.psicometrico_folio) + ' (pendiente)</span>' : '<span class="text-muted">—</span>');
                var entrevista = c.cita_confirmada
                    ? '<span class="text-success">' + formatearFecha(c.cita_confirmada) + '</span>'
                    : (c.cita_pendiente ? '<span class="text-info">Disponibilidad registrada</span>' : '<span class="text-muted">—</span>');

                var acc = '<div class="btn-group btn-group-sm">';
                if (c.estatus === 'aprobado_jefe') {
                    acc += '<button class="btn btn-outline-primary btnPsico" data-id="' + c.id + '"><i class="fas fa-clipboard-check mr-1"></i>Psicométrico</button>';
                } else if (c.estatus === 'psicometrico_asignado') {
                    acc += '<button class="btn btn-outline-success btnPresentado" data-id="' + c.id + '"><i class="fas fa-check mr-1"></i>Presentado</button>';
                } else if (c.estatus === 'psicometrico_presentado') {
                    if (c.cita_pendiente) {
                        acc += '<button class="btn btn-outline-primary btnConfirmar" data-id="' + c.id + '"><i class="fas fa-calendar-check mr-1"></i>Confirmar cita</button>';
                    }
                    acc += '<button class="btn btn-outline-secondary btnCita" data-id="' + c.id + '"><i class="fas fa-calendar-plus mr-1"></i>Disponibilidad</button>';
                } else if (c.estatus === 'entrevista_confirmada') {
                    acc += '<button class="btn btn-outline-success btnResultado" data-id="' + c.id + '"><i class="fas fa-flag-checkered mr-1"></i>Resultado</button>';
                }
                acc += '</div>';

                tabla.row.add([
                    escHtml(c.nombre) + '<div class="text-muted small">' + escHtml(c.correo) + '</div>',
                    escHtml(c.folio) + '<div class="text-muted small">' + escHtml(c.puesto) + '</div>',
                    badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus),
                    psico, entrevista, acc
                ]);
            });
            tabla.draw();
        });
    }

    // Psicométrico
    $('#tablaProceso tbody').on('click', '.btnPsico', function () {
        $('#formPsico')[0].reset(); $('#psico_id').val($(this).data('id')); $('#modalPsico').modal('show');
    });
    $('#formPsico').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray(); data.push({ name: 'accion', value: 'registrar_psicometrico' });
        ajaxPost('acciones_proceso.php', data, function (err, res) {
            if (res && res.success) { $('#modalPsico').modal('hide'); mostrarToast(res.message, 'success'); cargar(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });
    $('#tablaProceso tbody').on('click', '.btnPresentado', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Marcar psicométrico presentado', input: 'text', inputLabel: 'Resultado (opcional)',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Marcar presentado', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_proceso.php', { accion: 'marcar_presentado', id: id, resultado: r.value || '' }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // Confirmar entrevista (elige opción 1 o 2)
    $('#tablaProceso tbody').on('click', '.btnConfirmar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Confirmar entrevista', input: 'select',
            inputOptions: { 1: 'Opción 1', 2: 'Opción 2' }, inputPlaceholder: 'Selecciona la fecha',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_proceso.php', { accion: 'confirmar_entrevista', id: id, opcion: r.value }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // Nueva disponibilidad (reprogramar)
    $('#tablaProceso tbody').on('click', '.btnCita', function () {
        $('#formCita')[0].reset(); $('#cita_id').val($(this).data('id')); $('#modalCita').modal('show');
    });
    $('#formCita').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray(); data.push({ name: 'accion', value: 'nueva_cita' });
        ajaxPost('acciones_proceso.php', data, function (err, res) {
            if (res && res.success) { $('#modalCita').modal('hide'); mostrarToast(res.message, 'success'); cargar(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    // Resultado de entrevista
    $('#tablaProceso tbody').on('click', '.btnResultado', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Resultado de la entrevista', input: 'radio',
            inputOptions: { aceptado: 'Aceptado (pasa a propuesta)', descartado: 'Descartado' },
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Continuar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            if (r.value === 'aceptado') {
                ajaxPost('acciones_proceso.php', { accion: 'registrar_resultado_entrevista', id: id, resultado: 'aceptado' }, function (err, res) {
                    if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                    else { mostrarToast((res && res.message) || 'Error.', 'error'); }
                });
            } else {
                Swal.fire({
                    title: 'Motivo del descarte', input: 'textarea', showCancelButton: true,
                    confirmButtonColor: messColor('danger'), background: messColor('card-bg'), color: messColor('text'),
                    confirmButtonText: 'Descartar', cancelButtonText: 'Volver'
                }).then(function (r2) {
                    if (!r2.isConfirmed || !r2.value) return;
                    ajaxPost('acciones_proceso.php', { accion: 'registrar_resultado_entrevista', id: id, resultado: 'descartado', motivo: r2.value }, function (err, res) {
                        if (res && res.success) { mostrarToast(res.message, 'success'); cargar(); }
                        else { mostrarToast((res && res.message) || 'Error.', 'error'); }
                    });
                });
            }
        });
    });

    cargar();
});
