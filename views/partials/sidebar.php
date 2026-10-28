<aside class="app-sidebar" id="app-sidebar" aria-label="Navegación principal">
    <a class="sidebar-brand" href="<?= e(url('views/dashboard.php')) ?>" aria-label="FacturaSV, ir al inicio">
        <span class="brand-mark" aria-hidden="true">FS</span>
        <span>FacturaSV</span>
    </a>

    <nav class="sidebar-nav">
        <p class="sidebar-section">Principal</p>
        <a class="sidebar-link <?= ($activePage ?? 'dashboard') === 'dashboard' ? 'active' : '' ?>"
           href="<?= e(url('views/dashboard.php')) ?>" <?= ($activePage ?? 'dashboard') === 'dashboard' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">D</span> Dashboard
        </a>
        <?php if (in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true)): ?>
        <a class="sidebar-link <?= ($activePage ?? '') === 'nueva-factura' ? 'active' : '' ?>"
           href="<?= e(url('views/nueva-factura.php')) ?>" <?= ($activePage ?? '') === 'nueva-factura' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">N</span> Nuevo documento
        </a>
        <?php endif; ?>
        <a class="sidebar-link <?= ($activePage ?? '') === 'documentos' ? 'active' : '' ?>"
           href="<?= e(url('views/lista-documentos.php')) ?>" <?= ($activePage ?? '') === 'documentos' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">D</span> Documentos
        </a>
        <a class="sidebar-link <?= ($activePage ?? '') === 'contingencia' ? 'active' : '' ?>"
           href="<?= e(url('views/contingencia.php')) ?>" <?= ($activePage ?? '') === 'contingencia' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">E</span> Contingencia
        </a>

        <p class="sidebar-section">Administración</p>
        <a class="sidebar-link <?= ($activePage ?? '') === 'clientes' ? 'active' : '' ?>"
           href="<?= e(url('views/clientes.php')) ?>" <?= ($activePage ?? '') === 'clientes' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">C</span> Clientes
        </a>
        <a class="sidebar-link <?= ($activePage ?? '') === 'productos' ? 'active' : '' ?>"
           href="<?= e(url('views/productos.php')) ?>" <?= ($activePage ?? '') === 'productos' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">P</span> Productos
        </a>
        <a class="sidebar-link <?= ($activePage ?? '') === 'inventario' ? 'active' : '' ?>"
           href="<?= e(url('views/inventario.php')) ?>" <?= ($activePage ?? '') === 'inventario' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">I</span> Inventario
        </a>
        <a class="sidebar-link <?= ($activePage ?? '') === 'reportes' ? 'active' : '' ?>"
           href="<?= e(url('views/reportes.php')) ?>" <?= ($activePage ?? '') === 'reportes' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">R</span> Reportes
        </a>
        <?php if (in_array($user['rol'], ['ADMIN', 'CONTADOR'], true)): ?>
        <a class="sidebar-link <?= ($activePage ?? '') === 'contabilidad' ? 'active' : '' ?>"
           href="<?= e(url('views/contabilidad.php')) ?>" <?= ($activePage ?? '') === 'contabilidad' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">C</span> Contabilidad
        </a>
        <?php endif; ?>
        <a class="sidebar-link <?= ($activePage ?? '') === 'catalogos' ? 'active' : '' ?>"
           href="<?= e(url('views/catalogos.php')) ?>" <?= ($activePage ?? '') === 'catalogos' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">M</span> Catálogos
        </a>
        <?php if ($user['rol'] === 'ADMIN'): ?>
        <a class="sidebar-link <?= ($activePage ?? '') === 'configuracion' ? 'active' : '' ?>"
           href="<?= e(url('views/configuracion.php')) ?>" <?= ($activePage ?? '') === 'configuracion' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">C</span> Configuración
        </a>
        <?php else: ?>
        <span class="sidebar-link disabled" title="Solo administradores">
            <span class="nav-symbol" aria-hidden="true">C</span> Configuración
        </span>
        <?php endif; ?>

        <p class="sidebar-section">Cuenta</p>
        <a class="sidebar-link <?= ($activePage ?? '') === 'perfil' ? 'active' : '' ?>"
           href="<?= e(url('views/perfil.php')) ?>" <?= ($activePage ?? '') === 'perfil' ? 'aria-current="page"' : '' ?>>
            <span class="nav-symbol" aria-hidden="true">P</span> Mi perfil
        </a>
    </nav>

    <div class="sidebar-footer">
        <span class="status-dot" aria-hidden="true"></span>
        Módulos 1 al 15 activos
    </div>
    <div class="sidebar-dev">
        <img src="<?= e(url('assets/img/bissweb-mark.jpg')) ?>" alt="BissWeb">
        <span>Desarrollado por <strong>BissWeb</strong></span>
    </div>
</aside>
<button class="sidebar-overlay" id="sidebar-overlay" type="button" aria-label="Cerrar menú"></button>
