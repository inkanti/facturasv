<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
$user = requireAuth();
requireRole($user, 'ADMIN');

$pageTitle = 'Configuración';
$bodyClass = 'app-page';
$activePage = 'configuracion';
$pageScripts = ['assets/js/configuracion.js'];
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

    <main class="app-main" id="config-app" data-api="<?= e(url('api/configuracion.php')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 8 · Configuración</p>
                <h1>Configuración</h1>
                <p>Datos fiscales, emisor del DTE, numeración, usuarios y correo de la empresa.</p>
            </div>
            <span class="badge text-bg-primary align-self-start">Administrador</span>
        </div>

        <div class="alert alert-danger d-none" id="config-alert" role="alert"></div>

        <ul class="nav nav-tabs config-tabs" role="tablist">
            <li class="nav-item" role="presentation"><button class="nav-link active" id="tab-empresa-link" data-bs-toggle="tab" data-bs-target="#tab-empresa" type="button" role="tab" aria-controls="tab-empresa" aria-selected="true">Empresa</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-emisor-link" data-bs-toggle="tab" data-bs-target="#tab-emisor" type="button" role="tab" aria-controls="tab-emisor" aria-selected="false">Emisor y DTE</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-numeracion-link" data-bs-toggle="tab" data-bs-target="#tab-numeracion" type="button" role="tab" aria-controls="tab-numeracion" aria-selected="false">Numeración</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-usuarios-link" data-bs-toggle="tab" data-bs-target="#tab-usuarios" type="button" role="tab" aria-controls="tab-usuarios" aria-selected="false">Usuarios</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-correo-link" data-bs-toggle="tab" data-bs-target="#tab-correo" type="button" role="tab" aria-controls="tab-correo" aria-selected="false">Correo</button></li>
            <li class="nav-item" role="presentation"><button class="nav-link" id="tab-historial-link" data-bs-toggle="tab" data-bs-target="#tab-historial" type="button" role="tab" aria-controls="tab-historial" aria-selected="false">Historial</button></li>
        </ul>

        <div class="tab-content config-tab-content">
            <div class="tab-pane fade show active" id="tab-empresa" role="tabpanel" aria-labelledby="tab-empresa-link">
                <section class="card config-card">
                    <div class="card-body">
                        <div class="config-company-grid">
                            <div class="logo-panel">
                                <span class="info-label">Logo del emisor</span>
                                <div class="logo-preview" id="logo-preview">
                                    <img id="logo-image" class="logo-preview-img d-none" alt="Logo de la empresa">
                                    <span class="logo-placeholder" id="logo-placeholder">Sin logo</span>
                                </div>
                                <form id="logo-form" enctype="multipart/form-data">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                    <input class="form-control form-control-sm" type="file" name="logo" id="logo-input" accept="image/png,image/jpeg">
                                    <div class="invalid-feedback d-block" data-error-for="logo"></div>
                                    <div class="logo-actions">
                                        <button class="btn btn-outline-primary btn-sm" type="submit">Subir logo</button>
                                        <button class="btn btn-outline-danger btn-sm" type="button" id="remove-logo">Quitar logo</button>
                                    </div>
                                    <p class="form-text">PNG o JPG, máximo 1 MB, entre 40×40 y 2000×2000 px. Se normaliza a JPEG en el PDF.</p>
                                </form>
                            </div>
                            <form id="company-form" class="config-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                                <h2>Datos de la empresa</h2>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-razon">Razón social</label>
                                        <input class="form-control" id="company-razon" name="razon_social" maxlength="150" required>
                                        <div class="invalid-feedback" data-error-for="razon_social"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-nombre">Nombre comercial</label>
                                        <input class="form-control" id="company-nombre" name="nombre_comercial" maxlength="150" required>
                                        <div class="invalid-feedback" data-error-for="nombre_comercial"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-nit">NIT</label>
                                        <input class="form-control" id="company-nit" name="nit" placeholder="0000-000000-000-0" maxlength="17" required>
                                        <div class="invalid-feedback" data-error-for="nit"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-nrc">Número de registro MH (NRC)</label>
                                        <input class="form-control" id="company-nrc" name="numero_registro_mh" maxlength="30" required>
                                        <div class="invalid-feedback" data-error-for="numero_registro_mh"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-actividad">Código de actividad económica</label>
                                        <input class="form-control" id="company-actividad" name="codigo_actividad" maxlength="10" required>
                                        <div class="invalid-feedback" data-error-for="codigo_actividad"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-telefono">Teléfono</label>
                                        <input class="form-control" id="company-telefono" name="telefono" placeholder="0000-0000" maxlength="9" required>
                                        <div class="invalid-feedback" data-error-for="telefono"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-email">Correo</label>
                                        <input class="form-control" id="company-email" name="email" type="email" maxlength="150" required>
                                        <div class="invalid-feedback" data-error-for="email"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label required-label" for="company-direccion">Dirección</label>
                                        <input class="form-control" id="company-direccion" name="direccion" maxlength="255" required>
                                        <div class="invalid-feedback" data-error-for="direccion"></div>
                                    </div>
                                </div>
                                <div class="config-form-actions">
                                    <button class="btn btn-primary" type="submit"><span class="button-label">Guardar empresa</span><span class="button-loading d-none">Guardando…</span></button>
                                </div>
                                <p class="form-text mb-0">Los cambios se aplican a los próximos documentos; los DTE ya emitidos conservan sus datos.</p>
                            </form>
                        </div>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-emisor" role="tabpanel" aria-labelledby="tab-emisor-link">
                <section class="card config-card">
                    <div class="card-body">
                        <form id="emitter-form" class="config-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <h2>Emisor y entorno del DTE</h2>
                            <div class="alert alert-info">El emisor se toma de estos datos en cada documento nuevo. El entorno de producción del Ministerio de Hacienda aún no está disponible, por lo que permanece en SIMULACIÓN. Si la empresa está en modo interno, todos los documentos serán comprobantes no fiscales.</div>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label required-label" for="emitter-establecimiento">Establecimiento y punto de venta</label>
                                    <input class="form-control" id="emitter-establecimiento" name="establecimiento" maxlength="8" placeholder="M001P001" required>
                                    <div class="invalid-feedback" data-error-for="establecimiento"></div>
                                    <div class="form-text">8 caracteres: 4 del establecimiento y 4 del punto de venta.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required-label" for="emitter-distrito">Distrito del emisor</label>
                                    <input class="form-control" id="emitter-distrito" name="distrito_emisor" maxlength="2" placeholder="14" required>
                                    <div class="invalid-feedback" data-error-for="distrito_emisor"></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label required-label" for="emitter-mode">Entorno MH</label>
                                    <select class="form-select" id="emitter-mode" name="mh_mode" required>
                                        <option value="SIMULACION">SIMULACIÓN</option>
                                        <option value="PRODUCCION" disabled>PRODUCCIÓN (no disponible)</option>
                                    </select>
                                    <div class="invalid-feedback" data-error-for="mh_mode"></div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="emitter-billing-mode">Modo de facturación</label>
                                    <input class="form-control" id="emitter-billing-mode" disabled>
                                    <div class="form-text">Lo define el superadministrador de la plataforma.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required-label" for="emitter-department">Departamento del emisor</label>
                                    <select class="form-select" id="emitter-department" name="departamento_emisor" required></select>
                                    <div class="invalid-feedback" data-error-for="departamento_emisor"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required-label" for="emitter-municipality">Municipio del emisor</label>
                                    <select class="form-select" id="emitter-municipality" name="municipio_emisor" required></select>
                                    <div class="invalid-feedback" data-error-for="municipio_emisor"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required-label" for="emitter-timezone">Zona horaria</label>
                                    <select class="form-select" id="emitter-timezone" name="timezone" required></select>
                                    <div class="invalid-feedback" data-error-for="timezone"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="emitter-currency">Moneda</label>
                                    <input class="form-control" id="emitter-currency" value="USD" disabled>
                                </div>
                            </div>
                            <div class="config-form-actions">
                                <button class="btn btn-primary" type="submit"><span class="button-label">Guardar emisor</span><span class="button-loading d-none">Guardando…</span></button>
                            </div>
                            <p class="form-text mb-0">Cambiar el establecimiento crea series nuevas para ese punto de venta; los documentos anteriores conservan su número de control.</p>
                        </form>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-numeracion" role="tabpanel" aria-labelledby="tab-numeracion-link">
                <section class="card config-card">
                    <div class="card-body">
                        <h2>Numeración por tipo de documento</h2>
                        <p class="text-muted">El próximo número de control usa el número actual más uno. No puede ser menor al último documento emitido.</p>
                        <div class="table-responsive">
                            <table class="table config-sequences-table align-middle">
                                <thead><tr><th>Tipo</th><th>Establecimiento</th><th>Prefijo</th><th class="text-end">Inicial</th><th class="text-end">Actual</th><th class="text-end">Próximo</th><th class="text-center">Activa</th><th class="text-end">Acción</th></tr></thead>
                                <tbody id="sequences-body"><tr><td colspan="8" class="text-center text-muted py-4">Cargando series…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-usuarios" role="tabpanel" aria-labelledby="tab-usuarios-link">
                <section class="card config-card">
                    <div class="card-body">
                        <div class="config-toolbar">
                            <div>
                                <span class="info-label">Usuarios de la empresa</span>
                                <h2>Accesos y roles</h2>
                            </div>
                            <span class="badge text-bg-secondary">Solo lectura</span>
                        </div>
                        <div class="alert alert-info">La creación, edición, activación y restablecimiento de contraseñas de usuarios la realiza el superadministrador desde el panel global. Aquí solo puede consultar los accesos de su empresa.</div>
                        <div class="table-responsive">
                            <table class="table config-users-table align-middle">
                                <thead><tr><th>Nombre</th><th>Usuario</th><th>Correo</th><th>Rol</th><th>Estado</th><th>Último acceso</th></tr></thead>
                                <tbody id="users-body"><tr><td colspan="6" class="text-center text-muted py-4">Cargando usuarios…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-correo" role="tabpanel" aria-labelledby="tab-correo-link">
                <section class="card config-card">
                    <div class="card-body">
                        <h2>Envío de documentos por correo</h2>
                        <div class="alert alert-info">Las credenciales SMTP se definen en el servidor, en <code>config/correo.local.php</code> o en las variables <code>MAIL_*</code>. Por seguridad no se guardan ni se editan desde la base de datos.</div>
                        <dl class="config-mail-status" id="mail-status"></dl>
                        <form id="mail-test-form" class="config-mail-test">
                            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                            <div>
                                <label class="form-label required-label" for="mail-test-recipient">Enviar correo de prueba a</label>
                                <input class="form-control" id="mail-test-recipient" name="destinatario" type="email" maxlength="150" required>
                                <div class="invalid-feedback" data-error-for="destinatario"></div>
                            </div>
                            <button class="btn btn-outline-primary" type="submit"><span class="button-label">Enviar prueba</span><span class="button-loading d-none">Enviando…</span></button>
                        </form>
                    </div>
                </section>
            </div>

            <div class="tab-pane fade" id="tab-historial" role="tabpanel" aria-labelledby="tab-historial-link">
                <section class="card config-card">
                    <div class="card-body">
                        <h2>Cambios de configuración</h2>
                        <p class="text-muted">Últimos cambios realizados por los administradores.</p>
                        <div class="table-responsive">
                            <table class="table config-audit-table align-middle">
                                <thead><tr><th>Sección</th><th>Detalle</th><th>Usuario</th><th>Fecha</th></tr></thead>
                                <tbody id="audit-body"><tr><td colspan="4" class="text-center text-muted py-4">Cargando historial…</td></tr></tbody>
                            </table>
                        </div>
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
