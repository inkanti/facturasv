<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/clientes.php';
$user = requireAuth();
$canWrite = in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true);
$canDelete = $user['rol'] === 'ADMIN';

$pageTitle = 'Clientes';
$bodyClass = 'app-page';
$activePage = 'clientes';
$pageScripts = ['assets/js/masks.js', 'assets/js/clientes.js'];
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

    <main class="app-main" id="clients-app" data-api="<?= e(url('api/clientes.php')) ?>"
          data-can-write="<?= $canWrite ? '1' : '0' ?>" data-can-delete="<?= $canDelete ? '1' : '0' ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 3</p>
                <h1>Clientes</h1>
                <p>Administre los contribuyentes receptores de su empresa.</p>
            </div>
            <span class="badge <?= $canWrite ? 'text-bg-primary' : 'text-bg-secondary' ?> align-self-start">
                <?= $canWrite ? ($canDelete ? 'Modo administrador' : 'Gestión de clientes') : 'Solo lectura' ?>
            </span>
        </div>

        <section class="card clients-card">
            <div class="card-body">
                <div class="clients-toolbar">
                    <div>
                        <span class="info-label">Directorio</span>
                        <h2>Listado de clientes</h2>
                    </div>
                    <div class="clients-actions">
                        <button class="btn btn-outline-success" id="export-clients" type="button">Exportar CSV</button>
                        <?php if ($canWrite): ?>
                        <button class="btn btn-outline-primary" id="open-import" type="button">Importar CSV</button>
                        <button class="btn btn-primary" id="new-client" type="button">Nuevo cliente</button>
                        <?php endif; ?>
                    </div>
                </div>

                <form class="client-filters" id="client-filters">
                    <div class="filter-search">
                        <label class="form-label" for="client-search">Buscar</label>
                        <input class="form-control" id="client-search" type="search" maxlength="100"
                               placeholder="Nombre, documento sin guiones, correo o actividad">
                    </div>
                    <div class="client-filter-contributor">
                        <label class="form-label" for="client-contributor">Contribuyente</label>
                        <select class="form-select" id="client-contributor">
                            <option value="">Todos</option>
                            <?php foreach (clientContributorTypes() as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-status">
                        <label class="form-label" for="client-status">Estado</label>
                        <select class="form-select" id="client-status">
                            <option value="">Todos</option>
                            <option value="1">Activos</option>
                            <option value="0">Inactivos</option>
                        </select>
                    </div>
                    <button class="btn btn-outline-primary filter-submit" type="submit">Buscar</button>
                </form>

                <div class="alert alert-danger d-none" id="client-alert" role="alert"></div>
                <div class="table-responsive">
                    <table class="table clients-table align-middle">
                        <thead>
                        <tr>
                            <th>Cliente</th><th>Documento</th><th>Contacto</th><th>Ubicación</th>
                            <th>Contribuyente</th><th>Estado</th>
                            <?= $canWrite ? '<th class="text-end">Acciones</th>' : '' ?>
                        </tr>
                        </thead>
                        <tbody id="clients-table-body">
                        <tr><td colspan="7" class="text-center text-muted py-4">Cargando clientes…</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="catalog-pagination">
                    <span id="clients-pagination-summary">Mostrando 0 clientes</span>
                    <div class="btn-group" role="group" aria-label="Paginación">
                        <button class="btn btn-outline-secondary btn-sm" id="clients-page-prev" type="button">Anterior</button>
                        <span class="pagination-page" id="clients-pagination-page">Página 1</span>
                        <button class="btn btn-outline-secondary btn-sm" id="clients-page-next" type="button">Siguiente</button>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<?php if ($canWrite): ?>
<div class="modal fade" id="client-modal" tabindex="-1" aria-labelledby="client-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="client-form">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="client-modal-title">Nuevo cliente</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="client-modal-alert" role="alert"></div>
                    <input type="hidden" name="id" id="client-id">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="activo" value="0">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label required-label" for="client-document-type">Tipo de documento</label>
                            <select class="form-select" name="tipo_documento" id="client-document-type" required>
                                <option value="NIT">NIT</option>
                                <option value="DUI">DUI</option>
                                <option value="NINGUNO">Sin documento (consumidor final)</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_documento"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="client-document">Número de documento</label>
                            <input class="form-control" name="numero_documento" id="client-document" type="text"
                                   data-mask="nit" inputmode="numeric" maxlength="17" placeholder="0000-000000-000-0" required>
                            <div class="form-text">Opcional solo si el cliente no tiene DUI ni NIT.</div>
                            <div class="invalid-feedback" data-error-for="numero_documento"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="client-contributor-type">Tipo de contribuyente</label>
                            <select class="form-select" name="tipo_contribuyente" id="client-contributor-type" required>
                                <?php foreach (clientContributorTypes() as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= $key === 'OTRO' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_contribuyente"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label" for="client-name">Nombre o razón social</label>
                            <input class="form-control" name="nombre" id="client-name" type="text" maxlength="150" required>
                            <div class="invalid-feedback" data-error-for="nombre"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="client-trade-name">Nombre comercial</label>
                            <input class="form-control" name="nombre_comercial" id="client-trade-name" type="text" maxlength="150">
                            <div class="invalid-feedback" data-error-for="nombre_comercial"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="client-activity">Actividad económica</label>
                            <input class="form-control" name="actividad_economica" id="client-activity" type="text" maxlength="150">
                            <div class="invalid-feedback" data-error-for="actividad_economica"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label required-label" for="client-address">Dirección (complemento)</label>
                            <input class="form-control" name="direccion" id="client-address" type="text" maxlength="255" required>
                            <div class="invalid-feedback" data-error-for="direccion"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label" for="client-department">Departamento</label>
                            <select class="form-select" name="departamento_id" id="client-department" required>
                                <option value="">Seleccione…</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="departamento_id"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label" for="client-municipality">Municipio</label>
                            <select class="form-select" name="municipio_id" id="client-municipality" required>
                                <option value="">Seleccione primero un departamento…</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="municipio_id"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="client-phone">Teléfono</label>
                            <input class="form-control" name="telefono" id="client-phone" type="tel" data-mask="telefono"
                                   inputmode="numeric" maxlength="12" placeholder="0000-0000">
                            <div class="invalid-feedback" data-error-for="telefono"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="client-email">Correo electrónico</label>
                            <input class="form-control" name="email" id="client-email" type="email" maxlength="150">
                            <div class="invalid-feedback" data-error-for="email"></div>
                        </div>
                    </div>
                    <div class="form-check mt-3">
                        <input class="form-check-input" name="activo" id="client-active" type="checkbox" value="1" checked>
                        <label class="form-check-label" for="client-active">Cliente activo</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="button-label">Guardar cliente</span>
                        <span class="button-loading d-none">Guardando…</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="import-modal" tabindex="-1" aria-labelledby="import-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="import-form" enctype="multipart/form-data">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="import-modal-title">Importar clientes desde CSV</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="action" value="import">
                    <p class="import-help">Use la plantilla con columnas separadas por punto y coma. Máximo 1000 clientes y 2 MB. Si una fila tiene errores, no se importará ningún registro.</p>
                    <a class="template-link" href="<?= e(url('api/clientes.php?action=template')) ?>">Descargar plantilla CSV</a>
                    <div class="mt-3">
                        <label class="form-label required-label" for="client-file">Archivo CSV</label>
                        <input class="form-control" id="client-file" name="archivo" type="file" accept=".csv,text/csv" required>
                    </div>
                    <div class="alert alert-danger d-none mt-3" id="import-alert" role="alert"></div>
                    <div class="import-errors d-none" id="import-errors"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">
                        <span class="button-label">Importar clientes</span>
                        <span class="button-loading d-none">Importando…</span>
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
