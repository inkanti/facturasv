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

/**
 * Pruebas HTTP del módulo 14: página, API, permisos, movimientos y kardex.
 * Crea dos usuarios y algunos movimientos temporales; los elimina al final.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$createdMovements = [];
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$users = ['contador_m14', 'vendedor_m14'];

function checkHttp14(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO HTTP M14: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function http14(string $method, string $url, array &$jar, array $data = []): array
{
    global $base;
    $curl = curl_init($base . $url);
    $headers = ['Accept: application/json, text/html'];
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
        CURLOPT_TIMEOUT => 60,
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

function login14(string $usuario, string $password, array &$jar): array
{
    $page = http14('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $matches);
    return http14('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '',
        'usuario' => $usuario,
        'password' => $password,
    ]);
}

function module14TempUser(PDO $pdo, string $usuario, string $rol): void
{
    $pdo->prepare('DELETE FROM users WHERE usuario = :usuario')->execute(['usuario' => $usuario]);
    $pdo->prepare(
        "INSERT INTO users (tenant_id, nombre, usuario, email, password_hash, rol, activo)
         VALUES (1, :nombre, :usuario, :email, :hash, :rol, 1)"
    )->execute([
        'nombre' => 'Usuario temporal ' . $rol,
        'usuario' => $usuario,
        'email' => $usuario . '@example.local',
        'hash' => password_hash('Temporal1234!', PASSWORD_DEFAULT),
        'rol' => $rol,
    ]);
}

function module14MetaCsrf(string $body): string
{
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $body, $matches) ? $matches[1] : '';
}

try {
    $adminJar = [];
    $adminLogin = login14('admin', 'Demo1234!', $adminJar);
    checkHttp14($adminLogin['status'] === 200 && ($adminLogin['json']['success'] ?? false), 'el administrador inicia sesión');

    $page = http14('GET', 'views/inventario.php', $adminJar);
    checkHttp14($page['status'] === 200 && str_contains($page['body'], 'Módulo 14') && str_contains($page['body'], 'Nuevo movimiento'), 'la página de inventario carga');
    $csrf = module14MetaCsrf($page['body']);
    checkHttp14($csrf !== '', 'la página entrega el token CSRF');

    $summary = http14('GET', 'api/inventario.php?action=resumen', $adminJar);
    checkHttp14($summary['status'] === 200 && ($summary['json']['success'] ?? false), 'la API entrega el resumen de inventario');
    checkHttp14((int) ($summary['json']['productos_controlados'] ?? 0) >= 2, 'el resumen informa los productos controlados');
    checkHttp14(($summary['json']['fecha_inicio'] ?? '') !== '', 'el resumen informa la fecha de inicio');
    checkHttp14((int) ($summary['json']['pendientes'] ?? -1) === 0, 'el resumen no reporta documentos pendientes de conciliar');

    $options = http14('GET', 'api/inventario.php?action=productos', $adminJar);
    checkHttp14($options['status'] === 200 && count($options['json']['items'] ?? []) >= 2, 'la API devuelve los productos con inventario');
    $product = null;
    foreach ($options['json']['items'] as $item) {
        if (($item['codigo'] ?? '') === 'PROD-001') {
            $product = $item;
            break;
        }
    }
    checkHttp14($product !== null, 'la API devuelve el producto demo');
    $productId = (int) $product['id'];
    $baseline = (float) $product['stock_actual'];

    $stock = http14('GET', 'api/inventario.php?action=existencias', $adminJar);
    checkHttp14($stock['status'] === 200 && count($stock['json']['items'] ?? []) >= 2, 'la API devuelve las existencias');
    $movements = http14('GET', 'api/inventario.php?action=movimientos', $adminJar);
    checkHttp14($movements['status'] === 200 && isset($movements['json']['pagination']), 'la API devuelve los movimientos');
    $kardex = http14('GET', 'api/inventario.php?action=kardex&producto_id=' . $productId, $adminJar);
    checkHttp14($kardex['status'] === 200 && ($kardex['json']['producto']['codigo'] ?? '') === 'PROD-001', 'la API devuelve el kardex del producto');

    $create = http14('POST', 'api/inventario.php', $adminJar, [
        'action' => 'movimiento-create', 'csrf_token' => $csrf, 'producto_id' => $productId,
        'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'tipo' => 'ENTRADA', 'cantidad' => '1', 'motivo' => 'Entrada HTTP temporal del módulo 14',
    ]);
    checkHttp14($create['status'] === 200 && ($create['json']['success'] ?? false), 'el administrador registra una entrada');
    $movementId = (int) ($create['json']['id'] ?? 0);
    $createdMovements[] = $movementId;
    checkHttp14(abs((float) ($create['json']['stock_actual'] ?? 0) - ($baseline + 1)) < 0.0001, 'la entrada actualiza la existencia');

    $exit = http14('POST', 'api/inventario.php', $adminJar, [
        'action' => 'movimiento-create', 'csrf_token' => $csrf, 'producto_id' => $productId,
        'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'tipo' => 'SALIDA', 'cantidad' => '1', 'motivo' => 'Salida HTTP temporal del módulo 14',
    ]);
    checkHttp14($exit['status'] === 200 && abs((float) ($exit['json']['stock_actual'] ?? 0) - $baseline) < 0.0001, 'la salida devuelve la existencia a la base');
    $createdMovements[] = (int) ($exit['json']['id'] ?? 0);

    $invalid = http14('POST', 'api/inventario.php', $adminJar, [
        'action' => 'movimiento-create', 'csrf_token' => $csrf, 'producto_id' => $productId,
        'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'tipo' => 'SALIDA', 'cantidad' => '999999', 'motivo' => 'Salida HTTP inválida del módulo 14',
    ]);
    checkHttp14($invalid['status'] === 422 && isset($invalid['json']['errors']['cantidad']), 'la API rechaza una salida mayor que la existencia');

    $noCsrf = http14('POST', 'api/inventario.php', $adminJar, [
        'action' => 'movimiento-create', 'producto_id' => $productId, 'tipo' => 'ENTRADA',
        'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'cantidad' => '1', 'motivo' => 'Sin CSRF',
    ]);
    checkHttp14($noCsrf['status'] === 403, 'la API exige CSRF');

    $csv = http14('GET', 'api/inventario.php?action=export&report=existencias', $adminJar);
    checkHttp14($csv['status'] === 200 && str_contains($csv['body'], 'Código') && str_contains($csv['body'], 'Existencia'), 'las existencias se exportan en CSV');

    $products = http14('GET', 'api/productos.php?action=list&search=PROD-001', $adminJar);
    checkHttp14($products['status'] === 200 && abs((float) ($products['json']['items'][0]['stock'] ?? -1) - $baseline) < 0.0001, 'el catálogo de productos muestra el stock calculado');

    module14TempUser($pdo, 'contador_m14', 'CONTADOR');
    $contadorJar = [];
    $contadorLogin = login14('contador_m14', 'Temporal1234!', $contadorJar);
    checkHttp14($contadorLogin['status'] === 200, 'el contador inicia sesión');
    $contadorPage = http14('GET', 'views/inventario.php', $contadorJar);
    checkHttp14($contadorPage['status'] === 200, 'el contador consulta el inventario');
    $contadorCsrf = module14MetaCsrf($contadorPage['body']);
    $contadorCreate = http14('POST', 'api/inventario.php', $contadorJar, [
        'action' => 'movimiento-create', 'csrf_token' => $contadorCsrf, 'producto_id' => $productId,
        'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'tipo' => 'ENTRADA', 'cantidad' => '1', 'motivo' => 'Movimiento no permitido',
    ]);
    checkHttp14($contadorCreate['status'] === 403, 'el contador no registra movimientos');

    module14TempUser($pdo, 'vendedor_m14', 'VENDEDOR');
    $vendedorJar = [];
    $vendedorLogin = login14('vendedor_m14', 'Temporal1234!', $vendedorJar);
    checkHttp14($vendedorLogin['status'] === 200, 'el vendedor inicia sesión');
    $vendedorPage = http14('GET', 'views/inventario.php', $vendedorJar);
    checkHttp14($vendedorPage['status'] === 200, 'el vendedor consulta el inventario');
    $vendedorCsrf = module14MetaCsrf($vendedorPage['body']);
    $vendedorCreate = http14('POST', 'api/inventario.php', $vendedorJar, [
        'action' => 'movimiento-create', 'csrf_token' => $vendedorCsrf, 'producto_id' => $productId,
        'fecha' => (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d'),
        'tipo' => 'ENTRADA', 'cantidad' => '1', 'motivo' => 'Entrada del vendedor temporal',
    ]);
    checkHttp14($vendedorCreate['status'] === 200, 'el vendedor registra una entrada');
    $createdMovements[] = (int) ($vendedorCreate['json']['id'] ?? 0);

    $superJar = [];
    $superLogin = login14('superadmin', 'SuperDemo1234!', $superJar);
    checkHttp14($superLogin['status'] === 200, 'el superadministrador inicia sesión');
    $superApi = http14('GET', 'api/inventario.php?action=resumen', $superJar);
    checkHttp14($superApi['status'] === 401, 'el superadministrador no accede al inventario de una empresa');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($createdMovements !== []) {
        $pdo->exec('DELETE FROM inventario_movimientos WHERE id IN (' . implode(',', $createdMovements) . ')');
    }
    $pdo->exec("DELETE FROM users WHERE usuario IN ('contador_m14', 'vendedor_m14')");
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
