<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/contabilidad.php';

$user = requireAuth();
requireRole($user, 'ADMIN', 'CONTADOR');
$tenantId = (int) $user['tenant_id'];
contabilidadEnsureTenant($tenantId);
$canManage = $user['rol'] === 'ADMIN';
$mapping = contabilidadMapping();
$entryTypes = contabilidadEntryTypes();

$pageTitle = 'Contabilidad';
$bodyClass = 'app-page';
$activePage = 'contabilidad';
$pageScripts = ['assets/js/contabilidad.js'];
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

    <main class="app-main" id="accounting-app" data-api="<?= e(url('api/contabilidad.php')) ?>" data-can-manage="<?= $canManage ? '1' : '0' ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 13 · Contabilidad</p>
                <h1>Contabilidad</h1>
                <p>Catálogo de cuentas, asientos automáticos de los DTE, libros y estados financieros.</p>
            </div>
            <span class="badge text-bg-primary align-self-start"><?= $canManage ? 'Administrador' : 'Contador' ?></span>
        </div>

        <div class="alert alert-danger d-none" id="accounting-alert" role="alert"></div>

        <div class="accounting-pending d-none" id="accounting-pending" role="status">
            <div>
                <strong>Documentos pendientes de contabilizar</strong>
                <span id="accounting-pending-text">—</span>
            </div>
            <button class="btn btn-sm btn-primary" type="button" id="accounting-sync">Generar asientos</button>
        </div>

        <ul class="nav nav-tabs config-tabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-resumen-link" data-bs-toggle="tab" data-bs-target="#tab-resumen" type="button" role="tab" aria-controls="tab-resumen" aria-selected="true">Resumen</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-asientos-link" data-bs-toggle="tab" data-bs-target="#tab-asientos" type="button" role="tab" aria-controls="tab-asientos" aria-selected="false">Asientos</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-libros-link" data-bs-toggle="tab" data-bs-target="#tab-libros" type="button" role="tab" aria-controls="tab-libros" aria-selected="false">Libros</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-estados-link" data-bs-toggle="tab" data-bs-target="#tab-estados" type="button" role="tab" aria-controls="tab-estados" aria-selected="false">Estados financieros</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-catalogo-link" data-bs-toggle="tab" data-bs-target="#tab-catalogo" type="button" role="tab" aria-controls="tab-catalogo" aria-selected="false">Catálogo</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-configuracion-link" data-bs-toggle="tab" data-bs-target="#tab-configuracion" type="button" role="tab" aria-controls="tab-configuracion" aria-selected="false">Configuración</button></li>
        </ul>

        <div class="tab-content config-tab-content">
            <div class="tab-pane fade show active" id="tab-resumen" role="tabpanel" aria-labelledby="tab-resumen-link">
                <div class="report-kpis accounting-kpis">
                    <article class="kpi-card kpi-accent">
                        <span class="kpi-label">Asientos del mes</span>
                        <strong class="kpi-value" id="acc-kpi-entries">—</strong>
                        <span class="kpi-hint" id="acc-kpi-period">—</span>
                    </article>
                    <article class="kpi-card">
                        <span class="kpi-label">Total del debe</span>
                        <strong class="kpi-value" id="acc-kpi-debit">—</strong>
                        <span class="kpi-hint">Asientos contabilizados del periodo</span>
                    </article>
                    <article class="kpi-card">
                        <span class="kpi-label">Total del haber</span>
                        <strong class="kpi-value" id="acc-kpi-credit">—</strong>
                        <span class="kpi-hint">Debe y haber deben coincidir</span>
                    </article>
                    <article class="kpi-card">
                        <span class="kpi-label">Resultado del mes</span>
                        <strong class="kpi-value" id="acc-kpi-result">—</strong>
                        <span class="kpi-hint">Ingresos menos costos y gastos</span>
                    </article>
                </div>

                <div class="accounting-grid">
                    <section class="card report-card">
                        <div class="card-body">
                            <div class="report-card-head">
                                <h2>Últimos asientos</h2>
                                <a class="btn btn-sm btn-outline-secondary" href="#tab-asientos" data-bs-toggle="tab" data-bs-target="#tab-asientos">Ver todos</a>
                            </div>
                            <div class="table-responsive">
                                <table class="table report-table accounting-table align-middle mb-0">
                                    <thead><tr><th>Asiento</th><th>Fecha</th><th>Concepto</th><th class="text-end">Debe</th><th class="text-end">Haber</th></tr></thead>
                                    <tbody id="acc-recent-body"><tr><td colspan="5" class="report-empty">Cargando…</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </section>

                    <section class="card report-card">
                        <div class="card-body">
                            <h2 class="accounting-card-title">Estado del módulo</h2>
                            <dl class="dashboard-company accounting-status-list">
                                <div><dt>Cuentas activas</dt><dd id="acc-accounts-active">—</dd></div>
                                <div><dt>Total de cuentas</dt><dd id="acc-accounts-total">—</dd></div>
                                <div><dt>Documentos pendientes</dt><dd id="acc-pending-count">—</dd></div>
                                <div><dt>Periodo</dt><dd id="acc-period-label">—</dd></div>
                            </dl>
                            <p class="accounting-note">
                                Los asientos de venta se generan al procesar un DTE y se revierten al invalidarlo.
                                Si hay documentos anteriores a la instalación del módulo, use “Generar asientos”.
                            </p>
                            <?php if ($canManage): ?>
                            <a class="btn btn-outline-primary btn-sm" href="#tab-configuracion" data-bs-toggle="tab" data-bs-target="#tab-configuracion">Configurar cuentas automáticas</a>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-asientos" role="tabpanel" aria-labelledby="tab-asientos-link">
                <form class="report-filters accounting-filters" id="entry-filters">
                    <div>
                        <label class="form-label" for="entries-from">Desde</label>
                        <input class="form-control" type="date" id="entries-from" name="fecha_desde">
                    </div>
                    <div>
                        <label class="form-label" for="entries-to">Hasta</label>
                        <input class="form-control" type="date" id="entries-to" name="fecha_hasta">
                    </div>
                    <div>
                        <label class="form-label" for="entries-type">Tipo</label>
                        <select class="form-select" id="entries-type" name="tipo">
                            <option value="">Todos</option>
                            <?php foreach ($entryTypes as $value => $label): ?>
                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="entries-status">Estado</label>
                        <select class="form-select" id="entries-status" name="estado">
                            <option value="">Todos</option>
                            <option value="CONTABILIZADO">Contabilizado</option>
                            <option value="ANULADO">Anulado</option>
                        </select>
                    </div>
                    <div class="accounting-search">
                        <label class="form-label" for="entries-query">Buscar</label>
                        <input class="form-control" type="search" id="entries-query" name="q" maxlength="80" placeholder="Concepto, número o DTE">
                    </div>
                    <button class="btn btn-primary" type="submit">Filtrar</button>
                    <button class="btn btn-outline-primary" type="button" id="entry-new">Nuevo asiento</button>
                </form>

                <section class="card report-card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table report-table accounting-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Asiento</th><th>Fecha</th><th>Concepto</th><th>Tipo</th><th>Documento</th>
                                        <th class="text-end">Debe</th><th class="text-end">Haber</th><th>Estado</th><th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="entries-body"><tr><td colspan="9" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="accounting-pagination">
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="entries-prev">Anterior</button>
                            <span id="entries-page-label">—</span>
                            <button class="btn btn-sm btn-outline-secondary" type="button" id="entries-next">Siguiente</button>
                            <button class="btn btn-sm btn-outline-secondary ms-auto" type="button" id="entries-export">Exportar CSV</button>
                        </div>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-libros" role="tabpanel" aria-labelledby="tab-libros-link">
                <form class="report-filters accounting-filters" id="books-filters">
                    <div>
                        <label class="form-label" for="books-from">Desde</label>
                        <input class="form-control" type="date" id="books-from" name="fecha_desde">
                    </div>
                    <div>
                        <label class="form-label" for="books-to">Hasta</label>
                        <input class="form-control" type="date" id="books-to" name="fecha_hasta">
                    </div>
                    <div class="accounting-search">
                        <label class="form-label" for="books-account">Cuenta para el libro mayor</label>
                        <select class="form-select" id="books-account" name="cuenta_id"></select>
                    </div>
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                    <span class="text-muted small ms-auto" id="books-range-label">—</span>
                </form>

                <section class="card report-card mb-4">
                    <div class="card-body">
                        <div class="report-card-head">
                            <h2>Libro diario</h2>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-export="diario">Exportar CSV</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table report-table accounting-table align-middle mb-0">
                                <thead><tr><th>Fecha</th><th>Asiento</th><th>Concepto</th><th>Cuenta</th><th class="text-end">Debe</th><th class="text-end">Haber</th></tr></thead>
                                <tbody id="diary-body"><tr><td colspan="6" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="accounting-totals">
                            <span>Total debe: <strong id="diary-total-debit">$0.00</strong></span>
                            <span>Total haber: <strong id="diary-total-credit">$0.00</strong></span>
                        </div>
                        <p class="accounting-note d-none" id="diary-truncated">Se muestran los primeros 1000 asientos del periodo.</p>
                    </div>
                </section>

                <section class="card report-card mb-4">
                    <div class="card-body">
                        <div class="report-card-head">
                            <h2>Libro mayor <span class="accounting-account-label" id="ledger-account-label"></span></h2>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-export="mayor">Exportar CSV</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table report-table accounting-table align-middle mb-0">
                                <thead><tr><th>Fecha</th><th>Asiento</th><th>Concepto</th><th>Detalle</th><th class="text-end">Debe</th><th class="text-end">Haber</th><th class="text-end">Saldo</th></tr></thead>
                                <tbody id="ledger-body"><tr><td colspan="7" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="accounting-totals">
                            <span>Saldo inicial: <strong id="ledger-initial">$0.00</strong></span>
                            <span>Debe: <strong id="ledger-debit">$0.00</strong></span>
                            <span>Haber: <strong id="ledger-credit">$0.00</strong></span>
                            <span>Saldo final: <strong id="ledger-final">$0.00</strong></span>
                        </div>
                    </div>
                </section>

                <section class="card report-card">
                    <div class="card-body">
                        <div class="report-card-head">
                            <h2>Balanza de comprobación</h2>
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-export="balanza">Exportar CSV</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table report-table accounting-table align-middle mb-0">
                                <thead><tr><th>Código</th><th>Cuenta</th><th class="text-end">Saldo inicial</th><th class="text-end">Debe</th><th class="text-end">Haber</th><th class="text-end">Saldo final</th></tr></thead>
                                <tbody id="trial-body"><tr><td colspan="6" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="accounting-totals">
                            <span>Total debe: <strong id="trial-total-debit">$0.00</strong></span>
                            <span>Total haber: <strong id="trial-total-credit">$0.00</strong></span>
                            <span>Deudor: <strong id="trial-total-debtor">$0.00</strong></span>
                            <span>Acreedor: <strong id="trial-total-creditor">$0.00</strong></span>
                        </div>
                        <p class="accounting-note">Los asientos anulados no se incluyen en los libros ni en los estados financieros.</p>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-estados" role="tabpanel" aria-labelledby="tab-estados-link">
                <form class="report-filters accounting-filters" id="statements-filters">
                    <div>
                        <label class="form-label" for="statements-from">Desde</label>
                        <input class="form-control" type="date" id="statements-from" name="fecha_desde">
                    </div>
                    <div>
                        <label class="form-label" for="statements-to">Hasta</label>
                        <input class="form-control" type="date" id="statements-to" name="fecha_hasta">
                    </div>
                    <button class="btn btn-primary" type="submit">Aplicar</button>
                    <span class="text-muted small ms-auto" id="statements-range-label">—</span>
                </form>

                <div class="accounting-statements">
                    <section class="card report-card">
                        <div class="card-body">
                            <div class="report-card-head">
                                <h2>Estado de resultados</h2>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-export="resultados">Exportar CSV</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table report-table accounting-table align-middle mb-0">
                                    <thead><tr><th>Grupo</th><th>Código</th><th>Cuenta</th><th class="text-end">Saldo</th></tr></thead>
                                    <tbody id="income-body"><tr><td colspan="4" class="report-empty">Cargando…</td></tr></tbody>
                                </table>
                            </div>
                            <dl class="accounting-result-summary">
                                <div><dt>Total ingresos</dt><dd id="income-total-income">$0.00</dd></div>
                                <div><dt>Total costos</dt><dd id="income-total-cost">$0.00</dd></div>
                                <div><dt>Utilidad bruta</dt><dd id="income-gross">$0.00</dd></div>
                                <div><dt>Total gastos</dt><dd id="income-total-expense">$0.00</dd></div>
                                <div class="accounting-result-net"><dt>Utilidad neta</dt><dd id="income-net">$0.00</dd></div>
                            </dl>
                        </div>
                    </section>

                    <section class="card report-card">
                        <div class="card-body">
                            <div class="report-card-head">
                                <h2>Balance general</h2>
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-export="balance">Exportar CSV</button>
                            </div>
                            <div class="accounting-balance-filter">
                                <label class="form-label" for="balance-asof">Al</label>
                                <input class="form-control" type="date" id="balance-asof">
                                <button class="btn btn-sm btn-outline-primary" type="button" id="balance-apply">Actualizar</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table report-table accounting-table align-middle mb-0">
                                    <thead><tr><th>Grupo</th><th>Código</th><th>Cuenta</th><th class="text-end">Saldo</th></tr></thead>
                                    <tbody id="balance-body"><tr><td colspan="4" class="report-empty">Cargando…</td></tr></tbody>
                                </table>
                            </div>
                            <dl class="accounting-result-summary">
                                <div><dt>Total activo</dt><dd id="balance-total-assets">$0.00</dd></div>
                                <div><dt>Total pasivo</dt><dd id="balance-total-liabilities">$0.00</dd></div>
                                <div><dt>Patrimonio</dt><dd id="balance-total-equity">$0.00</dd></div>
                                <div><dt>Resultado del ejercicio</dt><dd id="balance-result">$0.00</dd></div>
                                <div><dt>Pasivo + patrimonio</dt><dd id="balance-total-liabilities-equity">$0.00</dd></div>
                                <div class="accounting-result-net"><dt>Diferencia</dt><dd id="balance-difference">$0.00</dd></div>
                            </dl>
                        </div>
                    </section>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-catalogo" role="tabpanel" aria-labelledby="tab-catalogo-link">
                <div class="config-toolbar">
                    <div class="accounting-search">
                        <label class="form-label" for="account-search">Buscar cuenta</label>
                        <input class="form-control" type="search" id="account-search" maxlength="80" placeholder="Código o nombre">
                    </div>
                    <?php if ($canManage): ?>
                    <button class="btn btn-primary" type="button" id="account-new">Nueva cuenta</button>
                    <?php endif; ?>
                </div>
                <section class="card report-card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table report-table accounting-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Código</th><th>Nombre</th><th>Tipo</th><th>Naturaleza</th>
                                        <th>Movimiento</th><th>Estado</th><th class="text-end">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody id="accounts-body"><tr><td colspan="7" class="report-empty">Cargando…</td></tr></tbody>
                            </table>
                        </div>
                        <p class="accounting-note">El código de una cuenta no se puede modificar después de crearla. Las cuentas con movimientos no se pueden desactivar.</p>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-configuracion" role="tabpanel" aria-labelledby="tab-configuracion-link">
                <section class="card config-card">
                    <div class="card-body">
                        <h2>Cuentas automáticas</h2>
                        <p class="text-muted">Estas cuentas se usan al contabilizar los DTE. Si cambia una cuenta, los asientos anteriores conservan la cuenta con la que se registraron.</p>
                        <form id="accounting-config-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <div class="row g-3 accounting-config-grid">
                                <?php foreach ($mapping as $field => $definition): ?>
                                <div class="col-md-6">
                                    <label class="form-label required-label" for="config-<?= e($field) ?>"><?= e($definition['label']) ?></label>
                                    <select class="form-select" id="config-<?= e($field) ?>" name="<?= e($field) ?>" <?= $canManage ? '' : 'disabled' ?> required></select>
                                    <div class="invalid-feedback" data-error-for="<?= e($field) ?>"></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="config-form-actions">
                                <?php if ($canManage): ?>
                                <button class="btn btn-primary" type="submit">
                                    <span class="button-label">Guardar configuración</span>
                                    <span class="button-loading d-none">Guardando…</span>
                                </button>
                                <?php else: ?>
                                <span class="text-muted small">Solo un administrador puede modificar esta configuración.</span>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="entry-modal" tabindex="-1" aria-labelledby="entry-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form id="entry-form">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="entry-modal-title">Nuevo asiento contable</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label required-label" for="entry-date">Fecha</label>
                            <input class="form-control" type="date" id="entry-date" name="fecha" required>
                            <div class="invalid-feedback" data-error-for="fecha"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label required-label" for="entry-concept">Concepto</label>
                            <input class="form-control" type="text" id="entry-concept" name="concepto" maxlength="300" required>
                            <div class="invalid-feedback" data-error-for="concepto"></div>
                        </div>
                    </div>
                    <div class="alert alert-danger d-none" id="entry-alert" role="alert"></div>
                    <div class="table-responsive">
                        <table class="table accounting-lines-table align-middle mb-2">
                            <thead><tr><th>Cuenta</th><th>Detalle</th><th class="text-end">Debe</th><th class="text-end">Haber</th><th></th></tr></thead>
                            <tbody id="entry-lines"></tbody>
                        </table>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" type="button" id="entry-add-line">Agregar línea</button>
                    <div class="invalid-feedback d-block" data-error-for="lineas"></div>
                    <div class="accounting-totals accounting-entry-totals">
                        <span>Total debe: <strong id="entry-total-debe">$0.00</strong></span>
                        <span>Total haber: <strong id="entry-total-haber">$0.00</strong></span>
                        <span>Diferencia: <strong id="entry-difference">$0.00</strong></span>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="button-label">Guardar asiento</span>
                        <span class="button-loading d-none">Guardando…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="entry-detail-modal" tabindex="-1" aria-labelledby="entry-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="entry-detail-title">Detalle del asiento</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="entry-detail-body"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="void-modal" tabindex="-1" aria-labelledby="void-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="void-form">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="void-modal-title">Anular asiento</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="id" id="void-id">
                    <p id="void-summary" class="mb-3"></p>
                    <label class="form-label required-label" for="void-reason">Motivo de la anulación</label>
                    <textarea class="form-control" id="void-reason" name="motivo" rows="3" maxlength="300" required></textarea>
                    <div class="invalid-feedback" data-error-for="motivo"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger">Anular asiento</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="account-modal" tabindex="-1" aria-labelledby="account-modal-title" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="account-form">
                <div class="modal-header">
                    <h2 class="modal-title h5" id="account-modal-title">Nueva cuenta</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="id" id="account-id">
                    <div class="mb-3">
                        <label class="form-label required-label" for="account-code">Código</label>
                        <input class="form-control" type="text" id="account-code" name="codigo" maxlength="12" pattern="[0-9]{1,12}" required>
                        <div class="form-text">Solo dígitos, hasta 12. No se puede cambiar después de crear la cuenta.</div>
                        <div class="invalid-feedback" data-error-for="codigo"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-label" for="account-name">Nombre</label>
                        <input class="form-control" type="text" id="account-name" name="nombre" maxlength="150" required>
                        <div class="invalid-feedback" data-error-for="nombre"></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label required-label" for="account-type">Tipo</label>
                            <select class="form-select" id="account-type" name="tipo" required></select>
                            <div class="invalid-feedback" data-error-for="tipo"></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label required-label" for="account-nature">Naturaleza</label>
                            <select class="form-select" id="account-nature" name="naturaleza" required></select>
                            <div class="invalid-feedback" data-error-for="naturaleza"></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="account-movement">Permite movimientos</label>
                            <select class="form-select" id="account-movement" name="permite_movimiento">
                                <option value="1">Sí</option>
                                <option value="0">No</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="permite_movimiento"></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="account-active">Estado</label>
                            <select class="form-select" id="account-active" name="activa">
                                <option value="1">Activa</option>
                                <option value="0">Inactiva</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="activa"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="button-label">Guardar cuenta</span>
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
