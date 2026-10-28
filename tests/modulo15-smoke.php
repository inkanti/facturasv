<?php

declare(strict_types=1);

$sessionPath = null;
foreach ([sys_get_temp_dir(), 'C:\\Windows\\Temp', '/tmp'] as $candidate) {
    if (is_dir($candidate) && is_writable($candidate)) {
        $sessionPath = $candidate;
        break;
    }
}
if ($sessionPath !== null) {
    ini_set('session.save_path', $sessionPath);
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/dte-pdf.php';
require_once dirname(__DIR__) . '/lib/ticket.php';
require_once dirname(__DIR__) . '/lib/inventario.php';
require_once dirname(__DIR__) . '/lib/contabilidad.php';

// La prueba no debe usar el SMTP real configurado.
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);

$pdo = db();
$checks = 0;
$tenantId = 1;
$admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
$originalMode = (string) $pdo->query('SELECT modo_facturacion FROM tenant_config WHERE tenant_id = 1')->fetchColumn();
$originalSequence = (int) $pdo->query("SELECT COALESCE(MAX(numero_actual), 0) FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = 'IN'")->fetchColumn();
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$documentIds = [];

function checkModule15(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO M15: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function module15SetMode(PDO $pdo, string $mode): void
{
    $pdo->prepare('UPDATE tenant_config SET modo_facturacion = :mode WHERE tenant_id = 1')
        ->execute(['mode' => $mode]);
}

function module15Payload(string $type, int $productId): array
{
    return [
        'tipo_dte' => $type,
        'cliente_id' => 1,
        'fecha_emision' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'condicion_operacion_id' => 1,
        'forma_pago_id' => 1,
        'dias_credito' => '',
        'emisor_distrito' => '14',
        'receptor_distrito' => '01',
        'aplicar_iva_retenido' => '1',
        'observaciones' => 'Prueba del modo interno (módulo 15)',
        'items' => [[
            'producto_id' => (string) $productId,
            'cantidad' => '2',
            'precio_unitario' => '56.50000000',
            'descuento' => '0',
        ]],
    ];
}

try {
    $pdo->prepare('UPDATE tenant_config SET modo_facturacion = :mode WHERE tenant_id = 1')
        ->execute(['mode' => 'SIMULACION']);
    checkModule15(tenantBillingMode($pdo, $tenantId) === 'SIMULACION', 'el modo por defecto es SIMULACION');
    checkModule15(documentAllTypeDefinitions()['IN']['disponible'] === true, 'el comprobante interno está definido y disponible');
    checkModule15(documentIsInternalType('IN') && !documentIsInternalType('01'), 'el tipo IN se identifica como interno');

    $product = $pdo->query(
        "SELECT id FROM productos WHERE tenant_id = 1 AND control_stock = 1 AND activo = 1 ORDER BY id LIMIT 1"
    )->fetch();
    checkModule15($product !== false, 'existe un producto con control de existencias');
    $productId = (int) $product['id'];
    inventarioEnsureTenant($tenantId);
    $stockBefore = inventarioStockActual($pdo, $tenantId, $productId);

    // En simulación no se puede emitir un comprobante interno.
    $blocked = false;
    try {
        saveDocument($tenantId, (int) $admin['id'], module15Payload('IN', $productId));
    } catch (DocumentValidationException $exception) {
        $blocked = isset($exception->errors['tipo_dte']);
    }
    checkModule15($blocked, 'en modo simulación se rechaza el comprobante interno');

    module15SetMode($pdo, 'INTERNO');
    checkModule15(tenantBillingMode($pdo, $tenantId) === 'INTERNO', 'el modo interno se lee desde tenant_config');

    // En modo interno no se puede emitir un DTE.
    $blockedDte = false;
    try {
        saveDocument($tenantId, (int) $admin['id'], module15Payload('01', $productId));
    } catch (DocumentValidationException $exception) {
        $blockedDte = isset($exception->errors['tipo_dte']);
    }
    checkModule15($blockedDte, 'en modo interno se rechaza un DTE');

    $documentId = saveDocument($tenantId, (int) $admin['id'], module15Payload('IN', $productId));
    $documentIds[] = $documentId;
    checkModule15($documentId > 0, 'el borrador del comprobante interno se guarda');

    $detail = emitDocument($tenantId, $admin, $documentId);
    $document = $detail['document'];
    checkModule15($document['tipo_dte'] === 'IN', 'el documento emitido es de tipo IN');
    checkModule15($document['estado'] === 'PROCESADA', 'el comprobante interno queda procesado sin transmisión');
    checkModule15(preg_match('/^INT-[0-9]{6}$/', (string) $document['numero_control']) === 1, 'la numeración interna usa INT-000001');
    checkModule15($document['codigo_generacion'] === null, 'el comprobante interno no tiene código de generación');
    checkModule15($document['sello_recepcion'] === null, 'el comprobante interno no tiene sello de recepción');
    checkModule15($document['dte'] === null, 'el comprobante interno no guarda JSON DTE');
    checkModule15(abs((float) $document['total_gravada'] - 100.0) < 0.01, 'la venta gravada separa el IVA incluido');
    checkModule15(abs((float) $document['total_iva'] - 13.0) < 0.01, 'el IVA se calcula para control interno');
    checkModule15(abs((float) $document['total_pagar'] - 113.0) < 0.01, 'el total a pagar es correcto');
    checkModule15((float) $document['iva_rete1'] === 0.0, 'el comprobante interno no aplica retención de IVA');

    $stockAfter = inventarioStockActual($pdo, $tenantId, $productId);
    checkModule15(abs(($stockBefore - $stockAfter) - 2) < 0.0001, 'el comprobante interno descuenta inventario');
    checkModule15(inventarioDocumentHasOutflow('IN'), 'el tipo IN está habilitado para salidas de inventario');

    $entry = $pdo->query(
        "SELECT a.id, a.tipo FROM contabilidad_asientos a
         WHERE a.tenant_id = 1 AND a.documento_id = " . (int) $documentId . " LIMIT 1"
    )->fetch();
    checkModule15($entry !== false && $entry['tipo'] === 'VENTA', 'el comprobante interno genera asiento de venta');
    $concept = (string) $pdo->query(
        "SELECT concepto FROM contabilidad_asientos WHERE id = " . (int) $entry['id']
    )->fetchColumn();
    checkModule15(str_contains($concept, 'Comprobante interno'), 'el asiento identifica el comprobante interno');

    $pdf = buildDocumentPdf($tenantId, $documentId);
    checkModule15(str_starts_with($pdf, '%PDF-'), 'el comprobante interno genera un PDF');
    checkModule15(str_contains($pdf, 'COMPROBANTE INTERNO'), 'el PDF se titula comprobante interno');
    checkModule15(str_contains($pdf, 'DOCUMENTO NO FISCAL'), 'el PDF advierte que no es fiscal');
    checkModule15(!str_contains($pdf, 'DOCUMENTO TRIBUTARIO ELECTRÓNICO'), 'el PDF no se presenta como DTE');
    checkModule15(!str_contains($pdf, 'Sello de recepción'), 'el PDF no muestra sello de recepción');

    $ticket = ticketDocumentData($tenantId, $documentId);
    checkModule15($ticket['is_internal'] === true, 'el ticket identifica el comprobante interno');
    checkModule15($ticket['type_name'] === 'Comprobante interno', 'el ticket usa el nombre de comprobante interno');
    checkModule15($ticket['qr_url'] === null && $ticket['qr_data_uri'] === null, 'el comprobante interno no muestra QR fiscal');

    $transmitBlocked = false;
    try {
        transmitDocument($tenantId, $admin, $documentId);
    } catch (RuntimeException $exception) {
        $transmitBlocked = str_contains($exception->getMessage(), 'no se transmite');
    }
    checkModule15($transmitBlocked, 'el comprobante interno no se transmite al MH');

    $invalidated = invalidateInternalDocument($pdo, $tenantId, $admin, $detail['document'], [
        'motivo' => 'Anulación de prueba del comprobante interno',
    ]);
    checkModule15($invalidated['document']['estado'] === 'INVALIDADA', 'el comprobante interno se puede anular');
    checkModule15(abs(inventarioStockActual($pdo, $tenantId, $productId) - $stockBefore) < 0.0001, 'la anulación devuelve el inventario');
    $reversal = (int) $pdo->query(
        "SELECT COUNT(*) FROM contabilidad_asientos
         WHERE tenant_id = 1 AND documento_id = " . (int) $documentId . " AND tipo = 'INVALIDACION'"
    )->fetchColumn();
    checkModule15($reversal === 1, 'la anulación genera el contra-asiento');

    $blockedEmail = false;
    try {
        require_once dirname(__DIR__) . '/lib/correo.php';
        enviarCorreoDocumento($tenantId, $documentId, (int) $admin['id']);
    } catch (Throwable $exception) {
        $blockedEmail = true;
    }
    checkModule15($blockedEmail, 'el modo interno no prepara correo fiscal');

    // Al volver a simulación el historial interno se conserva.
    module15SetMode($pdo, 'SIMULACION');
    checkModule15((string) $pdo->query('SELECT tipo_dte FROM documentos WHERE id = ' . (int) $documentId)->fetchColumn() === 'IN', 'el historial interno se conserva al volver a simulación');

    echo "\nRESULTADO: {$checks} comprobaciones correctas.\n";
} finally {
    foreach ($documentIds as $documentId) {
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $documentId]);
    }
    $pdo->prepare('UPDATE tenant_config SET modo_facturacion = :mode WHERE tenant_id = 1')
        ->execute(['mode' => $originalMode]);
    $pdo->prepare("UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = 'IN'")
        ->execute(['value' => $originalSequence]);
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    $pdo->exec(
        'UPDATE contabilidad_secuencias SET numero_actual = (
            SELECT COALESCE(MAX(numero), 0) FROM contabilidad_asientos WHERE tenant_id = 1
        ) WHERE tenant_id = 1'
    );
    echo "Datos temporales eliminados.\n";
}
