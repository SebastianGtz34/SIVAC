<?php
/**
 * pie.php — Cierre común de las páginas internas: cierra los wrappers de
 * encabezado.php y carga los scripts (todos LOCALES). Incluye el manejo del
 * tema claro/oscuro y la campana de notificaciones.
 */
?>
            </div><!-- /.container-fluid -->
        </div><!-- /#content -->

        <?php if (empty($embed)): ?>
        <footer class="sticky-footer bg-white">
            <div class="container my-auto"><div class="copyright text-center my-auto">
                <span>SIVAC — MESS · <?= date('Y') ?></span>
            </div></div>
        </footer>
        <?php endif; ?>
    </div><!-- /#content-wrapper -->
</div><!-- /#wrapper -->

<a class="scroll-to-top rounded" href="#page-top"><i class="fas fa-angle-up"></i></a>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/jquery-easing/jquery.easing.min.js"></script>
<script src="vendor/sb-admin-2/js/sb-admin-2.min.js"></script>
<script src="vendor/datatables/jquery.dataTables.min.js"></script>
<script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
<script src="vendor/chart.js/Chart.min.js"></script>
<script src="vendor/sweetalert2/sweetalert2.all.min.js"></script>
<script src="js/funciones.js"></script>

<script>
/* ===== Tema claro/oscuro ===== */
(function () {
    var guardado = null;
    try { guardado = localStorage.getItem('sivac-theme'); } catch (e) {}
    if (guardado === 'dark') $('body').addClass('theme-dark');
    function pintaIcono() {
        var dark = $('body').hasClass('theme-dark');
        $('#btnTema i').attr('class', dark ? 'fas fa-sun fa-fw' : 'fas fa-moon fa-fw');
    }
    pintaIcono();
    $('#btnTema').on('click', function (e) {
        e.preventDefault();
        $('body').toggleClass('theme-dark');
        try { localStorage.setItem('sivac-theme', $('body').hasClass('theme-dark') ? 'dark' : 'light'); } catch (e) {}
        pintaIcono();
        $(document).trigger('sivac:themechange');
    });
})();

/* ===== Campana de notificaciones ===== */
(function () {
    if (!document.getElementById('campanaBadge')) return;
    function cargar() {
        ajaxPost('acciones_notificaciones.php', { accion: 'listar' }, function (err, res) {
            if (err || !res || !res.success) return;
            var n = res.no_leidas || 0;
            var $b = $('#campanaBadge');
            if (n > 0) { $b.text(n > 99 ? '99+' : n).show(); } else { $b.hide(); }

            var items = res.data || [];
            var $lista = $('#campanaLista');
            $lista.find('.notif-item').remove();
            if (items.length === 0) { $('#campanaVacia').show(); return; }
            $('#campanaVacia').hide();
            items.forEach(function (it) {
                var leidaCls = parseInt(it.leida) ? '' : ' font-weight-bold';
                var html = '<a class="dropdown-item d-flex align-items-center notif-item' + leidaCls + '" href="'
                    + (it.url ? escHtml(it.url) : '#') + '" data-id="' + it.id + '">'
                    + '<div class="mr-3"><div class="icon-circle" style="background:var(--accent-soft)"><i class="fas fa-info text-primary"></i></div></div>'
                    + '<div><div class="small text-gray-500">' + formatearFecha(it.fecha_creacion) + '</div>'
                    + escHtml(it.titulo) + '</div></a>';
                $('#campanaVacia').before(html);
            });
        });
    }
    $(document).on('click', '.notif-item', function () {
        var id = $(this).data('id');
        if (id) ajaxPost('acciones_notificaciones.php', { accion: 'marcar_leida', id: id }, function () {});
    });
    cargar();
    setInterval(cargar, 60000);
})();
</script>
</body>
</html>
