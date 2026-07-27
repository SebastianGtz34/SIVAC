# Reporte de seguridad — gestionPersonal (alta de empleados)

**Fecha:** 2026-07-21 · **Autor:** revisión técnica durante la integración SIVAC ↔ gestionPersonal
**Alcance:** `c:\wamp64\www\gestionPersonal\action_controller.php` (acción
`registrar_nuevo_empleado_sistema`) y su contexto de despliegue.
**Regla:** este documento **no modifica** el código de gestionPersonal; sólo reporta.

> Este análisis surgió al diseñar cómo SIVAC entrega los datos de una contratación.
> Se decidió que **SIVAC nunca escribe en `mess_rrhh`**: marca al candidato como listo y
> gestionPersonal jala los datos desde `mess_sivac`. Por eso los hallazgos de abajo **no
> son de SIVAC**, pero sí condicionan cualquier acoplamiento entre ambos sistemas.

---

## Resumen

| # | Hallazgo | Severidad |
|---|----------|-----------|
| H-1 | Endpoint de alta **sin autenticación ni autorización** | **Crítica** |
| H-2 | **Contraseñas en texto plano**, predecibles y copiadas a `password_restaurar` | **Alta** |
| H-3 | Consultas por **concatenación de cadenas** con escapado manual | **Media** |
| CTX | El usuario MySQL de la app tiene **`GRANT ALL … WITH GRANT OPTION`** | Agravante (infra) |

> **Corrección respecto a una nota previa:** se había asumido que el `INSERT` del alta
> era **inyectable por SQL**. Al revisar el código actual, **no lo es**: todos los campos
> pasan por `mysqli_real_escape_string()` o `intval()` antes de interpolarse
> ([action_controller.php:779-792](../gestionPersonal/action_controller.php#L779-L792)).
> El riesgo real de ese `INSERT` es el **patrón** (H-3), no una inyección explotable hoy.

---

## H-1 · Endpoint sin autenticación ni autorización — **Crítica**

**Ubicación:** [action_controller.php:1-12](../gestionPersonal/action_controller.php#L1-L12),
alta en [:777-852](../gestionPersonal/action_controller.php#L777-L852).

El único control de acceso del controlador es que la petición sea POST:

```php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no autorizado.']);
    exit;
}
```

No hay verificación de sesión, cookie, rol ni token CSRF. Cualquiera que alcance la URL y
envíe un POST con `action=registrar_nuevo_empleado_sistema` **crea un empleado real** en
`mess_rrhh.usuarios` (≈259 usuarios reales) y le inserta accesos a los sistemas del portal
([:827-831](../gestionPersonal/action_controller.php#L827-L831)): `divIncidencias`,
`divControlVehicular`, `divCapacitacion`, `divVacaciones`.

El guard del menú tampoco protege: en [menu.php:3-5](../gestionPersonal/menu.php#L3-L5) la
redirección a loginMaster cuando falta la cookie está **comentada**:

```php
if(empty($_COOKIE['noEmpleadoGP'])){
    //echo '<script>window.location.assign("../loginMaster")</script>';
}
```

**Impacto:** creación no autenticada de cuentas con acceso a los sistemas internos; el
atacante controla `noEmpleado`, `correo`, `rol` (se inserta `rol = 1`) y por tanto la
contraseña resultante (ver H-2). Es un camino directo a **cuenta válida en el portal**.

**Prueba de concepto (responsable, para el equipo):** un `POST` a `action_controller.php`
con los campos `nuevo_noEmpleado`, `nuevo_nombre`, `nuevo_correo` (los únicos obligatorios,
[:796-799](../gestionPersonal/action_controller.php#L796-L799)) basta para dar de alta.

**Remediación:**
1. Exigir sesión válida **y rol autorizado** al inicio de `action_controller.php` (Calidad
   `5` / RRHH `403` son los super-usuarios del módulo) antes del `switch`.
2. Reactivar el guard de `menu.php`.
3. Añadir token anti-CSRF a las acciones de escritura.

---

## H-2 · Contraseñas en texto plano, predecibles y duplicadas — **Alta**

**Ubicación:** [action_controller.php:808-822](../gestionPersonal/action_controller.php#L808-L822).

```php
$usuario = $correo;
// ... $user_part = parte local del correo ...
$password = $user_part;               // contraseña = usuario del correo
// INSERT ... '$usuario', '$password', '$user_part' ...  (usuario, password, password_restaurar)
```

- La contraseña es **la parte local del correo** (p. ej. `juan.perez@mess.com.mx` →
  `juan.perez`): **predecible** para cualquiera que conozca el correo.
- Se guarda **en claro** en la columna `password` y **se copia** a `password_restaurar`.
- Un simple `SELECT` sobre `usuarios` expone credenciales utilizables de todo el personal.

**Nota:** loginMaster parece migrar a bcrypt en el primer inicio de sesión, pero eso **no
mitiga** el hueco: entre el alta y ese primer login la contraseña es texto plano conocido, y
`password_restaurar` conserva el valor en claro de forma indefinida.

**Remediación:** generar una contraseña aleatoria, guardar sólo su hash
(`password_hash(..., PASSWORD_DEFAULT)`), entregarla por un canal fuera de banda y **eliminar
`password_restaurar`** o guardar ahí también sólo un hash / token de un solo uso.

---

## H-3 · Consultas por concatenación con escapado manual — **Media**

**Ubicación:** [action_controller.php:819-822](../gestionPersonal/action_controller.php#L819-L822)
(y el resto de las consultas del archivo).

El `INSERT` del alta **sí escapa** sus entradas hoy, así que **no se encontró una inyección
explotable** en esta ruta. El hallazgo es de **patrón y robustez**: la consulta se arma
concatenando strings y depende de que *cada* campo se escape a mano. Basta agregar un campo
sin escapar —o reutilizar el patrón en otra acción sin el mismo cuidado— para abrir una
inyección. Dado el contexto de privilegios (CTX), el margen de error efectivo es **cero**.

**Remediación:** migrar a **sentencias preparadas** (`mysqli_prepare` + `bind_param`) en
todas las escrituras. Elimina la clase de bug de raíz y no depende de la disciplina de quien
edite después.

---

## CTX · Privilegios del usuario MySQL — agravante de infraestructura

El usuario `mess_incidencias@%` —**el mismo que usan gestionPersonal y SIVAC**— tiene
(verificado con `SHOW GRANTS`):

```
GRANT ALL PRIVILEGES ON *.* TO `mess_incidencias`@`%` WITH GRANT OPTION
  (incluye SUPER, FILE, CREATE USER, SHUTDOWN, SYSTEM_USER, RELOAD, …)
```

No hay contención a nivel de base de datos: cualquier fallo de aplicación (una de las tres
de arriba, o una futura) escala de "problema de una app" a **compromiso total del servidor
MySQL** (leer/escribir cualquier BD, `FILE` para leer/escribir archivos del sistema,
`CREATE USER`, `SHUTDOWN`). Además, `WITH GRANT OPTION` permite propagar privilegios.

**Remediación:** crear usuarios MySQL por aplicación con privilegios mínimos
(`SELECT/INSERT/UPDATE/DELETE` sobre las bases que cada app realmente usa), sin `SUPER`,
`FILE`, `CREATE USER` ni `GRANT OPTION`.

---

## Prioridad sugerida

1. **H-1** (bloquea el acceso no autenticado — es el más fácil de explotar y el de mayor impacto).
2. **CTX** (reduce el radio de impacto de todo lo demás).
3. **H-2** (rota credenciales y deja de guardarlas en claro).
4. **H-3** (prepared statements como higiene permanente).
