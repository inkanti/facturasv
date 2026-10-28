<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/configuracion.php';
require_once dirname(__DIR__) . '/lib/superadmin.php';
$superadmin = requireSuperadmin();

$pageTitle = 'Superadministración';
$bodyClass = 'app-page superadmin-page';
$pageScripts = ['assets/js/superadmin.js'];
require __DIR__ . '/partials/head.php';
?>
<div class="app-content superadmin-content">
    <header class="app-topbar">
        <div class="topbar-left">
            <div><span class="topbar-label">FacturaSV</span><strong>Superadministración</strong></div>
        </div>
        <div class="topbar-user">
            <div class="user-summary"><strong><?= e($superadmin['nombre']) ?></strong><span>SUPERADMIN</span></div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('views/perfil.php')) ?>">Mi perfil</a>
            <form action="<?= e(url('api/auth.php?action=logout')) ?>" method="post" id="logout-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="app-main" id="superadmin-app" data-api="<?= e(url('api/superadmin.php')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 11 · Gobierno del SaaS</p>
                <h1>Empresas</h1>
                <p>Cree empresas, asigne sus usuarios y controle el acceso al sistema.</p>
                <p class="text-muted mb-0">El modo de facturación define si la empresa emite DTE de prueba (simulación) o comprobantes internos no fiscales. Solo el superadministrador puede cambiarlo.</p>
            </div>
            <button class="btn btn-primary" type="button" id="new-company">Nueva empresa</button>
        </div>

        <div class="superadmin-summary">
            <div class="kpi-card"><span class="kpi-label">Empresas</span><strong class="kpi-value" id="sa-companies">—</strong></div>
            <div class="kpi-card"><span class="kpi-label">Empresas activas</span><strong class="kpi-value" id="sa-active">—</strong></div>
            <div class="kpi-card"><span class="kpi-label">Usuarios</span><strong class="kpi-value" id="sa-users">—</strong></div>
        </div>

        <div class="alert alert-danger d-none" id="superadmin-alert" role="alert"></div>

        <section class="card documents-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div><h2 class="h5 mb-1">Empresas registradas</h2><p class="text-muted mb-0">El superadministrador crea y desactiva empresas; los datos fiscales se conservan.</p></div>
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="refresh-companies">Actualizar</button>
                </div>
                <div class="table-responsive">
                    <table class="table documents-table align-middle superadmin-table">
                        <thead><tr><th>Empresa</th><th>NIT</th><th>Modo</th><th>Usuarios</th><th>Documentos</th><th>Estado</th><th>Creada</th><th class="text-end">Acciones</th></tr></thead>
                        <tbody id="companies-table-body"><tr><td colspan="8" class="text-center text-muted py-4">Cargando empresas…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

<div class="modal fade" id="company-create-modal" tabindex="-1" aria-labelledby="company-create-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="company-create-form">
                <div class="modal-header"><div><h2 class="modal-title fs-5" id="company-create-title">Nueva empresa</h2><span class="document-detail-control">Empresa + primer administrador</span></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="company-form-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <h3 class="superadmin-section-title">Datos de la empresa</h3>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label required-label" for="sa-nit">NIT</label><input class="form-control" name="nit" id="sa-nit" maxlength="17" placeholder="0000-000000-000-0" required><div class="invalid-feedback" data-error-for="nit"></div></div>
                        <div class="col-md-5"><label class="form-label required-label" for="sa-razon">Razón social</label><input class="form-control" name="razon_social" id="sa-razon" maxlength="150" required><div class="invalid-feedback" data-error-for="razon_social"></div></div>
                        <div class="col-md-3"><label class="form-label required-label" for="sa-comercial">Nombre comercial</label><input class="form-control" name="nombre_comercial" id="sa-comercial" maxlength="150" required><div class="invalid-feedback" data-error-for="nombre_comercial"></div></div>
                        <div class="col-md-3"><label class="form-label required-label" for="sa-actividad">Código actividad</label><input class="form-control" name="codigo_actividad" id="sa-actividad" maxlength="10" placeholder="00000" required><div class="invalid-feedback" data-error-for="codigo_actividad"></div></div>
                        <div class="col-md-5"><label class="form-label required-label" for="sa-direccion">Dirección</label><input class="form-control" name="direccion" id="sa-direccion" maxlength="255" required><div class="invalid-feedback" data-error-for="direccion"></div></div>
                        <div class="col-md-4"><label class="form-label required-label" for="sa-telefono">Teléfono</label><input class="form-control" name="telefono" id="sa-telefono" maxlength="30" placeholder="0000-0000" required><div class="invalid-feedback" data-error-for="telefono"></div></div>
                        <div class="col-md-5"><label class="form-label required-label" for="sa-email">Correo de la empresa</label><input class="form-control" type="email" name="email" id="sa-email" maxlength="150" required><div class="invalid-feedback" data-error-for="email"></div></div>
                        <div class="col-md-4"><label class="form-label required-label" for="sa-nrc">NRC</label><input class="form-control" name="numero_registro_mh" id="sa-nrc" maxlength="30" required><div class="invalid-feedback" data-error-for="numero_registro_mh"></div></div>
                        <div class="col-md-4"><label class="form-label required-label" for="sa-modo">Modo de facturación</label><select class="form-select" name="modo_facturacion" id="sa-modo" required><option value="SIMULACION">Simulación (DTE de prueba)</option><option value="INTERNO">Interno (comprobante no fiscal)</option></select><div class="invalid-feedback" data-error-for="modo_facturacion"></div></div>
                        <div class="col-12"><div class="form-text">En modo interno la empresa no genera DTE, sello ni código de generación; sus comprobantes son de control interno y no tienen validez fiscal.</div></div>
                    </div>

                    <h3 class="superadmin-section-title mt-4">Primer administrador</h3>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label required-label" for="sa-admin-nombre">Nombre</label><input class="form-control" name="admin_nombre" id="sa-admin-nombre" maxlength="120" required><div class="invalid-feedback" data-error-for="admin_nombre"></div></div>
                        <div class="col-md-4"><label class="form-label required-label" for="sa-admin-usuario">Usuario</label><input class="form-control" name="admin_usuario" id="sa-admin-usuario" maxlength="50" required><div class="invalid-feedback" data-error-for="admin_usuario"></div></div>
                        <div class="col-md-4"><label class="form-label required-label" for="sa-admin-email">Correo</label><input class="form-control" type="email" name="admin_email" id="sa-admin-email" maxlength="150" required><div class="invalid-feedback" data-error-for="admin_email"></div></div>
                        <div class="col-md-6"><label class="form-label required-label" for="sa-admin-password">Contraseña</label><input class="form-control" type="password" name="admin_password" id="sa-admin-password" minlength="10" required><div class="invalid-feedback" data-error-for="admin_password"></div></div>
                        <div class="col-md-6"><label class="form-label required-label" for="sa-admin-confirmation">Confirmar contraseña</label><input class="form-control" type="password" name="admin_password_confirmation" id="sa-admin-confirmation" minlength="10" required><div class="invalid-feedback" data-error-for="admin_password_confirmation"></div></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><span class="button-label">Crear empresa</span><span class="button-loading d-none">Creando…</span></button></div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="company-users-modal" tabindex="-1" aria-labelledby="company-users-title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header"><div><h2 class="modal-title fs-5" id="company-users-title">Usuarios de la empresa</h2><span class="document-detail-control" id="company-users-subtitle"></span></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
            <div class="modal-body">
                <div class="alert alert-danger d-none" id="users-alert"></div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="text-muted mb-0">Una empresa puede tener uno o muchos usuarios.</p>
                    <button class="btn btn-primary btn-sm" type="button" id="new-company-user">Nuevo usuario</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle superadmin-table"><thead><tr><th>Nombre</th><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th class="text-end">Acciones</th></tr></thead><tbody id="users-table-body"></tbody></table>
                </div>

                <div class="superadmin-inline-form d-none" id="user-create-wrap">
                    <form id="company-user-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="tenant_id" id="user-tenant-id">
                        <div class="alert alert-danger d-none" id="user-form-alert"></div>
                        <h3 class="superadmin-section-title">Nuevo usuario</h3>
                        <div class="row g-3">
                            <div class="col-md-4"><label class="form-label required-label" for="sa-user-nombre">Nombre</label><input class="form-control" name="nombre" id="sa-user-nombre" maxlength="120" required><div class="invalid-feedback" data-error-for="nombre"></div></div>
                            <div class="col-md-4"><label class="form-label required-label" for="sa-user-usuario">Usuario</label><input class="form-control" name="usuario" id="sa-user-usuario" maxlength="50" required><div class="invalid-feedback" data-error-for="usuario"></div></div>
                            <div class="col-md-4"><label class="form-label required-label" for="sa-user-email">Correo</label><input class="form-control" type="email" name="email" id="sa-user-email" maxlength="150" required><div class="invalid-feedback" data-error-for="email"></div></div>
                            <div class="col-md-4"><label class="form-label required-label" for="sa-user-rol">Rol</label><select class="form-select" name="rol" id="sa-user-rol" required><option value="ADMIN">Administrador</option><option value="VENDEDOR">Vendedor</option><option value="CONTADOR">Contador</option></select><div class="invalid-feedback" data-error-for="rol"></div></div>
                            <div class="col-md-4"><label class="form-label required-label" for="sa-user-password">Contraseña</label><input class="form-control" type="password" name="password" id="sa-user-password" minlength="10" required><div class="invalid-feedback" data-error-for="password"></div></div>
                            <div class="col-md-4"><label class="form-label required-label" for="sa-user-confirmation">Confirmar contraseña</label><input class="form-control" type="password" name="password_confirmation" id="sa-user-confirmation" minlength="10" required><div class="invalid-feedback" data-error-for="password_confirmation"></div></div>
                        </div>
                        <div class="d-flex justify-content-end gap-2 mt-3"><button class="btn btn-outline-secondary btn-sm" type="button" id="cancel-company-user">Cancelar</button><button class="btn btn-primary btn-sm" type="submit">Crear usuario</button></div>
                    </form>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="user-password-modal" tabindex="-1" aria-labelledby="user-password-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="user-password-form">
                <div class="modal-header"><h2 class="modal-title fs-5" id="user-password-title">Restablecer contraseña</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="password-alert"></div>
                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                    <input type="hidden" name="tenant_id" id="password-tenant-id">
                    <input type="hidden" name="id" id="password-user-id">
                    <p class="text-muted" id="password-user-label"></p>
                    <div class="mb-3"><label class="form-label required-label" for="sa-new-password">Nueva contraseña</label><input class="form-control" type="password" name="password" id="sa-new-password" minlength="10" required><div class="invalid-feedback" data-error-for="password"></div></div>
                    <div class="mb-0"><label class="form-label required-label" for="sa-new-confirmation">Confirmar contraseña</label><input class="form-control" type="password" name="password_confirmation" id="sa-new-confirmation" minlength="10" required><div class="invalid-feedback" data-error-for="password_confirmation"></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Actualizar contraseña</button></div>
            </form>
        </div>
    </div>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3"><div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true"><div class="toast-body" id="app-toast-message"></div></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
