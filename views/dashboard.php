<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
$user = requireAuth();
$canWrite = in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true);

$pageTitle = 'Dashboard';
$bodyClass = 'app-page';
$activePage = 'dashboard';
$pageScripts = ['assets/js/dashboard.js'];
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

    <main class="app-main" id="dashboard-app" data-api="<?= e(url('api/reportes.php')) ?>" data-documents-url="<?= e(url('views/lista-documentos.php')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 7 · Operación y reportes</p>
                <h1>Dashboard</h1>
                <p id="dashboard-period">Resumen del mes en curso.</p>
            </div>
            <div class="dashboard-actions">
                <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= e(url('views/nueva-factura.php')) ?>">Nuevo documento</a><?php endif; ?>
                <a class="btn btn-outline-primary" href="<?= e(url('views/reportes.php')) ?>">Ver reportes</a>
            </div>
        </div>

        <div class="alert alert-danger d-none" id="dashboard-alert" role="alert"></div>

        <div class="dashboard-kpis">
            <article class="kpi-card kpi-accent">
                <span class="kpi-label">Ingresos del mes</span>
                <strong class="kpi-value" id="kpi-ingresos">—</strong>
                <span class="kpi-hint" id="kpi-ingresos-hint">Sin datos</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Documentos emitidos</span>
                <strong class="kpi-value" id="kpi-documentos">—</strong>
                <span class="kpi-hint" id="kpi-documentos-hint">Sin datos</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">IVA generado</span>
                <strong class="kpi-value" id="kpi-iva">—</strong>
                <span class="kpi-hint" id="kpi-iva-hint">Débito fiscal del mes</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Documentos pendientes</span>
                <strong class="kpi-value" id="kpi-pendientes">—</strong>
                <span class="kpi-hint">Borradores, emitidos sin procesar y rechazados</span>
            </article>
        </div>

        <div class="dashboard-grid">
            <section class="card dashboard-card">
                <div class="card-body">
                    <h2>Ventas de los últimos 6 meses</h2>
                    <div class="chart-bars" id="dashboard-chart" role="img" aria-label="Ventas por mes"></div>
                    <p class="text-muted mb-0 d-none" id="dashboard-chart-empty">Todavía no hay documentos procesados en este periodo.</p>
                </div>
            </section>
            <section class="card dashboard-card">
                <div class="card-body">
                    <h2>Top 5 clientes del mes</h2>
                    <ol class="rank-list" id="dashboard-top"></ol>
                    <p class="text-muted mb-0 d-none" id="dashboard-top-empty">Aún no hay ventas procesadas este mes.</p>
                </div>
            </section>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <section class="card dashboard-card h-100">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between mb-3">
                            <h2 class="mb-0">Documentos recientes</h2>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('views/lista-documentos.php')) ?>">Ver todos</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table dashboard-recent-table align-middle mb-0">
                                <thead><tr><th>Documento</th><th>Cliente</th><th>Fecha</th><th class="text-end">Total</th><th>Estado</th></tr></thead>
                                <tbody id="dashboard-recent"><tr><td colspan="5" class="text-center text-muted py-4">Cargando documentos…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="card dashboard-card h-100">
                    <div class="card-body">
                        <h2>Empresa y entorno</h2>
                        <dl class="dashboard-company">
                            <div><dt>Razón social</dt><dd><?= e($user['razon_social']) ?></dd></div>
                            <div><dt>NIT</dt><dd><?= e($user['tenant_nit']) ?></dd></div>
                            <div><dt>Registro MH</dt><dd><?= e($user['numero_registro_mh']) ?></dd></div>
                            <div><dt>Actividad</dt><dd><?= e($user['codigo_actividad']) ?></dd></div>
                            <div><dt>Usuario</dt><dd><?= e($user['usuario']) ?> · <?= e($user['rol']) ?></dd></div>
                            <div><dt>Modo de facturación</dt><dd><span class="simulation-badge"><?= e(($user['modo_facturacion'] ?? 'SIMULACION') === 'INTERNO' ? 'INTERNO · no fiscal' : 'SIMULACIÓN') ?></span></dd></div>
                            <div><dt>Modalidad MH</dt><dd><span class="simulation-badge"><?= e($user['mh_mode']) ?></span></dd></div>
                            <div><dt>Zona horaria</dt><dd><?= e($user['timezone']) ?></dd></div>
                            <div><dt>Moneda</dt><dd><?= e($user['moneda']) ?></dd></div>
                        </dl>
                        <ol class="module-progress mt-3" aria-label="Progreso de módulos">
                            <li class="complete"><span>1</span> Base y autenticación</li>
                            <li class="complete"><span>2</span> Catálogos MH</li>
                            <li class="complete"><span>3</span> Clientes</li>
                            <li class="complete"><span>4</span> Productos y servicios</li>
                            <li class="complete"><span>5</span> Emisión DTE</li>
                            <li class="complete"><span>6</span> Representación PDF</li>
                            <li class="complete"><span>7</span> Dashboard y reportes</li>
                            <li class="complete"><span>8</span> Configuración</li>
                            <li class="complete"><span>9</span> Contingencia</li>
                            <li class="complete"><span>10</span> Tipos DTE</li>
                            <li class="complete"><span>11</span> Superadministración</li>
                            <li class="complete"><span>12</span> Mi perfil</li>
                            <li class="complete"><span>13</span> Contabilidad</li>
                            <li class="complete"><span>14</span> Inventario</li>
                            <li class="complete"><span>15</span> Modo interno</li>
                        </ol>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="app-toast-message"></div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
