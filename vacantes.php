<?php
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
requiereRRHHPage($conn, $noEmpSesion);
$pageTitle  = 'Vacantes';
$menuActivo = 'vacantes';
include 'encabezado.php';
?>
<div class="page-header">
    <h1><i class="fas fa-briefcase mr-2 text-primary"></i>Vacantes</h1>
    <button class="btn btn-primary" id="btnNuevaVacante"><i class="fas fa-plus mr-1"></i> Nueva vacante</button>
</div>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Listado de vacantes</span>
        <select id="filtroestatus" class="form-control form-control-sm" style="width:auto">
            <option value="">Todos los estatuss</option>
            <option value="abierta">Abiertas</option>
            <option value="en_proceso">En proceso</option>
            <option value="pausada">Pausadas</option>
            <option value="cerrada">Cerradas</option>
            <option value="cancelada">Canceladas</option>
        </select>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover w-100" id="tablaVacantes">
                <thead>
                    <tr>
                        <th>Folio</th><th>Puesto</th><th>Solicitante</th>
                        <th class="text-center">Candidatos</th><th class="text-center">Entrevistados</th>
                        <th class="text-center">OCC</th><th class="text-center">estatus</th><th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal crear/editar -->
<div class="modal fade" id="modalVacante" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <form id="formVacante">
      <div class="modal-header">
        <h5 class="modal-title" id="modalVacanteTitulo">Nueva vacante</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="vac_id">
        <div class="form-row">
          <div class="form-group col-md-8">
            <label>Puesto *</label>
            <input type="text" class="form-control" name="puesto" id="vac_puesto" maxlength="150" required>
          </div>
          <div class="form-group col-md-4">
            <label>Posiciones</label>
            <input type="number" class="form-control" name="posiciones" id="vac_posiciones" min="1" value="1">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Departamento *</label>
            <select class="form-control" name="departamento" id="vac_departamento" required></select>
          </div>
          <div class="form-group col-md-6">
            <label>Solicitante (dueño de la vacante) *</label>
            <select class="form-control" name="no_empleado_solicitante" id="vac_solicitante" required></select>
          </div>
        </div>
        <div class="form-group">
          <label>Descripción y requisitos</label>
          <textarea class="form-control" name="descripcion" id="vac_descripcion" rows="4" maxlength="4000"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal OCC -->
<div class="modal fade" id="modalOcc" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formOcc">
      <div class="modal-header">
        <h5 class="modal-title">Publicación en OCC</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="occ_id">
        <div class="form-group form-check">
          <input type="checkbox" class="form-check-input" name="occ_publicada" id="occ_publicada" value="1">
          <label class="form-check-label" for="occ_publicada">Publicada en OCC</label>
        </div>
        <div class="form-group">
          <label>URL de la publicación</label>
          <input type="url" class="form-control" name="occ_url" id="occ_url" placeholder="https://www.occ.com.mx/...">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<?php include 'pie.php'; ?>
<script src="js/vacantes.js"></script>
