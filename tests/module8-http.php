<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/configuracion.php';

/**
 * Pruebas HTTP del módulo 8: permisos, empresa, emisor, numeración,
 * usuarios, correo y aislamiento. Restaura la configuración al finalizar.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$tenant2 = null;
$tempUserId = null;
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$tenantBefore = $pdo->query('SELECT * FROM tenants WHERE id = 1')->fetch();
$configBefore = $pdo->query('SELECT * FROM tenant_config WHERE tenant_id = 1')->fetch();
$sequenceBefore = $pdo->query("SELECT * FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = '05' LIMIT 1")->fetch();
$suffix = (string) getmypid();
$userContador = 'm8_contador_' . $suffix;
$userOther = 'm8_other_' . $suffix;

function checkHttp8(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http8(string $method, string $url, array &$jar, array $data = []): array
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
        CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => $headers,
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

function login8(string $username, string $password, array &$jar): string
{
    $login = http8('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    http8('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    $dashboard = http8('GET', 'views/dashboard.php', $jar);
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dashboard['body'], $meta) ? $meta[1] : '';
}

try {
    $anonymous = [];
    checkHttp8(http8('GET', 'api/configuracion.php?action=data', $anonymous)['status'] === 401, 'la configuración exige autenticación');

    $adminJar = [];
    $adminCsrf = login8('admin', 'Demo1234!', $adminJar);
    $adminId = (int) $pdo->query("SELECT id FROM users WHERE usuario = 'admin' LIMIT 1")->fetchColumn();
    checkHttp8($adminCsrf !== '' && $adminId > 0, 'el administrador inicia sesión');

    $page = http8('GET', 'views/configuracion.php', $adminJar);
    checkHttp8($page['status'] === 200 && str_contains($page['body'], 'config-tabs'), 'la pantalla de configuración carga para el administrador');
    $data = http8('GET', 'api/configuracion.php?action=data', $adminJar);
    checkHttp8($data['status'] === 200 && ($data['json']['success'] ?? false), 'la API de configuración responde');
    checkHttp8(isset($data['json']['tenant']['nit'], $data['json']['config']['establecimiento'], $data['json']['users']), 'la configuración incluye empresa, emisor y usuarios');
    checkHttp8(count($data['json']['sequences'] ?? []) >= 6, 'la configuración incluye las series');
    checkHttp8(($data['json']['mail']['transport'] ?? '') !== '', 'la configuración informa el transporte de correo');

    // Usuarios sin permisos.
    $hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (1,'Contador temporal M8',:usuario,:email,:hash,'CONTADOR')")
        ->execute(['usuario' => $userContador, 'email' => $userContador . '@test.local', 'hash' => $hash]);
    $contadorJar = [];
    login8($userContador, 'Demo1234!', $contadorJar);
    checkHttp8(http8('GET', 'api/configuracion.php?action=data', $contadorJar)['status'] === 403, 'el contador no puede leer la configuración');
    checkHttp8(http8('GET', 'views/configuracion.php', $contadorJar)['status'] === 403, 'la pantalla de configuración bloquea al contador');

    // CSRF.
    checkHttp8(http8('POST', 'api/configuracion.php', $adminJar, ['action' => 'empresa'])['status'] === 403, 'la configuración exige CSRF');

    // Empresa: inválida y válida.
    $company = [
        'csrf_token' => $adminCsrf, 'action' => 'empresa',
        'nit' => $tenantBefore['nit'], 'razon_social' => $tenantBefore['razon_social'],
        'nombre_comercial' => $tenantBefore['nombre_comercial'],
        'codigo_actividad' => $tenantBefore['codigo_actividad'], 'direccion' => $tenantBefore['direccion'],
        'telefono' => $tenantBefore['telefono'], 'email' => $tenantBefore['email'],
        'numero_registro_mh' => $tenantBefore['numero_registro_mh'],
    ];
    $invalid = http8('POST', 'api/configuracion.php', $adminJar, ['nit' => 'invalido'] + $company);
    checkHttp8($invalid['status'] === 422 && isset($invalid['json']['errors']['nit']), 'la empresa rechaza un NIT inválido');
    $updated = http8('POST', 'api/configuracion.php', $adminJar, $company);
    checkHttp8($updated['status'] === 200 && ($updated['json']['success'] ?? false), 'el administrador guarda los datos de la empresa');

    // Emisor: producción rechazada y guardado válido.
    $emitter = [
        'csrf_token' => $adminCsrf, 'action' => 'emisor',
        'establecimiento' => $configBefore['establecimiento'], 'distrito_emisor' => $configBefore['distrito_emisor'],
        'departamento_emisor' => $configBefore['departamento_emisor'],
        'municipio_emisor' => $configBefore['municipio_emisor'],
        'timezone' => $configBefore['timezone'], 'mh_mode' => 'SIMULACION',
    ];
    $production = http8('POST', 'api/configuracion.php', $adminJar, ['mh_mode' => 'PRODUCCION'] + $emitter);
    checkHttp8($production['status'] === 422 && isset($production['json']['errors']['mh_mode']), 'el emisor rechaza el entorno de producción');
    checkHttp8(http8('POST', 'api/configuracion.php', $adminJar, $emitter)['status'] === 200, 'el administrador guarda la configuración del emisor');

    // Numeración.
    $invalidSequence = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'sequence', 'id' => $data['json']['sequences'][0]['id'],
        'prefijo' => 'DTE', 'numero_inicial' => '1', 'numero_actual' => '0', 'activo' => '1',
    ]);
    checkHttp8($invalidSequence['status'] === 422, 'la numeración rechaza bajar del último emitido');
    $sequenceUpdated = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'sequence', 'id' => $sequenceBefore['id'],
        'prefijo' => 'TMP', 'numero_inicial' => (string) $sequenceBefore['numero_inicial'],
        'numero_actual' => (string) $sequenceBefore['numero_actual'], 'activo' => '1',
    ]);
    checkHttp8($sequenceUpdated['status'] === 200, 'el administrador actualiza una serie');
    $restoreSequence = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'sequence', 'id' => $sequenceBefore['id'],
        'prefijo' => $sequenceBefore['prefijo'], 'numero_inicial' => (string) $sequenceBefore['numero_inicial'],
        'numero_actual' => (string) $sequenceBefore['numero_actual'], 'activo' => (string) $sequenceBefore['activo'],
    ]);
    checkHttp8($restoreSequence['status'] === 200, 'la serie se restaura por la API');

    // Usuarios: la empresa solo consulta; la gestión es del superadministrador.
    $username = 'm8_http_' . $suffix;
    $blockedCreate = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'user-create', 'nombre' => 'Usuario HTTP M8',
        'usuario' => $username, 'email' => $username . '@test.local', 'rol' => 'VENDEDOR',
        'activo' => '1', 'password' => 'Prueba12345!', 'password_confirmation' => 'Prueba12345!',
    ]);
    checkHttp8($blockedCreate['status'] === 403, 'el administrador de empresa no puede crear usuarios');
    $blockedUpdate = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'user-update', 'id' => $adminId,
        'nombre' => 'Administrador Demo', 'email' => 'admin@facturasv.local', 'rol' => 'VENDEDOR', 'activo' => '1',
    ]);
    checkHttp8($blockedUpdate['status'] === 403, 'el administrador de empresa no puede editar usuarios');
    $blockedPassword = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'user-password', 'id' => $adminId,
        'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
    ]);
    checkHttp8($blockedPassword['status'] === 403, 'el administrador de empresa no puede restablecer contraseñas');
    checkHttp8(str_contains((string) ($blockedCreate['json']['message'] ?? ''), 'superadministrador'), 'la API explica que la gestión es del superadministrador');

    // Correo: solo validación, sin enviar.
    $mailInvalid = http8('POST', 'api/configuracion.php', $adminJar, [
        'csrf_token' => $adminCsrf, 'action' => 'mail-test', 'destinatario' => 'no-es-correo',
    ]);
    checkHttp8($mailInvalid['status'] === 422 && isset($mailInvalid['json']['errors']['destinatario']), 'la prueba de correo valida el destinatario');

    // Historial.
    checkHttp8($pdo->query('SELECT COUNT(*) FROM configuracion_cambios WHERE id > ' . $auditStart)->fetchColumn() > 0, 'los cambios quedan auditados');

    // Otra empresa: datos propios y sin acceso a los ajenos.
    $pdo->prepare("INSERT INTO tenants (nit,razon_social,nombre_comercial,codigo_actividad,direccion,telefono,email,numero_registro_mh)
        VALUES ('0614-666666-666-6','Empresa config aislada','Aislada config','00000','Prueba','0000-0000','c@aislada.local','0000000')")->execute();
    $tenant2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tenant_config (tenant_id,mh_mode,timezone,moneda,distrito_emisor,establecimiento) VALUES (:id,'SIMULACION','America/El_Salvador','USD','14','M001P001')")
        ->execute(['id' => $tenant2]);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (:tenant,'Admin aislado',:usuario,:email,:hash,'ADMIN')")
        ->execute(['tenant' => $tenant2, 'usuario' => $userOther, 'email' => $userOther . '@test.local', 'hash' => $hash]);
    $otherJar = [];
    $otherCsrf = login8($userOther, 'Demo1234!', $otherJar);
    $otherData = http8('GET', 'api/configuracion.php?action=data', $otherJar);
    checkHttp8($otherData['status'] === 200 && ($otherData['json']['tenant']['nit'] ?? '') === '0614-666666-666-6', 'otra empresa ve solo su configuración');
    checkHttp8(count($otherData['json']['users'] ?? []) === 1, 'otra empresa solo ve sus usuarios');
    $crossUser = http8('POST', 'api/configuracion.php', $otherJar, [
        'csrf_token' => $otherCsrf, 'action' => 'user-update', 'id' => 1,
        'nombre' => 'Intruso', 'email' => 'intruso@test.local', 'rol' => 'ADMIN', 'activo' => '1',
    ]);
    checkHttp8($crossUser['status'] === 403, 'otra empresa tampoco puede editar usuarios ajenos');
    $crossSequence = http8('POST', 'api/configuracion.php', $otherJar, [
        'csrf_token' => $otherCsrf, 'action' => 'sequence', 'id' => $sequenceBefore['id'],
        'prefijo' => 'XXX', 'numero_inicial' => '1', 'numero_actual' => '0', 'activo' => '1',
    ]);
    checkHttp8($crossSequence['status'] === 409, 'otra empresa no puede editar series ajenas');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    if ($tempUserId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $tempUserId]);
    $pdo->prepare('DELETE FROM users WHERE tenant_id = 1 AND usuario = :usuario')->execute(['usuario' => $userContador]);
    if ($tenant2 !== null) {
        $pdo->prepare('DELETE FROM document_sequences WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM users WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenant2]);
    }
    if (is_array($tenantBefore)) {
        $pdo->prepare(
            'UPDATE tenants SET nit = :nit, razon_social = :razon_social, nombre_comercial = :nombre_comercial,
                codigo_actividad = :codigo_actividad, direccion = :direccion, telefono = :telefono,
                email = :email, numero_registro_mh = :numero_registro_mh WHERE id = 1'
        )->execute([
            'nit' => $tenantBefore['nit'], 'razon_social' => $tenantBefore['razon_social'],
            'nombre_comercial' => $tenantBefore['nombre_comercial'], 'codigo_actividad' => $tenantBefore['codigo_actividad'],
            'direccion' => $tenantBefore['direccion'], 'telefono' => $tenantBefore['telefono'],
            'email' => $tenantBefore['email'], 'numero_registro_mh' => $tenantBefore['numero_registro_mh'],
        ]);
    }
    if (is_array($configBefore)) {
        $pdo->prepare(
            'UPDATE tenant_config SET mh_mode = :mh_mode, timezone = :timezone, moneda = :moneda,
                distrito_emisor = :distrito_emisor, departamento_emisor = :departamento_emisor,
                municipio_emisor = :municipio_emisor, establecimiento = :establecimiento WHERE tenant_id = 1'
        )->execute([
            'mh_mode' => $configBefore['mh_mode'], 'timezone' => $configBefore['timezone'],
            'moneda' => $configBefore['moneda'], 'distrito_emisor' => $configBefore['distrito_emisor'],
            'departamento_emisor' => $configBefore['departamento_emisor'],
            'municipio_emisor' => $configBefore['municipio_emisor'],
            'establecimiento' => $configBefore['establecimiento'],
        ]);
    }
    if (is_array($sequenceBefore)) {
        $pdo->prepare(
            'UPDATE document_sequences SET prefijo = :prefijo, numero_inicial = :numero_inicial,
                numero_actual = :numero_actual, activo = :activo WHERE id = :id'
        )->execute([
            'prefijo' => $sequenceBefore['prefijo'], 'numero_inicial' => $sequenceBefore['numero_inicial'],
            'numero_actual' => $sequenceBefore['numero_actual'], 'activo' => $sequenceBefore['activo'],
            'id' => $sequenceBefore['id'],
        ]);
    }
    echo "Datos temporales eliminados.\n";
}
