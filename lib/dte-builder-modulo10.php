<?php

declare(strict_types=1);

/**
 * Módulo 10: DTE 05 (Nota de Crédito), 06 (Nota de Débito),
 * 11 (Factura de Exportación) y 14 (Factura de Sujeto Excluido).
 *
 * Se mantiene separado del builder de Factura/CCF para no alterar el flujo
 * ya probado de los tipos 01 y 03.
 */

function module10Address(array $tenant, string $district): array
{
    return [
        'departamento' => (string) ($tenant['departamento_emisor'] ?? '06'),
        'municipio' => (string) ($tenant['municipio_emisor'] ?? '04'),
        'distrito' => $district,
        'complemento' => mb_substr((string) $tenant['direccion'], 0, 200),
    ];
}

function module10ClientAddress(array $client, string $district): array
{
    return [
        'departamento' => (string) $client['departamento_codigo'],
        'municipio' => (string) $client['municipio_codigo'],
        'distrito' => $district,
        'complemento' => mb_substr((string) $client['direccion'], 0, 200),
    ];
}

function module10ClientActivity(array $client): string
{
    $activity = trim((string) ($client['actividad_economica'] ?? ''));
    if (mb_strlen($activity) < 5) {
        $activity = 'Actividad económica no especificada';
    }
    return mb_substr($activity, 0, 150);
}

function module10ClientDocumentType(array $client): string
{
    return match ($client['tipo_documento']) {
        'NIT' => '36',
        'DUI' => '13',
        default => '',
    };
}

function module10ClientDocumentNumber(array $client): string
{
    return (string) preg_replace('/\D/', '', (string) ($client['numero_documento'] ?? ''));
}

function module10Phone(?string $phone): ?string
{
    $digits = (string) preg_replace('/\D/', '', (string) $phone);
    return $digits === '' ? null : $digits;
}

function module10Email(?string $email): ?string
{
    $email = trim((string) $email);
    return $email === '' ? null : mb_substr($email, 0, 100);
}

function module10Payments(float $total, array $document, array $condition, array $payment): array
{
    $conditionCode = (int) $condition['codigo'];
    return [[
        'codigo' => (string) $payment['codigo'],
        'montoPago' => dteMoney($total),
        'referencia' => null,
        'plazo' => $conditionCode === 2 ? '01' : null,
        'periodo' => $conditionCode === 2 ? (int) $document['dias_credito'] : null,
    ]];
}

/**
 * Nota de Crédito / Débito: precios con IVA si el documento relacionado es
 * Factura 01; sin IVA si es Crédito Fiscal 03.
 */
function calculateNoteTotals(string $type, array $items, bool $withholdIva, array $options = []): array
{
    $relatedType = (string) ($options['related_type'] ?? '01');
    $includesIva = $relatedType === '01';
    $calculated = [];
    $totals = ['GRAVADO' => 0.0, 'EXENTO' => 0.0, 'NO_SUJETO' => 0.0];
    $discount = 0.0;
    $iva = 0.0;

    foreach ($items as $index => $item) {
        $quantity = (float) $item['cantidad'];
        $price = (float) $item['precio_unitario'];
        $lineDiscount = (float) $item['descuento'];
        $gross = dteQuantity(($quantity * $price) - $lineDiscount);
        if ($gross < 0) {
            throw new InvalidArgumentException('El descuento no puede superar el importe de la línea ' . ($index + 1) . '.');
        }

        $line = $item;
        $line['num_item'] = $index + 1;
        $line['cantidad'] = dteQuantity($quantity);
        $line['precio_unitario'] = dteQuantity($price);
        $line['descuento'] = dteQuantity($lineDiscount);
        $line['venta_gravada'] = 0.0;
        $line['venta_exenta'] = 0.0;
        $line['venta_no_suj'] = 0.0;
        $line['iva_item'] = 0.0;
        $line['compra'] = null;

        if ($item['tratamiento_iva'] === 'GRAVADO') {
            $net = $includesIva ? dteQuantity($gross / 1.13) : $gross;
            $lineIva = $includesIva ? dteQuantity($gross - $net) : dteQuantity($net * 0.13);
            $line['venta_gravada'] = $net;
            $line['iva_item'] = $lineIva;
            $totals['GRAVADO'] += $net;
            $iva += $lineIva;
        } elseif ($item['tratamiento_iva'] === 'EXENTO') {
            $line['venta_exenta'] = $gross;
            $totals['EXENTO'] += $gross;
        } else {
            $line['venta_no_suj'] = $gross;
            $totals['NO_SUJETO'] += $gross;
        }
        $discount += $lineDiscount;
        $calculated[] = $line;
    }

    $gravada = dteMoney($totals['GRAVADO']);
    $exenta = dteMoney($totals['EXENTO']);
    $noSuj = dteMoney($totals['NO_SUJETO']);
    $subTotal = dteMoney($gravada + $exenta + $noSuj);
    $totalIva = dteMoney($iva);
    $ivaRete = $withholdIva ? dteMoney($gravada * 0.01) : 0.0;
    $montoTotal = dteMoney($subTotal + $totalIva);
    $totalPagar = dteMoney($montoTotal - $ivaRete);

    $summary = [
        'totalNoSuj' => $noSuj,
        'totalExenta' => $exenta,
        'totalGravada' => $gravada,
        'subTotalVentas' => $subTotal,
        'totalDescu' => dteMoney($discount),
        'tributos' => $totalIva > 0
            ? [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => $totalIva]]
            : null,
        'montoTotalOperacion' => $montoTotal,
        'ivaPerci' => 0.0,
        'totalIva' => $totalIva,
        'ivaRete' => $ivaRete,
        'totalNoGravado' => 0.0,
        'totalPagar' => $totalPagar,
        'totalLetras' => amountToSpanishWords($totalPagar),
        '_db' => [
            'total_gravada' => $gravada,
            'total_exenta' => $exenta,
            'total_no_suj' => $noSuj,
            'total_descuento' => dteMoney($discount),
            'total_iva' => $totalIva,
            'iva_rete1' => $ivaRete,
            'rete_renta' => 0.0,
            'total_pagar' => $totalPagar,
        ],
    ];
    if ($type === '06') {
        $summary['numPagoElectronico'] = null;
    }
    return [$calculated, $summary];
}

/**
 * Factura de Exportación: venta gravada sin IVA, más flete y seguro.
 */
function calculateExportTotals(array $items, array $options = []): array
{
    $calculated = [];
    $totalGravada = 0.0;
    $totalDescuento = 0.0;

    foreach ($items as $index => $item) {
        $quantity = (float) $item['cantidad'];
        $price = (float) $item['precio_unitario'];
        $lineDiscount = (float) $item['descuento'];
        $gross = dteQuantity(($quantity * $price) - $lineDiscount);
        if ($gross < 0) {
            throw new InvalidArgumentException('El descuento no puede superar el importe de la línea ' . ($index + 1) . '.');
        }

        $line = $item;
        $line['num_item'] = $index + 1;
        $line['cantidad'] = dteQuantity($quantity);
        $line['precio_unitario'] = dteQuantity($price);
        $line['descuento'] = dteQuantity($lineDiscount);
        $line['venta_gravada'] = $gross;
        $line['venta_exenta'] = 0.0;
        $line['venta_no_suj'] = 0.0;
        $line['iva_item'] = 0.0;
        $line['compra'] = null;
        $totalGravada += $gross;
        $totalDescuento += $lineDiscount;
        $calculated[] = $line;
    }

    $totalGravada = dteMoney($totalGravada);
    $totalDescuento = dteMoney($totalDescuento);
    $flete = dteMoney(max(0.0, (float) ($options['flete'] ?? 0)));
    $seguro = dteMoney(max(0.0, (float) ($options['seguro'] ?? 0)));
    $noOnerosas = dteMoney(max(0.0, (float) ($options['no_onerosas'] ?? 0)));
    $base = dteMoney($totalGravada + $totalDescuento);
    $porcentajeDescuento = $base > 0 ? dteMoney(($totalDescuento / $base) * 100) : 0.0;
    $montoTotal = dteMoney($totalGravada + $flete + $seguro);
    $totalPagar = $montoTotal;

    return [$calculated, [
        'totalGravada' => $totalGravada,
        'descuGravada' => $totalDescuento,
        'porcentajeDescuento' => $porcentajeDescuento,
        'totalDescu' => $totalDescuento,
        'seguro' => $seguro,
        'flete' => $flete,
        'tributos' => null,
        'montoTotalOperacion' => $montoTotal,
        'totalNoGravado' => 0.0,
        'totalNoOnerosas' => $noOnerosas,
        'totalPagar' => $totalPagar,
        'totalLetras' => amountToSpanishWords($totalPagar),
        'saldoFavor' => 0.0,
        '_db' => [
            'total_gravada' => $totalGravada,
            'total_exenta' => 0.0,
            'total_no_suj' => 0.0,
            'total_descuento' => $totalDescuento,
            'total_iva' => 0.0,
            'iva_rete1' => 0.0,
            'rete_renta' => 0.0,
            'total_pagar' => $totalPagar,
        ],
    ]];
}

/**
 * Factura de Sujeto Excluido: compra sin IVA y retención de renta.
 */
function calculateExcludedTotals(array $items, array $options = []): array
{
    $calculated = [];
    $totalCompra = 0.0;
    $totalDescuento = 0.0;

    foreach ($items as $index => $item) {
        $quantity = (float) $item['cantidad'];
        $price = (float) $item['precio_unitario'];
        $lineDiscount = (float) $item['descuento'];
        $gross = dteQuantity(($quantity * $price) - $lineDiscount);
        if ($gross < 0) {
            throw new InvalidArgumentException('El descuento no puede superar el importe de la línea ' . ($index + 1) . '.');
        }

        $line = $item;
        $line['num_item'] = $index + 1;
        $line['cantidad'] = dteQuantity($quantity);
        $line['precio_unitario'] = dteQuantity($price);
        $line['descuento'] = dteQuantity($lineDiscount);
        $line['compra'] = $gross;
        $line['venta_gravada'] = 0.0;
        $line['venta_exenta'] = 0.0;
        $line['venta_no_suj'] = 0.0;
        $line['iva_item'] = 0.0;
        $totalCompra += $gross;
        $totalDescuento += $lineDiscount;
        $calculated[] = $line;
    }

    $totalCompra = dteMoney($totalCompra);
    $totalDescuento = dteMoney($totalDescuento);
    $subTotal = dteMoney($totalCompra - $totalDescuento);
    $reteRenta = dteMoney(max(0.0, (float) ($options['rete_renta'] ?? 0)));
    $totalPagar = dteMoney(max(0.0, $subTotal - $reteRenta));

    return [$calculated, [
        'totalCompra' => $totalCompra,
        'descu' => $totalDescuento,
        'totalDescu' => $totalDescuento,
        'subTotal' => $subTotal,
        'reteRenta' => $reteRenta,
        'totalPagar' => $totalPagar,
        'totalLetras' => amountToSpanishWords($totalPagar),
        '_db' => [
            'total_gravada' => 0.0,
            'total_exenta' => 0.0,
            'total_no_suj' => $totalCompra,
            'total_descuento' => $totalDescuento,
            'total_iva' => 0.0,
            'iva_rete1' => 0.0,
            'rete_renta' => $reteRenta,
            'total_pagar' => $totalPagar,
        ],
    ]];
}

function module10Emisor(string $type, array $tenant, array $document): array
{
    $base = [
        'nit' => (string) preg_replace('/\D/', '', (string) $tenant['nit']),
        'nrc' => $tenant['numero_registro_mh'] ?: null,
        'nombre' => (string) $tenant['razon_social'],
        'codActividad' => (string) $tenant['codigo_actividad'],
        'descActividad' => 'Actividad económica registrada',
        'direccion' => module10Address($tenant, (string) $document['emisor_distrito']),
        'telefono' => (string) preg_replace('/\D/', '', (string) $tenant['telefono']),
        'correo' => (string) $tenant['email'],
    ];

    if ($type === '05' || $type === '06') {
        $base['nombreComercial'] = $tenant['nombre_comercial'];
        return $base;
    }
    if ($type === '14') {
        $base['codEstable'] = substr((string) $tenant['establecimiento'], 0, 4);
        $base['codPuntoVenta'] = substr((string) $tenant['establecimiento'], 4, 4);
        return $base;
    }

    $base['nombreComercial'] = $tenant['nombre_comercial'];
    $base['codEstable'] = substr((string) $tenant['establecimiento'], 0, 4);
    $base['codPuntoVenta'] = substr((string) $tenant['establecimiento'], 4, 4);
    $base['tipoItemExpor'] = (int) ($document['export_tipo_item'] ?? 1);
    $base['recintoFiscal'] = null;
    $base['tipoRegimen'] = null;
    $base['regimen'] = null;
    return $base;
}

function module10Receptor(string $type, array $client, array $document): array
{
    $documentType = module10ClientDocumentType($client);
    $documentNumber = module10ClientDocumentNumber($client);
    $activity = module10ClientActivity($client);
    $phone = module10Phone($client['telefono'] ?? null);
    $email = module10Email($client['email'] ?? null);

    if ($type === '05' || $type === '06') {
        return [
            'tipoDocumento' => $documentType,
            'numDocumento' => $documentNumber,
            'nrc' => null,
            'nombre' => (string) $client['nombre'],
            'codActividad' => '00000',
            'descActividad' => $activity,
            'nombreComercial' => $client['nombre_comercial'],
            'direccion' => module10ClientAddress($client, (string) $document['receptor_distrito']),
            'telefono' => $phone,
            'correo' => $email,
        ];
    }
    if ($type === '11') {
        return [
            'nombre' => (string) $client['nombre'],
            'tipoDocumento' => $documentType,
            'numDocumento' => $documentNumber,
            'descActividad' => $activity,
            'nombreComercial' => $client['nombre_comercial'],
            'codPais' => (string) ($document['export_cod_pais'] ?? ''),
            'nombrePais' => (string) ($document['export_nombre_pais'] ?? ''),
            'complemento' => (string) ($document['export_complemento'] ?? ''),
            'tipoPersona' => (int) ($document['export_tipo_persona'] ?? 0),
            'telefono' => $phone,
            'correo' => $email,
        ];
    }

    return [
        'tipoDocumento' => $documentType,
        'numDocumento' => $documentNumber,
        'nombre' => (string) $client['nombre'],
        'codActividad' => '00000',
        'descActividad' => $activity,
        'direccion' => module10ClientAddress($client, (string) $document['receptor_distrito']),
        'telefono' => $phone,
        'correo' => $email,
    ];
}

function module10Body(string $type, array $items, ?array $related): array
{
    $body = [];
    foreach ($items as $item) {
        if ($type === '05' || $type === '06') {
            $body[] = [
                'numItem' => (int) $item['num_item'],
                'tipoItem' => $item['tipo'] === 'BIEN' ? 1 : 2,
                'numeroDocumento' => (string) ($related['numero_control'] ?? ''),
                'cantidad' => (float) $item['cantidad'],
                'codigo' => $item['codigo'] !== '' ? mb_substr((string) $item['codigo'], 0, 25) : null,
                'codTributo' => null,
                'uniMedida' => (int) $item['unidad_codigo'],
                'descripcion' => (string) $item['descripcion'],
                'precioUni' => (float) $item['precio_unitario'],
                'montoDescu' => (float) $item['descuento'],
                'ventaNoSuj' => (float) $item['venta_no_suj'],
                'ventaExenta' => (float) $item['venta_exenta'],
                'ventaGravada' => (float) $item['venta_gravada'],
                'tributos' => $item['tratamiento_iva'] === 'GRAVADO' ? ['20'] : null,
                'noGravado' => 0.0,
                'ivaPerci' => 0.0,
                'totalIva' => (float) $item['iva_item'],
                'ivaRete' => 0.0,
            ];
            continue;
        }
        if ($type === '11') {
            $body[] = [
                'numItem' => (int) $item['num_item'],
                'tipoItem' => $item['tipo'] === 'BIEN' ? 1 : 2,
                'numeroDocumento' => null,
                'cantidad' => (float) $item['cantidad'],
                'codigo' => $item['codigo'] !== '' ? mb_substr((string) $item['codigo'], 0, 25) : null,
                'codTributo' => null,
                'uniMedida' => (int) $item['unidad_codigo'],
                'descripcion' => (string) $item['descripcion'],
                'precioUni' => (float) $item['precio_unitario'],
                'montoDescu' => (float) $item['descuento'],
                'ventaGravada' => (float) $item['venta_gravada'],
                'tributos' => null,
                'noGravado' => 0.0,
            ];
            continue;
        }
        $body[] = [
            'numItem' => (int) $item['num_item'],
            'tipoItem' => $item['tipo'] === 'BIEN' ? 1 : 2,
            'cantidad' => (float) $item['cantidad'],
            'codigo' => $item['codigo'] !== '' ? mb_substr((string) $item['codigo'], 0, 25) : null,
            'uniMedida' => (int) $item['unidad_codigo'],
            'descripcion' => (string) $item['descripcion'],
            'precioUni' => (float) $item['precio_unitario'],
            'montoDescu' => (float) $item['descuento'],
            'compra' => (float) $item['compra'],
        ];
    }
    return $body;
}

function module10Summary(string $type, array $summary, array $document, array $condition, array $payment): array
{
    $conditionCode = (int) $condition['codigo'];
    $summary['condicionOperacion'] = $conditionCode;
    $summary['observaciones'] = $document['observaciones'] ?: null;

    if ($type === '05' || $type === '06') {
        $summary['codigoRetencionMH'] = null;
        if ($type === '06') {
            $summary['numPagoElectronico'] = null;
        }
        return $summary;
    }
    if ($type === '11') {
        $summary['pagos'] = module10Payments((float) $summary['totalPagar'], $document, $condition, $payment);
        $summary['codIncoterms'] = $document['export_incoterms'] ?: null;
        $summary['descIncoterms'] = $document['export_desc_incoterms'] ?: null;
        $summary['numPagoElectronico'] = null;
        return $summary;
    }

    $summary['pagos'] = module10Payments((float) $summary['totalPagar'], $document, $condition, $payment);
    return $summary;
}

function buildModule10Dte(string $type, array $document, array $tenant, array $client, array $items, array $condition, array $payment, ?array $related, array $options, array $definition): array
{
    if (($type === '05' || $type === '06') && $related === null) {
        throw new RuntimeException('La nota requiere un documento relacionado.');
    }
    $options = $options + [
        'related_type' => $related['tipo_dte'] ?? null,
        'flete' => (float) ($document['export_flete'] ?? 0),
        'seguro' => (float) ($document['export_seguro'] ?? 0),
        'no_onerosas' => (float) ($document['export_no_onerosas'] ?? 0),
        'rete_renta' => (float) ($document['rete_renta'] ?? 0),
    ];
    [$items, $summary] = calculateDocumentTotals($type, $items, (bool) $document['aplicar_iva_retenido'], $options);

    $identification = [
        'version' => (int) $definition['version'],
        'ambiente' => $tenant['mh_mode'] === 'PRODUCCION' ? '01' : '00',
        'tipoDte' => $type,
        'numeroControl' => (string) $document['numero_control'],
        'codigoGeneracion' => (string) $document['codigo_generacion'],
        'tipoModelo' => 1,
        'tipoOperacion' => (int) ($document['tipo_operacion'] ?? 1),
        'tipoContingencia' => $document['tipo_contingencia'] !== null ? (int) $document['tipo_contingencia'] : null,
        'motivoContin' => $document['motivo_contingencia'] ?: null,
        'fecEmi' => (string) $document['fecha_emision'],
        'horEmi' => (string) $document['hora_emision'],
        'tipoMoneda' => 'USD',
    ];
    if ($type === '05' || $type === '06') {
        $identification['fusion'] = null;
    }

    $body = module10Body($type, $items, $related);
    $summary = module10Summary($type, $summary, $document, $condition, $payment);
    unset($summary['_db']);

    $dte = [
        'identificacion' => $identification,
    ];
    if ($type === '05' || $type === '06') {
        $dte['documentoRelacionado'] = [[
            'tipoDocumento' => (string) $related['tipo_dte'],
            'tipoGeneracion' => 2,
            'numeroDocumento' => (string) $related['numero_control'],
            'fechaEmision' => (string) $related['fecha_emision'],
        ]];
        $dte['emisor'] = module10Emisor($type, $tenant, $document);
        $dte['receptor'] = module10Receptor($type, $client, $document);
        $dte['ventaTercero'] = null;
        $dte['cuerpoDocumento'] = $body;
        $dte['resumen'] = $summary;
        $dte['apendice'] = null;
    } elseif ($type === '11') {
        $dte['documentoRelacionado'] = null;
        $dte['emisor'] = module10Emisor($type, $tenant, $document);
        $dte['receptor'] = module10Receptor($type, $client, $document);
        $dte['otrosDocumentos'] = null;
        $dte['ventaTercero'] = null;
        $dte['compraTercero'] = null;
        $dte['cuerpoDocumento'] = $body;
        $dte['resumen'] = $summary;
        $dte['apendice'] = null;
    } else {
        $dte['emisor'] = module10Emisor($type, $tenant, $document);
        $dte['receptor'] = module10Receptor($type, $client, $document);
        $dte['cuerpoDocumento'] = $body;
        $dte['resumen'] = $summary;
        $dte['apendice'] = null;
    }

    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/documentacion-mh/' . $definition['schema']),
        true
    );
    if (!is_array($schema)) {
        throw new RuntimeException('No se pudo cargar el esquema oficial de este tipo de DTE.');
    }
    $errors = validateJsonSchema($dte, $schema);
    if ($errors !== []) {
        throw new RuntimeException('El DTE no superó la validación del esquema oficial: ' . implode(' ', array_slice($errors, 0, 6)));
    }
    return $dte;
}
