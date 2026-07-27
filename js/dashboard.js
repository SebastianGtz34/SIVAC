/* dashboard.js — Gráficas del dashboard (Chart.js 2.9.4, tema-aware).
 * Los filtros de región/gerente viajan por GET y los resuelve inicio.php: el
 * alcance de lo que cada quien puede ver se decide en el servidor, aquí solo se
 * reenvía el formulario. */
$(function () {
    var D = window.SIVAC_CHART || { embudo: { labels: [], data: [] }, vacantes: { labels: [], cand: [], entr: [] } };
    D.rechazos = D.rechazos || { labels: [], data: [] };
    D.tiempos  = D.tiempos  || { labels: [], data: [] };
    var charts = {};

    // Filtrar = recargar con los parámetros; sin botón "aplicar" de por medio.
    $('#filtroRegion, #filtroGerente').on('change', function () {
        $('#formFiltros').trigger('submit');
    });

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

        // Tiempo promedio por etapa (días): barras horizontales, un solo color.
        var $tm = $('#chartTiempos');
        if (!D.tiempos.labels.length) { $tm.hide(); $('#tiempoVacio').show(); }
        else {
            $tm.show(); $('#tiempoVacio').hide();
            if (charts.tiempo) charts.tiempo.destroy();
            charts.tiempo = new Chart($tm[0].getContext('2d'), {
                type: 'horizontalBar',
                data: {
                    labels: D.tiempos.labels,
                    datasets: [{
                        label: 'Días promedio',
                        data: D.tiempos.data,
                        backgroundColor: t.c2,
                        borderWidth: 0,
                        barPercentage: 0.7
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    legend: { display: false },
                    tooltips: {
                        backgroundColor: messColor('card-bg'), titleFontColor: t.text, bodyFontColor: t.text, borderColor: t.grid, borderWidth: 1,
                        callbacks: { label: function (item) { return item.xLabel + ' días'; } }
                    },
                    scales: {
                        xAxes: [{ ticks: { beginAtZero: true, fontColor: t.text }, gridLines: { color: t.grid, zeroLineColor: t.grid } }],
                        yAxes: [{ ticks: { fontColor: t.text }, gridLines: { display: false } }]
                    }
                }
            });
        }
    }

    construir();
    // Re-render al cambiar el tema para que los colores sigan al modo.
    $(document).on('sivac:themechange', construir);
});
