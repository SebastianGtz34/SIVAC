/* ─────────────────────────────────────────
   SIVAC — funciones.js (utilidades globales)
   Stack: jQuery 3.6 + Bootstrap 4.6 + SweetAlert2 + DataTables (es-MX local)
   ───────────────────────────────────────── */

/**
 * Deja escribir en un SweetAlert abierto desde dentro de un modal de Bootstrap.
 *
 * EL PROBLEMA: Bootstrap 4 engancha un `focusin` en todo el documento y, en
 * cuanto el foco cae en algo que no está dentro del modal, se lo regresa al
 * modal (modal.js → _enforceFocus). SweetAlert cuelga su cuadro del <body>, o
 * sea FUERA del modal, así que al hacer clic en su caja de texto el modal
 * recuperaba el foco al instante y no se podía teclear ni una letra. Se veía al
 * rechazar un documento desde «Documentación», que es un SweetAlert con textarea
 * lanzado desde el modal #modalDocs.
 *
 * EL PARCHE conserva la trampa de foco —es lo que mantiene el modal accesible y
 * lo que evita que el tabulador se escape a la página de atrás— y sólo le agrega
 * una excepción: lo que viva dentro de .swal2-container sí puede quedarse con el
 * foco. Va aquí, y no en la pantalla donde apareció el error, porque el choque
 * se repite en CUALQUIER SweetAlert con campos que se abra desde un modal.
 *
 * Se respeta el namespace `focusin.bs.modal` del original para que el `.off()`
 * que Bootstrap hace al cerrar el modal siga desenganchando este handler.
 */
if (window.jQuery && $.fn.modal && $.fn.modal.Constructor) {
    $.fn.modal.Constructor.prototype._enforceFocus = function () {
        var modal = this;
        $(document)
            .off('focusin.bs.modal')
            .on('focusin.bs.modal', function (e) {
                if (document === e.target || modal._element === e.target) return;
                if ($(modal._element).has(e.target).length) return;
                if ($(e.target).closest('.swal2-container').length) return;  // la excepción
                modal._element.focus();
            });
    };
}

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

/** Etiquetas del MISMO pipeline, redactadas para el JEFE (embed_solicitante.php).
 *
 *  Las de arriba están escritas desde RRHH y al jefe no le sirven: él ES el
 *  solicitante, así que «Enviado al solicitante» o «Aprobado por solicitante» no
 *  le dicen ni qué pasó ni de quién depende el siguiente paso —justo lo que
 *  necesita para saber si la pelota está de su lado—. Aquí cada estatus dice
 *  quién tiene la acción pendiente.
 *
 *  Es sólo PRESENTACIÓN de esa vista: las claves y el pipeline no cambian, y el
 *  backend sigue mandando el mismo estatus. Por eso este mapa no tiene espejo en
 *  includes/flujo.php. */
window.SIVAC_estatusS_JEFE = {
    aspirante: 'En revisión de RRHH',
    enviado_solicitante: 'Pendiente de revisión',
    aprobado_jefe: 'Aprobado · RRHH agenda la entrevista',
    entrevista_confirmada: 'Entrevista confirmada contigo',
    entrevistado: 'Ya lo entrevistaste · sigue RRHH',
    propuesta_enviada: 'RRHH le envió la propuesta',
    propuesta_expirada: 'La propuesta venció sin respuesta',
    propuesta_aceptada: 'Aceptó la propuesta',
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
        // Dura según lo que hay que leer: «el correo NO se envió: <motivo>» no
        // se alcanzaba a leer en 3.5 s. Con el mouse encima se detiene.
        timer: Math.min(10000, Math.max(3500, String(mensaje || '').length * 60)),
        timerProgressBar: true,
        didOpen: function (t) {
            t.addEventListener('mouseenter', Swal.stopTimer);
            t.addEventListener('mouseleave', Swal.resumeTimer);
        },
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

function formatearSoloFecha(fecha) {
    if (!fecha) return '—';

    var partes = String(fecha).substring(0, 10).split('-');

    if (partes.length !== 3) return fecha;

    return `${partes[2]}/${partes[1]}/${partes[0]}`;
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

/* ── Historial y comentarios de la ficha ──
   Los pintan la ficha de RRHH (js/candidatos.js) y el seguimiento del jefe
   (embed_solicitante.php). Viven aquí para que las dos vistas digan lo mismo
   (retro 2026-10-29: el jefe también ve el historial). */

/** Bitácora (candidatos_historial) → elementos del timeline {key, html}.
 *  `etiquetas` = el mapa de estatus de la vista (RRHH o jefe). */
function sivacItemsHistorial(historial, etiquetas) {
    etiquetas = etiquetas || {};
    return (historial || []).map(function (x) {
        var actor = (String(x.no_empleado) === '0') ? 'Sistema'
                  : (x.actor_nombre || ('#' + x.no_empleado));
        var cambio = x.estatus_anterior === x.estatus_nuevo
            ? (etiquetas[x.estatus_nuevo] || x.estatus_nuevo)
            : (etiquetas[x.estatus_anterior] || x.estatus_anterior) + ' → ' + (etiquetas[x.estatus_nuevo] || x.estatus_nuevo);
        return {
            key: x.fecha_creacion,
            html: '<li><div class="t-fecha">' + formatearFecha(x.fecha_creacion) + ' · ' + escHtml(actor) + '</div>'
                + escHtml(cambio)
                + (x.comentario ? '<div class="text-muted small">' + escHtml(x.comentario) + '</div>' : '') + '</li>'
        };
    });
}

/**
 * Fechas de la entrevista del jefe → elementos del timeline {key, html}.
 * `citas` viene de sivacHistorialCitas() (includes/citas.php), de la más nueva a
 * la más vieja. `estatusCandidato` sirve para decir que un cambio que se quedó
 * esperando VoBo ya no lo va a tener (el proceso siguió con la fecha vigente).
 */
function sivacItemsCitas(citas, estatusCandidato) {
    var items = [];
    var vivo = estatusCandidato === 'aprobado_jefe' || estatusCandidato === 'entrevista_confirmada';
    var vigente = null;  // la última que llegó a estar en vigor: los cambios sin VoBo no cuentan
    (citas || []).slice().reverse().forEach(function (ci) {
        var esCambio = ci.estatus === 'por_aprobar' || ci.estatus === 'no_aprobada' || !!ci.vobo_fecha;
        var accion = !vigente ? 'Propuso fechas de entrevista'
                   : vigente.estatus === 'rechazada' ? 'Propuso nuevas fechas (el candidato no pudo en las anteriores)'
                   : esCambio ? 'Pidió cambiar las fechas'
                   : 'Reagendó la entrevista';
        var estado;
        if (ci.estatus === 'pendiente') {
            estado = escHtml('Pendiente de confirmar con el candidato.');
        } else if (ci.estatus === 'confirmada') {
            estado = escHtml('Confirmada' + (ci.confirmada_por_nombre ? ' por ' + ci.confirmada_por_nombre : '')
                + ': ' + formatearFecha(ci.fecha_confirmada) + '.');
        } else if (ci.estatus === 'realizada') {
            estado = escHtml('Realizada el ' + formatearFecha(ci.fecha_confirmada) + '.');
        } else if (ci.estatus === 'rechazada') {
            estado = escHtml('El candidato no pudo en ninguna'
                + (ci.fecha_candidato ? '; disponibilidad del candidato: ' + formatearFecha(ci.fecha_candidato) : '') + '.');
        } else if (ci.estatus === 'por_aprobar') {
            estado = vivo
                ? '<strong class="text-warning">' + escHtml('Esperando el VoBo de RRHH; mientras tanto sigue la fecha anterior.') + '</strong>'
                : escHtml('Se quedó sin VoBo: el proceso siguió con la fecha anterior.');
        } else if (ci.estatus === 'no_aprobada') {
            estado = '<strong class="text-danger">' + escHtml('RRHH no lo aprobó'
                + (ci.vobo_por_nombre ? ' (' + ci.vobo_por_nombre + ', ' + formatearFecha(ci.vobo_fecha) + ')' : '')
                + ': ' + (ci.vobo_motivo || 'sin motivo') + '.') + '</strong> ' + escHtml('Siguió la fecha anterior.');
        } else {
            // Cancelada. Si ya tenía fecha confirmada, RRHH ya se la había dado
            // al candidato: es lo que hay que poder demostrar.
            estado = ci.fecha_confirmada
                ? '<strong class="text-danger">' + escHtml('Ya estaba confirmada con el candidato para el '
                    + formatearFecha(ci.fecha_confirmada) + '; se canceló al reagendar.') + '</strong>'
                : escHtml('Reemplazada por la siguiente propuesta.');
        }
        // El enterado de RRHH (VoBo) de un cambio que sí se aplicó.
        var vobo = (ci.vobo_fecha && ci.estatus !== 'no_aprobada')
            ? '<div class="small text-success"><i class="fas fa-check-circle mr-1"></i>'
                + escHtml('Enterado (VoBo) de RRHH: ' + (ci.vobo_por_nombre || 'RRHH') + ' · ' + formatearFecha(ci.vobo_fecha)) + '</div>'
            : '';
        var aviso = '';
        if (ci.dias_aviso !== null && ci.dias_aviso !== undefined) {
            var txtAviso = 'Aviso: ' + ci.dias_aviso + (Number(ci.dias_aviso) === 1 ? ' día hábil' : ' días hábiles');
            aviso = ' · ' + (String(ci.aviso_corto) === '1'
                ? '<strong class="text-danger">' + escHtml(txtAviso) + '</strong>'
                : '<span class="text-muted">' + escHtml(txtAviso) + '</span>');
        }
        items.push({
            key: ci.fecha_creacion,
            html: '<li><div class="t-fecha">' + formatearFecha(ci.fecha_creacion) + ' · '
                + escHtml(ci.solicitante_nombre || 'Solicitante') + '</div>'
                + escHtml(accion + ': ' + formatearFecha(ci.opcion1) + ' o ' + formatearFecha(ci.opcion2)) + aviso
                + '<div class="small">' + estado + '</div>' + vobo
                + (ci.notas ? '<div class="text-muted small">' + escHtml(ci.notas) + '</div>' : '') + '</li>'
        });
        if (ci.estatus !== 'por_aprobar' && ci.estatus !== 'no_aprobada') vigente = ci;
    });
    return items;
}

/** Elementos {key, html} → <ul class="timeline">, lo más reciente arriba. Las llaves
 *  son 'YYYY-MM-DD HH:MM:SS': la comparación de texto coincide con la cronológica. */
function sivacPintarTimeline(items) {
    items = (items || []).slice().sort(function (a, b) { return a.key < b.key ? 1 : (a.key > b.key ? -1 : 0); });
    if (!items.length) return '<p class="text-muted small">Sin movimientos todavía.</p>';
    return '<ul class="timeline">' + items.map(function (it) { return it.html; }).join('') + '</ul>';
}

/** Hilo de comentarios, del más viejo al más nuevo (como los de ticketsBI).
 *  Las notas internas sólo llegan aquí en la vista de RRHH: el servidor no se las
 *  manda al jefe. */
function sivacHtmlComentarios(comentarios) {
    if (!comentarios || !comentarios.length) {
        return '<p class="text-muted small mb-2">Sin comentarios todavía.</p>';
    }
    var yo = String(miNoEmpleado());
    return '<div class="coment-lista">' + comentarios.map(function (c) {
        var interno = String(c.es_interno) === '1';
        var mio = String(c.no_empleado) === yo;
        return '<div class="coment' + (mio ? ' mio' : '') + (interno ? ' interno' : '') + '">'
            + '<div class="coment-meta">' + escHtml(c.nombre) + ' · ' + formatearFecha(c.fecha_creacion)
            + (interno ? ' <span class="badge badge-warning"><i class="fas fa-lock mr-1"></i>Interno</span>' : '')
            + '</div>' + escHtml(c.comentario).replace(/\n/g, '<br>') + '</div>';
    }).join('') + '</div>';
}

/** Formulario para comentar. Con `conInterno` (RRHH) se elige si es nota interna
 *  o para el jefe; arranca en «Interno» para que nada le llegue al jefe por descuido. */
function sivacFormComentario(conInterno) {
    return '<div class="coment-form">'
        + '<textarea class="form-control form-control-sm mb-2 txtComentario" rows="2" maxlength="2000" placeholder="'
        + (conInterno ? 'Escribe un comentario…' : 'Escribe un comentario para RRHH…') + '"></textarea>'
        + '<div class="d-flex align-items-center justify-content-between">'
        + (conInterno
            ? '<div class="btn-group btn-group-sm btn-group-toggle grupoInterno" data-toggle="buttons">'
                + '<label class="btn btn-outline-warning active"><input type="radio" name="coment_interno" value="1" checked>'
                + '<i class="fas fa-lock mr-1"></i>Interno (sólo RRHH)</label>'
                + '<label class="btn btn-outline-primary"><input type="radio" name="coment_interno" value="0">'
                + '<i class="fas fa-user-tie mr-1"></i>Para el jefe</label></div>'
            : '<span></span>')
        + '<button type="button" class="btn btn-sm btn-primary btnComentar"><i class="fas fa-paper-plane mr-1"></i>Enviar</button>'
        + '</div></div>';
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
