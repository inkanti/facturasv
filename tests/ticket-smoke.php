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
require_once dirname(__DIR__) . '/lib/ticket.php';

$pdo = db();
$checks = 0;

function checkTicket(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO TICKET: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

$formats = ticketFormats();
checkTicket(isset($formats['80'], $formats['58']), 'existen los formatos de 80 mm y 58 mm');
checkTicket($formats['80']['width'] === '80mm', 'el formato estándar usa 80 mm');
checkTicket($formats['58']['width'] === '58mm', 'el formato compacto usa 58 mm');
checkTicket(ticketFormat('58')['class'] === 'ticket-58', 'el formato 58 se traduce a su clase CSS');
checkTicket(ticketFormat('desconocido')['key'] === '80', 'un formato inválido vuelve a 80 mm');
checkTicket(ticketMoney(84.75) === '$ 84.75', 'el dinero se presenta con dos decimales');
checkTicket(ticketQuantity(2) === '2' && ticketQuantity(1.5) === '1.5', 'las cantidades se presentan sin ceros sobrantes');

$processed = $pdo->query(
    "SELECT id FROM documentos
     WHERE tenant_id = 1 AND estado = 'PROCESADA' AND numero_control IS NOT NULL
     ORDER BY id DESC LIMIT 1"
)->fetch();
checkTicket($processed !== false, 'existe un documento procesado para imprimir');
$data = ticketDocumentData(1, (int) $processed['id']);
checkTicket($data['document']['numero_control'] !== '' && $data['items'] !== [], 'el ticket incluye número de control e ítems');
checkTicket($data['tenant']['nombre_comercial'] !== '', 'el ticket incluye los datos de la empresa');
checkTicket($data['subtotal'] === round(
    (float) $data['document']['total_gravada']
    + (float) $data['document']['total_exenta']
    + (float) $data['document']['total_no_suj'],
    2
), 'el subtotal coincide con los totales del documento');
$lineTotal = ticketLineTotal($data['items'][0], $data['type_code']);
checkTicket($lineTotal > 0, 'la línea del ticket tiene un total positivo');
checkTicket(
    is_string($data['qr_url']) && str_contains($data['qr_url'], 'consultaPublica?ambiente=00&codGen='),
    'el ticket incluye la URL de consulta del DTE'
);
checkTicket(
    is_string($data['qr_data_uri']) && str_starts_with($data['qr_data_uri'], 'data:image/png;base64,'),
    'el ticket incluye el código QR como PNG'
);
$qrPng = base64_decode(substr((string) $data['qr_data_uri'], 22), true);
checkTicket(is_string($qrPng) && str_starts_with($qrPng, "\x89PNG\r\n\x1a\n"), 'el QR del ticket es un PNG válido');
$qrImage = is_string($qrPng) ? @imagecreatefromstring($qrPng) : false;
checkTicket($qrImage !== false, 'la librería GD decodifica el QR del ticket');
if ($qrImage !== false) {
    imagedestroy($qrImage);
}

$invalidated = $pdo->query(
    "SELECT id FROM documentos
     WHERE tenant_id = 1 AND estado = 'INVALIDADA' AND numero_control IS NOT NULL
     ORDER BY id DESC LIMIT 1"
)->fetch();
checkTicket($invalidated !== false, 'existe un documento invalidado para probar la marca');
$invalidatedData = ticketDocumentData(1, (int) $invalidated['id']);
checkTicket($invalidatedData['is_invalidated'] === true, 'el ticket marca los documentos invalidados');

$thrown = false;
try {
    ticketDocumentData(1, 999999);
} catch (RuntimeException) {
    $thrown = true;
}
checkTicket($thrown, 'un documento inexistente no genera ticket');

checkTicket(ticketClientDocument(['cliente_tipo_documento' => 'NINGUNO', 'cliente_documento' => null]) === 'Consumidor final', 'el consumidor final se identifica correctamente');

echo "\nRESULTADO: {$checks} comprobaciones correctas.\n";
