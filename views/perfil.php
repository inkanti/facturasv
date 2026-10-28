<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/perfil.php';

$profile = perfilCurrent();
if ($profile === null) {
    redirect('views/login.php');
}

$isSuperadmin = (bool) $profile['superadmin'];
$user = null;
if (!$isSuperadmin) {
    $user = currentUser();
    if ($user === null) {
        redirect('views/login.php');
    }
}

$roleLabels = [
    'SUPERADMIN' => 'Superadministrador',
    'ADMIN' => 'Administrador',
    'VENDEDOR' => 'Vendedor',
    'CONTADOR' => 'Contador',
];
$roleLabel = $roleLabels[$profile['rol']] ?? $profile['rol'];
$lastAccess = $profile['last_login_at']
    ? date('d/m/Y H:i', strtotime((string) $profile['last_login_at']))
    : '—';

$pageTitle = 'Mi perfil';
$bodyClass = 'app-page' . ($isSuperadmin ? ' superadmin-page' : '');
$activePage = 'perfil';
$pageScripts = ['assets/js/perfil.js'];
require __DIR__ . '/partials/head.php';
if (!$isSuperadmin) {
    require __DIR__ . '/partials/sidebar.php';
}
?>
<div class="app-content <?= $isSuperadmin ? 'superadmin-content' : '' ?>">
    <header class="app-topbar">
        <div class="topbar-left">
            <?php if (!$isSuperadmin): ?>
            <button class="btn sidebar-toggle" id="sidebar-toggle" type="button" aria-label="Abrir menú" aria-controls="app-sidebar" aria-expanded="false">☰</button>
            <?php endif; ?>
            <div>
                <span class="topbar-label"><?= $isSuperadmin ? 'FacturaSV' : 'Empresa' ?></span>
                <strong><?= e($isSuperadmin ? 'Superadministración' : $user['nombre_comercial']) ?></strong>
            </div>
        </div>
        <div class="topbar-user">
            <div class="user-summary">
                <strong><?= e($profile['nombre']) ?></strong>
                <span><?= e($roleLabel) ?></span>
            </div>
            <?php if ($isSuperadmin): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('views/superadmin.php')) ?>">Panel global</a>
            <?php endif; ?>
            <form action="<?= e(url('api/auth.php?action=logout')) ?>" method="post" id="logout-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <button class="btn btn-outline-secondary btn-sm" type="submit">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="app-main" id="perfil-app" data-api="<?= e(url('api/perfil.php')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow">Módulo 12 · Cuenta</p>
                <h1>Mi perfil</h1>
                <p>Consulte sus datos de acceso y cambie únicamente su propia contraseña.</p>
            </div>
            <span class="badge text-bg-primary align-self-start"><?= e($roleLabel) ?></span>
        </div>

        <div class="alert alert-danger d-none" id="profile-alert" role="alert"></div>

        <div class="profile-grid">
            <section class="card config-card">
                <div class="card-body">
                    <h2 class="h5 mb-3">Datos de la cuenta</h2>
                    <dl class="profile-list">
                        <dt>Nombre</dt><dd><?= e($profile['nombre']) ?></dd>
                        <dt>Usuario</dt><dd><?= e($profile['usuario']) ?></dd>
                        <dt>Correo</dt><dd><?= e($profile['email']) ?></dd>
                        <dt>Rol</dt><dd><?= e($roleLabel) ?></dd>
                        <?php if ($isSuperadmin): ?>
                        <dt>Ámbito</dt><dd>Administración global</dd>
                        <?php else: ?>
                        <dt>Empresa</dt><dd><?= e($profile['empresa']) ?></dd>
                        <dt>NIT</dt><dd><?= e($profile['empresa_nit']) ?></dd>
                        <?php endif; ?>
                        <dt>Último acceso</dt><dd><?= e($lastAccess) ?></dd>
                    </dl>
                    <p class="profile-note mt-3 mb-0">
                        <?= $isSuperadmin
                            ? 'Los datos de la cuenta global se administran desde el propio panel de superadministración.'
                            : 'Si necesita cambiar su nombre, usuario, correo o rol, solicítelo al superadministrador.' ?>
                    </p>
                </div>
            </section>

            <section class="card config-card">
                <div class="card-body">
                    <h2 class="h5 mb-1">Cambiar contraseña</h2>
                    <p class="text-muted">Por seguridad debe confirmar su contraseña actual.</p>
                    <form id="profile-password-form" action="<?= e(url('api/perfil.php?action=password')) ?>" method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                        <div class="mb-3">
                            <label class="form-label required-label" for="current-password">Contraseña actual</label>
                            <input class="form-control" type="password" id="current-password" name="current_password" autocomplete="current-password" maxlength="72" required>
                            <div class="invalid-feedback" data-error-for="current_password"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required-label" for="new-password">Nueva contraseña</label>
                            <input class="form-control" type="password" id="new-password" name="password" autocomplete="new-password" maxlength="72" required>
                            <div class="invalid-feedback" data-error-for="password"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label required-label" for="new-password-confirmation">Confirmar nueva contraseña</label>
                            <input class="form-control" type="password" id="new-password-confirmation" name="password_confirmation" autocomplete="new-password" maxlength="72" required>
                            <div class="invalid-feedback" data-error-for="password_confirmation"></div>
                        </div>
                        <div class="form-text mb-3">Mínimo 10 caracteres, con mayúsculas, minúsculas y números. Debe ser diferente a la actual.</div>
                        <button class="btn btn-primary" type="submit">
                            <span class="button-label">Actualizar contraseña</span>
                            <span class="button-loading d-none">Actualizando…</span>
                        </button>
                    </form>
                </div>
            </section>
        </div>
    </main>
</div>

<div class="toast-container position-fixed bottom-0 end-0 p-3">
    <div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true">
        <div class="toast-body" id="app-toast-message"></div>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
