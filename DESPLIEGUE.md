# Desplegar / Compartir SIVAC (otro equipo o producción)

> ⚠️ **SIVAC YA ESTÁ EN PRODUCCIÓN** en `messbook.com.mx/SIVAC`, con vacantes y
> candidatos reales. Este documento sirve para dos cosas distintas: montar el
> sistema en OTRO equipo (sección A) y **actualizar el que ya corre** (sección B).
> Son procedimientos diferentes; no los mezcles. Lee primero los ⚠️.

## La BD: un solo archivo
**`database.sql` es el único archivo SQL del proyecto** — esquema completo
(puesto de catálogo, VoBo, practicantes, entrevista del jefe + constancia de la
de RRHH, validación de documentos, región) más los catálogos semilla. Instalar
desde cero = ejecutarlo. No hay scripts de migración ni de datos demo.

```
mysql -u USER -p --default-character-set=utf8mb4 < database.sql
```

### ⚠️ La trampa: reimportar NO actualiza producción
El script es `CREATE TABLE IF NOT EXISTS`, o sea **idempotente pero ciego**: sobre
una base que ya existe no altera nada. Reimportarlo en producción no cambia el
esquema, y borrar la base para reimportar perdería los datos reales.

**Por eso todo cambio de esquema va DOS veces**: se edita `database.sql` (para
las instalaciones nuevas) **y además** se corre el `ALTER` equivalente a mano en
el phpMyAdmin de producción. Ver «Cambios de esquema pendientes» abajo.

**Cómo se ve cuando falta el `ALTER`:** el `INSERT`/`UPDATE` truena con
`Unknown column` o `Field ... doesn't have a default value`, la excepción de
mysqli no se captura y sale **HTTP 500 con cuerpo vacío**, que el JS muestra como
**«error de conexión»**. Ese mensaje casi siempre es esquema desfasado, no red.

**Ojo:** producción corre con `sql_mode` estricto y el WAMP local **no** (su
`sql_mode` global está vacío). Hay errores que allá revientan y aquí pasan
silenciosos — sobre todo columnas `NOT NULL` sin default que el código dejó de
escribir. Para reproducir el comportamiento de producción en local:

```sql
SET SESSION sql_mode='STRICT_TRANS_TABLES';
```

**Código y BD viajan juntos**: el código nuevo contra un esquema viejo truena en
cuanto toca las columnas nuevas.

## ⚠️ Cambios de esquema pendientes en producción
Corre esto en phpMyAdmin **antes o junto con** la subida del código actual:

```sql
-- Retro 4 (12-ago): permite volver a mostrar el enlace del portal en vez de
-- regenerarlo (regenerar invalida el que el candidato ya tiene).
ALTER TABLE candidato_accesos
  ADD COLUMN token CHAR(64) NULL
  COMMENT 'el mismo token en claro, para poder repetir el enlace' AFTER token_hash;

-- Arrastrado del commit 95dce12 (11-ago), "Quitar el sueldo del alta": el código
-- dejó de escribir estas columnas, pero en producción siguen existiendo y
-- sueldo_propuesto es NOT NULL sin default. Sin este DROP, ENVIAR UNA PROPUESTA
-- FALLA en producción (error 1364) aunque en local funcione.
ALTER TABLE propuestas DROP COLUMN sueldo_propuesto;
ALTER TABLE contrataciones DROP COLUMN sueldo;   -- opcional: es NULL-able y no rompe nada

-- Retro 5 (14-ago): contraseña del portal del candidato. SIN ESTO NO SE PUEDE
-- GENERAR NINGÚN ENLACE: el INSERT de sivacGenerarAcceso() escribe pass_hash y
-- truena con "Unknown column". Faltaba también en local hasta el 18-ago.
-- Las filas que ya existan quedan con pass_hash NULL, o sea abriendo sin
-- contraseña: es el comportamiento diseñado para no dejar tirado a nadie a
-- media documentación.
ALTER TABLE candidato_accesos
  ADD COLUMN pass_hash VARCHAR(255) NULL
    COMMENT 'contraseña del portal (password_hash); NULL = enlace anterior al 2026-08-14' AFTER token,
  ADD COLUMN intentos TINYINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT 'intentos fallidos SEGUIDOS de contraseña' AFTER ultimo_uso,
  ADD COLUMN bloqueado_hasta DATETIME NULL
    COMMENT 'bloqueo temporal tras 5 fallos' AFTER intentos;
```

Verificar después con:

```sql
SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'mess_sivac'
   AND (COLUMN_NAME LIKE '%sueldo%'
        OR COLUMN_NAME IN ('token','pass_hash','intentos','bloqueado_hasta'));
```
Debe devolver **cuatro** filas, todas de `candidato_accesos` (`token`, `pass_hash`,
`intentos`, `bloqueado_hasta`) y **ninguna** de sueldo.

### Validación de documentos (17-ago) — comprobar antes de descartarlo
Estas columnas entraron con la retro de validación de documentos y **nunca se
anotaron aquí**, así que no consta si se aplicaron en producción. Comprobar:

```sql
SELECT COLUMN_NAME FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = 'mess_sivac' AND TABLE_NAME = 'documentos'
   AND COLUMN_NAME IN ('origen','validacion','validado_por','validado_fecha','motivo_validacion');
```
Deben salir **cinco** filas. Si faltan, correr el `ALTER` equivalente tomando los
tipos de `database.sql` (tabla `documentos`). La consulta de la ficha del
candidato las pide por nombre: sin ellas, `prepare()` devolvía `false` y la ficha
no abría —el `SELECT *` de otras pantallas no lo delata, por eso pasó inadvertido—.

## ⚠️ Lo que NO viaja con el proyecto (hay que crearlo en el destino)
- **`conn.php`** — está *gitignored*. Copiar de `conn.example.php` y poner las
  credenciales MySQL del equipo destino. **Sin esto, nada conecta.**
- **`config_correo.php`** — *gitignored*. Copiar de `config_correo.example.php`
  (SMTP) **en la raíz de SIVAC**, junto a `acciones_cierre.php` — no dentro de
  `includes/`, y con el nombre exacto en minúsculas (Linux distingue).
  El sistema sigue funcionando sin él, pero **ningún correo sale**: propuestas,
  reglamento y los cinco avisos de alta a las áreas se registran en
  `notificaciones` con `correo_error = 'Falta config_correo.php en el servidor.'`
  y nadie los recibe. Pasó en producción el **2026-08-18**: se completó un alta
  real y las cinco áreas nunca se enteraron. Al terminar cualquier
  actualización, comprobar:
  ```sql
  SELECT correo_enviado, correo_error, fecha_creacion
    FROM notificaciones ORDER BY id DESC LIMIT 5;
  ```
- **`uploads/cv/` y `uploads/documentos/`** — los archivos subidos no están en git.
  Para el demo: colocar tu PDF como **`uploads/cv/cv_demo.pdf`** (y opcional
  `uploads/documentos/doc_demo.pdf`).

## ⚠️ Dependencias del sistema (imprescindibles)
1. **loginMaster**: SIVAC **no tiene login propio**; usa la cookie `noEmpleadoL`
   que emite loginMaster. En el equipo destino debe existir loginMaster y una
   **sesión iniciada como usuario RRHH** (p. ej. el 523). Sin sesión, toda página
   redirige a `../loginMaster/index.php`.
2. **Dos bases de datos**: la app lee `mess_rrhh.usuarios`, `departamento` y
   —desde la retro PT1— también **`mess_rrhh.puesto` y `mess_rrhh.region`**
   (cross-DB). Necesitas **`mess_sivac` Y `mess_rrhh`** en el destino, y que el
   usuario MySQL tenga **SELECT en ambas**. El presentador (523) debe existir en
   `mess_rrhh` con departamento 27 (BI) o 47 (RRHH) para entrar como RRHH.
   > Si el select de puestos del alta de vacante sale **vacío**, es que falta el
   > SELECT sobre `mess_rrhh.puesto` (o no hay puestos con `estatus = 1`).
   > Para demostrar el rol de **jefe/gerente** (requisición con VoBo + dashboard
   > acotado) hace falta un empleado que tenga **subordinados activos** en
   > `mess_rrhh.usuarios.jefe` — el rol se deriva de ahí, no de `tipo_usr`.
3. **Nombre de carpeta = `SIVAC`**: los enlaces de loginMaster son `../SIVAC/`.
4. **Dos archivos de loginMaster (OTRO repo git) que hay que subir aparte:**
   - **`loginMaster/inicio.php`** — la card de SIVAC y la pestaña «Mis Vacantes».
     Su gate `$tieneSivacSolicitante` (jefe con personal a cargo, o dueño de
     alguna vacante) es **gemelo de `puedeSolicitarVacante()` en `auth.php`**: si
     cambia uno cambia el otro, o alguien verá la pestaña con el botón muerto —o
     tendrá el permiso sin puerta por dónde entrar—.
   - **`loginMaster/funcionesGlobales.js`** — el endpoint `sivac`, el ícono y la
     redirección de las notificaciones. **Sin este archivo las notificaciones de
     SIVAC salen, pero el clic no lleva a ningún lado.**

---

## A) Presentar en OTRO equipo con WAMP
1. Copiar la carpeta del proyecto como **`C:\wamp64\www\SIVAC`** (¡sin “3000”!).
2. Copiar también `loginMaster` (con su `inicio.php` ya editado).
3. Crear `conn.php` y `config_correo.php` en el destino.
4. Importar las BDs en el MySQL del destino:
   ```
   mysql -u USER -p --default-character-set=utf8mb4 < database.sql
   ```
   y asegurar que exista `mess_rrhh` con los empleados 523 / 107 / 110.
   > `database.sql` deja la BD **vacía de vacantes y candidatos** (solo esquema y
   > catálogos). Para **presentar con datos**, primero cárgalos en este equipo y
   > lleva el dump: `mysqldump -u USER -p mess_sivac > mess_sivac_full.sql`
   > y en el destino se importa ese dump **en vez de** `database.sql`.
5. Colocar `uploads/cv/cv_demo.pdf`.
6. PHP: extensión **fileinfo** activada; `upload_max_filesize ≥ 10M`,
   `post_max_size ≥ 12M`.
7. Abrir loginMaster, entrar como 523 (RRHH) y abrir la card **SIVAC**
   → `http://localhost/SIVAC/`.

## B) Actualizar producción (cPanel — messbook.com.mx)
Producción **ya existe y tiene datos reales**: esto es una actualización, no una
instalación. `conn.php`, `config_correo.php`, la BD `mess_sivac` y los uploads ya
están en el servidor y **no se tocan**.

1. **Primero la BD**: correr en phpMyAdmin los `ALTER` que traiga el cambio (ver
   «Cambios de esquema pendientes» arriba). Nunca reimportar `database.sql`.
2. **Luego el código**: subir los archivos modificados. Linux distingue
   mayúsculas: la carpeta es **`SIVAC`**.
3. **Y los de loginMaster** si cambiaron (`inicio.php`, `funcionesGlobales.js`):
   son de otro repo y no viajan con SIVAC.
4. **Probar de inmediato** la pantalla que tocó el cambio. Si sale «error de
   conexión», es esquema desfasado: falta un `ALTER`.

**Nunca subir a producción:** `conn.php` ni `config_correo.php` locales (apuntan a
tu MySQL), ni datos de prueba — el `mysqldump` de tu equipo **sí** los trae y es
sólo para presentar en otra máquina.

### Instalación desde cero (sólo si algún día se levanta otro entorno)
Crear `mess_sivac` desde el panel, importar `database.sql` (queda sin vacantes ni
candidatos), crear `conn.php` y `config_correo.php` en el servidor, verificar el
SELECT cross-DB sobre `mess_rrhh` (incluidas `puesto` y `region`) probando el
login RRHH y el alta de vacante, dar permisos de escritura a `uploads/cv/` y
`uploads/documentos/`, y confirmar que el `.htaccess` (deny) da **403** en acceso
directo. Detalle en la sección «Despliegue en cPanel» del `README.md`.

---

## Estado actual
- **En producción** en `messbook.com.mx/SIVAC` con vacantes y candidatos reales.
  **Liberado el 2026-09-01**: terminó la prueba cerrada y se quitaron las dos
  listas blancas hardcodeadas (`SIVAC_EMPLEADOS_TAB` en `auth.php` y
  `$empleadosSivacTab` en `loginMaster/inicio.php`). La pestaña «Mis Vacantes» y
  el permiso de levantar requisición los tiene ahora **cualquier jefe con
  personal a cargo**, más los **dueños de alguna vacante** —de ahí que quien ya
  traía un proceso empezado no lo pierda—.
- El flujo completo está construido: requisición con VoBo, candidatos, entrevista
  del jefe, propuesta, documentación validada por RRHH, portal del candidato con
  acceso por token, alta con avisos por área y notificaciones en la campana del
  portal.
- Desarrollo en `C:\wamp64\www\SIVAC` (el clon duplicado “SIVAC 3000” se eliminó).
  La BD local es de pruebas y **no** refleja producción: úsala para reproducir,
  no para concluir (ojo con el `sql_mode`, ver arriba).
- **Pendiente de aplicar en producción:** los `ALTER` de la sección «Cambios de
  esquema pendientes».
