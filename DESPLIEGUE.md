# Desplegar / Compartir SIVAC (otro equipo o producción)

> El código ya es compatible con la BD real (verificado). Lo que sigue son los
> pasos para que funcione en OTRO equipo o en producción. Lee primero los ⚠️.

## La BD: un solo archivo
**`database.sql` es el único archivo SQL del proyecto** — esquema completo
(puesto de catálogo, VoBo, practicantes, entrevista del jefe + constancia de la
de RRHH, validación de documentos, región) más los catálogos semilla. Instalar =
ejecutarlo. No hay scripts de migración ni de datos demo.

```
mysql -u USER -p --default-character-set=utf8mb4 < database.sql
```

Hoy SIVAC **no está en producción**, así que la BD es desechable: si el esquema
cambia, se reinstala. **Ojo el día que sí haya datos reales**: el script es
idempotente pero *no altera tablas que ya existen*, así que reimportarlo sobre
una base con datos no actualiza el esquema — y borrarla para reimportar perdería
los datos. En ese escenario, los cambios se aplican a mano con `ALTER`.

**Código y BD viajan juntos**: el código nuevo contra un esquema viejo truena en
cuanto toca las columnas nuevas.

## ⚠️ Lo que NO viaja con el proyecto (hay que crearlo en el destino)
- **`conn.php`** — está *gitignored*. Copiar de `conn.example.php` y poner las
  credenciales MySQL del equipo destino. **Sin esto, nada conecta.**
- **`config_correo.php`** — *gitignored*. Copiar de `config_correo.example.php`
  (SMTP). Sin esto solo se desactiva el envío de correo; el sistema sigue.
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
   `mess_rrhh` con departamento 26 o 27 para entrar como RRHH.
   > Si el select de puestos del alta de vacante sale **vacío**, es que falta el
   > SELECT sobre `mess_rrhh.puesto` (o no hay puestos con `estatus = 1`).
   > Para demostrar el rol de **jefe/gerente** (requisición con VoBo + dashboard
   > acotado) hace falta un empleado que tenga **subordinados activos** en
   > `mess_rrhh.usuarios.jefe` — el rol se deriva de ahí, no de `tipo_usr`.
3. **Nombre de carpeta = `SIVAC`**: los enlaces de loginMaster son `../SIVAC/`.
4. **loginMaster/inicio.php** ya fue editado (enlaces y textos → SIVAC): hay que
   **subir también ese archivo** al destino/servidor.

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

## B) Producción (cPanel — messbook.com.mx)
Seguir la sección **“Despliegue en cPanel”** del `README.md`. Puntos clave:
- Subir la carpeta como **`SIVAC`** (Linux distingue mayúsculas).
- Crear `conn.php` y `config_correo.php` **en el servidor** (no subir los locales).
- **BD**: crear `mess_sivac` desde el panel e importar **`database.sql`** (queda
  sin vacantes ni candidatos: prod arranca en blanco, como debe ser). En prod
  `mess_rrhh` y loginMaster **ya existen**; verificar el SELECT cross-DB
  (incluidas `puesto` y `region`) probando el login RRHH y el alta de vacante de
  inmediato.
- **Orden**: crear la BD y subir el código en la misma ventana. El código contra
  un esquema viejo truena.
- **Subir el `loginMaster/inicio.php` editado.**
- Permisos de escritura en `uploads/cv/` y `uploads/documentos/`; confirmar que
  el `.htaccess` (deny) da **403** en acceso directo.
- **No subir datos de prueba a producción**: `database.sql` no los trae, y el
  `mysqldump` de tu equipo local **sí** — ese es solo para presentar.

---

## Estado actual (este equipo)
- Trabajando en `C:\wamp64\www\SIVAC` (el clon duplicado “SIVAC 3000” se eliminó).
- Código ↔ BD reconciliado y verificado (esquema, máquina de estados y flujos SQL OK).
- Rename a “SIVAC” aplicado en el código, README y loginMaster.
- **Flujo replanteado (Fase A + C) aplicado**: se quitaron OCC, psicométrico y la
  entrevista de RRHH agendada; queda una sola entrevista (la del jefe) + la
  constancia de la de RRHH (obligatoria para enviar al jefe), y los documentos del
  alta ahora los **valida** RRHH. El `mess_sivac` local se reinstaló con el esquema
  nuevo y quedó **en blanco** (sin vacantes ni candidatos de prueba).
- Falta colocar `uploads/cv/cv_demo.pdf` (los CVs de prueba apuntan a ese archivo).
- Pendiente (Fase B, diferida): portal del candidato + autenticación por token.
- SIVAC **no está en producción** todavía.
