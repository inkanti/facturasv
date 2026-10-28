<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
$user = requireAuth();
$canWrite = in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true);
$isAdmin = $user['rol'] === 'ADMIN';
$billingMode = (string) ($user['modo_facturacion'] ?? 'SIMULACION');
$internalMode = $billingMode === 'INTERNO';

$pageTitle = 'Documentos';
$bodyClass = 'app-page';
$activePage = 'documentos';
$pageScripts = ['assets/js/documentos.js'];
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/sidebar.php';
?>
<div class="app-content">
    <header class="app-topbar">
        <div class="topbar-left">
            <button class="btn sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Abrir menú" aria-controls="app-sidebar" aria-expanded="false">☰</button>
            <div><span class="topbar-label">Empresa</span><strong><?= e($user['nombre_comercial']) ?></strong></div>
        </div>
        <div class="topbar-user">
            <div class="user-summary"><strong><?= e($user['nombre']) ?></strong><span><?= e($user['rol']) ?></span></div>
            <form action="<?= e(url('api/auth.php?action=logout')) ?>" method="post" id="logout-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="app-main" id="documents-app" data-api="<?= e(url('api/documentos.php')) ?>"
          data-mh-api="<?= e(url('api/mh.php')) ?>" data-edit-url="<?= e(url('views/nueva-factura.php')) ?>"
          data-download-url="<?= e(url('api/documentos.php?action=download')) ?>"
          data-pdf-url="<?= e(url('api/documentos.php?action=pdf')) ?>"
          data-ticket-url="<?= e(url('views/ticket.php')) ?>"
          data-contingency-url="<?= e(url('views/contingencia.php')) ?>"
          data-can-write="<?= $canWrite ? '1' : '0' ?>" data-is-admin="<?= $isAdmin ? '1' : '0' ?>"
          data-billing-mode="<?= e($billingMode) ?>"
          data-open-id="<?= e((string) ($_GET['documento'] ?? '')) ?>">
        <div class="page-heading">
            <div><p class="eyebrow">Módulos 5, 6, 9 y 10 · Emisión, PDF/ticket, contingencia y tipos DTE</p><h1>Documentos</h1><p>Consulte borradores, transmisiones, respuestas e invalidaciones, descargue el PDF o imprima el ticket del DTE.</p></div>
            <?php if ($canWrite): ?><a class="btn btn-primary" href="<?= e(url('views/nueva-factura.php')) ?>">Nuevo documento</a><?php endif; ?>
        </div>
        <div class="alert alert-info document-mode-note"><?php if ($internalMode): ?>Modo de facturación: <strong>INTERNO</strong>. Los comprobantes son de control interno, no se transmiten al MH y no tienen validez fiscal.<?php else: ?>Entorno MH: <strong>SIMULACIÓN</strong>. Las firmas, sellos y respuestas son demostrativos y no tienen validez fiscal.<?php endif; ?></div>

        <section class="card documents-card">
            <div class="card-body">
                <form class="document-filters" id="document-filters">
                    <div><label class="form-label" for="filter-from">Desde</label><input class="form-control" type="date" id="filter-from"></div>
                    <div><label class="form-label" for="filter-to">Hasta</label><input class="form-control" type="date" id="filter-to"></div>
                    <div><label class="form-label" for="filter-type">Tipo</label><select class="form-select" id="filter-type"><option value="">Todos</option><?php foreach (documentAllTypeDefinitions() as $code => $type): ?><option value="<?= e($code) ?>"><?= e($code . ' · ' . $type['nombre']) ?></option><?php endforeach; ?></select></div>
                    <div><label class="form-label" for="filter-status">Estado</label><select class="form-select" id="filter-status"><option value="">Todos</option><?php foreach (documentStatuses() as $code => $label): ?><option value="<?= e($code) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                    <div class="document-filter-client"><label class="form-label" for="filter-client">Cliente</label><select class="form-select" id="filter-client"><option value="">Todos</option></select></div>
                    <button class="btn btn-outline-primary" type="submit">Filtrar</button>
                    <button class="btn btn-link" id="clear-document-filters" type="button">Limpiar</button>
                </form>
                <div class="alert alert-danger d-none" id="documents-alert" role="alert"></div>
                <div class="table-responsive">
                    <table class="table documents-table align-middle">
                        <thead><tr><th>Documento</th><th>Cliente</th><th>Fecha</th><th>Total</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody id="documents-table-body"><tr><td colspan="6" class="text-center text-muted py-4">Cargando documentos…</td></tr></tbody>
                    </table>
                </div>
                <div class="catalog-pagination"><span id="documents-pagination-summary">Mostrando 0 registros</span><div class="btn-group"><button class="btn btn-outline-secondary btn-sm" id="documents-page-prev" type="button">Anterior</button><span class="pagination-page" id="documents-pagination-page">Página 1</span><button class="btn btn-outline-secondary btn-sm" id="documents-page-next" type="button">Siguiente</button></div></div>
            </div>
        </section>
    </main>
</div>

<div class="modal fade" id="document-detail-modal" tabindex="-1" aria-labelledby="document-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h2 class="modal-title fs-5" id="document-detail-title">Detalle del documento</h2><span class="document-detail-control" id="document-detail-control"></span></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body" id="document-detail-body"><p class="text-muted">Cargando detalle…</p></div>
        <div class="modal-footer" id="document-detail-actions"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="invalidation-modal" tabindex="-1" aria-labelledby="invalidation-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="invalidation-form">
        <div class="modal-header"><h2 class="modal-title fs-5" id="invalidation-title">Invalidar documento</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body">
            <div class="alert alert-warning" id="invalidation-note">La invalidación no elimina el documento; registra un evento de invalidación simulado.</div>
            <div class="alert alert-danger d-none" id="invalidation-alert"></div>
            <input type="hidden" name="id" id="invalidation-id"><input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <p class="required-note"><span class="required-marker">*</span> Campos obligatorios</p>
            <div class="mb-3" id="invalidation-type-group"><label class="form-label required-label" for="invalidation-type">Tipo de invalidación</label><select class="form-select" name="tipo_anulacion" id="invalidation-type" required><option value="1">1 · Error en los datos del documento</option><option value="2">2 · Rescindir de la operación realizada</option><option value="3">3 · Otro motivo</option></select><div class="invalid-feedback" data-error-for="tipo_anulacion"></div></div>
            <div class="mb-3"><label class="form-label required-label" for="invalidation-reason">Motivo</label><textarea class="form-control" name="motivo" id="invalidation-reason" rows="3" minlength="5" maxlength="200" required></textarea><div class="invalid-feedback" data-error-for="motivo"></div></div>
            <div class="row g-3" id="invalidation-responsible-group"><div class="col-md-5"><label class="form-label required-label" for="responsible-document-type">Documento responsable</label><select class="form-select" name="tipo_documento_responsable" id="responsible-document-type" required><option value="13">DUI</option><option value="36">NIT</option></select></div><div class="col-md-7"><label class="form-label required-label" for="responsible-document">Número</label><input class="form-control" name="numero_documento_responsable" id="responsible-document" placeholder="00000000-0" required><div class="invalid-feedback" data-error-for="numero_documento_responsable"></div><div class="form-text">Puede escribirlo con o sin guiones.</div></div></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><span class="button-label">Confirmar invalidación</span><span class="button-loading d-none">Procesando…</span></button></div>
    </form></div></div>
</div>
<?php endif; ?>
<div class="toast-container position-fixed bottom-0 end-0 p-3"><div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true"><div class="toast-body" id="app-toast-message"></div></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
