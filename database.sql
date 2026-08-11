-- ============================================================================
-- SIVAC — Sistema de Vacantes y Contratación (MESS)
-- Esquema completo de la base de datos mess_sivac.
--
-- ESTE ES EL ÚNICO ARCHIVO SQL DEL PROYECTO. Cualquier cambio de esquema se
-- edita AQUÍ; no se crean scripts de migración ni de datos demo aparte.
-- Instalar o reinstalar = ejecutar este archivo:
--
--   mysql -u USER -p --default-character-set=utf8mb4 < database.sql
--
-- (el --default-character-set importa: sin él los acentos se corrompen).
--
-- Idempotente: se puede ejecutar más de una vez sin romper datos existentes
-- (CREATE TABLE IF NOT EXISTS + INSERT IGNORE en los catálogos).
--
-- OJO, la otra cara de lo mismo: si una tabla YA existe, este script NO la
-- altera. Mientras la BD sea desechable (hoy lo es: SIVAC no está en
-- producción) reinstalar es la vía. El día que haya datos que no se puedan
-- perder, un cambio de esquema tendrá que aplicarse a mano con ALTER.
--
-- En cPanel: crear la BD desde el panel (respetando el prefijo de la cuenta)
-- y ejecutar este script SIN la línea CREATE DATABASE si el panel la pre-crea.
-- El usuario MySQL de la aplicación necesita además SELECT sobre mess_rrhh
-- (usuarios, departamento, puesto, region).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mess_sivac
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mess_sivac;

-- ----------------------------------------------------------------------------
-- Vacantes / requisiciones de personal
-- departamento = id en mess_rrhh.departamento (cross-DB, sin FK física)
-- id_puesto    = id en mess_rrhh.puesto (catálogo); `puesto` conserva el nombre
--   como snapshot legible para que el histórico no cambie si RRHH lo renombra.
-- region       = id en mess_rrhh.region — snapshot del solicitante al crear.
-- tipo         = tipo de contratación real. 'practicas' recorta el flujo (se
--   salta la propuesta económica); 'temporal' y 'permanente' llevan el flujo
--   estándar. 'temporal' además captura duracion_meses + motivo_temporal.
-- no_empleado_solicitante = dueño de la vacante (aprueba CVs, da disponibilidad)
-- origen       = quién levantó la requisición. 'solicitante' (el jefe) nace en
--   'pendiente_vobo' y requiere VoBo de RRHH; 'rrhh' nace 'abierta'.
-- creador_por = quién capturó la vacante (RRHH o el propio solicitante).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vacantes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folio         VARCHAR(20)  NOT NULL COMMENT 'VAC-AAAA-#### generado por el sistema',
    puesto        VARCHAR(150) NOT NULL COMMENT 'nombre del puesto (snapshot de mess_rrhh.puesto)',
    id_puesto     INT UNSIGNED NULL COMMENT 'id de mess_rrhh.puesto (catálogo, sin FK física)',
    tipo          ENUM('temporal','permanente','practicas') NOT NULL DEFAULT 'permanente'
                  COMMENT 'tipo de contratación; practicas = flujo corto (sin propuesta)',
    duracion_meses  SMALLINT UNSIGNED NULL COMMENT 'sólo temporal: duración en meses',
    motivo_temporal VARCHAR(255) NULL COMMENT 'sólo temporal: motivo de la contratación',
    departamento  INT UNSIGNED NOT NULL COMMENT 'id de mess_rrhh.departamento (área solicitante)',
    region        INT UNSIGNED NULL COMMENT 'id de mess_rrhh.region — snapshot del solicitante',
    no_empleado_solicitante INT UNSIGNED NOT NULL COMMENT 'dueño: aprueba CVs y da disponibilidad',
    descripcion   TEXT NULL COMMENT 'descripción y requisitos del puesto',
    posiciones    INT UNSIGNED NOT NULL DEFAULT 1,
    estatus       ENUM('pendiente_vobo','abierta','en_proceso','pausada',
                       'cerrada','cancelada','rechazada')
                  NOT NULL DEFAULT 'abierta',
    motivo_cancelacion TEXT NULL,
    creador_por   INT UNSIGNED NOT NULL COMMENT 'quién capturó la vacante',
    origen        ENUM('rrhh','solicitante') NOT NULL DEFAULT 'rrhh'
                  COMMENT 'quién levantó la requisición',
    vobo_por      INT UNSIGNED NULL COMMENT 'RRHH que dio el visto bueno',
    vobo_fecha    DATETIME NULL,
    motivo_rechazo TEXT NULL COMMENT 'por qué RRHH rechazó la requisición',
    fecha_cierre  DATETIME NULL,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vacante_folio (folio),
    KEY idx_vacante_estado (estatus),
    KEY idx_vacante_solicitante (no_empleado_solicitante),
    KEY idx_vacante_depto (departamento),
    KEY idx_vacante_region (region),
    KEY idx_vacante_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Candidatos (captura interna por RRHH; CV validado por firma de bytes)
-- El estatus SOLO se cambia vía includes/flujo.php (mapa de transiciones).
-- El pipeline arranca en 'aspirante' y la aprobación del solicitante es
-- 'aprobado_jefe' (vocabulario de la BD).
--
-- Hay UNA sola entrevista agendada en el sistema: la del jefe
-- (entrevista_confirmada → entrevistado). La entrevista de RRHH ocurre FUERA del
-- sistema y deja constancia en entrevista_rrhh_fecha / entrevista_rrhh_resultado;
-- esa constancia es obligatoria para poder enviar el candidato al jefe. El ENUM
-- es la unión de ambas ramas; qué transiciones son válidas depende de
-- vacantes.tipo y lo resuelve includes/flujo.php (temporal/permanente pasan por
-- propuesta económica; practicas se la salta).
-- El bloque psicometrico_* es PURAMENTE INFORMATIVO: no bloquea transiciones ni
-- descarta a nadie; se muestra al jefe incluidos los 'no_apto'.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidatos (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_vacante   INT UNSIGNED NOT NULL,
    nombre       VARCHAR(150) NOT NULL,
    apellidos    VARCHAR(500) NOT NULL,
    correo       VARCHAR(150) NOT NULL,
    telefono     VARCHAR(30)  NULL,
    cv_archivo   VARCHAR(100) NULL COMMENT 'nombre aleatorio en uploads/cv/',
    cv_nombre_original VARCHAR(255) NULL,
    cv_tamano    INT UNSIGNED NULL COMMENT 'bytes',
    estatus ENUM('aspirante','enviado_solicitante','aprobado_jefe',
                'entrevista_confirmada','entrevistado',
                'propuesta_enviada','propuesta_expirada','propuesta_aceptada',
                'documentacion','contratado','descartado')
           NOT NULL DEFAULT 'aspirante',
    entrevista_rrhh_fecha    DATE NULL COMMENT 'constancia: fecha de la entrevista de RRHH (fuera del sistema)',
    entrevista_rrhh_resultado ENUM('apto','no_apto') NULL COMMENT 'constancia: veredicto de la entrevista de RRHH',
    entrevista_rrhh_observaciones TEXT NULL COMMENT 'constancia: observaciones de la entrevista de RRHH',
    psicometrico_fecha         DATE NULL COMMENT 'psicométrico (informativo): fecha de aplicación',
    psicometrico_calificacion  VARCHAR(30) NULL COMMENT 'string libre a propósito; sin lógica numérica encima',
    psicometrico_resultado     ENUM('apto','no_apto') NULL COMMENT 'informativo: NO bloquea transiciones ni descarta',
    psicometrico_observaciones TEXT NULL COMMENT 'psicométrico: observaciones',
    etapa_descarte  VARCHAR(50) NULL COMMENT 'etapa en la que fue descartado',
    motivo_descarte TEXT NULL,
    nave    INT UNSIGNED NULL COMMENT 'id de mess_rrhh.nave (catálogo, sin FK); lo asigna RRHH, viaja al alta como SEDE',
    region  INT UNSIGNED NULL COMMENT 'id de mess_rrhh.region (catálogo, sin FK); asignación para el alta',
    -- La lista de herramientas se la pasa el JEFE a Almacén por su cuenta; SIVAC
    -- sólo guarda que ya lo hizo, para decírselo a Almacén en el aviso del alta.
    -- Lo marca el jefe al aprobar al candidato en su entrevista.
    herramientas_notificadas TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'el jefe ya envió la lista de herramientas a Almacén',
    creador_por  INT UNSIGNED NOT NULL,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cand_vacante (id_vacante),
    KEY idx_cand_estado (estatus),
    CONSTRAINT fk_cand_vacante FOREIGN KEY (id_vacante)
        REFERENCES vacantes (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Historial de transiciones (auditoría; solo lo escribe includes/flujo.php)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidatos_historial (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato    INT UNSIGNED NOT NULL,
    estatus_anterior VARCHAR(30) NOT NULL,
    estatus_nuevo    VARCHAR(30) NOT NULL,
    no_empleado     INT UNSIGNED NOT NULL COMMENT 'quién ejecutó la transición',
    comentario      TEXT NULL,
    fecha_creacion  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_hist_candidato (id_candidato),
    CONSTRAINT fk_hist_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Citas de entrevista: el solicitante da 2 opciones, RRHH confirma una.
-- 1:N — reprogramar = cancelar la vigente y crear una nueva.
-- En el sistema solo se agenda la entrevista del jefe/solicitante; la de RRHH
--   ocurre fuera y solo deja constancia en la tabla candidatos. tipo se conserva
--   como ENUM por compatibilidad, pero hoy siempre vale 'jefe'.
-- notas = comentarios de quien agenda (contexto para el entrevistador); viajan
--   en el correo de confirmación.
-- duracion_aprox = NOT NULL (la app la inserta vacía si no se captura).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citas (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato  INT UNSIGNED NOT NULL,
    tipo          ENUM('jefe') NOT NULL DEFAULT 'jefe'
                  COMMENT 'entrevista del jefe/solicitante (única agendada en el sistema)',
    opcion1       DATETIME NOT NULL,
    opcion2       DATETIME NOT NULL,
    estatus       ENUM('pendiente','confirmada','realizada','cancelada')
                  NOT NULL DEFAULT 'pendiente',
    opcion_confirmada TINYINT(1) NULL COMMENT '1 u 2',
    fecha_confirmada  DATETIME NULL COMMENT 'fecha/hora final de la entrevista',
    duracion_aprox VARCHAR(100) NOT NULL,
    confirmada_por    INT UNSIGNED NULL COMMENT 'RRHH que confirmó',
    notas         TEXT NULL,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cita_candidato (id_candidato),
    KEY idx_cita_estado (estatus),
    CONSTRAINT fk_cita_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Propuestas económicas con periodo de caducidad.
-- 1:N — si expira se puede reenviar una nueva.
-- capturado_por = RRHH que registró la propuesta.
-- documento = NOT NULL (la app lo inserta vacío por ahora).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS propuestas (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato  INT UNSIGNED NOT NULL,
    condiciones   TEXT NULL COMMENT 'resumen de condiciones enviadas',
    fecha_envio   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_caducidad DATE NOT NULL,
    estatus       ENUM('enviada','aceptada','rechazada','expirada')
                  NOT NULL DEFAULT 'enviada',
    fecha_respuesta DATETIME NULL,
    capturado_por INT UNSIGNED NOT NULL,
    documento     VARCHAR(250) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_prop_candidato (id_candidato),
    KEY idx_prop_estado_caducidad (estatus, fecha_caducidad),
    CONSTRAINT fk_prop_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Contrataciones (cierre): 1:1 con candidato en documentación/alta
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS contrataciones (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato  INT UNSIGNED NOT NULL,
    fecha_ingreso DATE NULL,
    fecha_limite_documentos DATE NULL,
    prorrogas     INT UNSIGNED NOT NULL DEFAULT 0,
    reglamento_enviado DATETIME NULL COMMENT 'cuándo se envió el reglamento de ingreso',
    alta_notificada    DATETIME NULL COMMENT 'cuándo se avisó a las áreas del catálogo',
    -- Datos que sólo sirven para los avisos del alta: los teclea RRHH al
    -- completar la contratación, no salen de ningún otro lado del proceso.
    -- Los tres flags van a Cuenta de gastos y a Sistemas.
    req_viaticos  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '¿necesita tarjeta de viáticos?',
    req_celular   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '¿necesita celular?',
    req_equipo    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '¿necesita computadora o laptop?',
    estatus       ENUM('documentacion','completada','cancelada')
                  NOT NULL DEFAULT 'documentacion',
    notas         TEXT NULL,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_contr_candidato (id_candidato),
    CONSTRAINT fk_contr_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Catálogo de tipos de documento requeridos para el alta
-- estatus = activo/inactivo (la app lo expone como 'activo' vía alias).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos_tipos (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre      VARCHAR(100) NOT NULL,
    obligatorio TINYINT(1) NOT NULL DEFAULT 1,
    estatus     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doctipo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Documentos subidos del seleccionado (formato validado por firma de bytes).
-- validacion = revisión de RRHH sobre el contenido: nace 'pendiente' y RRHH lo
--   marca 'validado' o 'rechazado' (con motivo). El alta exige los documentos
--   obligatorios en 'validado', no solo subidos.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS documentos (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato   INT UNSIGNED NOT NULL,
    id_tipo        INT UNSIGNED NOT NULL,
    nombre_archivo VARCHAR(100) NOT NULL COMMENT 'nombre aleatorio en uploads/documentos/',
    nombre_original VARCHAR(255) NOT NULL,
    mime           VARCHAR(50)  NOT NULL,
    tamano         INT UNSIGNED NOT NULL COMMENT 'bytes',
    subido_por     INT UNSIGNED NOT NULL,
    origen         ENUM('rrhh','candidato') NOT NULL DEFAULT 'rrhh'
                   COMMENT 'quién subió el archivo: RRHH o el candidato por su portal',
    validacion     ENUM('pendiente','validado','rechazado') NOT NULL DEFAULT 'pendiente'
                   COMMENT 'revisión de RRHH sobre el documento',
    validado_por   INT UNSIGNED NULL COMMENT 'RRHH que validó/rechazó',
    validado_fecha DATETIME NULL,
    motivo_validacion VARCHAR(255) NULL COMMENT 'motivo del rechazo',
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_doc_candidato (id_candidato),
    KEY fk_doc_tipo (id_tipo),
    CONSTRAINT fk_doc_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE,
    CONSTRAINT fk_doc_tipo FOREIGN KEY (id_tipo)
        REFERENCES documentos_tipos (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Catálogo de destinatarios de aviso al completar un alta
--
-- `clave` dice QUÉ correo recibe cada quien: cada área pide datos distintos
-- (Nóminas asigna el número de empleado, Sistemas los accesos, Almacén las herramientas),
-- así que el cuerpo se arma por clave — ver sivacCorreosAlta() en
-- includes/alta_avisos.php. `area` es sólo la etiqueta que se muestra.
-- Puede haber VARIAS filas con la misma clave (p. ej. Sistemas son dos personas).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notificaciones_destinatarios (
    id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave  VARCHAR(20) NOT NULL DEFAULT '' COMMENT 'nominas|gastos|marketing|sistemas|almacen',
    area   VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    KEY idx_dest_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Notificaciones: BITÁCORA de eventos y de los correos enviados.
-- no_empleado_destino NULL = evento solo de correo externo (candidato/áreas)
--
-- OJO: la CAMPANA no vive aquí. Los avisos a empleados se publican en
-- mess_rrhh.notificacion_historial con sistema = 'sivac', que es la tabla que
-- comparten todos los sistemas del portal y la que lee el badge de loginMaster
-- (ver includes/notificaciones.php). La columna `leida` de esta tabla quedó
-- como histórico: el estado de lectura vive en notificacion_historial.estatus.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notificaciones (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    no_empleado_destino INT UNSIGNED NULL,
    id_vacante    INT UNSIGNED NULL,
    id_candidato  INT UNSIGNED NULL,
    evento        VARCHAR(50)  NOT NULL,
    titulo        VARCHAR(200) NOT NULL,
    mensaje       TEXT NULL,
    url           VARCHAR(300) NULL COMMENT 'ruta interna sugerida al hacer clic',
    leida         TINYINT(1) NOT NULL DEFAULT 0,
    correo_enviado TINYINT(1) NOT NULL DEFAULT 0,
    correo_destinatarios VARCHAR(500) NULL,
    correo_error  VARCHAR(255) NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_destino_leida (no_empleado_destino, leida),
    KEY idx_notif_candidato (id_candidato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Accesos de consulta (vista read-only global, p. ej. dirección)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accesos_consulta (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    no_empleado INT UNSIGNED NOT NULL,
    comentario  VARCHAR(200) NULL,
    activo      TINYINT(1) NOT NULL DEFAULT 1,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_consulta_empleado (no_empleado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Accesos por token del portal del candidato (Fase B).
-- Única superficie pública de SIVAC: el candidato entra por portal.php?t=<token>
-- SIN pasar por loginMaster. Se guarda SOLO el hash SHA-256 del token (nunca el
-- token en claro), mismo criterio que una contraseña: si se filtra la BD, los
-- enlaces no sirven. Desactivar = poner activo=0 (invalida el token).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidato_accesos (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato INT UNSIGNED NOT NULL,
    token_hash   CHAR(64) NOT NULL COMMENT 'hash SHA-256 del token; el claro nunca se guarda',
    fecha_expira DATETIME NOT NULL,
    activo       TINYINT(1) NOT NULL DEFAULT 1,
    usos         INT UNSIGNED NOT NULL DEFAULT 0,
    ultimo_uso   DATETIME NULL,
    creado_por   INT UNSIGNED NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_acceso_token (token_hash),
    KEY idx_acceso_candidato (id_candidato),
    CONSTRAINT fk_acceso_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Datos fiscales / de alta capturados por el candidato en su portal (1:1).
-- Es el CONTRATO DE ENTREGA hacia gestionPersonal: los nombres de campo espejan
-- el alta de allá para que jalar los datos sea un SELECT directo. SIVAC NUNCA
-- escribe en mess_rrhh: marca listo_para_alta=1 y gestionPersonal jala desde aquí.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS candidatos_datos_alta (
    id_candidato INT UNSIGNED NOT NULL,
    curp         VARCHAR(18)  NULL,
    rfc          VARCHAR(13)  NULL,
    nss          VARCHAR(11)  NULL,
    sexo         ENUM('M','F') NULL,
    fecha_nacimiento DATE NULL,
    tipo_sangre  VARCHAR(15)  NULL,
    no_empleado_asignado VARCHAR(11) NULL COMMENT 'lo decide RRHH; SIVAC no lo sabe',
    correo_corporativo   VARCHAR(150) NULL COMMENT 'lo crea TI; SIVAC no lo sabe',
    listo_para_alta TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'bandera que lee gestionPersonal',
    fecha_listo  DATETIME NULL,
    alta_aplicada TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'gestionPersonal lo marca al dar de alta',
    fecha_alta   DATETIME NULL,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id_candidato),
    CONSTRAINT fk_datosalta_candidato FOREIGN KEY (id_candidato)
        REFERENCES candidatos (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================================
-- Datos semilla (INSERT IGNORE — no duplican en re-ejecución)
-- ============================================================================
INSERT IGNORE INTO documentos_tipos (nombre, obligatorio, estatus) VALUES
    ('INE (identificación oficial)', 1, 1),
    ('CURP', 1, 1),
    ('RFC (constancia de situación fiscal)', 1, 1),
    ('NSS (número de seguro social)', 1, 1),
    ('Acta de nacimiento', 1, 1),
    ('Comprobante de domicilio', 1, 1),
    ('Comprobante de estudios', 1, 1),
    ('Carátula de cuenta bancaria', 1, 1),
    ('Fotografía', 0, 1),
    ('Carta de recomendación', 0, 1);

-- Las 5 áreas que se avisan al completar un alta. El CORREO va vacío a
-- propósito: lo carga RRHH desde Configuración → Destinatarios (cada área puede
-- tener más de una persona). Mientras esté vacío, esa área simplemente no recibe
-- el aviso y `completar_alta` lo reporta como pendiente en vez de fallar.
INSERT IGNORE INTO notificaciones_destinatarios (id, clave, area, correo, activo) VALUES
    (1, 'nominas',   'Nóminas',          '', 1),
    (2, 'gastos',    'Cuenta de gastos', '', 1),
    (3, 'marketing', 'Marketing',        '', 1),
    (4, 'sistemas',  'Sistemas',         '', 1),
    (5, 'almacen',   'Almacén',          '', 1);
