<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/contingencia.php';
$user = requireAuth();
$isAdmin = $user['rol'] === 'ADMIN';
$tenantId = (int) $user['tenant_id'];
$establishments = contingencyEstablishmentTypes(db(), $tenantId);
$selectedEstablishment = array_key_exists('02', $establishments) ? '02' : (string) array_key_first($establishments);
$today = (new DateTimeImmutable('now', new DateTimeZone($user['timezone'])))->format('Y-m-d');
$nowTime = (new DateTimeImmutable('now', new DateTimeZone($user['timezone'])))->format('H:i');

$pageTitle = 'Contingencia';
$bodyClass = 'app-page';
$activePage = 'contingencia';
$pageScripts = ['assets/js/contingencia.js'];
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

    <main class="app-main" id="contingency-app"
          data-api="<?= e(url('api/contingencia.php')) ?>"
          data-is-admin="<?= $isAdmin ? '1' : '0' ?>"
          data-types="<?= e(json_encode(contingencyTypes(), JSON_UNESCAPED_UNICODE)) ?>"
          data-today="<?= e($today) ?>"
          data-now="<?= e($nowTime) ?>"
          data-open-id="<?= e((string) ($_GET['evento'] ?? '')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 9 · Eventos MH</p>
                <h1>Contingencia</h1>
                <p>Declare los DTE emitidos durante una contingencia y transmítalos cuando el servicio se restablezca.</p>
            </div>
            <?php if ($isAdmin): ?>
            <button class="btn btn-primary" type="button" id="new-contingency-event">Nuevo evento</button>
            <?php endif; ?>
        </div>

        <div class="alert alert-info document-mode-note">
            Entorno MH: <strong>SIMULACIÓN</strong>. El evento y los DTE se validan contra el esquema oficial v4, pero la firma y la transmisión son demostrativas.
        </div>

        <section class="card documents-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2 class="h5 mb-1">Eventos de contingencia</h2>
                        <p class="text-muted mb-0">Cada evento agrupa hasta 1000 DTE de un mismo periodo.</p>
                    </div>
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="refresh-contingency-events">Actualizar</button>
                </div>
                <div class="alert alert-danger d-none" id="contingency-list-alert" role="alert"></div>
                <div class="table-responsive">
                    <table class="table documents-table align-middle">
                        <thead>
                            <tr><th>Evento</th><th>Periodo</th><th>Tipo</th><th>Documentos</th><th>Estado</th><th>Sello</th><th class="text-end">Acciones</th></tr>
                        </thead>
                        <tbody id="contingency-table-body">
                            <tr><td colspan="7" class="text-center text-muted py-4">Cargando eventos…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="contingency-create-modal" tabindex="-1" aria-labelledby="contingency-create-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="contingency-form">
                <div class="modal-header">
                    <div><h2 class="modal-title fs-5" id="contingency-create-title">Nuevo evento de contingencia</h2><span class="document-detail-control">Esquema MH v4 · simulación</span></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">Al procesar el evento se transmitirán (en simulación) todos los DTE pendientes del periodo seleccionado.</div>
                    <div class="alert alert-danger d-none" id="contingency-form-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <p class="required-note"><span class="required-marker">*</span> Campos obligatorios</p>

                    <h3 class="contingency-section-title">Periodo de la contingencia</h3>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label required-label" for="cont-f-inicio">Fecha inicio</label>
                            <input class="form-control" type="date" name="f_inicio" id="cont-f-inicio" value="<?= e($today) ?>" required>
                            <div class="invalid-feedback" data-error-for="f_inicio"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-label" for="cont-h-inicio">Hora inicio</label>
                            <input class="form-control" type="time" name="h_inicio" id="cont-h-inicio" value="00:00" required>
                            <div class="invalid-feedback" data-error-for="h_inicio"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-label" for="cont-f-fin">Fecha fin</label>
                            <input class="form-control" type="date" name="f_fin" id="cont-f-fin" value="<?= e($today) ?>" required>
                            <div class="invalid-feedback" data-error-for="f_fin"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-label" for="cont-h-fin">Hora fin</label>
                            <input class="form-control" type="time" name="h_fin" id="cont-h-fin" value="<?= e($nowTime) ?>" required>
                            <div class="invalid-feedback" data-error-for="h_fin"></div>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <label class="form-label required-label" for="cont-type">Tipo de contingencia</label>
                            <select class="form-select" name="tipo_contingencia" id="cont-type" required>
                                <?php foreach (contingencyTypes() as $code => $label): ?>
                                <option value="<?= e((string) $code) ?>"><?= e($code . ' · ' . $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_contingencia"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label required-label" for="cont-establishment">Tipo de establecimiento</label>
                            <select class="form-select" name="tipo_establecimiento" id="cont-establishment" required>
                                <?php foreach ($establishments as $code => $name): ?>
                                <option value="<?= e($code) ?>" <?= $code === $selectedEstablishment ? 'selected' : '' ?>><?= e($code . ' · ' . $name) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_establecimiento"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="cont-reason">Motivo</label>
                            <textarea class="form-control" name="motivo_contingencia" id="cont-reason" rows="2" maxlength="500" placeholder="Detalle el motivo; es obligatorio para el tipo 5 (Otro)"></textarea>
                            <div class="invalid-feedback" data-error-for="motivo_contingencia"></div>
                            <div class="form-text" id="cont-reason-help">Máximo 500 caracteres.</div>
                        </div>
                    </div>

                    <h3 class="contingency-section-title mt-4">Responsable del establecimiento</h3>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label required-label" for="cont-responsible-name">Nombre</label>
                            <input class="form-control" type="text" name="nombre_responsable" id="cont-responsible-name" maxlength="100" value="<?= e($user['nombre']) ?>" required>
                            <div class="invalid-feedback" data-error-for="nombre_responsable"></div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label required-label" for="cont-responsible-type">Documento</label>
                            <select class="form-select" name="tipo_doc_responsable" id="cont-responsible-type" required>
                                <option value="36">NIT</option>
                                <option value="13">DUI</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_doc_responsable"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required-label" for="cont-responsible-number">Número</label>
                            <input class="form-control" type="text" name="numero_doc_responsable" id="cont-responsible-number" placeholder="0000-000000-000-0" required>
                            <div class="invalid-feedback" data-error-for="numero_doc_responsable"></div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <div>
                            <h3 class="contingency-section-title mb-1">Documentos pendientes del periodo</h3>
                            <p class="text-muted small mb-0" id="pending-summary">Aún no se han consultado documentos.</p>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" type="button" id="refresh-pending">Actualizar</button>
                    </div>
                    <div class="alert alert-warning d-none mt-2" id="pending-alert"></div>
                    <div class="table-responsive contingency-pending-wrap">
                        <table class="table table-sm align-middle">
                            <thead><tr><th>Tipo</th><th>Número de control</th><th>Cliente</th><th>Fecha</th><th>Total</th><th>Tipo contingencia</th></tr></thead>
                            <tbody id="pending-table-body"><tr><td colspan="6" class="text-center text-muted py-3">Sin consultar.</td></tr></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" id="create-contingency-submit"><span class="button-label">Generar y transmitir evento</span><span class="button-loading d-none">Procesando…</span></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="contingency-detail-modal" tabindex="-1" aria-labelledby="contingency-detail-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div><h2 class="modal-title fs-5" id="contingency-detail-title">Evento de contingencia</h2><span class="document-detail-control" id="contingency-detail-code"></span></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body" id="contingency-detail-body"><p class="text-muted">Cargando detalle…</p></div>
            <div class="modal-footer" id="contingency-detail-actions">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"><div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true"><div class="toast-body" id="app-toast-message"></div></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
