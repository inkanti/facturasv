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
require_once dirname(__DIR__) . '/lib/inventario.php';
require_once dirname(__DIR__) . '/lib/documentos.php';

// La prueba de integración no debe usar el SMTP real configurado.
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);

$pdo = db();
$checks = 0;
$tenantId = 1;
$admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$movementStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM inventario_movimientos')->fetchColumn();
$documentIds = [];
$originalDocSequences = [];

function checkModule14(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO M14: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function module14Date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');
}

function module14Stock(PDO $pdo, int $productId): float
{
    return inventarioStockActual($pdo, 1, $productId);
}

function module14Products(PDO $pdo): array
{
    $products = [];
    foreach ($pdo->query("SELECT id, codigo, control_stock FROM productos WHERE tenant_id = 1")->fetchAll() as $row) {
        $products[(string) $row['codigo']] = $row;
    }
    return $products;
}

function module14Emit(PDO $pdo, array $admin, int $productId, string $quantity): array
{
    global $documentIds;
    $today = module14Date();
    $id = saveDocument(1, (int) $admin['id'], [
        'tipo_dte' => '01', 'cliente_id' => 1, 'fecha_emision' => $today,
        'condicion_operacion_id' => 1, 'forma_pago_id' => 1, 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Prueba de inventario del módulo 14',
        'items' => [[
            'producto_id' => (string) $productId, 'cantidad' => $quantity,
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ]);
    $documentIds[] = $id;
    return ['id' => $id, 'detail' => emitDocument(1, $admin, $id)];
}

function module14DeleteDocument(PDO $pdo, int $documentId): void
{
    $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')
        ->execute(['id' => $documentId]);
    $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $documentId]);
}

try {
    inventarioEnsureTenant($tenantId);
    $config = inventarioConfig($tenantId);
    $originalDocSequences = $pdo->query(
        'SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    checkModule14(inventarioDate($config['fecha_inicio']) !== null, 'existe la fecha de inicio del inventario');
    $products = module14Products($pdo);
    checkModule14(isset($products['PROD-001'], $products['PROD-002']), 'existen productos con control de existencias');
    checkModule14((int) $products['PROD-001']['control_stock'] === 1, 'el producto demo tiene control de existencias');

    $productId = (int) $products['PROD-001']['id'];
    $baseline = module14Stock($pdo, $productId);
    checkModule14($baseline >= 0, 'la existencia base del producto es válida');
    checkModule14(inventarioPendingDocuments($tenantId) === 0, 'no hay documentos pendientes de conciliar al iniciar');

    $stock = inventarioExistencias($tenantId);
    checkModule14(count($stock['items']) >= 2, 'la lista de existencias incluye los productos controlados');
    $today = module14Date();
    $monthStart = substr($today, 0, 8) . '01';

    $entry = inventarioCreateMovement($admin, [
        'producto_id' => $productId, 'fecha' => $today, 'tipo' => 'ENTRADA',
        'cantidad' => '5', 'motivo' => 'Entrada temporal de prueba',
    ]);
    checkModule14(abs($entry['stock_actual'] - ($baseline + 5)) < 0.0001, 'una entrada manual aumenta la existencia');
    $exit = inventarioCreateMovement($admin, [
        'producto_id' => $productId, 'fecha' => $today, 'tipo' => 'SALIDA',
        'cantidad' => '2', 'motivo' => 'Salida temporal de prueba',
    ]);
    checkModule14(abs($exit['stock_actual'] - ($baseline + 3)) < 0.0001, 'una salida manual disminuye la existencia');
    $adjust = inventarioCreateMovement($admin, [
        'producto_id' => $productId, 'fecha' => $today, 'tipo' => 'AJUSTE_NEGATIVO',
        'cantidad' => '1', 'motivo' => 'Ajuste temporal de prueba',
    ]);
    checkModule14(abs($adjust['stock_actual'] - ($baseline + 2)) < 0.0001, 'un ajuste negativo descuenta existencias');

    try {
        inventarioCreateMovement($admin, [
            'producto_id' => $productId, 'fecha' => $today, 'tipo' => 'SALIDA',
            'cantidad' => (string) ($baseline + 100), 'motivo' => 'Salida inválida de prueba',
        ]);
        throw new RuntimeException('Se aceptó una salida manual mayor que la existencia.');
    } catch (InventarioValidationException $exception) {
        checkModule14(isset($exception->errors['cantidad']), 'se rechaza una salida manual mayor que la existencia');
    }

    $kardex = inventarioKardex($tenantId, $productId, '2026-01-01', $today);
    checkModule14(abs($kardex['saldo_inicial'] - $baseline) < 0.0001, 'el kardex inicia con la existencia base');
    checkModule14(abs($kardex['saldo_final'] - ($baseline + 2)) < 0.0001, 'el kardex calcula el saldo final');
    checkModule14(count($kardex['rows']) >= 3, 'el kardex muestra los movimientos manuales');

    $list = inventarioMovements($tenantId, [
        'fecha_desde' => $monthStart, 'fecha_hasta' => $today, 'producto_id' => $productId,
    ], 1, 50);
    checkModule14($list['pagination']['total'] >= 3, 'el listado de movimientos incluye los movimientos manuales');
    checkModule14($list['total_entradas'] >= 5 && $list['total_salidas'] >= 3, 'el listado suma entradas y salidas');

    // Se retiran los movimientos manuales antes de probar las ventas automáticas.
    $pdo->exec('DELETE FROM inventario_movimientos WHERE id > ' . $movementStart);
    checkModule14(abs(module14Stock($pdo, $productId) - $baseline) < 0.0001, 'al eliminar los movimientos manuales vuelve la existencia base');

    $sale = module14Emit($pdo, $admin, $productId, '2');
    checkModule14($sale['detail']['document']['estado'] === 'PROCESADA', 'la venta de prueba se procesa');
    checkModule14(abs(module14Stock($pdo, $productId) - ($baseline - 2)) < 0.0001, 'la venta descuenta automáticamente 2 unidades');
    $auto = $pdo->query(
        'SELECT tipo, cantidad FROM inventario_movimientos WHERE tenant_id = 1 AND documento_id = ' . (int) $sale['id']
    )->fetchAll();
    checkModule14(count($auto) === 1 && $auto[0]['tipo'] === 'SALIDA' && abs((float) $auto[0]['cantidad'] - 2) < 0.0001, 'la venta genera un movimiento de salida');
    $again = inventarioRegistrarSalidaDocumento($tenantId, (int) $sale['id'], (int) $admin['id']);
    checkModule14($again === 0, 'la salida automática es idempotente');

    $invalid = invalidateDocument($tenantId, $admin, (int) $sale['id'], [
        'tipo_anulacion' => '1', 'motivo' => 'Prueba de inventario con invalidación',
        'tipo_documento_responsable' => '13', 'numero_documento_responsable' => '12345678-9',
    ]);
    checkModule14($invalid['document']['estado'] === 'INVALIDADA', 'la venta de prueba se invalida');
    checkModule14(abs(module14Stock($pdo, $productId) - $baseline) < 0.0001, 'la invalidación devuelve las 2 unidades');
    $reversal = $pdo->query(
        "SELECT COUNT(*) FROM inventario_movimientos WHERE tenant_id = 1 AND documento_id = " . (int) $sale['id']
        . " AND tipo = 'ENTRADA'"
    )->fetchColumn();
    checkModule14((int) $reversal === 1, 'la invalidación genera el movimiento inverso');

    module14DeleteDocument($pdo, (int) $sale['id']);
    checkModule14((int) $pdo->query(
        'SELECT COUNT(*) FROM inventario_movimientos WHERE documento_id = ' . (int) $sale['id']
    )->fetchColumn() === 0, 'al eliminar el documento se eliminan sus movimientos');
    checkModule14(abs(module14Stock($pdo, $productId) - $baseline) < 0.0001, 'la existencia vuelve a la base tras eliminar el documento');

    // Una venta sin existencias no bloquea el DTE y deja stock negativo.
    $negativeSale = module14Emit($pdo, $admin, $productId, (string) ($baseline + 5));
    checkModule14($negativeSale['detail']['document']['estado'] === 'PROCESADA', 'la venta sin existencias se procesa');
    checkModule14(module14Stock($pdo, $productId) < 0, 'la venta sin existencias deja stock negativo');
    $negativeStock = inventarioExistencias($tenantId, ['estado' => 'NEGATIVO']);
    checkModule14($negativeStock['negativos'] >= 1, 'la lista marca el producto con stock negativo');
    invalidateDocument($tenantId, $admin, (int) $negativeSale['id'], [
        'tipo_anulacion' => '1', 'motivo' => 'Prueba de stock negativo con invalidación',
        'tipo_documento_responsable' => '13', 'numero_documento_responsable' => '12345678-9',
    ]);
    checkModule14(abs(module14Stock($pdo, $productId) - $baseline) < 0.0001, 'la invalidación restaura el stock negativo');
    module14DeleteDocument($pdo, (int) $negativeSale['id']);

    // Reconciliación: si falta un movimiento, se puede regenerar desde los DTE.
    $repairSale = module14Emit($pdo, $admin, $productId, '1');
    $pdo->exec(
        'DELETE FROM inventario_movimientos WHERE tenant_id = 1 AND documento_id = ' . (int) $repairSale['id']
    );
    checkModule14(abs(module14Stock($pdo, $productId) - $baseline) < 0.0001, 'al borrar el movimiento la existencia vuelve a la base');
    $sync = inventarioSincronizar($tenantId, (int) $admin['id']);
    checkModule14($sync['movimientos'] >= 1, 'la reconciliación regenera el movimiento de la venta');
    checkModule14(abs(module14Stock($pdo, $productId) - ($baseline - 1)) < 0.0001, 'la reconciliación vuelve a descontar la venta');
    module14DeleteDocument($pdo, (int) $repairSale['id']);
    checkModule14(abs(module14Stock($pdo, $productId) - $baseline) < 0.0001, 'la existencia queda en la base al eliminar la venta de reparación');

    $csvStock = inventarioCsv($tenantId, 'existencias', []);
    checkModule14(count($csvStock['headers']) === 6, 'las existencias se exportan a CSV');
    $csvMovements = inventarioCsv($tenantId, 'movimientos', ['fecha_desde' => $monthStart, 'fecha_hasta' => $today]);
    checkModule14(count($csvMovements['headers']) === 8, 'los movimientos se exportan a CSV');
    $csvKardex = inventarioCsv($tenantId, 'kardex', [
        'producto_id' => $productId, 'fecha_desde' => $monthStart, 'fecha_hasta' => $today,
    ]);
    checkModule14(count($csvKardex['headers']) === 6, 'el kardex se exporta a CSV');

    $audit = (int) $pdo->query('SELECT COUNT(*) FROM configuracion_cambios WHERE id > ' . $auditStart)->fetchColumn();
    checkModule14($audit >= 1, 'los movimientos manuales quedan auditados');

    echo "RESULTADO: {$checks} comprobaciones correctas.\n";
} finally {
    foreach ($documentIds as $documentId) {
        module14DeleteDocument($pdo, (int) $documentId);
    }
    $pdo->exec('DELETE FROM inventario_movimientos WHERE id > ' . $movementStart);
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    if ($originalDocSequences !== []) {
        $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
        foreach ($originalDocSequences as $type => $value) {
            $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
        }
    }
    $pdo->exec(
        'UPDATE contabilidad_secuencias SET numero_actual = (
            SELECT COALESCE(MAX(numero), 0) FROM contabilidad_asientos WHERE tenant_id = 1
        ) WHERE tenant_id = 1'
    );
    echo "Datos temporales eliminados.\n";
}
