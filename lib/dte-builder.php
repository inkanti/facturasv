<?php

declare(strict_types=1);

require_once __DIR__ . '/numero-letras.php';
require_once __DIR__ . '/json-schema-validator.php';
require_once __DIR__ . '/dte-builder-modulo10.php';

function documentTypeDefinitions(): array
{
    return [
        '01' => ['nombre' => 'Factura', 'version' => 2, 'schema' => 'fe-f-v2.json', 'disponible' => true],
        '03' => ['nombre' => 'Comprobante de Crédito Fiscal', 'version' => 4, 'schema' => 'fe-ccf-v4.json', 'disponible' => true],
        '05' => ['nombre' => 'Nota de Crédito', 'version' => 4, 'schema' => 'fe-nc-v4.json', 'disponible' => true],
        '06' => ['nombre' => 'Nota de Débito', 'version' => 4, 'schema' => 'fe-nd-v4.json', 'disponible' => true],
        '11' => ['nombre' => 'Factura de Exportación', 'version' => 3, 'schema' => 'fe-fex-v3.json', 'disponible' => true],
        '14' => ['nombre' => 'Factura de Sujeto Excluido', 'version' => 2, 'schema' => 'fe-fse-v2.json', 'disponible' => true],
    ];
}

/**
 * Definición del comprobante interno. No es un DTE: no tiene esquema MH,
 * código de generación ni transmisión. Se mantiene fuera de
 * documentTypeDefinitions() para no alterar la lista de DTE del sistema.
 */
function documentInternalTypeDefinition(): array
{
    return ['nombre' => 'Comprobante interno', 'version' => 0, 'schema' => null, 'disponible' => true];
}

/** Tipos de documento que puede emitir una empresa: los seis DTE más el interno. */
function documentAllTypeDefinitions(): array
{
    return documentTypeDefinitions() + ['IN' => documentInternalTypeDefinition()];
}

function dteMoney(float $value): float
{
    return round($value + ($value >= 0 ? 0.000000001 : -0.000000001), 2);
}

function dteQuantity(float $value): float
{
    return round($value, 8);
}

function calculateDocumentTotals(string $type, array $items, bool $withholdIva, array $options = []): array
{
    if ($type === '05' || $type === '06') {
        return calculateNoteTotals($type, $items, $withholdIva, $options);
    }
    if ($type === '11') {
        return calculateExportTotals($items, $options);
    }
    if ($type === '14') {
        return calculateExcludedTotals($items, $options);
    }
    $calculated = [];
    $totals = ['GRAVADO' => 0.0, 'EXENTO' => 0.0, 'NO_SUJETO' => 0.0];
    $discount = 0.0;
    $iva = 0.0;

    foreach ($items as $index => $item) {
        $quantity = (float) $item['cantidad'];
        $price = (float) $item['precio_unitario'];
        $lineDiscount = (float) $item['descuento'];
        $gross = dteQuantity(($quantity * $price) - $lineDiscount);
        if ($gross < 0) throw new InvalidArgumentException('El descuento no puede superar el importe de la línea ' . ($index + 1) . '.');

        $line = $item;
        $line['num_item'] = $index + 1;
        $line['cantidad'] = dteQuantity($quantity);
        $line['precio_unitario'] = dteQuantity($price);
        $line['descuento'] = dteQuantity($lineDiscount);
        $line['venta_gravada'] = 0.0;
        $line['venta_exenta'] = 0.0;
        $line['venta_no_suj'] = 0.0;
        $line['iva_item'] = 0.0;

        if ($item['tratamiento_iva'] === 'GRAVADO') {
            // Manual funcional XVIII: FE trabaja precios con IVA incluido; CCFE, sin IVA.
            // El comprobante interno imita a la Factura: sus precios incluyen IVA.
            $net = in_array($type, ['01', 'IN'], true) ? dteQuantity($gross / 1.13) : $gross;
            $line['venta_gravada'] = $net;
            $line['iva_item'] = dteQuantity($gross - $net);
            if (!in_array($type, ['01', 'IN'], true)) $line['iva_item'] = dteQuantity($net * 0.13);
            $totals['GRAVADO'] += $net;
            $iva += $line['iva_item'];
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
    // El resumen separa la base gravada del IVA en ambos tipos de documento.
    $montoTotal = dteMoney($subTotal + $totalIva);
    $totalPagar = dteMoney($montoTotal - $ivaRete);

    $summary = [
        'totalNoSuj' => $noSuj,
        'totalExenta' => $exenta,
        'totalGravada' => $gravada,
        'subTotalVentas' => $subTotal,
        'descuNoSuj' => 0.0,
        'descuExenta' => 0.0,
        'descuGravada' => 0.0,
        'porcentajeDescuento' => 0.0,
        'totalDescu' => dteMoney($discount),
        'tributos' => $type === '03' && $totalIva > 0
            ? [['codigo' => '20', 'descripcion' => 'Impuesto al Valor Agregado 13%', 'valor' => $totalIva]]
            : null,
        'subTotal' => $subTotal,
        'ivaRete' => $ivaRete,
        'montoTotalOperacion' => $montoTotal,
        'totalNoGravado' => 0.0,
        'totalPagar' => $totalPagar,
        'totalLetras' => amountToSpanishWords($totalPagar),
    ];
    if (in_array($type, ['01', 'IN'], true)) $summary['totalIva'] = $totalIva;

    return [$calculated, $summary];
}

function buildDteDocument(array $document, array $tenant, array $client, array $items, array $condition, array $payment, ?array $related = null, array $options = []): array
{
    $type = $document['tipo_dte'];
    $definition = documentTypeDefinitions()[$type] ?? null;
    if (!$definition || !$definition['disponible']) {
        throw new RuntimeException('El esquema oficial de este tipo de DTE aún no está incorporado.');
    }
    if (in_array($type, ['05', '06', '11', '14'], true)) {
        return buildModule10Dte($type, $document, $tenant, $client, $items, $condition, $payment, $related, $options, $definition);
    }
    [$items, $summary] = calculateDocumentTotals($type, $items, (bool) $document['aplicar_iva_retenido']);
    $body = [];
    foreach ($items as $item) {
        $bodyItem = [
            'numItem' => (int) $item['num_item'],
            'tipoItem' => $item['tipo'] === 'BIEN' ? 1 : 2,
            'numeroDocumento' => null,
            'codigo' => $item['codigo'] !== '' ? mb_substr($item['codigo'], 0, 25) : null,
            'codTributo' => null,
            'descripcion' => $item['descripcion'],
            'cantidad' => (float) $item['cantidad'],
            'uniMedida' => (int) $item['unidad_codigo'],
            'precioUni' => (float) $item['precio_unitario'],
            'montoDescu' => (float) $item['descuento'],
            'ventaNoSuj' => (float) $item['venta_no_suj'],
            'ventaExenta' => (float) $item['venta_exenta'],
            'ventaGravada' => (float) $item['venta_gravada'],
            'tributos' => $item['tratamiento_iva'] === 'GRAVADO' ? ['20'] : null,
            'psv' => 0.0,
            'noGravado' => 0.0,
        ];
        if ($type === '01') $bodyItem['ivaItem'] = (float) $item['iva_item'];
        $body[] = $bodyItem;
    }

    $conditionCode = (int) $condition['codigo'];
    $summary['condicionOperacion'] = $conditionCode;
    $summary['pagos'] = [[
        'codigo' => $payment['codigo'],
        'montoPago' => $summary['totalPagar'],
        'referencia' => null,
        'plazo' => $conditionCode === 2 ? '01' : null,
        'periodo' => $conditionCode === 2 ? (int) $document['dias_credito'] : null,
    ]];
    $summary['numPagoElectronico'] = null;
    $summary['observaciones'] = $document['observaciones'] ?: null;
    $summary['saldoFavor'] = 0.0;
    if ($type === '03') $summary['ivaPerci'] = 0.0;

    $tipoOperacion = (int) ($document['tipo_operacion'] ?? 1);
    $tipoContingencia = $tipoOperacion === 2 ? (int) ($document['tipo_contingencia'] ?? 0) : null;
    $motivoContin = $tipoOperacion === 2 ? ($document['motivo_contingencia'] ?? null) : null;
    if ($tipoOperacion === 2 && ($tipoContingencia === null || $tipoContingencia < 1 || $tipoContingencia > 5)) {
        throw new RuntimeException('El tipo de contingencia del DTE no es válido.');
    }
    if ($tipoOperacion === 2 && $tipoContingencia === 5 && trim((string) $motivoContin) === '') {
        throw new RuntimeException('El motivo de contingencia es obligatorio para el tipo 5.');
    }

    $dte = [
        'identificacion' => [
            'version' => $definition['version'],
            'ambiente' => $tenant['mh_mode'] === 'PRODUCCION' ? '01' : '00',
            'tipoDte' => $type,
            'numeroControl' => $document['numero_control'],
            'codigoGeneracion' => $document['codigo_generacion'],
            'tipoModelo' => 1,
            'tipoOperacion' => $tipoOperacion,
            'tipoContingencia' => $tipoContingencia,
            'motivoContin' => $motivoContin,
            'fecEmi' => $document['fecha_emision'],
            'horEmi' => $document['hora_emision'],
            'tipoMoneda' => 'USD',
        ],
        'documentoRelacionado' => null,
        'emisor' => [
            'nit' => preg_replace('/\D/', '', $tenant['nit']),
            'nrc' => $tenant['numero_registro_mh'],
            'nombre' => $tenant['razon_social'],
            'codActividad' => $tenant['codigo_actividad'],
            'descActividad' => 'Actividad económica registrada',
            'nombreComercial' => $tenant['nombre_comercial'],
            'direccion' => [
                'departamento' => (string) ($tenant['departamento_emisor'] ?? '06'),
                'municipio' => (string) ($tenant['municipio_emisor'] ?? '04'),
                'distrito' => $document['emisor_distrito'],
                'complemento' => mb_substr($tenant['direccion'], 0, 200),
            ],
            'telefono' => preg_replace('/\D/', '', $tenant['telefono']),
            'correo' => $tenant['email'],
            'codEstable' => substr($tenant['establecimiento'], 0, 4),
            'codPuntoVenta' => substr($tenant['establecimiento'], 4, 4),
        ],
        'receptor' => buildDteReceiver($type, $client, $document['receptor_distrito']),
        'otrosDocumentos' => null,
        'ventaTercero' => null,
        'cuerpoDocumento' => $body,
        'resumen' => $summary,
        'apendice' => null,
    ];
    // Departamento, municipio y distrito del emisor son configurables (módulo 8).

    $schema = json_decode(file_get_contents(dirname(__DIR__) . '/documentacion-mh/' . $definition['schema']), true);
    $errors = validateJsonSchema($dte, $schema);
    if ($errors !== []) {
        throw new RuntimeException('El DTE no superó la validación del esquema oficial: ' . implode(' ', array_slice($errors, 0, 6)));
    }
    return $dte;
}

function buildDteReceiver(string $type, array $client, string $district): array
{
    $address = [
        'departamento' => $client['departamento_codigo'],
        'municipio' => $client['municipio_codigo'],
        'distrito' => $district,
        'complemento' => mb_substr($client['direccion'], 0, 200),
    ];
    $phone = $client['telefono'] ? preg_replace('/\D/', '', $client['telefono']) : null;
    $activityCode = '00000';
    $activity = $client['actividad_economica'] ?: 'Actividad económica no especificada';
    if ($type === '03') {
        return [
            'nit' => preg_replace('/\D/', '', $client['numero_documento']),
            'nrc' => null,
            'nombre' => $client['nombre'],
            'codActividad' => $activityCode,
            'descActividad' => $activity,
            'nombreComercial' => $client['nombre_comercial'],
            'direccion' => $address,
            'telefono' => $phone,
            'correo' => $client['email'],
        ];
    }
    return [
        'tipoDocumento' => $client['tipo_documento'] === 'NIT' ? '36'
            : ($client['tipo_documento'] === 'DUI' ? '13' : null),
        'numDocumento' => $client['numero_documento'] === null || $client['numero_documento'] === ''
            ? null
            : preg_replace('/\D/', '', $client['numero_documento']),
        'nrc' => null,
        'nombre' => $client['nombre'],
        'codActividad' => $activityCode,
        'descActividad' => $activity,
        'direccion' => $address,
        'telefono' => $phone,
        'correo' => $client['email'],
    ];
}

function contingencyEventSchema(): array
{
    $schema = json_decode(
        (string) file_get_contents(dirname(__DIR__) . '/documentacion-mh/contingencia-schema-v4.json'),
        true
    );
    if (!is_array($schema)) {
        throw new RuntimeException('No se pudo cargar el esquema oficial del evento de contingencia.');
    }
    return $schema;
}

/**
 * Construye el evento de contingencia del esquema MH v4.
 *
 * @param array $header      Codigo de generacion, fechas, horas y motivo.
 * @param array $documents   DTE incluidos, con tipo_dte y codigo_generacion.
 * @param array $tenant      Datos del emisor (tenant + tenant_config).
 * @param array $responsible Nombre, documento y tipo de establecimiento.
 */
function buildContingencyEvent(array $header, array $documents, array $tenant, array $responsible): array
{
    $details = [];
    foreach (array_values($documents) as $index => $document) {
        $details[] = [
            'noItem' => $index + 1,
            'tipoDoc' => (string) $document['tipo_dte'],
            'codigoGeneracion' => (string) $document['codigo_generacion'],
        ];
    }

    $event = [
        'identificacion' => [
            'version' => 4,
            'ambiente' => $tenant['mh_mode'] === 'PRODUCCION' ? '01' : '00',
            'codigoGeneracion' => (string) $header['codigo_generacion'],
            'fTransmision' => (string) $header['f_transmision'],
            'hTransmision' => (string) $header['h_transmision'],
        ],
        'emisor' => [
            'nit' => preg_replace('/\D/', '', (string) $tenant['nit']),
            'nombre' => (string) $tenant['razon_social'],
            'nombreResponsable' => (string) $responsible['nombre'],
            'tipoDocResponsable' => (string) $responsible['tipo_documento'],
            'numeroDocResponsable' => (string) $responsible['numero_documento'],
            'tipoEstablecimiento' => (string) $responsible['tipo_establecimiento'],
            'codEstableMH' => substr((string) $tenant['establecimiento'], 0, 4),
            'codPuntoVentaMH' => substr((string) $tenant['establecimiento'], 4, 4),
            'telefono' => preg_replace('/\D/', '', (string) $tenant['telefono']),
            'correo' => (string) $tenant['email'],
        ],
        'detalleDTE' => $details,
        'motivo' => [
            'fInicio' => (string) $header['f_inicio'],
            'fFin' => (string) $header['f_fin'],
            'hInicio' => (string) $header['h_inicio'],
            'hFin' => (string) $header['h_fin'],
            'tipoContingencia' => (int) $header['tipo_contingencia'],
            'motivoContingencia' => $header['motivo_contingencia'] === null || $header['motivo_contingencia'] === ''
                ? null
                : (string) $header['motivo_contingencia'],
        ],
    ];

    $errors = validateJsonSchema($event, contingencyEventSchema());
    if ($errors !== []) {
        throw new RuntimeException('El evento de contingencia no superó la validación del esquema oficial: ' . implode(' ', array_slice($errors, 0, 6)));
    }
    return $event;
}

function buildInvalidationEvent(array $document, array $dte, array $tenant, array $input, array $user): array
{
    $eventDate = $document['tipo_dte'] === '03'
        ? $document['fecha_emision']
        : (new DateTimeImmutable('now', new DateTimeZone($tenant['timezone'])))->format('Y-m-d');
    $responsible = [
        'nombreResponsable' => mb_substr($user['nombre'], 0, 100),
        'tipDocResponsable' => $input['tipo_documento_responsable'],
        'numDocResponsable' => $input['numero_documento_responsable'],
        'nombreSolicita' => mb_substr($user['nombre'], 0, 100),
        'tipDocSolicita' => $input['tipo_documento_responsable'],
        'numDocSolicita' => $input['numero_documento_responsable'],
    ];
    $event = [
        'identificacion' => [
            'version' => 3,
            'ambiente' => $tenant['mh_mode'] === 'PRODUCCION' ? '01' : '00',
            'codigoGeneracion' => generateDteUuid(),
            'fecEmi' => $eventDate,
            'horEmi' => (new DateTimeImmutable('now', new DateTimeZone($tenant['timezone'])))->format('H:i:s'),
            'fusion' => null,
        ],
        'emisor' => [
            'nit' => $dte['emisor']['nit'], 'nombre' => $tenant['razon_social'],
            'codEstableMH' => substr($tenant['establecimiento'], 0, 4),
            'codEstable' => substr($tenant['establecimiento'], 0, 4),
            'codPuntoVentaMH' => substr($tenant['establecimiento'], 4, 4),
            'codPuntoVenta' => substr($tenant['establecimiento'], 4, 4),
            'telefono' => $dte['emisor']['telefono'], 'correo' => $tenant['email'],
        ],
        'documento' => [
            'tipoDte' => $document['tipo_dte'],
            'codigoGeneracion' => $document['codigo_generacion'],
            'selloRecibido' => $document['sello_recepcion'],
            'numeroControl' => $document['numero_control'],
            'fecEmi' => $document['fecha_emision'],
            'codigoGeneracionR' => null,
            'tipoDocumento' => $document['tipo_dte'] === '03'
                ? '36'
                : ($dte['receptor']['tipoDocumento'] ?? null),
            'numDocumento' => $document['tipo_dte'] === '03'
                ? $dte['receptor']['nit']
                : ($dte['receptor']['numDocumento'] ?? null),
            'nombre' => $dte['receptor']['nombre'],
            'telefono' => $dte['receptor']['telefono'],
            'correo' => $dte['receptor']['correo'],
        ],
        'motivo' => [
            'tipoAnulacion' => (int) $input['tipo_anulacion'],
            'motivoAnulacion' => $input['motivo'],
        ] + $responsible,
    ];
    $schema = json_decode(file_get_contents(dirname(__DIR__) . '/documentacion-mh/invalidacion-schema-v3.json'), true);
    $errors = validateJsonSchema($event, $schema);
    if ($errors !== []) throw new RuntimeException('El evento de invalidación no es válido: ' . implode(' ', array_slice($errors, 0, 6)));
    return $event;
}

function generateDteUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4)));
}
