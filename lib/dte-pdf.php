<?php

declare(strict_types=1);

require_once __DIR__ . '/pdf-simple.php';
require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/empresa.php';
require_once __DIR__ . '/numero-letras.php';
require_once __DIR__ . '/qr.php';

/**
 * Representación gráfica PDF del DTE.
 *
 * El diseño sigue el formato del ejemplo institucional: tarjetas con borde
 * azul redondeado, encabezado con logo y código QR, emisor y receptor en dos
 * columnas, tabla con encabezado gris y resumen alineado a la derecha.
 */

function pdfMoney(float $value): string
{
    return '$ ' . number_format($value, 2, '.', ',');
}

function pdfNumber(float $value): string
{
    $text = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    return $text === '' ? '0' : $text;
}

function pdfTruncate(SimplePdf $pdf, string $text, float $size, float $maxWidth, string $font = 'regular'): string
{
    if ($pdf->textWidth($text, $size, $font) <= $maxWidth) {
        return $text;
    }
    while ($text !== '' && $pdf->textWidth($text . '...', $size, $font) > $maxWidth) {
        $text = mb_substr($text, 0, -1);
    }
    return rtrim($text) . '...';
}

function pdfReceiverDocumentLabel(array $client): string
{
    if ($client['tipo_documento'] === 'NINGUNO' || $client['numero_documento'] === null) {
        return 'Sin documento';
    }
    return $client['tipo_documento'] . ' ' . $client['numero_documento'];
}

/**
 * Genera el PDF del documento y lo devuelve como cadena binaria.
 *
 * @throws RuntimeException Si el documento no existe o aún no fue emitido.
 */
function buildDocumentPdf(int $tenantId, int $documentId): string
{
    $pdo = db();
    $document = documentRecord($pdo, $tenantId, $documentId);
    if ($document === null) {
        throw new RuntimeException('El documento no existe.');
    }
    $isInternal = documentIsInternalType((string) $document['tipo_dte']);
    if (empty($document['numero_control'])) {
        throw new RuntimeException('Solo puede generar el PDF de un documento emitido.');
    }
    if (!$isInternal && empty($document['codigo_generacion'])) {
        throw new RuntimeException('Solo puede generar el PDF de un documento emitido.');
    }

    $tenant = tenantDocumentContext($pdo, $tenantId);
    $items = documentItemRecords($pdo, $tenantId, $documentId);

    $statement = $pdo->prepare(
        'SELECT c.*, d.nombre AS departamento_nombre, m.nombre AS municipio_nombre
         FROM clientes c
         INNER JOIN mh_departamentos d ON d.tenant_id = c.tenant_id AND d.id = c.departamento_id
         INNER JOIN mh_municipios m ON m.tenant_id = c.tenant_id AND m.id = c.municipio_id
         WHERE c.tenant_id = :tenant_id AND c.id = :id'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $document['cliente_id']]);
    $client = $statement->fetch();
    if ($client === false) {
        throw new RuntimeException('El cliente del documento ya no existe.');
    }

    $definition = documentAllTypeDefinitions()[$document['tipo_dte']] ?? ['nombre' => 'Documento'];
    $docName = mb_strtoupper($definition['nombre']);
    $isInvalidated = $document['estado'] === 'INVALIDADA';
    $isContingency = (int) ($document['tipo_operacion'] ?? 1) === 2;
    $isExport = $document['tipo_dte'] === '11';
    $isExcluded = $document['tipo_dte'] === '14';
    $isNote = in_array($document['tipo_dte'], ['05', '06'], true);

    $related = null;
    if (!empty($document['documento_relacionado_id'])) {
        $relatedStatement = $pdo->prepare(
            'SELECT tipo_dte, numero_control, codigo_generacion, fecha_emision FROM documentos
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $relatedStatement->execute([
            'tenant_id' => $tenantId,
            'id' => (int) $document['documento_relacionado_id'],
        ]);
        $related = $relatedStatement->fetch() ?: null;
    }

    $qrUrl = dteQrUrl($document, (string) ($tenant['mh_mode'] ?? 'SIMULACION'));
    $qrImage = $qrUrl !== null ? QrEncoder::grayscale($qrUrl, 6, 2) : null;

    $discountNoSuj = 0.0;
    $discountExenta = 0.0;
    $discountGravada = 0.0;
    foreach ($items as $item) {
        $discount = (float) ($item['descuento'] ?? 0);
        if ($item['tratamiento_iva'] === 'GRAVADO') {
            $discountGravada += $discount;
        } elseif ($item['tratamiento_iva'] === 'EXENTO') {
            $discountExenta += $discount;
        } else {
            $discountNoSuj += $discount;
        }
    }

    $gravada = (float) $document['total_gravada'];
    $exenta = (float) $document['total_exenta'];
    $noSuj = (float) $document['total_no_suj'];
    $iva = (float) $document['total_iva'];
    $ivaRete = (float) $document['iva_rete1'];
    $reteRenta = (float) $document['rete_renta'];
    $subTotal = $gravada + $exenta + $noSuj;
    $operationTotal = $subTotal + $iva - $ivaRete;
    $totalPagar = (float) $document['total_pagar'];

    // Paleta del formato institucional.
    $blue = [31, 163, 255];
    $navy = [30, 80, 122];
    $grayFill = [217, 217, 217];
    $softGray = [120, 130, 140];
    $lineGray = [205, 210, 216];
    $black = [20, 20, 20];

    $pdf = new SimplePdf();
    $pageWidth = SimplePdf::PAGE_WIDTH;
    $pageHeight = SimplePdf::PAGE_HEIGHT;
    $margin = 28.0;
    $contentWidth = $pageWidth - $margin * 2;

    $footer = static function () use ($pdf, $softGray, $isInternal, $black): void {
        $pdf->setStrokeColor(200, 205, 212);
        $pdf->line(28, 758, 584, 758, 0.6);
        $pdf->setFillColor(...$softGray);
        $pdf->text(
            28,
            769,
            $isInternal
                ? 'FacturaSV · Comprobante interno · Documento NO fiscal, sin validez tributaria'
                : 'FacturaSV · Representación gráfica del DTE · Entorno de SIMULACIÓN, sin validez fiscal',
            6.8
        );
        $pdf->text(584, 769, 'Página {PAGE} de {PAGES}', 6.8, 'regular', 'right');
        $pdf->setFillColor(...$black);
    };

    $card = static function (float $top, float $height, float $x = 28.0, ?float $width = null) use ($pdf, $blue): void {
        $pdf->setStrokeColor(...$blue);
        $pdf->roundedRect($x, $top, $width ?? (584.0 - $x), $height, 8.0, false, true, 0.9);
    };

    $label = static function (float $x, float $top, string $text) use ($pdf, $navy): void {
        $pdf->setFillColor(...$navy);
        $pdf->text($x, $top, $text, 6.2, 'bold');
        $pdf->setFillColor(20, 20, 20);
    };

    $value = static function (float $x, float $top, string $text, float $size = 6.8, float $maxWidth = 0) use ($pdf): void {
        if ($maxWidth > 0) {
            $text = pdfTruncate($pdf, $text, $size, $maxWidth);
        }
        $pdf->text($x, $top, $text, $size);
    };

    $inlineField = static function (float $x, float $top, string $title, string $text, float $maxWidth) use ($pdf, $navy): void {
        $pdf->setFillColor(...$navy);
        $pdf->text($x, $top, $title, 6.2, 'bold');
        $offset = $pdf->textWidth($title, 6.2, 'bold') + 3;
        $pdf->setFillColor(20, 20, 20);
        $pdf->text($x + $offset, $top, pdfTruncate($pdf, $text, 6.8, max(10, $maxWidth - $offset)), 6.8);
    };

    // Columnas del cuerpo según el tipo de documento.
    if ($isExcluded) {
        $columns = [
            ['key' => 'num', 'label' => 'N°', 'width' => 20, 'align' => 'center'],
            ['key' => 'cantidad', 'label' => 'Cantidad', 'width' => 40, 'align' => 'right'],
            ['key' => 'unidad', 'label' => 'Unidad', 'width' => 42, 'align' => 'left'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'width' => 260, 'align' => 'left'],
            ['key' => 'precio', 'label' => 'Precio Unitario', 'width' => 64, 'align' => 'right'],
            ['key' => 'descuento', 'label' => 'Desc. Item', 'width' => 50, 'align' => 'right'],
            ['key' => 'compra', 'label' => 'Compra', 'width' => 80, 'align' => 'right'],
        ];
    } elseif ($isExport) {
        $columns = [
            ['key' => 'num', 'label' => 'N°', 'width' => 20, 'align' => 'center'],
            ['key' => 'cantidad', 'label' => 'Cantidad', 'width' => 40, 'align' => 'right'],
            ['key' => 'unidad', 'label' => 'Unidad', 'width' => 42, 'align' => 'left'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'width' => 260, 'align' => 'left'],
            ['key' => 'precio', 'label' => 'Precio Unitario', 'width' => 64, 'align' => 'right'],
            ['key' => 'descuento', 'label' => 'Desc. Item', 'width' => 50, 'align' => 'right'],
            ['key' => 'gravada', 'label' => 'Ventas Gravadas', 'width' => 80, 'align' => 'right'],
        ];
    } else {
        $columns = [
            ['key' => 'num', 'label' => 'N°', 'width' => 20, 'align' => 'center'],
            ['key' => 'cantidad', 'label' => 'Cantidad', 'width' => 38, 'align' => 'right'],
            ['key' => 'unidad', 'label' => 'Unidad', 'width' => 38, 'align' => 'left'],
            ['key' => 'descripcion', 'label' => 'Descripción', 'width' => 158, 'align' => 'left'],
            ['key' => 'precio', 'label' => 'Precio Unitario', 'width' => 54, 'align' => 'right'],
            ['key' => 'descuento', 'label' => 'Desc. Item', 'width' => 42, 'align' => 'right'],
            ['key' => 'otros', 'label' => 'Otros montos no afectados', 'width' => 58, 'align' => 'right'],
            ['key' => 'no_suj', 'label' => 'Ventas No Sujetas', 'width' => 52, 'align' => 'right'],
            ['key' => 'exenta', 'label' => 'Ventas Exentas', 'width' => 44, 'align' => 'right'],
            ['key' => 'gravada', 'label' => 'Ventas Gravadas', 'width' => 52, 'align' => 'right'],
        ];
    }

    $rowHeight = static function (array $item) use ($columns): float {
        $descriptionColumn = null;
        foreach ($columns as $column) {
            if ($column['key'] === 'descripcion') {
                $descriptionColumn = $column;
            }
        }
        $width = ($descriptionColumn['width'] ?? 150) - 6;
        $probe = new SimplePdf();
        $lines = $probe->wrapText((string) $item['descripcion'], 6.3, $width, 'regular', 2);
        return max(14.0, count($lines) * 8.5 + 6.0);
    };

    // Distribución de los ítems en páginas. La última página reserva espacio
    // para el resumen y las tarjetas finales.
    $pages = [];
    $remaining = array_values($items);
    $firstTableTop = $isNote ? 386.0 : 340.0;
    $tableTop = $firstTableTop;
    $index = 0;
    $summarySpace = 292.0;
    while ($index < count($remaining)) {
        $y = $tableTop + 20.0;
        $pageItems = [];
        while ($index < count($remaining)) {
            $height = $rowHeight($remaining[$index]);
            $isLast = $index === count($remaining) - 1;
            $limit = $isLast ? 748.0 - $summarySpace : 744.0;
            if ($y + $height > $limit && $pageItems !== []) {
                break;
            }
            if ($y + $height > 744.0 && $pageItems === []) {
                break;
            }
            $pageItems[] = $remaining[$index];
            $y += $height;
            $index++;
        }
        if ($pageItems === [] && $index < count($remaining)) {
            // Una línea demasiado alta siempre cabe sola.
            $pageItems[] = $remaining[$index];
            $y += $rowHeight($remaining[$index]);
            $index++;
        }
        $pages[] = ['items' => $pageItems, 'end' => $y];
        $tableTop = 62.0; // páginas de continuación
    }
    if ($pages === []) {
        $pages[] = ['items' => [], 'end' => $firstTableTop + 20.0];
    }
    $lastPage = count($pages) - 1;
    if ($pages[$lastPage]['end'] + $summarySpace > 752.0) {
        $pages[] = ['items' => [], 'end' => 82.0];
        $lastPage = count($pages) - 1;
    }

    $drawTableHeader = static function (float $top) use ($pdf, $columns, $grayFill, $lineGray, $black): float {
        $pdf->setFillColor(...$grayFill);
        $pdf->roundedRect(28, $top, 556, 21, 4, true, false, 0.5);
        $pdf->setFillColor(...$black);
        $twoLineLabels = [
            'precio' => ['Precio', 'Unitario'],
            'descuento' => ['Desc.', 'Item'],
            'otros' => ['Otros montos', 'no afectados'],
            'no_suj' => ['Ventas', 'No Sujetas'],
            'exenta' => ['Ventas', 'Exentas'],
            'gravada' => ['Ventas', 'Gravadas'],
            'compra' => ['Compra', ''],
        ];
        $x = 28.0;
        foreach ($columns as $column) {
            $align = $column['align'];
            $labelX = $align === 'right' ? $x + $column['width'] - 3
                : ($align === 'center' ? $x + $column['width'] / 2 : $x + 3);
            $textAlign = $align === 'right' ? 'right' : ($align === 'center' ? 'center' : 'left');
            $lines = $twoLineLabels[$column['key']] ?? [$column['label']];
            if (count($lines) > 1) {
                $pdf->text($labelX, $top + 9, $lines[0], 5.2, 'bold', $textAlign);
                $pdf->text($labelX, $top + 16, $lines[1], 5.2, 'bold', $textAlign);
            } else {
                $pdf->text($labelX, $top + 13, $lines[0], 5.4, 'bold', $textAlign);
            }
            $x += $column['width'];
        }
        $pdf->setStrokeColor(...$lineGray);
        $pdf->line(28, $top + 21, 584, $top + 21, 0.5);
        return $top + 21;
    };

    $drawItem = static function (array $item, float $top) use ($pdf, $columns, $lineGray, $black): float {
        $descriptionColumn = null;
        foreach ($columns as $column) {
            if ($column['key'] === 'descripcion') {
                $descriptionColumn = $column;
            }
        }
        $lines = $pdf->wrapText((string) $item['descripcion'], 6.3, ($descriptionColumn['width'] ?? 150) - 6, 'regular', 2);
        $height = max(14.0, count($lines) * 8.5 + 6.0);
        $values = [
            'num' => (string) (int) $item['num_item'],
            'cantidad' => pdfNumber((float) $item['cantidad']),
            'unidad' => pdfTruncate($pdf, (string) $item['unidad_nombre'], 6.2, 36),
            'precio' => pdfMoney((float) $item['precio_unitario']),
            'descuento' => pdfMoney((float) $item['descuento']),
            'otros' => pdfMoney(0),
            'no_suj' => pdfMoney((float) $item['venta_no_suj']),
            'exenta' => pdfMoney((float) $item['venta_exenta']),
            'gravada' => pdfMoney((float) $item['venta_gravada']),
            'compra' => pdfMoney((float) ($item['compra'] ?? 0)),
        ];
        $x = 28.0;
        $pdf->setFillColor(...$black);
        foreach ($columns as $column) {
            if ($column['key'] === 'descripcion') {
                foreach ($lines as $lineIndex => $line) {
                    $pdf->text($x + 3, $top + 9 + $lineIndex * 8.5, $line, 6.3);
                }
                $x += $column['width'];
                continue;
            }
            $align = $column['align'];
            $valueX = $align === 'right' ? $x + $column['width'] - 3
                : ($align === 'center' ? $x + $column['width'] / 2 : $x + 3);
            $pdf->text($valueX, $top + 9.5, $values[$column['key']] ?? '', 6.3, 'regular', $align);
            $x += $column['width'];
        }
        $pdf->setStrokeColor(...$lineGray);
        $pdf->line(28, $top + $height, 584, $top + $height, 0.4);
        return $top + $height;
    };

    $summaryBlock = static function (float $top) use (
        $pdf, $navy, $black, $grayFill, $lineGray,
        $gravada, $exenta, $noSuj, $iva, $ivaRete, $reteRenta,
        $subTotal, $operationTotal, $totalPagar,
        $discountNoSuj, $discountExenta, $discountGravada, $document
    ): float {
        $x = 284.0;
        $valueX = 580.0;
        $y = $top;
        $pdf->setFillColor(...$navy);
        $pdf->text($x, $y, 'Suma de Ventas:', 6.6, 'bold');
        $pdf->setFillColor(...$black);
        $pdf->text(470, $y, pdfMoney($noSuj), 6.6, 'regular', 'right');
        $pdf->text(512, $y, pdfMoney($exenta), 6.6, 'regular', 'right');
        $pdf->text($valueX, $y, pdfMoney($gravada), 6.6, 'regular', 'right');
        $y += 11;

        $pdf->setFillColor(...$navy);
        $pdf->text(500, $y, 'Suma Total Ventas:', 6.6, 'bold', 'right');
        $pdf->text($valueX, $y, pdfMoney($subTotal), 6.6, 'bold', 'right');
        $pdf->setFillColor(...$black);
        $y += 11;

        $rows = [
            ['Monto global de Desc., Rebajas y otros a Ventas No Sujetas:', $discountNoSuj],
            ['Monto global de Desc., Rebajas y otros a Ventas Exentas:', $discountExenta],
            ['Monto global Desc., Rebajas y otros a Ventas Gravadas:', $discountGravada],
            ['Sub-Total:', $subTotal],
            ['IVA (Impuesto al Valor Agregado 13%):', $iva],
            ['IVA Retenido:', $ivaRete],
            ['Renta 10% (ISR):', $reteRenta],
        ];
        foreach ($rows as [$text, $amount]) {
            $pdf->text(500, $y, pdfTruncate($pdf, $text, 6.0, 214), 6.0, 'regular', 'right');
            $pdf->text($valueX, $y, pdfMoney((float) $amount), 6.4, 'regular', 'right');
            $y += 10.5;
        }

        $pdf->setFillColor(...$navy);
        $pdf->text(500, $y + 1, 'Monto Total de la Operación:', 6.6, 'bold', 'right');
        $pdf->setFillColor(...$black);
        $pdf->text($valueX, $y + 1, pdfMoney($operationTotal), 6.6, 'bold', 'right');
        $y += 13;

        $pdf->setFillColor(...$grayFill);
        $pdf->roundedRect(280, $y - 1, 304, 15, 3, true, false, 0.5);
        $pdf->setFillColor(...$black);
        $pdf->text($x, $y + 9.5, 'Total a Pagar:', 8.0, 'bold');
        $pdf->text($valueX, $y + 9.5, pdfMoney($totalPagar), 8.0, 'bold', 'right');
        $pdf->setStrokeColor(...$lineGray);
        return $y + 20;
    };

    foreach ($pages as $pageIndex => $page) {
        $pdf->addPage();

        if ($isInvalidated) {
            $pdf->setFillColor(247, 205, 205);
            $pdf->rotatedText(100, 640, 'INVALIDADA', 58, 38, 'bold');
            $pdf->setFillColor(0, 0, 0);
        }

        if ($pageIndex === 0) {
            $card(26, 164);

            // Logo del emisor.
            $logoPath = tenantLogoAbsolutePath($tenant['logo_path'] ?? null);
            $logoDrawn = false;
            if ($logoPath !== null) {
                $info = @getimagesize($logoPath);
                if ($info !== false && ($info[2] ?? 0) === IMAGETYPE_JPEG) {
                    $maxW = 96.0;
                    $maxH = 48.0;
                    $ratio = min($maxW / $info[0], $maxH / $info[1]);
                    $w = $info[0] * $ratio;
                    $h = $info[1] * $ratio;
                    $pdf->imageJpeg((string) file_get_contents($logoPath), (int) $info[0], (int) $info[1], 40, 36, $w, $h);
                    $logoDrawn = true;
                }
            }
            if (!$logoDrawn) {
                $pdf->setStrokeColor(...$blue);
                $pdf->roundedRect(40, 36, 96, 48, 4, false, true, 0.7);
                $pdf->setFillColor(...$navy);
                $pdf->text(88, 64, 'LOGO', 8, 'bold', 'center');
                $pdf->setFillColor(0, 0, 0);
            }

            $centerX = 306.0;
            $pdf->setFillColor(...$black);
            if ($isInternal) {
                $pdf->text($centerX, 50, 'COMPROBANTE INTERNO', 12.0, 'bold', 'center');
                $pdf->setFillColor(...$navy);
                $pdf->text($centerX, 68, 'DOCUMENTO NO FISCAL', 10.5, 'bold', 'center');
                $pdf->setFillColor(...$black);
            } else {
                $pdf->text($centerX, 50, 'DOCUMENTO TRIBUTARIO ELECTRÓNICO', 10.5, 'bold', 'center');
                $pdf->text($centerX, 68, $docName, 11.0, 'bold', 'center');
            }

            // Código QR de consulta pública.
            if ($qrImage !== null) {
                $pdf->imageGray($qrImage['pixels'], $qrImage['width'], $qrImage['height'], 490, 34, 74, 74);
            } else {
                $pdf->setStrokeColor(...$blue);
                $pdf->roundedRect(490, 34, 74, 74, 5, false, true, 0.7);
                $pdf->setFillColor(...$navy);
                $pdf->text(527, 66, 'NO FISCAL', 6.6, 'bold', 'center');
                $pdf->text(527, 76, 'SIN QR', 6.2, 'regular', 'center');
                $pdf->setFillColor(0, 0, 0);
            }

            $idLeft = 40.0;
            $idRight = 306.0;
            $maxLeft = 220.0;
            $maxRight = 170.0;
            $codeValue = $isInternal ? 'No aplica' : (string) $document['codigo_generacion'];
            $sealValue = $isInternal ? 'No aplica' : ($document['sello_recepcion'] ?: 'No recibido');
            $modelValue = $isInternal ? 'Comprobante interno' : 'Modelo Facturación Previo';
            $transmissionValue = $isInternal ? 'No aplica' : ($isContingency ? 'Transmisión en Contingencia' : 'Transmisión Normal');

            $label($idLeft, 100, 'Código de Generación:');
            $value($idLeft, 109, $codeValue, 6.8, $maxLeft);
            $label($idLeft, 123, 'Número de Control:');
            $value($idLeft, 132, (string) $document['numero_control'], 6.8, $maxLeft);
            $label($idLeft, 146, 'Sello Recepción:');
            $value($idLeft, 155, $sealValue, 6.8, $maxLeft);

            $label($idRight, 100, 'Modelo de Facturación:');
            $value($idRight, 109, $modelValue, 6.8, $maxRight);
            $label($idRight, 123, 'Tipo de Transmisión:');
            $value($idRight, 132, $transmissionValue, 6.8, $maxRight);
            $label($idRight, 146, 'Fecha y Hora de Generación:');
            $value(
                $idRight,
                155,
                $document['fecha_emision'] . ($document['hora_emision'] ? ' ' . $document['hora_emision'] : ''),
                6.8,
                $maxRight
            );

            if ($isContingency) {
                $pdf->setFillColor(180, 83, 9);
                $pdf->text(584, 18, 'EMITIDO EN CONTINGENCIA', 6.6, 'bold', 'right');
                $pdf->setFillColor(0, 0, 0);
            }

            // Emisor y receptor.
            $partyTop = 198.0;
            $partyHeight = 132.0;
            $card($partyTop, $partyHeight, 28, 270);
            $card($partyTop, $partyHeight, 314, 270);

            $emitterX = 38.0;
            $y = $partyTop + 13;
            $label($emitterX, $y, 'Nombre o razón social:');
            $value($emitterX, $y + 8.5, (string) $tenant['razon_social'], 6.8, 250);
            $y += 23;
            $inlineField($emitterX, $y, 'NIT:', (string) $tenant['nit'], 120);
            $inlineField($emitterX + 122, $y, 'NRC:', (string) $tenant['numero_registro_mh'], 128);
            $y += 15;
            $label($emitterX, $y, 'Actividad económica:');
            $value($emitterX, $y + 8.5, (string) $tenant['codigo_actividad'], 6.8, 250);
            $y += 21;
            $label($emitterX, $y, 'Dirección:');
            $addressLines = $pdf->wrapText((string) $tenant['direccion'], 6.4, 246, 'regular', 2);
            $addressY = $y + 8.5;
            foreach ($addressLines as $line) {
                $value($emitterX, $addressY, $line, 6.4);
                $addressY += 7.5;
            }
            $y = $addressY + 4;
            $inlineField($emitterX, $y, 'Teléfono:', (string) $tenant['telefono'], 246);
            $y += 11;
            $inlineField($emitterX, $y, 'Correo electrónico:', (string) $tenant['email'], 246);

            $receiverX = 324.0;
            $y = $partyTop + 13;
            $label($receiverX, $y, 'Nombre o razón social:');
            $value($receiverX, $y + 8.5, (string) $client['nombre'], 6.8, 250);
            $y += 23;
            $inlineField($receiverX, $y, 'Documento:', pdfReceiverDocumentLabel($client), 250);
            $y += 15;
            $label($receiverX, $y, 'Actividad económica:');
            $value($receiverX, $y + 8.5, (string) ($client['actividad_economica'] ?: 'No especificada'), 6.8, 250);
            $y += 21;
            $label($receiverX, $y, 'Dirección:');
            $receiverAddress = $client['municipio_nombre'] . ', ' . $client['departamento_nombre'] . ' · ' . $client['direccion'];
            $addressLines = $pdf->wrapText($receiverAddress, 6.4, 246, 'regular', 2);
            $addressY = $y + 8.5;
            foreach ($addressLines as $line) {
                $value($receiverX, $addressY, $line, 6.4);
                $addressY += 7.5;
            }
            $y = $addressY + 4;
            $inlineField($receiverX, $y, 'Teléfono:', (string) ($client['telefono'] ?: '—'), 246);
            $y += 11;
            $inlineField($receiverX, $y, 'Correo electrónico:', (string) ($client['email'] ?: '—'), 246);

            if ($isInvalidated) {
                $pdf->setFillColor(185, 28, 28);
                $pdf->text(584, 190, 'DOCUMENTO INVALIDADO', 7, 'bold', 'right');
                $pdf->setFillColor(0, 0, 0);
            }
        } else {
            $pdf->setFillColor(...$black);
            $pdf->text(28, 42, pdfTruncate($pdf, (string) $tenant['nombre_comercial'], 9, 330, 'bold'), 9, 'bold');
            $pdf->text(584, 42, $docName . ' · ' . $document['numero_control'], 7.2, 'regular', 'right');
            $pdf->setStrokeColor(...$lineGray);
            $pdf->line(28, 50, 584, 50, 0.6);
            if ($isInvalidated) {
                $pdf->setFillColor(185, 28, 28);
                $pdf->text(584, 60, 'DOCUMENTO INVALIDADO', 6.6, 'bold', 'right');
                $pdf->setFillColor(0, 0, 0);
            }
        }

        if ($pageIndex === 0 && $isNote && $related !== null) {
            $relatedTop = 334.0;
            $card($relatedTop, 40);
            $label(40, $relatedTop + 12, 'Tipo de documento Relacionado:');
            $value(40, $relatedTop + 22, documentAllTypeDefinitions()[$related['tipo_dte']]['nombre'] ?? $related['tipo_dte'], 6.8, 160);
            $label(230, $relatedTop + 12, 'N de Documento Relacionado:');
            $value(
                230,
                $relatedTop + 22,
                (string) ($related['codigo_generacion'] ?: $related['numero_control']),
                6.6,
                180
            );
            $label(430, $relatedTop + 12, 'Fecha de Documento Relacionado:');
            $value(430, $relatedTop + 22, (string) $related['fecha_emision'], 6.8, 130);
        }

        $tableTop = $pageIndex === 0 ? $firstTableTop : 62.0;
        $y = $tableTop;
        if ($page['items'] !== []) {
            $y = $drawTableHeader($y);
            foreach ($page['items'] as $item) {
                $y = $drawItem($item, $y);
            }
            $pdf->setStrokeColor(...$lineGray);
            $pdf->line(28, $tableTop, 28, $y, 0.4);
            $pdf->line(584, $tableTop, 584, $y, 0.4);
        }

        if ($pageIndex === $lastPage) {
            $summaryTop = max($y + 16, 470.0);
            if ($summaryTop > 748.0 - 250.0) {
                $summaryTop = 748.0 - 250.0;
            }
            $summaryBottom = $summaryBlock($summaryTop);

            // Valor en letras, condición y observaciones.
            $infoTop = $summaryBottom + 12;
            $card($infoTop, 58);
            $label(40, $infoTop + 14, 'Valor en Letras:');
            $value(126, $infoTop + 14, 'Son ' . amountToSpanishWords($totalPagar), 6.8, 448);
            $label(40, $infoTop + 32, 'Condición de la Operación:');
            $value(150, $infoTop + 32, (string) $document['condicion_nombre'], 6.8, 110);
            if ((int) ($document['dias_credito'] ?? 0) > 0) {
                $value(260, $infoTop + 32, 'Plazo: ' . (int) $document['dias_credito'] . ' días', 6.8, 80);
            }
            $label(348, $infoTop + 32, 'Observaciones:');
            $value(412, $infoTop + 32, (string) ($document['observaciones'] ?: '—'), 6.6, 160);
            $label(40, $infoTop + 48, 'Forma de pago:');
            $value(110, $infoTop + 48, (string) $document['pago_nombre'], 6.6, 240);
            if ($isExport) {
                $value(360, $infoTop + 48, 'País: ' . $document['export_cod_pais'] . ' · ' . $document['export_nombre_pais'], 6.6, 212);
            } elseif ($isExcluded) {
                $value(360, $infoTop + 48, 'Sujeto excluido · operación sin IVA', 6.6, 212);
            }

            // Responsables.
            $responsibleTop = $infoTop + 66;
            $card($responsibleTop, 46);
            $inlineField(40, $responsibleTop + 14, 'Responsable por parte del Emisor:', '—', 240);
            $inlineField(310, $responsibleTop + 14, 'N° de Documento:', '—', 240);
            $inlineField(40, $responsibleTop + 31, 'Responsable por parte del Receptor:', '—', 240);
            $inlineField(310, $responsibleTop + 31, 'N° de Documento:', '—', 240);
        }

        $footer();
    }

    return $pdf->output();
}
