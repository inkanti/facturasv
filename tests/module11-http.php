<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP del módulo 11: login del superadministrador, panel, creación
 * de empresa y usuarios, desactivación/reactivación y registro público cerrado.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$tenantId = null;
$suffix = (string) getmypid();
$adminUser = 'm11h_admin_' . $suffix;
$vendorUser = 'm11h_vendor_' . $suffix;
$nit = '0614-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '-888-8';

function checkHttp11(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http11(string $method, string $url, array &$jar, array $data = []): array
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

function login11(string $username, string $password, array &$jar, bool $remember = false): array
{
    $login = http11('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    $data = [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ];
    if ($remember) $data['remember'] = '1';
    $response = http11('POST', 'api/auth.php?action=login', $jar, $data);
    $isSuperadmin = (bool) ($response['json']['superadmin'] ?? false);
    $page = http11('GET', $isSuperadmin ? 'views/superadmin.php' : 'views/dashboard.php', $jar);
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $meta);
    return [
        'status' => $response['status'],
        'json' => $response['json'],
        'superadmin' => $isSuperadmin,
        'csrf' => $meta[1] ?? '',
        'page_status' => $page['status'],
        'remember_cookie' => $jar['facturasv_remember'] ?? null,
    ];
}

try {
    $anonymous = [];
    checkHttp11(http11('GET', 'api/superadmin.php?action=companies', $anonymous)['status'] === 401, 'la API de superadministración exige autenticación');

    $superJar = [];
    $super = login11('superadmin', 'SuperDemo1234!', $superJar);
    checkHttp11($super['status'] === 200 && $super['superadmin'] === true, 'el superadministrador inicia sesión');
    checkHttp11($super['page_status'] === 200 && $super['csrf'] !== '', 'el panel de superadministración carga');

    $emailJar = [];
    $emailLogin = login11('superadmin@facturasv.local', 'SuperDemo1234!', $emailJar);
    checkHttp11($emailLogin['status'] === 200 && $emailLogin['superadmin'] === true, 'el superadministrador inicia sesión con su correo');

    $rememberJar = [];
    $rememberLogin = login11('admin', 'Demo1234!', $rememberJar, true);
    checkHttp11($rememberLogin['status'] === 200 && $rememberLogin['remember_cookie'] === '1', 'la opción mantener sesión activa crea la cookie persistente');

    $list = http11('GET', 'api/superadmin.php?action=companies', $superJar);
    checkHttp11($list['status'] === 200 && ($list['json']['success'] ?? false) && count($list['json']['items'] ?? []) >= 1, 'el panel lista las empresas');

    $create = http11('POST', 'api/superadmin.php', $superJar, [
        'action' => 'company-create', 'csrf_token' => $super['csrf'],
        'nit' => $nit, 'razon_social' => 'Empresa HTTP M11 SA de CV',
        'nombre_comercial' => 'HTTP M11', 'codigo_actividad' => '00000',
        'direccion' => 'San Salvador, El Salvador', 'telefono' => '2222-8888',
        'email' => 'empresa.http.m11@test.local', 'numero_registro_mh' => '8888888',
        'admin_nombre' => 'Administrador HTTP M11', 'admin_usuario' => $adminUser,
        'admin_email' => 'admin.http.m11@test.local',
        'admin_password' => 'Admin12345!', 'admin_password_confirmation' => 'Admin12345!',
    ]);
    checkHttp11($create['status'] === 201 && ($create['json']['success'] ?? false), 'el superadministrador crea una empresa');
    $tenantId = (int) ($create['json']['tenant_id'] ?? 0);
    checkHttp11($tenantId > 0, 'la empresa creada devuelve su identificador');

    $users = http11('GET', 'api/superadmin.php?action=users&tenant_id=' . $tenantId, $superJar);
    checkHttp11($users['status'] === 200 && count($users['json']['users'] ?? []) === 1, 'la empresa nace con un administrador');

    $vendor = http11('POST', 'api/superadmin.php', $superJar, [
        'action' => 'user-create', 'csrf_token' => $super['csrf'], 'tenant_id' => $tenantId,
        'nombre' => 'Vendedor HTTP M11', 'usuario' => $vendorUser,
        'email' => 'vendedor.http.m11@test.local', 'rol' => 'VENDEDOR',
        'password' => 'Venta12345!', 'password_confirmation' => 'Venta12345!',
    ]);
    checkHttp11($vendor['status'] === 200 && ($vendor['json']['success'] ?? false), 'el superadministrador asigna un usuario');
    $users = http11('GET', 'api/superadmin.php?action=users&tenant_id=' . $tenantId, $superJar);
    checkHttp11(count($users['json']['users'] ?? []) === 2, 'la empresa queda con dos usuarios');
    $vendorId = 0;
    foreach ($users['json']['users'] as $row) {
        if (($row['usuario'] ?? '') === $vendorUser) $vendorId = (int) $row['id'];
    }
    checkHttp11($vendorId > 0, 'la API devuelve el usuario asignado');

    $adminJar = [];
    $admin = login11($adminUser, 'Admin12345!', $adminJar);
    checkHttp11($admin['status'] === 200 && $admin['superadmin'] === false && $admin['page_status'] === 200, 'el administrador asignado inicia sesión');

    $vendorJar = [];
    $vendorLogin = login11($vendorUser, 'Venta12345!', $vendorJar);
    checkHttp11($vendorLogin['status'] === 200 && $vendorLogin['page_status'] === 200, 'el vendedor asignado inicia sesión');

    $reset = http11('POST', 'api/superadmin.php', $superJar, [
        'action' => 'user-reset-password', 'csrf_token' => $super['csrf'], 'tenant_id' => $tenantId,
        'id' => $vendorId, 'password' => 'NuevaVenta12345!', 'password_confirmation' => 'NuevaVenta12345!',
    ]);
    checkHttp11($reset['status'] === 200 && ($reset['json']['success'] ?? false), 'el superadministrador restablece una contraseña');
    $newVendorJar = [];
    $newVendorLogin = login11($vendorUser, 'NuevaVenta12345!', $newVendorJar);
    checkHttp11($newVendorLogin['status'] === 200 && $newVendorLogin['page_status'] === 200, 'el usuario entra con la contraseña restablecida');

    $off = http11('POST', 'api/superadmin.php', $superJar, [
        'action' => 'company-toggle', 'csrf_token' => $super['csrf'], 'tenant_id' => $tenantId, 'activo' => '0',
    ]);
    checkHttp11($off['status'] === 200 && ($off['json']['activo'] ?? true) === false, 'el superadministrador desactiva la empresa');

    $blockedJar = [];
    $blocked = login11($adminUser, 'Admin12345!', $blockedJar);
    checkHttp11($blocked['status'] === 401, 'un usuario de empresa desactivada no puede iniciar sesión');

    $on = http11('POST', 'api/superadmin.php', $superJar, [
        'action' => 'company-toggle', 'csrf_token' => $super['csrf'], 'tenant_id' => $tenantId, 'activo' => '1',
    ]);
    checkHttp11($on['status'] === 200 && ($on['json']['activo'] ?? false) === true, 'el superadministrador reactiva la empresa');

    $renewedJar = [];
    $renewed = login11($adminUser, 'Admin12345!', $renewedJar);
    checkHttp11($renewed['status'] === 200 && $renewed['page_status'] === 200, 'la empresa reactivada permite iniciar sesión');

    $publicJar = [];
    $loginPage = http11('GET', 'views/login.php', $publicJar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $loginPage['body'], $matches);
    $public = http11('POST', 'api/auth.php?action=register', $publicJar, [
        'csrf_token' => $matches[1] ?? '', 'nit' => '0614-000000-000-9',
        'razon_social' => 'No permitida', 'nombre_comercial' => 'No permitida',
        'codigo_actividad' => '00000', 'direccion' => 'San Salvador',
        'telefono' => '2222-0000', 'email' => 'no@test.local',
        'numero_registro_mh' => '0000000', 'nombre' => 'No permitida',
        'usuario' => 'no_permitida_' . $suffix, 'usuario_email' => 'no@test.local',
        'password' => 'NoPermitida123!', 'password_confirmation' => 'NoPermitida123!',
    ]);
    checkHttp11($public['status'] === 403, 'el registro público está deshabilitado');

    checkHttp11(http11('GET', 'views/superadmin.php', $adminJar)['status'] === 403, 'un administrador de empresa no entra al panel global');
    checkHttp11(http11('GET', 'api/superadmin.php?action=companies', $adminJar)['status'] === 401, 'un administrador de empresa no usa la API global');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($tenantId !== null) {
        $pdo->prepare('DELETE FROM contabilidad_asientos WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM contabilidad_config WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM contabilidad_cuentas WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM contabilidad_secuencias WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM inventario_movimientos WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM inventario_config WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM configuracion_cambios WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM document_sequences WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM users WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        foreach (['mh_municipios', 'mh_departamentos', 'mh_formas_pago', 'mh_condiciones_operacion', 'mh_unidades_medida', 'mh_tipos_item', 'mh_tipos_establecimiento', 'mh_tipos_documento'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = :tenant_id")->execute(['tenant_id' => $tenantId]);
        }
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
    }
    echo "Datos temporales eliminados.\n";
}
