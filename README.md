# SIVAC — Sistema de Vacantes y Contratación (MESS)

Subsistema del portal MESS/loginMaster para controlar el proceso completo de
vacantes y contratación: requisición (la levanta RRHH o el jefe, y en ese caso
pasa por VoBo de RRHH) → candidatos con CV (RRHH ya los entrevistó fuera del
sistema y deja constancia) → aprobación del solicitante → entrevista del jefe →
propuesta con caducidad → documentación del seleccionado (validada por RRHH) →
alta con avisos a TI/Viáticos/Teléfono/Marketing.

La entrevista de **RRHH** ocurre fuera del sistema; SIVAC solo guarda su
constancia (fecha + resultado), obligatoria para enviar el candidato al jefe. La
única entrevista que el sistema agenda es la del jefe.

Las vacantes de **practicante** siguen el mismo camino pero corto: sin propuesta
económica (pasan de entrevistado directo a documentación).

## Stack
- PHP 8.x + mysqli (sentencias preparadas en el 100 % de las consultas).
- Bootstrap 4.6 + SB Admin 2 + DataTables (es-MX) + SweetAlert2 + FontAwesome +
  Chart.js — **todo local en `vendor/`, cero CDN**.
- Correo: PHPMailer local (`PHPMailer-master/`) por SMTP (config gitignored).
- BD: `mess_sivac` (utf8mb4). Cross-DB a `mess_rrhh` (`usuarios`, `puesto`,
  `region`, `departamento`) con prefijo explícito.

> **Cross-DB, ojo con las collations.** `mess_sivac` y `mess_rrhh` no comparten
> collation (`utf8mb4_0900_ai_ci` vs `utf8mb4_unicode_ci`): comparar **texto**
> entre ambas sin `COLLATE` explícito aborta con *"Illegal mix of collations"*.
> Por eso los catálogos se resuelven siempre por **id**, nunca por nombre. Si
> algún día hace falta un JOIN por texto cross-DB, hay que forzar la collation en
> los dos lados. Comparar contra `usuarios.noEmpleado` / `usuarios.jefe`
> (VARCHAR latin1) usando un INT sí es seguro: MySQL castea la cadena a número y
> no interviene collation alguna.

## Roles
| Rol | Quién | Acceso |
|---|---|---|
| RRHH / Reclutamiento | Departamentos **26 (BI)** y **27 (RRHH)** — constante `SIVAC_DEPTS_RRHH` en `auth.php` | Sistema completo (card en loginMaster). Da el VoBo y entrevista antes que el jefe |
| Jefe / gerente | Empleado con **al menos un subordinado activo** (`mess_rrhh.usuarios.jefe` apunta a él) | Levanta requisiciones (nacen `pendiente_vobo`) y ve el dashboard **acotado a su equipo** |
| Solicitante | Dueño de la vacante (`vacantes.no_empleado_solicitante`), cualquier depto | Pestaña "Mis Vacantes" (iframe `embed_solicitante.php`): aprueba/descarta CVs y da 2 fechas de entrevista |
| Consulta | Tabla `accesos_consulta` (la administra RRHH en Configuración) | `embed_consulta.php`, solo lectura sin datos personales |

El rol de jefe **se deriva de la jerarquía real**, no de la etiqueta
`usuarios.tipo_usr`: esa etiqueta está incompleta (hay 30 empleados con
subordinados activos y solo 21 etiquetados), así que gatear por ella dejaría
fuera a jefes de facto. Ver `sivacSubordinados()` / `esJefe()` en `auth.php`.

## Base de datos: un solo archivo
**`database.sql` es el ÚNICO archivo SQL del proyecto.** Trae el esquema completo
más los catálogos semilla (tipos de documento, correos de área). Todo cambio de
esquema se edita ahí; no hay scripts de migración ni de datos demo aparte.

Es idempotente (`CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`), así que correrlo
dos veces no rompe nada. La otra cara de eso: **si una tabla ya existe, el script
no la altera**. Mientras la BD sea desechable —hoy lo es, SIVAC no está en
producción— reinstalar es la vía. Cuando haya datos que no se puedan perder, un
cambio de esquema habrá que aplicarlo a mano con `ALTER`.

## Instalación local (WAMP)
1. Ejecutar `database.sql`:
   `mysql -u root --default-character-set=utf8mb4 < database.sql`
   (el `--default-character-set` es importante: sin él los acentos se corrompen).
2. Copiar `conn.example.php` → `conn.php` con las credenciales locales.
3. Copiar `config_correo.example.php` → `config_correo.php` (cuenta SMTP; misma
   cuenta Gmail que usa ControlVehicular).
4. Entrar por `http://localhost/SIVAC/` con sesión activa de loginMaster.

## Estructura
- `auth.php` — sesión por cookie (`noEmpleadoSVC` ?? `noEmpleadoL`) + roles
  (incluye la jerarquía jefe→subordinados y el alcance del dashboard).
- `includes/flujo.php` — máquina de estatuss del candidato (única vía de cambio
  de estatus; transiciones validadas por rama + historial + expiración lazy de
  propuestas).
- `includes/catalogos.php` — lectura cross-DB de los catálogos que administra
  RRHH en su propio sistema (`mess_rrhh.puesto`, `mess_rrhh.region`). SIVAC no
  los duplica: guarda el id **más** un snapshot del nombre.
- `includes/vacantes.php` — lógica común a las dos vías de alta de requisición
  (folio y validación del puesto contra el catálogo).
- `includes/assets.php` — `sivacAsset()` cuelga `?v=<filemtime>` a los js/css
  propios para que un despliegue no deje al usuario con el JS viejo en caché.
- `includes/archivos.php` — validación de subidas por **firma de bytes**
  (PDF/JPG/PNG), límites (CV 5 MB, docs 10 MB), detección de overflow de POST.
- `includes/correo.php` / `includes/notificaciones.php` — correo con plantilla
  MESS + campana in-system + bitácora (`notificaciones.correo_enviado/error`).
  Un fallo de SMTP nunca aborta la operación de negocio.
- `descargar.php` — único punto de descarga (sesión + permiso por recurso);
  `uploads/` está bloqueado por `.htaccess`.
- `acciones_*.php` — endpoints JSON `{success, message, ...}` con `exit` por rama.
- `embed_*.php` — vistas slim para iframes de loginMaster.

## Ciclo de vida de la vacante
```
origen = 'solicitante' (la levanta el jefe) → pendiente_vobo → abierta | rechazada
origen = 'rrhh'        (la captura RRHH)    → abierta                (VoBo implícito)
                                     abierta → en_proceso → cerrada
                                             ↘ pausada / cancelada
```
El VoBo aplica a **toda** requisición levantada por un jefe, sea plaza nueva o
reemplazo. Rechazar exige motivo (`vacantes.motivo_rechazo`).

## Pipeline de estatuss del candidato
Hay **dos ramas** según `vacantes.tipo`. La rama no la elige el cliente: se lee
de la vacante del candidato en cada transición (`includes/flujo.php`).

**Plaza** (`SIVAC_TRANSICIONES_PLAZA`) — flujo completo:
```
aspirante → enviado_solicitante → aprobado_jefe
→ entrevista_confirmada → entrevistado
→ propuesta_enviada → (propuesta_expirada ⇄ reenvío) → propuesta_aceptada
→ documentacion → contratado          (descartado desde casi cualquier etapa)
```

**Practicante** (`SIVAC_TRANSICIONES_PRACTICANTE`) — un atajo: `entrevistado`
pasa directo a documentación (sin propuesta económica):
```
aspirante → enviado_solicitante → aprobado_jefe
→ entrevista_confirmada → entrevistado
→ documentacion → contratado
```

### La entrevista del jefe (y la constancia de RRHH)
El sistema agenda **una sola entrevista: la del jefe** (`citas.tipo = 'jefe'`). La
del jefe la propone él mismo al aprobar el CV (2 opciones de horario en
`citas.opcion1` / `opcion2`, ambas obligatorias); el candidato elige una por fuera
del sistema y RRHH confirma cuál. Al agendar se pueden dejar comentarios para el
candidato en `citas.notas`.

La entrevista de **RRHH** ya no se agenda: ocurre fuera del sistema y queda como
constancia en `candidatos.entrevista_rrhh_fecha` / `entrevista_rrhh_resultado`.
Esa constancia (fecha **y** resultado) es **obligatoria** para poder enviar el
candidato al jefe.

Reglas duras (backend): no se envía candidato al jefe sin CV **ni sin la
constancia de la entrevista de RRHH**; agendar exige 2 fechas futuras distintas;
completar alta exige fecha de ingreso + reglamento enviado + documentos
obligatorios **validados** por RRHH; las propuestas vencidas expiran
automáticamente (lazy) al cargar cierre/dashboard.

**El `tipo` de la vacante no se puede cambiar si ya hay candidatos avanzados**
(cualquiera fuera de `aspirante`): mover la rama bajo los pies de un proceso vivo
dejaría a un candidato a media etapa que su nueva rama no contempla.

## Dashboard (`inicio.php`)
Lo ven RRHH y los gerentes, pero no ven lo mismo:
- **RRHH** → todas las vacantes; puede filtrar por región y por gerente.
- **Gerente** → solo las suyas (las que él solicitó + las de sus subordinados
  directos, vía `sivacAlcanceVacantes()`). El acotamiento **no es un filtro que el
  usuario pueda quitar**: se aplica en el `WHERE` antes de cualquier filtro suyo.

Todo indicador se calcula sobre el mismo alcance. `vacantes.region` es un
snapshot de la región del solicitante al crear la vacante, para que el histórico
no cambie si el empleado se mueve de región después.

## Checklist de seguridad aplicado (sección 6 del requerimiento)
1. **Prepared statements + bind_param en toda consulta** — cero concatenación de
   input en SQL (los `IN (...)` solo interpolan constantes del código).
2. **Toda escritura re-valida en backend** — rol (RRHH/ownership), transición del
   mapa de estatuss y reglas duras se verifican por acción; el cliente nunca
   decide estatuss ni privilegios. **La rama del pipeline tampoco la manda el
   cliente**: `cambiarestatusCandidato()` relee de la BD el estatus actual y el
   `tipo` de la vacante, así que nadie se salta la propuesta económica
   declarándose practicante. El `UPDATE` filtra por `id AND estatus = actual` (anti-carrera).
3. **Update/Delete filtran por dueño/permiso** — el solicitante solo actúa sobre
   sus vacantes (JOIN por `no_empleado_solicitante = sesión`); notificaciones
   solo del propio empleado; descargas gateadas por recurso. En el dashboard, el
   alcance del gerente se impone en el `WHERE` del servidor: los filtros de
   región/gerente que manda el cliente solo pueden **estrechar** ese alcance,
   nunca salirse de él.
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
   Linux es case-sensitive) e importar **`database.sql`** desde phpMyAdmin
   **quitando la línea `CREATE DATABASE`/`USE`** si el panel la pre-crea.
   > Si en el futuro SIVAC ya está arriba **con datos reales**, reimportar
   > `database.sql` los borraría: en ese escenario el cambio de esquema se aplica
   > a mano con `ALTER` sobre la base existente.

   Asignar el usuario MySQL de los demás sistemas con ALL
   PRIVILEGES y **verificar su SELECT cross-DB sobre `mess_rrhh`** — se
   leen también `puesto` y `region`, no solo `usuarios` (probar el login RRHH y
   abrir el alta de vacante de inmediato: si el select de puestos sale vacío, es
   eso).
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
