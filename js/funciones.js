/* ─────────────────────────────────────────
   SIVAC — funciones.js (utilidades globales)
   Stack: jQuery 3.6 + Bootstrap 4.6 + SweetAlert2 + DataTables (es-MX local)
   ───────────────────────────────────────── */

/** Etiquetas legibles de los estatuss del pipeline (espejo de includes/flujo.php).
 *  Si cambias uno, cámbialo también allá: el backend es la fuente de verdad. */
window.SIVAC_estatusS = {
    aspirante: 'Capturado',
    enviado_solicitante: 'Enviado al solicitante',
    aprobado_jefe: 'Aprobado por solicitante',
    entrevista_confirmada: 'Entrevista con jefe confirmada',
    entrevistado: 'Entrevistado por el jefe',
    propuesta_enviada: 'Propuesta enviada',
    propuesta_expirada: 'Propuesta expirada',
    propuesta_aceptada: 'Propuesta aceptada',
    documentacion: 'En documentación',
    contratado: 'Contratado',
    descartado: 'Descartado'
};

/** Etiquetas de estatus de VACANTE (espejo del ENUM vacantes.estatus). */
window.SIVAC_estatusS_VAC = {
    pendiente_vobo: 'Pendiente de VoBo',
    abierta: 'Abierta',
    en_proceso: 'En proceso',
    pausada: 'Pausada',
    cerrada: 'Cerrada',
    cancelada: 'Cancelada',
    rechazada: 'Rechazada'
};

/** Etiquetas del tipo de vacante ('practicas' = flujo corto, sin propuesta). */
window.SIVAC_TIPOS_VACANTE = { temporal: 'Temporal', permanente: 'Permanente', practicas: 'Prácticas' };

/** Lee una cookie por nombre. */
function getCookie(name) {
    var cookies = new URLSearchParams(document.cookie.replace(/; /g, '&'));
    return cookies.get(name) || undefined;
}

/** noEmpleado de la sesión (cookie propia o global del portal). */
function miNoEmpleado() {
    return getCookie('noEmpleadoSVC') || getCookie('noEmpleadoL') || '';
}

/** Lee una variable CSS del tema actual (para SweetAlert2/Chart.js). */
function messColor(token) {
    var v = getComputedStyle(document.body).getPropertyValue('--' + token);
    return (v || '').trim() || '#074480';
}

/** Toast (SweetAlert2, independiente de la versión de Bootstrap).
 *  El mensaje es TEXTO PLANO: `title` de SweetAlert2 se pinta como HTML, y los
 *  errores de SMTP traen la dirección entre picos ("... failed: <x@mess.com.mx>"),
 *  que el navegador se comía como etiqueta desconocida. El toast acababa diciendo
 *  "no se pudo enviar a:" sin decir a quién. Si algún día un toast necesita
 *  formato, que reciba el HTML ya armado por una función aparte. */
function mostrarToast(mensaje, tipo) {
    var iconMap = { success: 'success', danger: 'error', error: 'error', warning: 'warning', info: 'info' };
    Swal.fire({
        toast: true,
        position: 'bottom-end',
        icon: iconMap[tipo] || 'info',
        title: escHtml(mensaje),
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true,
        background: messColor('card-bg'),
        color: messColor('text')
    });
}

/** Compatibilidad con firma mostrarAlerta(tipo, mensaje). */
function mostrarAlerta(tipo, mensaje) {
    mostrarToast(mensaje, tipo);
}

/** Confirmación (SweetAlert2). callback() se ejecuta si el usuario confirma.
 *  `mensaje` ADMITE HTML: varias confirmaciones resaltan con <strong> el dato que
 *  decide la respuesta (a qué áreas se avisa, qué NO se toca). Iba como `text`,
 *  que SweetAlert2 pinta con textContent, y las etiquetas salían escritas en
 *  pantalla. Todo lo que venga de datos se escapa con escHtml() en el llamador. */
function confirmarAccion(mensaje, callback, opciones) {
    opciones = opciones || {};
    Swal.fire({
        title: opciones.titulo || '¿Estás seguro?',
        html: mensaje,
        icon: opciones.icon || 'warning',
        showCancelButton: true,
        confirmButtonColor: messColor('accent'),
        cancelButtonColor: messColor('text-muted'),
        background: messColor('card-bg'),
        color: messColor('text'),
        confirmButtonText: opciones.confirmar || 'Sí, continuar',
        cancelButtonText: 'Cancelar'
    }).then(function (result) {
        if (result.isConfirmed && typeof callback === 'function') callback();
    });
}

/** Escapa HTML para evitar XSS al inyectar datos en el DOM. */
function escHtml(str) {
    if (str == null) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/** Formatea 'YYYY-MM-DD HH:MM:SS' → 'DD/MM/YYYY HH:mm'. */
function formatearFecha(fecha) {
    if (!fecha) return '—';
    var d = new Date(String(fecha).replace(' ', 'T'));
    if (isNaN(d)) return fecha;
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear()
        + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
}

/** Formatea solo la fecha 'YYYY-MM-DD' → 'DD/MM/YYYY'. */
function formatearSoloFecha(fecha) {
    if (!fecha) return '—';
    var d = new Date(String(fecha).replace(' ', 'T'));
    if (isNaN(d)) return fecha;
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
}

/** Formatea tamaño en bytes → B/KB/MB. */
function formatearTamano(bytes) {
    bytes = parseInt(bytes) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
}

/** Badge HTML de estatus de candidato. */
function badgeestatusCandidato(estatus, label) {
    return '<span class="badge badge-estatus badge-' + escHtml(estatus) + '">' + escHtml(label || estatus) + '</span>';
}

/** Badge HTML de estatus de vacante. */
function badgeestatusVacante(estatus, label) {
    return '<span class="badge badge-estatus badge-vac-' + escHtml(estatus) + '">' + escHtml(label || estatus) + '</span>';
}

/** Idioma es-MX local para DataTables (cero CDN). */
function dtIdioma() {
    return { url: 'vendor/datatables/i18n/es-MX.json' };
}
function dtIdiomaEmbed() {
    return { url: '../SIVAC/vendor/datatables/i18n/es-MX.json' };
}

/** Reajusta el ancho de columnas de una DataTable cuando cambia el contexto:
 *  al redimensionar la ventana y al cambiar de tema. Corrige columnas angostas y
 *  encabezados desalineados, que aparecen porque la tabla se inicializa vacía y se
 *  llena por AJAX (el ancho se calcula sobre la tabla vacía). Tras poblar, cada
 *  módulo debe llamar además `tabla.columns.adjust()` después del draw(). Devuelve
 *  la misma tabla para poder encadenar. */
function dtAutoAjustar(tabla) {
    var timer;
    var reajustar = function () { try { tabla.columns.adjust(); } catch (e) {} };
    $(window).on('resize', function () { clearTimeout(timer); timer = setTimeout(reajustar, 150); });
    $(document).on('sivac:themechange', reajustar);
    return tabla;
}

/** Wrapper $.ajax POST → JSON con callback(err, res) estilo node. */
function ajaxPost(url, data, callback) {
    $.ajax({
        url: url, method: 'POST', data: data, dataType: 'json',
        success: function (res) { if (typeof callback === 'function') callback(null, res); },
        error: function (xhr, status, err) { if (typeof callback === 'function') callback(err || status, null); }
    });
}

/** Envía un FormData (para subidas de archivo) → JSON con callback(err, res). */
function ajaxUpload(url, formData, callback) {
    $.ajax({
        url: url, method: 'POST', data: formData,
        processData: false, contentType: false, dataType: 'json',
        success: function (res) { if (typeof callback === 'function') callback(null, res); },
        error: function (xhr, status, err) { if (typeof callback === 'function') callback(err || status, null); }
    });
}
