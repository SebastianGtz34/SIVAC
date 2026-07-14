/* dashboard.js — Gráficas del dashboard (Chart.js 2.9.4, tema-aware). */
$(function () {
    var D = window.SIVAC_CHART || { embudo: { labels: [], data: [] }, vacantes: { labels: [], cand: [], entr: [] } };
    var charts = {};

    function tema() {
        return {
            c1: messColor('chart-1'),
            c2: messColor('chart-2'),
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
    }

    construir();
    // Re-render al cambiar el tema para que los colores sigan al modo.
    $(document).on('sivac:themechange', construir);
});
