<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/catalogos.php';
$user = requireAuth();
$definitions = catalogDefinitions();
$canEdit = $user['rol'] === 'ADMIN';

$pageTitle = 'Catálogos MH';
$bodyClass = 'app-page';
$activePage = 'catalogos';
$pageScripts = ['assets/js/catalogos.js'];
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

    <main class="app-main" id="catalog-app" data-api="<?= e(url('api/catalogos.php')) ?>"
          data-can-edit="<?= $canEdit ? '1' : '0' ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 2</p>
                <h1>Catálogos MH</h1>
                <p>Administre los valores que alimentarán los formularios del sistema.</p>
            </div>
            <span class="badge <?= $canEdit ? 'text-bg-primary' : 'text-bg-secondary' ?> align-self-start">
                <?= $canEdit ? 'Modo administrador' : 'Solo lectura' ?>
            </span>
        </div>

        <div class="alert catalog-notice" role="note">
            <strong>Valores pendientes de validación oficial.</strong>
            Los códigos iniciales se revisarán con la documentación MH antes de completar la emisión de DTE.
        </div>

        <div class="catalog-layout">
            <aside class="card catalog-menu-card">
                <div class="card-body">
                    <span class="info-label">Catálogos disponibles</span>
                    <nav class="catalog-menu" aria-label="Seleccionar catálogo">
                        <?php foreach ($definitions as $key => $definition): ?>
                        <button class="catalog-option <?= $key === 'tipos-documento' ? 'active' : '' ?>"
                                type="button" data-resource="<?= e($key) ?>">
                            <?= e($definition['label']) ?>
                        </button>
                        <?php endforeach; ?>
                    </nav>
                </div>
            </aside>

            <section class="card catalog-data-card">
                <div class="card-body">
                    <div class="catalog-toolbar">
                        <div>
                            <span class="info-label">Listado</span>
                            <h2 id="catalog-title">Tipos de documento</h2>
                        </div>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-primary" id="new-catalog-item" type="button">Agregar registro</button>
                        <?php endif; ?>
                    </div>

                    <form class="catalog-filters" id="catalog-filters">
                        <div class="filter-search">
                            <label class="form-label" for="catalog-search">Buscar</label>
                            <input class="form-control" id="catalog-search" type="search" maxlength="100"
                                   placeholder="Código o nombre; puede escribir varias palabras">
                        </div>
                        <div class="filter-department d-none" id="department-filter-group">
                            <label class="form-label" for="department-filter">Departamento</label>
                            <select class="form-select" id="department-filter">
                                <option value="">Todos</option>
                            </select>
                        </div>
                        <div class="filter-status">
                            <label class="form-label" for="catalog-status">Estado</label>
                            <select class="form-select" id="catalog-status">
                                <option value="">Todos</option>
                                <option value="1">Activos</option>
                                <option value="0">Inactivos</option>
                            </select>
                        </div>
                        <button class="btn btn-outline-primary filter-submit" type="submit">Buscar</button>
                    </form>

                    <div class="alert alert-danger d-none" id="catalog-alert" role="alert"></div>
                    <div class="table-responsive">
                        <table class="table catalog-table align-middle">
                            <thead>
                            <tr id="catalog-table-head">
                                <th>Código</th><th>Nombre</th><th>Orden</th><th>Estado</th>
                                <?= $canEdit ? '<th class="text-end">Acciones</th>' : '' ?>
                            </tr>
                            </thead>
                            <tbody id="catalog-table-body">
                            <tr><td colspan="6" class="text-center text-muted py-4">Cargando registros…</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="catalog-pagination">
                        <span id="pagination-summary">Mostrando 0 registros</span>
                        <div class="btn-group" role="group" aria-label="Paginación">
                            <button class="btn btn-outline-secondary btn-sm" id="page-prev" type="button">Anterior</button>
                            <span class="pagination-page" id="pagination-page">Página 1</span>
                            <button class="btn btn-outline-secondary btn-sm" id="page-next" type="button">Siguiente</button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>

<?php if ($canEdit): ?>
<div class="modal fade" id="catalog-modal" tabindex="-1" aria-labelledby="catalog-modal-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="catalog-form">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="catalog-modal-title">Agregar registro</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="modal-alert" role="alert"></div>
                    <input type="hidden" name="id" id="catalog-id">
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <div class="mb-3 d-none" id="modal-department-group">
                        <label class="form-label required-label" for="modal-department">Departamento</label>
                        <select class="form-select" name="departamento_id" id="modal-department">
                            <option value="">Seleccione…</option>
                        </select>
                        <div class="invalid-feedback" data-error-for="departamento_id"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-label" for="catalog-code">Código</label>
                        <input class="form-control" name="codigo" id="catalog-code" type="text" inputmode="numeric" required>
                        <div class="form-text" id="code-help">Ingrese el código numérico del catálogo.</div>
                        <div class="invalid-feedback" data-error-for="codigo"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-label" for="catalog-name">Nombre</label>
                        <input class="form-control" name="nombre" id="catalog-name" type="text" maxlength="120" required>
                        <div class="invalid-feedback" data-error-for="nombre"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required-label" for="catalog-order">Orden</label>
                        <input class="form-control" name="orden" id="catalog-order" type="number" min="0" max="65535" value="0" required>
                        <div class="invalid-feedback" data-error-for="orden"></div>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" name="activo" id="catalog-active" type="checkbox" value="1" checked>
                        <label class="form-check-label" for="catalog-active">Registro activo</label>
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
