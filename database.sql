-- ============================================================================
-- SIVAC — Sistema de Vacantes y Contratación (MESS)
-- Esquema de la base de datos mess_sivac  (refleja la BD real en producción)
--
-- Idempotente: se puede ejecutar más de una vez sin romper datos existentes.
-- En cPanel: crear la BD desde el panel (respetando el prefijo de la cuenta)
-- y ejecutar este script SIN la línea CREATE DATABASE si el panel la pre-crea.
-- El usuario MySQL de la aplicación necesita además SELECT sobre mess_rrhh.
-- ============================================================================

CREATE DATABASE IF NOT EXISTS mess_sivac
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE mess_sivac;

-- ----------------------------------------------------------------------------
-- Vacantes / requisiciones de personal
-- departamento = id en mess_rrhh.departamento (cross-DB, sin FK física)
-- no_empleado_solicitante = dueño de la vacante (aprueba CVs, da disponibilidad)
-- publicada_en / fecha_publicada / url_publicacion = datos de publicación (OCC).
--   OJO: la app expone estos campos como occ_publicada/occ_fecha/occ_url (alias).
-- creador_por = RRHH que capturó la vacante.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS vacantes (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    folio         VARCHAR(20)  NOT NULL COMMENT 'VAC-AAAA-#### generado por el sistema',
    puesto        VARCHAR(150) NOT NULL,
    departamento  INT UNSIGNED NOT NULL COMMENT 'id de mess_rrhh.departamento (área solicitante)',
    no_empleado_solicitante INT UNSIGNED NOT NULL COMMENT 'dueño: aprueba CVs y da disponibilidad',
    descripcion   TEXT NULL COMMENT 'descripción y requisitos del puesto',
    posiciones    INT UNSIGNED NOT NULL DEFAULT 1,
    estatus       ENUM('abierta','en_proceso','pausada','cerrada','cancelada')
                  NOT NULL DEFAULT 'abierta',
    motivo_cancelacion TEXT NULL,
    publicada_en  VARCHAR(100) NOT NULL DEFAULT '0' COMMENT 'publicada en OCC (0/1 o medio)',
    fecha_publicada DATE NULL,
    url_publicacion VARCHAR(500) NULL,
    creador_por   INT UNSIGNED NOT NULL COMMENT 'RRHH que capturó la vacante',
    fecha_cierre  DATETIME NULL,
    fecha_creacion      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vacante_folio (folio),
    KEY idx_vacante_estado (estatus),
    KEY idx_vacante_solicitante (no_empleado_solicitante),
    KEY idx_vacante_depto (departamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Candidatos (captura interna por RRHH; CV validado por firma de bytes)
-- El estatus SOLO se cambia vía includes/flujo.php (mapa de transiciones).
-- El pipeline arranca en 'aspirante' y la aprobación del solicitante es
-- 'aprobado_jefe' (vocabulario de la BD).
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
                'psicometrico_asignado','psicometrico_presentado',
                'entrevista_confirmada','entrevistado',
                'propuesta_enviada','propuesta_expirada','propuesta_aceptada',
                'documentacion','contratado','descartado')
           NOT NULL DEFAULT 'aspirante',
    psicometrico_correo  VARCHAR(150) NULL COMMENT 'cuenta para presentar el examen externo',
    psicometrico_folio   VARCHAR(100) NULL,
    psicometrico_fecha_presentado DATETIME NULL,
    psicometrico_resultado VARCHAR(255) NULL,
    etapa_descarte  VARCHAR(50) NULL COMMENT 'etapa en la que fue descartado',
    motivo_descarte TEXT NULL,
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
-- duracion_aprox = NOT NULL (la app la inserta vacía si no se captura).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS citas (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_candidato  INT UNSIGNED NOT NULL,
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
-- documento / sueldo_propuesto = NOT NULL (la app los inserta vacíos por ahora).
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
    sueldo_propuesto VARCHAR(100) NOT NULL COMMENT 'REVISAR CON RRHH',
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
    alta_notificada    DATETIME NULL COMMENT 'cuándo se avisó a TI/viáticos/teléfono/marketing',
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
-- Documentos subidos del seleccionado (validados por firma de bytes)
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
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notificaciones_destinatarios (
    id     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    area   VARCHAR(100) NOT NULL,
    correo VARCHAR(150) NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ----------------------------------------------------------------------------
-- Notificaciones: campana in-system + bitácora de correos enviados
-- no_empleado_destino NULL = evento solo de correo externo (candidato/áreas)
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

INSERT IGNORE INTO notificaciones_destinatarios (id, area, correo, activo) VALUES
    (1, 'TI',        'ti@mess.com.mx',        1),
    (2, 'Viáticos',  'viaticos@mess.com.mx',  1),
    (3, 'Teléfono',  'telefono@mess.com.mx',  1),
    (4, 'Marketing', 'marketing@mess.com.mx', 1);
