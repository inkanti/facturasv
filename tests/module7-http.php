<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/reportes.php';

/**
 * Pruebas HTTP del módulo 7: dashboard, reportes, CSV y envío por correo.
 * Usa cookies en memoria y elimina todo lo temporal al finalizar.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$tenant2 = null;
$contadorId = null;
$clientId = null;
$documentId = null;
$originalSequences = [];
$suffix = (string) getmypid();
$userContador = 'm7_contador_' . $suffix;
$userOther = 'm7_other_' . $suffix;

function checkHttp7(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http7(string $method, string $url, array &$jar, array $data = []): array
{
    global $base;
    $curl = curl_init($base . $url);
    $headers = ['Accept: application/json, text/csv, text/html'];
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

function login7(string $username, string $password, array &$jar): string
{
    $login = http7('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    http7('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    $dashboard = http7('GET', 'views/dashboard.php', $jar);
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dashboard['body'], $meta) ? $meta[1] : '';
}

try {
    $today = (new DateTimeImmutable('now', new DateTimeZone(reportTimezone(1))))->format('Y-m-d');
    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    $originalSequences = $pdo->query('SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1')->fetchAll(PDO::FETCH_KEY_PAIR);

    $anonymous = [];
    checkHttp7(http7('GET', 'api/reportes.php?action=dashboard', $anonymous)['status'] === 401, 'el dashboard exige autenticación');

    $adminJar = [];
    $adminCsrf = login7('admin', 'Demo1234!', $adminJar);
    checkHttp7($adminCsrf !== '', 'el administrador inicia sesión');

    $dashboardPage = http7('GET', 'views/dashboard.php', $adminJar);
    checkHttp7($dashboardPage['status'] === 200 && str_contains($dashboardPage['body'], 'dashboard-kpis'), 'la pantalla del dashboard carga');
    $dashboardData = http7('GET', 'api/reportes.php?action=dashboard', $adminJar);
    checkHttp7($dashboardData['status'] === 200 && ($dashboardData['json']['success'] ?? false), 'la API del dashboard responde');
    checkHttp7(count($dashboardData['json']['serie'] ?? []) === 6, 'la serie del dashboard tiene seis meses');
    checkHttp7(is_array($dashboardData['json']['top_clientes'] ?? null) && is_array($dashboardData['json']['recientes'] ?? null), 'el dashboard devuelve clientes y documentos recientes');

    $reportsPage = http7('GET', 'views/reportes.php', $adminJar);
    checkHttp7($reportsPage['status'] === 200 && str_contains($reportsPage['body'], 'report-filters'), 'la pantalla de reportes carga');

    $range = 'fecha_desde=' . $today . '&fecha_hasta=' . $today;
    $reportData = http7('GET', 'api/reportes.php?action=data&' . $range, $adminJar);
    checkHttp7($reportData['status'] === 200, 'la API de reportes responde con el rango');
    checkHttp7(isset($reportData['json']['summary']['total'], $reportData['json']['by_client'], $reportData['json']['iva']), 'el reporte incluye resumen, clientes e IVA');
    checkHttp7(($reportData['json']['range']['desde'] ?? '') === $today, 'el rango devuelto coincide con el solicitado');

    $csv = http7('GET', 'api/reportes.php?action=export&report=clientes&' . $range, $adminJar);
    checkHttp7($csv['status'] === 200, 'la exportación de clientes responde');
    checkHttp7(str_starts_with($csv['body'], "\xEF\xBB\xBF"), 'el CSV incluye la marca UTF-8 para Excel');
    checkHttp7(str_contains($csv['body'], 'Cliente;Documento;Documentos'), 'el CSV usa encabezados y separador por punto y coma');
    checkHttp7(str_contains((string) ($csv['headers']['content-disposition'] ?? ''), 'attachment'), 'el CSV se descarga como adjunto');
    checkHttp7(http7('GET', 'api/reportes.php?action=export&report=iva&' . $range, $adminJar)['status'] === 200, 'la exportación de IVA responde');
    checkHttp7(http7('GET', 'api/reportes.php?action=export&report=otro&' . $range, $adminJar)['status'] === 400, 'un reporte desconocido se rechaza');
    checkHttp7(http7('POST', 'api/reportes.php', $adminJar, [])['status'] === 405, 'la API de reportes solo acepta GET');

    // Usuario CONTADOR: consulta y exportación permitidas, envío de correo prohibido.
    $hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (1,'Contador temporal M7',:usuario,:email,:hash,'CONTADOR')")
        ->execute(['usuario' => $userContador, 'email' => $userContador . '@test.local', 'hash' => $hash]);
    $contadorId = (int) $pdo->lastInsertId();
    $contadorJar = [];
    $contadorCsrf = login7($userContador, 'Demo1234!', $contadorJar);
    checkHttp7(http7('GET', 'views/reportes.php', $contadorJar)['status'] === 200, 'el contador puede abrir reportes');
    checkHttp7(http7('GET', 'api/reportes.php?action=data&' . $range, $contadorJar)['status'] === 200, 'el contador puede consultar los reportes');
    checkHttp7(http7('GET', 'api/reportes.php?action=export&report=resumen&' . $range, $contadorJar)['status'] === 200, 'el contador puede exportar CSV');

    // Documento temporal: cliente sin correo, para no enviar correos reales.
    $location = $pdo->query(
        'SELECT d.id AS departamento_id, m.id AS municipio_id
         FROM mh_departamentos d INNER JOIN mh_municipios m
            ON m.tenant_id = d.tenant_id AND m.departamento_id = d.id
         WHERE d.tenant_id = 1 AND d.activo = 1 AND m.activo = 1 LIMIT 1'
    )->fetch();
    $pdo->prepare(
        "INSERT INTO clientes (tenant_id, tipo_documento, numero_documento, nombre, email, direccion,
            departamento_id, municipio_id, tipo_contribuyente, activo)
         VALUES (1, 'NINGUNO', NULL, 'Cliente HTTP M7 sin correo', NULL, 'San Salvador', :departamento, :municipio, 'OTRO', 1)"
    )->execute(['departamento' => $location['departamento_id'], 'municipio' => $location['municipio_id']]);
    $clientId = (int) $pdo->lastInsertId();

    $saved = http7('POST', 'api/documentos.php', $adminJar, [
        'action' => 'save', 'csrf_token' => $adminCsrf, 'tipo_dte' => '01', 'cliente_id' => (string) $clientId,
        'fecha_emision' => $today, 'condicion_operacion_id' => '1', 'forma_pago_id' => '1',
        'dias_credito' => '', 'emisor_distrito' => '14', 'receptor_distrito' => '01',
        'aplicar_iva_retenido' => '0', 'observaciones' => 'Prueba HTTP del módulo 7',
        'items' => [['producto_id' => '1', 'cantidad' => '1', 'precio_unitario' => '113.00000000', 'descuento' => '0']],
    ]);
    checkHttp7($saved['status'] === 200 && (int) ($saved['json']['id'] ?? 0) > 0, 'el administrador guarda el documento de prueba');
    $documentId = (int) $saved['json']['id'];
    $emitted = http7('POST', 'api/documentos.php', $adminJar, ['action' => 'emit', 'csrf_token' => $adminCsrf, 'id' => $documentId]);
    checkHttp7($emitted['status'] === 200 && ($emitted['json']['estado'] ?? '') === 'PROCESADA', 'el documento de prueba se emite y procesa');

    $emails = $pdo->prepare('SELECT estado, transporte FROM documento_correos WHERE tenant_id = 1 AND documento_id = :id');
    $emails->execute(['id' => $documentId]);
    $emailRows = $emails->fetchAll();
    checkHttp7(
        count($emailRows) === 1 && $emailRows[0]['estado'] === 'OMITIDO',
        'la emisión sin correo del cliente registra el envío como omitido'
    );

    $emailEndpoint = http7('POST', 'api/documentos.php', $adminJar, ['action' => 'email', 'csrf_token' => $adminCsrf, 'id' => $documentId]);
    checkHttp7(
        $emailEndpoint['status'] === 422
        && str_contains((string) ($emailEndpoint['json']['message'] ?? ''), 'correo'),
        'el endpoint explica que el cliente no tiene correo'
    );
    $forbidden = http7('POST', 'api/documentos.php', $contadorJar, ['action' => 'email', 'csrf_token' => $contadorCsrf, 'id' => $documentId]);
    checkHttp7($forbidden['status'] === 403, 'el contador no puede enviar correos');

    $detail = http7('GET', 'api/documentos.php?action=get&id=' . $documentId, $adminJar);
    checkHttp7(count($detail['json']['correos'] ?? []) === 2, 'el detalle muestra el historial de correos');

    // Otra empresa: dashboard y reportes vacíos.
    $pdo->prepare("INSERT INTO tenants (nit,razon_social,nombre_comercial,codigo_actividad,direccion,telefono,email,numero_registro_mh)
        VALUES ('0614-777777-777-7','Empresa reportes aislada','Aislada reportes','00000','Prueba','0000-0000','r@aislada.local','0000000')")->execute();
    $tenant2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tenant_config (tenant_id,mh_mode,timezone,moneda,distrito_emisor,establecimiento) VALUES (:id,'SIMULACION','America/El_Salvador','USD','14','M001P001')")
        ->execute(['id' => $tenant2]);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (:tenant,'Admin aislado',:usuario,:email,:hash,'ADMIN')")
        ->execute(['tenant' => $tenant2, 'usuario' => $userOther, 'email' => $userOther . '@test.local', 'hash' => $hash]);
    $otherJar = [];
    login7($userOther, 'Demo1234!', $otherJar);
    $otherDashboard = http7('GET', 'api/reportes.php?action=dashboard', $otherJar);
    checkHttp7(($otherDashboard['json']['ingresos'] ?? -1) === 0 && ($otherDashboard['json']['documentos'] ?? -1) === 0, 'el dashboard de otra empresa no muestra ventas ajenas');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($documentId !== null) {
        $pdo->prepare('DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $documentId]);
        $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $documentId]);
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $documentId]);
    }
    if ($contadorId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $contadorId]);
    if ($clientId !== null) $pdo->prepare('DELETE FROM clientes WHERE tenant_id = 1 AND id = :id')->execute(['id' => $clientId]);
    if ($tenant2 !== null) {
        $pdo->prepare('DELETE FROM users WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenant2]);
    }
    if ($originalSequences !== []) {
        $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
        foreach ($originalSequences as $type => $value) $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
    }
    echo "Datos temporales eliminados.\n";
}
