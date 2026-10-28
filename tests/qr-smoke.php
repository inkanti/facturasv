<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/qr.php';

/**
 * Pruebas del generador de QR: matriz verificada contra una implementación de
 * referencia (BaconQrCode), PNG, URL de consulta y ambiente.
 */

$checks = 0;

function checkQr(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO QR: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

$url = 'https://admin.factura.gob.sv/consultaPublica?ambiente=00&codGen=05AA5C3A-9BE0-4FCA-A0DD-E3A170FACDA4&fechaEmi=2026-10-27';
$matrix = QrEncoder::encode($url);
checkQr($matrix['version'] === 7, 'la URL de consulta usa la versión 7');
checkQr($matrix['size'] === 45, 'la matriz de la versión 7 mide 45x45');
$rows = [];
foreach ($matrix['modules'] as $row) {
    $rows[] = implode('', array_map(static fn(bool $bit): string => $bit ? '1' : '0', $row));
}
checkQr(
    hash('sha256', implode("\n", $rows)) === 'b09e506775688c99f2dfa353c9f7df8a5879857580276d031ae91a1748d85d1f',
    'la matriz coincide con la implementación de referencia'
);

$png = QrEncoder::png($url, 5, 4);
checkQr(str_starts_with($png, "\x89PNG\r\n\x1a\n"), 'el QR se exporta como PNG válido');
$dimensions = unpack('Nwidth/Nheight', substr($png, 16, 8));
checkQr($dimensions['width'] === 265 && $dimensions['height'] === 265, 'el PNG incluye la zona de silencio y la escala');
checkQr(str_contains($png, 'IDAT') && str_ends_with($png, 'IEND' . pack('N', crc32('IEND'))), 'el PNG contiene los bloques IDAT e IEND');
checkQr((int) unpack('N', substr($png, 8, 4))[1] === 13, 'el bloque IHDR declara sus 13 bytes');
$pngInfo = getimagesizefromstring($png);
checkQr($pngInfo !== false && $pngInfo[0] === 265 && $pngInfo[1] === 265, 'el PNG se reconoce como imagen de 265x265');
$pngImage = @imagecreatefromstring($png);
checkQr($pngImage !== false && imagesx($pngImage) === 265, 'la librería GD decodifica el PNG del ticket');
if ($pngImage !== false) {
    imagedestroy($pngImage);
}

$document = [
    'codigo_generacion' => '05AA5C3A-9BE0-4FCA-A0DD-E3A170FACDA4',
    'fecha_emision' => '2026-10-27',
];
checkQr(
    dteQrUrl($document, 'SIMULACION') === 'https://admin.factura.gob.sv/consultaPublica?ambiente=00&codGen=05AA5C3A-9BE0-4FCA-A0DD-E3A170FACDA4&fechaEmi=2026-10-27',
    'la URL del QR usa el ambiente de simulación y los datos del DTE'
);
checkQr(
    dteQrUrl($document, 'PRODUCCION') === 'https://admin.factura.gob.sv/consultaPublica?ambiente=01&codGen=05AA5C3A-9BE0-4FCA-A0DD-E3A170FACDA4&fechaEmi=2026-10-27',
    'la URL del QR usa el ambiente de producción'
);
checkQr(dteQrUrl(['codigo_generacion' => null, 'fecha_emision' => '2026-10-27']) === null, 'un documento sin código de generación no genera QR');

$otherRows = [];
foreach (QrEncoder::encode('https://admin.factura.gob.sv/consultaPublica?ambiente=00&codGen=11111111-1111-1111-1111-111111111111&fechaEmi=2026-10-27')['modules'] as $row) {
    $otherRows[] = implode('', array_map(static fn(bool $bit): string => $bit ? '1' : '0', $row));
}
checkQr(implode("\n", $otherRows) !== implode("\n", $rows), 'dos DTE distintos generan QR distintos');

echo "\nRESULTADO: {$checks} comprobaciones correctas.\n";
