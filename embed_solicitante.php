<?php
/**
 * embed_solicitante.php — Vista "Mis Vacantes" del solicitante, para iframe
 * dentro de loginMaster. Autocontenida (sin sidebar/topbar). Gate: solo sesión;
 * la seguridad real (ownership) vive en acciones_solicitante.php.
 */
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
$embed = true;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis Vacantes · SIVAC</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="css/estilos.css" rel="stylesheet">
</head>
<body class="embed">
<div class="container-fluid">
    <h5 class="mb-3"><i class="fas fa-briefcase mr-2 text-primary"></i>Mis vacantes</h5>
    <div class="row" id="misVacantes"></div>

    <h5 class="mt-4 mb-3"><i class="fas fa-user-clock mr-2 text-primary"></i>Candidatos por revisar</h5>
    <div class="card"><div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="tablaMisCand">
                <thead><tr><th>Candidato</th><th>Vacante</th><th class="text-center">estatus</th><th class="text-right">Acciones</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div></div>
</div>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="js/funciones.js"></script>
<script>
$(function () {
    var estatusS = window.SIVAC_estatusS || {};

    function cargarVacantes() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_vacantes' }, function (err, res) {
            var $c = $('#misVacantes').empty();
            if (err || !res || !res.success || !res.data.length) {
                $c.html('<div class="col-12 text-muted small">No tienes vacantes asignadas.</div>'); return;
            }
            res.data.forEach(function (v) {
                $c.append(
                    '<div class="col-md-4 mb-3"><div class="card h-100"><div class="card-body">'
                    + '<div class="d-flex justify-content-between align-items-start">'
                    + '<div class="fw-700">' + escHtml(v.puesto) + '</div>'
                    + badgeestatusVacante(v.estatus, v.estatus) + '</div>'
                    + '<div class="text-muted small mb-2">' + escHtml(v.folio) + '</div>'
                    + '<div class="d-flex justify-content-between small">'
                    + '<span><i class="fas fa-users mr-1"></i>' + v.total + ' cand.</span>'
                    + '<span class="text-warning"><i class="fas fa-eye mr-1"></i>' + v.por_revisar + ' por revisar</span>'
                    + '<span class="text-success"><i class="fas fa-user-check mr-1"></i>' + v.entrevistados + '</span>'
                    + '</div></div></div></div>'
                );
            });
        });
    }

    function cargarCandidatos() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_candidatos' }, function (err, res) {
            var $b = $('#tablaMisCand tbody').empty();
            if (err || !res || !res.success || !res.data.length) {
                $b.html('<tr><td colspan="4" class="text-muted small text-center py-3">Sin candidatos.</td></tr>'); return;
            }
            res.data.forEach(function (c) {
                var acc = '';
                if (c.cv_archivo) acc += '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-outline-secondary mr-1"><i class="fas fa-file-pdf"></i> CV</a>';
                if (c.estatus === 'enviado_solicitante') {
                    acc += '<button class="btn btn-sm btn-success btnAprobar mr-1" data-id="' + c.id + '"><i class="fas fa-check"></i> Aprobar</button>';
                    acc += '<button class="btn btn-sm btn-outline-danger btnDescartar" data-id="' + c.id + '"><i class="fas fa-times"></i></button>';
                } else if (c.cita_confirmada) {
                    acc += '<span class="small text-success">Entrevista ' + formatearFecha(c.cita_confirmada) + '</span>';
                }
                $b.append('<tr><td>' + escHtml(c.nombre) + '</td><td>' + escHtml(c.folio) + '<div class="text-muted small">' + escHtml(c.puesto) + '</div></td>'
                    + '<td class="text-center">' + badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus) + '</td>'
                    + '<td class="text-right">' + acc + '</td></tr>');
            });
        });
    }

    $('#tablaMisCand').on('click', '.btnAprobar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Aprobar y proponer entrevista',
            html: '<p class="small text-muted mb-2">Ofrece dos opciones de fecha y hora:</p>'
                + '<input type="datetime-local" id="sw_op1" class="swal2-input">'
                + '<input type="datetime-local" id="sw_op2" class="swal2-input">',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Aprobar', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                var o1 = document.getElementById('sw_op1').value;
                var o2 = document.getElementById('sw_op2').value;
                if (!o1 || !o2) { Swal.showValidationMessage('Indica las dos fechas.'); return false; }
                return { opcion1: o1, opcion2: o2 };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_solicitante.php', { accion: 'aprobar_cv', id: id, opcion1: r.value.opcion1, opcion2: r.value.opcion2 }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    $('#tablaMisCand').on('click', '.btnDescartar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Descartar candidato', input: 'textarea', inputLabel: 'Motivo',
            showCancelButton: true, confirmButtonColor: messColor('danger'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Descartar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_solicitante.php', { accion: 'descartar_cv', id: id, motivo: r.value }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    cargarVacantes();
    cargarCandidatos();
});
</script>
</body>
</html>
