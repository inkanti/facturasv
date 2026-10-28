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
 * Pruebas HTTP del módulo 13: página, API, permisos, asientos y reportes.
 * Crea un asiento manual, una cuenta y dos usuarios temporales; los elimina
 * al finalizar.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$createdEntries = [];
$createdAccounts = [];
$createdUsers = ['contador_m13', 'vendedor_m13'];
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();

function checkHttp13(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO HTTP M13: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function http13(string $method, string $url, array &$jar, array $data = []): array
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

function login13(string $usuario, string $password, array &$jar): array
{
    $page = http13('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $matches);
    return http13('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '',
        'usuario' => $usuario,
        'password' => $password,
    ]);
}

function module13TempUser(PDO $pdo, string $usuario, string $rol): void
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

try {
    $adminJar = [];
    $login = login13('admin', 'Demo1234!', $adminJar);
    checkHttp13($login['status'] === 200 && ($login['json']['success'] ?? false), 'el administrador inicia sesión');

    $page = http13('GET', 'views/contabilidad.php', $adminJar);
    checkHttp13($page['status'] === 200 && str_contains($page['body'], 'Módulo 13') && str_contains($page['body'], 'Nuevo asiento'), 'la página de contabilidad carga para el administrador');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page['body'], $meta);
    $csrf = $meta[1] ?? '';
    checkHttp13($csrf !== '', 'la página de contabilidad entrega el token CSRF');

    $summary = http13('GET', 'api/contabilidad.php?action=resumen', $adminJar);
    checkHttp13($summary['status'] === 200 && ($summary['json']['success'] ?? false), 'la API entrega el resumen contable');
    checkHttp13((int) ($summary['json']['cuentas'] ?? 0) >= 20, 'el resumen informa el catálogo de cuentas');
    checkHttp13((int) ($summary['json']['pendientes'] ?? -1) === 0, 'no hay documentos pendientes de contabilizar');

    $accountsResponse = http13('GET', 'api/contabilidad.php?action=cuentas', $adminJar);
    checkHttp13($accountsResponse['status'] === 200 && count($accountsResponse['json']['items'] ?? []) >= 20, 'la API devuelve el catálogo de cuentas');
    $accounts = [];
    foreach ($accountsResponse['json']['items'] as $account) {
        $accounts[(string) $account['codigo']] = $account;
    }
    checkHttp13(isset($accounts['1101'], $accounts['4101'], $accounts['2102']), 'el catálogo contiene caja, ventas e IVA débito');

    $configResponse = http13('GET', 'api/contabilidad.php?action=config', $adminJar);
    checkHttp13($configResponse['status'] === 200 && count($configResponse['json']['mapping'] ?? []) === 12, 'la API devuelve la configuración automática');
    $config = $configResponse['json']['config'];
    $sameConfig = http13('POST', 'api/contabilidad.php', $adminJar, array_merge($config, [
        'action' => 'config-save', 'csrf_token' => $csrf,
    ]));
    checkHttp13($sameConfig['status'] === 200 && str_contains((string) ($sameConfig['json']['message'] ?? ''), 'no tiene cambios'), 'guardar la misma configuración no genera cambios');

    $invalidConfig = $config;
    $invalidConfig['cuenta_caja_id'] = $accounts['4101']['id'];
    $invalidSave = http13('POST', 'api/contabilidad.php', $adminJar, array_merge($invalidConfig, [
        'action' => 'config-save', 'csrf_token' => $csrf,
    ]));
    checkHttp13($invalidSave['status'] === 422 && isset($invalidSave['json']['errors']['cuenta_caja_id']), 'la API rechaza una cuenta de tipo incorrecto en la configuración');

    $today = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');
    $entry = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'asiento-create', 'csrf_token' => $csrf, 'fecha' => $today,
        'concepto' => 'Asiento HTTP temporal del módulo 13',
        'lineas' => [
            ['cuenta_id' => $accounts['1101']['id'], 'descripcion' => 'Cargo HTTP', 'debe' => '50.00', 'haber' => ''],
            ['cuenta_id' => $accounts['4101']['id'], 'descripcion' => 'Abono HTTP', 'debe' => '', 'haber' => '50.00'],
        ],
    ]);
    checkHttp13($entry['status'] === 200 && ($entry['json']['success'] ?? false), 'el administrador crea un asiento manual por HTTP');
    $entryId = (int) ($entry['json']['id'] ?? 0);
    $createdEntries[] = $entryId;

    $unbalanced = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'asiento-create', 'csrf_token' => $csrf, 'fecha' => $today,
        'concepto' => 'Asiento HTTP desbalanceado',
        'lineas' => [
            ['cuenta_id' => $accounts['1101']['id'], 'debe' => '50.00', 'haber' => ''],
            ['cuenta_id' => $accounts['4101']['id'], 'debe' => '', 'haber' => '40.00'],
        ],
    ]);
    checkHttp13($unbalanced['status'] === 422 && isset($unbalanced['json']['errors']['lineas']), 'la API rechaza un asiento desbalanceado');

    $detail = http13('GET', 'api/contabilidad.php?action=asiento&id=' . $entryId, $adminJar);
    checkHttp13($detail['status'] === 200 && abs((float) ($detail['json']['asiento']['total_debe'] ?? 0) - 50.0) < 0.01, 'la API devuelve el detalle del asiento');

    $void = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'asiento-anular', 'csrf_token' => $csrf, 'id' => $entryId,
        'motivo' => 'Anulación de prueba HTTP del módulo 13',
    ]);
    checkHttp13($void['status'] === 200 && ($void['json']['success'] ?? false), 'el administrador anula el asiento manual');
    $detailAfter = http13('GET', 'api/contabilidad.php?action=asiento&id=' . $entryId, $adminJar);
    checkHttp13(($detailAfter['json']['asiento']['estado'] ?? '') === 'ANULADO', 'el asiento anulado conserva su estado');

    $pdo->prepare('DELETE FROM contabilidad_cuentas WHERE tenant_id = 1 AND codigo = :codigo')->execute(['codigo' => '9998']);
    $newAccount = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'cuenta-save', 'csrf_token' => $csrf, 'codigo' => '9998',
        'nombre' => 'Cuenta HTTP temporal', 'tipo' => 'ACTIVO', 'naturaleza' => 'DEUDORA',
        'permite_movimiento' => '1', 'activa' => '1',
    ]);
    checkHttp13($newAccount['status'] === 200 && (int) ($newAccount['json']['id'] ?? 0) > 0, 'el administrador crea una cuenta por HTTP');
    $accountId = (int) ($newAccount['json']['id'] ?? 0);
    $createdAccounts[] = $accountId;
    $duplicate = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'cuenta-save', 'csrf_token' => $csrf, 'codigo' => '9998',
        'nombre' => 'Cuenta HTTP duplicada', 'tipo' => 'ACTIVO', 'naturaleza' => 'DEUDORA',
        'permite_movimiento' => '1', 'activa' => '1',
    ]);
    checkHttp13($duplicate['status'] === 422 && isset($duplicate['json']['errors']['codigo']), 'la API rechaza un código de cuenta duplicado');
    $toggle = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'cuenta-toggle', 'csrf_token' => $csrf, 'id' => $accountId, 'activa' => '0',
    ]);
    checkHttp13($toggle['status'] === 200, 'la API desactiva una cuenta sin movimientos');

    $diary = http13('GET', 'api/contabilidad.php?action=diario&fecha_desde=' . $today . '&fecha_hasta=' . $today, $adminJar);
    checkHttp13($diary['status'] === 200 && abs((float) ($diary['json']['total_debe'] ?? 0) - (float) ($diary['json']['total_haber'] ?? -1)) < 0.01, 'el libro diario está cuadrado');
    $trial = http13('GET', 'api/contabilidad.php?action=balanza&fecha_desde=' . $today . '&fecha_hasta=' . $today, $adminJar);
    checkHttp13($trial['status'] === 200 && abs((float) ($trial['json']['total_debe'] ?? 0) - (float) ($trial['json']['total_haber'] ?? -1)) < 0.01, 'la balanza está cuadrada');
    $ledger = http13('GET', 'api/contabilidad.php?action=mayor&cuenta_id=' . $accounts['1101']['id'] . '&fecha_desde=' . $today . '&fecha_hasta=' . $today, $adminJar);
    checkHttp13($ledger['status'] === 200 && ($ledger['json']['cuenta']['codigo'] ?? '') === '1101', 'el libro mayor devuelve la cuenta solicitada');
    $income = http13('GET', 'api/contabilidad.php?action=resultados&fecha_desde=' . $today . '&fecha_hasta=' . $today, $adminJar);
    checkHttp13($income['status'] === 200 && isset($income['json']['utilidad_neta']), 'la API devuelve el estado de resultados');
    $balance = http13('GET', 'api/contabilidad.php?action=balance&fecha_hasta=' . $today, $adminJar);
    checkHttp13($balance['status'] === 200 && abs((float) ($balance['json']['diferencia'] ?? 1)) < 0.01, 'el balance general está cuadrado');

    $csv = http13('GET', 'api/contabilidad.php?action=export&report=balanza&fecha_desde=' . $today . '&fecha_hasta=' . $today, $adminJar);
    checkHttp13($csv['status'] === 200 && str_contains($csv['body'], 'Código') && str_contains($csv['body'], 'Saldo deudor'), 'la balanza se exporta en CSV');

    $noCsrf = http13('POST', 'api/contabilidad.php', $adminJar, [
        'action' => 'asiento-create', 'fecha' => $today, 'concepto' => 'Sin CSRF',
    ]);
    checkHttp13($noCsrf['status'] === 403, 'la API exige CSRF en las operaciones de escritura');

    module13TempUser($pdo, 'contador_m13', 'CONTADOR');
    $contadorJar = [];
    $contadorLogin = login13('contador_m13', 'Temporal1234!', $contadorJar);
    checkHttp13($contadorLogin['status'] === 200 && ($contadorLogin['json']['success'] ?? false), 'el contador inicia sesión');
    $contadorPage = http13('GET', 'views/contabilidad.php', $contadorJar);
    checkHttp13($contadorPage['status'] === 200 && str_contains($contadorPage['body'], 'Contabilidad'), 'el contador accede a contabilidad');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $contadorPage['body'], $contadorMeta);
    $contadorCsrf = $contadorMeta[1] ?? '';
    $contadorAccount = http13('POST', 'api/contabilidad.php', $contadorJar, [
        'action' => 'cuenta-save', 'csrf_token' => $contadorCsrf, 'codigo' => '9997',
        'nombre' => 'Cuenta no permitida', 'tipo' => 'ACTIVO', 'naturaleza' => 'DEUDORA',
        'permite_movimiento' => '1', 'activa' => '1',
    ]);
    checkHttp13($contadorAccount['status'] === 403, 'el contador no puede modificar el catálogo');

    module13TempUser($pdo, 'vendedor_m13', 'VENDEDOR');
    $vendedorJar = [];
    $vendedorLogin = login13('vendedor_m13', 'Temporal1234!', $vendedorJar);
    checkHttp13($vendedorLogin['status'] === 200, 'el vendedor inicia sesión');
    $vendedorPage = http13('GET', 'views/contabilidad.php', $vendedorJar);
    checkHttp13($vendedorPage['status'] === 403, 'el vendedor no accede a la página de contabilidad');
    $vendedorApi = http13('GET', 'api/contabilidad.php?action=resumen', $vendedorJar);
    checkHttp13($vendedorApi['status'] === 403, 'el vendedor no accede a la API de contabilidad');

    $superJar = [];
    $superLogin = login13('superadmin', 'SuperDemo1234!', $superJar);
    checkHttp13($superLogin['status'] === 200, 'el superadministrador inicia sesión');
    $superApi = http13('GET', 'api/contabilidad.php?action=resumen', $superJar);
    checkHttp13($superApi['status'] === 401, 'el superadministrador no accede a la contabilidad de una empresa');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($createdEntries !== []) {
        $pdo->exec('DELETE FROM contabilidad_asientos WHERE id IN (' . implode(',', $createdEntries) . ')');
    }
    if ($createdAccounts !== []) {
        $pdo->exec('DELETE FROM contabilidad_cuentas WHERE id IN (' . implode(',', $createdAccounts) . ')');
    }
    $pdo->exec("DELETE FROM contabilidad_cuentas WHERE tenant_id = 1 AND codigo IN ('9997', '9998')");
    $pdo->exec("DELETE FROM users WHERE usuario IN ('contador_m13', 'vendedor_m13')");
    $pdo->exec(
        'UPDATE contabilidad_secuencias SET numero_actual = (
            SELECT COALESCE(MAX(numero), 0) FROM contabilidad_asientos WHERE tenant_id = 1
        ) WHERE tenant_id = 1'
    );
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
