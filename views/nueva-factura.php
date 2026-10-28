<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/contingencia.php';
$user = requireAuth();
requireRole($user, 'ADMIN', 'VENDEDOR');
$options = documentFormOptions((int) $user['tenant_id']);
$today = (new DateTimeImmutable('now', new DateTimeZone($user['timezone'])))->format('Y-m-d');
$billingMode = (string) ($options['modo_facturacion'] ?? 'SIMULACION');
$internalMode = $billingMode === 'INTERNO';

$pageTitle = 'Nuevo documento';
$bodyClass = 'app-page';
$activePage = 'nueva-factura';
$pageScripts = ['assets/js/nueva-factura.js'];
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

    <main class="app-main document-editor" id="document-editor"
          data-api="<?= e(url('api/documentos.php')) ?>" data-mh-api="<?= e(url('api/mh.php')) ?>"
          data-list-url="<?= e(url('views/lista-documentos.php')) ?>"
          data-billing-mode="<?= e($billingMode) ?>"
          data-edit-id="<?= e((string) ($_GET['id'] ?? '')) ?>">
        <div class="page-heading">
            <div>
                <p class="eyebrow"><?= $internalMode ? 'Módulo 15 · Comprobante interno' : 'Módulos 5, 9 y 10 · Emisión DTE' ?></p>
                <h1 id="document-form-title"><?= $internalMode ? 'Nuevo comprobante interno' : 'Nuevo documento' ?></h1>
                <p><?= $internalMode
                    ? 'Registre la venta para control interno. No se genera DTE, sello ni código de generación.'
                    : 'Prepare el documento, revise sus totales y transmítalo al entorno de simulación.' ?></p>
            </div>
            <span class="simulation-badge align-self-start"><?= $internalMode ? 'MODO INTERNO · NO FISCAL' : 'MH · SIMULACIÓN' ?></span>
        </div>

        <div class="alert alert-info document-mode-note">
            <?php if ($internalMode): ?>
            Esta empresa está en <strong>modo interno</strong>: el comprobante conserva clientes, productos, inventario, contabilidad y ticket, pero <strong>no es una factura válida</strong> ni se envía a Hacienda.
            <?php else: ?>
            Factura (01), Crédito Fiscal (03), Nota de Crédito (05), Nota de Débito (06), Factura de Exportación (11) y Factura de Sujeto Excluido (14) están habilitados con sus esquemas oficiales.
            <?php endif; ?>
        </div>
        <div class="alert alert-danger d-none" id="document-alert" role="alert"></div>

        <form id="document-form">
            <input type="hidden" name="id" id="document-id">
            <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
            <input type="hidden" name="aplicar_iva_retenido" value="0">
            <p class="required-note"><span class="required-marker">*</span> Campos obligatorios</p>

            <section class="card document-section">
                <div class="card-body">
                    <div class="document-section-heading"><span>1</span><div><h2>Datos generales</h2><p>Identificación del comprobante y receptor.</p></div></div>
                    <div class="row g-3">
                        <div class="col-lg-4 col-md-6">
                            <label class="form-label required-label" for="document-type">Tipo de documento</label>
                            <select class="form-select" name="tipo_dte" id="document-type" required>
                                <?php foreach ($options['tipos_documento'] as $code => $type): ?>
                                <?php if ($internalMode !== ($code === 'IN')) continue; ?>
                                <option value="<?= e($code) ?>" <?= $type['disponible'] ? '' : 'disabled' ?>><?= e($type['nombre'] . ' (' . $code . ')' . ($type['disponible'] ? '' : ' — pendiente')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_dte"></div>
                        </div>
                        <div class="col-lg-5 col-md-6">
                            <label class="form-label required-label" for="document-client">Cliente</label>
                            <select class="form-select" name="cliente_id" id="document-client" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($options['clientes'] as $client): ?>
                                <option value="<?= e($client['id']) ?>" data-document-type="<?= e($client['tipo_documento']) ?>" data-email="<?= e($client['email'] ?? '') ?>">
                                    <?= e($client['nombre'] . ' · ' . ($client['numero_documento'] ?? 'Sin documento')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="cliente_id"></div>
                            <div class="form-text" id="client-document-help">Crédito Fiscal requiere NIT y correo electrónico.</div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label required-label" for="document-date">Fecha de emisión</label>
                            <input class="form-control" type="date" name="fecha_emision" id="document-date" value="<?= e($today) ?>" required>
                            <div class="invalid-feedback" data-error-for="fecha_emision"></div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label required-label" for="document-condition">Condición</label>
                            <select class="form-select" name="condicion_operacion_id" id="document-condition" required>
                                <?php foreach ($options['condiciones'] as $condition): ?>
                                <option value="<?= e($condition['id']) ?>" data-code="<?= e($condition['codigo']) ?>"><?= e($condition['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="condicion_operacion_id"></div>
                        </div>
                        <div class="col-lg-3 col-md-6 d-none" id="credit-days-group">
                            <label class="form-label required-label" for="credit-days">Días de crédito</label>
                            <input class="form-control" type="number" name="dias_credito" id="credit-days" min="1" max="3650" step="1" value="30" disabled>
                            <div class="invalid-feedback" data-error-for="dias_credito"></div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label required-label" for="document-payment">Forma de pago</label>
                            <select class="form-select" name="forma_pago_id" id="document-payment" required>
                                <option value="">Seleccione…</option>
                                <?php foreach ($options['formas_pago'] as $payment): ?>
                                <option value="<?= e($payment['id']) ?>"><?= e($payment['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="forma_pago_id"></div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label required-label" for="issuer-district">Distrito emisor</label>
                            <input class="form-control" name="emisor_distrito" id="issuer-district" value="<?= e($options['empresa']['distrito_emisor']) ?>" pattern="[0-9]{2}" maxlength="2" inputmode="numeric" required>
                            <div class="invalid-feedback" data-error-for="emisor_distrito"></div>
                        </div>
                        <div class="col-lg-3 col-md-6">
                            <label class="form-label required-label" for="receiver-district">Distrito receptor</label>
                            <input class="form-control" name="receptor_distrito" id="receiver-district" value="01" pattern="[0-9]{2}" maxlength="2" inputmode="numeric" required>
                            <div class="invalid-feedback" data-error-for="receptor_distrito"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card document-section d-none" id="related-document-section">
                <div class="card-body">
                    <div class="document-section-heading"><span>R</span><div><h2>Documento relacionado</h2><p>La nota se emite contra una Factura o un Crédito Fiscal procesado del mismo cliente.</p></div></div>
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label required-label" for="related-document">Documento relacionado</label>
                            <select class="form-select" name="documento_relacionado_id" id="related-document">
                                <option value="">Seleccione un documento procesado…</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="documento_relacionado_id"></div>
                            <div class="form-text" id="related-document-help">Solo aparecen Facturas y Créditos Fiscales procesados del cliente seleccionado.</div>
                        </div>
                        <div class="col-md-5">
                            <div class="alert alert-info mb-0" id="related-document-info">Seleccione el cliente y el documento relacionado.</div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card document-section d-none" id="export-section">
                <div class="card-body">
                    <div class="document-section-heading"><span>E</span><div><h2>Datos de exportación</h2><p>Receptor extranjero y condiciones de la operación de exportación.</p></div></div>
                    <div class="row g-3">
                        <div class="col-md-3 col-lg-2">
                            <label class="form-label required-label" for="export-cod-pais">Código país</label>
                            <input class="form-control" type="text" name="export_cod_pais" id="export-cod-pais" maxlength="2" placeholder="US" required>
                            <div class="invalid-feedback" data-error-for="export_cod_pais"></div>
                        </div>
                        <div class="col-md-5 col-lg-4">
                            <label class="form-label required-label" for="export-nombre-pais">Nombre del país</label>
                            <input class="form-control" type="text" name="export_nombre_pais" id="export-nombre-pais" maxlength="50" placeholder="Estados Unidos" required>
                            <div class="invalid-feedback" data-error-for="export_nombre_pais"></div>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label required-label" for="export-tipo-persona">Tipo de persona</label>
                            <select class="form-select" name="export_tipo_persona" id="export-tipo-persona" required>
                                <option value="1">Persona jurídica</option>
                                <option value="2">Persona natural</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="export_tipo_persona"></div>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label required-label" for="export-tipo-item">Tipo de exportación</label>
                            <select class="form-select" name="export_tipo_item" id="export-tipo-item" required>
                                <option value="1">Bienes</option>
                                <option value="2">Servicios</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="export_tipo_item"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="export-incoterms">Incoterm</label>
                            <select class="form-select" name="export_incoterms" id="export-incoterms">
                                <option value="">Sin incoterm</option>
                                <option value="01">01 · FOB</option>
                                <option value="02">02 · CIF</option>
                                <option value="03">03 · EXW</option>
                            </select>
                            <div class="invalid-feedback" data-error-for="export_incoterms"></div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="export-desc-incoterms">Descripción del incoterm</label>
                            <input class="form-control" type="text" name="export_desc_incoterms" id="export-desc-incoterms" maxlength="150" placeholder="Libre a bordo">
                            <div class="invalid-feedback" data-error-for="export_desc_incoterms"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="export-flete">Flete</label>
                            <input class="form-control" type="number" name="export_flete" id="export-flete" min="0" step="0.01" value="0">
                            <div class="invalid-feedback" data-error-for="export_flete"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="export-seguro">Seguro</label>
                            <input class="form-control" type="number" name="export_seguro" id="export-seguro" min="0" step="0.01" value="0">
                            <div class="invalid-feedback" data-error-for="export_seguro"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="export-no-onerosas">Muestras no onerosas</label>
                            <input class="form-control" type="number" name="export_no_onerosas" id="export-no-onerosas" min="0" step="0.01" value="0">
                            <div class="invalid-feedback" data-error-for="export_no_onerosas"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label required-label" for="export-complemento">Complemento de dirección del receptor</label>
                            <input class="form-control" type="text" name="export_complemento" id="export-complemento" maxlength="200" placeholder="Ciudad, estado, dirección" required>
                            <div class="invalid-feedback" data-error-for="export_complemento"></div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card document-section">
                <div class="card-body">
                    <div class="document-section-heading items-heading">
                        <span>2</span><div><h2>Productos y servicios</h2><p id="price-mode-help">En Factura los precios incluyen IVA.</p></div>
                        <button class="btn btn-outline-primary" id="add-document-item" type="button">Agregar línea</button>
                    </div>
                    <div class="barcode-scan">
                        <label class="form-label" for="barcode-input">Escanear código de barras</label>
                        <div class="barcode-scan-row">
                            <input class="form-control" id="barcode-input" type="text" maxlength="64"
                                   autocomplete="off" spellcheck="false" placeholder="Escanee o escriba el código y presione Enter">
                            <button class="btn btn-outline-primary" id="barcode-submit" type="button">Agregar</button>
                        </div>
                        <div class="barcode-feedback" id="barcode-feedback" role="status" aria-live="polite"></div>
                    </div>
                    <div class="alert alert-danger d-none" id="items-error" role="alert"></div>
                    <div class="table-responsive">
                        <table class="table document-items-table align-middle">
                            <thead><tr><th>Producto o servicio</th><th>Cantidad</th><th>Precio USD</th><th>Descuento</th><th>IVA</th><th>Total</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
                            <tbody id="document-items-body"></tbody>
                        </table>
                    </div>
                    <div class="document-empty d-none" id="items-empty">Agregue al menos un producto o servicio.</div>
                </div>
            </section>

            <div class="document-bottom-grid">
                <section class="card document-section">
                    <div class="card-body">
                        <div class="document-section-heading"><span>3</span><div><h2>Condiciones adicionales</h2><p>Retención y observaciones opcionales.</p></div></div>
                        <div id="withhold-iva-group">
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="aplicar_iva_retenido" id="withhold-iva" value="1">
                                <label class="form-check-label" for="withhold-iva">Aplicar IVA retenido del 1 % sobre ventas gravadas</label>
                            </div>
                        </div>
                        <div class="d-none" id="excluded-fields">
                            <label class="form-label" for="rete-renta">Retención de renta</label>
                            <input class="form-control" type="number" name="rete_renta" id="rete-renta" min="0" step="0.01" value="0">
                            <div class="invalid-feedback" data-error-for="rete_renta"></div>
                            <div class="form-text">Se descuenta del subtotal de la compra. Use 0 si no aplica.</div>
                        </div>
                        <label class="form-label" for="document-notes">Observaciones</label>
                        <textarea class="form-control" name="observaciones" id="document-notes" rows="5" maxlength="3000" placeholder="Información complementaria del documento"></textarea>
                    </div>
                </section>

                <aside class="card document-summary">
                    <div class="card-body">
                        <span class="info-label">Resumen del documento</span>
                        <dl>
                            <div id="summary-taxed-row"><dt id="summary-taxed-label">Venta gravada</dt><dd id="summary-taxed">$ 0.00</dd></div>
                            <div id="summary-exempt-row"><dt>Venta exenta</dt><dd id="summary-exempt">$ 0.00</dd></div>
                            <div id="summary-nonsubject-row"><dt>Venta no sujeta</dt><dd id="summary-nonsubject">$ 0.00</dd></div>
                            <div><dt>Descuentos</dt><dd id="summary-discount">$ 0.00</dd></div>
                            <div id="summary-iva-row"><dt>IVA 13 %</dt><dd id="summary-iva">$ 0.00</dd></div>
                            <div id="summary-withheld-row"><dt>IVA retenido 1 %</dt><dd id="summary-withheld">− $ 0.00</dd></div>
                            <div class="d-none" id="summary-rete-row"><dt>Retención renta</dt><dd id="summary-rete">− $ 0.00</dd></div>
                            <div class="d-none" id="summary-export-row"><dt>Flete + seguro</dt><dd id="summary-export">$ 0.00</dd></div>
                            <div class="summary-total"><dt>Total a pagar</dt><dd id="summary-total">$ 0.00</dd></div>
                        </dl>
                        <p id="summary-words">CERO CON 00/100 USD</p>
                    </div>
                </aside>
            </div>

            <section class="card document-section <?= $internalMode ? 'd-none' : '' ?>" id="contingency-section">
                <div class="card-body">
                    <div class="document-section-heading"><span>4</span><div><h2>Contingencia</h2><p>Emita el DTE sin transmitirlo y declárelo después en un evento.</p></div></div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="contingencia" id="contingency-check" value="1">
                        <label class="form-check-label" for="contingency-check">Emitir en contingencia (no se transmite al MH)</label>
                    </div>
                    <div id="contingency-fields" class="row g-3 d-none">
                        <div class="col-md-5">
                            <label class="form-label" for="contingency-type">Tipo de contingencia</label>
                            <select class="form-select" name="tipo_contingencia" id="contingency-type" disabled>
                                <?php foreach (contingencyTypes() as $code => $label): ?>
                                <option value="<?= e((string) $code) ?>"><?= e($code . ' · ' . $label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" data-error-for="tipo_contingencia"></div>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label" for="contingency-reason">Motivo</label>
                            <textarea class="form-control" name="motivo_contin" id="contingency-reason" rows="3" maxlength="500" disabled placeholder="Detalle el motivo de la contingencia"></textarea>
                            <div class="invalid-feedback" data-error-for="motivo_contin"></div>
                            <div class="form-text" id="contingency-reason-help">Obligatorio para el tipo 5 (Otro). Máximo 500 caracteres.</div>
                        </div>
                    </div>
                    <div class="alert alert-warning mt-3 mb-0 d-none" id="contingency-note">
                        El documento quedará emitido en contingencia, sin sello. Declare el evento en la pantalla Contingencia para transmitirlo al MH.
                    </div>
                </div>
            </section>

            <div class="document-form-actions">
                <a class="btn btn-outline-secondary" href="<?= e(url('views/lista-documentos.php')) ?>">Cancelar</a>
                <div>
                    <button class="btn btn-outline-primary" id="save-draft" type="button"><span class="button-label">Guardar borrador</span><span class="button-loading d-none">Guardando…</span></button>
                    <button class="btn btn-primary" id="emit-document" type="button"><span class="button-label"><?= $internalMode ? 'Emitir comprobante' : 'Emitir y transmitir' ?></span><span class="button-loading d-none">Procesando…</span></button>
                </div>
            </div>
        </form>
    </main>
</div>
<div class="toast-container position-fixed bottom-0 end-0 p-3"><div id="app-toast" class="toast" role="status" aria-live="polite" aria-atomic="true"><div class="toast-body" id="app-toast-message"></div></div></div>
<?php require __DIR__ . '/partials/footer.php'; ?>
