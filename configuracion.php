<?php
require_once 'conn.php';
require_once 'auth.php';
$noEmpSesion = requiereSesionPage();
requiereRRHHPage($conn, $noEmpSesion);
$pageTitle  = 'Configuración';
$menuActivo = 'configuracion';
include 'encabezado.php';
?>
<div class="page-header"><h1><i class="fas fa-cog mr-2 text-primary"></i>Configuración</h1></div>

<ul class="nav nav-tabs mb-3" id="configTabs" role="tablist">
    <li class="nav-item"><a class="nav-link active" data-toggle="tab" href="#tabDocs" role="tab">Tipos de documento</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabDest" role="tab">Avisos de alta</a></li>
    <li class="nav-item"><a class="nav-link" data-toggle="tab" href="#tabCons" role="tab">Accesos de consulta</a></li>
</ul>

<div class="tab-content">
    <!-- Tipos de documento -->
    <div class="tab-pane fade show active" id="tabDocs" role="tabpanel">
        <div class="card"><div class="card-body">
            <button class="btn btn-primary btn-sm mb-3" id="btnNuevoTipo"><i class="fas fa-plus mr-1"></i>Nuevo tipo</button>
            <table class="table table-sm" id="tablaTipos">
                <thead><tr><th>Nombre</th><th class="text-center">Obligatorio</th><th class="text-center">Activo</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>

    <!-- Destinatarios -->
    <div class="tab-pane fade" id="tabDest" role="tabpanel">
        <div class="card"><div class="card-body">
            <p class="text-muted small">Correos que reciben el aviso cuando se completa un alta (TI, viáticos, teléfono, marketing…).</p>
            <button class="btn btn-primary btn-sm mb-3" id="btnNuevoDest"><i class="fas fa-plus mr-1"></i>Nuevo destinatario</button>
            <table class="table table-sm" id="tablaDest">
                <thead><tr><th>Área</th><th>Correo</th><th class="text-center">Activo</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>

    <!-- Accesos de consulta -->
    <div class="tab-pane fade" id="tabCons" role="tabpanel">
        <div class="card"><div class="card-body">
            <p class="text-muted small">Empleados con acceso de solo lectura al avance de vacantes (p. ej. dirección).</p>
            <form class="form-inline mb-3" id="formConsulta">
                <input type="number" class="form-control form-control-sm mr-2" id="cons_noEmpleado" placeholder="N° empleado" required>
                <input type="text" class="form-control form-control-sm mr-2" id="cons_comentario" placeholder="Comentario (opcional)">
                <button type="submit" class="btn btn-primary btn-sm">Conceder acceso</button>
            </form>
            <table class="table table-sm" id="tablaCons">
                <thead><tr><th>Empleado</th><th>Comentario</th><th class="text-center">Activo</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div></div>
    </div>
</div>

<!-- Modal tipo -->
<div class="modal fade" id="modalTipo" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formTipo">
      <div class="modal-header"><h5 class="modal-title">Tipo de documento</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" id="tipo_id">
        <div class="form-group"><label>Nombre *</label><input type="text" class="form-control" id="tipo_nombre" required></div>
        <div class="form-check"><input type="checkbox" class="form-check-input" id="tipo_obligatorio"><label class="form-check-label" for="tipo_obligatorio">Obligatorio</label></div>
        <div class="form-check"><input type="checkbox" class="form-check-input" id="tipo_activo" checked><label class="form-check-label" for="tipo_activo">Activo</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form>
  </div></div>
</div>

<!-- Modal destinatario -->
<div class="modal fade" id="modalDest" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formDest">
      <div class="modal-header"><h5 class="modal-title">Destinatario de aviso</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" id="dest_id">
        <div class="form-group"><label>Área *</label><input type="text" class="form-control" id="dest_area" required></div>
        <div class="form-group"><label>Correo *</label><input type="email" class="form-control" id="dest_correo" required></div>
        <div class="form-check"><input type="checkbox" class="form-check-input" id="dest_activo" checked><label class="form-check-label" for="dest_activo">Activo</label></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
    </form>
  </div></div>
</div>

<?php include 'pie.php'; ?>
<script src="js/configuracion.js"></script>
