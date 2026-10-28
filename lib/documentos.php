<?php

declare(strict_types=1);

require_once __DIR__ . '/dte-builder.php';
require_once __DIR__ . '/mh-connector.php';
require_once __DIR__ . '/contabilidad.php';
require_once __DIR__ . '/inventario.php';

function documentStatuses(): array
{
    return [
        'BORRADOR' => 'Borrador',
        'EMITIDA' => 'Emitida',
        'PROCESADA' => 'Procesada',
        'RECHAZADA' => 'Rechazada',
        'INVALIDADA' => 'Invalidada',
    ];
}

/**
 * Modos de facturación por empresa (migración 016).
 *
 * INTERNO: comprobante interno no fiscal, sin DTE, sello ni transmisión.
 * SIMULACION: comportamiento histórico con DTE simulados.
 * PRODUCCION: reservado para la integración real con Hacienda.
 */
function documentBillingModes(): array
{
    return [
        'INTERNO' => 'Interno (comprobante no fiscal)',
        'SIMULACION' => 'Simulación (DTE de prueba)',
        'PRODUCCION' => 'Producción (no disponible)',
    ];
}

function documentIsInternalType(string $type): bool
{
    return $type === 'IN';
}

/** Lee el modo de facturación de la empresa; SIMULACION si aún no existe la columna. */
function tenantBillingMode(PDO $pdo, int $tenantId): string
{
    try {
        $statement = $pdo->prepare('SELECT modo_facturacion FROM tenant_config WHERE tenant_id = :tenant_id');
        $statement->execute(['tenant_id' => $tenantId]);
        $mode = $statement->fetchColumn();
    } catch (PDOException) {
        return 'SIMULACION';
    }
    return is_string($mode) && array_key_exists($mode, documentBillingModes()) ? $mode : 'SIMULACION';
}

function documentFormOptions(int $tenantId): array
{
    $pdo = db();
    $queries = [
        'clientes' => 'SELECT id, tipo_documento, numero_documento, nombre, nombre_comercial, email
                       FROM clientes WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY nombre, id',
        'productos' => 'SELECT p.id, p.codigo, p.codigo_barras, p.descripcion, p.tipo, p.precio_unitario,
                               p.tratamiento_iva, p.unidad_medida_id, u.codigo AS unidad_codigo,
                               u.nombre AS unidad_nombre
                        FROM productos p
                        INNER JOIN mh_unidades_medida u
                            ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
                        WHERE p.tenant_id = :tenant_id AND p.activo = 1 AND u.activo = 1
                        ORDER BY p.descripcion, p.codigo, p.id',
        'condiciones' => "SELECT id, codigo, nombre FROM mh_condiciones_operacion
                          WHERE tenant_id = :tenant_id AND activo = 1 AND codigo IN ('1', '2')
                          ORDER BY orden, id",
        'formas_pago' => 'SELECT id, codigo, nombre FROM mh_formas_pago
                          WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY orden, id',
        'documentos_relacionados' => "SELECT d.id, d.tipo_dte, d.numero_control, d.fecha_emision,
                                             d.cliente_id, d.total_pagar
                                      FROM documentos d
                                      WHERE d.tenant_id = :tenant_id AND d.estado = 'PROCESADA'
                                        AND d.tipo_dte IN ('01', '03')
                                      ORDER BY d.fecha_emision DESC, d.id DESC LIMIT 500",
    ];
    $options = [];
    foreach ($queries as $key => $sql) {
        $statement = $pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId]);
        $options[$key] = $statement->fetchAll();
    }
    $statement = $pdo->prepare(
        'SELECT c.distrito_emisor, c.establecimiento, c.mh_mode, c.modo_facturacion, t.nombre_comercial
         FROM tenant_config c INNER JOIN tenants t ON t.id = c.tenant_id
         WHERE c.tenant_id = :tenant_id'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    $options['empresa'] = $statement->fetch();
    $options['modo_facturacion'] = tenantBillingMode($pdo, $tenantId);
    $options['modos_facturacion'] = documentBillingModes();
    $options['tipos_documento'] = documentAllTypeDefinitions();
    $options['estados'] = documentStatuses();
    $options['tratamientos_iva'] = productTaxTreatments();
    return $options;
}

function normalizeDocumentData(array $input): array
{
    $text = static function (string $key, int $max) use ($input): string {
        $value = $input[$key] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    };
    $items = [];
    foreach (($input['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        $items[] = [
            'producto_id' => $item['producto_id'] ?? null,
            'cantidad' => is_string($item['cantidad'] ?? null) ? trim($item['cantidad']) : '',
            'precio_unitario' => is_string($item['precio_unitario'] ?? null) ? trim($item['precio_unitario']) : '',
            'descuento' => is_string($item['descuento'] ?? null) ? trim($item['descuento']) : '',
        ];
    }
    return [
        'id' => $input['id'] ?? null,
        'tipo_dte' => $text('tipo_dte', 2),
        'cliente_id' => $input['cliente_id'] ?? null,
        'documento_relacionado_id' => $input['documento_relacionado_id'] ?? null,
        'fecha_emision' => $text('fecha_emision', 10),
        'condicion_operacion_id' => $input['condicion_operacion_id'] ?? null,
        'forma_pago_id' => $input['forma_pago_id'] ?? null,
        'dias_credito' => $text('dias_credito', 4),
        'emisor_distrito' => $text('emisor_distrito', 2),
        'receptor_distrito' => $text('receptor_distrito', 2),
        'aplicar_iva_retenido' => isset($input['aplicar_iva_retenido'])
            && ($input['aplicar_iva_retenido'] === '1' || $input['aplicar_iva_retenido'] === 1) ? 1 : 0,
        'observaciones' => $text('observaciones', 3000),
        'export_cod_pais' => strtoupper($text('export_cod_pais', 2)),
        'export_nombre_pais' => $text('export_nombre_pais', 50),
        'export_tipo_persona' => $input['export_tipo_persona'] ?? null,
        'export_complemento' => $text('export_complemento', 200),
        'export_tipo_item' => $input['export_tipo_item'] ?? null,
        'export_incoterms' => strtoupper($text('export_incoterms', 2)),
        'export_desc_incoterms' => $text('export_desc_incoterms', 150),
        'export_flete' => $text('export_flete', 20),
        'export_seguro' => $text('export_seguro', 20),
        'export_no_onerosas' => $text('export_no_onerosas', 20),
        'rete_renta' => $text('rete_renta', 20),
        'items' => $items,
    ];
}

function validateDocumentData(PDO $pdo, int $tenantId, array $data): array
{
    $errors = [];
    $definition = documentAllTypeDefinitions()[$data['tipo_dte']] ?? null;
    if (!$definition || !$definition['disponible']) {
        $errors['tipo_dte'] = 'Seleccione un tipo de DTE habilitado.';
    }
    $billingMode = tenantBillingMode($pdo, $tenantId);
    $internalType = documentIsInternalType($data['tipo_dte']);
    if ($billingMode === 'INTERNO' && !$internalType) {
        $errors['tipo_dte'] = 'La empresa está en modo interno: solo puede emitir comprobantes internos.';
    } elseif ($billingMode !== 'INTERNO' && $internalType) {
        $errors['tipo_dte'] = 'El comprobante interno solo está disponible para empresas en modo interno.';
    }
    if ($internalType) {
        // Un comprobante interno no liquida retenciones fiscales.
        $data['aplicar_iva_retenido'] = 0;
    }

    $clientId = filter_var($data['cliente_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $client = null;
    if ($clientId === false || $clientId === null) {
        $errors['cliente_id'] = 'Seleccione un cliente.';
    } else {
        $statement = $pdo->prepare(
            'SELECT c.*, d.codigo AS departamento_codigo, m.codigo AS municipio_codigo
             FROM clientes c
             INNER JOIN mh_departamentos d ON d.tenant_id = c.tenant_id AND d.id = c.departamento_id
             INNER JOIN mh_municipios m ON m.tenant_id = c.tenant_id AND m.id = c.municipio_id
             WHERE c.tenant_id = :tenant_id AND c.id = :id AND c.activo = 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $clientId]);
        $client = $statement->fetch();
        if ($client === false) {
            $errors['cliente_id'] = 'El cliente no existe para esta empresa o está inactivo.';
        } elseif ($data['tipo_dte'] === '03' && $client['tipo_documento'] !== 'NIT') {
            $errors['cliente_id'] = 'Crédito Fiscal requiere un cliente identificado con NIT.';
        } elseif ($data['tipo_dte'] === '03' && empty($client['email'])) {
            $errors['cliente_id'] = 'Crédito Fiscal requiere que el cliente tenga correo electrónico.';
        } elseif (in_array($data['tipo_dte'], ['05', '06', '11', '14'], true) && $client['tipo_documento'] === 'NINGUNO') {
            $errors['cliente_id'] = 'Este tipo de DTE requiere un cliente identificado con NIT o DUI.';
        }
    }

    $related = null;
    $data['related_type'] = null;
    if (in_array($data['tipo_dte'], ['05', '06'], true)) {
        $relatedId = filter_var($data['documento_relacionado_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($relatedId === false || $relatedId === null) {
            $errors['documento_relacionado_id'] = 'Seleccione el documento relacionado de la nota.';
        } elseif ($clientId !== false && $clientId !== null) {
            $statement = $pdo->prepare(
                'SELECT id, tipo_dte, numero_control, fecha_emision, cliente_id, estado
                 FROM documentos WHERE tenant_id = :tenant_id AND id = :id'
            );
            $statement->execute(['tenant_id' => $tenantId, 'id' => (int) $relatedId]);
            $related = $statement->fetch() ?: null;
            $documentId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($related === null) {
                $errors['documento_relacionado_id'] = 'El documento relacionado no existe.';
            } elseif ($documentId !== false && $documentId !== null && (int) $documentId === (int) $relatedId) {
                $errors['documento_relacionado_id'] = 'Una nota no puede relacionarse consigo misma.';
                $related = null;
            } elseif (!in_array($related['tipo_dte'], ['01', '03'], true)) {
                $errors['documento_relacionado_id'] = 'El documento relacionado debe ser Factura o Crédito Fiscal.';
                $related = null;
            } elseif ((int) $related['cliente_id'] !== (int) $clientId) {
                $errors['documento_relacionado_id'] = 'El documento relacionado debe ser del mismo cliente.';
                $related = null;
            } elseif ($related['estado'] !== 'PROCESADA' || empty($related['numero_control'])) {
                $errors['documento_relacionado_id'] = 'El documento relacionado debe estar procesado.';
                $related = null;
            } else {
                $data['related_type'] = $related['tipo_dte'];
            }
        }
        $data['documento_relacionado_id'] = $related === null ? null : (int) $related['id'];
    } else {
        $data['documento_relacionado_id'] = null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $data['fecha_emision']);
    if ($date === false || $date->format('Y-m-d') !== $data['fecha_emision']) {
        $errors['fecha_emision'] = 'Ingrese una fecha de emisión válida.';
    }

    $condition = documentCatalogRow($pdo, $tenantId, 'mh_condiciones_operacion', $data['condicion_operacion_id']);
    if ($condition === null || !in_array($condition['codigo'], ['1', '2'], true)) {
        $errors['condicion_operacion_id'] = 'Seleccione contado o crédito.';
    }
    $payment = documentCatalogRow($pdo, $tenantId, 'mh_formas_pago', $data['forma_pago_id']);
    if ($payment === null) $errors['forma_pago_id'] = 'Seleccione una forma de pago válida.';

    if ($condition !== null && $condition['codigo'] === '2') {
        $days = filter_var($data['dias_credito'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3650]]);
        if ($days === false || $days === null) $errors['dias_credito'] = 'Indique un plazo entre 1 y 3650 días.';
        $data['dias_credito'] = $days;
    } else {
        $data['dias_credito'] = null;
    }

    $numericAmount = static function (mixed $value): ?float {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') return 0.0;
        if (preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', $value) !== 1) return null;
        return (float) $value;
    };

    if ($data['tipo_dte'] === '11') {
        if (!preg_match('/^[A-Z]{2}$/', $data['export_cod_pais'])) {
            $errors['export_cod_pais'] = 'Ingrese el código de país de dos letras.';
        }
        $pais = trim((string) $data['export_nombre_pais']);
        if (mb_strlen($pais) < 3 || mb_strlen($pais) > 50) {
            $errors['export_nombre_pais'] = 'Ingrese el nombre del país (3 a 50 caracteres).';
        }
        $data['export_nombre_pais'] = $pais;
        $persona = filter_var($data['export_tipo_persona'], FILTER_VALIDATE_INT);
        if (!in_array($persona, [1, 2], true)) {
            $errors['export_tipo_persona'] = 'Seleccione si el cliente es persona jurídica o natural.';
        }
        $data['export_tipo_persona'] = is_int($persona) ? $persona : null;
        $item = filter_var($data['export_tipo_item'], FILTER_VALIDATE_INT);
        if (!in_array($item, [1, 2], true)) {
            $errors['export_tipo_item'] = 'Seleccione si la exportación es de bienes o servicios.';
        }
        $data['export_tipo_item'] = is_int($item) ? $item : null;
        $complemento = trim((string) $data['export_complemento']);
        if ($complemento === '' || mb_strlen($complemento) > 200) {
            $errors['export_complemento'] = 'Ingrese el complemento de la dirección del receptor (máximo 200).';
        }
        $data['export_complemento'] = $complemento;
        $incoterms = trim((string) $data['export_incoterms']);
        if ($incoterms !== '' && preg_match('/^[A-Z0-9]{1,2}$/', $incoterms) !== 1) {
            $errors['export_incoterms'] = 'El código de incoterm debe tener 1 o 2 caracteres.';
        }
        $data['export_incoterms'] = $incoterms === '' ? null : $incoterms;
        $incotermDescription = trim((string) $data['export_desc_incoterms']);
        if (mb_strlen($incotermDescription) > 150) {
            $errors['export_desc_incoterms'] = 'La descripción del incoterm no puede superar 150 caracteres.';
        }
        $data['export_desc_incoterms'] = $incotermDescription === '' ? null : $incotermDescription;
        foreach (['export_flete' => 'flete', 'export_seguro' => 'seguro', 'export_no_onerosas' => 'no onerosas'] as $field => $label) {
            $amount = $numericAmount($data[$field]);
            if ($amount === null) {
                $errors[$field] = 'Ingrese un monto válido para ' . $label . '.';
            }
            $data[$field] = $amount ?? 0.0;
        }
    } else {
        $data['export_cod_pais'] = null;
        $data['export_nombre_pais'] = null;
        $data['export_tipo_persona'] = null;
        $data['export_complemento'] = null;
        $data['export_tipo_item'] = null;
        $data['export_incoterms'] = null;
        $data['export_desc_incoterms'] = null;
        $data['export_flete'] = 0.0;
        $data['export_seguro'] = 0.0;
        $data['export_no_onerosas'] = 0.0;
    }

    if ($data['tipo_dte'] === '14') {
        $rete = $numericAmount($data['rete_renta']);
        if ($rete === null) {
            $errors['rete_renta'] = 'Ingrese un monto válido de retención de renta.';
        }
        $data['rete_renta'] = $rete ?? 0.0;
        $data['aplicar_iva_retenido'] = 0;
    } else {
        $data['rete_renta'] = 0.0;
        if ($data['tipo_dte'] === '11') {
            $data['aplicar_iva_retenido'] = 0;
        }
    }

    foreach (['emisor_distrito', 'receptor_distrito'] as $field) {
        if (!preg_match('/^[0-9]{2}$/', $data[$field])) $errors[$field] = 'Ingrese el código de distrito de dos dígitos.';
    }
    if (count($data['items']) < 1 || count($data['items']) > 2000) {
        $errors['items'] = 'Agregue entre 1 y 2000 productos o servicios.';
    }

    $productStatement = $pdo->prepare(
        'SELECT p.*, u.codigo AS unidad_codigo
         FROM productos p INNER JOIN mh_unidades_medida u
            ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
         WHERE p.tenant_id = :tenant_id AND p.id = :id AND p.activo = 1 AND u.activo = 1'
    );
    $items = [];
    foreach ($data['items'] as $index => $item) {
        $number = $index + 1;
        $productId = filter_var($item['producto_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || $productId === null) {
            $errors['items'] = "Seleccione el producto o servicio de la línea {$number}.";
            continue;
        }
        $productStatement->execute(['tenant_id' => $tenantId, 'id' => $productId]);
        $product = $productStatement->fetch();
        if ($product === false) {
            $errors['items'] = "El producto de la línea {$number} no existe o está inactivo.";
            continue;
        }
        foreach (['cantidad', 'precio_unitario', 'descuento'] as $field) {
            if (!preg_match('/^[0-9]+(?:\.[0-9]{1,8})?$/', $item[$field])) {
                $errors['items'] = "Revise cantidad, precio y descuento de la línea {$number}; use punto decimal y hasta 8 decimales.";
                continue 2;
            }
        }
        $quantity = (float) $item['cantidad'];
        $price = (float) $item['precio_unitario'];
        $discount = (float) $item['descuento'];
        if ($quantity <= 0 || $quantity >= 100000000000 || $price < 0 || $price >= 100000000000
            || $discount < 0 || $discount >= 100000000000 || $discount > $quantity * $price) {
            $errors['items'] = "La línea {$number} tiene valores fuera de rango o un descuento mayor al importe.";
            continue;
        }
        $items[] = [
            'producto_id' => (int) $product['id'],
            'codigo' => $product['codigo'],
            'descripcion' => $product['descripcion'],
            'tipo' => $product['tipo'],
            'unidad_medida_id' => (int) $product['unidad_medida_id'],
            'unidad_codigo' => $product['unidad_codigo'],
            'cantidad' => $item['cantidad'],
            'precio_unitario' => $item['precio_unitario'],
            'descuento' => $item['descuento'],
            'tratamiento_iva' => $product['tratamiento_iva'],
        ];
    }
    if ($errors !== []) return [$data, [], [], null, null, null, $errors];

    try {
        $calculationOptions = [];
        if (in_array($data['tipo_dte'], ['05', '06'], true) && !empty($data['related_type'])) {
            $calculationOptions['related_type'] = $data['related_type'];
        }
        if ($data['tipo_dte'] === '11') {
            $calculationOptions['flete'] = (float) $data['export_flete'];
            $calculationOptions['seguro'] = (float) $data['export_seguro'];
            $calculationOptions['no_onerosas'] = (float) $data['export_no_onerosas'];
        }
        if ($data['tipo_dte'] === '14') {
            $calculationOptions['rete_renta'] = (float) $data['rete_renta'];
        }
        [$items, $summary] = calculateDocumentTotals(
            $data['tipo_dte'],
            $items,
            (bool) $data['aplicar_iva_retenido'],
            $calculationOptions
        );
        if ($summary['totalPagar'] >= 100000000000) $errors['items'] = 'El total supera el límite permitido por el esquema MH.';
    } catch (InvalidArgumentException $exception) {
        $errors['items'] = $exception->getMessage();
    }
    $data['cliente_id'] = $clientId;
    $data['condicion_operacion_id'] = (int) $condition['id'];
    $data['forma_pago_id'] = (int) $payment['id'];
    $data['observaciones'] = $data['observaciones'] === '' ? null : $data['observaciones'];
    return [$data, $items, $summary, $client, $condition, $payment, $errors];
}

function documentCatalogRow(PDO $pdo, int $tenantId, string $table, mixed $id): ?array
{
    if (!in_array($table, ['mh_condiciones_operacion', 'mh_formas_pago'], true)) return null;
    $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) return null;
    $statement = $pdo->prepare("SELECT id, codigo, nombre FROM {$table} WHERE tenant_id = :tenant_id AND id = :id AND activo = 1");
    $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function documentRecord(PDO $pdo, int $tenantId, int $id, bool $forUpdate = false): ?array
{
    $statement = $pdo->prepare(
        'SELECT d.*, c.nombre AS cliente_nombre, c.numero_documento AS cliente_documento,
                c.tipo_documento AS cliente_tipo_documento, c.email AS cliente_email,
                co.codigo AS condicion_codigo,
                co.nombre AS condicion_nombre, fp.codigo AS pago_codigo, fp.nombre AS pago_nombre
         FROM documentos d
         INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
         INNER JOIN mh_condiciones_operacion co ON co.tenant_id = d.tenant_id AND co.id = d.condicion_operacion_id
         INNER JOIN mh_formas_pago fp ON fp.tenant_id = d.tenant_id AND fp.id = d.forma_pago_id
         WHERE d.tenant_id = :tenant_id AND d.id = :id' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
    $document = $statement->fetch();
    return $document === false ? null : $document;
}

function documentItemRecords(PDO $pdo, int $tenantId, int $documentId): array
{
    $statement = $pdo->prepare(
        'SELECT i.*, u.nombre AS unidad_nombre FROM documento_items i
         INNER JOIN mh_unidades_medida u ON u.tenant_id = i.tenant_id AND u.id = i.unidad_medida_id
         WHERE i.tenant_id = :tenant_id AND i.documento_id = :documento_id ORDER BY i.num_item'
    );
    $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId]);
    return $statement->fetchAll();
}

function insertDocumentItems(PDO $pdo, int $tenantId, int $documentId, array $items): void
{
    $statement = $pdo->prepare(
        'INSERT INTO documento_items (
            tenant_id, documento_id, producto_id, num_item, codigo, descripcion, tipo,
            unidad_medida_id, unidad_codigo, cantidad, precio_unitario, descuento,
            tratamiento_iva, venta_gravada, venta_exenta, venta_no_suj, iva_item, compra
        ) VALUES (
            :tenant_id, :documento_id, :producto_id, :num_item, :codigo, :descripcion, :tipo,
            :unidad_medida_id, :unidad_codigo, :cantidad, :precio_unitario, :descuento,
            :tratamiento_iva, :venta_gravada, :venta_exenta, :venta_no_suj, :iva_item, :compra
        )'
    );
    foreach ($items as $item) {
        $statement->execute([
            'tenant_id' => $tenantId, 'documento_id' => $documentId,
            'producto_id' => $item['producto_id'], 'num_item' => $item['num_item'],
            'codigo' => $item['codigo'], 'descripcion' => $item['descripcion'], 'tipo' => $item['tipo'],
            'unidad_medida_id' => $item['unidad_medida_id'], 'unidad_codigo' => $item['unidad_codigo'],
            'cantidad' => $item['cantidad'], 'precio_unitario' => $item['precio_unitario'],
            'descuento' => $item['descuento'], 'tratamiento_iva' => $item['tratamiento_iva'],
            'venta_gravada' => $item['venta_gravada'], 'venta_exenta' => $item['venta_exenta'],
            'venta_no_suj' => $item['venta_no_suj'], 'iva_item' => $item['iva_item'],
            'compra' => $item['compra'] ?? null,
        ]);
    }
}

function documentTotalParams(array $summary): array
{
    $defaults = [
        'total_gravada' => 0.0, 'total_exenta' => 0.0, 'total_no_suj' => 0.0,
        'total_descuento' => 0.0, 'total_iva' => 0.0, 'iva_rete1' => 0.0,
        'rete_renta' => 0.0, 'total_pagar' => 0.0,
    ];
    if (isset($summary['_db']) && is_array($summary['_db'])) {
        return array_merge($defaults, $summary['_db']);
    }
    return array_merge($defaults, [
        'total_gravada' => $summary['totalGravada'] ?? 0,
        'total_exenta' => $summary['totalExenta'] ?? 0,
        'total_no_suj' => $summary['totalNoSuj'] ?? 0,
        'total_descuento' => $summary['totalDescu'] ?? 0,
        'total_iva' => $summary['totalIva'] ?? ($summary['tributos'][0]['valor'] ?? 0),
        'iva_rete1' => $summary['ivaRete'] ?? 0,
        'total_pagar' => $summary['totalPagar'] ?? 0,
    ]);
}

function saveDocument(int $tenantId, int $userId, array $input): int
{
    $pdo = db();
    [$data, $items, $summary, , , , $errors] = validateDocumentData($pdo, $tenantId, normalizeDocumentData($input));
    if ($errors !== []) throw new DocumentValidationException('Revise los campos indicados.', $errors);
    $id = filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $isEdit = $id !== false && $id !== null;
    $documentParams = [
        'documento_relacionado_id' => $data['documento_relacionado_id'],
        'export_cod_pais' => $data['export_cod_pais'],
        'export_nombre_pais' => $data['export_nombre_pais'],
        'export_tipo_persona' => $data['export_tipo_persona'],
        'export_complemento' => $data['export_complemento'],
        'export_tipo_item' => $data['export_tipo_item'],
        'export_incoterms' => $data['export_incoterms'],
        'export_desc_incoterms' => $data['export_desc_incoterms'],
        'export_flete' => $data['export_flete'],
        'export_seguro' => $data['export_seguro'],
        'export_no_onerosas' => $data['export_no_onerosas'],
        'rete_renta' => $data['rete_renta'],
    ];
    $pdo->beginTransaction();
    try {
        if ($isEdit) {
            $existing = documentRecord($pdo, $tenantId, $id, true);
            if ($existing === null) throw new RuntimeException('El documento no existe.');
            if ($existing['estado'] !== 'BORRADOR') throw new RuntimeException('Solo se pueden editar documentos en borrador.');
            $statement = $pdo->prepare(
                'UPDATE documentos SET tipo_dte = :tipo_dte, cliente_id = :cliente_id,
                    documento_relacionado_id = :documento_relacionado_id,
                    export_cod_pais = :export_cod_pais, export_nombre_pais = :export_nombre_pais,
                    export_tipo_persona = :export_tipo_persona, export_complemento = :export_complemento,
                    export_tipo_item = :export_tipo_item, export_incoterms = :export_incoterms,
                    export_desc_incoterms = :export_desc_incoterms, export_flete = :export_flete,
                    export_seguro = :export_seguro, export_no_onerosas = :export_no_onerosas,
                    fecha_emision = :fecha_emision, condicion_operacion_id = :condicion_operacion_id,
                    forma_pago_id = :forma_pago_id, dias_credito = :dias_credito,
                    emisor_distrito = :emisor_distrito, receptor_distrito = :receptor_distrito,
                    aplicar_iva_retenido = :aplicar_iva_retenido, observaciones = :observaciones,
                    total_gravada = :total_gravada, total_exenta = :total_exenta, total_no_suj = :total_no_suj,
                    total_descuento = :total_descuento, total_iva = :total_iva,
                    iva_rete1 = :iva_rete1, rete_renta = :rete_renta, total_pagar = :total_pagar
                 WHERE tenant_id = :tenant_id AND id = :id AND estado = \'BORRADOR\''
            );
            $statement->execute([
                'tenant_id' => $tenantId, 'id' => $id, 'tipo_dte' => $data['tipo_dte'],
                'cliente_id' => $data['cliente_id'], 'fecha_emision' => $data['fecha_emision'],
                'condicion_operacion_id' => $data['condicion_operacion_id'], 'forma_pago_id' => $data['forma_pago_id'],
                'dias_credito' => $data['dias_credito'], 'emisor_distrito' => $data['emisor_distrito'],
                'receptor_distrito' => $data['receptor_distrito'], 'aplicar_iva_retenido' => $data['aplicar_iva_retenido'],
                'observaciones' => $data['observaciones'],
            ] + $documentParams + documentTotalParams($summary));
            $delete = $pdo->prepare('DELETE FROM documento_items WHERE tenant_id = :tenant_id AND documento_id = :documento_id');
            $delete->execute(['tenant_id' => $tenantId, 'documento_id' => $id]);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO documentos (
                    tenant_id, tipo_dte, cliente_id, documento_relacionado_id,
                    export_cod_pais, export_nombre_pais, export_tipo_persona, export_complemento,
                    export_tipo_item, export_incoterms, export_desc_incoterms,
                    export_flete, export_seguro, export_no_onerosas,
                    fecha_emision, condicion_operacion_id, forma_pago_id,
                    dias_credito, emisor_distrito, receptor_distrito, aplicar_iva_retenido, observaciones,
                    total_gravada, total_exenta, total_no_suj, total_descuento, total_iva,
                    iva_rete1, rete_renta, total_pagar, created_by
                ) VALUES (
                    :tenant_id, :tipo_dte, :cliente_id, :documento_relacionado_id,
                    :export_cod_pais, :export_nombre_pais, :export_tipo_persona, :export_complemento,
                    :export_tipo_item, :export_incoterms, :export_desc_incoterms,
                    :export_flete, :export_seguro, :export_no_onerosas,
                    :fecha_emision, :condicion_operacion_id, :forma_pago_id,
                    :dias_credito, :emisor_distrito, :receptor_distrito, :aplicar_iva_retenido, :observaciones,
                    :total_gravada, :total_exenta, :total_no_suj, :total_descuento, :total_iva,
                    :iva_rete1, :rete_renta, :total_pagar, :created_by
                )'
            );
            $statement->execute([
                'tenant_id' => $tenantId, 'tipo_dte' => $data['tipo_dte'], 'cliente_id' => $data['cliente_id'],
                'fecha_emision' => $data['fecha_emision'], 'condicion_operacion_id' => $data['condicion_operacion_id'],
                'forma_pago_id' => $data['forma_pago_id'], 'dias_credito' => $data['dias_credito'],
                'emisor_distrito' => $data['emisor_distrito'], 'receptor_distrito' => $data['receptor_distrito'],
                'aplicar_iva_retenido' => $data['aplicar_iva_retenido'], 'observaciones' => $data['observaciones'],
                'created_by' => $userId,
            ] + $documentParams + documentTotalParams($summary));
            $id = (int) $pdo->lastInsertId();
        }
        insertDocumentItems($pdo, $tenantId, $id, $items);
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

final class DocumentValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }
}

function deleteDraftDocument(int $tenantId, int $id): void
{
    $pdo = db();
    $statement = $pdo->prepare("DELETE FROM documentos WHERE tenant_id = :tenant_id AND id = :id AND estado = 'BORRADOR'");
    $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
    if ($statement->rowCount() === 0) throw new RuntimeException('Solo puede eliminar un borrador existente de esta empresa.');
}

function reserveDocumentNumber(PDO $pdo, int $tenantId, string $type, string $establishment): string
{
    $statement = $pdo->prepare(
        'SELECT id, prefijo, numero_inicial, numero_actual, activo FROM document_sequences
         WHERE tenant_id = :tenant_id AND tipo_dte = :tipo_dte AND establecimiento = :establecimiento
         FOR UPDATE'
    );
    $statement->execute(['tenant_id' => $tenantId, 'tipo_dte' => $type, 'establecimiento' => $establishment]);
    $sequence = $statement->fetch();
    if ($sequence === false) {
        $defaultPrefix = $type === 'IN' ? 'INT' : 'DTE';
        $insert = $pdo->prepare(
            "INSERT INTO document_sequences (tenant_id, tipo_dte, establecimiento, prefijo, numero_inicial, numero_actual)
             VALUES (:tenant_id, :tipo_dte, :establecimiento, :prefijo, 1, 0)"
        );
        $insert->execute([
            'tenant_id' => $tenantId, 'tipo_dte' => $type,
            'establecimiento' => $establishment, 'prefijo' => $defaultPrefix,
        ]);
        $statement->execute(['tenant_id' => $tenantId, 'tipo_dte' => $type, 'establecimiento' => $establishment]);
        $sequence = $statement->fetch();
    }
    if ((int) ($sequence['activo'] ?? 1) !== 1) {
        throw new RuntimeException(
            'La serie del tipo ' . $type . ' está inactiva. Actívela en Configuración antes de emitir.'
        );
    }
    $next = max((int) $sequence['numero_inicial'], (int) $sequence['numero_actual'] + 1);
    if ($next > 999999999999999) throw new RuntimeException('El correlativo llegó al máximo de 15 dígitos.');
    $update = $pdo->prepare('UPDATE document_sequences SET numero_actual = :actual WHERE id = :id');
    $update->execute(['actual' => $next, 'id' => $sequence['id']]);
    $prefix = trim((string) $sequence['prefijo']);
    if ($type === 'IN') {
        // Serie interna independiente y legible: INT-000001.
        return ($prefix !== '' ? $prefix : 'INT') . '-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
    return ($prefix !== '' ? $prefix . '-' : '') . $type . '-' . $establishment . '-' . str_pad((string) $next, 15, '0', STR_PAD_LEFT);
}

function tenantDocumentContext(PDO $pdo, int $tenantId): array
{
    $statement = $pdo->prepare(
        'SELECT t.*, c.mh_mode, c.modo_facturacion, c.timezone, c.moneda, c.distrito_emisor, c.departamento_emisor,
                c.municipio_emisor, c.establecimiento
         FROM tenants t INNER JOIN tenant_config c ON c.tenant_id = t.id WHERE t.id = :tenant_id'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    $tenant = $statement->fetch();
    if ($tenant === false) throw new RuntimeException('La empresa no está configurada.');
    if (!preg_match('/^[A-Z0-9]{8}$/', $tenant['establecimiento'])) {
        throw new RuntimeException('El establecimiento y punto de venta deben configurarse con 8 caracteres.');
    }
    return $tenant;
}

function emissionClient(PDO $pdo, int $tenantId, int $clientId, string $type): array
{
    $statement = $pdo->prepare(
        'SELECT c.*, d.codigo AS departamento_codigo, m.codigo AS municipio_codigo
         FROM clientes c INNER JOIN mh_departamentos d ON d.tenant_id = c.tenant_id AND d.id = c.departamento_id
         INNER JOIN mh_municipios m ON m.tenant_id = c.tenant_id AND m.id = c.municipio_id
         WHERE c.tenant_id = :tenant_id AND c.id = :id AND c.activo = 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $clientId]);
    $client = $statement->fetch();
    if ($client === false) throw new RuntimeException('El cliente está inactivo o ya no existe.');
    if ($type === '03' && ($client['tipo_documento'] !== 'NIT' || empty($client['email']))) {
        throw new RuntimeException('Para Crédito Fiscal el cliente debe tener NIT y correo electrónico.');
    }
    return $client;
}

function documentRelatedRecord(PDO $pdo, int $tenantId, ?int $documentId): ?array
{
    if ($documentId === null || $documentId <= 0) return null;
    $statement = $pdo->prepare(
        'SELECT id, tipo_dte, numero_control, fecha_emision, codigo_generacion, cliente_id, estado
         FROM documentos WHERE tenant_id = :tenant_id AND id = :id'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $documentId]);
    $related = $statement->fetch();
    return $related === false ? null : $related;
}

function emitDocument(int $tenantId, array $user, int $id, array $contingency = []): array
{
    $pdo = db();
    $isContingency = ($contingency['enabled'] ?? false) === true;
    $contingencyType = null;
    $contingencyReason = null;
    if ($isContingency) {
        $typeValue = filter_var($contingency['tipo_contingencia'] ?? null, FILTER_VALIDATE_INT);
        $contingencyType = is_int($typeValue) ? $typeValue : null;
        $contingencyReason = is_string($contingency['motivo'] ?? null) ? trim($contingency['motivo']) : '';
        $errors = [];
        if ($contingencyType === null || $contingencyType < 1 || $contingencyType > 5) {
            $errors['tipo_contingencia'] = 'Seleccione un tipo de contingencia válido.';
        }
        if (mb_strlen($contingencyReason) > 500) {
            $errors['motivo_contin'] = 'El motivo no puede superar los 500 caracteres.';
        } elseif ($contingencyType === 5 && $contingencyReason === '') {
            $errors['motivo_contin'] = 'Explique el motivo de la contingencia cuando selecciona el tipo 5.';
        }
        if ($errors !== []) {
            throw new DocumentValidationException('Revise los datos de contingencia.', $errors);
        }
    }

    $pdo->beginTransaction();
    try {
        $document = documentRecord($pdo, $tenantId, $id, true);
        if ($document === null) throw new RuntimeException('El documento no existe.');
        if ($document['estado'] !== 'BORRADOR') throw new RuntimeException('Solo puede emitir un documento en borrador. Para reintentar use Transmitir.');
        $tenant = tenantDocumentContext($pdo, $tenantId);
        $client = emissionClient($pdo, $tenantId, (int) $document['cliente_id'], $document['tipo_dte']);
        $items = documentItemRecords($pdo, $tenantId, $id);
        if ($items === []) throw new RuntimeException('El documento no tiene productos o servicios.');
        $condition = ['codigo' => $document['condicion_codigo']];
        $payment = ['codigo' => $document['pago_codigo']];

        if (documentIsInternalType((string) $document['tipo_dte'])) {
            if ($isContingency) {
                throw new RuntimeException('El comprobante interno no se transmite al MH; no aplica la contingencia.');
            }
            $document['numero_control'] = reserveDocumentNumber($pdo, $tenantId, 'IN', $tenant['establecimiento']);
            $document['hora_emision'] = (new DateTimeImmutable('now', new DateTimeZone($tenant['timezone'])))->format('H:i:s');
            [, $summary] = calculateDocumentTotals('IN', $items, false);
            $statement = $pdo->prepare(
                "UPDATE documentos SET numero_control = :numero_control, hora_emision = :hora_emision,
                    estado = 'PROCESADA', tipo_operacion = 1, tipo_contingencia = NULL,
                    motivo_contingencia = NULL, codigo_generacion = NULL, dte_json = NULL,
                    firma_digital = NULL, sello_recepcion = NULL, procesado_at = UTC_TIMESTAMP(),
                    total_gravada = :total_gravada, total_exenta = :total_exenta, total_no_suj = :total_no_suj,
                    total_descuento = :total_descuento, total_iva = :total_iva, iva_rete1 = :iva_rete1,
                    rete_renta = :rete_renta, total_pagar = :total_pagar
                 WHERE tenant_id = :tenant_id AND id = :id AND estado = 'BORRADOR'"
            );
            $statement->execute([
                'tenant_id' => $tenantId, 'id' => $id,
                'numero_control' => $document['numero_control'],
                'hora_emision' => $document['hora_emision'],
            ] + documentTotalParams($summary));
            $pdo->commit();
            // El comprobante ya quedó firme: un fallo de inventario o contabilidad
            // no lo revierte, pero queda registrado para conciliar.
            try {
                inventarioRegistrarSalidaDocumento($tenantId, $id, (int) $user['id']);
            } catch (Throwable $inventoryException) {
                error_log('FacturaSV: no se pudo descontar inventario del comprobante interno ' . $id . ': ' . $inventoryException->getMessage());
            }
            try {
                contabilidadRegisterDocument($tenantId, $id, (int) $user['id']);
            } catch (Throwable $accountingException) {
                error_log('FacturaSV: no se pudo contabilizar el comprobante interno ' . $id . ': ' . $accountingException->getMessage());
            }
            return documentDetail($tenantId, $id);
        }

        $document['numero_control'] = reserveDocumentNumber($pdo, $tenantId, $document['tipo_dte'], $tenant['establecimiento']);
        $document['codigo_generacion'] = generateDteUuid();
        $document['hora_emision'] = (new DateTimeImmutable('now', new DateTimeZone($tenant['timezone'])))->format('H:i:s');
        $document['tipo_operacion'] = $isContingency ? 2 : 1;
        $document['tipo_contingencia'] = $isContingency ? $contingencyType : null;
        $document['motivo_contingencia'] = $isContingency && $contingencyReason !== '' ? $contingencyReason : null;

        $related = null;
        $options = [];
        if (in_array($document['tipo_dte'], ['05', '06'], true)) {
            $related = documentRelatedRecord($pdo, $tenantId, (int) ($document['documento_relacionado_id'] ?? 0));
            if ($related === null
                || !in_array($related['tipo_dte'], ['01', '03'], true)
                || $related['estado'] !== 'PROCESADA'
                || (int) $related['cliente_id'] !== (int) $document['cliente_id']) {
                throw new RuntimeException('El documento relacionado de la nota ya no es válido. Edite el borrador y seleccione otro.');
            }
            $options['related_type'] = $related['tipo_dte'];
        }
        if ($document['tipo_dte'] === '11') {
            $options['flete'] = (float) $document['export_flete'];
            $options['seguro'] = (float) $document['export_seguro'];
            $options['no_onerosas'] = (float) $document['export_no_onerosas'];
        }
        if ($document['tipo_dte'] === '14') {
            $options['rete_renta'] = (float) $document['rete_renta'];
        }

        $dte = buildDteDocument($document, $tenant, $client, $items, $condition, $payment, $related, $options);
        [, $summary] = calculateDocumentTotals(
            $document['tipo_dte'],
            $items,
            (bool) $document['aplicar_iva_retenido'],
            $options
        );
        $statement = $pdo->prepare(
            "UPDATE documentos SET numero_control = :numero_control, codigo_generacion = :codigo_generacion,
                hora_emision = :hora_emision, estado = 'EMITIDA', dte_json = :dte_json,
                tipo_operacion = :tipo_operacion, tipo_contingencia = :tipo_contingencia,
                motivo_contingencia = :motivo_contingencia,
                total_gravada = :total_gravada, total_exenta = :total_exenta, total_no_suj = :total_no_suj,
                total_descuento = :total_descuento, total_iva = :total_iva, iva_rete1 = :iva_rete1,
                rete_renta = :rete_renta, total_pagar = :total_pagar
             WHERE tenant_id = :tenant_id AND id = :id AND estado = 'BORRADOR'"
        );
        $statement->execute([
            'tenant_id' => $tenantId, 'id' => $id, 'numero_control' => $document['numero_control'],
            'codigo_generacion' => $document['codigo_generacion'], 'hora_emision' => $document['hora_emision'],
            'tipo_operacion' => $document['tipo_operacion'], 'tipo_contingencia' => $document['tipo_contingencia'],
            'motivo_contingencia' => $document['motivo_contingencia'],
            'dte_json' => json_encode($dte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        ] + documentTotalParams($summary));
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
    if (!$isContingency) {
        transmitDocument($tenantId, $user, $id);
    }
    return documentDetail($tenantId, $id);
}

function mhLog(PDO $pdo, int $tenantId, ?int $documentId, ?int $userId, string $action, mixed $request, mixed $response, string $status, ?int $eventoId = null): void
{
    $statement = $pdo->prepare(
        'INSERT INTO mh_transmissions_log
            (tenant_id, documento_id, evento_contingencia_id, user_id, accion, request_json, response_json, estado)
         VALUES
            (:tenant_id, :documento_id, :evento_contingencia_id, :user_id, :accion, :request_json, :response_json, :estado)'
    );
    $statement->execute([
        'tenant_id' => $tenantId, 'documento_id' => $documentId,
        'evento_contingencia_id' => $eventoId, 'user_id' => $userId, 'accion' => $action,
        'request_json' => $request === null ? null : json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        'response_json' => $response === null ? null : json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
        'estado' => $status,
    ]);
}

function transmitDocument(int $tenantId, array $user, int $id): array
{
    $pdo = db();
    $document = documentRecord($pdo, $tenantId, $id);
    if ($document === null) throw new RuntimeException('El documento no existe.');
    if (documentIsInternalType((string) $document['tipo_dte'])) {
        throw new RuntimeException('El comprobante interno no se transmite al Ministerio de Hacienda.');
    }
    if (!in_array($document['estado'], ['EMITIDA', 'RECHAZADA'], true)) {
        throw new RuntimeException('Solo puede transmitir documentos emitidos o rechazados.');
    }
    if ((int) ($document['tipo_operacion'] ?? 1) === 2) {
        $eventId = (int) ($document['contingencia_evento_id'] ?? 0);
        if ($eventId <= 0) {
            throw new RuntimeException('El documento está emitido en contingencia. Debe declarar el evento de contingencia antes de transmitirlo.');
        }
        $statement = $pdo->prepare('SELECT estado FROM eventos_contingencia WHERE tenant_id = :tenant_id AND id = :id');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $eventId]);
        if ($statement->fetchColumn() !== 'PROCESADO') {
            throw new RuntimeException('El evento de contingencia asociado aún no está procesado.');
        }
    }
    $dte = json_decode((string) $document['dte_json'], true);
    if (!is_array($dte)) throw new RuntimeException('El documento no contiene un JSON DTE válido.');
    $connector = MHConnectorFactory::make($document['mh_mode'] ?? tenantDocumentContext($pdo, $tenantId)['mh_mode']);
    try {
        $signed = $connector->firmarDocumento($dte, ['tenant_id' => $tenantId]);
        mhLog($pdo, $tenantId, $id, (int) $user['id'], 'FIRMA', $dte, $signed, $signed['estado']);
        $statement = $pdo->prepare('UPDATE documentos SET firma_digital = :firma WHERE tenant_id = :tenant_id AND id = :id');
        $statement->execute(['firma' => $signed['firma'], 'tenant_id' => $tenantId, 'id' => $id]);
        $response = $connector->transmitirDTE($signed['firma'], $dte, ['tenant_id' => $tenantId]);
        mhLog($pdo, $tenantId, $id, (int) $user['id'], 'TRANSMISION', ['codigoGeneracion' => $dte['identificacion']['codigoGeneracion']], $response, $response['estado']);
        $processed = ($response['estado'] ?? '') === 'PROCESADO';
        $seal = $response['selloRecetion'] ?? $response['selloRecepcion'] ?? null;
        if ($processed && (!is_string($seal) || !preg_match('/^[A-Z0-9]{40}$/', $seal))) {
            throw new RuntimeException('La respuesta simulada no contiene un sello válido.');
        }
        $statement = $pdo->prepare(
            'UPDATE documentos SET estado = :estado, sello_recepcion = :sello,
                procesado_at = CASE WHEN :processed = 1 THEN UTC_TIMESTAMP() ELSE procesado_at END
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $statement->execute([
            'estado' => $processed ? 'PROCESADA' : 'RECHAZADA', 'sello' => $processed ? $seal : null,
            'processed' => $processed ? 1 : 0, 'tenant_id' => $tenantId, 'id' => $id,
        ]);
        if ($processed) {
            // El inventario y la contabilidad son posteriores a la transmisión:
            // un fallo en cualquiera de ellos no revierte el DTE ya procesado.
            try {
                inventarioRegistrarSalidaDocumento($tenantId, $id, (int) $user['id']);
            } catch (Throwable $inventoryException) {
                error_log('FacturaSV: no se pudo descontar inventario del documento ' . $id . ': ' . $inventoryException->getMessage());
            }
            try {
                contabilidadRegisterDocument($tenantId, $id, (int) $user['id']);
            } catch (Throwable $accountingException) {
                error_log('FacturaSV: no se pudo contabilizar el documento ' . $id . ': ' . $accountingException->getMessage());
            }
            // El envío al cliente es posterior a la transmisión: un fallo de
            // correo no revierte el DTE ya procesado, pero queda registrado.
            try {
                require_once __DIR__ . '/correo.php';
                enviarCorreoDocumento($tenantId, $id, (int) $user['id']);
            } catch (Throwable $mailException) {
                error_log('FacturaSV: no se pudo preparar el correo del documento ' . $id . ': ' . $mailException->getMessage());
            }
        }
        return $response;
    } catch (Throwable $exception) {
        mhLog($pdo, $tenantId, $id, (int) $user['id'], 'TRANSMISION', ['codigoGeneracion' => $dte['identificacion']['codigoGeneracion']], ['error' => $exception->getMessage()], 'ERROR');
        $statement = $pdo->prepare("UPDATE documentos SET estado = 'RECHAZADA' WHERE tenant_id = :tenant_id AND id = :id AND estado IN ('EMITIDA','RECHAZADA')");
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        throw $exception;
    }
}

function consultDocument(int $tenantId, array $user, int $id): array
{
    $pdo = db();
    $document = documentRecord($pdo, $tenantId, $id);
    if ($document === null || empty($document['codigo_generacion'])) throw new RuntimeException('El documento aún no tiene código de generación.');
    $connector = MHConnectorFactory::make(tenantDocumentContext($pdo, $tenantId)['mh_mode']);
    $response = $connector->consultarEstado($document['codigo_generacion'], ['sello_recepcion' => $document['sello_recepcion']]);
    mhLog($pdo, $tenantId, $id, (int) $user['id'], 'CONSULTA', ['codigoGeneracion' => $document['codigo_generacion']], $response, $response['estado']);
    return $response;
}

/**
 * Anula un comprobante interno. No hay evento ante el MH: solo se marca el
 * documento, se revierte el inventario y se genera el contra-asiento.
 */
function invalidateInternalDocument(PDO $pdo, int $tenantId, array $user, array $document, array $input): array
{
    $id = (int) $document['id'];
    if ($document['estado'] !== 'PROCESADA' || empty($document['numero_control'])) {
        throw new RuntimeException('Solo puede anular un comprobante interno procesado.');
    }
    $reason = is_string($input['motivo'] ?? null) ? trim($input['motivo']) : '';
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
        throw new DocumentValidationException('Revise los datos de anulación.', [
            'motivo' => 'El motivo debe tener entre 5 y 200 caracteres.',
        ]);
    }
    $event = [
        'tipoEvento' => 'ANULACION_INTERNA',
        'numeroControl' => $document['numero_control'],
        'motivo' => $reason,
        'fechaAnulacion' => gmdate('c'),
        'usuario' => (string) ($user['nombre'] ?? ''),
    ];
    $statement = $pdo->prepare(
        "UPDATE documentos SET estado = 'INVALIDADA', motivo_invalidacion = :motivo,
            invalidacion_json = :evento, invalidado_at = UTC_TIMESTAMP()
         WHERE tenant_id = :tenant_id AND id = :id AND estado = 'PROCESADA'"
    );
    $statement->execute([
        'motivo' => $reason,
        'evento' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'tenant_id' => $tenantId,
        'id' => $id,
    ]);
    if ($statement->rowCount() === 0) {
        throw new RuntimeException('El comprobante interno ya fue anulado.');
    }
    try {
        inventarioRegistrarReversionDocumento($tenantId, $id, (int) $user['id']);
    } catch (Throwable $inventoryException) {
        error_log('FacturaSV: no se pudo revertir el inventario del comprobante interno ' . $id . ': ' . $inventoryException->getMessage());
    }
    try {
        contabilidadRegisterInvalidation($tenantId, $id, (int) $user['id']);
    } catch (Throwable $accountingException) {
        error_log('FacturaSV: no se pudo registrar la anulación contable del comprobante interno ' . $id . ': ' . $accountingException->getMessage());
    }
    return documentDetail($tenantId, $id);
}

function invalidateDocument(int $tenantId, array $user, int $id, array $input): array
{
    $pdo = db();
    $document = documentRecord($pdo, $tenantId, $id);
    if ($document === null) throw new RuntimeException('El documento no existe.');
    if (documentIsInternalType((string) $document['tipo_dte'])) {
        return invalidateInternalDocument($pdo, $tenantId, $user, $document, $input);
    }
    if ($document['estado'] !== 'PROCESADA' || empty($document['sello_recepcion'])) {
        throw new RuntimeException('Solo puede invalidar un documento procesado con sello de recepción.');
    }
    $type = $input['tipo_anulacion'] ?? '';
    $reason = is_string($input['motivo'] ?? null) ? trim($input['motivo']) : '';
    $docType = $input['tipo_documento_responsable'] ?? '';
    $docNumber = preg_replace('/\D/', '', (string) ($input['numero_documento_responsable'] ?? ''));
    if (!in_array($type, ['1', '2', '3'], true)) throw new DocumentValidationException('Revise los datos de invalidación.', ['tipo_anulacion' => 'Seleccione un tipo de invalidación válido.']);
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) throw new DocumentValidationException('Revise los datos de invalidación.', ['motivo' => 'El motivo debe tener entre 5 y 200 caracteres.']);
    if (!in_array($docType, ['13', '36'], true) || !preg_match($docType === '36' ? '/^[0-9]{14}$/' : '/^[0-9]{9}$/', $docNumber)) {
        throw new DocumentValidationException('Revise los datos de invalidación.', ['numero_documento_responsable' => 'Ingrese un DUI de 9 dígitos o NIT de 14 dígitos del responsable.']);
    }
    $input = ['tipo_anulacion' => $type, 'motivo' => $reason, 'tipo_documento_responsable' => $docType, 'numero_documento_responsable' => $docNumber];
    $tenant = tenantDocumentContext($pdo, $tenantId);
    $dte = json_decode((string) $document['dte_json'], true);
    $event = buildInvalidationEvent($document, $dte, $tenant, $input, $user);
    $connector = MHConnectorFactory::make($tenant['mh_mode']);
    try {
        $response = $connector->invalidarDTE($event, ['tenant_id' => $tenantId]);
        mhLog($pdo, $tenantId, $id, (int) $user['id'], 'INVALIDACION', $event, $response, $response['estado']);
        if (($response['estado'] ?? '') !== 'PROCESADO') throw new RuntimeException('El evento de invalidación no fue procesado.');
        $statement = $pdo->prepare(
            "UPDATE documentos SET estado = 'INVALIDADA', invalidacion_json = :evento,
                motivo_invalidacion = :motivo, invalidado_at = UTC_TIMESTAMP()
             WHERE tenant_id = :tenant_id AND id = :id AND estado = 'PROCESADA'"
        );
        $statement->execute([
            'evento' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'motivo' => $reason, 'tenant_id' => $tenantId, 'id' => $id,
        ]);
        try {
            inventarioRegistrarReversionDocumento($tenantId, $id, (int) $user['id']);
        } catch (Throwable $inventoryException) {
            error_log('FacturaSV: no se pudo revertir el inventario del documento ' . $id . ': ' . $inventoryException->getMessage());
        }
        try {
            contabilidadRegisterInvalidation($tenantId, $id, (int) $user['id']);
        } catch (Throwable $accountingException) {
            error_log('FacturaSV: no se pudo registrar la invalidación contable del documento ' . $id . ': ' . $accountingException->getMessage());
        }
        return documentDetail($tenantId, $id);
    } catch (Throwable $exception) {
        mhLog($pdo, $tenantId, $id, (int) $user['id'], 'INVALIDACION', $event, ['error' => $exception->getMessage()], 'ERROR');
        throw $exception;
    }
}

function documentDetail(int $tenantId, int $id): array
{
    $pdo = db();
    $document = documentRecord($pdo, $tenantId, $id);
    if ($document === null) throw new RuntimeException('El documento no existe.');
    $statement = $pdo->prepare(
        'SELECT l.id, l.accion, l.request_json, l.response_json, l.estado, l.created_at, u.nombre AS usuario
         FROM mh_transmissions_log l LEFT JOIN users u ON u.id = l.user_id
         WHERE l.tenant_id = :tenant_id AND l.documento_id = :documento_id ORDER BY l.id DESC'
    );
    $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $id]);
    $logs = array_map(static function (array $log): array {
        $log['request'] = $log['request_json'] ? json_decode($log['request_json'], true) : null;
        $log['response'] = $log['response_json'] ? json_decode($log['response_json'], true) : null;
        unset($log['request_json'], $log['response_json']);
        return $log;
    }, $statement->fetchAll());
    $document['dte'] = $document['dte_json'] ? json_decode($document['dte_json'], true) : null;
    $document['invalidacion'] = $document['invalidacion_json'] ? json_decode($document['invalidacion_json'], true) : null;
    unset($document['dte_json'], $document['invalidacion_json'], $document['firma_digital']);
    // El historial de correos requiere la migración 009; si aún no se aplicó,
    // el detalle del documento sigue funcionando sin esa sección.
    $correos = [];
    try {
        $statement = $pdo->prepare(
            'SELECT c.id, c.destinatario, c.copia, c.asunto, c.transporte, c.estado, c.error, c.adjuntos,
                    c.created_at, u.nombre AS usuario
             FROM documento_correos c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.tenant_id = :tenant_id AND c.documento_id = :documento_id
             ORDER BY c.id DESC LIMIT 8'
        );
        $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $id]);
        $correos = $statement->fetchAll();
    } catch (PDOException) {
        $correos = [];
    }
    return ['document' => $document, 'items' => documentItemRecords($pdo, $tenantId, $id), 'logs' => $logs, 'correos' => $correos];
}

function documentQueryParts(int $tenantId, array $filters): array
{
    $where = ['d.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    foreach (['fecha_desde' => '>=', 'fecha_hasta' => '<='] as $field => $operator) {
        $date = (string) ($filters[$field] ?? '');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed !== false && $parsed->format('Y-m-d') === $date) {
            $where[] = "d.fecha_emision {$operator} :{$field}";
            $params[$field] = $date;
        }
    }
    if (array_key_exists($filters['tipo_dte'] ?? '', documentAllTypeDefinitions())) {
        $where[] = 'd.tipo_dte = :tipo_dte';
        $params['tipo_dte'] = $filters['tipo_dte'];
    }
    if (array_key_exists($filters['estado'] ?? '', documentStatuses())) {
        $where[] = 'd.estado = :estado';
        $params['estado'] = $filters['estado'];
    }
    $clientId = filter_var($filters['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($clientId !== false && $clientId !== null) {
        $where[] = 'd.cliente_id = :cliente_id';
        $params['cliente_id'] = $clientId;
    }
    return ['where' => implode(' AND ', $where), 'params' => $params];
}
