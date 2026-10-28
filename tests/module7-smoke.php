<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/correo.php';
require_once dirname(__DIR__) . '/lib/reportes.php';

/**
 * Pruebas del módulo 7: dashboard, reportes, exportación y envío por correo.
 * Trabaja con datos temporales y restaura correlativos y registros al final.
 */

$pdo = db();
$tenantId = 1;
$assertions = 0;
$documentIds = [];
$clientIds = [];
$originalSequences = [];

function checkModule7(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function module7Input(string $type, int $clientId, string $productId = '1', string $price = '113.00000000', string $date = ''): array
{
    return [
        'tipo_dte' => $type, 'cliente_id' => (string) $clientId,
        'fecha_emision' => $date, 'condicion_operacion_id' => '1', 'forma_pago_id' => '1',
        'dias_credito' => '', 'emisor_distrito' => '14', 'receptor_distrito' => '01',
        'aplicar_iva_retenido' => '0', 'observaciones' => 'Prueba automatizada del módulo 7',
        'items' => [['producto_id' => $productId, 'cantidad' => '1', 'precio_unitario' => $price, 'descuento' => '0']],
    ];
}

try {
    // La prueba fuerza el transporte simulado para no depender de un SMTP real.
    $GLOBALS['facturasv_config']['mail'] = array_merge(
        is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
        ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
    );
    checkModule7((int) $pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn() >= 9, 'la base de datos está en la versión 9 o superior');
    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    checkModule7(is_array($admin), 'existe el administrador de pruebas');

    $originalSequences = $pdo->query('SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1')->fetchAll(PDO::FETCH_KEY_PAIR);
    $timezone = reportTimezone($tenantId);
    checkModule7(in_array($timezone, timezone_identifiers_list(), true), 'la zona horaria de la empresa es válida');
    $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
    $range = reportRange([], $timezone);
    checkModule7($range['desde'] === (new DateTimeImmutable('now', new DateTimeZone($timezone)))->modify('first day of this month')->format('Y-m-d'), 'el rango predeterminado inicia el primer día del mes');
    checkModule7($range['hasta'] === $today, 'el rango predeterminado termina hoy');
    checkModule7(reportRange(['fecha_desde' => '2026-02-10', 'fecha_hasta' => '2026-01-05'], $timezone)['desde'] === '2026-01-05', 'las fechas invertidas se ordenan');

    $location = $pdo->query(
        'SELECT d.id AS departamento_id, m.id AS municipio_id
         FROM mh_departamentos d INNER JOIN mh_municipios m
            ON m.tenant_id = d.tenant_id AND m.departamento_id = d.id
         WHERE d.tenant_id = 1 AND d.activo = 1 AND m.activo = 1 LIMIT 1'
    )->fetch();
    $insertClient = $pdo->prepare(
        "INSERT INTO clientes (tenant_id, tipo_documento, numero_documento, nombre, email, direccion,
            departamento_id, municipio_id, tipo_contribuyente, activo)
         VALUES (1, 'NINGUNO', NULL, :nombre, :email, 'San Salvador', :departamento, :municipio, 'OTRO', 1)"
    );
    $insertClient->execute(['nombre' => 'Cliente correo M7', 'email' => 'cliente.m7@example.com',
        'departamento' => $location['departamento_id'], 'municipio' => $location['municipio_id']]);
    $clientWithEmail = (int) $pdo->lastInsertId();
    $clientIds[] = $clientWithEmail;
    $insertClient->execute(['nombre' => 'Cliente sin correo M7', 'email' => null,
        'departamento' => $location['departamento_id'], 'municipio' => $location['municipio_id']]);
    $clientWithoutEmail = (int) $pdo->lastInsertId();
    $clientIds[] = $clientWithoutEmail;

    $before = reportSummary($tenantId, $today, $today);
    $beforeDay = reportByDay($tenantId, $today, $today)[0] ?? null;
    $dashboardBefore = dashboardMetrics($tenantId);

    // Factura con cliente que tiene correo: el envío se registra en modo simulación.
    $withEmailId = saveDocument($tenantId, (int) $admin['id'], module7Input('01', $clientWithEmail, '1', '113.00000000', $today));
    $documentIds[] = $withEmailId;
    $withEmail = emitDocument($tenantId, $admin, $withEmailId);
    checkModule7($withEmail['document']['estado'] === 'PROCESADA', 'la Factura de prueba se procesa');
    $emails = correosDocumento($tenantId, $withEmailId);
    checkModule7(count($emails) === 1, 'la emisión registra un intento de correo');
    checkModule7($emails[0]['estado'] === 'SIMULADO', 'sin SMTP configurado el correo queda en modo simulado');
    checkModule7($emails[0]['destinatario'] === 'cliente.m7@example.com', 'el destinatario es el correo del cliente');
    checkModule7(str_contains($emails[0]['adjuntos'], '.pdf') && str_contains($emails[0]['adjuntos'], '.json'), 'el envío adjunta PDF y JSON');
    checkModule7(count($withEmail['correos']) === 1, 'el detalle del documento incluye el historial de correos');

    $resent = enviarCorreoDocumento($tenantId, $withEmailId, (int) $admin['id']);
    checkModule7($resent['estado'] === 'SIMULADO', 'el reenvío manual usa el transporte simulado');
    checkModule7(count(correosDocumento($tenantId, $withEmailId)) === 2, 'el reenvío agrega otro registro');

    // Un servidor SMTP inalcanzable registra ERROR sin lanzar excepción al usuario.
    $failed = enviarCorreoDocumento($tenantId, $withEmailId, (int) $admin['id'], ['mail' => [
        'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none', 'timeout' => 3,
    ]]);
    checkModule7($failed['estado'] === 'ERROR', 'un servidor SMTP inalcanzable devuelve ERROR');
    $failedLog = correosDocumento($tenantId, $withEmailId, 1);
    checkModule7($failedLog[0]['estado'] === 'ERROR' && str_contains((string) $failedLog[0]['error'], 'conectar'), 'el error de conexión queda registrado');

    // Factura de cliente sin correo: queda registrada como omitida.
    $withoutEmailId = saveDocument($tenantId, (int) $admin['id'], module7Input('01', $clientWithoutEmail, '1', '113.00000000', $today));
    $documentIds[] = $withoutEmailId;
    emitDocument($tenantId, $admin, $withoutEmailId);
    $omitted = correosDocumento($tenantId, $withoutEmailId);
    checkModule7(count($omitted) === 1 && $omitted[0]['estado'] === 'OMITIDO', 'un cliente sin correo registra el envío como omitido');
    checkModule7(enviarCorreoDocumento($tenantId, $withoutEmailId, (int) $admin['id'])['estado'] === 'OMITIDO', 'el reenvío sin correo devuelve OMITIDO');

    // Reportes: las dos facturas suman $226 y $26 de IVA.
    $after = reportSummary($tenantId, $today, $today);
    checkModule7($after['documentos'] === $before['documentos'] + 2, 'el resumen cuenta los documentos procesados');
    checkModule7(abs($after['total'] - ($before['total'] + 226.0)) < 0.005, 'el resumen suma el total de las facturas');
    checkModule7(abs($after['gravada'] - ($before['gravada'] + 200.0)) < 0.005, 'el resumen suma la base gravada');
    checkModule7(abs($after['iva'] - ($before['iva'] + 26.0)) < 0.005, 'el resumen suma el IVA');

    $byClient = reportByClient($tenantId, $today, $today);
    $clientRow = null;
    foreach ($byClient as $row) if ((int) $row['id'] === $clientWithEmail) $clientRow = $row;
    checkModule7($clientRow !== null && abs((float) $clientRow['total'] - 113.0) < 0.005, 'las ventas por cliente incluyen la factura de prueba');

    $byProduct = reportByProduct($tenantId, $today, $today);
    $productRow = null;
    foreach ($byProduct as $row) if ($row['codigo'] === 'PROD-001') $productRow = $row;
    checkModule7($productRow !== null && (float) $productRow['cantidad'] >= 2.0, 'las ventas por producto acumulan las cantidades');

    $dayRow = reportByDay($tenantId, $today, $today)[0] ?? null;
    checkModule7($dayRow !== null && abs((float) $dayRow['total'] - (($beforeDay['total'] ?? 0) + 226.0)) < 0.005, 'las ventas por día reflejan el total');

    $ivaRows = reportMonthlyIva($tenantId, $range['desde'], $range['hasta']);
    checkModule7($ivaRows !== [], 'el resumen mensual de IVA devuelve periodos');

    $dashboardAfter = dashboardMetrics($tenantId);
    checkModule7(abs($dashboardAfter['ingresos'] - ($dashboardBefore['ingresos'] + 226.0)) < 0.005, 'el dashboard suma los ingresos del mes');
    checkModule7($dashboardAfter['documentos'] === $dashboardBefore['documentos'] + 2, 'el dashboard cuenta los documentos del mes');
    checkModule7(abs($dashboardAfter['iva'] - ($dashboardBefore['iva'] + 26.0)) < 0.005, 'el dashboard suma el IVA del mes');
    checkModule7(count($dashboardAfter['serie']) === 6, 'la serie del dashboard tiene seis meses');
    checkModule7($dashboardAfter['recientes'] !== [], 'el dashboard devuelve documentos recientes');

    // CSV: encabezados y filas con el separador y dinero del sistema.
    $csvClients = reportCsvData($tenantId, 'clientes', $today, $today);
    checkModule7($csvClients['headers'][0] === 'Cliente' && $csvClients['rows'] !== [], 'el CSV por cliente tiene encabezados y filas');
    $csvSummary = reportCsvData($tenantId, 'resumen', $today, $today);
    checkModule7($csvSummary['headers'] === ['Concepto', 'Valor'], 'el CSV de resumen usa concepto y valor');
    checkModule7(in_array('Total vendido', array_column($csvSummary['rows'], 0), true), 'el CSV de resumen incluye el total vendido');
    foreach (['productos', 'iva', 'diario'] as $report) {
        $csv = reportCsvData($tenantId, $report, $range['desde'], $range['hasta']);
        checkModule7($csv['headers'] !== [] && count($csv['headers']) === count($csv['rows'][0] ?? $csv['headers']), "el CSV de {$report} está bien formado");
    }

    // Aislamiento por empresa.
    checkModule7(reportSummary(999, $today, $today)['documentos'] === 0, 'los reportes respetan el aislamiento por empresa');

    // Mensaje MIME: asunto codificado, límites y adjunto base64.
    $mime = correoConstruirMime([
        'from_email' => 'no-responder@facturasv.local', 'from_name' => 'FacturaSV',
        'to' => [['email' => 'cliente@example.com', 'name' => 'Cliente de Prueba']],
        'subject' => 'Factura de prueba · ñandú', 'html' => '<p>Hola</p>', 'text' => 'Hola',
        'attachments' => [['name' => 'DTE-01-1.pdf', 'type' => 'application/pdf', 'data' => '%PDF-1.4 prueba']],
    ]);
    checkModule7(str_starts_with($mime['subject'], '=?UTF-8?B?'), 'el asunto se codifica en UTF-8');
    checkModule7(str_contains($mime['subject'], base64_encode('Factura de prueba · ñandú')), 'el asunto codificado conserva el texto');
    checkModule7(str_contains($mime['headers'][2] ?? '', '=?UTF-8?B?'), 'el nombre del destinatario se codifica');
    checkModule7(str_contains(implode("\r\n", $mime['headers']), 'Content-Type: multipart/mixed'), 'el mensaje declara multipart/mixed');
    checkModule7(str_contains($mime['body'], 'Content-Type: multipart/alternative'), 'el cuerpo incluye texto y HTML alternativos');
    checkModule7(str_contains($mime['body'], 'filename="DTE-01-1.pdf"'), 'el adjunto conserva su nombre');
    checkModule7(str_contains($mime['body'], base64_encode('%PDF-1.4 prueba')), 'el adjunto se codifica en base64');
    $injected = false;
    try { correoConstruirMime(['from_email' => 'x@y.local', 'to' => [['email' => "a@b.local\r\nBcc: otro@x.local"]], 'subject' => 'x', 'html' => '', 'text' => '']); }
    catch (RuntimeException) { $injected = true; }
    checkModule7($injected, 'se rechazan direcciones con saltos de línea');

    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $pdo->prepare("DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id IN ({$placeholders})")->execute($documentIds);
        $pdo->prepare("DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id IN ({$placeholders})")->execute($documentIds);
        $pdo->prepare("DELETE FROM documentos WHERE tenant_id = 1 AND id IN ({$placeholders})")->execute($documentIds);
    }
    foreach ($clientIds as $clientId) {
        $pdo->prepare('DELETE FROM clientes WHERE tenant_id = 1 AND id = :id')->execute(['id' => $clientId]);
    }
    if ($originalSequences !== []) {
        $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
        foreach ($originalSequences as $type => $value) $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
    }
    echo "Datos temporales eliminados.\n";
}
