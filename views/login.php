<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

if (currentSuperadmin() !== null) {
    redirect('views/superadmin.php');
}

if (currentUser() !== null) {
    redirect('views/dashboard.php');
}

$pageTitle = 'Iniciar sesión';
$bodyClass = 'auth-page';
$pageScripts = ['assets/js/login.js'];
require __DIR__ . '/partials/head.php';
?>
<main class="auth-shell auth-shell-split">
    <section class="auth-showcase" aria-label="FacturaSV">
        <span class="auth-orb auth-orb-one" aria-hidden="true"></span>
        <span class="auth-orb auth-orb-two" aria-hidden="true"></span>
        <span class="auth-grid" aria-hidden="true"></span>

        <div class="auth-showcase-top">
            <span class="auth-logo" aria-hidden="true">FS</span>
            <div class="auth-showcase-name">
                <strong>FacturaSV</strong>
                <span>Facturación electrónica</span>
            </div>
        </div>

        <div class="auth-showcase-copy">
            <p class="auth-kicker">Hecho para El Salvador</p>
            <p class="auth-display">Facturación electrónica <span>sin fricción.</span></p>
            <p class="auth-lede">Emisión de DTE, contabilidad e inventario en un solo espacio de trabajo. Diseñado para que su equipo avance sin ruido y con control.</p>
            <ul class="auth-benefits">
                <li><span class="auth-benefit-check" aria-hidden="true">✓</span> Los seis tipos de DTE y contingencia</li>
                <li><span class="auth-benefit-check" aria-hidden="true">✓</span> Contabilidad y libros al día</li>
                <li><span class="auth-benefit-check" aria-hidden="true">✓</span> Inventario con kardex y alertas</li>
                <li><span class="auth-benefit-check" aria-hidden="true">✓</span> Multiempresa, usuarios y auditoría</li>
            </ul>
        </div>

        <div class="auth-showcase-bottom">
            <div class="auth-partner">
                <img class="auth-partner-mark" src="<?= e(url('assets/img/bissweb-mark.jpg')) ?>" alt="BissWeb">
                <div class="auth-partner-copy">
                    <span class="auth-partner-label">Una plataforma de</span>
                    <strong>BissWeb</strong>
                    <span class="auth-partner-tagline">Impulso Digital</span>
                </div>
            </div>
            <p class="auth-showcase-note">Facturación · Contabilidad · Inventario</p>
        </div>
    </section>

    <section class="auth-panel" aria-labelledby="login-title">
        <div class="auth-card">
            <div class="auth-card-head">
                <div class="auth-brand">
                    <span class="brand-mark" aria-hidden="true">FS</span>
                    <span>FacturaSV</span>
                </div>
                <span class="auth-secure">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <rect x="5" y="10" width="14" height="10" rx="2.2"></rect>
                        <path d="M8 10V7.8A4 4 0 0 1 16 7.8V10"></path>
                    </svg>
                    Acceso seguro
                </span>
            </div>

            <div class="auth-heading">
                <p class="eyebrow">Bienvenido de nuevo</p>
                <h1 id="login-title">Inicia sesión</h1>
                <p>Use su usuario o correo corporativo para entrar a su espacio de trabajo.</p>
            </div>

            <?php if (isset($_GET['registro']) && $_GET['registro'] === 'deshabilitado'): ?>
            <div class="alert alert-warning" role="status">El registro público está deshabilitado. Las empresas las crea el superadministrador.</div>
            <?php elseif (isset($_GET['registro'])): ?>
            <div class="alert alert-success" role="status">Empresa registrada correctamente. Ya puede iniciar sesión.</div>
            <?php endif; ?>
            <div class="alert alert-danger d-none" id="form-alert" role="alert"></div>

            <form id="login-form" action="<?= e(url('api/auth.php?action=login')) ?>" method="post" novalidate>
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">

                <div class="auth-field">
                    <div class="auth-label-row">
                        <label class="form-label required-label" for="usuario">Usuario o correo</label>
                        <span class="auth-label-hint">Ej. admin o admin@empresa.com</span>
                    </div>
                    <div class="auth-input">
                        <span class="auth-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="8" r="3.2"></circle>
                                <path d="M5 20c.8-3.6 3.5-5.4 7-5.4s6.2 1.8 7 5.4"></path>
                            </svg>
                        </span>
                        <input class="form-control" id="usuario" name="usuario" type="text" maxlength="150"
                               autocomplete="username" placeholder="admin o admin@empresa.com" autofocus required>
                    </div>
                    <div class="invalid-feedback" data-error-for="usuario"></div>
                    <p class="auth-field-hint" id="usuario-hint" aria-live="polite"></p>
                </div>

                <div class="auth-field">
                    <div class="auth-label-row">
                        <label class="form-label required-label" for="password">Contraseña</label>
                        <span class="auth-label-hint">Distingue mayúsculas</span>
                    </div>
                    <div class="auth-input auth-password-input">
                        <span class="auth-input-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="5" y="10" width="14" height="10" rx="2.2"></rect>
                                <path d="M8 10V7.8A4 4 0 0 1 16 7.8V10"></path>
                            </svg>
                        </span>
                        <input class="form-control" id="password" name="password" type="password" maxlength="72"
                               autocomplete="current-password" placeholder="Su contraseña" required>
                        <button class="auth-password-toggle" id="toggle-password" type="button"
                                aria-label="Mostrar contraseña" aria-pressed="false">
                            <svg class="auth-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6S2.5 12 2.5 12Z"></path>
                                <circle cx="12" cy="12" r="2.6"></circle>
                            </svg>
                            <svg class="auth-eye-off d-none" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m3 3 18 18"></path>
                                <path d="M10.6 6.1A10.7 10.7 0 0 1 12 6c6 0 9.5 6 9.5 6a17.4 17.4 0 0 1-3.2 3.8"></path>
                                <path d="M6.2 6.7A17 17 0 0 0 2.5 12s3.5 6 9.5 6c1.8 0 3.3-.5 4.6-1.2"></path>
                                <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
                            </svg>
                        </button>
                    </div>
                    <div class="invalid-feedback" data-error-for="password"></div>
                    <p class="auth-caps d-none" id="caps-hint">Bloq Mayús está activado.</p>
                </div>

                <div class="auth-options">
                    <div class="form-check auth-remember">
                        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1" title="Actívela solo en equipos de confianza">
                        <label class="form-check-label" for="remember">Mantener sesión activa</label>
                    </div>
                    <button class="auth-link" id="auth-help-toggle" type="button" aria-expanded="false" aria-controls="auth-help">¿Problemas para entrar?</button>
                </div>

                <div class="auth-help d-none" id="auth-help">
                    Solicite al administrador de su empresa el restablecimiento de su contraseña. Por seguridad, el sistema no envía contraseñas por correo.
                </div>

                <button class="btn btn-primary w-100 auth-submit" type="submit">
                    <span class="button-label">Entrar a FacturaSV <span class="auth-submit-arrow" aria-hidden="true">→</span></span>
                    <span class="button-loading d-none">Verificando…</span>
                </button>
            </form>

            <div class="auth-divider"><span>Acceso protegido</span></div>
            <div class="auth-trust">
                <span>Sesión cifrada</span>
                <span>Intentos limitados</span>
                <span>Auditoría</span>
            </div>

            <p class="auth-switch">El registro de empresas lo administra el proveedor del servicio.</p>
            <p class="auth-roadmap">Próximamente: magic links, passkeys y SSO corporativo.</p>

            <div class="auth-mobile-partner">
                <img src="<?= e(url('assets/img/bissweb-mark.jpg')) ?>" alt="">
                <span>Desarrollado por <strong>BissWeb</strong></span>
            </div>
        </div>
        <p class="auth-footer">FacturaSV · Plataforma de facturación electrónica multiempresa</p>
    </section>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
