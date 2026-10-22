/* dashboard-unicornio.js — Gráficas del unicornio (chiste local con RRHH).
 * inicio.php sólo carga este archivo —y sólo manda window.SIVAC_UNICORNIO— a
 * quien es del departamento de RRHH; los demás ven nada más las gráficas de
 * siempre (dashboard.js). El botón y la sección los maneja dashboard.js: aquí se
 * construye cuando la sección «unicornio» se abre (evento sivac:graficas).
 *
 * Cada gráfica contesta una pregunta y el unicornio (img/unicornio_grafica.jpeg)
 * la dice en una frase.
 *   - Carriles: un renglón por vacante y un unicornio por candidato en la etapa
 *     donde va (el dibujo que hizo RRHH).
 *   - Rechazos y tiempos: cada barra se pinta como cuerno. La LONGITUD del cuerno
 *     es el dato, igual que en una barra normal, y el valor va escrito en la
 *     punta; el dorado y los surcos son decoración. */
$(function () {
    var $seccion = $('#seccionUnicornio');
    if (!$seccion.length) return;

    var base = window.SIVAC_CHART || {};
    var D = {
        carriles: (window.SIVAC_UNICORNIO || {}).carriles || { columnas: [], vacantes: [] },
        rechazos: base.rechazos || { labels: [], data: [] },
        tiempos:  base.tiempos  || { labels: [], data: [] }
    };
    var charts = {};
    var TRANSPARENTE = 'rgba(0,0,0,0)';

    function abierta() { return !$seccion.prop('hidden'); }

    // ---------------------------------------------------------------------
    // Utilidades
    // ---------------------------------------------------------------------
    function tema() {
        function tono(token) {
            return { brillo: messColor(token + '-brillo'), medio: messColor(token), sombra: messColor(token + '-sombra') };
        }
        return {
            grid: messColor('grid'),
            text: messColor('text'),
            muted: messColor('text-muted'),
            fondo: messColor('card-bg'),
            oro: tono('cuerno'),
            plata: tono('plata'),
            surco: messColor('cuerno-surco')
        };
    }

    function num(n) { return Number(n).toLocaleString('es-MX'); }
    function plural(n, uno, varios) { return num(n) + ' ' + (Number(n) === 1 ? uno : varios); }
    function dias(v) {
        if (v < 1) return 'menos de 1 día';
        var t = Number(v).toLocaleString('es-MX', { maximumFractionDigits: 1 });
        return t + (t === '1' ? ' día' : ' días');
    }
    function diasCorto(v) { return v < 1 ? '< 1 día' : dias(v); }
    function suma(arr) { return arr.reduce(function (a, v) { return a + (Number(v) || 0); }, 0); }
    function indiceMayor(arr) {
        var k = 0;
        arr.forEach(function (v, i) { if (Number(v) > Number(arr[k])) k = i; });
        return k;
    }
    // «a, b y c»
    function enLista(partes) {
        return partes.length > 1 ? partes.slice(0, -1).join(', ') + ' y ' + partes[partes.length - 1] : partes.join('');
    }

    // Chart.js 2 pinta cada elemento de un arreglo en su propio renglón: así los
    // nombres largos se parten en vez de girarse 45°.
    function renglones(texto, max, tope) {
        var lineas = [], actual = '';
        String(texto || '').split(/\s+/).forEach(function (p) {
            if (actual && (actual + ' ' + p).length > max) { lineas.push(actual); actual = p; }
            else actual = actual ? actual + ' ' + p : p;
        });
        if (actual) lineas.push(actual);
        if (lineas.length > tope) {
            lineas = lineas.slice(0, tope);
            lineas[tope - 1] += '…';
        }
        return lineas;
    }

    function tooltips(t, callbacks) {
        return {
            backgroundColor: t.fondo, titleFontColor: t.text, bodyFontColor: t.text,
            borderColor: t.grid, borderWidth: 1, displayColors: false,
            callbacks: callbacks || {}
        };
    }

    // Eje de categorías sin rejilla: sólo la línea base donde se apoyan los cuernos.
    function ejeCategorias(t) {
        return {
            ticks: { fontColor: t.muted, maxRotation: 0, autoSkip: false, padding: 8 },
            gridLines: { display: true, drawOnChartArea: false, drawTicks: false, color: t.grid }
        };
    }

    // ---------------------------------------------------------------------
    // ¿En qué etapa va cada candidato? — carriles: un renglón por vacante
    // activa, una columna por etapa (las de RRHH) y un unicornio por candidato.
    // La barra llega hasta la etapa del candidato más avanzado de la vacante.
    // ---------------------------------------------------------------------
    var MAX_EN_PILA = 3;   // unicornios visibles por casilla; el resto va en «+N»

    function unicornio(c) {
        var etq = c.nombre + ' · ' + c.estatus;
        return $('<span class="unicornio-avatar carril-unicornio" role="img" tabindex="0">')
            .attr({ title: etq, 'aria-label': etq });
    }

    function construirCarriles() {
        var C = D.carriles, cols = C.columnas, nCols = cols.length;
        var $c = $('#carriles').empty();
        var porCol = cols.map(function () { return 0; });
        var activas = C.vacantes.filter(function (v) { return v.cand.length; }).map(function (v) {
            var max = 0;
            v.cand.forEach(function (c) { porCol[c.col]++; if (c.col > max) max = c.col; });
            return { v: v, max: max };
        });
        var sinCand = C.vacantes.length - activas.length;

        if (!activas.length) {
            $('#carriles, #notaCarriles').hide();
            $('#dichoCarriles').html(!C.vacantes.length ? 'No hay vacantes activas con estos filtros.'
                : (C.vacantes.length === 1 ? 'La vacante activa todavía no tiene candidatos.'
                    : 'Las <strong>' + num(C.vacantes.length) + '</strong> vacantes activas todavía no tienen candidatos.'));
            return;
        }
        $('#carriles, #notaCarriles').show();

        // Las que van más adelante, arriba; a empate, la que tiene más candidatos.
        activas.sort(function (a, b) { return (b.max - a.max) || (b.v.cand.length - a.v.cand.length); });

        var total = suma(porCol);
        var reparto = [];
        porCol.forEach(function (n, i) { if (n) reparto.push('<strong>' + num(n) + '</strong> en ' + escHtml(cols[i].toLowerCase())); });
        var dicho = 'Hay <strong>' + plural(total, 'candidato', 'candidatos') + '</strong> en <strong>'
            + plural(activas.length, 'vacante', 'vacantes') + '</strong>: ' + enLista(reparto) + '. '
            + 'La que va más adelante es «' + escHtml(activas[0].v.puesto)
            + (activas[0].v.region ? ' · ' + escHtml(activas[0].v.region) : '') + '» (ya en '
            + escHtml(cols[activas[0].max].toLowerCase()) + ').';
        if (sinCand) {
            dicho += ' <strong>' + num(sinCand) + '</strong> '
                + (sinCand === 1 ? 'vacante activa aún no tiene' : 'vacantes activas aún no tienen') + ' candidatos.';
        }
        $('#dichoCarriles').html(dicho);

        $c[0].style.setProperty('--carril-cols', nCols);
        $('<div class="carril-cabeza carril-cabeza-vacante">').text('Vacante').appendTo($c);
        cols.forEach(function (col, i) {
            $('<div class="carril-cabeza">').text(col)
                .append($('<span class="carril-cabeza-num">').text(num(porCol[i])))
                .appendTo($c);
        });

        activas.forEach(function (a) {
            var v = a.v;
            var $vac = $('<div class="carril-vacante">').append($('<strong>').text(v.puesto));
            $('<small>').text(v.region ? v.folio + ' · ' + v.region : v.folio).appendTo($vac);

            var $pista = $('<div class="carril-pista">')
                .attr('aria-label', v.puesto + ': ' + plural(v.cand.length, 'candidato', 'candidatos'));
            $('<div class="carril-barra">').css('width', ((a.max + 0.5) / nCols * 100) + '%').appendTo($pista);

            for (var i = 0; i < nCols; i++) {
                var aqui = v.cand.filter(function (c) { return c.col === i; });
                if (!aqui.length) continue;
                var $grupo = $('<div class="carril-grupo">').css('left', ((i + 0.5) / nCols * 100) + '%');
                aqui.slice(0, MAX_EN_PILA).forEach(function (c) { unicornio(c).appendTo($grupo); });
                if (aqui.length > MAX_EN_PILA) {
                    var resto = aqui.slice(MAX_EN_PILA).map(function (c) { return c.nombre + ' · ' + c.estatus; }).join('\n');
                    $('<span class="carril-mas" tabindex="0">').text('+' + (aqui.length - MAX_EN_PILA))
                        .attr({ title: resto, 'aria-label': resto }).appendTo($grupo);
                }
                $grupo.appendTo($pista);
            }
            $c.append($vac, $pista);
        });
    }

    // ---------------------------------------------------------------------
    // El cuerno: la marca de las barras (rechazos y tiempos)
    // ---------------------------------------------------------------------
    // Silueta en ejes locales: base plana sobre el cero (y = 0), punta en
    // (0, -largo) y costados apenas abombados.
    function silueta(ctx, b, largo) {
        ctx.beginPath();
        ctx.moveTo(-b, 0);
        ctx.quadraticCurveTo(-b * 0.7, -largo * 0.5, 0, -largo);
        ctx.quadraticCurveTo(b * 0.7, -largo * 0.5, b, 0);
        ctx.closePath();
    }

    // Sombreado de cono: oscuro en los cantos, brillo cargado a la izquierda.
    function degradado(ctx, b, c) {
        var g = ctx.createLinearGradient(-b, 0, b, 0);
        g.addColorStop(0, c.sombra);
        g.addColorStop(0.32, c.brillo);
        g.addColorStop(0.62, c.medio);
        g.addColorStop(1, c.sombra);
        return g;
    }

    // Pinta un cuerno de la base (bx, by) a la punta (tx, ty).
    function pintarCuerno(ctx, o, c) {
        var dx = o.tx - o.bx, dy = o.ty - o.by;
        var largo = Math.sqrt(dx * dx + dy * dy), b = o.ancho / 2;
        if (largo < 1 || b < 1) return;

        ctx.save();
        ctx.translate(o.bx, o.by);
        ctx.rotate(Math.atan2(dx, -dy));

        silueta(ctx, b, largo);
        if (o.resaltar) { ctx.shadowColor = c.brillo; ctx.shadowBlur = 14; }
        ctx.fillStyle = degradado(ctx, b, c);
        ctx.fill();
        ctx.shadowBlur = 0;

        // Surcos en espiral: una raya oscura y su brillo, inclinadas.
        ctx.save();
        ctx.clip();
        var paso = Math.max(7, Math.min(11, b * 0.8)), incl = b * 0.45;
        ctx.lineWidth = 1.2;
        for (var s = paso * 0.8; s < largo + incl; s += paso) {
            ctx.globalAlpha = 0.45; ctx.strokeStyle = c.surco;
            ctx.beginPath(); ctx.moveTo(-b, -s + incl); ctx.lineTo(b, -s - incl); ctx.stroke();
            ctx.globalAlpha = 0.5; ctx.strokeStyle = c.brillo;
            ctx.beginPath(); ctx.moveTo(-b, -s + incl + 1.5); ctx.lineTo(b, -s - incl + 1.5); ctx.stroke();
        }
        ctx.restore();

        // Canto: separa el dorado de una superficie clara.
        silueta(ctx, b, largo);
        ctx.lineWidth = 1;
        ctx.strokeStyle = c.sombra;
        ctx.stroke();
        ctx.restore();
    }

    /* Plugin por gráfica (no global): las barras de Chart.js quedan transparentes
     * y siguen dando el tamaño, la animación y el tooltip; encima se pinta un
     * cuerno por barra con su valor en la punta. Opciones en `options.cuernos`:
     * colores, tono(j) → 'oro' | 'plata', fuerte(j) → valor en color de texto
     * aunque el cuerno sea plata, y formato(valor). */
    var cuernosPlugin = {
        afterDatasetsDraw: function (chart) {
            var op = chart.options.cuernos;
            if (!op) return;
            var ctx = chart.ctx, activos = chart.active || [];
            var familia = Chart.defaults.global.defaultFontFamily;
            chart.data.datasets.forEach(function (ds, i) {
                var meta = chart.getDatasetMeta(i);
                if (meta.hidden) return;
                meta.data.forEach(function (el, j) {
                    var v = el._view, horiz = !!v.horizontal, valor = Number(ds.data[j]) || 0;
                    var tono = op.tono ? op.tono(j) : 'oro';
                    pintarCuerno(ctx, {
                        bx: horiz ? v.base : v.x, by: horiz ? v.y : v.base,
                        tx: v.x, ty: v.y, ancho: horiz ? v.height : v.width,
                        resaltar: activos.indexOf(el) !== -1
                    }, op.colores[tono]);

                    // El valor, escrito en la punta: no hace falta leer ningún eje.
                    ctx.save();
                    ctx.font = Chart.helpers.fontString(12, 'bold', familia);
                    ctx.fillStyle = tono === 'oro' || (op.fuerte && op.fuerte(j)) ? op.colores.texto : op.colores.textoTenue;
                    ctx.textAlign = horiz ? 'left' : 'center';
                    ctx.textBaseline = horiz ? 'middle' : 'bottom';
                    ctx.fillText(op.formato ? op.formato(valor) : num(valor), horiz ? v.x + 6 : v.x, horiz ? v.y : v.y - 4);
                    ctx.restore();
                });
            });
        }
    };

    function coloresCuerno(t) {
        return {
            oro:   $.extend({}, t.oro,   { surco: t.surco }),
            plata: $.extend({}, t.plata, { surco: t.plata.sombra }),
            texto: t.text,
            textoTenue: t.muted
        };
    }

    // ---------------------------------------------------------------------
    // ¿En qué etapa se descartan más? — el cuerno dorado es la etapa con más.
    // ---------------------------------------------------------------------
    function construirRechazos(t) {
        var R = D.rechazos, n = R.labels.length;
        if (charts.rech) { charts.rech.destroy(); charts.rech = null; }

        if (!n) {
            $('#lienzoRechazos, #notaRechazos').hide();
            $('#dichoRechazos').text('Aún no hay descartes registrados.');
            return;
        }
        $('#lienzoRechazos, #notaRechazos').show();

        var iMax = indiceMayor(R.data);
        $('#dichoRechazos').html('Se han descartado <strong>' + plural(suma(R.data), 'candidato', 'candidatos') + '</strong>; '
            + 'donde más, en «' + escHtml(R.labels[iMax]) + '»: <strong>' + num(R.data[iMax]) + '</strong>.');

        charts.rech = new Chart(document.getElementById('chartRechazosUni').getContext('2d'), {
            type: 'bar',
            data: {
                labels: R.labels.map(function (l) { return renglones(l, 12, 3); }),
                datasets: [{
                    label: 'Descartados', data: R.data,
                    backgroundColor: TRANSPARENTE, hoverBackgroundColor: TRANSPARENTE, borderWidth: 0,
                    categoryPercentage: 0.8, barPercentage: 0.62, maxBarThickness: 44
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                legend: { display: false },
                layout: { padding: { top: 22 } },
                tooltips: tooltips(t, {
                    title: function (it) { return R.labels[it[0].index]; },
                    label: function (it) { return 'Descartados: ' + num(R.data[it.index]); }
                }),
                scales: {
                    xAxes: [ejeCategorias(t)],
                    yAxes: [{ display: false, ticks: { beginAtZero: true } }]
                },
                cuernos: { colores: coloresCuerno(t), tono: function (j) { return j === iMax ? 'oro' : 'plata'; } }
            },
            plugins: [cuernosPlugin]
        });
    }

    // ---------------------------------------------------------------------
    // ¿Cuántos días tarda cada etapa? — cuernos acostados; el dorado es la más lenta.
    // Las fases (los cortes de RRHH: Selección y Contratación) van intercaladas
    // igual que en la gráfica de siempre (lo común está en dashboard.js); aquí su
    // cuerno es la fase completa, de punta a punta.
    // ---------------------------------------------------------------------
    function construirTiempos(t) {
        var T = D.tiempos, n = T.labels.length;
        if (charts.tiempo) { charts.tiempo.destroy(); charts.tiempo = null; }

        if (!n) {
            $('#lienzoTiempos, #notaTiempos').hide();
            $('#dichoTiempos').text('Aún no hay suficientes movimientos para medir tiempos.');
            return;
        }

        var filas = sivacFilasTiempos(T), fases = T.fases || [];
        var lenta = filas.filter(function (f) { return !f.fase; })[indiceMayor(T.data)];

        // Alto según el número de renglones, para que cada cuerno tenga el suyo.
        $('#lienzoTiempos, #notaTiempos').show();
        $('#notaTiemposCortes').toggle(fases.length > 0);
        $('#lienzoTiempos').css('height', Math.max(180, filas.length * 38 + 16) + 'px');

        var dicho = 'La etapa más lenta es «' + escHtml(lenta.label) + '»: tarda <strong>'
            + dias(lenta.data) + '</strong> en promedio.';
        if (fases.length) {
            dicho += ' De punta a punta, ' + enLista(fases.map(function (f, k) {
                return 'la ' + escHtml(f.label.toLowerCase()) + (k ? ', ' : ' toma ') + '<strong>' + dias(f.data) + '</strong>';
            })) + '.';
        }
        $('#dichoTiempos').html(dicho);

        var eje = ejeCategorias(t);
        eje.ticks.callback = sivacEtiquetaTiempo(filas);

        charts.tiempo = new Chart(document.getElementById('chartTiemposUni').getContext('2d'), {
            type: 'horizontalBar',
            data: {
                labels: filas.map(function (f) { return renglones(f.label, 18, 2); }),
                datasets: [{
                    label: 'Días promedio', data: filas.map(function (f) { return f.data; }),
                    backgroundColor: TRANSPARENTE, hoverBackgroundColor: TRANSPARENTE, borderWidth: 0,
                    categoryPercentage: 0.8, barPercentage: 0.7, maxBarThickness: 26
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                legend: { display: false },
                layout: { padding: { right: 72 } },
                tooltips: tooltips(t, {
                    title: function (it) { return filas[it[0].index].label; },
                    label: function (it) { return sivacTooltipTiempo(filas[it.index], dias); }
                }),
                scales: {
                    xAxes: [{ display: false, ticks: { beginAtZero: true } }],
                    yAxes: [eje]
                },
                cuernos: {
                    colores: coloresCuerno(t),
                    tono: function (j) { return filas[j] === lenta ? 'oro' : 'plata'; },
                    fuerte: function (j) { return !!filas[j].fase; },
                    formato: diasCorto
                },
                fases: { filas: filas, colores: sivacColoresFases(t.grid, t.muted, t.text) }
            },
            plugins: [sivacFasesPlugin, cuernosPlugin]
        });
    }

    function construir() {
        var t = tema();
        construirCarriles();
        construirRechazos(t);
        construirTiempos(t);
    }

    // dashboard.js avisa cuál vista se abrió; si ya venía abierta (recarga del
    // filtro) se construye de una vez.
    $(document).on('sivac:graficas', function (e, vista) { if (vista === 'unicornio') construir(); });
    if (abierta()) construir();
    // Re-render al cambiar el tema para que los colores sigan al modo.
    $(document).on('sivac:themechange', function () { if (abierta()) construir(); });
});
