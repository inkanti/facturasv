<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';

/**
 * Pruebas del código de barras por producto: normalización, validación,
 * unicidad, búsqueda en el catálogo y exposición en el formulario de factura.
 */

$pdo = db();
$checks = 0;
$productId = null;
$suffix = (string) getmypid() . (string) random_int(100, 999);
$code = 'M16-' . $suffix;
$barcode = 'BC' . $suffix;

function checkBarcode(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO CÓDIGO DE BARRAS: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function barcodeInput(string $code, string $barcode, int $unitId): array
{
    return [
        'codigo' => $code,
        'codigo_barras' => '  ' . strtolower($barcode) . '  ',
        'descripcion' => 'Producto de prueba con código de barras',
        'tipo' => 'BIEN',
        'unidad_medida_id' => $unitId,
        'precio_unitario' => '113.00',
        'tratamiento_iva' => 'GRAVADO',
        'control_stock' => '0',
        'activo' => '1',
    ];
}

try {
    $unitId = (int) $pdo->query(
        'SELECT id FROM mh_unidades_medida WHERE tenant_id = 1 AND activo = 1 ORDER BY orden, id LIMIT 1'
    )->fetchColumn();
    checkBarcode($unitId > 0, 'existe una unidad de medida para la prueba');

    $normalized = normalizeProductData(barcodeInput($code, $barcode, $unitId));
    checkBarcode($normalized['codigo_barras'] === $barcode, 'el código de barras se recorta y pasa a mayúsculas');
    checkBarcode(normalizeProductData(['codigo_barras' => ''])['codigo_barras'] === '', 'un código vacío se conserva vacío');

    [$valid, $errors] = validateProductData($pdo, 1, $normalized);
    checkBarcode($errors === [], 'un código de barras válido no genera errores');

    $statement = $pdo->prepare(
        'INSERT INTO productos (tenant_id, codigo, codigo_barras, descripcion, tipo, unidad_medida_id,
            precio_unitario, tratamiento_iva, control_stock, stock, activo)
         VALUES (1, :codigo, :codigo_barras, :descripcion, :tipo, :unidad_medida_id,
            :precio_unitario, :tratamiento_iva, 0, NULL, 1)'
    );
    $statement->execute([
        'codigo' => $valid['codigo'],
        'codigo_barras' => $valid['codigo_barras'],
        'descripcion' => $valid['descripcion'],
        'tipo' => $valid['tipo'],
        'unidad_medida_id' => $valid['unidad_medida_id'],
        'precio_unitario' => $valid['precio_unitario'],
        'tratamiento_iva' => $valid['tratamiento_iva'],
    ]);
    $productId = (int) $pdo->lastInsertId();
    checkBarcode($productId > 0, 'el producto con código de barras se guarda');

    $stored = $pdo->query('SELECT codigo_barras FROM productos WHERE id = ' . $productId)->fetchColumn();
    checkBarcode($stored === $barcode, 'el código de barras queda almacenado');

    [$duplicate, $duplicateErrors] = validateProductData($pdo, 1, normalizeProductData(barcodeInput('M16-DUP-' . $suffix, $barcode, $unitId)));
    checkBarcode(isset($duplicateErrors['codigo_barras']), 'se rechaza un código de barras duplicado');

    $sameProduct = barcodeInput($code, $barcode, $unitId);
    $sameProduct['id'] = (string) $productId;
    [, $sameErrors] = validateProductData($pdo, 1, normalizeProductData($sameProduct));
    checkBarcode($sameErrors === [], 'el mismo producto puede conservar su código al editarse');

    [$invalid, $invalidErrors] = validateProductData($pdo, 1, normalizeProductData([
        'codigo_barras' => 'AB CD', 'codigo' => 'M16-BAD-' . $suffix,
        'descripcion' => 'Código inválido', 'tipo' => 'BIEN', 'unidad_medida_id' => $unitId,
        'precio_unitario' => '1.00', 'tratamiento_iva' => 'GRAVADO', 'activo' => '1',
    ]));
    checkBarcode(isset($invalidErrors['codigo_barras']), 'se rechaza un código con espacios o demasiado corto');

    $query = productQueryParts(1, ['search' => $barcode]);
    $count = $pdo->prepare('SELECT COUNT(*) FROM productos p WHERE ' . $query['where']);
    $count->execute($query['params']);
    checkBarcode((int) $count->fetchColumn() === 1, 'la búsqueda del catálogo encuentra el producto por código de barras');

    $options = documentFormOptions(1);
    $found = null;
    foreach ($options['productos'] as $product) {
        if ((int) $product['id'] === $productId) {
            $found = $product;
        }
    }
    checkBarcode($found !== null && $found['codigo_barras'] === $barcode, 'el formulario de factura recibe el código de barras');

    $duplicateInsert = false;
    try {
        $pdo->prepare(
            'INSERT INTO productos (tenant_id, codigo, codigo_barras, descripcion, tipo, unidad_medida_id,
                precio_unitario, tratamiento_iva, control_stock, stock, activo)
             VALUES (1, :codigo, :codigo_barras, :descripcion, :tipo, :unidad_medida_id,
                :precio_unitario, :tratamiento_iva, 0, NULL, 1)'
        )->execute([
            'codigo' => 'M16-UNIQ-' . $suffix,
            'codigo_barras' => $barcode,
            'descripcion' => 'Duplicado a nivel de base',
            'tipo' => 'BIEN',
            'unidad_medida_id' => $unitId,
            'precio_unitario' => '1.000000',
            'tratamiento_iva' => 'GRAVADO',
        ]);
    } catch (PDOException $exception) {
        $duplicateInsert = $exception->getCode() === '23000';
    }
    checkBarcode($duplicateInsert, 'la base de datos impide duplicar el código de barras en la empresa');

    echo "\nRESULTADO: {$checks} comprobaciones correctas.\n";
} finally {
    if ($productId !== null) {
        $pdo->prepare('DELETE FROM productos WHERE tenant_id = 1 AND id = :id')->execute(['id' => $productId]);
    }
    $pdo->prepare('DELETE FROM productos WHERE tenant_id = 1 AND codigo LIKE :code')
        ->execute(['code' => 'M16-%' . $suffix]);
    echo "Datos temporales eliminados.\n";
}
