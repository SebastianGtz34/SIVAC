# SIVAC — Sistema de Vacantes y Contratación (MESS)

Subsistema del portal MESS/loginMaster para controlar el proceso completo de
vacantes y contratación: requisición → publicación OCC → candidatos con CV →
filtro RRHH → aprobación del solicitante → psicométrico (externo, con folio)
**antes** de entrevista → cita confirmada → propuesta con caducidad →
documentación del seleccionado → alta con avisos a TI/Viáticos/Teléfono/Marketing.

## Stack
- PHP 8.x + mysqli (sentencias preparadas en el 100 % de las consultas).
- Bootstrap 4.6 + SB Admin 2 + DataTables (es-MX) + SweetAlert2 + FontAwesome +
  Chart.js — **todo local en `vendor/`, cero CDN**.
- Correo: PHPMailer local (`PHPMailer-master/`) por SMTP (config gitignored).
- BD: `mess_sivac` (utf8mb4). Cross-DB a `mess_rrhh.usuarios` con prefijo explícito.

## Roles
| Rol | Quién | Acceso |
|---|---|---|
| RRHH / Reclutamiento | Departamentos **26 (BI)** y **27 (RRHH)** — constante `SIVAC_DEPTS_RRHH` en `auth.php` | Sistema completo (card en loginMaster) |
| Solicitante | Dueño de la vacante (`vacantes.no_empleado_solicitante`), cualquier depto | Pestaña "Mis Vacantes" (iframe `embed_solicitante.php`): aprueba/descarta CVs y da 2 fechas de entrevista |
| Consulta | Tabla `accesos_consulta` (la administra RRHH en Configuración) | `embed_consulta.php`, solo lectura sin datos personales |

## Instalación local (WAMP)
1. Ejecutar `database.sql`:
   `mysql -u root --default-character-set=utf8mb4 < database.sql`
   (el `--default-character-set` es importante: sin él los acentos del seed se corrompen).
2. Copiar `conn.example.php` → `conn.php` con las credenciales locales.
3. Copiar `config_correo.example.php` → `config_correo.php` (cuenta SMTP; misma
   cuenta Gmail que usa ControlVehicular).
4. Entrar por `http://localhost/SIVAC/` con sesión activa de loginMaster.

## Estructura
- `auth.php` — sesión por cookie (`noEmpleadoSVC` ?? `noEmpleadoL`) + roles.
- `includes/flujo.php` — máquina de estatuss del candidato (única vía de cambio
  de estatus; transiciones validadas + historial + expiración lazy de propuestas).
- `includes/archivos.php` — validación de subidas por **firma de bytes**
  (PDF/JPG/PNG), límites (CV 5 MB, docs 10 MB), detección de overflow de POST.
- `includes/correo.php` / `includes/notificaciones.php` — correo con plantilla
  MESS + campana in-system + bitácora (`notificaciones.correo_enviado/error`).
  Un fallo de SMTP nunca aborta la operación de negocio.
- `descargar.php` — único punto de descarga (sesión + permiso por recurso);
  `uploads/` está bloqueado por `.htaccess`.
- `acciones_*.php` — endpoints JSON `{success, message, ...}` con `exit` por rama.
- `embed_*.php` — vistas slim para iframes de loginMaster.

## Pipeline de estatuss del candidato
```
capturado → enviado_solicitante → aprobado_solicitante → psicometrico_asignado
→ psicometrico_presentado → entrevista_confirmada → entrevistado
→ propuesta_enviada → (propuesta_expirada ⇄ reenvío) → propuesta_aceptada
→ documentacion → contratado          (descartado desde casi cualquier etapa)
```
Reglas duras (backend): no hay entrevista sin psicométrico presentado; no se
envía candidato sin CV; aprobar CV exige 2 fechas futuras distintas; completar
alta exige fecha de ingreso + reglamento enviado + documentos obligatorios
completos; las propuestas vencidas expiran automáticamente (lazy) al cargar
cierre/dashboard.

## Checklist de seguridad aplicado (sección 6 del requerimiento)
1. **Prepared statements + bind_param en toda consulta** — cero concatenación de
   input en SQL (los `IN (...)` solo interpolan constantes del código).
2. **Toda escritura re-valida en backend** — rol (RRHH/ownership), transición del
   mapa de estatuss y reglas duras se verifican por acción; el cliente nunca
   decide estatuss ni privilegios.
3. **Update/Delete filtran por dueño/permiso** — el solicitante solo actúa sobre
   sus vacantes (JOIN por `no_empleado_solicitante = sesión`); notificaciones
   solo del propio empleado; descargas gateadas por recurso.
4. **Archivos validados por firma de bytes** (`%PDF-`, JPEG `FFD8FF`, PNG
   `89504E47`) + límite de tamaño + mensaje claro si el POST excede
   `post_max_size` + nombre aleatorio en disco + `.htaccess` deny + descarga
   solo vía `descargar.php`.
5. **Escape de toda salida** — `htmlspecialchars` en PHP, `escHtml()` en JS
   (incluidos renders de DataTables).
6. **Fechas/números saneados con rangos** — opciones de entrevista futuras y
   distintas, caducidad futura, prórroga posterior a la actual, ids casteados.

Además: `conn.php` y `config_correo.php` gitignored (hay `.example` committeados).

## Integración con loginMaster (ya aplicada en `loginMaster/inicio.php`)
1. **Gate PHP** (tras el bloque `$tieneVehiculo`): `$tieneSivac` (deptos 26/27
   vía `mess_rrhh.usuarios`) y `$tieneSivacSolicitante` (dueño de vacante activa
   vía `mess_sivac.vacantes`, cross-DB con el `$conn` existente).
2. **Card** `id="divSivac"` en el tab `#tabSistemas` (junto a Cotizador IA),
   envuelta en `<?php if ($tieneSivac): ?>` → enlaza `../SIVAC/`.
3. **Pestaña "Mis Vacantes"** (`#tabSivacSol-tab`, solo si
   `$tieneSivacSolicitante`) con `<iframe data-src="../SIVAC/embed_solicitante.php">`
   de carga lazy (reutiliza `cargarIframeTickets()`).
La visibilidad de card/pestaña es solo UX: la seguridad real está en los
endpoints (`requiereRRHHJson` / ownership por JOIN).

## Despliegue en cPanel (messbook.com.mx)
1. **BD**: crear la base desde cPanel → MySQL Databases (el nombre final con el
   prefijo de la cuenta debe coincidir con el de `conn.php`; usar minúsculas:
   Linux es case-sensitive). Importar `database.sql` desde phpMyAdmin **quitando
   la línea `CREATE DATABASE`/`USE`** si el panel la pre-crea. Asignar el usuario
   MySQL de los demás sistemas con ALL PRIVILEGES y **verificar su SELECT
   cross-DB sobre `mess_rrhh`** (probar el login RRHH de inmediato).
2. **Carpeta**: subir exactamente como `SIVAC` (los enlaces `../SIVAC/`
   de loginMaster distinguen mayúsculas en Linux).
3. **PHP** (Select PHP Version): extensión **fileinfo** habilitada;
   `upload_max_filesize ≥ 10M`, `post_max_size ≥ 12M`.
   (Ojo: en WAMP local el default puede ser 2M/8M — subirlo también ahí.)
4. **Configs**: crear `conn.php` y `config_correo.php` a mano en el servidor (no
   viajan por git). Probar un envío SMTP real (ssl:465 a Gmail ya funciona en ese
   hosting para ControlVehicular).
5. **uploads/**: permisos de escritura (755) en `uploads/cv/` y
   `uploads/documentos/`; confirmar que el `.htaccess` (Require all denied)
   también aplica en producción probando una URL directa (debe dar 403).
6. Subir también el `loginMaster/inicio.php` editado.
