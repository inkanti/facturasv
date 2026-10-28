<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
$user = requireAuth();
$canWrite = in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true);
$canDelete = $user['rol'] === 'ADMIN';

$pageTitle = 'Productos y Servicios';
$bodyClass = 'app-page';
$activePage = 'productos';
$pageScripts = ['assets/js/productos.js'];
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

    <main class="app-main" id="products-app" data-api="<?= e(url('api/productos.php')) ?>"
          data-can-write="<?= $canWrite ? '1' : '0' ?>" data-can-delete="<?= $canDelete ? '1' : '0' ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 4</p>
                <h1>Productos y Servicios</h1>
                <p>Administre precios, tratamiento de IVA y existencias de su catálogo.</p>
            </div>
            <span class="badge <?= $canWrite ? 'text-bg-primary' : 'text-bg-secondary' ?> align-self-start">
                <?= $canWrite ? ($canDelete ? 'Modo administrador' : 'Gestión de productos') : 'Solo lectura' ?>
            </span>
        </div>

        <section class="card products-card">
            <div class="card-body">
                <div class="products-toolbar">
                    <div>
                        <span class="info-label">Catálogo comercial</span>
                        <h2>Listado de productos y servicios</h2>
                    </div>
                    <?php if ($canWrite): ?>
                    <button class="btn btn-primary" id="new-product" type="button">Nuevo producto o servicio</button>
                    <?php endif; ?>
                </div>

                <form class="product-filters" id="product-filters">
                    <div class="filter-search">
                        <label class="form-label" for="product-search">Buscar</label>
                        <input class="form-control" id="product-search" type="search" maxlength="100"
                               placeholder="Código, código de barras o descripción">
                    </div>
                    <div class="product-filter-type">
                        <label class="form-label" for="product-type">Tipo</label>
                        <select class="form-select" id="product-type">
                            <option value="">Todos</option>
                            <option value="BIEN">Bienes</option>
                            <option value="SERVICIO">Servicios</option>
                        </select>
                    </div>
                    <div class="product-filter-tax">
                        <label class="form-label" for="product-tax">Tratamiento IVA</label>
                        <select class="form-select" id="product-tax">
                            <option value="">Todos</option>
                            <?php foreach (productTaxTreatments() as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-status">
                        <label class="form-label" for="product-status">Estado</label>
                        <select class="form-select" id="product-status">
                            <option value="">Todos</option>
                            <option value="1">Activos</option>
                            <option value="0">Inactivos</option>
                        </select>
                    </div>
                    <button class="btn btn-outline-primary filter-submit" type="submit">Buscar</button>
                </form>

                <div class="alert alert-danger d-none" id="product-alert" role="alert"></div>
                <div class="table-responsive">
                    <table class="table products-table align-middle">
                        <thead>
                        <tr>
                            <th>Código</th><th>Descripción</th><th>Tipo y unidad</th><th>Precio</th>
                            <th>IVA</th><th>Existencias</th><th>Estado</th>
                            <?= $canWrite ? '<th class="text-end">Acciones</th>' : '' ?>
                        </tr>
                        </thead>
                        <tbody id="products-table-body">
                        <tr><td colspan="8" class="text-center text-muted py-4">Cargando productos y servicios…</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="catalog-pagination">
                    <span id="products-pagination-summary">Mostrando 0 registros</span>
                    <div class="btn-group" role="group" aria-label="Paginación">
                        <button class="btn btn-outline-secondary btn-sm" id="products-page-prev" type="button">Anterior</button>
                        <span class="pagination-page" id="products-pagination-page">Página 1</span>
                        <button class="btn btn-outline-secondary btn-sm" id="products-page-next" type="button">Siguiente</button>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<?php if ($canWrite): ?>
<div class="modal fade" id="product-modal" tabindex="-1" aria-labelledby="product-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="product-form">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="product-modal-title">Nuevo producto o servicio</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="product-modal-alert" role="alert"></div>
                    <p class="required-note"><span class="required-marker">*</span> Campos obligatorios</p>
                    <input type="hidden" name="id" id="product-id">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="control_stock" value="0">
                    <input type="hidden" name="activo" value="0">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required-label" for="product-code">Código</label>
                            <input class="form-control" name="codigo" id="product-code" type="text" maxlength="30"
                                   pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,29}" placeholder="PROD-001" required>
                            <div class="invalid-feedback" data-error-for="codigo"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="product-barcode">Código de barras</label>
                            <input class="form-control" name="codigo_barras" id="product-barcode" type="text" maxlength="64"
                                   autocomplete="off" placeholder="EAN-13, UPC o Code128">
                            <div class="invalid-feedback" data-error-for="codigo_barras"></div>
                            <div class="form-text">Opcional. Escanéelo con la pistola USB o escríbalo.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="product-kind">Tipo</label>
                            <select class="form-select" name="tipo" id="product-kind" required>
                                <option value="BIEN">Bien</option>
                                <option value="SERVICIO">Servicio</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label required-label" for="product-description">Descripción</label>
                            <input class="form-control" name="descripcion" id="product-description" type="text" maxlength="255" required>
                            <div class="invalid-feedback" data-error-for="descripcion"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="product-unit">Unidad de medida</label>
                            <select class="form-select" name="unidad_medida_id" id="product-unit" required>
                                <option value="">Seleccione…</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="unidad_medida_id"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="product-price">Precio unitario (USD)</label>
                            <input class="form-control" name="precio_unitario" id="product-price" type="number"
                                   min="0" max="999999999.999999" step="0.000001" placeholder="0.00" required>
                            <div class="invalid-feedback" data-error-for="precio_unitario"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="product-tax-treatment">Tratamiento de IVA</label>
                            <select class="form-select" name="tratamiento_iva" id="product-tax-treatment" required>
                                <?php foreach (productTaxTreatments() as $key => $label): ?>
                                <option value="<?= e($key) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tratamiento_iva"></div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" name="control_stock" id="product-stock-control" type="checkbox" value="1">
                                <label class="form-check-label" for="product-stock-control">Controlar existencias</label>
                            </div>
                            <div class="form-text">Si no se activa, el registro no llevará control de stock.</div>
                        </div>
                        <div class="col-md-6 d-none" id="product-stock-group">
                            <label class="form-label required-label" for="product-stock">Existencias actuales</label>
                            <input class="form-control" name="stock" id="product-stock" type="number"
                                   min="0" max="99999999999.9999" step="0.0001" placeholder="0" disabled>
                            <div class="invalid-feedback" data-error-for="stock"></div>
                            <div class="form-text d-none" id="product-stock-note">El stock se ajusta desde Inventario → Movimientos. Aquí se muestra la existencia actual.</div>
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" name="activo" id="product-active" type="checkbox" value="1" checked>
                        <label class="form-check-label" for="product-active">Producto o servicio activo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="button-label">Guardar</span>
                        <span class="button-loading d-none">Guardando…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="app-toast-message"></div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
