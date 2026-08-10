/* configuracion.js — Catálogos y accesos de consulta (RRHH). */
$(function () {
    var URL = 'acciones_configuracion.php';

    /* ---- Tipos de documento ---- */
    function cargarTipos() {
        ajaxPost(URL, { accion: 'listar_tipos' }, function (err, res) {
            var $b = $('#tablaTipos tbody').empty();
            if (err || !res || !res.success) return;
            res.data.forEach(function (t) {
                $b.append('<tr><td>' + escHtml(t.nombre) + '</td>'
                    + '<td class="text-center">' + (parseInt(t.obligatorio) ? '<i class="fas fa-check text-success"></i>' : '—') + '</td>'
                    + '<td class="text-center">' + (parseInt(t.activo) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-secondary">No</span>') + '</td>'
                    + '<td class="text-right"><button class="btn btn-sm btn-outline-secondary btnEditTipo" data-t=\'' + JSON.stringify(t) + '\'><i class="fas fa-edit"></i></button></td></tr>');
            });
        });
    }
    $('#btnNuevoTipo').on('click', function () {
        $('#formTipo')[0].reset(); $('#tipo_id').val(''); $('#tipo_activo').prop('checked', true); $('#modalTipo').modal('show');
    });
    $('#tablaTipos').on('click', '.btnEditTipo', function () {
        var t = $(this).data('t');
        $('#tipo_id').val(t.id); $('#tipo_nombre').val(t.nombre);
        $('#tipo_obligatorio').prop('checked', parseInt(t.obligatorio) === 1);
        $('#tipo_activo').prop('checked', parseInt(t.activo) === 1);
        $('#modalTipo').modal('show');
    });
    $('#formTipo').on('submit', function (e) {
        e.preventDefault();
        ajaxPost(URL, {
            accion: 'guardar_tipo', id: $('#tipo_id').val(), nombre: $('#tipo_nombre').val(),
            obligatorio: $('#tipo_obligatorio').prop('checked') ? 1 : 0,
            activo: $('#tipo_activo').prop('checked') ? 1 : 0
        }, function (err, res) {
            if (res && res.success) { $('#modalTipo').modal('hide'); mostrarToast(res.message, 'success'); cargarTipos(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    /* ---- Destinatarios ---- */
    function cargarDest() {
        ajaxPost(URL, { accion: 'listar_destinatarios' }, function (err, res) {
            var $b = $('#tablaDest tbody').empty();
            if (err || !res || !res.success) return;
            res.data.forEach(function (d) {
                // Un área sin correo es una que nunca va a recibir su aviso: se
                // marca, porque desde la pantalla del alta sólo se ve deshabilitada.
                var correo = d.correo
                    ? escHtml(d.correo)
                    : '<span class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i>sin cargar</span>';
                $b.append('<tr><td>' + escHtml(d.area) + '</td><td>' + correo + '</td>'
                    + '<td class="text-center">' + (parseInt(d.activo) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-secondary">No</span>') + '</td>'
                    + '<td class="text-right"><button class="btn btn-sm btn-outline-secondary btnEditDest" data-d=\'' + JSON.stringify(d) + '\'><i class="fas fa-edit"></i></button> '
                    + '<button class="btn btn-sm btn-outline-danger btnDelDest" data-id="' + d.id + '"><i class="fas fa-trash"></i></button></td></tr>');
            });
        });
    }
    $('#btnNuevoDest').on('click', function () {
        $('#formDest')[0].reset(); $('#dest_id').val(''); $('#dest_activo').prop('checked', true); $('#modalDest').modal('show');
    });
    $('#tablaDest').on('click', '.btnEditDest', function () {
        var d = $(this).data('d');
        $('#dest_id').val(d.id); $('#dest_clave').val(d.clave || 'nominas');
        $('#dest_area').val(d.area); $('#dest_correo').val(d.correo);
        $('#dest_activo').prop('checked', parseInt(d.activo) === 1); $('#modalDest').modal('show');
    });
    // El área es sólo la etiqueta: se propone la del aviso elegido para no
    // teclearla, pero se puede cambiar (p. ej. "Nóminas — Gisela").
    $('#dest_clave').on('change', function () {
        if ($('#dest_id').val()) return;                    // editando: no pisar lo que ya hay
        $('#dest_area').val($(this).find('option:selected').text().split('—')[0].trim());
    });
    $('#tablaDest').on('click', '.btnDelDest', function () {
        var id = $(this).data('id');
        confirmarAccion('¿Eliminar este destinatario?', function () {
            ajaxPost(URL, { accion: 'eliminar_destinatario', id: id }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarDest(); }
            });
        });
    });
    $('#formDest').on('submit', function (e) {
        e.preventDefault();
        ajaxPost(URL, {
            accion: 'guardar_destinatario', id: $('#dest_id').val(), clave: $('#dest_clave').val(),
            area: $('#dest_area').val(), correo: $('#dest_correo').val(),
            activo: $('#dest_activo').prop('checked') ? 1 : 0
        }, function (err, res) {
            if (res && res.success) { $('#modalDest').modal('hide'); mostrarToast(res.message, 'success'); cargarDest(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });

    /* ---- Accesos de consulta ---- */
    function cargarCons() {
        ajaxPost(URL, { accion: 'listar_consulta' }, function (err, res) {
            var $b = $('#tablaCons tbody').empty();
            if (err || !res || !res.success) return;
            res.data.forEach(function (a) {
                $b.append('<tr><td>' + escHtml(a.nombre) + ' <span class="text-muted small">#' + escHtml(a.no_empleado) + '</span></td>'
                    + '<td>' + escHtml(a.comentario || '') + '</td>'
                    + '<td class="text-center">' + (parseInt(a.activo) ? '<span class="badge badge-success">Sí</span>' : '<span class="badge badge-secondary">No</span>') + '</td>'
                    + '<td class="text-right"><button class="btn btn-sm btn-outline-secondary btnToggleCons" data-id="' + a.id + '">' + (parseInt(a.activo) ? 'Desactivar' : 'Activar') + '</button></td></tr>');
            });
        });
    }
    $('#formConsulta').on('submit', function (e) {
        e.preventDefault();
        ajaxPost(URL, { accion: 'guardar_consulta', no_empleado: $('#cons_noEmpleado').val(), comentario: $('#cons_comentario').val() }, function (err, res) {
            if (res && res.success) { $('#formConsulta')[0].reset(); mostrarToast(res.message, 'success'); cargarCons(); }
            else { mostrarToast((res && res.message) || 'Error.', 'error'); }
        });
    });
    $('#tablaCons').on('click', '.btnToggleCons', function () {
        var id = $(this).data('id');
        ajaxPost(URL, { accion: 'toggle_consulta', id: id }, function (err, res) {
            if (res && res.success) { cargarCons(); }
        });
    });

    cargarTipos(); cargarDest(); cargarCons();
});
