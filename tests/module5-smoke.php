<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';

$pdo = db();
// Las pruebas no deben usar el SMTP real configurado: fuerzan el transporte simulado.
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);
$tenantId = 1;
$documentIds = [];
$assertions = 0;
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();

function checkModule5(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function module5Input(int $type, int $clientId, int $conditionId, int $paymentId): array
{
    return [
        'tipo_dte' => $type === 1 ? '01' : '03', 'cliente_id' => $clientId,
        'fecha_emision' => '2026-10-26', 'condicion_operacion_id' => $conditionId,
        'forma_pago_id' => $paymentId, 'dias_credito' => $conditionId === 2 ? '30' : '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '1',
        'observaciones' => 'Prueba automatizada del módulo 5',
        'items' => [[
            'producto_id' => '1', 'cantidad' => $type === 1 ? '1' : '1',
            'precio_unitario' => $type === 1 ? '113.00000000' : '100.00000000', 'descuento' => '0',
        ]],
    ];
}

try {
    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    checkModule5(is_array($admin), 'existe el administrador de pruebas');

    // La prueba convive con documentos existentes: calcula el correlativo
    // esperado y restaura los valores originales al finalizar.
    $originalSequences = $pdo->query(
        'SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $establishment = (string) $pdo->query('SELECT establecimiento FROM tenant_config WHERE tenant_id = 1')->fetchColumn();
    $expectedControl = static function (string $type) use ($originalSequences, $establishment): string {
        return 'DTE-' . $type . '-' . $establishment . '-' . str_pad((string) ((int) ($originalSequences[$type] ?? 0) + 1), 15, '0', STR_PAD_LEFT);
    };
    $expectedFe = $expectedControl('01');
    $expectedCcf = $expectedControl('03');

    [, $feSummary] = calculateDocumentTotals('01', [[
        'cantidad' => '1', 'precio_unitario' => '113', 'descuento' => '0', 'tratamiento_iva' => 'GRAVADO',
    ]], false);
    checkModule5($feSummary['totalGravada'] === 100.0 && $feSummary['totalIva'] === 13.0 && $feSummary['totalPagar'] === 113.0, 'Factura calcula IVA incluido sin duplicarlo');
    checkModule5(!array_key_exists('reteRenta', $feSummary), 'el JSON de Factura no contiene reteRenta');
    [, $ccfSummary] = calculateDocumentTotals('03', [[
        'cantidad' => '1', 'precio_unitario' => '100', 'descuento' => '0', 'tratamiento_iva' => 'GRAVADO',
    ]], true);
    checkModule5($ccfSummary['totalPagar'] === 112.0 && $ccfSummary['tributos'][0]['valor'] === 13.0, 'Crédito Fiscal agrega IVA y retención correctamente');
    checkModule5(!array_key_exists('totalIva', $ccfSummary), 'el JSON CCF excluye totalIva según su esquema');

    $feId = saveDocument($tenantId, (int) $admin['id'], module5Input(1, 1, 1, 1));
    $documentIds[] = $feId;
    $draft = documentDetail($tenantId, $feId);
    checkModule5($draft['document']['estado'] === 'BORRADOR' && $draft['document']['dte'] === null, 'el borrador se guarda sin consumir correlativo');
    $fe = emitDocument($tenantId, $admin, $feId);
    checkModule5($fe['document']['estado'] === 'PROCESADA', 'Factura pasa de borrador a procesada');
    checkModule5($fe['document']['numero_control'] === $expectedFe, 'Factura usa el correlativo siguiente de su tipo');
    checkModule5(strlen($fe['document']['sello_recepcion']) === 40, 'la transmisión devuelve sello de 40 caracteres');
    checkModule5($fe['document']['dte']['resumen']['totalPagar'] === 112.0, 'el JSON persistido incluye retención de IVA');
    checkModule5(count($fe['logs']) === 2, 'firma y transmisión quedan registradas');

    $ccfId = saveDocument($tenantId, (int) $admin['id'], module5Input(3, 1, 2, 1));
    $documentIds[] = $ccfId;
    $ccf = emitDocument($tenantId, $admin, $ccfId);
    checkModule5($ccf['document']['estado'] === 'PROCESADA', 'Crédito Fiscal se transmite correctamente');
    checkModule5($ccf['document']['numero_control'] === $expectedCcf, 'CCF mantiene correlativo independiente');
    checkModule5($ccf['document']['dte']['resumen']['pagos'][0]['plazo'] === '01' && $ccf['document']['dte']['resumen']['pagos'][0]['periodo'] === 30, 'crédito registra plazo y días');
    checkModule5($ccf['document']['dte']['receptor']['nit'] === '06142222222222', 'el NIT se transmite sin guiones');

    $consult = consultDocument($tenantId, $admin, $feId);
    checkModule5($consult['estado'] === 'PROCESADO' && count(documentDetail($tenantId, $feId)['logs']) === 3, 'la consulta MH queda registrada');
    $invalidated = invalidateDocument($tenantId, $admin, $feId, [
        'tipo_anulacion' => '1', 'motivo' => 'Prueba de invalidación automatizada',
        'tipo_documento_responsable' => '13', 'numero_documento_responsable' => '12345678-9',
    ]);
    checkModule5($invalidated['document']['estado'] === 'INVALIDADA', 'la invalidación cambia el estado y conserva el documento');
    checkModule5($invalidated['document']['invalidacion']['documento']['selloRecibido'] === $fe['document']['sello_recepcion'], 'la invalidación referencia el sello original');

    $rejected = false;
    try { saveDocument($tenantId, (int) $admin['id'], module5Input(3, 3, 1, 1)); }
    catch (DocumentValidationException $exception) { $rejected = isset($exception->errors['cliente_id']); }
    checkModule5($rejected, 'CCF rechaza clientes con DUI');

    $other = documentRecord($pdo, 999, $feId);
    checkModule5($other === null, 'el detalle respeta el aislamiento por empresa');
    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $statement = $pdo->prepare("DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id IN ({$placeholders})");
        $statement->execute($documentIds);
        $statement = $pdo->prepare("DELETE FROM documentos WHERE tenant_id = 1 AND id IN ({$placeholders})");
        $statement->execute($documentIds);
    }
    // Se devuelven los correlativos a su valor inicial: la prueba no consume números.
    if (isset($originalSequences) && $originalSequences !== []) {
        $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
        foreach ($originalSequences as $type => $value) {
            $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
        }
    }
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
