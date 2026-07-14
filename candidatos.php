<?php
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
requiereRRHHPage($conn, $noEmpSesion);
$pageTitle  = 'Candidatos';
$menuActivo = 'candidatos';
$vacantePre = (int)($_GET['vacante'] ?? 0);
include 'encabezado.php';
?>
<div class="page-header">
    <h1><i class="fas fa-users mr-2 text-primary"></i>Candidatos</h1>
    <div>
        <button class="btn btn-outline-primary" id="btnEnviarSel" disabled>
            <i class="fas fa-paper-plane mr-1"></i> Enviar seleccionados al solicitante
        </button>
        <button class="btn btn-primary" id="btnNuevoCandidato"><i class="fas fa-plus mr-1"></i> Nuevo candidato</button>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Listado de candidatos</span>
        <select id="filtroVacante" class="form-control form-control-sm" style="width:auto">
            <option value="">Todas las vacantes</option>
        </select>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover w-100" id="tablaCandidatos">
                <thead><tr>
                    <th style="width:36px"><input type="checkbox" id="chkTodos"></th>
                    <th>Nombre</th><th>Vacante</th><th>Contacto</th>
                    <th class="text-center">estatus</th><th class="text-center">CV</th><th></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal nuevo/editar candidato -->
<div class="modal fade" id="modalCandidato" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formCandidato" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title" id="modalCandidatoTitulo">Nuevo candidato</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="cand_id">
        <div class="form-group" id="grupoVacante">
          <label>Vacante *</label>
          <select class="form-control" name="id_vacante" id="cand_vacante" required></select>
        </div>
        <div class="form-group">
          <label>Nombre completo *</label>
          <input type="text" class="form-control" name="nombre" id="cand_nombre" maxlength="150" required>
        </div>
        <div class="form-row">
          <div class="form-group col-md-7">
            <label>Correo *</label>
            <input type="email" class="form-control" name="correo" id="cand_correo" maxlength="150" required>
          </div>
          <div class="form-group col-md-5">
            <label>Teléfono</label>
            <input type="text" class="form-control" name="telefono" id="cand_telefono" maxlength="30">
          </div>
        </div>
        <div class="form-group" id="grupoCv">
          <label>CV (PDF, máx. 5 MB) *</label>
          <input type="file" class="form-control-file" name="cv" id="cand_cv" accept="application/pdf">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="btnGuardarCandidato">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal detalle -->
<div class="modal fade" id="modalDetalle" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header">
      <h5 class="modal-title" id="detalleTitulo">Candidato</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button>
    </div>
    <div class="modal-body" id="detalleBody"></div>
  </div></div>
</div>

<script>window.VACANTE_PRE = <?= $vacantePre ?>;</script>
<?php include 'pie.php'; ?>
<script src="js/candidatos.js"></script>
