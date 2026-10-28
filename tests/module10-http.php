<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP del módulo 10: pantalla de emisión, guardado y emisión de los
 * tipos 05, 06, 11 y 14, PDF, listado y permisos de CONTADOR.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$documentIds = [];
$contadorId = null;
$today = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');
$sequenceBefore = $pdo->query(
    "SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1 AND tipo_dte IN ('05','06','11','14')"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$baseDocument = (int) $pdo->query(
    "SELECT id FROM documentos WHERE tenant_id = 1 AND cliente_id = 1 AND estado = 'PROCESADA' AND tipo_dte IN ('01','03') ORDER BY id LIMIT 1"
)->fetchColumn();
$suffix = (string) getmypid();
$userContador = 'm10_contador_' . $suffix;

function checkHttp10(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http10(string $method, string $url, array &$jar, array $data = []): array
{
    global $base;
    $curl = curl_init($base . $url);
    $headers = ['Accept: application/json, text/html'];
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') $options[CURLOPT_POSTFIELDS] = http_build_query($data);
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    if ($raw === false) throw new RuntimeException(curl_error($curl));
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    $parsed = [];
    foreach (preg_split('/\r?\n/', substr($raw, 0, $headerSize)) ?: [] as $line) {
        if (preg_match('/^([^:]+):\s*(.*)$/', $line, $matches) !== 1) continue;
        $name = strtolower(trim($matches[1]));
        if ($name === 'set-cookie') {
            if (preg_match('/^([^=]+)=([^;]*)/', $matches[2], $cookie) === 1) $jar[trim($cookie[1])] = trim($cookie[2]);
            continue;
        }
        $parsed[$name] = trim($matches[2]);
    }
    $body = substr($raw, $headerSize);
    return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'json' => json_decode($body, true)];
}

function login10(string $username, string $password, array &$jar): string
{
    $login = http10('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    http10('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    $dashboard = http10('GET', 'views/dashboard.php', $jar);
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dashboard['body'], $meta) ? $meta[1] : '';
}

function module10SavePayload(string $csrf, string $today): array
{
    return [
        'csrf_token' => $csrf, 'action' => 'save', 'fecha_emision' => $today,
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Prueba HTTP módulo 10',
        'items' => [0 => [
            'producto_id' => '1', 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ];
}

try {
    $anonymous = [];
    checkHttp10(http10('GET', 'api/documentos.php?action=options', $anonymous)['status'] === 401, 'la API de emisión exige autenticación');

    $adminJar = [];
    $adminCsrf = login10('admin', 'Demo1234!', $adminJar);
    checkHttp10($adminCsrf !== '', 'el administrador inicia sesión');

    $page = http10('GET', 'views/nueva-factura.php', $adminJar);
    checkHttp10($page['status'] === 200, 'la pantalla de emisión carga');
    foreach (['05', '06', '11', '14'] as $type) {
        checkHttp10(str_contains($page['body'], 'value="' . $type . '"'), 'la pantalla incluye el tipo ' . $type);
    }
    checkHttp10(str_contains($page['body'], 'related-document-section') && str_contains($page['body'], 'export-section') && str_contains($page['body'], 'excluded-fields'), 'la pantalla incluye las secciones del módulo 10');

    $options = http10('GET', 'api/documentos.php?action=options', $adminJar);
    checkHttp10(($options['json']['success'] ?? false) && count($options['json']['documentos_relacionados'] ?? []) >= 1, 'la API devuelve documentos relacionados');
    checkHttp10($baseDocument > 0, 'existe un documento base procesado para las notas');

    checkHttp10(http10('POST', 'api/documentos.php', $adminJar, ['action' => 'save'])['status'] === 403, 'guardar sin CSRF es rechazado');

    $payload = module10SavePayload($adminCsrf, $today);
    $creditSave = http10('POST', 'api/documentos.php', $adminJar, ['tipo_dte' => '05', 'cliente_id' => '1', 'documento_relacionado_id' => (string) $baseDocument, 'observaciones' => 'Nota de crédito HTTP'] + $payload);
    checkHttp10($creditSave['status'] === 200 && ($creditSave['json']['success'] ?? false), 'se guarda la Nota de Crédito');
    $creditId = (int) ($creditSave['json']['id'] ?? 0);
    if ($creditId > 0) $documentIds[] = $creditId;
    $creditEmit = http10('POST', 'api/documentos.php', $adminJar, ['action' => 'emit', 'id' => $creditId, 'csrf_token' => $adminCsrf, 'contingencia' => '0']);
    checkHttp10($creditEmit['status'] === 200 && ($creditEmit['json']['estado'] ?? '') === 'PROCESADA', 'la Nota de Crédito se emite');
    $creditDetail = http10('GET', 'api/documentos.php?action=get&id=' . $creditId, $adminJar);
    checkHttp10((int) ($creditDetail['json']['document']['documento_relacionado_id'] ?? 0) === $baseDocument, 'la Nota de Crédito conserva el relacionado');

    $debitSave = http10('POST', 'api/documentos.php', $adminJar, ['tipo_dte' => '06', 'cliente_id' => '1', 'documento_relacionado_id' => (string) $baseDocument, 'observaciones' => 'Nota de débito HTTP'] + $payload);
    $debitId = (int) ($debitSave['json']['id'] ?? 0);
    if ($debitId > 0) $documentIds[] = $debitId;
    checkHttp10($debitSave['status'] === 200 && $debitId > 0, 'se guarda la Nota de Débito');
    $debitEmit = http10('POST', 'api/documentos.php', $adminJar, ['action' => 'emit', 'id' => $debitId, 'csrf_token' => $adminCsrf, 'contingencia' => '0']);
    checkHttp10($debitEmit['status'] === 200 && ($debitEmit['json']['estado'] ?? '') === 'PROCESADA', 'la Nota de Débito se emite');

    $exportPayload = $payload + [
        'tipo_dte' => '11', 'cliente_id' => '1',
        'export_cod_pais' => 'US', 'export_nombre_pais' => 'Estados Unidos',
        'export_tipo_persona' => '1', 'export_complemento' => 'Miami, Florida',
        'export_tipo_item' => '1', 'export_flete' => '10', 'export_seguro' => '5',
        'export_no_onerosas' => '0',
    ];
    $exportSave = http10('POST', 'api/documentos.php', $adminJar, $exportPayload);
    $exportId = (int) ($exportSave['json']['id'] ?? 0);
    if ($exportId > 0) $documentIds[] = $exportId;
    checkHttp10($exportSave['status'] === 200 && $exportId > 0, 'se guarda la Factura de Exportación');
    $exportEmit = http10('POST', 'api/documentos.php', $adminJar, ['action' => 'emit', 'id' => $exportId, 'csrf_token' => $adminCsrf, 'contingencia' => '0']);
    checkHttp10($exportEmit['status'] === 200 && ($exportEmit['json']['estado'] ?? '') === 'PROCESADA', 'la Factura de Exportación se emite');
    $exportDetail = http10('GET', 'api/documentos.php?action=get&id=' . $exportId, $adminJar);
    checkHttp10(($exportDetail['json']['document']['dte']['receptor']['codPais'] ?? '') === 'US', 'la exportación guarda el país del receptor');
    checkHttp10((float) ($exportDetail['json']['document']['total_pagar'] ?? 0) === 128.0, 'la exportación suma flete y seguro');

    $excludedPayload = $payload + ['tipo_dte' => '14', 'cliente_id' => '1', 'rete_renta' => '10'];
    $excludedSave = http10('POST', 'api/documentos.php', $adminJar, $excludedPayload);
    $excludedId = (int) ($excludedSave['json']['id'] ?? 0);
    if ($excludedId > 0) $documentIds[] = $excludedId;
    checkHttp10($excludedSave['status'] === 200 && $excludedId > 0, 'se guarda la Factura de Sujeto Excluido');
    $excludedEmit = http10('POST', 'api/documentos.php', $adminJar, ['action' => 'emit', 'id' => $excludedId, 'csrf_token' => $adminCsrf, 'contingencia' => '0']);
    checkHttp10($excludedEmit['status'] === 200 && ($excludedEmit['json']['estado'] ?? '') === 'PROCESADA', 'la Factura de Sujeto Excluido se emite');
    $excludedDetail = http10('GET', 'api/documentos.php?action=get&id=' . $excludedId, $adminJar);
    checkHttp10((float) ($excludedDetail['json']['document']['total_pagar'] ?? 0) === 103.0, 'el sujeto excluido descuenta la retención');

    $pdf = http10('GET', 'api/documentos.php?action=pdf&id=' . $creditId, $adminJar);
    checkHttp10(str_starts_with($pdf['body'], '%PDF-1.4') && strlen($pdf['body']) > 2500, 'el PDF de la nota se genera');
    $pdf14 = http10('GET', 'api/documentos.php?action=pdf&id=' . $excludedId, $adminJar);
    checkHttp10(str_starts_with($pdf14['body'], '%PDF-1.4') && strlen($pdf14['body']) > 2500, 'el PDF del sujeto excluido se genera');

    $list = http10('GET', 'api/documentos.php?action=list', $adminJar);
    checkHttp10($list['status'] === 200 && ($list['json']['pagination']['total'] ?? 0) >= 4, 'el listado incluye los documentos del módulo 10');

    $hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (1,'Contador temporal M10',:usuario,:email,:hash,'CONTADOR')")
        ->execute(['usuario' => $userContador, 'email' => $userContador . '@test.local', 'hash' => $hash]);
    $contadorId = (int) $pdo->lastInsertId();
    $contadorJar = [];
    $contadorCsrf = login10($userContador, 'Demo1234!', $contadorJar);
    checkHttp10(http10('GET', 'views/nueva-factura.php', $contadorJar)['status'] === 403, 'el contador no puede abrir la emisión');
    checkHttp10(http10('POST', 'api/documentos.php', $contadorJar, ['action' => 'save', 'csrf_token' => $contadorCsrf])['status'] === 403, 'el contador no puede guardar documentos');
    checkHttp10(http10('GET', 'api/documentos.php?action=list', $contadorJar)['status'] === 200, 'el contador conserva la consulta');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    foreach (array_reverse($documentIds) as $id) {
        $pdo->prepare('DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $id]);
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $id]);
    }
    if ($contadorId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $contadorId]);
    $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
    foreach ($sequenceBefore as $type => $value) {
        $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
    }
    echo "Datos temporales eliminados.\n";
}
