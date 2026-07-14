<?php
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
requiereRRHHPage($conn, $noEmpSesion);
$pageTitle  = 'Contrataciones';
$menuActivo = 'contrataciones';
include 'encabezado.php';
?>
<div class="page-header">
    <h1><i class="fas fa-file-signature mr-2 text-primary"></i>Cierre y contrataciones</h1>
    <button class="btn btn-outline-secondary" id="btnExpirar"><i class="fas fa-clock mr-1"></i> Expirar propuestas vencidas</button>
</div>

<div class="card mb-4">
    <div class="card-header">Candidatos en cierre</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover w-100" id="tablaCierre">
                <thead><tr>
                    <th>Candidato</th><th>Vacante</th><th class="text-center">estatus</th>
                    <th>Detalle</th><th></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal propuesta -->
<div class="modal fade" id="modalPropuesta" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formPropuesta">
      <div class="modal-header"><h5 class="modal-title">Enviar propuesta</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="prop_id">
        <div class="form-group"><label>Fecha de caducidad *</label>
          <input type="date" class="form-control" name="fecha_caducidad" id="prop_caducidad" required></div>
        <div class="form-group"><label>Condiciones (opcional)</label>
          <textarea class="form-control" name="condiciones" id="prop_condiciones" rows="4" maxlength="4000"></textarea></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Enviar propuesta</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal documentación -->
<div class="modal fade" id="modalDocs" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="docsTitulo">Documentación</h5>
      <button type="button" class="close" data-dismiss="modal">&times;</button></div>
    <div class="modal-body">
      <input type="hidden" id="docs_id">
      <div class="row">
        <div class="col-md-6">
          <h6>Datos del alta</h6>
          <div class="form-group"><label>Fecha de ingreso</label>
            <div class="input-group">
              <input type="date" class="form-control" id="ingreso_fecha">
              <div class="input-group-append"><button class="btn btn-outline-primary" id="btnIngreso">Guardar</button></div>
            </div>
          </div>
          <div class="form-group"><label>Prórroga de fecha límite de documentos</label>
            <div class="input-group">
              <input type="date" class="form-control" id="prorroga_fecha">
              <div class="input-group-append"><button class="btn btn-outline-secondary" id="btnProrroga">Prórroga</button></div>
            </div>
          </div>
          <button class="btn btn-outline-info btn-sm mb-2" id="btnReglamento"><i class="fas fa-book mr-1"></i>Enviar reglamento</button>
          <div id="cierreInfo" class="small text-muted"></div>
          <button class="btn btn-success btn-block mt-3" id="btnCompletarAlta"><i class="fas fa-user-check mr-1"></i>Completar alta</button>
        </div>
        <div class="col-md-6">
          <h6>Subir documento</h6>
          <form id="formDoc" enctype="multipart/form-data">
            <div class="form-group">
              <select class="form-control mb-2" id="doc_tipo" required></select>
              <input type="file" class="form-control-file" id="doc_archivo" accept="application/pdf,image/jpeg,image/png" required>
              <small class="text-muted">PDF, JPG o PNG, máx. 10 MB.</small>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-upload mr-1"></i>Subir</button>
          </form>
          <hr>
          <h6>Documentos subidos</h6>
          <ul class="list-group" id="listaDocs"></ul>
        </div>
      </div>
    </div>
  </div></div>
</div>

<?php include 'pie.php'; ?>
<script src="js/contrataciones.js"></script>
