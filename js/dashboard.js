/* dashboard.js — Gráficas del dashboard (Chart.js 2.9.4, tema-aware).
 * Los filtros de región/gerente viajan por GET y los resuelve inicio.php: el
 * alcance de lo que cada quien puede ver se decide en el servidor, aquí solo se
 * reenvía el formulario.
 *
 * Las gráficas van ocultas: RRHH trabaja con la tabla y las abre con «Ver
 * gráficas». Hay dos vistas, una por botón, y sólo una abierta a la vez:
 *   - 'clasicas'  → estas cuatro, para todos.
 *   - 'unicornio' → chiste local con RRHH (dashboard-unicornio.js); inicio.php
 *                   sólo pinta su botón y su sección a quien es de RRHH.
 * La vista abierta se guarda por pestaña (sessionStorage) para que sobreviva a
 * la recarga del filtro; inicio.php la lee antes de pintar la tabla para que no
 * brinque. Las gráficas se construyen hasta que su vista se abre: un canvas
 * oculto mide 0 y Chart.js no se redimensiona solo al aparecer.
 *
 * Regla: todo cambio a una gráfica se replica en su par de la otra vista. Lo
 * que comparten (hoy, los cortes de la de tiempos) vive aquí afuera para que
 * dashboard-unicornio.js —que se carga después— use lo mismo. */

// ---------------------------------------------------------------------------
// Tiempo por etapa con los cortes de RRHH (Selección y Contratación, ver
// inicio.php): cada fase va en su propio renglón, después de su última etapa.
// ---------------------------------------------------------------------------

// Renglones: las etapas en orden y cada fase insertada en su 'pos' (de atrás
// hacia adelante para que las posiciones sigan valiendo).
function sivacFilasTiempos(T) {
    var filas = T.labels.map(function (l, i) { return { label: l, data: T.data[i] }; });
    (T.fases || []).slice().reverse().forEach(function (f) { filas.splice(f.pos, 0, $.extend({ fase: true }, f)); });
    return filas;
}

// Callback del eje: la etiqueta de una fase va en blanco (la pinta el plugin).
function sivacEtiquetaTiempo(filas) {
    return function (l, j) { return filas[j].fase ? '' : l; };
}

// Líneas del tooltip de un renglón; `dias` formatea el valor a la manera de
// cada gráfica.
function sivacTooltipTiempo(f, dias) {
    if (!f.fase) return 'Promedio: ' + dias(f.data);
    return ['Fase completa: ' + dias(f.data), f.detalle,
        'Medido en ' + f.n + (f.n === 1 ? ' candidato' : ' candidatos')];
}

/* Plugin por gráfica: franja y etiqueta en negritas para los renglones de fase y
 * la línea punteada del corte debajo. Va antes de las barras para quedar debajo
 * de ellas. Opciones en `options.fases`: filas y colores { franja, corte, texto }. */
var sivacFasesPlugin = {
    beforeDatasetsDraw: function (chart) {
        var op = chart.options.fases;
        if (!op) return;
        var ctx = chart.ctx, eje = chart.scales[chart.options.scales.yAxes[0].id];
        var els = chart.getDatasetMeta(0).data, alto = (eje.bottom - eje.top) / els.length;
        // Mismo x que Chart.js usa para las etiquetas de un eje a la izquierda.
        var gl = eje.options.gridLines;
        var xEtq = eje.right - (gl.drawTicks ? gl.tickMarkLength : 0) - eje.options.ticks.padding;
        var familia = Chart.defaults.global.defaultFontFamily;
        els.forEach(function (el, j) {
            if (!op.filas[j].fase) return;
            var y = el._view.y;
            ctx.save();
            ctx.fillStyle = op.colores.franja;
            ctx.fillRect(0, y - alto / 2, chart.width, alto);

            ctx.font = Chart.helpers.fontString(12, 'bold', familia);
            ctx.fillStyle = op.colores.texto;
            ctx.textAlign = 'right';
            ctx.textBaseline = 'middle';
            ctx.fillText(op.filas[j].label, xEtq, y);

            // El corte: separa la fase de la etapa que sigue (si sigue alguna).
            if (j < els.length - 1) {
                var yc = Math.round(y + alto / 2) + 0.5;
                ctx.strokeStyle = op.colores.corte;
                ctx.lineWidth = 1;
                ctx.setLineDash([4, 3]);
                ctx.beginPath();
                ctx.moveTo(0, yc);
                ctx.lineTo(chart.width, yc);
                ctx.stroke();
            }
            ctx.restore();
        });
    }
};

function sivacColoresFases(grid, corte, texto) {
    return { franja: Chart.helpers.color(grid).alpha(0.55).rgbString(), corte: corte, texto: texto };
}

$(function () {
    var D = window.SIVAC_CHART || { embudo: { labels: [], data: [] }, vacantes: { labels: [], cand: [], entr: [] } };
    D.rechazos = D.rechazos || { labels: [], data: [] };
    D.tiempos  = D.tiempos  || { labels: [], data: [] };
    var charts = {};

    // Filtrar = recargar con los parámetros; sin botón "aplicar" de por medio.
    $('#filtroRegion, #filtroGerente').on('change', function () {
        $('#formFiltros').trigger('submit');
    });

    // ---------------------------------------------------------------------
    // Ver / ocultar gráficas. Cada botón trae data-graficas (la vista) y
    // aria-controls (su sección). Abrir una cierra la otra.
    // ---------------------------------------------------------------------
    var CLAVE_VISTA = 'sivac-dashboard-graficas';
    var $botones = $('[data-graficas]');

    function seccionDe(btn) { return $('#' + $(btn).attr('aria-controls')); }

    function pintarBotones() {
        $botones.each(function () {
            var ver = !seccionDe(this).prop('hidden');
            $(this).attr('aria-expanded', ver ? 'true' : 'false')
                .find('.btn-graficas-texto').text(ver ? 'Ocultar gráficas' : 'Ver gráficas');
        });
    }

    $botones.on('click', function () {
        var vista = $(this).data('graficas'), abrir = seccionDe(this).prop('hidden');
        $botones.each(function () { seccionDe(this).prop('hidden', true); });
        if (abrir) seccionDe(this).prop('hidden', false);
        try { sessionStorage.setItem(CLAVE_VISTA, abrir ? vista : ''); } catch (e) {}
        pintarBotones();
        if (abrir) $(document).trigger('sivac:graficas', [vista]);
    });

    function clasicasAbiertas() { return !$('#seccionGraficas').prop('hidden'); }

    function tema() {
        return {
            c1: messColor('chart-1'),
            c2: messColor('chart-2'),
            // Paleta categórica (para la gráfica de rechazos por etapa).
            paleta: ['chart-1', 'chart-2', 'chart-3', 'chart-4', 'chart-5'].map(messColor),
            grid: messColor('grid'),
            text: messColor('text-muted'),
            accent: messColor('accent')
        };
    }

    function ejeEntero(t) {
        return {
            ticks: { beginAtZero: true, precision: 0, fontColor: t.text },
            gridLines: { color: t.grid, zeroLineColor: t.grid }
        };
    }

    function construir() {
        var t = tema();

        // Embudo del proceso: barras horizontales, un solo color (magnitud por etapa).
        if (charts.embudo) charts.embudo.destroy();
        charts.embudo = new Chart(document.getElementById('chartEmbudo').getContext('2d'), {
            type: 'horizontalBar',
            data: {
                labels: D.embudo.labels,
                datasets: [{
                    label: 'Candidatos',
                    data: D.embudo.data,
                    backgroundColor: t.accent,
                    borderWidth: 0,
                    barPercentage: 0.7
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                legend: { display: false },
                tooltips: { backgroundColor: messColor('card-bg'), titleFontColor: t.text, bodyFontColor: t.text, borderColor: t.grid, borderWidth: 1 },
                scales: {
                    xAxes: [ejeEntero(t)],
                    yAxes: [{ ticks: { fontColor: t.text }, gridLines: { display: false } }]
                }
            }
        });

        // Candidatos vs entrevistados por vacante: dos series categóricas.
        var $c = $('#chartVacantes');
        if (!D.vacantes.labels.length) { $c.hide(); $('#vacVacio').show(); }
        else {
            $c.show(); $('#vacVacio').hide();
            if (charts.vac) charts.vac.destroy();
            charts.vac = new Chart($c[0].getContext('2d'), {
                type: 'bar',
                data: {
                    labels: D.vacantes.labels,
                    datasets: [
                        { label: 'Candidatos', data: D.vacantes.cand, backgroundColor: t.c1, borderWidth: 0 },
                        { label: 'Entrevistados', data: D.vacantes.entr, backgroundColor: t.c2, borderWidth: 0 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    legend: { position: 'bottom', labels: { fontColor: t.text } },
                    tooltips: { backgroundColor: messColor('card-bg'), titleFontColor: t.text, bodyFontColor: t.text, borderColor: t.grid, borderWidth: 1 },
                    scales: {
                        xAxes: [{ ticks: { fontColor: t.text }, gridLines: { display: false } }],
                        yAxes: [ejeEntero(t)]
                    }
                }
            });
        }

        // Rechazados por etapa: una categoría por etapa (paleta categórica).
        var $r = $('#chartRechazos');
        if (!D.rechazos.labels.length) { $r.hide(); $('#rechVacio').show(); }
        else {
            $r.show(); $('#rechVacio').hide();
            if (charts.rech) charts.rech.destroy();
            charts.rech = new Chart($r[0].getContext('2d'), {
                type: 'bar',
                data: {
                    labels: D.rechazos.labels,
                    datasets: [{
                        label: 'Rechazados',
                        data: D.rechazos.data,
                        backgroundColor: D.rechazos.labels.map(function (_, i) { return t.paleta[i % t.paleta.length]; }),
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    legend: { display: false },
                    tooltips: { backgroundColor: messColor('card-bg'), titleFontColor: t.text, bodyFontColor: t.text, borderColor: t.grid, borderWidth: 1 },
                    scales: {
                        xAxes: [{ ticks: { fontColor: t.text }, gridLines: { display: false } }],
                        yAxes: [ejeEntero(t)]
                    }
                }
            });
        }

        // Tiempo promedio por etapa (días): barras horizontales, un color para
        // las etapas y otro para las fases (los cortes, con su renglón resaltado).
        var $tm = $('#chartTiempos'), $tmLienzo = $('#lienzoTiemposClasica');
        if (!D.tiempos.labels.length) { $tmLienzo.hide(); $('#tiempoVacio').show(); $('#tiempoCortes').hide(); }
        else {
            var filasT = sivacFilasTiempos(D.tiempos);
            $tmLienzo.show(); $('#tiempoVacio').hide();
            $('#tiempoCortes').toggle(filasT.length > D.tiempos.labels.length);
            if (charts.tiempo) charts.tiempo.destroy();
            charts.tiempo = new Chart($tm[0].getContext('2d'), {
                type: 'horizontalBar',
                data: {
                    labels: filasT.map(function (f) { return f.label; }),
                    datasets: [{
                        label: 'Días promedio',
                        data: filasT.map(function (f) { return f.data; }),
                        backgroundColor: filasT.map(function (f) { return f.fase ? t.c1 : t.c2; }),
                        borderWidth: 0,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    legend: { display: false },
                    tooltips: {
                        backgroundColor: messColor('card-bg'), titleFontColor: t.text, bodyFontColor: t.text, borderColor: t.grid, borderWidth: 1,
                        callbacks: { label: function (item) { return sivacTooltipTiempo(filasT[item.index], function (v) { return v + ' días'; }); } }
                    },
                    scales: {
                        xAxes: [{ ticks: { beginAtZero: true, fontColor: t.text }, gridLines: { color: t.grid, zeroLineColor: t.grid } }],
                        yAxes: [{ ticks: { fontColor: t.text, callback: sivacEtiquetaTiempo(filasT) }, gridLines: { display: false } }]
                    },
                    fases: { filas: filasT, colores: sivacColoresFases(t.grid, t.text, messColor('text')) }
                },
                plugins: [sivacFasesPlugin]
            });
        }
    }

    pintarBotones();
    $(document).on('sivac:graficas', function (e, vista) { if (vista === 'clasicas') construir(); });
    if (clasicasAbiertas()) construir();
    // Re-render al cambiar el tema para que los colores sigan al modo.
    $(document).on('sivac:themechange', function () { if (clasicasAbiertas()) construir(); });
});
