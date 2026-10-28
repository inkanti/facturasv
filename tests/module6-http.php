<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP de la representación gráfica PDF (módulo 6).
 * Comprueba autenticación, roles, descarga, borradores y aislamiento.
 * Las cookies se mantienen en memoria para no dejar archivos temporales.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$tenant2 = null;
$contadorId = null;
$draftId = null;
$suffix = (string) getmypid();
$userContador = 'm6_contador_' . $suffix;
$userOther = 'm6_other_' . $suffix;

function checkHttp6(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

/**
 * @param array<string,string> $jar Cookies de la sesión en curso.
 */
function httpRequest(string $method, string $url, array &$jar, array $data = []): array
{
    global $base;
    $curl = curl_init($base . $url);
    $headers = ['Accept: application/json, application/pdf, text/html'];
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
            if (preg_match('/^([^=]+)=([^;]*)/', $matches[2], $cookie) === 1) {
                $jar[trim($cookie[1])] = trim($cookie[2]);
            }
            continue;
        }
        $parsed[$name] = trim($matches[2]);
    }
    $body = substr($raw, $headerSize);
    return ['status' => $status, 'headers' => $parsed, 'body' => $body, 'json' => json_decode($body, true)];
}

function loginHttp6(string $username, string $password, array &$jar): string
{
    $login = httpRequest('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    httpRequest('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    $dashboard = httpRequest('GET', 'views/dashboard.php', $jar);
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $dashboard['body'], $meta) ? $meta[1] : '';
}

function isPdf(array $response): bool
{
    return str_starts_with($response['body'], '%PDF-1.4') && str_ends_with($response['body'], '%%EOF');
}

try {
    $documents = $pdo->query(
        "SELECT id FROM documentos WHERE tenant_id = 1 AND estado IN ('PROCESADA','INVALIDADA') ORDER BY id LIMIT 1"
    )->fetchAll();
    checkHttp6($documents !== [], 'existe un documento procesado para probar el PDF');
    $documentId = (int) $documents[0]['id'];

    $anonymous = [];
    $unauthorized = httpRequest('GET', 'api/documentos.php?action=pdf&id=' . $documentId, $anonymous);
    checkHttp6($unauthorized['status'] === 401, 'el PDF exige autenticación');

    $adminJar = [];
    $adminCsrf = loginHttp6('admin', 'Demo1234!', $adminJar);
    checkHttp6($adminCsrf !== '', 'el administrador inicia sesión por HTTP');

    $pdf = httpRequest('GET', 'api/documentos.php?action=pdf&id=' . $documentId, $adminJar);
    checkHttp6($pdf['status'] === 200, 'el PDF del documento procesado responde con HTTP 200');
    checkHttp6(($pdf['headers']['content-type'] ?? '') === 'application/pdf', 'la respuesta declara el tipo application/pdf');
    checkHttp6(isPdf($pdf), 'la respuesta es un PDF completo');
    checkHttp6(str_contains($pdf['body'], '/DeviceGray'), 'el PDF incluye el código QR de consulta');
    checkHttp6(str_contains($pdf['headers']['content-disposition'] ?? '', 'inline'), 'el PDF se presenta en línea para impresión');
    checkHttp6((int) ($pdf['headers']['content-length'] ?? 0) === strlen($pdf['body']), 'la longitud declarada coincide con el archivo');

    $download = httpRequest('GET', 'api/documentos.php?action=pdf&download=1&id=' . $documentId, $adminJar);
    checkHttp6($download['status'] === 200 && isPdf($download), 'el PDF se puede descargar como archivo');
    checkHttp6(str_contains($download['headers']['content-disposition'] ?? '', 'attachment'), 'la descarga usa disposición adjunta');

    $notFound = httpRequest('GET', 'api/documentos.php?action=pdf&id=999999', $adminJar);
    checkHttp6($notFound['status'] === 404, 'un documento inexistente responde 404');

    // Borrador: no debe generar PDF.
    $draft = httpRequest('POST', 'api/documentos.php', $adminJar, [
        'action' => 'save', 'csrf_token' => $adminCsrf, 'tipo_dte' => '01', 'cliente_id' => '1',
        'fecha_emision' => '2026-10-26', 'condicion_operacion_id' => '1', 'forma_pago_id' => '1',
        'dias_credito' => '', 'emisor_distrito' => '14', 'receptor_distrito' => '01',
        'aplicar_iva_retenido' => '1', 'observaciones' => 'Borrador de prueba HTTP del módulo 6',
        'items' => [['producto_id' => '1', 'cantidad' => '1', 'precio_unitario' => '113.00000000', 'descuento' => '0']],
    ]);
    checkHttp6($draft['status'] === 200 && (int) ($draft['json']['id'] ?? 0) > 0, 'el administrador guarda un borrador');
    $draftId = (int) $draft['json']['id'];
    $draftPdf = httpRequest('GET', 'api/documentos.php?action=pdf&id=' . $draftId, $adminJar);
    checkHttp6($draftPdf['status'] === 404, 'el borrador no genera PDF');
    checkHttp6(str_contains((string) ($draftPdf['json']['message'] ?? ''), 'emitido'), 'el mensaje explica que debe emitirse primero');

    // CONTADOR: solo lectura, puede ver y descargar el PDF.
    $hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $statement = $pdo->prepare(
        "INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (1,'Contador temporal M6',:usuario,:email,:hash,'CONTADOR')"
    );
    $statement->execute(['usuario' => $userContador, 'email' => $userContador . '@test.local', 'hash' => $hash]);
    $contadorId = (int) $pdo->lastInsertId();
    $contadorJar = [];
    loginHttp6($userContador, 'Demo1234!', $contadorJar);
    $contadorPdf = httpRequest('GET', 'api/documentos.php?action=pdf&id=' . $documentId, $contadorJar);
    checkHttp6($contadorPdf['status'] === 200 && isPdf($contadorPdf), 'el contador puede ver el PDF del documento');

    // Otra empresa: no debe acceder al PDF ajeno.
    $statement = $pdo->prepare(
        "INSERT INTO tenants (nit,razon_social,nombre_comercial,codigo_actividad,direccion,telefono,email,numero_registro_mh)
         VALUES ('0614-888888-888-8','Empresa PDF aislada','Aislada PDF','00000','Prueba','0000-0000','pdf@aislada.local','0000000')"
    );
    $statement->execute();
    $tenant2 = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO tenant_config (tenant_id,mh_mode,timezone,moneda,distrito_emisor,establecimiento)
         VALUES (:id,'SIMULACION','America/El_Salvador','USD','14','M001P001')"
    )->execute(['id' => $tenant2]);
    $pdo->prepare(
        "INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (:tenant,'Admin aislado',:usuario,:email,:hash,'ADMIN')"
    )->execute(['tenant' => $tenant2, 'usuario' => $userOther, 'email' => $userOther . '@test.local', 'hash' => $hash]);
    $otherJar = [];
    loginHttp6($userOther, 'Demo1234!', $otherJar);
    $cross = httpRequest('GET', 'api/documentos.php?action=pdf&id=' . $documentId, $otherJar);
    checkHttp6($cross['status'] === 404, 'otra empresa no puede generar el PDF ajeno');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($draftId !== null) {
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $draftId]);
    }
    if ($contadorId !== null) {
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $contadorId]);
    }
    if ($tenant2 !== null) {
        $pdo->prepare('DELETE FROM users WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :id')->execute(['id' => $tenant2]);
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenant2]);
    }
    echo "Usuarios, empresa y borrador temporales eliminados.\n";
}
