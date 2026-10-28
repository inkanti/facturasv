<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP de la búsqueda inteligente en clientes, productos y catálogos.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$checks = 0;

function checkSmartHttp(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO HTTP BÚSQUEDA: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function smartHttp(string $method, string $url, array &$jar, array $data = []): array
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

function smartLogin(array &$jar): void
{
    $login = smartHttp('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    $response = smartHttp('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => 'admin', 'password' => 'Demo1234!',
    ]);
    if ($response['status'] !== 200) {
        throw new RuntimeException('No fue posible iniciar sesión para la prueba de búsqueda.');
    }
}

function smartNames(array $items, string $field = 'nombre'): array
{
    return array_map(static fn(array $item): string => (string) ($item[$field] ?? ''), $items);
}

$jar = [];
smartLogin($jar);

$clients = smartHttp('GET', 'api/clientes.php?action=list&search=' . rawurlencode('lopez maria'), $jar);
checkSmartHttp(
    ($clients['json']['success'] ?? false) && in_array('María López', smartNames($clients['json']['items'] ?? []), true),
    'la API de clientes encuentra "lopez maria" en cualquier orden'
);

$clients = smartHttp('GET', 'api/clientes.php?action=list&search=' . rawurlencode('0614111111111'), $jar);
checkSmartHttp(
    in_array('Comercial Ejemplo SA de CV', smartNames($clients['json']['items'] ?? []), true),
    'la API de clientes encuentra el documento sin guiones'
);

$products = smartHttp('GET', 'api/productos.php?action=list&search=' . rawurlencode('laptop empresa'), $jar);
$productCodes = array_map(static fn(array $item): string => (string) ($item['codigo'] ?? ''), $products['json']['items'] ?? []);
checkSmartHttp(in_array('PROD-001', $productCodes, true), 'la API de productos busca por varias palabras');

$catalog = smartHttp('GET', 'api/catalogos.php?action=list&resource=unidades-medida&search=' . rawurlencode('kilometro cuadrado'), $jar);
checkSmartHttp(
    in_array('Kilómetro cuadrado', smartNames($catalog['json']['items'] ?? []), true),
    'la API de catálogos ignora el acento en unidades'
);

$catalog = smartHttp('GET', 'api/catalogos.php?action=list&resource=formas-pago&search=' . rawurlencode('TARJETA DEBITO'), $jar);
checkSmartHttp(
    in_array('Tarjeta Débito', smartNames($catalog['json']['items'] ?? []), true),
    'la API de catálogos busca formas de pago en mayúsculas y sin acento'
);

echo "\nRESULTADO: {$checks} comprobaciones HTTP correctas.\n";
