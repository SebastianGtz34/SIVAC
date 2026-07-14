<?php
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
requiereRRHHPage($conn, $noEmpSesion);
$pageTitle  = 'Seguimiento';
$menuActivo = 'seguimiento';
include 'encabezado.php';
?>
<div class="page-header">
    <h1><i class="fas fa-clipboard-list mr-2 text-primary"></i>Seguimiento del proceso</h1>
</div>

<div class="card mb-4">
    <div class="card-header">Candidatos en psicométrico y entrevista</div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover w-100" id="tablaProceso">
                <thead><tr>
                    <th>Candidato</th><th>Vacante</th><th class="text-center">estatus</th>
                    <th>Psicométrico</th><th>Entrevista</th><th></th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal psicométrico -->
<div class="modal fade" id="modalPsico" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formPsico">
      <div class="modal-header"><h5 class="modal-title">Asignar psicométrico</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="psico_id">
        <div class="form-group"><label>Correo de acceso al examen *</label>
          <input type="email" class="form-control" name="correo" id="psico_correo" required></div>
        <div class="form-group"><label>Folio *</label>
          <input type="text" class="form-control" name="folio" id="psico_folio" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Asignar y notificar</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal nueva cita -->
<div class="modal fade" id="modalCita" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formCita">
      <div class="modal-header"><h5 class="modal-title">Registrar disponibilidad de entrevista</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="cita_id">
        <p class="text-muted small">Normalmente el solicitante propone las fechas; usa esto para reprogramar.</p>
        <div class="form-group"><label>Opción 1 *</label>
          <input type="datetime-local" class="form-control" name="opcion1" id="cita_op1" required></div>
        <div class="form-group"><label>Opción 2 *</label>
          <input type="datetime-local" class="form-control" name="opcion2" id="cita_op2" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<?php include 'pie.php'; ?>
<script src="js/seguimiento.js"></script>
