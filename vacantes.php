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
            <option value="">Todos los estatus</option>
            <option value="pendiente_vobo">Pendientes de VoBo</option>
            <option value="abierta">Abiertas</option>
            <option value="en_proceso">En proceso</option>
            <option value="pausada">Pausadas</option>
            <option value="cerrada">Cerradas</option>
            <option value="cancelada">Canceladas</option>
            <option value="rechazada">Rechazadas</option>
        </select>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover w-100" id="tablaVacantes">
                <thead>
                    <tr>
                        <th>Folio</th><th>Puesto</th><th>Solicitante</th><th>Región</th>
                        <th class="text-center">Candidatos</th><th class="text-center">Entrevistados</th>
                        <th class="text-center">Estatus</th><th>Registrada</th><th></th>
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
          <div class="form-group col-md-6">
            <!-- El puesto sale del catálogo de RRHH (mess_rrhh.puesto): dejó de ser
                 texto libre para que las vacantes se puedan agrupar y comparar. -->
            <label>Puesto *</label>
            <select class="form-control" name="id_puesto" id="vac_puesto" required></select>
          </div>
          <div class="form-group col-md-3">
            <label>Tipo *</label>
            <select class="form-control" name="tipo" id="vac_tipo" required>
              <option value="temporal">Temporal</option>
              <option value="permanente">Permanente</option>
              <option value="practicas">Prácticas</option>
            </select>
            <small class="form-text text-muted">Prácticas: sin propuesta económica.</small>
          </div>
          <div class="form-group col-md-3">
            <label>Posiciones</label>
            <input type="number" class="form-control" name="posiciones" id="vac_posiciones" min="1" value="1">
          </div>
        </div>
        <!-- Sólo Temporal: duración + motivo. El toggle (show/hide + required) lo maneja js/vacantes.js. -->
        <div class="form-row" id="vac_temporal_fields" style="display:none">
          <div class="form-group col-md-4">
            <label>Duración (meses) *</label>
            <input type="number" class="form-control" name="duracion_meses" id="vac_duracion" min="1" max="600">
          </div>
          <div class="form-group col-md-8">
            <label>Motivo de la contratación temporal *</label>
            <input type="text" class="form-control" name="motivo_temporal" id="vac_motivo_temporal" maxlength="255">
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

<!-- Modal VoBo: revisión de una requisición levantada por un jefe.
     Es la única salida de 'pendiente_vobo' (así vobo_por/vobo_fecha siempre
     quedan registrados), por eso vive aparte del cambio de estatus normal. -->
<div class="modal fade" id="modalVobo" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Visto bueno de la requisición</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="vobo_id">
        <dl class="row mb-3 small">
          <dt class="col-sm-3">Folio</dt><dd class="col-sm-9" id="vobo_folio"></dd>
          <dt class="col-sm-3">Puesto</dt><dd class="col-sm-9" id="vobo_puesto"></dd>
          <dt class="col-sm-3">Tipo</dt><dd class="col-sm-9" id="vobo_tipo"></dd>
          <dt class="col-sm-3">Solicitante</dt><dd class="col-sm-9" id="vobo_solicitante"></dd>
          <dt class="col-sm-3">Región</dt><dd class="col-sm-9" id="vobo_region"></dd>
          <dt class="col-sm-3">Posiciones</dt><dd class="col-sm-9" id="vobo_posiciones"></dd>
        </dl>
        <label class="small text-muted mb-1">Justificación y descripción</label>
        <pre class="border rounded p-2 small mb-3" id="vobo_descripcion"
             style="white-space:pre-wrap;max-height:220px;overflow:auto;background:var(--card-bg);color:var(--text)"></pre>
        <div class="form-group mb-0">
          <label>Motivo del rechazo</label>
          <textarea class="form-control" id="vobo_motivo" rows="2"
                    placeholder="Obligatorio solo si rechazas la requisición."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
        <button type="button" class="btn btn-outline-danger" id="btnVoboRechazar">
          <i class="fas fa-times mr-1"></i>Rechazar
        </button>
        <button type="button" class="btn btn-success" id="btnVoboAprobar">
          <i class="fas fa-check mr-1"></i>Dar visto bueno
        </button>
      </div>
  </div></div>
</div>

<?php include 'pie.php'; ?>
<script src="<?= sivacAsset('js/vacantes.js') ?>"></script>
