<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP del código de barras: guardado, búsqueda, consulta exacta,
 * duplicados, edición, desactivación y eliminación.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$suffix = (string) getmypid() . (string) random_int(100, 999);
$code = 'H16-' . $suffix;
$barcode = 'HTTP' . $suffix;
$productId = null;

function checkBarcodeHttp(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO HTTP CÓDIGO DE BARRAS: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function barcodeHttp(string $method, string $url, array &$jar, array $data = []): array
{
    global $base;
    $curl = curl_init($base . $url);
    $headers = ['Accept: application/json'];
    if ($jar !== []) {
        $pairs = [];
        foreach ($jar as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $options[CURLOPT_POSTFIELDS] = http_build_query($data);
    }
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    if ($raw === false) {
        throw new RuntimeException(curl_error($curl));
    }
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    foreach (preg_split('/\r?\n/', substr($raw, 0, $headerSize)) ?: [] as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $matches) === 1) {
            $jar[trim($matches[1])] = trim($matches[2]);
        }
    }
    $body = substr($raw, $headerSize);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

function barcodeLogin(array &$jar): string
{
    $login = barcodeHttp('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    $response = barcodeHttp('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => 'admin', 'password' => 'Demo1234!',
    ]);
    if ($response['status'] !== 200) {
        throw new RuntimeException('No fue posible iniciar sesión para la prueba de código de barras.');
    }
    $page = barcodeHttp('GET', 'views/dashboard.php', $jar);
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $meta);
    return $meta[1] ?? '';
}

try {
    $jar = [];
    $csrf = barcodeLogin($jar);
    checkBarcodeHttp($csrf !== '', 'el administrador inicia sesión');

    $options = barcodeHttp('GET', 'api/productos.php?action=options', $jar);
    $unitId = (int) ($options['json']['unidades_medida'][0]['id'] ?? 0);
    checkBarcodeHttp($unitId > 0, 'la API entrega las unidades de medida');

    $payload = [
        'action' => 'save', 'csrf_token' => $csrf,
        'codigo' => $code, 'codigo_barras' => $barcode,
        'descripcion' => 'Producto HTTP con código de barras',
        'tipo' => 'BIEN', 'unidad_medida_id' => $unitId,
        'precio_unitario' => '10.00', 'tratamiento_iva' => 'GRAVADO',
        'control_stock' => '0', 'activo' => '1',
    ];
    $created = barcodeHttp('POST', 'api/productos.php', $jar, $payload);
    checkBarcodeHttp($created['status'] === 200 && ($created['json']['success'] ?? false), 'la API guarda un producto con código de barras');
    $productId = (int) $pdo->query("SELECT id FROM productos WHERE tenant_id = 1 AND codigo = " . $pdo->quote($code))->fetchColumn();
    checkBarcodeHttp($productId > 0, 'el producto creado existe en la base');

    $search = barcodeHttp('GET', 'api/productos.php?action=list&search=' . rawurlencode($barcode), $jar);
    $found = false;
    foreach (($search['json']['items'] ?? []) as $item) {
        if ((int) $item['id'] === $productId && $item['codigo_barras'] === $barcode) {
            $found = true;
        }
    }
    checkBarcodeHttp($found, 'la lista encuentra el producto por código de barras');

    $lookup = barcodeHttp('GET', 'api/productos.php?action=barcode&codigo=' . rawurlencode($barcode), $jar);
    checkBarcodeHttp($lookup['status'] === 200 && (int) ($lookup['json']['product']['id'] ?? 0) === $productId, 'la API busca el producto por código exacto');

    $duplicate = barcodeHttp('POST', 'api/productos.php', $jar, [
        'codigo' => 'H16-DUP-' . $suffix, 'codigo_barras' => $barcode,
        'descripcion' => 'Duplicado HTTP', 'tipo' => 'BIEN', 'unidad_medida_id' => $unitId,
        'precio_unitario' => '1.00', 'tratamiento_iva' => 'GRAVADO',
        'control_stock' => '0', 'activo' => '1', 'csrf_token' => $csrf, 'action' => 'save',
    ]);
    checkBarcodeHttp($duplicate['status'] === 422 && isset($duplicate['json']['errors']['codigo_barras']), 'la API rechaza un código de barras duplicado');

    $updated = barcodeHttp('POST', 'api/productos.php', $jar, array_merge($payload, [
        'id' => $productId, 'descripcion' => 'Producto HTTP editado',
    ]));
    checkBarcodeHttp($updated['status'] === 200, 'la API actualiza un producto con código de barras');
    checkBarcodeHttp(
        (string) $pdo->query('SELECT descripcion FROM productos WHERE id = ' . $productId)->fetchColumn() === 'Producto HTTP editado',
        'la edición se guarda en la base'
    );

    $inactive = barcodeHttp('POST', 'api/productos.php', $jar, array_merge($payload, [
        'id' => $productId, 'activo' => '0',
    ]));
    checkBarcodeHttp($inactive['status'] === 200, 'el producto se puede desactivar');
    $inactiveLookup = barcodeHttp('GET', 'api/productos.php?action=barcode&codigo=' . rawurlencode($barcode), $jar);
    checkBarcodeHttp($inactiveLookup['status'] === 404, 'el escaneo ignora los productos inactivos');

    $deleted = barcodeHttp('POST', 'api/productos.php', $jar, [
        'action' => 'delete', 'id' => $productId, 'csrf_token' => $csrf,
    ]);
    checkBarcodeHttp($deleted['status'] === 200, 'el producto de prueba se elimina');
    $productId = null;

    echo "\nRESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($productId !== null) {
        $pdo->prepare('DELETE FROM productos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $productId]);
    }
    $pdo->prepare('DELETE FROM productos WHERE tenant_id = 1 AND codigo LIKE :code')
        ->execute(['code' => 'H16-%' . $suffix]);
    echo "Datos temporales eliminados.\n";
}
