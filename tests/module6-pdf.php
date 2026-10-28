<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/clientes.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/dte-pdf.php';
require_once dirname(__DIR__) . '/lib/empresa.php';

$pdo = db();
// Las pruebas no deben usar el SMTP real configurado: fuerzan el transporte simulado.
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);
$tenantId = 1;
$documentIds = [];
$tempClientId = null;
$assertions = 0;
$originalLogo = null;
$originalLogoContent = null;
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();

function checkModule6(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function checkPdf(string $pdf, string $context): void
{
    checkModule6(str_starts_with($pdf, '%PDF-1.4'), "el PDF {$context} inicia con la cabecera estándar");
    checkModule6(str_ends_with($pdf, '%%EOF'), "el PDF {$context} termina con el marcador EOF");
    checkModule6(strlen($pdf) > 2500, "el PDF {$context} contiene contenido suficiente");
    checkModule6(substr_count($pdf, ' obj') >= 8, "el PDF {$context} declara varios objetos");
}

function module6Input(string $type, int $clientId): array
{
    return [
        'tipo_dte' => $type, 'cliente_id' => $clientId,
        'fecha_emision' => '2026-10-26', 'condicion_operacion_id' => 1,
        'forma_pago_id' => 1, 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '1',
        'observaciones' => 'Prueba automatizada del módulo 6',
        'items' => [
            ['producto_id' => '1', 'cantidad' => '2', 'precio_unitario' => '113.00000000', 'descuento' => '0'],
            ['producto_id' => '2', 'cantidad' => '1', 'precio_unitario' => '50.00000000', 'descuento' => '5'],
        ],
    ];
}

try {
    // Se conserva el logo original para restaurarlo incluso si una comprobación falla.
    $originalLogo = $pdo->query('SELECT logo_path FROM tenants WHERE id = 1')->fetchColumn();
    $originalLogoPath = tenantLogoAbsolutePath(is_string($originalLogo) ? $originalLogo : null);
    $originalLogoContent = $originalLogoPath !== null ? file_get_contents($originalLogoPath) : null;
    $originalSequences = $pdo->query(
        'SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1'
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    checkModule6(
        (int) $pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn() >= 8,
        'la base de datos está en la versión 8 o superior'
    );
    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    checkModule6(is_array($admin), 'existe el administrador de pruebas');

    // Cliente sin documento de identidad.
    $location = $pdo->query(
        'SELECT d.id AS departamento_id, m.id AS municipio_id
         FROM mh_departamentos d INNER JOIN mh_municipios m
            ON m.tenant_id = d.tenant_id AND m.departamento_id = d.id
         WHERE d.tenant_id = 1 AND d.activo = 1 AND m.activo = 1 LIMIT 1'
    )->fetch();
    $statement = $pdo->prepare(
        "INSERT INTO clientes (tenant_id, tipo_documento, numero_documento, nombre, direccion,
            departamento_id, municipio_id, tipo_contribuyente, activo)
         VALUES (1, 'NINGUNO', NULL, 'Consumidor Final Prueba M6', 'San Salvador',
            :departamento, :municipio, 'OTRO', 1)"
    );
    $statement->execute(['departamento' => $location['departamento_id'], 'municipio' => $location['municipio_id']]);
    $tempClientId = (int) $pdo->lastInsertId();
    checkModule6($tempClientId > 0, 'se registra un cliente sin DUI ni NIT');

    // Segundo cliente sin documento: la unicidad no lo impide.
    $statement->execute(['departamento' => $location['departamento_id'], 'municipio' => $location['municipio_id']]);
    $secondTempClientId = (int) $pdo->lastInsertId();
    checkModule6($secondTempClientId > 0, 'puede existir más de un cliente sin documento');
    $pdo->prepare('DELETE FROM clientes WHERE tenant_id = 1 AND id = :id')->execute(['id' => $secondTempClientId]);

    // Factura 01 para el cliente sin documento.
    $feId = saveDocument($tenantId, (int) $admin['id'], module6Input('01', $tempClientId));
    $documentIds[] = $feId;
    $draftPdfBlocked = false;
    try { buildDocumentPdf($tenantId, $feId); } catch (RuntimeException) { $draftPdfBlocked = true; }
    checkModule6($draftPdfBlocked, 'el borrador no genera PDF antes de emitirse');

    $fe = emitDocument($tenantId, $admin, $feId);
    checkModule6($fe['document']['estado'] === 'PROCESADA', 'la Factura del cliente sin documento se procesa');
    checkModule6(
        $fe['document']['dte']['receptor']['tipoDocumento'] === null
        && $fe['document']['dte']['receptor']['numDocumento'] === null,
        'el JSON de Factura envía el receptor sin tipo ni número de documento'
    );

    $fePdf = buildDocumentPdf($tenantId, $feId);
    checkPdf($fePdf, 'de Factura');
    checkModule6(str_contains($fePdf, '/DCTDecode'), 'el PDF de Factura incrusta el logo JPEG del emisor');
    checkModule6(str_contains($fePdf, '/DeviceGray'), 'el PDF de Factura incrusta el código QR de consulta');

    // Crédito Fiscal exige NIT; el cliente sin documento debe ser rechazado.
    $ccfRejected = false;
    try { saveDocument($tenantId, (int) $admin['id'], module6Input('03', $tempClientId)); }
    catch (DocumentValidationException $exception) { $ccfRejected = isset($exception->errors['cliente_id']); }
    checkModule6($ccfRejected, 'Crédito Fiscal rechaza clientes sin documento');

    // CCF con cliente NIT y su PDF.
    $ccfId = saveDocument($tenantId, (int) $admin['id'], module6Input('03', 1));
    $documentIds[] = $ccfId;
    $ccf = emitDocument($tenantId, $admin, $ccfId);
    checkModule6($ccf['document']['estado'] === 'PROCESADA', 'el Crédito Fiscal se procesa');
    checkPdf(buildDocumentPdf($tenantId, $ccfId), 'de Crédito Fiscal');

    // Documento invalidado: el PDF sigue disponible con la marca correspondiente.
    invalidateDocument($tenantId, $admin, $feId, [
        'tipo_anulacion' => '1', 'motivo' => 'Prueba de PDF invalidado del módulo 6',
        'tipo_documento_responsable' => '13', 'numero_documento_responsable' => '12345678-9',
    ]);
    $invalidPdf = buildDocumentPdf($tenantId, $feId);
    checkPdf($invalidPdf, 'invalidado');
    checkModule6(str_contains($invalidPdf, 'INVALIDADA'), 'el PDF invalidado incluye la marca INVALIDADA');

    // Aislamiento por empresa.
    $foreign = false;
    try { buildDocumentPdf(999, $feId); } catch (RuntimeException) { $foreign = true; }
    checkModule6($foreign, 'el PDF respeta el aislamiento por empresa');

    // Documento largo: la tabla continúa en otra página y el resumen queda al final.
    $longInput = module6Input('01', 1);
    $longInput['items'] = [];
    for ($line = 1; $line <= 45; $line++) {
        $longInput['items'][] = [
            'producto_id' => (string) (($line % 5) + 1), 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ];
    }
    $longId = saveDocument($tenantId, (int) $admin['id'], $longInput);
    $documentIds[] = $longId;
    emitDocument($tenantId, $admin, $longId);
    $longPdf = buildDocumentPdf($tenantId, $longId);
    checkPdf($longPdf, 'del documento de 45 líneas');
    $longPages = substr_count($longPdf, '/Type /Page') - substr_count($longPdf, '/Type /Pages');
    checkModule6($longPages >= 2, 'el documento de 45 líneas ocupa varias páginas');
    // El texto del PDF usa codificación WinAnsi: se comprueba el fragmento ASCII.
    checkModule6(
        str_contains($longPdf, 'gina ' . $longPages . ' de ' . $longPages),
        'el pie numera correctamente la última página del documento largo'
    );

    // Logo: se acepta un PNG válido y se normaliza a JPEG; se rechaza texto.
    $png = imagecreatetruecolor(120, 60);
    imagefill($png, 0, 0, imagecolorallocate($png, 30, 60, 120));
    $tmpPng = tempnam(sys_get_temp_dir(), 'logo') . '.png';
    imagepng($png, $tmpPng);
    imagedestroy($png);
    $relative = storeTenantLogo($pdo, $tenantId, [
        'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpPng),
        'tmp_name' => $tmpPng, 'name' => 'logo.png',
    ]);
    $stored = tenantLogoAbsolutePath($relative);
    checkModule6($stored !== null && str_ends_with($relative, '.jpg'), 'el logo PNG se normaliza y guarda como JPEG');
    $info = getimagesize($stored);
    checkModule6($info !== false && $info[2] === IMAGETYPE_JPEG, 'el logo almacenado es un JPEG válido');
    @unlink($tmpPng);
    @unlink($stored);

    $tmpTxt = tempnam(sys_get_temp_dir(), 'logo') . '.txt';
    file_put_contents($tmpTxt, 'no es una imagen');
    $rejected = false;
    try {
        storeTenantLogo($pdo, $tenantId, [
            'error' => UPLOAD_ERR_OK, 'size' => filesize($tmpTxt),
            'tmp_name' => $tmpTxt, 'name' => 'logo.png',
        ]);
    } catch (RuntimeException) { $rejected = true; }
    checkModule6($rejected, 'se rechaza un archivo de texto como logo');
    @unlink($tmpTxt);

    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    if ($originalLogo !== null) {
        $pdo->prepare('UPDATE tenants SET logo_path = :path WHERE id = 1')->execute(['path' => $originalLogo]);
        if ($originalLogoContent !== null) {
            $path = dirname(__DIR__) . '/' . $originalLogo;
            if (!is_file($path)) file_put_contents($path, $originalLogoContent);
        }
    }
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $statement = $pdo->prepare("DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id IN ({$placeholders})");
        $statement->execute($documentIds);
        $statement = $pdo->prepare("DELETE FROM documentos WHERE tenant_id = 1 AND id IN ({$placeholders})");
        $statement->execute($documentIds);
    }
    if ($tempClientId !== null) {
        $pdo->prepare('DELETE FROM clientes WHERE tenant_id = 1 AND id = :id')->execute(['id' => $tempClientId]);
    }
    // Se devuelven los correlativos a su valor inicial: la prueba no consume números.
    if (isset($originalSequences) && $originalSequences !== []) {
        $restoreSequence = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
        foreach ($originalSequences as $type => $value) {
            $restoreSequence->execute(['value' => (int) $value, 'type' => (string) $type]);
        }
    }
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
