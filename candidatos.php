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

<!-- Etiqueta de contexto: qué vacante se está viendo. El select de la esquina se
     perdía, sobre todo al llegar desde Vacantes con ?vacante=N. La pinta
     js/candidatos.js (pintarEtiquetaVacante) a partir del filtro. -->
<div id="etiquetaVacante" class="vac-contexto d-none">
    <div class="vac-contexto-info">
        <i class="fas fa-briefcase"></i>
        <div>
            <div class="vac-contexto-titulo">
                <span id="etiquetaVacantePuesto"></span>
                <span id="etiquetaVacanteBadge"></span>
            </div>
            <div class="vac-contexto-folio text-muted small" id="etiquetaVacanteFolio"></div>
        </div>
    </div>
    <button type="button" class="btn btn-sm btn-outline-primary" id="btnTodasVacantes">
        <i class="fas fa-times mr-1"></i>Ver todas
    </button>
</div>

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span id="tituloTablaCandidatos">Candidatos</span>
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
                    <th class="text-center">Estatus</th><th class="text-center">CV</th><th></th>
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
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Nombre(s) *</label>
            <input type="text" class="form-control" name="nombre" id="cand_nombre" maxlength="150" required>
          </div>
          <div class="form-group col-md-6">
            <label>Apellidos *</label>
            <input type="text" class="form-control" name="apellidos" id="cand_apellidos" maxlength="200" required>
          </div>
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
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Nave</label>
            <select class="form-control" name="nave" id="cand_nave">
              <option value="">Sin asignar</option>
            </select>
          </div>
          <div class="form-group col-md-6">
            <label>Región</label>
            <select class="form-control" name="region" id="cand_region">
              <option value="">Sin asignar</option>
            </select>
          </div>
        </div>
        <div class="form-group" id="grupoCv">
          <label>CV (PDF, máx. 5 MB) *</label>
          <input type="file" class="form-control-file" name="cv" id="cand_cv" accept="application/pdf">
        </div>
        <hr>
        <p class="text-muted small mb-2">
          <i class="fas fa-user-check mr-1"></i>Constancia de la entrevista de RRHH
        </p>
        <div class="form-row align-items-end">
          <div class="form-group col-md-5">
            <label>Fecha de la entrevista de RRHH</label>
            <input type="date" class="form-control" name="entrevista_rrhh_fecha" id="cand_ent_rrhh_fecha">
          </div>
          <div class="form-group col-md-7">
            <label class="d-block">Resultado</label>
            <div class="btn-group" id="cand_ent_rrhh_res_group" role="group">
              <button type="button" class="btn btn-outline-success" data-val="apto">
                <i class="far fa-circle mr-1 marca-off"></i><i class="fas fa-check-circle mr-1 marca-sel d-none"></i>Apto</button>
              <button type="button" class="btn btn-outline-danger" data-val="no_apto">
                <i class="far fa-circle mr-1 marca-off"></i><i class="fas fa-times-circle mr-1 marca-sel d-none"></i>No apto</button>
            </div>
            <input type="hidden" name="entrevista_rrhh_resultado" id="cand_ent_rrhh_res">
          </div>
        </div>
        <div class="form-group mb-0">
          <label>Observaciones</label>
          <input type="text" class="form-control" name="entrevista_rrhh_observaciones" id="cand_ent_rrhh_obs" maxlength="500"
                 placeholder="p. ej. buen perfil técnico (opcional)">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary" id="btnGuardarCandidato">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<!-- Modal agendar/reprogramar la entrevista del jefe -->
<div class="modal fade" id="modalCita" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formCita">
      <div class="modal-header"><h5 class="modal-title">Agendar entrevista con el jefe</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="cita_id">
        <p class="text-muted small mb-3">Normalmente el jefe propone las fechas al aprobar el CV; usa esto para reprogramar. Se le enviarán las dos opciones al candidato.
           Basta con que las dos opciones sean distintas: pueden caer el <strong>mismo día a distinta hora</strong>.</p>
        <div class="form-group"><label>Opción 1 *</label>
          <input type="datetime-local" class="form-control" name="opcion1" id="cita_op1" required></div>
        <div class="form-group"><label>Opción 2 *</label>
          <input type="datetime-local" class="form-control" name="opcion2" id="cita_op2" required></div>
        <div class="form-group mb-0"><label>Comentarios</label>
          <textarea class="form-control" name="notas" id="cita_notas" rows="2"
                    placeholder="Contexto para el entrevistador y el candidato (opcional)."></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar y enviar opciones</button>
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

<!-- Modal psicométrico (informativo: no bloquea el proceso ni descarta). -->
<div class="modal fade" id="modalPsicometrico" tabindex="-1" role="dialog">
  <div class="modal-dialog" role="document"><div class="modal-content">
    <form id="formPsicometrico">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-brain mr-2 text-info"></i>Psicométrico</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="id" id="ps_id">
        <p class="text-muted small mb-3">Registro informativo. No cambia el estatus del candidato ni lo descarta.</p>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Fecha de aplicación</label>
            <input type="date" class="form-control" name="psicometrico_fecha" id="ps_fecha">
          </div>
          <div class="form-group col-md-6">
            <label>Calificación</label>
            <input type="text" class="form-control" name="psicometrico_calificacion" id="ps_calificacion"
                   maxlength="30" placeholder="p. ej. 85 / A / Alto (opcional)">
          </div>
        </div>
        <div class="form-group">
          <label class="d-block">Resultado</label>
          <div class="btn-group" id="ps_res_group" role="group">
            <button type="button" class="btn btn-outline-success" data-val="apto">
              <i class="far fa-circle mr-1 marca-off"></i><i class="fas fa-check-circle mr-1 marca-sel d-none"></i>Apto</button>
            <button type="button" class="btn btn-outline-danger" data-val="no_apto">
              <i class="far fa-circle mr-1 marca-off"></i><i class="fas fa-times-circle mr-1 marca-sel d-none"></i>No apto</button>
          </div>
          <input type="hidden" name="psicometrico_resultado" id="ps_res">
        </div>
        <div class="form-group mb-0">
          <label>Observaciones</label>
          <input type="text" class="form-control" name="psicometrico_observaciones" id="ps_obs"
                 maxlength="500" placeholder="Opcional">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Guardar</button>
      </div>
    </form>
  </div></div>
</div>

<script>window.VACANTE_PRE = <?= $vacantePre ?>;</script>
<?php include 'pie.php'; ?>
<script src="<?= sivacAsset('js/candidatos.js') ?>"></script>
