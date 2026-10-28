<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP del módulo 15: cambio de modo por el superadministrador,
 * emisión de comprobante interno, PDF/ticket no fiscales y bloqueo de
 * transmisión, JSON y correo.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$documentId = null;
$originalMode = (string) $pdo->query('SELECT modo_facturacion FROM tenant_config WHERE tenant_id = 1')->fetchColumn();
$originalSequence = (int) $pdo->query("SELECT COALESCE(MAX(numero_actual), 0) FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = 'IN'")->fetchColumn();
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();

function checkHttp15(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP M15: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http15(string $method, string $url, array &$jar, array $data = []): array
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
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);
    foreach (preg_split('/\r?\n/', substr($raw, 0, $headerSize)) ?: [] as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $matches) === 1) {
            $jar[trim($matches[1])] = trim($matches[2]);
        }
    }
    $body = substr($raw, $headerSize);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'content_type' => $contentType];
}

function login15(string $username, string $password, array &$jar, string $page): string
{
    $login = http15('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    $response = http15('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    if ($response['status'] !== 200) throw new RuntimeException('No fue posible iniciar sesión como ' . $username);
    $html = http15('GET', $page, $jar);
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html['body'], $meta);
    return $meta[1] ?? '';
}

try {
    $anonymous = [];
    $anonymousMode = http15('POST', 'api/superadmin.php', $anonymous, [
        'action' => 'company-billing-mode', 'tenant_id' => 1, 'modo_facturacion' => 'INTERNO',
    ]);
    checkHttp15($anonymousMode['status'] === 401, 'solo el superadministrador autenticado puede cambiar el modo');

    $superJar = [];
    $superCsrf = login15('superadmin', 'SuperDemo1234!', $superJar, 'views/superadmin.php');
    $companies = http15('GET', 'api/superadmin.php?action=companies', $superJar);
    $company = null;
    foreach (($companies['json']['items'] ?? []) as $item) {
        if ((int) $item['id'] === 1) $company = $item;
    }
    checkHttp15($company !== null && ($company['modo_facturacion'] ?? '') === $originalMode, 'el panel expone el modo de facturación de la empresa');

    $toInternal = http15('POST', 'api/superadmin.php', $superJar, [
        'action' => 'company-billing-mode', 'tenant_id' => 1,
        'modo_facturacion' => 'INTERNO', 'csrf_token' => $superCsrf,
    ]);
    checkHttp15($toInternal['status'] === 200 && ($toInternal['json']['success'] ?? false), 'el superadministrador activa el modo interno');
    checkHttp15((string) $pdo->query('SELECT modo_facturacion FROM tenant_config WHERE tenant_id = 1')->fetchColumn() === 'INTERNO', 'el modo interno queda guardado');

    $production = http15('POST', 'api/superadmin.php', $superJar, [
        'action' => 'company-billing-mode', 'tenant_id' => 1,
        'modo_facturacion' => 'PRODUCCION', 'csrf_token' => $superCsrf,
    ]);
    checkHttp15($production['status'] === 422, 'el modo producción sigue bloqueado');

    $adminJar = [];
    $adminCsrf = login15('admin', 'Demo1234!', $adminJar, 'views/dashboard.php');

    $page = http15('GET', 'views/nueva-factura.php', $adminJar);
    checkHttp15($page['status'] === 200 && str_contains($page['body'], 'MODO INTERNO'), 'el formulario se presenta en modo interno');
    checkHttp15(str_contains($page['body'], 'data-billing-mode="INTERNO"'), 'el formulario declara el modo interno');
    checkHttp15(str_contains($page['body'], 'Comprobante interno (IN)'), 'el formulario ofrece el comprobante interno');
    checkHttp15(!str_contains($page['body'], 'Crédito Fiscal (03)'), 'el formulario oculta los tipos DTE en modo interno');

    $options = http15('GET', 'api/documentos.php?action=options', $adminJar);
    checkHttp15(($options['json']['modo_facturacion'] ?? '') === 'INTERNO', 'la API de opciones informa el modo interno');

    $client = $pdo->query("SELECT id FROM clientes WHERE tenant_id = 1 AND activo = 1 ORDER BY id LIMIT 1")->fetch();
    $product = $pdo->query("SELECT id FROM productos WHERE tenant_id = 1 AND activo = 1 AND control_stock = 1 ORDER BY id LIMIT 1")->fetch();
    $condition = $pdo->query("SELECT id FROM mh_condiciones_operacion WHERE tenant_id = 1 AND codigo = '1' LIMIT 1")->fetchColumn();
    $payment = $pdo->query("SELECT id FROM mh_formas_pago WHERE tenant_id = 1 ORDER BY orden, id LIMIT 1")->fetchColumn();

    $blocked = http15('POST', 'api/documentos.php', $adminJar, [
        'action' => 'save', 'csrf_token' => $adminCsrf, 'tipo_dte' => '01',
        'cliente_id' => (int) $client['id'], 'fecha_emision' => date('Y-m-d'),
        'condicion_operacion_id' => (int) $condition, 'forma_pago_id' => (int) $payment,
        'emisor_distrito' => '14', 'receptor_distrito' => '01',
        'items' => [['producto_id' => (int) $product['id'], 'cantidad' => '1', 'precio_unitario' => '10.00', 'descuento' => '0']],
    ]);
    checkHttp15($blocked['status'] === 422 && isset($blocked['json']['errors']['tipo_dte']), 'la API rechaza un DTE en modo interno');

    $saved = http15('POST', 'api/documentos.php', $adminJar, [
        'action' => 'save', 'csrf_token' => $adminCsrf, 'tipo_dte' => 'IN',
        'cliente_id' => (int) $client['id'], 'fecha_emision' => date('Y-m-d'),
        'condicion_operacion_id' => (int) $condition, 'forma_pago_id' => (int) $payment,
        'emisor_distrito' => '14', 'receptor_distrito' => '01',
        'items' => [['producto_id' => (int) $product['id'], 'cantidad' => '1', 'precio_unitario' => '113.00', 'descuento' => '0']],
    ]);
    checkHttp15($saved['status'] === 200 && ($saved['json']['success'] ?? false), 'la API guarda el borrador interno');
    $documentId = (int) ($saved['json']['id'] ?? 0);
    checkHttp15($documentId > 0, 'el borrador interno devuelve su identificador');

    $emitted = http15('POST', 'api/documentos.php', $adminJar, [
        'action' => 'emit', 'id' => $documentId, 'csrf_token' => $adminCsrf,
    ]);
    checkHttp15($emitted['status'] === 200 && ($emitted['json']['estado'] ?? '') === 'PROCESADA', 'la API emite el comprobante interno');
    checkHttp15(str_contains((string) ($emitted['json']['message'] ?? ''), 'Comprobante interno'), 'la respuesta explica que es un comprobante interno');

    $row = $pdo->query('SELECT numero_control, codigo_generacion, sello_recepcion, dte_json FROM documentos WHERE id = ' . $documentId)->fetch();
    checkHttp15(preg_match('/^INT-[0-9]{6}$/', (string) $row['numero_control']) === 1, 'el comprobante usa la serie interna');
    checkHttp15($row['codigo_generacion'] === null && $row['sello_recepcion'] === null && $row['dte_json'] === null, 'el comprobante no guarda datos fiscales');

    $pdf = http15('GET', 'api/documentos.php?action=pdf&id=' . $documentId, $adminJar);
    checkHttp15($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF-'), 'la API entrega el PDF del comprobante interno');
    checkHttp15(str_contains($pdf['body'], 'COMPROBANTE INTERNO') && str_contains($pdf['body'], 'DOCUMENTO NO FISCAL'), 'el PDF se identifica como no fiscal');

    $ticket = http15('GET', 'views/ticket.php?id=' . $documentId, $adminJar);
    checkHttp15($ticket['status'] === 200 && str_contains($ticket['body'], 'COMPROBANTE INTERNO'), 'el ticket se imprime como comprobante interno');
    checkHttp15(str_contains($ticket['body'], 'sin validez fiscal'), 'el ticket advierte que no tiene validez fiscal');

    $json = http15('GET', 'api/documentos.php?action=download&id=' . $documentId, $adminJar);
    checkHttp15($json['status'] === 409, 'el comprobante interno no descarga JSON DTE');

    $transmit = http15('POST', 'api/mh.php', $adminJar, [
        'action' => 'transmit', 'id' => $documentId, 'csrf_token' => $adminCsrf,
    ]);
    checkHttp15($transmit['status'] === 409, 'el comprobante interno no se transmite al MH');

    $email = http15('POST', 'api/documentos.php', $adminJar, [
        'action' => 'email', 'id' => $documentId, 'csrf_token' => $adminCsrf,
    ]);
    checkHttp15($email['status'] === 409, 'el comprobante interno no envía correo fiscal');

    $invalidate = http15('POST', 'api/mh.php', $adminJar, [
        'action' => 'invalidate', 'id' => $documentId, 'csrf_token' => $adminCsrf,
        'motivo' => 'Anulación de prueba HTTP del comprobante interno',
    ]);
    checkHttp15($invalidate['status'] === 200 && ($invalidate['json']['document']['estado'] ?? '') === 'INVALIDADA', 'la API anula el comprobante interno');

    $toSimulation = http15('POST', 'api/superadmin.php', $superJar, [
        'action' => 'company-billing-mode', 'tenant_id' => 1,
        'modo_facturacion' => 'SIMULACION', 'csrf_token' => $superCsrf,
    ]);
    checkHttp15($toSimulation['status'] === 200, 'el superadministrador devuelve la empresa a simulación');
    $restored = http15('GET', 'api/documentos.php?action=options', $adminJar);
    checkHttp15(($restored['json']['modo_facturacion'] ?? '') === 'SIMULACION', 'la empresa vuelve a operar en simulación');
    checkHttp15((string) $pdo->query('SELECT tipo_dte FROM documentos WHERE id = ' . $documentId)->fetchColumn() === 'IN', 'el comprobante interno se conserva como historial');

    echo "\nRESULTADO: {$checks} comprobaciones correctas.\n";
} finally {
    if ($documentId !== null) {
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
