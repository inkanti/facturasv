<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$tenant2 = null;
$contadorId = null;
$cookieFiles = [];
$suffix = (string) getmypid();
$userContador = 'm5_contador_' . $suffix;
$userOther = 'm5_other_' . $suffix;

function httpCheck(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function httpRequest(string $method, string $url, ?string $cookie, array $data = []): array
{
    global $base, $cookieFiles;
    $curl = curl_init($base . $url);
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 30, CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if ($cookie !== null) {
        // La cookie va al directorio temporal: el proyecto puede ser de solo
        // lectura para el usuario que ejecuta las pruebas.
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'facturasv_' . $cookie . '_' . getmypid() . '.cookie';
        $cookieFiles[] = $file;
        $options[CURLOPT_COOKIEJAR] = $file;
        $options[CURLOPT_COOKIEFILE] = $file;
    }
    if ($method === 'POST') $options[CURLOPT_POSTFIELDS] = http_build_query($data);
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    if ($body === false) throw new RuntimeException(curl_error($curl));
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

function csrfFromPage(string $page): string
{
    return preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page, $matches) ? $matches[1] : '';
}

function loginHttp(string $username, string $password, string $cookie): array
{
    $login = httpRequest('GET', 'views/login.php', $cookie);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $login['body'], $matches);
    $response = httpRequest('POST', 'api/auth.php?action=login', $cookie, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $username, 'password' => $password,
    ]);
    $dashboard = httpRequest('GET', 'views/dashboard.php', $cookie);
    return ['login' => $response, 'csrf' => csrfFromPage($dashboard['body'])];
}

try {
    $docs = $pdo->query("SELECT id FROM documentos WHERE tenant_id = 1 AND estado IN ('PROCESADA','INVALIDADA') ORDER BY id LIMIT 2")->fetchAll();
    httpCheck(count($docs) >= 1, 'existe un documento visual para las pruebas HTTP');
    $documentId = (int) $docs[0]['id'];
    $unauth = httpRequest('GET', 'api/documentos.php?action=list', null);
    httpCheck($unauth['status'] === 401, 'la API exige autenticación');

    $admin = loginHttp('admin', 'Demo1234!', 'm5_admin');
    httpCheck($admin['login']['status'] === 200 && $admin['csrf'] !== '', 'el administrador inicia sesión por HTTP');
    $noCsrf = httpRequest('POST', 'api/documentos.php', 'm5_admin', ['action' => 'save']);
    httpCheck($noCsrf['status'] === 403, 'guardar sin CSRF es rechazado');
    $list = httpRequest('GET', 'api/documentos.php?action=list', 'm5_admin');
    httpCheck($list['status'] === 200 && ($list['json']['success'] ?? false), 'el listado responde correctamente');
    $download = httpRequest('GET', 'api/documentos.php?action=download&id=' . $documentId, 'm5_admin');
    httpCheck($download['status'] === 200 && (($download['json']['identificacion']['tipoDte'] ?? '') !== ''), 'la descarga devuelve el JSON DTE');

    $hash = password_hash('Demo1234!', PASSWORD_DEFAULT);
    $statement = $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (1,'Contador temporal',:usuario,:email,:hash,'CONTADOR')");
    $statement->execute(['usuario' => $userContador, 'email' => $userContador . '@test.local', 'hash' => $hash]);
    $contadorId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare("INSERT INTO tenants (nit,razon_social,nombre_comercial,codigo_actividad,direccion,telefono,email,numero_registro_mh) VALUES ('0614-999999-999-9','Empresa aislada temporal','Aislada','00000','Prueba','0000-0000','a@b.local','0000000')");
    $statement->execute();
    $tenant2 = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare("INSERT INTO tenant_config (tenant_id,mh_mode,timezone,moneda,distrito_emisor,establecimiento) VALUES (:id,'SIMULACION','America/El_Salvador','USD','14','M001P001')");
    $statement->execute(['id' => $tenant2]);
    $statement = $pdo->prepare("INSERT INTO users (tenant_id,nombre,usuario,email,password_hash,rol) VALUES (:tenant,'Admin aislado',:usuario,:email,:hash,'ADMIN')");
    $statement->execute(['tenant' => $tenant2, 'usuario' => $userOther, 'email' => $userOther . '@test.local', 'hash' => $hash]);

    $contador = loginHttp($userContador, 'Demo1234!', 'm5_contador');
    httpCheck($contador['login']['status'] === 200, 'el contador inicia sesión');
    $forbidden = httpRequest('POST', 'api/documentos.php', 'm5_contador', ['action' => 'save', 'csrf_token' => $contador['csrf']]);
    httpCheck($forbidden['status'] === 403, 'el contador no puede guardar documentos');
    $forbidden = httpRequest('POST', 'api/mh.php', 'm5_contador', ['action' => 'transmit', 'id' => $documentId, 'csrf_token' => $contador['csrf']]);
    httpCheck($forbidden['status'] === 403, 'el contador no puede transmitir');
    $forbidden = httpRequest('POST', 'api/mh.php', 'm5_contador', ['action' => 'invalidate', 'id' => $documentId, 'csrf_token' => $contador['csrf']]);
    httpCheck($forbidden['status'] === 403, 'el contador no puede invalidar');
    $editor = httpRequest('GET', 'views/nueva-factura.php', 'm5_contador');
    httpCheck($editor['status'] === 403, 'la pantalla de emisión bloquea al contador');
    $read = httpRequest('GET', 'api/documentos.php?action=get&id=' . $documentId, 'm5_contador');
    httpCheck($read['status'] === 200, 'el contador conserva acceso de lectura');

    $other = loginHttp($userOther, 'Demo1234!', 'm5_other');
    $cross = httpRequest('GET', 'api/documentos.php?action=get&id=' . $documentId, 'm5_other');
    httpCheck($cross['status'] === 404, 'otra empresa no puede consultar el documento');
    $crossList = httpRequest('GET', 'api/documentos.php?action=list', 'm5_other');
    httpCheck(($crossList['json']['pagination']['total'] ?? -1) === 0, 'el listado de otra empresa está aislado');
    $crossDownload = httpRequest('GET', 'api/documentos.php?action=download&id=' . $documentId, 'm5_other');
    httpCheck($crossDownload['status'] === 404, 'otra empresa no puede descargar el JSON');
    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    if ($contadorId !== null) {
        $statement = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $statement->execute(['id' => $contadorId]);
    }
    if ($tenant2 !== null) {
        $statement = $pdo->prepare('DELETE FROM users WHERE tenant_id = :tenant');
        $statement->execute(['tenant' => $tenant2]);
        $statement = $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :tenant');
        $statement->execute(['tenant' => $tenant2]);
        $statement = $pdo->prepare('DELETE FROM tenants WHERE id = :tenant');
        $statement->execute(['tenant' => $tenant2]);
    }
    foreach (array_unique($cookieFiles) as $file) if (is_file($file)) unlink($file);
    echo "Usuarios, empresa y cookies temporales eliminados.\n";
}
