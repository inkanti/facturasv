<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/inventario.php';

$user = requireAuth();
requireRole($user, 'ADMIN', 'VENDEDOR', 'CONTADOR');
$tenantId = (int) $user['tenant_id'];
inventarioEnsureTenant($tenantId);
$canWrite = in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true);
$types = inventarioTipos();

$pageTitle = 'Inventario';
$bodyClass = 'app-page';
$activePage = 'inventario';
$pageScripts = ['assets/js/inventario.js'];
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

    <main class="app-main" id="inventory-app" data-api="<?= e(url('api/inventario.php')) ?>" data-can-write="<?= $canWrite ? '1' : '0' ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 14 · Inventario</p>
                <h1>Inventario</h1>
                <p>Existencias, movimientos y kardex por producto. Las ventas descuentan stock al procesarse.</p>
            </div>
            <span class="badge text-bg-primary align-self-start"><?= $canWrite ? 'Operación' : 'Solo lectura' ?></span>
        </div>

        <div class="alert alert-danger d-none" id="inventory-alert" role="alert"></div>

        <div class="inventory-negative d-none" id="inventory-negative" role="status">
            <div>
                <strong>Hay productos con stock negativo</strong>
                <span id="inventory-negative-text">—</span>
            </div>
            <a class="btn btn-sm btn-outline-danger" href="#tab-existencias" data-bs-toggle="tab" data-bs-target="#tab-existencias">Revisar existencias</a>
        </div>

        <div class="inventory-pending d-none" id="inventory-pending" role="status">
            <div>
                <strong>Documentos pendientes de conciliar</strong>
                <span id="inventory-pending-text">—</span>
            </div>
            <?php if ($user['rol'] === 'ADMIN'): ?>
            <button class="btn btn-sm btn-primary" type="button" id="inventory-sync-banner">Reconciliar ventas</button>
            <?php endif; ?>
        </div>

        <ul class="nav nav-tabs config-tabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-resumen-link" data-bs-toggle="tab" data-bs-target="#tab-resumen" type="button" role="tab" aria-controls="tab-resumen" aria-selected="true">Resumen</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-existencias-link" data-bs-toggle="tab" data-bs-target="#tab-existencias" type="button" role="tab" aria-controls="tab-existencias" aria-selected="false">Existencias</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-movimientos-link" data-bs-toggle="tab" data-bs-target="#tab-movimientos" type="button" role="tab" aria-controls="tab-movimientos" aria-selected="false">Movimientos</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-kardex-link" data-bs-toggle="tab" data-bs-target="#tab-kardex" type="button" role="tab" aria-controls="tab-kardex" aria-selected="false">Kardex</button></li>
        </ul>

        <div class="tab-content config-tab-content">
            <div class="tab-pane fade show active" id="tab-resumen" role="tabpanel" aria-labelledby="tab-resumen-link">
                <div class="report-kpis inventory-kpis">
                    <article class="kpi-card kpi-accent">
                        <span class="kpi-label">Productos controlados</span>
                        <strong class="kpi-value" id="inv-kpi-products">—</strong>
                        <span class="kpi-hint" id="inv-kpi-start">—</span>
                    </article>
                    <article class="kpi-card">
                        <span class="kpi-label">Unidades netas</span>
                        <strong class="kpi-value" id="inv-kpi-units">—</strong>
                        <span class="kpi-hint">Suma de existencias de los productos</span>
                    </article>
                    <article class="kpi-card">
                        <span class="kpi-label">Con stock negativo</span>
                        <strong class="kpi-value" id="inv-kpi-negative">—</strong>
                        <span class="kpi-hint">Ventas procesadas sin existencia suficiente</span>
                    </article>
                    <article class="kpi-card">
                        <span class="kpi-label">Movimientos del mes</span>
                        <strong class="kpi-value" id="inv-kpi-movements">—</strong>
                        <span class="kpi-hint" id="inv-kpi-period">—</span>
                    </article>
                </div>

                <div class="accounting-grid">
                    <section class="card report-card">
                        <div class="card-body">
                            <div class="report-card-head">
                                <h2>Últimos movimientos</h2>
                                <a class="btn btn-sm btn-outline-secondary" href="#tab-movimientos" data-bs-toggle="tab" data-bs-target="#tab-movimientos">Ver todos</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table report-table inventory-table align-middle mb-0">
                                    <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th class="text-end">Cantidad</th><th>Motivo</th></tr></thead>
                                    <tbody id="inv-recent-body"><tr><td colspan="5" class="report-empty">Cargando…</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="card report-card">
                        <div class="card-body">
                            <h2 class="accounting-card-title">Estado del inventario</h2>
                            <dl class="dashboard-company accounting-status-list">
                                <div><dt>Control activo desde</dt><dd id="inv-start">—</dd></div>
                                <div><dt>Productos agotados</dt><dd id="inv-empty">—</dd></div>
                                <div><dt>Productos negativos</dt><dd id="inv-negative-count">—</dd></div>
                                <div><dt>Unidades netas</dt><dd id="inv-units">—</dd></div>
                            </dl>
                            <p class="accounting-note">
                                El stock se calcula con la existencia base del producto más sus movimientos.
                                Las ventas de los DTE 01, 03, 06 y 11 descuentan automáticamente; las notas de
                                crédito y los sujetos excluidos no mueven inventario. Si necesita devolver
                                mercadería, registre una entrada manual.
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if ($canWrite): ?>
                                <button class="btn btn-primary btn-sm" type="button" id="inv-new-movement">Nuevo movimiento</button>
                                <?php endif; ?>
                                <?php if ($user['rol'] === 'ADMIN'): ?>
                                <button class="btn btn-outline-secondary btn-sm" type="button" id="inv-sync">Reconciliar ventas</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-existencias" role="tabpanel" aria-labelledby="tab-existencias-link">
                <form class="report-filters accounting-filters" id="stock-filters">
                    <div class="accounting-search">
                        <label class="form-label" for="stock-query">Buscar</label>
                        <input class="form-control" type="search" id="stock-query" name="q" maxlength="80" placeholder="Código o producto">
                    </div>
                    <div>
                        <label class="form-label" for="stock-status">Estado</label>
                        <select class="form-select" id="stock-status" name="estado">
                            <option value="">Todos</option>
                            <option value="DISPONIBLE">Disponible</option>
                            <option value="AGOTADO">Agotado</option>
                            <option value="NEGATIVO">Negativo</option>
                        </select>
                    </div>
                    <button class="btn btn-primary" type="submit">Filtrar</button>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" data-export="existencias">Exportar CSV</button>
                </form>
                <section class="card report-card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table report-table inventory-table align-middle mb-0">
                                <thead><tr><th>Código</th><th>Producto</th><th>Unidad</th><th class="text-end">Existencia</th><th>Estado</th><th>Último movimiento</th></tr></thead>
                                <tbody id="stock-body"><tr><td colspan="6" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <p class="accounting-note">El stock negativo se permite en ventas automáticas para no bloquear la emisión del DTE; regístrelo o ajústelo desde Movimientos.</p>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-movimientos" role="tabpanel" aria-labelledby="tab-movimientos-link">
                <form class="report-filters accounting-filters" id="movement-filters">
                    <div>
                        <label class="form-label" for="movement-from">Desde</label>
                        <input class="form-control" type="date" id="movement-from" name="fecha_desde">
                    </div>
                    <div>
                        <label class="form-label" for="movement-to">Hasta</label>
                        <input class="form-control" type="date" id="movement-to" name="fecha_hasta">
                    </div>
                    <div>
                        <label class="form-label" for="movement-type-filter">Tipo</label>
                        <select class="form-select" id="movement-type-filter" name="tipo">
                            <option value="">Todos</option>
                            <?php foreach ($types as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="accounting-search">
                        <label class="form-label" for="movement-product-filter">Producto</label>
                        <select class="form-select" id="movement-product-filter" name="producto_id"></select>
                    </div>
                    <div class="accounting-search">
                        <label class="form-label" for="movement-query">Buscar</label>
                        <input class="form-control" type="search" id="movement-query" name="q" maxlength="80" placeholder="Motivo o documento">
                    </div>
                    <button class="btn btn-primary" type="submit">Filtrar</button>
                    <?php if ($canWrite): ?>
                    <button class="btn btn-outline-primary" type="button" id="movement-new">Nuevo movimiento</button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-export="movimientos">Exportar CSV</button>
                </form>
                <section class="card report-card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table report-table inventory-table align-middle mb-0">
                                <thead><tr><th>Fecha</th><th>Producto</th><th>Tipo</th><th class="text-end">Cantidad</th><th>Motivo</th><th>Documento</th><th>Usuario</th></tr></thead>
                                <tbody id="movements-body"><tr><td colspan="7" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="accounting-pagination">
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="movements-prev">Anterior</button>
                            <span id="movements-page-label">—</span>
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="movements-next">Siguiente</button>
                            <span class="text-muted small ms-auto" id="movements-totals">—</span>
                        </div>
                        <p class="accounting-note">Los movimientos no se eliminan ni se editan: para corregir uno, registre el movimiento inverso con el motivo correspondiente.</p>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-kardex" role="tabpanel" aria-labelledby="tab-kardex-link">
                <form class="report-filters accounting-filters" id="kardex-filters">
                    <div class="accounting-search">
                        <label class="form-label" for="kardex-product">Producto</label>
                        <select class="form-select" id="kardex-product" name="producto_id"></select>
                    </div>
                    <div>
                        <label class="form-label" for="kardex-from">Desde</label>
                        <input class="form-control" type="date" id="kardex-from" name="fecha_desde">
                    </div>
                    <div>
                        <label class="form-label" for="kardex-to">Hasta</label>
                        <input class="form-control" type="date" id="kardex-to" name="fecha_hasta">
                    </div>
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                    <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" data-export="kardex">Exportar CSV</button>
                </form>
                <section class="card report-card">
                    <div class="card-body">
                        <div class="report-card-head">
                            <h2>Kardex <span class="accounting-account-label" id="kardex-product-label"></span></h2>
                        </div>
                        <div class="table-responsive">
                            <table class="table report-table inventory-table align-middle mb-0">
                                <thead><tr><th>Fecha</th><th>Tipo</th><th class="text-end">Cantidad</th><th>Motivo</th><th>Documento</th><th class="text-end">Saldo</th></tr></thead>
                                <tbody id="kardex-body"><tr><td colspan="6" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="accounting-totals">
                            <span>Saldo inicial: <strong id="kardex-opening">0</strong></span>
                            <span>Entradas: <strong id="kardex-entries">0</strong></span>
                            <span>Salidas: <strong id="kardex-exits">0</strong></span>
                            <span>Saldo final: <strong id="kardex-final">0</strong></span>
                        </div>
                        <p class="accounting-note">El saldo inicial incluye la existencia base del producto y todos sus movimientos anteriores al rango.</p>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="movement-modal" tabindex="-1" aria-labelledby="movement-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="movement-form">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="movement-modal-title">Nuevo movimiento de inventario</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <div class="mb-3">
                        <label class="form-label required-label" for="movement-product">Producto</label>
                        <select class="form-select" id="movement-product" name="producto_id" required></select>
                        <div class="form-text" id="movement-stock-hint">—</div>
                        <div class="invalid-feedback" data-error-for="producto_id"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required-label" for="movement-type">Tipo</label>
                            <select class="form-select" id="movement-type" name="tipo" required>
                                <?php foreach ($types as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label" for="movement-date">Fecha</label>
                            <input class="form-control" type="date" id="movement-date" name="fecha" required>
                            <div class="invalid-feedback" data-error-for="fecha"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label" for="movement-quantity">Cantidad</label>
                            <input class="form-control" type="number" id="movement-quantity" name="cantidad" min="0.0001" step="0.0001" required>
                            <div class="form-text">Hasta 4 decimales.</div>
                            <div class="invalid-feedback" data-error-for="cantidad"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="movement-current">Existencia actual</label>
                            <input class="form-control" type="text" id="movement-current" disabled>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label required-label" for="movement-reason">Motivo</label>
                        <input class="form-control" type="text" id="movement-reason" name="motivo" maxlength="200" required>
                        <div class="invalid-feedback" data-error-for="motivo"></div>
                    </div>
                    <div class="alert alert-danger d-none mt-3" id="movement-alert" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="button-label">Guardar movimiento</span>
                        <span class="button-loading d-none">Guardando…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="app-toast-message"></div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
