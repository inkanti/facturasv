<?php

declare(strict_types=1);

require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/configuracion.php';
require_once __DIR__ . '/qr.php';

/**
 * Impresión de tickets de venta (representación adicional al PDF del DTE).
 *
 * No genera un DTE nuevo ni modifica el documento: solo prepara los datos
 * necesarios para una impresión térmica de 58 mm u 80 mm.
 */

function ticketFormats(): array
{
    return [
        '80' => [
            'key' => '80',
            'label' => '80 mm',
            'class' => 'ticket-80',
            'width' => '80mm',
            'description' => 'Formato estándar para impresoras térmicas de 80 mm.',
        ],
        '58' => [
            'key' => '58',
            'label' => '58 mm',
            'class' => 'ticket-58',
            'width' => '58mm',
            'description' => 'Formato compacto para impresoras térmicas de 58 mm.',
        ],
    ];
}

function ticketFormat(mixed $value): array
{
    $key = is_string($value) ? trim($value) : '';
    $formats = ticketFormats();
    return $formats[$key] ?? $formats['80'];
}

function ticketMoney(float|int|string|null $value): string
{
    return '$ ' . number_format((float) $value, 2, '.', ',');
}

function ticketQuantity(float|int|string|null $value): string
{
    $number = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    return $number === '' ? '0' : $number;
}

function ticketTypeName(string $type): string
{
    return documentAllTypeDefinitions()[$type]['nombre'] ?? 'Documento';
}

function ticketTitle(string $type): string
{
    return in_array($type, ['05', '06'], true) ? 'Ticket de nota' : 'Ticket de venta';
}

function ticketClientDocument(array $document): string
{
    if (($document['cliente_tipo_documento'] ?? 'NINGUNO') === 'NINGUNO' || empty($document['cliente_documento'])) {
        return 'Consumidor final';
    }
    return $document['cliente_tipo_documento'] . ' ' . $document['cliente_documento'];
}

function ticketLineTotal(array $item, string $type): float
{
    if ($type === '14') {
        return round((float) ($item['compra'] ?? 0), 2);
    }
    return round(
        (float) ($item['venta_gravada'] ?? 0)
        + (float) ($item['venta_exenta'] ?? 0)
        + (float) ($item['venta_no_suj'] ?? 0)
        + (float) ($item['iva_item'] ?? 0),
        2
    );
}

function ticketSubtotal(array $document): float
{
    return round(
        (float) ($document['total_gravada'] ?? 0)
        + (float) ($document['total_exenta'] ?? 0)
        + (float) ($document['total_no_suj'] ?? 0),
        2
    );
}

/**
 * @throws RuntimeException Si el documento no existe, no está emitido o no
 *                          está en un estado imprimible.
 */
function ticketDocumentData(int $tenantId, int $documentId): array
{
    $detail = documentDetail($tenantId, $documentId);
    $document = $detail['document'];
    if (empty($document['numero_control'])) {
        throw new RuntimeException('Solo puede imprimir el ticket de un documento emitido.');
    }
    if (!in_array($document['estado'], ['PROCESADA', 'INVALIDADA'], true)) {
        throw new RuntimeException('El documento aún no está procesado; no es posible imprimir su ticket.');
    }

    $tenant = tenantDocumentContext(db(), $tenantId);
    $type = (string) $document['tipo_dte'];
    $qrUrl = dteQrUrl($document, (string) ($tenant['mh_mode'] ?? 'SIMULACION'));
    return [
        'document' => $document,
        'items' => $detail['items'],
        'tenant' => $tenant,
        'type_code' => $type,
        'type_name' => ticketTypeName($type),
        'ticket_title' => ticketTitle($type),
        'logo_url' => configuracionLogoUrl($tenant['logo_path'] ?? null),
        'is_invalidated' => $document['estado'] === 'INVALIDADA',
        'is_internal' => documentIsInternalType($type),
        'is_contingency' => (int) ($document['tipo_operacion'] ?? 1) === 2,
        'client_document' => ticketClientDocument($document),
        'subtotal' => ticketSubtotal($document),
        'retention' => round((float) ($document['iva_rete1'] ?? 0) + (float) ($document['rete_renta'] ?? 0), 2),
        'qr_url' => $qrUrl,
        'qr_data_uri' => $qrUrl !== null
            ? 'data:image/png;base64,' . base64_encode(QrEncoder::png($qrUrl, 5, 4))
            : null,
    ];
}
