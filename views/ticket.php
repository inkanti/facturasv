<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/ticket.php';

$user = requireAuth();
$tenantId = (int) $user['tenant_id'];
$format = ticketFormat($_GET['formato'] ?? '80');
$documentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$error = null;
$data = null;

if ($documentId === false || $documentId === null) {
    http_response_code(400);
    $error = 'El documento solicitado no es válido.';
} else {
    try {
        $data = ticketDocumentData($tenantId, (int) $documentId);
    } catch (RuntimeException $exception) {
        http_response_code(404);
        $error = $exception->getMessage();
    }
}

$pageTitle = $data === null ? 'Ticket no disponible' : 'Ticket · ' . $data['type_name'];
$bodyClass = 'ticket-page ' . $format['class'];
$pageStyles = ['assets/css/ticket.css'];
if ($format['key'] === '58') {
    $pageStyles[] = 'assets/css/ticket-58.css';
}
$pageScripts = ['assets/js/ticket.js'];
require __DIR__ . '/partials/head.php';
?>
<main class="ticket-shell">
    <?php if ($error !== null): ?>
    <section class="ticket-error" role="alert">
        <p class="ticket-error-kicker">Ticket no disponible</p>
        <h1>No se puede imprimir este ticket</h1>
        <p><?= e($error) ?></p>
        <a class="btn btn-primary" href="<?= e(url('views/lista-documentos.php')) ?>">Volver a documentos</a>
    </section>
    <?php else: ?>
    <div class="ticket-toolbar">
        <div class="ticket-toolbar-title">
            <a href="<?= e(url('views/lista-documentos.php?documento=' . (int) $data['document']['id'])) ?>">Volver</a>
            <strong><?= e($data['type_name']) ?></strong>
            <span><?= e($data['document']['numero_control']) ?></span>
        </div>
        <div class="ticket-toolbar-actions">
            <div class="ticket-format-switch" aria-label="Ancho del ticket">
                <?php foreach (ticketFormats() as $option): ?>
                <a class="<?= $option['key'] === $format['key'] ? 'active' : '' ?>"
                   href="<?= e(url('views/ticket.php?id=' . (int) $data['document']['id'] . '&formato=' . $option['key'])) ?>"
                   title="<?= e($option['description']) ?>"><?= e($option['label']) ?></a>
                <?php endforeach; ?>
            </div>
            <button class="btn btn-primary btn-sm" type="button" id="ticket-print">Imprimir</button>
        </div>
    </div>

    <article class="ticket-sheet" aria-label="<?= e($data['ticket_title'] . ' de ' . $data['type_name']) ?>">
        <?php if ($data['is_invalidated']): ?>
        <div class="ticket-flag ticket-flag-danger"><?= $data['is_internal'] ? 'COMPROBANTE ANULADO' : 'DOCUMENTO INVALIDADO' ?></div>
        <?php endif; ?>
        <?php if ($data['is_internal']): ?>
        <div class="ticket-flag">COMPROBANTE INTERNO · NO FISCAL</div>
        <?php endif; ?>
        <?php if ($data['is_contingency']): ?>
        <div class="ticket-flag">EMITIDO EN CONTINGENCIA</div>
        <?php endif; ?>

        <header class="ticket-header">
            <?php if ($data['logo_url'] !== ''): ?>
            <img class="ticket-logo" src="<?= e($data['logo_url']) ?>" alt="Logo de <?= e($data['tenant']['nombre_comercial']) ?>">
            <?php endif; ?>
            <div class="ticket-company-name"><?= e($data['tenant']['nombre_comercial'] ?: $data['tenant']['razon_social']) ?></div>
            <?php if (!empty($data['tenant']['razon_social']) && $data['tenant']['razon_social'] !== $data['tenant']['nombre_comercial']): ?>
            <div class="ticket-company-legal"><?= e($data['tenant']['razon_social']) ?></div>
            <?php endif; ?>
            <div class="ticket-company-meta">
                <span>NIT: <?= e($data['tenant']['nit']) ?></span>
                <?php if (!empty($data['tenant']['numero_registro_mh'])): ?><span>NRC: <?= e($data['tenant']['numero_registro_mh']) ?></span><?php endif; ?>
            </div>
            <div class="ticket-company-meta ticket-company-address"><?= e($data['tenant']['direccion']) ?></div>
            <div class="ticket-company-meta">
                <?php if (!empty($data['tenant']['telefono'])): ?><span>Tel. <?= e($data['tenant']['telefono']) ?></span><?php endif; ?>
                <?php if (!empty($data['tenant']['email'])): ?><span><?= e($data['tenant']['email']) ?></span><?php endif; ?>
            </div>
        </header>

        <div class="ticket-separator"></div>

        <section class="ticket-document">
            <div class="ticket-doc-kicker"><?= e($data['ticket_title']) ?></div>
            <h1 class="ticket-doc-type"><?= e(mb_strtoupper($data['type_name'])) ?></h1>
            <div class="ticket-doc-number">No. <?= e($data['document']['numero_control']) ?></div>
            <div class="ticket-doc-date">
                <?= e(date('d/m/Y', strtotime((string) $data['document']['fecha_emision']))) ?>
                <?= e($data['document']['hora_emision'] ? ' · ' . $data['document']['hora_emision'] : '') ?>
            </div>
        </section>

        <div class="ticket-separator"></div>

        <section class="ticket-client" aria-label="Datos del cliente">
            <div class="ticket-row"><span>Cliente</span><strong><?= e($data['document']['cliente_nombre']) ?></strong></div>
            <div class="ticket-row"><span>Documento</span><span><?= e($data['client_document']) ?></span></div>
            <div class="ticket-row"><span>Condición</span><span><?= e($data['document']['condicion_nombre']) ?><?= (int) $data['document']['dias_credito'] > 0 ? ' · ' . (int) $data['document']['dias_credito'] . ' días' : '' ?></span></div>
            <div class="ticket-row"><span>Forma de pago</span><span><?= e($data['document']['pago_nombre']) ?></span></div>
        </section>

        <div class="ticket-separator"></div>

        <section class="ticket-items" aria-label="Detalle de productos o servicios">
            <?php foreach ($data['items'] as $item): ?>
            <div class="ticket-item">
                <div class="ticket-item-line">
                    <span class="ticket-item-name"><?= e($item['descripcion']) ?></span>
                    <strong><?= e(ticketMoney(ticketLineTotal($item, $data['type_code']))) ?></strong>
                </div>
                <div class="ticket-item-meta">
                    <span><?= e(ticketQuantity($item['cantidad'])) ?> x <?= e(ticketMoney($item['precio_unitario'])) ?></span>
                    <?php if ((float) $item['descuento'] > 0): ?><span>Desc. <?= e(ticketMoney($item['descuento'])) ?></span><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>

        <div class="ticket-separator"></div>

        <section class="ticket-totals" aria-label="Totales">
            <div class="ticket-total-row"><span>Subtotal</span><span><?= e(ticketMoney($data['subtotal'])) ?></span></div>
            <?php if ((float) $data['document']['total_descuento'] > 0): ?>
            <div class="ticket-total-row"><span>Descuento</span><span><?= e(ticketMoney($data['document']['total_descuento'])) ?></span></div>
            <?php endif; ?>
            <?php if ((float) $data['document']['total_iva'] > 0): ?>
            <div class="ticket-total-row"><span>IVA</span><span><?= e(ticketMoney($data['document']['total_iva'])) ?></span></div>
            <?php endif; ?>
            <?php if ($data['retention'] > 0): ?>
            <div class="ticket-total-row"><span>Retención</span><span><?= e(ticketMoney($data['retention'])) ?></span></div>
            <?php endif; ?>
            <div class="ticket-total-row ticket-grand-total"><span>Total</span><strong><?= e(ticketMoney($data['document']['total_pagar'])) ?></strong></div>
        </section>

        <?php if (!empty($data['qr_data_uri'])): ?>
        <div class="ticket-separator"></div>
        <section class="ticket-qr" aria-label="Código QR del documento">
            <img src="<?= e($data['qr_data_uri']) ?>" alt="Código QR para consultar el DTE" width="180" height="180">
            <p>Escanee para consultar el DTE en Hacienda</p>
        </section>
        <?php endif; ?>

        <div class="ticket-separator"></div>

        <footer class="ticket-footer">
            <p class="ticket-thanks"><?= $data['is_internal'] ? 'Comprobante de control interno' : (in_array($data['type_code'], ['05', '06'], true) ? 'Documento de nota' : 'Gracias por su compra') ?></p>
            <?php if (!empty($data['document']['codigo_generacion'])): ?>
            <div class="ticket-code"><span>Código de generación</span><code><?= e($data['document']['codigo_generacion']) ?></code></div>
            <?php endif; ?>
            <?php if (!empty($data['document']['sello_recepcion'])): ?>
            <div class="ticket-code"><span>Sello de recepción</span><code><?= e($data['document']['sello_recepcion']) ?></code></div>
            <?php endif; ?>
            <p class="ticket-disclaimer"><?= $data['is_internal']
                ? 'Comprobante interno sin validez fiscal. No es una factura ni sustituye un DTE.'
                : 'Documento generado en modo simulación. Sin validez fiscal.' ?></p>
            <p class="ticket-brand">FacturaSV</p>
        </footer>
    </article>
    <?php endif; ?>
</main>
<?php require __DIR__ . '/partials/footer.php'; ?>
