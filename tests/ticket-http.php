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
 * Pruebas HTTP de la impresión de tickets: acceso, formatos, documento
 * procesado, invalidación y ausencia de errores PHP en la vista.
 */

$base = 'http://localhost/sistemas/factElectronica_saas/facturacion%20electronica/';
$pdo = db();
$checks = 0;

function checkTicketHttp(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO HTTP TICKET: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function ticketHttp(string $method, string $url, array &$jar, array $data = []): array
{
    global $base;
    $curl = curl_init($base . $url);
    $headers = ['Accept: text/html, application/json'];
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
    return ['status' => $status, 'body' => substr($raw, $headerSize)];
}

function loginTicket(array &$jar): void
{
    $page = ticketHttp('GET', 'views/login.php', $jar);
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $page['body'], $matches);
    $login = ticketHttp('POST', 'api/auth.php?action=login', $jar, [
        'csrf_token' => $matches[1] ?? '',
        'usuario' => 'admin',
        'password' => 'Demo1234!',
    ]);
    if ($login['status'] !== 200) {
        throw new RuntimeException('No fue posible iniciar sesión para la prueba de ticket.');
    }
}

$processed = $pdo->query(
    "SELECT id, numero_control FROM documentos
     WHERE tenant_id = 1 AND estado = 'PROCESADA' AND numero_control IS NOT NULL
     ORDER BY id DESC LIMIT 1"
)->fetch();
$invalidated = $pdo->query(
    "SELECT id FROM documentos
     WHERE tenant_id = 1 AND estado = 'INVALIDADA' AND numero_control IS NOT NULL
     ORDER BY id DESC LIMIT 1"
)->fetch();
checkTicketHttp($processed !== false && $invalidated !== false, 'hay documentos procesado e invalidado para probar');

$anonymousJar = [];
$anonymous = ticketHttp('GET', 'views/ticket.php?id=' . (int) $processed['id'], $anonymousJar);
checkTicketHttp($anonymous['status'] === 302, 'el ticket exige una sesión iniciada');

$jar = [];
loginTicket($jar);
$page = ticketHttp('GET', 'views/ticket.php?id=' . (int) $processed['id'] . '&formato=80', $jar);
checkTicketHttp($page['status'] === 200, 'el administrador abre el ticket de 80 mm');
checkTicketHttp(str_contains($page['body'], 'ticket-sheet') && str_contains($page['body'], 'ticket-80'), 'la vista usa la hoja y la clase de 80 mm');
checkTicketHttp(str_contains($page['body'], 'id="ticket-print"') && str_contains($page['body'], 'ticket-item'), 'la vista incluye botón de impresión e ítems');
checkTicketHttp(str_contains($page['body'], (string) $processed['numero_control']), 'el ticket muestra el número de control');
checkTicketHttp(stripos($page['body'], 'Empresa Demo') !== false, 'el ticket muestra la empresa');
checkTicketHttp(stripos($page['body'], 'modo simulación') !== false, 'el ticket advierte que es una simulación');
checkTicketHttp(
    str_contains($page['body'], 'ticket-qr') && str_contains($page['body'], 'data:image/png;base64,'),
    'el ticket incluye el código QR de consulta'
);
preg_match('/data:image\/png;base64,([A-Za-z0-9+\/=]+)/', $page['body'], $qrMatch);
$qrBinary = isset($qrMatch[1]) ? base64_decode($qrMatch[1], true) : false;
$qrInfo = is_string($qrBinary) ? getimagesizefromstring($qrBinary) : false;
checkTicketHttp(
    $qrInfo !== false && $qrInfo['mime'] === 'image/png',
    'el navegador puede decodificar el QR del ticket'
);
checkTicketHttp(!preg_match('/(Fatal error|Parse error|Warning:|Notice:)/i', $page['body']), 'la vista no muestra errores PHP');

$page58 = ticketHttp('GET', 'views/ticket.php?id=' . (int) $processed['id'] . '&formato=58', $jar);
checkTicketHttp($page58['status'] === 200, 'el administrador abre el ticket de 58 mm');
checkTicketHttp(str_contains($page58['body'], 'ticket-58') && str_contains($page58['body'], 'ticket-58.css'), 'la vista carga la clase y hoja de 58 mm');

$invalidatedPage = ticketHttp('GET', 'views/ticket.php?id=' . (int) $invalidated['id'], $jar);
checkTicketHttp(str_contains($invalidatedPage['body'], 'DOCUMENTO INVALIDADO'), 'el ticket de un documento invalidado queda marcado');

$invalid = ticketHttp('GET', 'views/ticket.php?id=999999', $jar);
checkTicketHttp($invalid['status'] === 404, 'un documento inexistente responde 404');

$documents = ticketHttp('GET', 'views/lista-documentos.php', $jar);
checkTicketHttp(str_contains($documents['body'], 'data-ticket-url'), 'la pantalla de documentos integra el enlace de ticket');

echo "\nRESULTADO: {$checks} comprobaciones HTTP correctas.\n";
