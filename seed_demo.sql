-- ============================================================================
-- SIVAC — Datos de DEMO para la presentación
-- Un candidato de ejemplo en CADA etapa del pipeline, repartidos en 3 vacantes:
--   • VAC-2026-9001  "Analista de Datos Jr. (DEMO)"  → en proceso, embudo completo
--   • VAC-2026-9002  "Diseñador UX (DEMO)"           → cerrada, con 1 contratado
--   • VAC-2026-9003  "Auxiliar Contable (DEMO)"      → abierta, recién creada (sin candidatos)
--
-- Ajustado al esquema REAL de mess_sivac (apellidos, creador_por, publicada_en,
-- capturado_por, duracion_aprox, sueldo_propuesto; estatus 'aspirante'/'aprobado_jefe').
--
-- Cómo usar:
--   mysql -u mess_incidencias -p --default-character-set=utf8mb4 mess_sivac < seed_demo.sql
--
-- CV descargable: coloca tu PDF en  uploads/cv/cv_demo.pdf
-- (opcional) documento de alta:      uploads/documentos/doc_demo.pdf
--
-- Reejecutable: al inicio borra la corrida anterior (folios VAC-2026-90%% y
-- notificaciones con prefijo [DEMO]). Lo demás cae por ON DELETE CASCADE.
-- ============================================================================

USE mess_sivac;

/* ---------- Limpieza de una corrida previa ---------- */
DELETE FROM candidatos WHERE id_vacante IN (SELECT id FROM (SELECT id FROM vacantes WHERE folio LIKE 'VAC-2026-90%') t);
DELETE FROM vacantes   WHERE folio LIKE 'VAC-2026-90%';
DELETE FROM notificaciones WHERE titulo LIKE '[DEMO]%';

/* ---------- Actores (existen y están activos en mess_rrhh) ---------- */
SET @rrhh := 523;   -- Sebastian Gutiérrez (dept 26, RRHH) — creador / actor
SET @sol1 := 523;   -- solicitante vacante 1 (= tú, para demostrar "Mis Vacantes")
SET @sol2 := 107;   -- Brenda Elizabeth Morales (solicitante vacante 2)
SET @sol3 := 110;   -- Ángel Fernando San Juan (solicitante vacante 3)
SET @cv   := 'cv_demo.pdf';    -- coloca tu PDF en uploads/cv/cv_demo.pdf
SET @doc  := 'doc_demo.pdf';   -- (opcional) uploads/documentos/doc_demo.pdf
SET @dur  := '60 minutos';

-- ============================================================================
-- VACANTE 1 — en proceso, con un candidato en cada etapa
-- ============================================================================
INSERT INTO vacantes (folio, puesto, departamento, no_empleado_solicitante, descripcion, posiciones, estatus, publicada_en, fecha_publicada, url_publicacion, creador_por)
VALUES ('VAC-2026-9001', 'Analista de Datos Jr. (DEMO)', 26, @sol1,
        'Vacante de demostración: candidatos en todas las etapas del proceso.', 1,
        'en_proceso', '1', CURDATE(), 'https://www.occ.com.mx/empleo/demo-sivac', @rrhh);
SET @v1 := LAST_INSERT_ID();

/* -- (1) ASPIRANTE (capturado) ------------------------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus, creador_por)
VALUES (@v1, 'Ana Torres Reyes', '', 'ana.torres@example.com', '442-100-0001', @cv, 'CV Ana Torres.pdf', 145321, 'aspirante', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario)
VALUES (@c,'aspirante','aspirante',@rrhh,'Candidato capturado');

/* -- (2) ENVIADO AL SOLICITANTE ------------------------------------------ */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus, creador_por)
VALUES (@v1, 'Bruno Díaz Salas', '', 'bruno.diaz@example.com', '442-100-0002', @cv, 'CV Bruno Diaz.pdf', 152233, 'enviado_solicitante', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aspirante','aspirante',@rrhh,'Candidato capturado'),
 (@c,'aspirante','enviado_solicitante',@rrhh,'Enviado al solicitante para revisión.');

/* -- (3) APROBADO POR SOLICITANTE (con disponibilidad = cita pendiente) --- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus, creador_por)
VALUES (@v1, 'Carla Ruiz Méndez', '', 'carla.ruiz@example.com', '442-100-0003', @cv, 'CV Carla Ruiz.pdf', 160145, 'aprobado_jefe', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aspirante','aspirante',@rrhh,'Candidato capturado'),
 (@c,'aspirante','enviado_solicitante',@rrhh,'Enviado al solicitante para revisión.'),
 (@c,'enviado_solicitante','aprobado_jefe',@sol1,'CV aprobado por el solicitante; disponibilidad registrada.');
INSERT INTO citas (id_candidato, opcion1, opcion2, estatus, duracion_aprox)
VALUES (@c, DATE_ADD(CURDATE(), INTERVAL 3 DAY) + INTERVAL 10 HOUR,
            DATE_ADD(CURDATE(), INTERVAL 4 DAY) + INTERVAL 12 HOUR, 'pendiente', @dur);

/* -- (4) PSICOMÉTRICO ASIGNADO ------------------------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_correo, psicometrico_folio, creador_por)
VALUES (@v1, 'Diego Peña Ortiz', '', 'diego.pena@example.com', '442-100-0004', @cv, 'CV Diego Pena.pdf', 138990, 'psicometrico_asignado',
        'diego.pena@example.com', 'PSI-2026-0004', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aspirante','enviado_solicitante',@rrhh,'Enviado al solicitante para revisión.'),
 (@c,'enviado_solicitante','aprobado_jefe',@sol1,'CV aprobado por el solicitante.'),
 (@c,'aprobado_jefe','psicometrico_asignado',@rrhh,'Psicométrico asignado (folio PSI-2026-0004).');

/* -- (5) PSICOMÉTRICO PRESENTADO ----------------------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_correo, psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Elena Marín Cano', '', 'elena.marin@example.com', '442-100-0005', @cv, 'CV Elena Marin.pdf', 149870, 'psicometrico_presentado',
        'elena.marin@example.com', 'PSI-2026-0005', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Apto — perfil compatible', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aprobado_jefe','psicometrico_asignado',@rrhh,'Psicométrico asignado (folio PSI-2026-0005).'),
 (@c,'psicometrico_asignado','psicometrico_presentado',@rrhh,'Psicométrico presentado (Apto).');

/* -- (6) ENTREVISTA CONFIRMADA (cita confirmada) ------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Fabián Cruz Ledesma', '', 'fabian.cruz@example.com', '442-100-0006', @cv, 'CV Fabian Cruz.pdf', 151002, 'entrevista_confirmada',
        'PSI-2026-0006', DATE_SUB(NOW(), INTERVAL 2 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aprobado_jefe','psicometrico_asignado',@rrhh,'Psicométrico asignado.'),
 (@c,'psicometrico_asignado','psicometrico_presentado',@rrhh,'Psicométrico presentado.'),
 (@c,'psicometrico_presentado','entrevista_confirmada',@rrhh,'Entrevista confirmada.');
INSERT INTO citas (id_candidato, opcion1, opcion2, estatus, opcion_confirmada, fecha_confirmada, duracion_aprox, confirmada_por)
VALUES (@c, DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 11 HOUR,
            DATE_ADD(CURDATE(), INTERVAL 3 DAY) + INTERVAL 16 HOUR,
            'confirmada', 1, DATE_ADD(CURDATE(), INTERVAL 2 DAY) + INTERVAL 11 HOUR, @dur, @rrhh);

/* -- (7) ENTREVISTADO (listo para propuesta; cita realizada) ------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Gabriela Soto Rivas', '', 'gabriela.soto@example.com', '442-100-0007', @cv, 'CV Gabriela Soto.pdf', 158441, 'entrevistado',
        'PSI-2026-0007', DATE_SUB(NOW(), INTERVAL 4 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'psicometrico_presentado','entrevista_confirmada',@rrhh,'Entrevista confirmada.'),
 (@c,'entrevista_confirmada','entrevistado',@rrhh,'Entrevista realizada: aprobado para propuesta.');
INSERT INTO citas (id_candidato, opcion1, opcion2, estatus, opcion_confirmada, fecha_confirmada, duracion_aprox, confirmada_por)
VALUES (@c, DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY) + INTERVAL 2 HOUR,
            'realizada', 1, DATE_SUB(NOW(), INTERVAL 1 DAY), @dur, @rrhh);

/* -- (8) PROPUESTA ENVIADA (vigente) ------------------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Héctor Vega Luna', '', 'hector.vega@example.com', '442-100-0008', @cv, 'CV Hector Vega.pdf', 162118, 'propuesta_enviada',
        'PSI-2026-0008', DATE_SUB(NOW(), INTERVAL 6 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'entrevista_confirmada','entrevistado',@rrhh,'Entrevista realizada: aprobado para propuesta.'),
 (@c,'entrevistado','propuesta_enviada',@rrhh,'Propuesta enviada.');
INSERT INTO propuestas (id_candidato, condiciones, fecha_envio, fecha_caducidad, estatus, capturado_por, documento, sueldo_propuesto)
VALUES (@c, 'Sueldo mensual $18,000 + prestaciones de ley + vales de despensa.', NOW(),
        DATE_ADD(CURDATE(), INTERVAL 5 DAY), 'enviada', @rrhh, '', '$18,000 mensuales');

/* -- (9) PROPUESTA EXPIRADA (lista para reenviar) ------------------------ */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Irene Nava Fuentes', '', 'irene.nava@example.com', '442-100-0009', @cv, 'CV Irene Nava.pdf', 147553, 'propuesta_expirada',
        'PSI-2026-0009', DATE_SUB(NOW(), INTERVAL 12 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'entrevistado','propuesta_enviada',@rrhh,'Propuesta enviada (caduca hace 3 días).'),
 (@c,'propuesta_enviada','propuesta_expirada',0,'Propuesta expirada automáticamente por vencimiento.');
INSERT INTO propuestas (id_candidato, condiciones, fecha_envio, fecha_caducidad, estatus, capturado_por, documento, sueldo_propuesto)
VALUES (@c, 'Sueldo mensual $17,500 + prestaciones de ley.', DATE_SUB(NOW(), INTERVAL 10 DAY),
        DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'expirada', @rrhh, '', '$17,500 mensuales');

/* -- (10) PROPUESTA ACEPTADA --------------------------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Javier Luna Prado', '', 'javier.luna@example.com', '442-100-0010', @cv, 'CV Javier Luna.pdf', 155790, 'propuesta_aceptada',
        'PSI-2026-0010', DATE_SUB(NOW(), INTERVAL 8 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'entrevistado','propuesta_enviada',@rrhh,'Propuesta enviada.'),
 (@c,'propuesta_enviada','propuesta_aceptada',@rrhh,'Propuesta aceptada por el candidato.');
INSERT INTO propuestas (id_candidato, condiciones, fecha_envio, fecha_caducidad, estatus, fecha_respuesta, capturado_por, documento, sueldo_propuesto)
VALUES (@c, 'Sueldo mensual $18,500 + prestaciones superiores.', DATE_SUB(NOW(), INTERVAL 3 DAY),
        DATE_ADD(CURDATE(), INTERVAL 4 DAY), 'aceptada', NOW(), @rrhh, '', '$18,500 mensuales');

/* -- (11) EN DOCUMENTACIÓN (contratación en curso, faltan documentos) ---- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v1, 'Karla Ibarra Solís', '', 'karla.ibarra@example.com', '442-100-0011', @cv, 'CV Karla Ibarra.pdf', 168004, 'documentacion',
        'PSI-2026-0011', DATE_SUB(NOW(), INTERVAL 9 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'entrevistado','propuesta_enviada',@rrhh,'Propuesta enviada.'),
 (@c,'propuesta_enviada','propuesta_aceptada',@rrhh,'Propuesta aceptada por el candidato.'),
 (@c,'propuesta_aceptada','documentacion',@rrhh,'Inicia proceso de documentación.');
INSERT INTO propuestas (id_candidato, condiciones, fecha_envio, fecha_caducidad, estatus, fecha_respuesta, capturado_por, documento, sueldo_propuesto)
VALUES (@c, 'Sueldo mensual $19,000 + prestaciones superiores.', DATE_SUB(NOW(), INTERVAL 5 DAY),
        DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'aceptada', DATE_SUB(NOW(), INTERVAL 2 DAY), @rrhh, '', '$19,000 mensuales');
INSERT INTO contrataciones (id_candidato, fecha_limite_documentos, estatus)
VALUES (@c, DATE_ADD(CURDATE(), INTERVAL 10 DAY), 'documentacion');
-- 3 de los documentos obligatorios ya entregados (faltan el resto → demo de "completar alta")
INSERT INTO documentos (id_candidato, id_tipo, nombre_archivo, nombre_original, mime, tamano, subido_por)
SELECT @c, id, @doc, CONCAT(nombre, '.pdf'), 'application/pdf', 234567, @rrhh
FROM documentos_tipos
WHERE nombre IN ('INE (identificación oficial)', 'CURP', 'RFC (constancia de situación fiscal)');

-- ============================================================================
-- VACANTE 2 — cerrada, con un contratado (proceso terminado)
-- ============================================================================
INSERT INTO vacantes (folio, puesto, departamento, no_empleado_solicitante, descripcion, posiciones, estatus, publicada_en, fecha_publicada, creador_por, fecha_cierre)
VALUES ('VAC-2026-9002', 'Diseñador UX (DEMO)', 18, @sol2,
        'Vacante de demostración ya cerrada con un colaborador contratado.', 1,
        'cerrada', '1', DATE_SUB(CURDATE(), INTERVAL 30 DAY), @rrhh, NOW());
SET @v2 := LAST_INSERT_ID();

/* -- (12) CONTRATADO ----------------------------------------------------- */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        psicometrico_folio, psicometrico_fecha_presentado, psicometrico_resultado, creador_por)
VALUES (@v2, 'Luis Ramos Aguilar', '', 'luis.ramos@example.com', '442-100-0012', @cv, 'CV Luis Ramos.pdf', 171200, 'contratado',
        'PSI-2026-0012', DATE_SUB(NOW(), INTERVAL 20 DAY), 'Apto', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aspirante','aspirante',@rrhh,'Candidato capturado'),
 (@c,'aspirante','enviado_solicitante',@rrhh,'Enviado al solicitante para revisión.'),
 (@c,'enviado_solicitante','aprobado_jefe',@sol2,'CV aprobado por el solicitante.'),
 (@c,'aprobado_jefe','psicometrico_asignado',@rrhh,'Psicométrico asignado.'),
 (@c,'psicometrico_asignado','psicometrico_presentado',@rrhh,'Psicométrico presentado (Apto).'),
 (@c,'psicometrico_presentado','entrevista_confirmada',@rrhh,'Entrevista confirmada.'),
 (@c,'entrevista_confirmada','entrevistado',@rrhh,'Entrevista realizada: aprobado para propuesta.'),
 (@c,'entrevistado','propuesta_enviada',@rrhh,'Propuesta enviada.'),
 (@c,'propuesta_enviada','propuesta_aceptada',@rrhh,'Propuesta aceptada por el candidato.'),
 (@c,'propuesta_aceptada','documentacion',@rrhh,'Inicia proceso de documentación.'),
 (@c,'documentacion','contratado',@rrhh,'Alta completada. Fecha de ingreso registrada.');
INSERT INTO propuestas (id_candidato, condiciones, fecha_envio, fecha_caducidad, estatus, fecha_respuesta, capturado_por, documento, sueldo_propuesto)
VALUES (@c, 'Sueldo mensual $22,000 + prestaciones superiores.', DATE_SUB(NOW(), INTERVAL 18 DAY),
        DATE_SUB(CURDATE(), INTERVAL 12 DAY), 'aceptada', DATE_SUB(NOW(), INTERVAL 15 DAY), @rrhh, '', '$22,000 mensuales');
INSERT INTO contrataciones (id_candidato, fecha_ingreso, fecha_limite_documentos, reglamento_enviado, alta_notificada, estatus)
VALUES (@c, DATE_ADD(CURDATE(), INTERVAL 7 DAY), DATE_SUB(CURDATE(), INTERVAL 5 DAY),
        DATE_SUB(NOW(), INTERVAL 10 DAY), DATE_SUB(NOW(), INTERVAL 6 DAY), 'completada');
-- Todos los documentos obligatorios entregados
INSERT INTO documentos (id_candidato, id_tipo, nombre_archivo, nombre_original, mime, tamano, subido_por)
SELECT @c, id, @doc, CONCAT(nombre, '.pdf'), 'application/pdf', 234567, @rrhh
FROM documentos_tipos WHERE obligatorio = 1 AND estatus = 1;

/* -- (13) DESCARTADO (en la etapa de solicitante) ------------------------ */
INSERT INTO candidatos (id_vacante, nombre, apellidos, correo, telefono, cv_archivo, cv_nombre_original, cv_tamano, estatus,
        etapa_descarte, motivo_descarte, creador_por)
VALUES (@v1, 'Mónica Gil Herrera', '', 'monica.gil@example.com', '442-100-0013', @cv, 'CV Monica Gil.pdf', 143870, 'descartado',
        'solicitante', 'No cumple con la experiencia mínima requerida.', @rrhh);
SET @c := LAST_INSERT_ID();
INSERT INTO candidatos_historial (id_candidato, estatus_anterior, estatus_nuevo, no_empleado, comentario) VALUES
 (@c,'aspirante','enviado_solicitante',@rrhh,'Enviado al solicitante para revisión.'),
 (@c,'enviado_solicitante','descartado',@sol1,'CV descartado por el solicitante: No cumple con la experiencia mínima requerida.');

-- ============================================================================
-- VACANTE 3 — abierta, recién creada (sin candidatos)
-- ============================================================================
INSERT INTO vacantes (folio, puesto, departamento, no_empleado_solicitante, descripcion, posiciones, estatus, publicada_en, creador_por)
VALUES ('VAC-2026-9003', 'Auxiliar Contable (DEMO)', 22, @sol3,
        'Vacante de demostración recién creada, aún sin candidatos capturados.', 2,
        'abierta', '0', @rrhh);
SET @v3 := LAST_INSERT_ID();

-- ============================================================================
-- Notificaciones (campana) para el usuario RRHH — quedan como NO leídas
-- ============================================================================
INSERT INTO notificaciones (no_empleado_destino, id_vacante, evento, titulo, mensaje, url, leida) VALUES
 (@rrhh, @v1, 'cv_aprobado',  '[DEMO] CV aprobado — Carla Ruiz Méndez', 'VAC-2026-9001 · Analista de Datos Jr.: registra el psicométrico.', 'seguimiento.php', 0),
 (@rrhh, @v1, 'propuesta_enviada', '[DEMO] Propuesta enviada a Héctor Vega Luna', 'Analista de Datos Jr.', 'contrataciones.php', 0),
 (@rrhh, @v2, 'alta_completada', '[DEMO] Alta completada — Luis Ramos Aguilar', 'Diseñador UX · ingreso próximo', 'contrataciones.php', 0);

/* ---------- Resumen ---------- */
SELECT c.estatus, COUNT(*) AS n
FROM candidatos c JOIN vacantes v ON v.id = c.id_vacante
WHERE v.folio LIKE 'VAC-2026-90%'
GROUP BY c.estatus
ORDER BY FIELD(c.estatus,'aspirante','enviado_solicitante','aprobado_jefe',
        'psicometrico_asignado','psicometrico_presentado','entrevista_confirmada','entrevistado',
        'propuesta_enviada','propuesta_expirada','propuesta_aceptada','documentacion','contratado','descartado');
