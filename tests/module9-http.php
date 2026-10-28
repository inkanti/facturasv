<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP del módulo 9: emisión en contingencia, evento, PDF, permisos,
 * CSRF y aislamiento entre empresas. Elimina los datos temporales al finalizar.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$documentId = null;
$eventId = null;
$contadorId = null;
$tenant2 = null;
$otherUserId = null;
$today = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');
$sequenceBefore = $pdo->query("SELECT numero_actual FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = '01'")->fetchColumn();
$suffix = (string) getmypid();
$userContador = 'm9_contador_' . $suffix;
$userOther = 'm9_other_' . $suffix;

function checkHttp9(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http9(string $method, string $url, array &$jar, array $data = []): array
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

function login9(string $username, string $password, array &$jar): string
{
    $login = http9('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    http9('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    $dashboard = http9('GET', 'views/dashboard.php', $jar);
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dashboard['body'], $meta) ? $meta[1] : '';
}

try {
    $anonymous = [];
    checkHttp9(http9('GET', 'api/contingencia.php?action=list', $anonymous)['status'] === 401, 'la API de contingencia exige autenticación');

    $adminJar = [];
    $adminCsrf = login9('admin', 'Demo1234!', $adminJar);
    checkHttp9($adminCsrf !== '', 'el administrador inicia sesión');

    $page = http9('GET', 'views/contingencia.php', $adminJar);
    checkHttp9($page['status'] === 200 && str_contains($page['body'], 'contingency-app') && str_contains($page['body'], 'Nuevo evento'), 'la pantalla de contingencia carga para el administrador');

    $save = http9('POST', 'api/documentos.php', $adminJar, [
        'action' => 'save', 'csrf_token' => $adminCsrf,
        'tipo_dte' => '01', 'cliente_id' => '1', 'fecha_emision' => $today,
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Prueba HTTP módulo 9',
        'items' => [0 => ['producto_id' => '1', 'cantidad' => '1', 'precio_unitario' => '113.00000000', 'descuento' => '0']],
    ]);
    checkHttp9($save['status'] === 200 && ($save['json']['success'] ?? false), 'se guarda el borrador de contingencia');
    $documentId = (int) ($save['json']['id'] ?? 0);
    checkHttp9($documentId > 0, 'el borrador devuelve su identificador');

    $emit = http9('POST', 'api/documentos.php', $adminJar, [
        'action' => 'emit', 'id' => $documentId, 'csrf_token' => $adminCsrf,
        'contingencia' => '1', 'tipo_contingencia' => '2', 'motivo_contin' => 'Prueba HTTP de contingencia',
    ]);
    checkHttp9($emit['status'] === 200 && ($emit['json']['estado'] ?? '') === 'EMITIDA', 'el DTE se emite en contingencia sin transmitirse');

    $pending = http9('GET', 'api/contingencia.php?action=pending&f_inicio=' . $today . '&h_inicio=00:00&f_fin=' . $today . '&h_fin=23:59', $adminJar);
    checkHttp9($pending['status'] === 200 && (int) ($pending['json']['total'] ?? 0) === 1, 'la API muestra el DTE pendiente del periodo');

    $noCsrf = http9('POST', 'api/contingencia.php', $adminJar, ['action' => 'create']);
    checkHttp9($noCsrf['status'] === 403, 'crear el evento sin CSRF es rechazado');

    $create = http9('POST', 'api/contingencia.php', $adminJar, [
        'action' => 'create', 'csrf_token' => $adminCsrf,
        'f_inicio' => $today, 'h_inicio' => '00:00', 'f_fin' => $today, 'h_fin' => '23:59',
        'tipo_contingencia' => '2', 'motivo_contingencia' => 'Evento HTTP de contingencia',
        'nombre_responsable' => 'Administrador Demo', 'tipo_doc_responsable' => '36',
        'numero_doc_responsable' => '0614-000000-000-0', 'tipo_establecimiento' => '02',
    ]);
    checkHttp9($create['status'] === 200 && ($create['json']['success'] ?? false), 'el evento de contingencia se crea y procesa');
    $eventId = (int) ($create['json']['event']['id'] ?? 0);
    checkHttp9($eventId > 0 && ($create['json']['event']['estado'] ?? '') === 'PROCESADO', 'el evento queda procesado');
    checkHttp9(($create['json']['transmision']['procesados'] ?? 0) === 1, 'el evento transmite el DTE pendiente');
    checkHttp9((int) ($create['json']['event']['evento']['identificacion']['version'] ?? 0) === 4, 'el evento usa el esquema v4');

    $list = http9('GET', 'api/contingencia.php?action=list', $adminJar);
    checkHttp9($list['status'] === 200 && count($list['json']['items'] ?? []) >= 1, 'el listado de eventos responde');

    $detail = http9('GET', 'api/contingencia.php?action=get&id=' . $eventId, $adminJar);
    checkHttp9($detail['status'] === 200 && ($detail['json']['event']['estado'] ?? '') === 'PROCESADO', 'el detalle del evento responde');
    checkHttp9(($detail['json']['documentos'][0]['estado'] ?? '') === 'PROCESADA', 'el detalle muestra el DTE procesado');

    $download = http9('GET', 'api/contingencia.php?action=download&id=' . $eventId, $adminJar);
    checkHttp9($download['status'] === 200 && ($download['json']['identificacion']['version'] ?? 0) === 4, 'la descarga devuelve el JSON del evento');

    $pdf = http9('GET', 'api/documentos.php?action=pdf&id=' . $documentId, $adminJar);
    checkHttp9(str_starts_with($pdf['body'], '%PDF-1.4') && str_contains($pdf['body'], 'CONTINGENCIA'), 'el PDF marca el documento como emitido en contingencia');

    $hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (1,'Contador temporal M9',:usuario,:email,:hash,'CONTADOR')")
        ->execute(['usuario' => $userContador, 'email' => $userContador . '@test.local', 'hash' => $hash]);
    $contadorId = (int) $pdo->lastInsertId();
    $contadorJar = [];
    $contadorCsrf = login9($userContador, 'Demo1234!', $contadorJar);
    checkHttp9(http9('GET', 'api/contingencia.php?action=list', $contadorJar)['status'] === 200, 'el contador puede consultar los eventos');
    checkHttp9(http9('GET', 'views/contingencia.php', $contadorJar)['status'] === 200, 'el contador puede abrir la pantalla de contingencia');
    $forbidden = http9('POST', 'api/contingencia.php', $contadorJar, [
        'action' => 'create', 'csrf_token' => $contadorCsrf,
        'f_inicio' => $today, 'h_inicio' => '00:00', 'f_fin' => $today, 'h_fin' => '23:59',
        'tipo_contingencia' => '2', 'nombre_responsable' => 'Contador', 'tipo_doc_responsable' => '36',
        'numero_doc_responsable' => '0614-000000-000-0', 'tipo_establecimiento' => '02',
    ]);
    checkHttp9($forbidden['status'] === 403, 'el contador no puede declarar eventos');

    $pdo->prepare("INSERT INTO tenants (nit,razon_social,nombre_comercial,codigo_actividad,direccion,telefono,email,numero_registro_mh) VALUES ('0614-999999-999-9','Empresa aislada temporal M9','Aislada M9','00000','Prueba','0000-0000','a@b.local','0000000')")->execute();
    $tenant2 = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO tenant_config (tenant_id,mh_mode,timezone,moneda,distrito_emisor,establecimiento) VALUES (:id,'SIMULACION','America/El_Salvador','USD','14','M001P001')")->execute(['id' => $tenant2]);
    $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (:tenant,'Admin aislado M9',:usuario,:email,:hash,'ADMIN')")
        ->execute(['tenant' => $tenant2, 'usuario' => $userOther, 'email' => $userOther . '@test.local', 'hash' => $hash]);
    $otherUserId = (int) $pdo->lastInsertId();
    $otherJar = [];
    login9($userOther, 'Demo1234!', $otherJar);
    checkHttp9(http9('GET', 'api/contingencia.php?action=get&id=' . $eventId, $otherJar)['status'] === 404, 'otra empresa no puede consultar el evento');
    checkHttp9((http9('GET', 'api/contingencia.php?action=list', $otherJar)['json']['items'] ?? []) === [], 'el listado de otra empresa está aislado');
    checkHttp9(http9('GET', 'api/contingencia.php?action=download&id=' . $eventId, $otherJar)['status'] === 404, 'otra empresa no puede descargar el JSON del evento');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($documentId !== null) {
        $pdo->prepare('DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $documentId]);
        $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')->execute(['id' => $documentId]);
    }
    if ($eventId !== null) {
        $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND evento_contingencia_id = :id')->execute(['id' => $eventId]);
        $pdo->prepare('DELETE FROM evento_contingencia_documentos WHERE tenant_id = 1 AND evento_id = :id')->execute(['id' => $eventId]);
    }
    if ($documentId !== null) {
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $documentId]);
    }
    if ($eventId !== null) {
        $pdo->prepare('DELETE FROM eventos_contingencia WHERE tenant_id = 1 AND id = :id')->execute(['id' => $eventId]);
    }
    if ($contadorId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $contadorId]);
    if ($otherUserId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $otherUserId]);
    if ($tenant2 !== null) {
        $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenant2]);
    }
    if ($sequenceBefore !== false) {
        $pdo->prepare("UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = '01'")
            ->execute(['value' => (int) $sequenceBefore]);
    }
    echo "Datos temporales eliminados.\n";
}
