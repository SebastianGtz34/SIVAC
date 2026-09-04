<?php
/**
 * embed_solicitante.php — Vista "Mis Vacantes" del solicitante, para iframe
 * dentro de loginMaster. Autocontenida (sin sidebar/topbar). Gate: solo sesión;
 * la seguridad real (ownership) vive en acciones_solicitante.php.
 */
require_once 'conn.php';
require_once 'auth.php';
require_once 'includes/assets.php';
$noEmpSesion = requiereSesionPage();
$embed = true;
// Quien llega a esta pestaña puede levantar su requisición (ver
// puedeSolicitarVacante en auth.php). El gate real está en
// acciones_solicitante.php; esto solo evita ofrecer un formulario que el backend
// va a rechazar.
$puedeSolicitar = puedeSolicitarVacante($conn, $noEmpSesion);
// Nombre para el mensaje de "no hay vacantes a tu nombre": decirle "empleado #523"
// a alguien no le confirma nada; ver su propio nombre sí.
$datosSesion = obtenerDatosEmpleado($conn, $noEmpSesion);
$nombreSesion = $datosSesion['nombre'] ?? ('empleado #' . $noEmpSesion);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis Vacantes · NEST</title>
    <link href="vendor/fontawesome-free/css/all.min.css" rel="stylesheet">
    <link href="vendor/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="vendor/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="<?= sivacAsset('css/estilos.css') ?>" rel="stylesheet">
</head>
<body class="embed">
<div class="container-fluid">
    <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between pb-3 mb-4 border-bottom">
        <div class="mb-2 mb-sm-0">
            <h4 class="h5 font-weight-bold text-gray-800 mb-0 d-flex align-items-center">
                <span class="icon-circle bg-primary-soft text-primary mr-2" style="width: 34px; height: 34px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 0.85rem; background-color: #eaecf4;">
                    <i class="fas fa-briefcase"></i>
                </span>
                Mis Vacantes
            </h4>            
        </div>

        <?php if ($puedeSolicitar): ?>
        <div class="d-flex align-items-center">
            <button type="button" class="btn btn-sm btn-outline-primary shadow-sm mr-2 font-weight-bold" id="btnNuevaSolicitudPuesto">
                <i class="fas fa-plus-circle mr-1"></i> Nueva Solicitud de Puesto
            </button>
            <button type="button" class="btn btn-sm btn-primary shadow-sm font-weight-bold" id="btnSolicitar">
                <i class="fas fa-user-plus mr-1"></i> Solicitar Candidato
            </button>
        </div>
        <?php endif; ?>
    </div>
    <div class="row" id="misVacantes"></div>

    <h5 class="mt-4 mb-3"><i class="fas fa-user-clock mr-2 text-primary"></i>Candidatos por revisar</h5>
    <p class="text-muted small mb-3">Revisa, aprueba a los que te interesen o descártalos.</p>
    <div class="row" id="misCandidatos"></div>

    <h5 class="mt-4 mb-3"><i class="fas fa-file-signature mr-2 text-primary"></i>Mis Solicitudes de Nueva Posición</h5>
    <p class="text-muted small mb-3">Estado de autorizaciones por Gerencia y Dirección para creación de nuevos puestos.</p>
    <div class="row" id="misSolicitudesPosicion"></div>
</div>

<?php if ($puedeSolicitar): ?>
<div class="modal fade" id="modalSolicitar" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document"><div class="modal-content">
    <form id="formSolicitar">
      <div class="modal-header">
        <h5 class="modal-title">Solicitar una vacante</h5>
        <button type="button" class="close" data-dismiss="modal">&times;</button>
      </div>
      <div class="modal-body">
        <p class="small text-muted">
          RRHH revisará tu solicitud antes de abrir la vacante. El departamento y la
          región se toman de tu registro de empleado.
        </p>
        <div class="form-row">
          <div class="form-group col-md-6">
            <label>Puesto *</label>
            <select class="form-control" name="id_puesto" id="sol_puesto" required></select>
          </div>
          <div class="form-group col-md-3">
            <label>Tipo *</label>
            <select class="form-control" name="tipo" id="sol_tipo" required>
              <option value="temporal">Temporal</option>
              <option value="permanente">Permanente</option>
              <option value="practicas">Prácticas</option>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Posiciones</label>
            <input type="number" class="form-control" name="posiciones" id="sol_posiciones" min="1" value="1">
          </div>
        </div>
        <div class="form-row" id="sol_temporal_fields" style="display:none">
          <div class="form-group col-md-4">
            <label>Duración (meses) *</label>
            <input type="number" class="form-control" name="duracion_meses" id="sol_duracion" min="1" max="600">
          </div>
          <div class="form-group col-md-8">
            <label>Motivo de la contratación temporal *</label>
            <input type="text" class="form-control" name="motivo_temporal" id="sol_motivo_temporal" maxlength="255">
          </div>
        </div>
        <div class="form-group">
          <label>¿Por qué se necesita? *</label>
          <textarea class="form-control" name="justificacion" id="sol_justificacion" rows="3"
                    placeholder="Es lo que RRHH lee para dar el visto bueno." required></textarea>
        </div>
        <div class="form-group mb-0">
          <label>Descripción y requisitos</label>
          <textarea class="form-control" name="descripcion" id="sol_descripcion" rows="3" maxlength="4000"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" class="btn btn-primary">Enviar solicitud</button>
      </div>
    </form>
  </div></div>
</div>


<div class="modal fade" id="modalSolicitudPuesto" tabindex="-1" role="dialog" aria-labelledby="modalSolicitudLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content border-0 shadow-lg">
      
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title font-weight-bold" id="modalSolicitudLabel">
          <i class="fas fa-file-alt mr-2"></i>Solicitud y Justificación de Nueva Posición
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body bg-light">
        <form id="formSolicitudPuesto">
          <input type="hidden" name="fecha_solicitud" value="<?= date('Y-m-d') ?>">

          <div class="card shadow mb-4 border-left-primary">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-id-card mr-2"></i>1. Información General y Esquema de Contratación
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-8">
                  <label class="font-weight-bold text-dark small">Nombre del Puesto Nuevo <span class="text-danger">*</span></label>
                  <input type="text" name="nombre_puesto" class="form-control" placeholder="Ej. Analista BI Senior" required>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Nº de Vacantes <span class="text-danger">*</span></label>
                  <input type="number" name="numero_vacantes" class="form-control" min="1" value="1" required>
                </div>
              </div>

              <div class="form-row">
                                  <!-- Modal de Crear / Editar Solicitud -->
                  <input type="hidden" id="jefe_solicitante" name="jefe_solicitante">

                  <div class="form-group col-md-6">
                      <label for="area">Área Solicitante *</label>
                      <select class="form-control" id="area" name="area" required>
                          <option value="">Selecciona un área...</option>
                      </select>
                  </div>

                  <div class="form-group col-md-6">
                      <label for="sede">Sede / Planta *</label>
                      <select class="form-control" id="sede" name="sede" required>
                          <option value="">Selecciona una sede...</option>
                      </select>
                  </div>
              </div>

              <hr class="sidebar-divider my-2">

              <div class="form-row pt-2">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Tipo de Contratación <span class="text-danger">*</span></label>
                  <select name="tipo_contratacion" id="tipoContratacion" class="form-control" required>
                    <option value="">-- Seleccionar esquema --</option>
                    <option value="Permanente">Permanente</option>
                    <option value="Temporal">Temporal</option>
                    <option value="Proyecto específico">Proyecto específico</option>
                    <option value="Practicante">Practicante</option>
                  </select>
                </div>
                <div class="form-group col-md-6 d-none" id="secTemporal">
                  <label class="font-weight-bold text-dark small">Especificar duración o motivo temporal</label>
                  <input type="text" name="especificacion_temporal" class="form-control" placeholder="Ej. 6 meses por incapacidad">
                </div>
              </div>

              <div id="secProyectoPracticante" class="card border-left-secondary bg-light mt-2 d-none">
                <div class="card-body">
                  <h6 class="font-weight-bold text-secondary mb-3">
                    <i class="fas fa-project-diagram mr-2"></i>a) Detalles del Proyecto / Practicante
                  </h6>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Nombre del Proyecto</label>
                      <input type="text" name="proyecto_nombre" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Carrera(s) Afines</label>
                      <input type="text" name="carreras_solicitadas" class="form-control">
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Objetivo del Proyecto</label>
                      <textarea name="proyecto_objetivo" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Actividades del Practicante</label>
                      <textarea name="practicante_actividades" class="form-control" rows="2"></textarea>
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">Periodo Estimado</label>
                      <input type="text" name="periodo_estimado" class="form-control" placeholder="Ej. 6 Meses">
                    </div>
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">Horario</label>
                      <input type="text" name="horario_solicitado" class="form-control" placeholder="Ej. 08:00 - 14:00">
                    </div>
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">Horas Requeridas</label>
                      <input type="number" name="horas_requeridas" class="form-control" placeholder="Total horas">
                    </div>
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">¿Posibilidad de Contratación?</label>
                      <select name="posibilidad_contratacion" class="form-control">
                        <option value="">Seleccionar...</option>
                        <option value="Sí">Sí</option>
                        <option value="No">No</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <div class="card shadow mb-4 border-left-warning">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-warning">
                <i class="fas fa-bullseye mr-2"></i>2. Justificación Operativa
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Origen de la Necesidad <span class="text-danger">*</span></label>
                  <select name="motivo_necesidad" class="form-control" required>
                    <option value="Crecimiento del área">Crecimiento del área</option>
                    <option value="Proyecto nuevo">Proyecto nuevo</option>
                    <option value="Incremento de carga de trabajo">Incremento de carga de trabajo</option>
                    <option value="Cobertura temporal">Cobertura temporal</option>
                    <option value="Otro">Otro</option>
                  </select>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">¿Se evaluó automatizar / redistribuir? <span class="text-danger">*</span></label>
                  <select name="evaluo_redistribucion" class="form-control" required>
                    <option value="Sí">Sí</option>
                    <option value="No">No</option>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="font-weight-bold text-dark small">Explicación de por qué no es suficiente la redistribución</label>
                <textarea name="justificacion_redistribucion" class="form-control" rows="2" placeholder="Detalla los motivos..."></textarea>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Problema principal que resolverá <span class="text-danger">*</span></label>
                  <select name="problema_resuelve" class="form-control" required>
                    <option value="Incremento de ventas">Incremento de ventas</option>
                    <option value="Reducción de tiempos">Reducción de tiempos</option>
                    <option value="Cumplimiento normativo">Cumplimiento normativo</option>
                    <option value="Atención a nuevos clientes">Atención a nuevos clientes</option>
                    <option value="Disminución de carga de trabajo">Disminución de carga de trabajo</option>
                    <option value="Desarrollo de nuevos proyectos">Desarrollo de nuevos proyectos</option>
                    <option value="Otro">Otro</option>
                  </select>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">¿Quién realiza actualmente estas funciones? <span class="text-danger">*</span></label>
                  <input type="text" name="quien_realiza_actualmente" class="form-control" required>
                </div>
              </div>
              <div class="form-group">
                <label class="font-weight-bold text-dark small">Funciones Principales (5 a 8 actividades) <span class="text-danger">*</span></label>
                <textarea name="funciones_principales" class="form-control" rows="3" placeholder="- Función 1&#10;- Función 2..." required></textarea>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Riesgos de NO autorizar la posición <span class="text-danger">*</span></label>
                  <textarea name="riesgos_no_autorizacion" class="form-control" rows="2" required></textarea>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">KPIs / Indicadores de Éxito a 6 meses <span class="text-danger">*</span></label>
                  <textarea name="impacto_kpis" class="form-control" rows="2" required></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow mb-4 border-left-success">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-success">
                <i class="fas fa-user-graduate mr-2"></i>3. Perfil del Candidato
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Escolaridad Mínima <span class="text-danger">*</span></label>
                  <input type="text" name="escolaridad" class="form-control" placeholder="Ej. Licenciatura / Ingeniería" required>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Carrera <span class="text-danger">*</span></label>
                  <input type="text" name="carrera" class="form-control" placeholder="Ej. Sistemas, Industrial" required>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Experiencia Requerida <span class="text-danger">*</span></label>
                  <input type="text" name="experiencia" class="form-control" placeholder="Ej. 2 años en puesto similar" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Conocimientos Técnicos <span class="text-danger">*</span></label>
                  <textarea name="conocimientos_tecnicos" class="form-control" rows="2" required></textarea>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Software Especializado</label>
                  <textarea name="software_requerido" class="form-control" rows="2" placeholder="Ej. Excel avanzado, Power BI, SQL"></textarea>
                </div>
              </div>
            </div>
          </div>

          <div class="card shadow mb-4 border-left-dark">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-dark">
                <i class="fas fa-coins mr-2"></i>4. Presupuesto y Recursos
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Sueldo Mensual Propuesto (MXN) <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text">$</span>
                    </div>
                    <input type="number" step="0.01" name="sueldo_mensual_propuesto" class="form-control" required>
                  </div>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">¿Accede a Comisiones / Bonos? <span class="text-danger">*</span></label>
                  <select name="accede_comisiones_bonos" class="form-control" required>
                    <option value="No">No</option>
                    <option value="Sí">Sí</option>
                  </select>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Fecha Ideal de Ingreso <span class="text-danger">*</span></label>
                  <input type="date" name="fecha_ideal_ingreso" class="form-control" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">¿Cuenta con estación de trabajo? <span class="text-danger">*</span></label>
                  <select name="cuenta_estacion_trabajo" class="form-control" required>
                    <option value="Sí">Sí</option>
                    <option value="No">No</option>
                  </select>
                </div>
                <div class="form-group col-md-8">
                  <label class="font-weight-bold text-dark small d-block">Equipo e Insumos Requeridos</label>
                  <div class="pt-1">
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input" id="checkEpp" name="equipo_requerido[]" value="EPP">
                      <label class="custom-control-label small" for="checkEpp"><i class="fas fa-hard-hat mr-1 text-secondary"></i> EPP</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input" id="checkComp" name="equipo_requerido[]" value="Computadora">
                      <label class="custom-control-label small" for="checkComp"><i class="fas fa-laptop mr-1 text-secondary"></i> Computadora</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input" id="checkCel" name="equipo_requerido[]" value="Celular">
                      <label class="custom-control-label small" for="checkCel"><i class="fas fa-mobile-alt mr-1 text-secondary"></i> Celular</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input" id="checkAuto" name="equipo_requerido[]" value="Vehículo">
                      <label class="custom-control-label small" for="checkAuto"><i class="fas fa-car mr-1 text-secondary"></i> Vehículo</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input" id="checkHerr" name="equipo_requerido[]" value="Herramientas">
                      <label class="custom-control-label small" for="checkHerr"><i class="fas fa-wrench mr-1 text-secondary"></i> Herramientas</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </form>
      </div>

      <div class="modal-footer bg-white border-top-0">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
        <button type="submit" form="formSolicitudPuesto" class="btn btn-primary btn-icon-split shadow-sm">
          <span class="icon text-white-50">
            <i class="fas fa-paper-plane"></i>
          </span>
          <span class="text">Enviar Solicitud</span>
        </button>
      </div>

    </div>
  </div>
</div>


<!-- Modal Detalle y Edición de Solicitud de Puesto -->
<div class="modal fade" id="modalDetalleSolicitud" tabindex="-1" role="dialog" aria-labelledby="modalDetalleLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" role="document">
    <div class="modal-content border-0 shadow-lg">
      
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title font-weight-bold" id="modalDetalleLabel">
          <i class="fas fa-file-alt mr-2"></i>Detalle de la Solicitud de Puesto
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>

      <div class="modal-body bg-light">
        <form id="formEditarSolicitudVacante">
          <input type="hidden" name="id" id="edit_id">

          <!-- Card 1: Información General y Esquema -->
          <div class="card shadow mb-4 border-left-primary">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-primary">
                <i class="fas fa-id-card mr-2"></i>1. Información General y Esquema de Contratación
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-8">
                  <label class="font-weight-bold text-dark small">Nombre del Puesto Nuevo <span class="text-danger">*</span></label>
                  <input type="text" name="nombre_puesto" id="edit_nombre_puesto" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Nº de Vacantes <span class="text-danger">*</span></label>
                  <input type="number" name="numero_vacantes" id="edit_numero_vacantes" class="form-control" min="1" required>
                </div>
              </div>

              <div class="form-row">
                <input type="hidden" id="jefe_solicitante" name="jefe_solicitante">

                  <!-- Dentro de #modalDetalleSolicitud -->
<div class="form-group col-md-6">
    <label for="edit_area">Área Solicitante *</label>
    <select class="form-control" id="edit_area" name="area" required>
        <option value="">Selecciona un área...</option>
    </select>
</div>

<div class="form-group col-md-6">
    <label for="edit_sede">Sede / Planta *</label>
    <select class="form-control" id="edit_sede" name="sede" required>
        <option value="">Selecciona una sede...</option>
    </select>
</div>
              </div>

              <hr class="sidebar-divider my-2">

              <div class="form-row pt-2">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Tipo de Contratación <span class="text-danger">*</span></label>
                  <select name="tipo_contratacion" id="edit_tipoContratacion" class="form-control" required>
                    <option value="Permanente">Permanente</option>
                    <option value="Temporal">Temporal</option>
                    <option value="Proyecto específico">Proyecto específico</option>
                    <option value="Practicante">Practicante</option>
                  </select>
                </div>
                <div class="form-group col-md-6 d-none" id="edit_secTemporal">
                  <label class="font-weight-bold text-dark small">Especificar duración o motivo temporal</label>
                  <input type="text" name="especificacion_temporal" id="edit_especificacion_temporal" class="form-control">
                </div>
              </div>

              <div id="edit_secProyectoPracticante" class="card border-left-secondary bg-light mt-2 d-none">
                <div class="card-body">
                  <h6 class="font-weight-bold text-secondary mb-3">
                    <i class="fas fa-project-diagram mr-2"></i>a) Detalles del Proyecto / Practicante
                  </h6>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Nombre del Proyecto</label>
                      <input type="text" name="proyecto_nombre" id="edit_proyecto_nombre" class="form-control">
                    </div>
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Carrera(s) Afines</label>
                      <input type="text" name="carreras_solicitadas" id="edit_carreras_solicitadas" class="form-control">
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Objetivo del Proyecto</label>
                      <textarea name="proyecto_objetivo" id="edit_proyecto_objetivo" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-group col-md-6">
                      <label class="font-weight-bold text-dark small">Actividades del Practicante</label>
                      <textarea name="practicante_actividades" id="edit_practicante_actividades" class="form-control" rows="2"></textarea>
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">Periodo Estimado</label>
                      <input type="text" name="periodo_estimado" id="edit_periodo_estimado" class="form-control">
                    </div>
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">Horario</label>
                      <input type="text" name="horario_solicitado" id="edit_horario_solicitado" class="form-control">
                    </div>
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">Horas Requeridas</label>
                      <input type="number" name="horas_requeridas" id="edit_horas_requeridas" class="form-control">
                    </div>
                    <div class="form-group col-md-3">
                      <label class="font-weight-bold text-dark small">¿Posibilidad de Contratación?</label>
                      <select name="posibilidad_contratacion" id="edit_posibilidad_contratacion" class="form-control">
                        <option value="">Seleccionar...</option>
                        <option value="Sí">Sí</option>
                        <option value="No">No</option>
                      </select>
                    </div>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Card 2: Justificación Operativa -->
          <div class="card shadow mb-4 border-left-warning">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-warning">
                <i class="fas fa-bullseye mr-2"></i>2. Justificación Operativa
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Origen de la Necesidad <span class="text-danger">*</span></label>
                  <select name="motivo_necesidad" id="edit_motivo_necesidad" class="form-control" required>
                    <option value="Crecimiento del área">Crecimiento del área</option>
                    <option value="Proyecto nuevo">Proyecto nuevo</option>
                    <option value="Incremento de carga de trabajo">Incremento de carga de trabajo</option>
                    <option value="Cobertura temporal">Cobertura temporal</option>
                    <option value="Otro">Otro</option>
                  </select>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">¿Se evaluó automatizar / redistribuir? <span class="text-danger">*</span></label>
                  <select name="evaluo_redistribucion" id="edit_evaluo_redistribucion" class="form-control" required>
                    <option value="Sí">Sí</option>
                    <option value="No">No</option>
                  </select>
                </div>
              </div>
              <div class="form-group">
                <label class="font-weight-bold text-dark small">Explicación de por qué no es suficiente la redistribución</label>
                <textarea name="justificacion_redistribucion" id="edit_justificacion_redistribucion" class="form-control" rows="2"></textarea>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Problema principal que resolverá <span class="text-danger">*</span></label>
                  <select name="problema_resuelve" id="edit_problema_resuelve" class="form-control" required>
                    <option value="Incremento de ventas">Incremento de ventas</option>
                    <option value="Reducción de tiempos">Reducción de tiempos</option>
                    <option value="Cumplimiento normativo">Cumplimiento normativo</option>
                    <option value="Atención a nuevos clientes">Atención a nuevos clientes</option>
                    <option value="Disminución de carga de trabajo">Disminución de carga de trabajo</option>
                    <option value="Desarrollo de nuevos proyectos">Desarrollo de nuevos proyectos</option>
                    <option value="Otro">Otro</option>
                  </select>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">¿Quién realiza actualmente estas funciones? <span class="text-danger">*</span></label>
                  <input type="text" name="quien_realiza_actualmente" id="edit_quien_realiza_actualmente" class="form-control" required>
                </div>
              </div>
              <div class="form-group">
                <label class="font-weight-bold text-dark small">Funciones Principales (5 a 8 actividades) <span class="text-danger">*</span></label>
                <textarea name="funciones_principales" id="edit_funciones_principales" class="form-control" rows="3" required></textarea>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Riesgos de NO autorizar la posición <span class="text-danger">*</span></label>
                  <textarea name="riesgos_no_autorizacion" id="edit_riesgos_no_autorizacion" class="form-control" rows="2" required></textarea>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">KPIs / Indicadores de Éxito a 6 meses <span class="text-danger">*</span></label>
                  <textarea name="impacto_kpis" id="edit_impacto_kpis" class="form-control" rows="2" required></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 3: Perfil Requerido -->
          <div class="card shadow mb-4 border-left-success">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-success">
                <i class="fas fa-user-graduate mr-2"></i>3. Perfil del Candidato
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Escolaridad Mínima <span class="text-danger">*</span></label>
                  <input type="text" name="escolaridad" id="edit_escolaridad" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Carrera <span class="text-danger">*</span></label>
                  <input type="text" name="carrera" id="edit_carrera" class="form-control" required>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Experiencia Requerida <span class="text-danger">*</span></label>
                  <input type="text" name="experiencia" id="edit_experiencia" class="form-control" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Conocimientos Técnicos <span class="text-danger">*</span></label>
                  <textarea name="conocimientos_tecnicos" id="edit_conocimientos_tecnicos" class="form-control" rows="2" required></textarea>
                </div>
                <div class="form-group col-md-6">
                  <label class="font-weight-bold text-dark small">Software Especializado</label>
                  <textarea name="software_requerido" id="edit_software_requerido" class="form-control" rows="2"></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Card 4: Presupuesto & Recursos -->
          <div class="card shadow mb-4 border-left-dark">
            <div class="card-header py-3 bg-white">
              <h6 class="m-0 font-weight-bold text-dark">
                <i class="fas fa-coins mr-2"></i>4. Presupuesto y Recursos
              </h6>
            </div>
            <div class="card-body">
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Sueldo Mensual Propuesto (MXN) <span class="text-danger">*</span></label>
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text">$</span>
                    </div>
                    <input type="number" step="0.01" name="sueldo_mensual_propuesto" id="edit_sueldo_mensual_propuesto" class="form-control" required>
                  </div>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">¿Accede a Comisiones / Bonos? <span class="text-danger">*</span></label>
                  <select name="accede_comisiones_bonos" id="edit_accede_comisiones_bonos" class="form-control" required>
                    <option value="No">No</option>
                    <option value="Sí">Sí</option>
                  </select>
                </div>
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">Fecha Ideal de Ingreso <span class="text-danger">*</span></label>
                  <input type="date" name="fecha_ideal_ingreso" id="edit_fecha_ideal_ingreso" class="form-control" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group col-md-4">
                  <label class="font-weight-bold text-dark small">¿Cuenta con estación de trabajo? <span class="text-danger">*</span></label>
                  <select name="cuenta_estacion_trabajo" id="edit_cuenta_estacion_trabajo" class="form-control" required>
                    <option value="Sí">Sí</option>
                    <option value="No">No</option>
                  </select>
                </div>
                <div class="form-group col-md-8">
                  <label class="font-weight-bold text-dark small d-block">Equipo e Insumos Requeridos</label>
                  <div class="pt-1">
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input edit-equipo" id="edit_checkEpp" name="equipo_requerido[]" value="EPP">
                      <label class="custom-control-label small" for="edit_checkEpp"><i class="fas fa-hard-hat mr-1 text-secondary"></i> EPP</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input edit-equipo" id="edit_checkComp" name="equipo_requerido[]" value="Computadora">
                      <label class="custom-control-label small" for="edit_checkComp"><i class="fas fa-laptop mr-1 text-secondary"></i> Computadora</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input edit-equipo" id="edit_checkCel" name="equipo_requerido[]" value="Celular">
                      <label class="custom-control-label small" for="edit_checkCel"><i class="fas fa-mobile-alt mr-1 text-secondary"></i> Celular</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input edit-equipo" id="edit_checkAuto" name="equipo_requerido[]" value="Vehículo">
                      <label class="custom-control-label small" for="edit_checkAuto"><i class="fas fa-car mr-1 text-secondary"></i> Vehículo</label>
                    </div>
                    <div class="custom-control custom-checkbox custom-control-inline">
                      <input type="checkbox" class="custom-control-input edit-equipo" id="edit_checkHerr" name="equipo_requerido[]" value="Herramientas">
                      <label class="custom-control-label small" for="edit_checkHerr"><i class="fas fa-wrench mr-1 text-secondary"></i> Herramientas</label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </form>
      </div>

      <div class="modal-footer bg-white border-top-0">
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cerrar</button>
        <button type="submit" id="btnGuardarEdicion" form="formEditarSolicitudVacante" class="btn btn-success btn-icon-split shadow-sm">
          <span class="icon text-white-50">
            <i class="fas fa-save"></i>
          </span>
          <span class="text">Guardar Cambios</span>
        </button>
      </div>

    </div>
  </div>
</div>


<script>
  // Control de visibilidad de secciones dinámicas
  document.getElementById('tipoContratacion').addEventListener('change', function() {
    const secProyecto = document.getElementById('secProyectoPracticante');
    const secTemporal = document.getElementById('secTemporal');
    
    if (this.value === 'Proyecto específico' || this.value === 'Practicante') {
      secProyecto.classList.remove('d-none');
    } else {
      secProyecto.classList.add('d-none');
    }

    if (this.value === 'Temporal') {
      secTemporal.classList.remove('d-none');
    } else {
      secTemporal.classList.add('d-none');
    }
  });
</script>

<?php endif; ?>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="<?= sivacAsset('js/funciones.js') ?>"></script>
<script>
$(function () {
    var estatusS = window.SIVAC_estatusS || {};
    var estatusS_VAC = window.SIVAC_estatusS_VAC || {};
    var puedeSolicitar = <?= $puedeSolicitar ? 'true' : 'false' ?>;
    var NOMBRE_SESION = <?= json_encode($nombreSesion, JSON_UNESCAPED_UNICODE) ?>;
    var candidatosData = [];   // caché de mis_candidatos (se filtra por vacante)
    var vacSel = 0;            // vacante seleccionada (master-detalle)
    var hayVacantes = false;   // para que el bloque de abajo no pida "selecciona una"

    function cargarVacantes() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_vacantes' }, function (err, res) {
            var $c = $('#misVacantes').empty();
            if (err || !res || !res.success) {
                hayVacantes = false;
                $c.html('<div class="col-12 text-danger small">'
                    + '<i class="fas fa-exclamation-triangle mr-1"></i>No se pudieron cargar tus vacantes. '
                    + 'Recarga la página; si sigue igual, avisa a RRHH.</div>');
                renderCandidatos();
                return;
            }
            // Vacío ≠ error. Aquí se listan SÓLO las vacantes en las que la sesión
            // es el solicitante: si RRHH abrió una para el área pero puso a otra
            // persona, esta pantalla se ve vacía y nadie sabe por qué. Se dice
            // explícitamente, con el número de empleado que SIVAC está leyendo,
            // para que se pueda comparar contra el dueño real de la vacante.
            if (!res.data.length) {
                hayVacantes = false;
                $c.html('<div class="col-12"><div class="alert alert-info mb-0 small">'
                    + '<i class="fas fa-info-circle mr-1"></i>'
                    + 'Todavía no hay ninguna vacante a nombre de <strong>'
                    + escHtml(NOMBRE_SESION) + '</strong>.'
                    + '<br>Si RRHH ya abrió una para tu área, pídeles que te registren como '
                    + '<strong>solicitante</strong>: sólo así aparece aquí.'
                    + (puedeSolicitar
                        ? '<br>También puedes levantar la tuya con <strong>Solicitar Candidato</strong>, arriba a la derecha.'
                        : '')
                    + '</div></div>');
                renderCandidatos();
                return;
            }
            hayVacantes = true;
            res.data.forEach(function (v) {
                // Una requisición pendiente o rechazada aún no tiene candidatos:
                // en vez de tres ceros se muestra en qué va.
                var pie;
                if (v.estatus === 'pendiente_vobo') {
                    pie = '<div class="small text-muted"><i class="fas fa-clock mr-1"></i>Esperando el visto bueno de RRHH.</div>';
                } else if (v.estatus === 'rechazada') {
                    pie = '<div class="small text-danger"><i class="fas fa-times-circle mr-1"></i>'
                        + escHtml(v.motivo_rechazo || 'RRHH rechazó la requisición.') + '</div>';
                } else if (Number(v.total) === 0) {
                    // La vacante existe y está viva, pero todavía no hay a quién
                    // revisar: tres ceros no lo explicaban.
                    pie = '<div class="small text-muted"><i class="fas fa-search mr-1"></i>'
                        + 'RRHH todavía no registra candidatos.</div>';
                } else {
                    pie = '<div class="d-flex justify-content-between small">'
                        + '<span><i class="fas fa-users mr-1"></i>' + v.total + ' cand.</span>'
                        + '<span class="text-warning"><i class="fas fa-eye mr-1"></i>' + v.por_revisar + ' por revisar</span>'
                        + '<span class="text-success"><i class="fas fa-user-check mr-1"></i>' + v.entrevistados + '</span>'
                        + '</div>';
                }
                $c.append(
                    '<div class="col-md-4 mb-3"><div class="card h-100 vac-card" data-id="' + v.id + '" style="cursor:pointer"><div class="card-body">'
                    + '<div class="d-flex justify-content-between align-items-start">'
                    + '<div class="fw-700">' + escHtml(v.puesto) + '</div>'
                    + badgeestatusVacante(v.estatus, estatusS_VAC[v.estatus] || v.estatus) + '</div>'
                    + '<div class="text-muted small mb-2">' + escHtml(v.folio)
                    + ' · ' + escHtml((window.SIVAC_TIPOS_VACANTE && window.SIVAC_TIPOS_VACANTE[v.tipo]) || v.tipo) + '</div>'
                    + pie
                    + '</div></div></div>'
                );
            });
            resaltarVac();   // mantiene el resaltado de la vacante seleccionada
        });
    }

    // Resalta la tarjeta de la vacante seleccionada (master-detalle).
    function resaltarVac() {
        $('#misVacantes .vac-card').removeClass('border-primary shadow');
        if (vacSel) $('#misVacantes .vac-card[data-id="' + vacSel + '"]').addClass('border-primary shadow');
    }

    // Al hacer clic en una vacante, abajo se muestran SOLO sus candidatos.
    $('#misVacantes').on('click', '.vac-card', function () {
        vacSel = $(this).data('id');
        resaltarVac();
        renderCandidatos();
    });

    /** Número de candidato con formato #CAN-000N. */
    function folioCandidato(id) {
        return '#CAN-' + String(id).padStart(4, '0');
    }

    function cargarCandidatos() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_candidatos' }, function (err, res) {
            candidatosData = (!err && res && res.success && res.data) ? res.data : [];
            renderCandidatos();
        });
    }

    // Pinta SOLO los candidatos de la vacante seleccionada (o un aviso si no hay).
    function renderCandidatos() {
        var $c = $('#misCandidatos').empty();
        if (!hayVacantes) {
            $c.html('<div class="col-12 text-muted small">Aquí aparecerán los candidatos en cuanto tengas una vacante.</div>'); return;
        }
        if (!vacSel) {
            $c.html('<div class="col-12 text-muted small">Selecciona una vacante de arriba para ver sus candidatos.</div>'); return;
        }
        var lista = candidatosData.filter(function (x) { return String(x.id_vacante) === String(vacSel); });
        if (!lista.length) {
            $c.html('<div class="col-12 text-muted small">Esta vacante no tiene candidatos por revisar.</div>'); return;
        }
        lista.forEach(function (c) {
                var esDescartado = c.estatus === 'descartado';

                // Constancia de la entrevista de RRHH (el filtro previo). No se
                // muestra el veredicto: al jefe solo le llegan los aptos.
                var rrhh = '<div class="small"><i class="fas fa-user-check text-success mr-1"></i>'
                    + '<strong>Entrevista RRHH:</strong> '
                    + (c.entrevista_rrhh_fecha ? formatearSoloFecha(c.entrevista_rrhh_fecha) : 'sin fecha')
                    + '</div>';
                if (c.entrevista_rrhh_observaciones) {
                    rrhh += '<div class="small text-muted"><i class="fas fa-comment-dots mr-1"></i>'
                        + escHtml(c.entrevista_rrhh_observaciones) + '</div>';
                }

                // Psicométrico (informativo): a diferencia de la constancia de RRHH,
                // aquí SÍ se muestra el veredicto y la calificación — es el punto de
                // la retro: el jefe ve todo, incluidos los no aptos, y decide.
                var psico = '';
                if (c.psicometrico_fecha || c.psicometrico_resultado || c.psicometrico_calificacion) {
                    var psRes = c.psicometrico_resultado === 'apto' ? '<span class="text-success">Apto</span>'
                              : c.psicometrico_resultado === 'no_apto' ? '<span class="text-danger">No apto</span>' : '—';
                    psico = '<div class="small"><i class="fas fa-brain text-info mr-1"></i><strong>Psicométrico:</strong> ' + psRes
                          + (c.psicometrico_calificacion ? ' · ' + escHtml(c.psicometrico_calificacion) : '') + '</div>';
                    if (c.psicometrico_observaciones) {
                        psico += '<div class="small text-muted"><i class="fas fa-comment-dots mr-1"></i>'
                            + escHtml(c.psicometrico_observaciones) + '</div>';
                    }
                }

                // Estado de MI entrevista (la del jefe), si ya está confirmada.
                var miEnt = c.cita_confirmada
                    ? '<div class="h6 mb-0"><i class="fas fa-calendar-check text-primary mr-1"></i>'
                        + '<strong>Entrevista a candidato: ' + formatearFecha(c.cita_confirmada) + '</strong></div>'
                    : '<div class="small text-muted"><i class="fas fa-clock text-warning mr-1"></i>'
                        + '<strong> Sugeridas: ' + c.cita_sugerida + '</strong> Cita pendiente de confirmación</div>';

                // Motivo del descarte (sólo en tarjetas descartadas).
                var motivo = (esDescartado && c.motivo_descarte)
                    ? '<div class="small text-danger"><i class="fas fa-ban mr-1"></i>' + escHtml(c.motivo_descarte) + '</div>'
                    : '';

                var cv = c.cv_archivo
                    ? '<a href="descargar.php?tipo=cv&id=' + c.id + '" target="_blank" class="btn btn-sm btn-primary mr-2"><i class="fas fa-folder-open mr-1"></i>Ver CV</a>'
                    : '';
                // Acciones según la etapa: aprobar/descartar el CV cuando está por
                // revisar; aprobar/descartar el resultado de TU entrevista cuando ya
                // se confirmó el horario (punto 15: lo captura el jefe).
                var acc = '';
                if (c.estatus === 'enviado_solicitante') {
                    acc = '<button class="btn btn-sm btn-success btnAprobar mr-1" data-id="' + c.id + '"><i class="fas fa-check mr-1"></i>Aprobar</button>'
                        + '<button class="btn btn-sm btn-outline-danger btnDescartar" data-id="' + c.id + '"><i class="fas fa-times mr-1"></i>Descartar</button>';
                } else if (c.estatus === 'entrevista_confirmada') {
                    acc = '<button class="btn btn-sm btn-success btnResultadoOk mr-1" data-id="' + c.id + '"><i class="fas fa-check mr-1"></i>Aprobó</button>'
                        + '<button class="btn btn-sm btn-outline-danger btnResultadoNo" data-id="' + c.id + '"><i class="fas fa-times mr-1"></i>Descartó</button>';
                }

                $c.append(
                    '<div class="col-md-4 mb-3"><div class="card h-100 shadow-sm"' + (esDescartado ? ' style="opacity:.6"' : '') + '><div class="card-body d-flex flex-column">'
                    + '<div class="d-flex justify-content-between align-items-start mb-1">'
                    + '<span class="text-muted small">' + folioCandidato(c.id) + '</span>'
                    + badgeestatusCandidato(c.estatus, estatusS[c.estatus] || c.estatus) + '</div>'
                    + '<div class="fw-700" style="font-size:1.05rem">' + escHtml(c.nombre) + '</div>'
                    + '<div class="text-muted small mb-2">' + escHtml(c.folio) + ' · ' + escHtml(c.puesto) + '</div>'
                    + '<hr class="my-2">'
                    + rrhh + psico + miEnt + motivo
                    + '<div class="mt-auto pt-3">' + cv + acc + '</div>'
                    + '</div></div></div>'
                );
            });
    }

    $('#misCandidatos').on('click', '.btnAprobar', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Aprobar y proponer entrevista',
            html: '<p class="small text-muted mb-2">Ofrece dos opciones de fecha y hora para <strong>tu</strong> entrevista. '
                + 'Pueden ser el <strong>mismo día a distinta hora</strong>; solo tienen que ser distintas y futuras.</p>'
                + '<input type="datetime-local" id="sw_op1" class="swal2-input">'
                + '<input type="datetime-local" id="sw_op2" class="swal2-input">'
                + '<textarea id="sw_notas" class="swal2-textarea" placeholder="Comentarios para RRHH (opcional)."></textarea>',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Aprobar', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                var o1 = document.getElementById('sw_op1').value;
                var o2 = document.getElementById('sw_op2').value;
                if (!o1 || !o2) { Swal.showValidationMessage('Indica las dos fechas.'); return false; }
                return { opcion1: o1, opcion2: o2, notas: document.getElementById('sw_notas').value };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_solicitante.php', {
                accion: 'aprobar_cv', id: id,
                opcion1: r.value.opcion1, opcion2: r.value.opcion2, notas: r.value.notas
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // ── Levantar una requisición (punto 2: el jefe la pide) ──
    if (puedeSolicitar) {
        var puestosCargados = false;

        // Muestra/oculta duración + motivo según el tipo. Alterna `required` con el
        // show/hide (un `required` oculto rompe el submit) y se llama a mano tras el
        // reset (que no dispara change).
        function toggleTemporalSol() {
            var esTemporal = $('#sol_tipo').val() === 'temporal';
            $('#sol_temporal_fields').toggle(esTemporal);
            $('#sol_duracion, #sol_motivo_temporal').prop('required', esTemporal);
            if (!esTemporal) { $('#sol_duracion').val(''); $('#sol_motivo_temporal').val(''); }
        }
        $('#sol_tipo').on('change', toggleTemporalSol);

        $('#btnSolicitar').on('click', function () {
            $('#formSolicitar')[0].reset();
            $('#sol_tipo').val('permanente');
            toggleTemporalSol();
            if (puestosCargados) { $('#modalSolicitar').modal('show'); return; }
            // El catálogo se pide una sola vez y solo cuando hace falta.
            ajaxPost('acciones_solicitante.php', { accion: 'catalogos' }, function (err, res) {
                if (err || !res || !res.success) {
                    mostrarToast((res && res.message) || 'No se pudo cargar el catálogo de puestos.', 'error');
                    return;
                }
                var o = '<option value="">Seleccionar…</option>';
                res.puestos.forEach(function (p) { o += '<option value="' + p.id + '">' + escHtml(p.puesto) + '</option>'; });
                $('#sol_puesto').html(o);
                puestosCargados = true;
                $('#modalSolicitar').modal('show');
            });
        });

        // Al dar clic en el botón de "Nueva Solicitud de Puesto"
        $('#btnNuevaSolicitudPuesto').on('click', function () {
            var noEmpActual = getCookie('noEmpleado');
            
            // Asignar el jefe solicitante automáticamente desde la cookie
            $('#jefe_solicitante').val(noEmpActual);

            // Cargar los catálogos y abrir el modal
            cargarCatalogosSolicitud(function () {
                $('#modalSolicitudPuesto').modal('show');
            });
        })

        $('#formSolicitar').on('submit', function (e) {
            e.preventDefault();
            var data = $(this).serializeArray();
            data.push({ name: 'accion', value: 'solicitar_vacante' });
            ajaxPost('acciones_solicitante.php', data, function (err, res) {
                if (res && res.success) {
                    $('#modalSolicitar').modal('hide');
                    mostrarToast(res.message, 'success');
                    cargarVacantes();
                } else { mostrarToast((res && res.message) || 'No se pudo enviar.', 'error'); }
            });
        });
    }

    $('#misCandidatos').on('click', '.btnDescartar', function () {
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

    // ── Resultado de la entrevista del jefe (punto 15: lo captura el propio jefe) ──
    $('#misCandidatos').on('click', '.btnResultadoOk', function () {
        var id = $(this).data('id');
        // Al aprobar es cuando el jefe ya sabe que esta persona entra y qué va a
        // necesitar: se aprovecha para preguntarle por la lista de herramientas.
        // SIVAC no la guarda —se la pasa él a Almacén— pero sí registra si ya lo
        // hizo, y eso es lo que Almacén lee en el aviso del alta.
        Swal.fire({
            title: '¿Aprobar tras la entrevista?',
            html: '<textarea id="sw_res_notas" class="swal2-textarea" '
                + 'placeholder="Notas de la entrevista (opcional)."></textarea>'
                + '<div style="text-align:left;margin:12px 4px 0;font-size:.9rem">'
                + '<label style="cursor:pointer">'
                + '<input type="checkbox" id="sw_herramientas" style="margin-right:6px">'
                + 'Ya le envié a <strong>Almacén</strong> la lista de herramientas que va a necesitar'
                + '</label>'
                + '<div class="text-muted" style="font-size:.8rem;margin-top:4px">'
                + 'Si todavía no, déjalo sin marcar: a Almacén le llegará el aviso de que está pendiente.'
                + '</div></div>',
            showCancelButton: true, confirmButtonColor: messColor('accent'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Sí, aprobar', cancelButtonText: 'Cancelar',
            preConfirm: function () {
                return {
                    notas: document.getElementById('sw_res_notas').value,
                    herramientas: document.getElementById('sw_herramientas').checked ? 1 : 0
                };
            }
        }).then(function (r) {
            if (!r.isConfirmed) return;
            ajaxPost('acciones_solicitante.php', {
                accion: 'registrar_resultado_entrevista', id: id, resultado: 'aceptado',
                notas: r.value.notas || '', herramientas: r.value.herramientas
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    $('#misCandidatos').on('click', '.btnResultadoNo', function () {
        var id = $(this).data('id');
        Swal.fire({
            title: 'Descartar tras la entrevista', input: 'textarea', inputLabel: 'Motivo',
            showCancelButton: true, confirmButtonColor: messColor('danger'),
            background: messColor('card-bg'), color: messColor('text'),
            confirmButtonText: 'Descartar', cancelButtonText: 'Cancelar'
        }).then(function (r) {
            if (!r.isConfirmed || !r.value) return;
            ajaxPost('acciones_solicitante.php', {
                accion: 'registrar_resultado_entrevista', id: id, resultado: 'descartado', motivo: r.value
            }, function (err, res) {
                if (res && res.success) { mostrarToast(res.message, 'success'); cargarVacantes(); cargarCandidatos(); }
                else { mostrarToast((res && res.message) || 'Error.', 'error'); }
            });
        });
    });

    // - Form Submit para el Modal de Nueva Posición ──
    $('#formSolicitudPuesto').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'crear_solicitud_posicion' });

        ajaxPost('acciones_solicitante.php', data, function (err, res) {
            if (res && res.success) {
                $('#modalSolicitudPuesto').modal('hide');
                $('#formSolicitudPuesto')[0].reset();
                
                // Ocultar bloques dinámicos al limpiar el formulario
                $('#secProyectoPracticante, #secTemporal').addClass('d-none');

                mostrarToast(res.message || 'Solicitud registrada correctamente.', 'success');
                cargarSolicitudesPosicion();
            } else {
                mostrarToast((res && res.message) || 'No se pudo registrar la solicitud.', 'error');
            }
        });
    });
    
// ── Cargar Tarjetas de Solicitud de Puesto ──
    function cargarSolicitudesPosicion() {
        ajaxPost('acciones_solicitante.php', { accion: 'mis_solicitudes_posicion' }, function (err, res) {
            var $c = $('#misSolicitudesPosicion').empty();
            var listado = (res && res.data) ? res.data : (res ? Object.keys(res).filter(k => !isNaN(k)).map(k => res[k]) : []);

            if (err || !res || !res.success || !listado.length) {
                $c.html('<div class="col-12 text-center text-muted py-4 small"><i class="fas fa-folder-open mr-2"></i>No tienes solicitudes de creación de puesto registradas.</div>');
                return;
            }

            var noEmpActual = parseInt(getCookie('noEmpleado') || 0, 10);

            listado.forEach(function (s) {
                // 1. Definición de variables numéricas y roles desde el inicio
                var jefeSolicitante = parseInt(s.jefe_solicitante || 0, 10);
                var jefeInmediato   = parseInt(s.noEmpleado_J || 0, 10);

                var esElQueCapturo    = (jefeSolicitante === noEmpActual);
                var esGerenciaDirecta = (jefeInmediato === noEmpActual);
                var esDireccion       = (noEmpActual === 19 || noEmpActual === 403);
                var esJefeDireccion   = (jefeInmediato === 19 || jefeInmediato === 403);

                // 2. Condición de Edición
                var sinDictaminarAun = (s.estado_gerencia === 'Pendiente' && s.estado_direccion === 'Pendiente');
                var btnEditar = (esElQueCapturo && sinDictaminarAun)
                    ? '<button class="btn btn-sm btn-link text-primary p-0 font-weight-bold btnEditarSolicitud" data-id="' + s.id + '"><i class="fas fa-edit mr-1"></i>Editar</button>'
                    : '';

                // Badges de estado
                var badgeGerencia = s.estado_gerencia === 'Aprobado' 
                    ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Gerencia</span>' 
                    : (s.estado_gerencia === 'Rechazado' 
                        ? '<span class="badge badge-danger px-2 py-1"><i class="fas fa-times mr-1"></i>Gerencia</span>' 
                        : '<span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i>Gerencia</span>');

                var badgeDireccion = s.estado_direccion === 'Aprobado' 
                    ? '<span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i>Dirección</span>' 
                    : (s.estado_direccion === 'Rechazado' 
                        ? '<span class="badge badge-danger px-2 py-1"><i class="fas fa-times mr-1"></i>Dirección</span>' 
                        : '<span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i>Dirección</span>');

                var borderClass = 'border-left-warning';
                if (s.estado_general === 'Aprobada') borderClass = 'border-left-success';
                if (s.estado_general === 'Rechazada') borderClass = 'border-left-danger';

                var fechaFormateada = s.fecha_solicitud ? s.fecha_solicitud : '';

                // 3. Botones de dictamen según el rol
                var btnsAutorizacion = '';
                if (s.estado_general !== 'Aprobada' && s.estado_general !== 'Rechazada') {
                    if ((esGerenciaDirecta && esJefeDireccion) || (esDireccion && jefeSolicitante === noEmpActual)) {
                        if (s.estado_gerencia === 'Pendiente' || s.estado_direccion === 'Pendiente') {
                            btnsAutorizacion = '<button class="btn btn-xs btn-success mr-1 px-2 py-1 btnDictaminar" data-id="' + s.id + '" data-rol="unica" data-accion="Aprobado"><i class="fas fa-check-double mr-1"></i>Autorizar Única</button>' +
                                               '<button class="btn btn-xs btn-outline-danger px-2 py-1 btnDictaminar" data-id="' + s.id + '" data-rol="unica" data-accion="Rechazado"><i class="fas fa-times mr-1"></i>Rechazar</button>';
                        }
                    } 
                    else if (esGerenciaDirecta && s.estado_gerencia === 'Pendiente') {
                        btnsAutorizacion = '<button class="btn btn-xs btn-success mr-1 px-2 py-1 btnDictaminar" data-id="' + s.id + '" data-rol="gerencia" data-accion="Aprobado"><i class="fas fa-check mr-1"></i>Autorizar Gerencia</button>' +
                                           '<button class="btn btn-xs btn-outline-danger px-2 py-1 btnDictaminar" data-id="' + s.id + '" data-rol="gerencia" data-accion="Rechazado"><i class="fas fa-times mr-1"></i>Rechazar Gerencia</button>';
                    } 
                    else if (esDireccion && s.estado_direccion === 'Pendiente') {
                        btnsAutorizacion = '<button class="btn btn-xs btn-success mr-1 px-2 py-1 btnDictaminar" data-id="' + s.id + '" data-rol="direccion" data-accion="Aprobado"><i class="fas fa-check mr-1"></i>Autorizar Dirección</button>' +
                                           '<button class="btn btn-xs btn-outline-danger px-2 py-1 btnDictaminar" data-id="' + s.id + '" data-rol="direccion" data-accion="Rechazado"><i class="fas fa-times mr-1"></i>Rechazar Dirección</button>';
                    }
                }

                var nombreSolicitante = (s.nombres || '') + ' ' + (s.apellidos || '');

                $c.append(
                    '<div class="col-xl-4 col-md-6 mb-3">' +
                        '<div class="card h-100 shadow-sm ' + borderClass + ' mb-0">' +
                            '<div class="card-body p-3 d-flex flex-column">' +
                                
                                '<div class="d-flex justify-content-between align-items-start mb-1">' +
                                    '<h6 class="font-weight-bold text-dark mb-0 text-truncate mr-2" style="max-width: 75%;" title="' + escHtml(s.nombre_puesto) + '">' +
                                        escHtml(s.nombre_puesto) +
                                    '</h6>' +
                                    '<span class="badge badge-secondary" style="font-size: 0.7rem;">' + escHtml(s.tipo_contratacion) + '</span>' +
                                '</div>' +

                                '<div class="small text-muted mb-2">' +
                                    '<i class="fas fa-user-circle mr-1 text-primary"></i><strong class="text-dark">' + escHtml(nombreSolicitante) + '</strong>' +
                                    '<div class="mt-1" style="font-size: 0.75rem;">' +
                                        '<i class="far fa-calendar-alt mr-1"></i>' + escHtml(fechaFormateada) + ' · <i class="fas fa-users mr-1"></i>Vacantes: ' + s.numero_vacantes +
                                    '</div>' +
                                '</div>' +

                                '<div class="d-flex justify-content-between align-items-center mb-2 pt-1 border-top" style="font-size: 0.75rem;">' +
                                    '<span class="text-muted font-weight-bold">Autorizaciones:</span>' +
                                    '<div>' + badgeGerencia + ' ' + badgeDireccion + '</div>' +
                                '</div>' +

                                '<!-- Comentarios con etiquetas completas -->' +
                                (s.comentarios_gerencia || s.comentarios_direccion ? 
                                    '<div class="small text-muted mb-2 border-top pt-1 text-break" style="font-size: 0.75rem; line-height: 1.3;">' +
                                        (s.comentarios_gerencia ? '<div class="mb-1"><strong class="text-dark">Gerencia:</strong> ' + escHtml(s.comentarios_gerencia) + '</div>' : '') +
                                        (s.comentarios_direccion ? '<div><strong class="text-dark">Dirección:</strong> ' + escHtml(s.comentarios_direccion) + '</div>' : '') +
                                    '</div>' : '') +

                                (btnsAutorizacion ? '<div class="py-1 mb-2 text-center bg-light rounded">' + btnsAutorizacion + '</div>' : '') +

                                '<div class="mt-auto pt-2 border-top d-flex justify-content-between align-items-center small">' +
                                    '<button class="btn btn-sm btn-link text-info p-0 font-weight-bold btnVerDetalleSolicitud" data-id="' + s.id + '">' +
                                        '<i class="fas fa-eye mr-1"></i>Ver solicitud' +
                                    '</button>' +
                                    btnEditar +
                                '</div>' +

                            '</div>' +
                        '</div>' +
                    '</div>'
                );
            });
        });
    }

    // Toggle dinámico de secciones según tipo de contratación
    document.getElementById('edit_tipoContratacion').addEventListener('change', function() {
        const secProyecto = document.getElementById('edit_secProyectoPracticante');
        const secTemporal = document.getElementById('edit_secTemporal');
        
        if (this.value === 'Proyecto específico' || this.value === 'Practicante') {
            secProyecto.classList.remove('d-none');
        } else {
            secProyecto.classList.add('d-none');
        }

        if (this.value === 'Temporal') {
            secTemporal.classList.remove('d-none');
        } else {
            secTemporal.classList.add('d-none');
        }
    });

    // Abrir Modal en Lectura
    $('#misSolicitudesPosicion').on('click', '.btnVerDetalleSolicitud', function() {
        var id = $(this).data('id');
        cargarDetalleModal(id, true);
    });

    // Abrir Modal en Edición
    $('#misSolicitudesPosicion').on('click', '.btnEditarSolicitud', function() {
        var id = $(this).data('id');
        cargarDetalleModal(id, false);
    });

    function cargarDetalleModal(id, soloLectura) {
        // 1. Obtener la información de la solicitud desde la BD
        ajaxPost('acciones_solicitante.php', { accion: 'obtener_solicitud_posicion', id: id }, function (err, res) {
            var d = (res && res.data) ? res.data : (res && res.id ? res : null);

            if (err || !res || !res.success || !d) {
                mostrarToast('No se pudo cargar la información de la solicitud.', 'error');
                return;
            }

            // 2. PRIMERO cargar catálogos y poblar el DOM con las <option>
            cargarCatalogosSolicitud(function () {

                // 3. HASTA QUE YA EXISTAN LAS OPCIONES, asignar los valores a los selects
                $('#edit_area').val(String(d.id_area || d.area || '')).trigger('change');
                $('#edit_sede').val(String(d.id_sede || d.sede || '')).trigger('change');

                // Asignar resto de campos de texto e identificadores
                $('#edit_id').val(d.id);
                $('#edit_jefe_solicitante').val(d.jefe_solicitante);

                $('#edit_nombre_puesto').val(d.nombre_puesto);
                $('#edit_numero_vacantes').val(d.numero_vacantes);
                $('#edit_tipoContratacion').val(d.tipo_contratacion).trigger('change');
                $('#edit_especificacion_temporal').val(d.especificacion_temporal);

                // Campos de proyecto / practicante
                $('#edit_proyecto_nombre').val(d.proyecto_nombre);
                $('#edit_carreras_solicitadas').val(d.carreras_solicitadas);
                $('#edit_proyecto_objetivo').val(d.proyecto_objetivo);
                $('#edit_practicante_actividades').val(d.practicante_actividades);
                $('#edit_periodo_estimado').val(d.periodo_estimado);
                $('#edit_horario_solicitado').val(d.horario_solicitado);
                $('#edit_horas_requeridas').val(d.horas_requeridas);
                $('#edit_posibilidad_contratacion').val(d.posibilidad_contratacion);

                // Justificación y perfil
                $('#edit_motivo_necesidad').val(d.motivo_necesidad);
                $('#edit_evaluo_redistribucion').val(d.evaluo_redistribucion);
                $('#edit_justificacion_redistribucion').val(d.justificacion_redistribucion);
                $('#edit_problema_resuelve').val(d.problema_resuelve);
                $('#edit_quien_realiza_actualmente').val(d.quien_realiza_actualmente);
                $('#edit_funciones_principales').val(d.funciones_principales);
                $('#edit_riesgos_no_autorizacion').val(d.riesgos_no_autorizacion);
                $('#edit_impacto_kpis').val(d.impacto_kpis);

                $('#edit_escolaridad').val(d.escolaridad);
                $('#edit_carrera').val(d.carrera);
                $('#edit_experiencia').val(d.experiencia);
                $('#edit_conocimientos_tecnicos').val(d.conocimientos_tecnicos);
                $('#edit_software_requerido').val(d.software_requerido);

                $('#edit_sueldo_mensual_propuesto').val(d.sueldo_mensual_propuesto);
                $('#edit_accede_comisiones_bonos').val(d.accede_comisiones_bonos);
                $('#edit_fecha_ideal_ingreso').val(d.fecha_ideal_ingreso);
                $('#edit_cuenta_estacion_trabajo').val(d.cuenta_estacion_trabajo);

                // Checkboxes de equipo
                $('.edit-equipo').prop('checked', false);
                if (d.equipo_requerido) {
                    var equipos = d.equipo_requerido.split(',');
                    equipos.forEach(function (eq) {
                        $('.edit-equipo[value="' + eq.trim() + '"]').prop('checked', true);
                    });
                }

                // Habilitar / Deshabilitar según si es Ver Solicitud (Solo Lectura) o Editar
                $('#formEditarSolicitudVacante input, #formEditarSolicitudVacante select, #formEditarSolicitudVacante textarea')
                    .prop('disabled', soloLectura);
                
                $('#edit_jefe_solicitante').prop('disabled', true);

                if (soloLectura) {
                    $('#modalDetalleLabel').html('<i class="fas fa-eye mr-2"></i>Detalle de la Solicitud (Solo Lectura)');
                    $('#btnGuardarEdicion').hide();
                } else {
                    $('#modalDetalleLabel').html('<i class="fas fa-edit mr-2"></i>Editar Solicitud de Puesto');
                    $('#btnGuardarEdicion').show();
                }

                // Abrir Modal
                $('#modalDetalleSolicitud').modal('show');
            });
        });
    }

    // Submit de Actualización
    $('#formEditarSolicitudVacante').on('submit', function (e) {
        e.preventDefault();
        var data = $(this).serializeArray();
        data.push({ name: 'accion', value: 'actualizar_solicitud_posicion' });

        ajaxPost('acciones_solicitante.php', data, function (err, res) {
            if (res && res.success) {
                $('#modalDetalleSolicitud').modal('hide');
                mostrarToast(res.message || 'Solicitud actualizada correctamente.', 'success');
                cargarSolicitudesPosicion();
            } else {
                mostrarToast((res && res.message) || 'No se pudo actualizar la solicitud.', 'error');
            }
        });
    });

    // Listener para los botones de Autorizar / Rechazar
    $('#misSolicitudesPosicion').on('click', '.btnDictaminar', function () {
        var id = $(this).data('id');
        var rol = $(this).data('rol'); // 'gerencia', 'direccion', o 'unica'
        var dictamen = $(this).data('accion'); // 'Aprobado' o 'Rechazado'

        var titulo = (dictamen === 'Aprobado' ? 'Autorizar' : 'Rechazar') + ' Solicitud';
        var colorBoton = (dictamen === 'Aprobado' ? '#1cc88a' : '#e74a3b');

        Swal.fire({
            title: titulo,
            text: 'Por favor ingresa un comentario o retroalimentación:',
            input: 'textarea',
            inputPlaceholder: 'Escribe aquí la retroalimentación...',
            inputAttributes: { 'aria-label': 'Retroalimentación' },
            showCancelButton: true,
            confirmButtonColor: colorBoton,
            confirmButtonText: 'Confirmar',
            cancelButtonText: 'Cancelar',
            inputValidator: (value) => {
                if (!value || !value.trim()) {
                    return '¡Debes ingresar una retroalimentación obligatoria!';
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                var comentarios = result.value.trim();
                ajaxPost('acciones_solicitante.php', {
                    accion: 'dictaminar_solicitud_posicion',
                    id: id,
                    rol: rol,
                    dictamen: dictamen,
                    comentarios: comentarios
                }, function (err, res) {
                    if (res && res.success) {
                        mostrarToast(res.message || 'Dictamen registrado con éxito.', 'success');
                        cargarSolicitudesPosicion();
                    } else {
                        mostrarToast((res && res.message) || 'No se pudo registrar el dictamen.', 'error');
                    }
                });
            }
        });
    });


    cargarVacantes();
    cargarCandidatos();
    cargarSolicitudesPosicion();

    // Obtener el noEmpleado desde la cookie
    function getCookie(name) {
        var value = "; " + document.cookie;
        var parts = value.split("; " + name + "=");
        if (parts.length === 2) return parts.pop().split(";").shift();
        return "";
    }

    function cargarCatalogosSolicitud(callback) {
        ajaxPost('acciones_solicitante.php', { accion: 'obtener_catalogos_solicitud' }, function (err, res) {
            // Manejo seguro del objeto de datos
            var data = (res && res.data) ? res.data : (res || {});
            var areas = data.areas || [];
            var sedes = data.sedes || [];

            if (err || !res || !res.success) {
                console.error('Error al obtener catálogos:', err || res);
                if (typeof callback === 'function') callback();
                return;
            }

            var $area = $('#area, #edit_area').empty().append('<option value="">Selecciona un área...</option>');
            var $sede = $('#sede, #edit_sede').empty().append('<option value="">Selecciona una sede...</option>');

            areas.forEach(function (a) {
                $area.append('<option value="' + a.id + '">' + escHtml(a.departamento) + '</option>');
            });

            sedes.forEach(function (s) {
                $sede.append('<option value="' + s.id + '">' + escHtml(s.region) + '</option>');
            });

            if (typeof callback === 'function') callback();
        });
    }

});
</script>
</body>
</html>