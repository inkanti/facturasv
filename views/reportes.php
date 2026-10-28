<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
$user = requireAuth();

$pageTitle = 'Reportes';
$bodyClass = 'app-page';
$activePage = 'reportes';
$pageScripts = ['assets/js/reportes.js'];
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="app-content">
    <header class="app-topbar">
        <div class="topbar-left">
            <button class="btn sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Abrir menú" aria-controls="app-sidebar" aria-expanded="false">☰</button>
            <div>
                <span class="topbar-label">Empresa</span>
                <strong><?= e($user['nombre_comercial']) ?></strong>
            </div>
        </div>
        <div class="topbar-user">
            <div class="user-summary">
                <strong><?= e($user['nombre']) ?></strong>
                <span><?= e($user['rol']) ?></span>
            </div>
            <form action="<?= e(url('api/auth.php?action=logout')) ?>" method="post" id="logout-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="app-main" id="reports-app" data-api="<?= e(url('api/reportes.php')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 7 · Operación y reportes</p>
                <h1>Reportes</h1>
                <p>Ventas, IVA y documentos por rango de fechas. Solo cuentan los documentos procesados.</p>
            </div>
            <a class="btn btn-outline-primary" href="<?= e(url('views/dashboard.php')) ?>">Volver al dashboard</a>
        </div>

        <form class="report-filters" id="report-filters">
            <div>
                <label class="form-label" for="report-from">Desde</label>
                <input class="form-control" type="date" id="report-from" name="fecha_desde">
            </div>
            <div>
                <label class="form-label" for="report-to">Hasta</label>
                <input class="form-control" type="date" id="report-to" name="fecha_hasta">
            </div>
            <button class="btn btn-primary" type="submit">Aplicar filtros</button>
            <button class="btn btn-outline-secondary" type="button" id="report-current-month">Mes actual</button>
            <span class="text-muted small ms-auto" id="report-range-label">—</span>
        </form>

        <div class="alert alert-danger d-none" id="report-alert" role="alert"></div>

        <div class="report-kpis">
            <article class="kpi-card kpi-accent">
                <span class="kpi-label">Total vendido</span>
                <strong class="kpi-value" id="report-total">—</strong>
                <span class="kpi-hint" id="report-total-hint">Documentos procesados del periodo</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Documentos procesados</span>
                <strong class="kpi-value" id="report-documents">—</strong>
                <span class="kpi-hint" id="report-documents-hint">Sin datos</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">IVA débito fiscal</span>
                <strong class="kpi-value" id="report-iva">—</strong>
                <span class="kpi-hint">IVA del periodo seleccionado</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Invalidados en el periodo</span>
                <strong class="kpi-value" id="report-invalidated">—</strong>
                <span class="kpi-hint" id="report-invalidated-hint">No se suman a las ventas</span>
            </article>
        </div>

        <section class="card report-card mb-4">
            <div class="card-body">
                <div class="report-card-head">
                    <h2>Resumen del periodo</h2>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-report="resumen">Exportar CSV</button>
                </div>
                <dl class="dashboard-company" id="report-summary">
                    <div><dt>Ventas gravadas</dt><dd id="summary-gravada">—</dd></div>
                    <div><dt>Ventas exentas</dt><dd id="summary-exenta">—</dd></div>
                    <div><dt>Ventas no sujetas</dt><dd id="summary-no-suj">—</dd></div>
                    <div><dt>Descuentos</dt><dd id="summary-descuentos">—</dd></div>
                    <div><dt>IVA débito fiscal</dt><dd id="summary-iva">—</dd></div>
                    <div><dt>IVA retenido (1 %)</dt><dd id="summary-iva-retenido">—</dd></div>
                    <div><dt>Total vendido</dt><dd id="summary-total">—</dd></div>
                    <div><dt>Documentos pendientes</dt><dd id="summary-pendientes">—</dd></div>
                </dl>
            </div>
        </section>

        <div class="report-tables">
            <section class="card report-card">
                <div class="card-body">
                    <div class="report-card-head">
                        <h2>Ventas por cliente</h2>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-report="clientes">Exportar CSV</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle mb-0">
                            <thead><tr><th>Cliente</th><th>Documento</th><th class="text-end">Docs.</th><th class="text-end">Total</th></tr></thead>
                            <tbody id="report-clients"><tr><td colspan="4" class="report-empty">Cargando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card report-card">
                <div class="card-body">
                    <div class="report-card-head">
                        <h2>Ventas por producto y servicio</h2>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-report="productos">Exportar CSV</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle mb-0">
                            <thead><tr><th>Código</th><th>Descripción</th><th class="text-end">Cantidad</th><th class="text-end">Total</th></tr></thead>
                            <tbody id="report-products"><tr><td colspan="4" class="report-empty">Cargando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card report-card">
                <div class="card-body">
                    <div class="report-card-head">
                        <h2>Resumen mensual de IVA</h2>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-report="iva">Exportar CSV</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle mb-0">
                            <thead><tr><th>Periodo</th><th class="text-end">Docs.</th><th class="text-end">Gravada</th><th class="text-end">IVA</th><th class="text-end">Total</th></tr></thead>
                            <tbody id="report-iva-table"><tr><td colspan="5" class="report-empty">Cargando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section class="card report-card">
                <div class="card-body">
                    <div class="report-card-head">
                        <h2>Ventas por día</h2>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-report="diario">Exportar CSV</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table report-table align-middle mb-0">
                            <thead><tr><th>Fecha</th><th class="text-end">Docs.</th><th class="text-end">IVA</th><th class="text-end">Total</th></tr></thead>
                            <tbody id="report-days"><tr><td colspan="4" class="report-empty">Cargando…</td></tr></tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="app-toast-message"></div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
