<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/dte-pdf.php';

/**
 * Pruebas del módulo 10: Nota de Crédito (05), Nota de Débito (06),
 * Factura de Exportación (11) y Factura de Sujeto Excluido (14).
 */

$pdo = db();
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);
$tenantId = 1;
$assertions = 0;
$documentIds = [];
$sequences = $pdo->query(
    'SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1'
)->fetchAll(PDO::FETCH_KEY_PAIR);
$today = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');

function checkModule10(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function expectModule10Error(callable $callback, string $field, string $message): void
{
    $caught = false;
    try {
        $callback();
    } catch (DocumentValidationException $exception) {
        $caught = isset($exception->errors[$field]);
    }
    checkModule10($caught, $message);
}

function module10BaseInput(string $today): array
{
    return [
        'tipo_dte' => '01', 'cliente_id' => '1', 'fecha_emision' => $today,
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Base para notas del módulo 10',
        'items' => [[
            'producto_id' => '1', 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ];
}

function module10NoteInput(string $type, int $relatedId, string $today): array
{
    return [
        'tipo_dte' => $type, 'cliente_id' => '1', 'documento_relacionado_id' => (string) $relatedId,
        'fecha_emision' => $today, 'condicion_operacion_id' => '1', 'forma_pago_id' => '1',
        'dias_credito' => '', 'emisor_distrito' => '14', 'receptor_distrito' => '01',
        'aplicar_iva_retenido' => '0', 'observaciones' => 'Nota de prueba del módulo 10',
        'items' => [[
            'producto_id' => '1', 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ];
}

function module10ExportInput(string $today): array
{
    return [
        'tipo_dte' => '11', 'cliente_id' => '1', 'fecha_emision' => $today,
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Exportación de prueba del módulo 10',
        'export_cod_pais' => 'US', 'export_nombre_pais' => 'Estados Unidos',
        'export_tipo_persona' => '1', 'export_complemento' => 'Miami, Florida',
        'export_tipo_item' => '1', 'export_flete' => '10', 'export_seguro' => '5',
        'export_no_onerosas' => '0',
        'items' => [[
            'producto_id' => '1', 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ];
}

function module10ExcludedInput(string $today): array
{
    return [
        'tipo_dte' => '14', 'cliente_id' => '1', 'fecha_emision' => $today,
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Sujeto excluido de prueba del módulo 10',
        'rete_renta' => '10',
        'items' => [[
            'producto_id' => '1', 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ];
}

function checkModule10Pdf(string $pdf, string $context): void
{
    checkModule10(str_starts_with($pdf, '%PDF-1.4'), "el PDF de {$context} inicia correctamente");
    checkModule10(str_ends_with($pdf, '%%EOF') && strlen($pdf) > 2500, "el PDF de {$context} tiene contenido");
}

try {
    checkModule10((int) $pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn() >= 12, 'la base de datos está en la versión 12 o superior');
    $types = documentTypeDefinitions();
    checkModule10(count($types) === 6 && $types['05']['disponible'] && $types['06']['disponible'] && $types['11']['disponible'] && $types['14']['disponible'], 'los seis tipos de DTE están habilitados');
    [, $noteCcfSummary] = calculateDocumentTotals('05', [[
        'cantidad' => '1', 'precio_unitario' => '100', 'descuento' => '0', 'tratamiento_iva' => 'GRAVADO',
    ]], false, ['related_type' => '03']);
    checkModule10($noteCcfSummary['totalIva'] === 13.0 && $noteCcfSummary['totalPagar'] === 113.0, 'la nota relacionada con CCF agrega el IVA');

    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    checkModule10(is_array($admin), 'existe el administrador de pruebas');

    $baseId = saveDocument($tenantId, (int) $admin['id'], module10BaseInput($today));
    $documentIds[] = $baseId;
    $base = emitDocument($tenantId, $admin, $baseId);
    checkModule10($base['document']['estado'] === 'PROCESADA', 'la Factura base se procesa');
    $baseControl = (string) $base['document']['numero_control'];

    $creditId = saveDocument($tenantId, (int) $admin['id'], module10NoteInput('05', $baseId, $today));
    $documentIds[] = $creditId;
    saveDocument($tenantId, (int) $admin['id'], ['id' => $creditId, 'observaciones' => 'Nota de crédito editada'] + module10NoteInput('05', $baseId, $today));
    $credit = emitDocument($tenantId, $admin, $creditId);
    checkModule10($credit['document']['estado'] === 'PROCESADA', 'la Nota de Crédito se procesa');
    checkModule10((int) $credit['document']['documento_relacionado_id'] === $baseId, 'la nota guarda el documento relacionado');
    checkModule10($credit['document']['dte']['identificacion']['tipoDte'] === '05', 'el JSON usa el tipo 05');
    checkModule10($credit['document']['dte']['documentoRelacionado'][0]['numeroDocumento'] === $baseControl, 'la nota referencia el número de control base');
    checkModule10($credit['document']['dte']['cuerpoDocumento'][0]['numeroDocumento'] === $baseControl, 'la línea referencia el documento base');
    checkModule10($credit['document']['dte']['resumen']['totalIva'] === 13.0 && $credit['document']['dte']['resumen']['totalPagar'] === 113.0, 'la Nota de Crédito calcula IVA y total');
    checkModule10($credit['document']['dte']['resumen']['codigoRetencionMH'] === null, 'la Nota de Crédito incluye codigoRetencionMH');
    checkModule10Pdf(buildDocumentPdf($tenantId, $creditId), 'Nota de Crédito');

    $debitId = saveDocument($tenantId, (int) $admin['id'], module10NoteInput('06', $baseId, $today));
    $documentIds[] = $debitId;
    $debit = emitDocument($tenantId, $admin, $debitId);
    checkModule10($debit['document']['estado'] === 'PROCESADA', 'la Nota de Débito se procesa');
    checkModule10($debit['document']['dte']['identificacion']['tipoDte'] === '06', 'el JSON usa el tipo 06');
    checkModule10(array_key_exists('numPagoElectronico', $debit['document']['dte']['resumen']), 'la Nota de Débito incluye numPagoElectronico');
    checkModule10Pdf(buildDocumentPdf($tenantId, $debitId), 'Nota de Débito');

    $exportId = saveDocument($tenantId, (int) $admin['id'], module10ExportInput($today));
    $documentIds[] = $exportId;
    $export = emitDocument($tenantId, $admin, $exportId);
    checkModule10($export['document']['estado'] === 'PROCESADA', 'la Factura de Exportación se procesa');
    checkModule10($export['document']['dte']['identificacion']['tipoDte'] === '11', 'el JSON usa el tipo 11');
    checkModule10($export['document']['dte']['receptor']['codPais'] === 'US' && $export['document']['dte']['receptor']['tipoPersona'] === 1, 'la exportación guarda país y tipo de persona');
    checkModule10($export['document']['dte']['resumen']['flete'] === 10.0 && $export['document']['dte']['resumen']['seguro'] === 5.0, 'la exportación incluye flete y seguro');
    checkModule10((float) $export['document']['total_pagar'] === 128.0, 'la exportación suma flete y seguro al total');
    checkModule10Pdf(buildDocumentPdf($tenantId, $exportId), 'Factura de Exportación');

    $excludedId = saveDocument($tenantId, (int) $admin['id'], module10ExcludedInput($today));
    $documentIds[] = $excludedId;
    $excluded = emitDocument($tenantId, $admin, $excludedId);
    checkModule10($excluded['document']['estado'] === 'PROCESADA', 'la Factura de Sujeto Excluido se procesa');
    checkModule10($excluded['document']['dte']['identificacion']['tipoDte'] === '14', 'el JSON usa el tipo 14');
    checkModule10($excluded['document']['dte']['cuerpoDocumento'][0]['compra'] === 113.0, 'el sujeto excluido guarda el importe de compra');
    checkModule10($excluded['document']['dte']['resumen']['reteRenta'] === 10.0, 'el sujeto excluido guarda la retención de renta');
    checkModule10((float) $excluded['document']['total_pagar'] === 103.0, 'el sujeto excluido descuenta la retención');
    $storedCompra = $pdo->query('SELECT compra FROM documento_items WHERE documento_id = ' . (int) $excludedId)->fetchColumn();
    checkModule10((float) $storedCompra === 113.0, 'la línea guarda el importe de compra');
    checkModule10Pdf(buildDocumentPdf($tenantId, $excludedId), 'Factura de Sujeto Excluido');

    expectModule10Error(
        static fn() => saveDocument(1, (int) $admin['id'], array_diff_key(module10NoteInput('05', 1, $today), ['documento_relacionado_id' => true])),
        'documento_relacionado_id', 'la nota exige documento relacionado'
    );
    expectModule10Error(
        static fn() => saveDocument(1, (int) $admin['id'], ['cliente_id' => '2'] + module10NoteInput('05', $baseId, $today)),
        'documento_relacionado_id', 'la nota exige un relacionado del mismo cliente'
    );
    expectModule10Error(
        static fn() => saveDocument(1, (int) $admin['id'], array_diff_key(module10ExportInput($today), ['export_cod_pais' => true])),
        'export_cod_pais', 'la exportación exige código de país'
    );
    expectModule10Error(
        static fn() => saveDocument(1, (int) $admin['id'], ['cliente_id' => '22'] + module10ExcludedInput($today)),
        'cliente_id', 'el sujeto excluido exige cliente con NIT o DUI'
    );

    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    foreach (array_reverse($documentIds) as $id) {
        $pdo->prepare('DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $id]);
    }
    $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
    foreach ($sequences as $type => $value) {
        $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
    }
    echo "Datos temporales eliminados.\n";
}
