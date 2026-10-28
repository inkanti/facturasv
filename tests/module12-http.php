<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';

/**
 * Pruebas HTTP del módulo 12: Mi perfil y cambio de la propia contraseña.
 * Restaura la contraseña del administrador demo al finalizar.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;
$admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
$originalHash = $admin['password_hash'];
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();

function checkHttp12(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO HTTP M12: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

function http12(string $method, string $url, array &$jar, array $data = []): array
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
    foreach (preg_split('/\r?\n/', substr($raw, 0, $headerSize)) ?: [] as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $line, $matches) === 1) {
            $jar[trim($matches[1])] = trim($matches[2]);
        }
    }
    $body = substr($raw, $headerSize);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true)];
}

function login12(string $usuario, string $password, array &$jar): array
{
    $page = http12('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $matches);
    return http12('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '', 'usuario' => $usuario, 'password' => $password,
    ]);
}

try {
    $adminJar = [];
    $adminLogin = login12('admin', 'Demo1234!', $adminJar);
    checkHttp12($adminLogin['status'] === 200 && ($adminLogin['json']['success'] ?? false), 'el administrador inicia sesión');

    $perfil = http12('GET', 'views/perfil.php', $adminJar);
    checkHttp12($perfil['status'] === 200 && str_contains($perfil['body'], 'Mi perfil') && str_contains($perfil['body'], 'Cambiar contraseña'), 'la página Mi perfil carga para un usuario de empresa');
    preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $perfil['body'], $meta);
    $csrf = $meta[1] ?? '';
    checkHttp12($csrf !== '', 'la página de perfil entrega el token CSRF');

    $data = http12('GET', 'api/perfil.php?action=data', $adminJar);
    checkHttp12($data['status'] === 200 && (int) ($data['json']['perfil']['id'] ?? 0) === (int) $admin['id'], 'la API devuelve la propia cuenta');

    $wrong = http12('POST', 'api/perfil.php?action=password', $adminJar, [
        'csrf_token' => $csrf, 'current_password' => 'Incorrecta123!',
        'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
    ]);
    checkHttp12($wrong['status'] === 422 && isset($wrong['json']['errors']['current_password']), 'se rechaza la contraseña actual incorrecta');

    $weak = http12('POST', 'api/perfil.php?action=password', $adminJar, [
        'csrf_token' => $csrf, 'current_password' => 'Demo1234!',
        'password' => 'corta', 'password_confirmation' => 'corta',
    ]);
    checkHttp12($weak['status'] === 422 && isset($weak['json']['errors']['password']), 'se rechaza una contraseña nueva débil');

    $changed = http12('POST', 'api/perfil.php?action=password', $adminJar, [
        'csrf_token' => $csrf, 'current_password' => 'Demo1234!',
        'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
    ]);
    checkHttp12($changed['status'] === 200 && ($changed['json']['success'] ?? false), 'el usuario cambia su propia contraseña');

    $oldJar = [];
    $oldLogin = login12('admin', 'Demo1234!', $oldJar);
    checkHttp12($oldLogin['status'] === 401, 'la contraseña anterior ya no permite entrar');

    $newJar = [];
    $newLogin = login12('admin', 'Nueva12345!', $newJar);
    checkHttp12($newLogin['status'] === 200 && ($newLogin['json']['success'] ?? false), 'la nueva contraseña permite entrar');
    checkHttp12(http12('GET', 'views/perfil.php', $newJar)['status'] === 200, 'el usuario entra a Mi perfil con la nueva contraseña');

    $noCsrf = http12('POST', 'api/perfil.php?action=password', $newJar, [
        'current_password' => 'Nueva12345!', 'password' => 'Otra12345!', 'password_confirmation' => 'Otra12345!',
    ]);
    checkHttp12($noCsrf['status'] === 403, 'el cambio de contraseña exige CSRF');

    $superJar = [];
    $superLogin = login12('superadmin', 'SuperDemo1234!', $superJar);
    checkHttp12($superLogin['status'] === 200 && ($superLogin['json']['superadmin'] ?? false), 'el superadministrador inicia sesión');
    $superPerfil = http12('GET', 'views/perfil.php', $superJar);
    checkHttp12($superPerfil['status'] === 200 && str_contains($superPerfil['body'], 'Mi perfil') && str_contains($superPerfil['body'], 'Administración global'), 'el superadministrador también accede a Mi perfil');

    echo "RESULTADO: {$checks} comprobaciones HTTP correctas.\n";
} finally {
    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
        ->execute(['hash' => $originalHash, 'id' => (int) $admin['id']]);
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
