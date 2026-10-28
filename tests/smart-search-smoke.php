<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/clientes.php';
require_once dirname(__DIR__) . '/lib/productos.php';

/**
 * Pruebas de búsqueda inteligente: varios términos en cualquier orden,
 * sin distinguir mayúsculas ni acentos, documentos sin guiones y catálogos.
 */

$pdo = db();
$checks = 0;

function checkSmart(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO BÚSQUEDA: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function smartCount(PDO $pdo, string $sql, array $params): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

$params = [];
checkSmart(smartSearchCondition(['c.nombre'], '   ', 'x_', $params) === null, 'una búsqueda vacía no genera condición');
$params = [];
$condition = smartSearchCondition(['c.nombre', 'c.email'], 'maria lopez', 'x_', $params);
checkSmart(
    $condition !== null && substr_count($condition, ' AND ') === 1 && count($params) === 4,
    'dos términos generan dos grupos con una condición por columna'
);

$clientQuery = clientQueryParts(1, ['search' => 'lopez maria']);
$clientCount = smartCount($pdo, 'SELECT COUNT(*) ' . $clientQuery['from'] . ' WHERE ' . $clientQuery['where'], $clientQuery['params']);
checkSmart($clientCount === 1, 'clientes encuentra "lopez maria" en cualquier orden');

$clientQuery = clientQueryParts(1, ['search' => 'MARIA LOPEZ']);
$clientCount = smartCount($pdo, 'SELECT COUNT(*) ' . $clientQuery['from'] . ' WHERE ' . $clientQuery['where'], $clientQuery['params']);
checkSmart($clientCount === 1, 'clientes ignora mayúsculas y el orden de las palabras');

$clientQuery = clientQueryParts(1, ['search' => '0614111111111']);
$clientCount = smartCount($pdo, 'SELECT COUNT(*) ' . $clientQuery['from'] . ' WHERE ' . $clientQuery['where'], $clientQuery['params']);
checkSmart($clientCount === 1, 'clientes encuentra un documento escrito sin guiones');

$clientQuery = clientQueryParts(1, ['search' => 'comercial ejemplo']);
$clientCount = smartCount($pdo, 'SELECT COUNT(*) ' . $clientQuery['from'] . ' WHERE ' . $clientQuery['where'], $clientQuery['params']);
checkSmart($clientCount === 1, 'clientes busca por varias palabras del nombre');

$productQuery = productQueryParts(1, ['search' => 'laptop empresa']);
$productCount = smartCount($pdo, 'SELECT COUNT(*) FROM productos p WHERE ' . $productQuery['where'], $productQuery['params']);
checkSmart($productCount === 1, 'productos encuentra "laptop empresa" por descripción');

$productQuery = productQueryParts(1, ['search' => 'PROD 001']);
$productCount = smartCount($pdo, 'SELECT COUNT(*) FROM productos p WHERE ' . $productQuery['where'], $productQuery['params']);
checkSmart($productCount === 1, 'productos encuentra un código partido en dos términos');

$productQuery = productQueryParts(1, ['search' => 'inalambrico']);
$productCount = smartCount($pdo, 'SELECT COUNT(*) FROM productos p WHERE ' . $productQuery['where'], $productQuery['params']);
checkSmart($productCount === 1, 'productos ignora el acento de "inalámbrico"');

$catalogParams = ['tenant_id' => 1];
$catalogCondition = smartSearchCondition(
    ['m.codigo', 'm.nombre'],
    'kilometro cuadrado',
    'catalog_search_',
    $catalogParams
);
$catalogCount = smartCount(
    $pdo,
    'SELECT COUNT(*) FROM mh_unidades_medida m WHERE m.tenant_id = :tenant_id AND ' . $catalogCondition,
    $catalogParams
);
checkSmart($catalogCount === 1, 'catálogos encuentra "kilometro cuadrado" sin acento');

$catalogParams = ['tenant_id' => 1];
$catalogCondition = smartSearchCondition(
    ['m.codigo', 'm.nombre'],
    'TARJETA DEBITO',
    'catalog_search_',
    $catalogParams
);
$catalogCount = smartCount(
    $pdo,
    'SELECT COUNT(*) FROM mh_formas_pago m WHERE m.tenant_id = :tenant_id AND ' . $catalogCondition,
    $catalogParams
);
checkSmart($catalogCount === 1, 'catálogos busca "TARJETA DEBITO" sin acento y en mayúsculas');

echo "\nRESULTADO: {$checks} comprobaciones correctas.\n";
