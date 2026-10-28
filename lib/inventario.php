<?php

declare(strict_types=1);

/**
 * Módulo 14: inventario básico.
 *
 * El stock actual no se guarda como un saldo mutable: se calcula como la
 * existencia base del producto (productos.stock) más la suma de sus
 * movimientos. Así, al eliminar un documento sus movimientos se eliminan en
 * cascada y el stock vuelve solo a su valor anterior.
 *
 * Las ventas descuentan existencias automáticamente al procesarse el DTE. Las
 * invalidaciones generan el movimiento inverso. No incluye compras ni costeo.
 */

final class InventarioValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

function inventarioTipos(): array
{
    return [
        'ENTRADA' => 'Entrada',
        'SALIDA' => 'Salida',
        'AJUSTE_POSITIVO' => 'Ajuste positivo',
        'AJUSTE_NEGATIVO' => 'Ajuste negativo',
    ];
}

function inventarioTipoSigno(string $type): int
{
    return in_array($type, ['ENTRADA', 'AJUSTE_POSITIVO'], true) ? 1 : -1;
}

function inventarioTimezone(int $tenantId): string
{
    $statement = db()->prepare('SELECT timezone FROM tenant_config WHERE tenant_id = :tenant_id');
    $statement->execute(['tenant_id' => $tenantId]);
    $timezone = $statement->fetchColumn();
    return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
        ? $timezone
        : 'America/El_Salvador';
}

function inventarioToday(int $tenantId): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(inventarioTimezone($tenantId))))->format('Y-m-d');
}

function inventarioDate(mixed $value): ?string
{
    $text = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
}

/**
 * Normaliza un rango de fechas. Por omisión, el mes en curso.
 *
 * @return array{desde:string,hasta:string,mes:string}
 */
function inventarioRange(array $input, ?string $timezone = null): array
{
    $timezone = $timezone ?? 'America/El_Salvador';
    $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $from = inventarioDate($input['fecha_desde'] ?? '') ?? $today->modify('first day of this month')->format('Y-m-d');
    $to = inventarioDate($input['fecha_hasta'] ?? '') ?? $today->format('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $limit = (new DateTimeImmutable($to))->modify('-3 years')->format('Y-m-d');
    if ($from < $limit) {
        $from = $limit;
    }
    return ['desde' => $from, 'hasta' => $to, 'mes' => substr($to, 0, 7)];
}

/**
 * Crea la configuración de inventario con la fecha de inicio del control.
 * Los documentos procesados antes de esa fecha no se contabilizan en el kardex.
 */
function inventarioEnsureTenant(int $tenantId): void
{
    $pdo = db();
    $exists = $pdo->prepare('SELECT COUNT(*) FROM inventario_config WHERE tenant_id = :tenant_id');
    $exists->execute(['tenant_id' => $tenantId]);
    if ((int) $exists->fetchColumn() === 0) {
        $pdo->prepare(
            'INSERT IGNORE INTO inventario_config (tenant_id, fecha_inicio) VALUES (:tenant_id, :fecha_inicio)'
        )->execute(['tenant_id' => $tenantId, 'fecha_inicio' => inventarioToday($tenantId)]);
    }
}

function inventarioConfig(int $tenantId): array
{
    inventarioEnsureTenant($tenantId);
    $statement = db()->prepare('SELECT * FROM inventario_config WHERE tenant_id = :tenant_id LIMIT 1');
    $statement->execute(['tenant_id' => $tenantId]);
    $config = $statement->fetch();
    if ($config === false) {
        throw new RuntimeException('La empresa no tiene configuración de inventario.');
    }
    return $config;
}

/** Expresión SQL del stock actual para un alias de productos. */
function inventarioStockExpression(string $alias = 'p'): string
{
    $prefix = $alias === '' ? '' : $alias . '.';
    return '(' . $prefix . 'stock + COALESCE((
        SELECT SUM(CASE WHEN m.tipo IN (\'ENTRADA\', \'AJUSTE_POSITIVO\') THEN m.cantidad ELSE -m.cantidad END)
        FROM inventario_movimientos m
        WHERE m.tenant_id = ' . $prefix . 'tenant_id AND m.producto_id = ' . $prefix . 'id
    ), 0))';
}

function inventarioStockActual(PDO $pdo, int $tenantId, int $productId): float
{
    $statement = $pdo->prepare(
        'SELECT ' . inventarioStockExpression('p') . ' AS stock_actual
         FROM productos p WHERE p.tenant_id = :tenant_id AND p.id = :id'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $productId]);
    return round((float) $statement->fetchColumn(), 4);
}

function inventarioProduct(int $tenantId, int $productId): ?array
{
    $statement = db()->prepare(
        'SELECT p.id, p.codigo, p.descripcion, p.tipo, p.control_stock, p.stock,
                ' . inventarioStockExpression('p') . ' AS stock_actual,
                u.codigo AS unidad_codigo, u.nombre AS unidad_nombre
         FROM productos p
         INNER JOIN mh_unidades_medida u ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
         WHERE p.tenant_id = :tenant_id AND p.id = :id LIMIT 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $productId]);
    $product = $statement->fetch();
    if ($product === false) {
        return null;
    }
    $product['stock_actual'] = round((float) $product['stock_actual'], 4);
    return $product;
}

function inventarioProductOptions(int $tenantId): array
{
    $statement = db()->prepare(
        'SELECT p.id, p.codigo, p.descripcion, p.tipo, p.activo,
                ' . inventarioStockExpression('p') . ' AS stock_actual,
                u.codigo AS unidad_codigo, u.nombre AS unidad_nombre
         FROM productos p
         INNER JOIN mh_unidades_medida u ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
         WHERE p.tenant_id = :tenant_id AND p.control_stock = 1
         ORDER BY p.descripcion, p.codigo'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    $products = $statement->fetchAll();
    foreach ($products as &$product) {
        $product['stock_actual'] = round((float) $product['stock_actual'], 4);
    }
    unset($product);
    return $products;
}

function inventarioParseQuantity(mixed $value): ?int
{
    $text = trim((string) $value);
    if (preg_match('/^[0-9]{1,11}(\.[0-9]{1,4})?$/', $text) !== 1) {
        return null;
    }
    return (int) round(((float) $text) * 10000);
}

function inventarioQuantityFromDocument(mixed $value): int
{
    return (int) round(((float) $value) * 10000);
}

function inventarioAudit(int $tenantId, ?int $userId, string $detail): void
{
    $statement = db()->prepare(
        'INSERT INTO configuracion_cambios (tenant_id, user_id, seccion, detalle)
         VALUES (:tenant_id, :user_id, :seccion, :detalle)'
    );
    $statement->execute([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'seccion' => 'inventario',
        'detalle' => mb_substr($detail, 0, 1000),
    ]);
}

/**
 * Inserta un movimiento ya validado. El llamador debe haber bloqueado la fila
 * del producto cuando corresponda.
 *
 * @param array{producto_id:int,fecha:string,tipo:string,cantidad:int,motivo:string,documento_id?:?int} $data
 */
function inventarioInsertMovement(PDO $pdo, int $tenantId, int $userId, array $data): int
{
    $productId = (int) ($data['producto_id'] ?? 0);
    $type = (string) ($data['tipo'] ?? '');
    $quantity = (int) ($data['cantidad'] ?? 0);
    $date = inventarioDate($data['fecha'] ?? '');
    $reason = trim((string) ($data['motivo'] ?? ''));
    if ($productId <= 0 || !array_key_exists($type, inventarioTipos()) || $quantity <= 0) {
        throw new InventarioValidationException('El movimiento de inventario no es válido.', [
            'cantidad' => 'Indique una cantidad mayor que cero.',
        ]);
    }
    if ($date === null) {
        throw new InventarioValidationException('La fecha del movimiento no es válida.', [
            'fecha' => 'Seleccione una fecha válida.',
        ]);
    }
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
        throw new InventarioValidationException('Explique el motivo del movimiento.', [
            'motivo' => 'El motivo debe tener entre 3 y 200 caracteres.',
        ]);
    }
    $statement = $pdo->prepare(
        'INSERT INTO inventario_movimientos
            (tenant_id, producto_id, fecha, tipo, cantidad, motivo, documento_id, created_by)
         VALUES (:tenant_id, :producto_id, :fecha, :tipo, :cantidad, :motivo, :documento_id, :created_by)'
    );
    $statement->execute([
        'tenant_id' => $tenantId,
        'producto_id' => $productId,
        'fecha' => $date,
        'tipo' => $type,
        'cantidad' => number_format($quantity / 10000, 4, '.', ''),
        'motivo' => $reason,
        'documento_id' => $data['documento_id'] ?? null,
        'created_by' => $userId,
    ]);
    return (int) $pdo->lastInsertId();
}

/**
 * Registra un movimiento manual. Las salidas y ajustes negativos manuales no
 * pueden dejar el stock en negativo; las ventas automáticas sí pueden hacerlo.
 *
 * @return array{id:int,stock_actual:float,message:string}
 */
function inventarioCreateMovement(array $user, array $input): array
{
    $tenantId = (int) $user['tenant_id'];
    $pdo = db();
    inventarioEnsureTenant($tenantId);

    $date = inventarioDate($input['fecha'] ?? '');
    $type = strtoupper(trim((string) ($input['tipo'] ?? '')));
    $productId = filter_var($input['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $quantity = inventarioParseQuantity($input['cantidad'] ?? '');
    $reason = trim((string) ($input['motivo'] ?? ''));
    $errors = [];

    if ($date === null) {
        $errors['fecha'] = 'Seleccione una fecha válida.';
    } else {
        $today = inventarioToday($tenantId);
        if ($date > $today) {
            $errors['fecha'] = 'La fecha del movimiento no puede ser futura.';
        }
        $limit = (new DateTimeImmutable($today))->modify('-3 years')->format('Y-m-d');
        if ($date < $limit) {
            $errors['fecha'] = 'La fecha no puede ser anterior a ' . $limit . '.';
        }
    }
    if (!array_key_exists($type, inventarioTipos())) {
        $errors['tipo'] = 'Seleccione un tipo de movimiento válido.';
    }
    if ($productId === false || $productId === null) {
        $errors['producto_id'] = 'Seleccione un producto con control de existencias.';
    }
    if ($quantity === null || $quantity <= 0) {
        $errors['cantidad'] = 'Ingrese una cantidad mayor que cero, con hasta cuatro decimales.';
    }
    if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
        $errors['motivo'] = 'El motivo debe tener entre 3 y 200 caracteres.';
    }
    if ($errors !== []) {
        throw new InventarioValidationException('Revise los datos del movimiento.', $errors);
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT id, codigo, descripcion, control_stock FROM productos
             WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => (int) $productId]);
        $product = $statement->fetch();
        if ($product === false) {
            throw new InventarioValidationException('El producto no existe para esta empresa.', [
                'producto_id' => 'Seleccione un producto de la empresa.',
            ]);
        }
        if ((int) $product['control_stock'] !== 1) {
            throw new InventarioValidationException('El producto no tiene control de existencias.', [
                'producto_id' => 'Active el control de existencias en el producto.',
            ]);
        }
        $current = inventarioStockActual($pdo, $tenantId, (int) $productId);
        $currentUnits = (int) round($current * 10000);
        $signed = inventarioTipoSigno($type) * $quantity;
        if ($signed < 0 && $currentUnits + $signed < 0) {
            throw new InventarioValidationException('La salida supera las existencias disponibles.', [
                'cantidad' => 'Disponible: ' . inventarioFormatQuantity($currentUnits) . '.',
            ]);
        }
        $movementId = inventarioInsertMovement($pdo, $tenantId, (int) $user['id'], [
            'producto_id' => (int) $productId,
            'fecha' => $date,
            'tipo' => $type,
            'cantidad' => $quantity,
            'motivo' => $reason,
            'documento_id' => null,
        ]);
        $newStock = ($currentUnits + $signed) / 10000;
        inventarioAudit(
            $tenantId,
            (int) $user['id'],
            'Movimiento de inventario ' . inventarioTipos()[$type] . ' de ' . inventarioFormatQuantity($quantity)
            . ' en ' . $product['codigo'] . ': ' . $reason
        );
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'id' => $movementId,
        'stock_actual' => $newStock,
        'message' => 'Movimiento de inventario registrado correctamente.',
    ];
}

function inventarioFormatQuantity(int $tenThousandths): string
{
    $value = $tenThousandths / 10000;
    $formatted = number_format($value, 4, '.', '');
    return rtrim(rtrim($formatted, '0'), '.') ?: '0';
}

/**
 * @return array{items:array,pagination:array,total_entradas:float,total_salidas:float}
 */
function inventarioMovements(int $tenantId, array $filters, int $page = 1, int $perPage = 25): array
{
    $where = ['m.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    $from = inventarioDate($filters['fecha_desde'] ?? '');
    $to = inventarioDate($filters['fecha_hasta'] ?? '');
    if ($from !== null) {
        $where[] = 'm.fecha >= :fecha_desde';
        $params['fecha_desde'] = $from;
    }
    if ($to !== null) {
        $where[] = 'm.fecha <= :fecha_hasta';
        $params['fecha_hasta'] = $to;
    }
    $type = strtoupper((string) ($filters['tipo'] ?? ''));
    if (array_key_exists($type, inventarioTipos())) {
        $where[] = 'm.tipo = :tipo';
        $params['tipo'] = $type;
    }
    $productId = filter_var($filters['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($productId !== false && $productId !== null) {
        $where[] = 'm.producto_id = :producto_id';
        $params['producto_id'] = (int) $productId;
    }
    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = '(m.motivo LIKE :q OR p.codigo LIKE :q OR p.descripcion LIKE :q OR d.numero_control LIKE :q)';
        $params['q'] = '%' . mb_substr($query, 0, 80) . '%';
    }
    $whereSql = implode(' AND ', $where);
    $join = 'INNER JOIN productos p ON p.tenant_id = m.tenant_id AND p.id = m.producto_id
             INNER JOIN mh_unidades_medida u ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
             LEFT JOIN documentos d ON d.tenant_id = m.tenant_id AND d.id = m.documento_id
             LEFT JOIN users us ON us.id = m.created_by';

    $count = db()->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN m.tipo IN ('ENTRADA', 'AJUSTE_POSITIVO') THEN m.cantidad ELSE 0 END), 0) AS entradas,
                COALESCE(SUM(CASE WHEN m.tipo IN ('SALIDA', 'AJUSTE_NEGATIVO') THEN m.cantidad ELSE 0 END), 0) AS salidas
         FROM inventario_movimientos m " . $join . ' WHERE ' . $whereSql
    );
    $count->execute($params);
    $totals = $count->fetch() ?: ['total' => 0, 'entradas' => 0, 'salidas' => 0];

    $page = max(1, $page);
    $perPage = max(1, min(500, $perPage));
    $offset = ($page - 1) * $perPage;
    $statement = db()->prepare(
        'SELECT m.id, m.fecha, m.tipo, m.cantidad, m.motivo, m.documento_id, m.created_at,
                p.id AS producto_id, p.codigo AS producto_codigo, p.descripcion AS producto_descripcion,
                u.codigo AS unidad_codigo, d.numero_control, d.tipo_dte,
                us.nombre AS usuario
         FROM inventario_movimientos m ' . $join . '
         WHERE ' . $whereSql . '
         ORDER BY m.fecha DESC, m.id DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $statement->execute($params);
    $items = $statement->fetchAll();
    foreach ($items as &$item) {
        $quantity = (float) $item['cantidad'];
        $item['cantidad'] = $quantity;
        $item['cantidad_firmada'] = inventarioTipoSigno((string) $item['tipo']) * $quantity;
        $item['tipo_label'] = inventarioTipos()[$item['tipo']] ?? $item['tipo'];
    }
    unset($item);

    $total = (int) ($totals['total'] ?? 0);
    return [
        'items' => $items,
        'total_entradas' => round((float) ($totals['entradas'] ?? 0), 4),
        'total_salidas' => round((float) ($totals['salidas'] ?? 0), 4),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ],
    ];
}

/**
 * @return array{items:array,total_unidades:float,negativos:int,agotados:int}
 */
function inventarioExistencias(int $tenantId, array $filters = []): array
{
    $where = ['p.tenant_id = :tenant_id', 'p.control_stock = 1'];
    $params = ['tenant_id' => $tenantId];
    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = '(p.codigo LIKE :q OR p.descripcion LIKE :q)';
        $params['q'] = '%' . mb_substr($query, 0, 80) . '%';
    }
    $statement = db()->prepare(
        'SELECT p.id, p.codigo, p.descripcion, p.tipo, p.stock AS stock_base,
                ' . inventarioStockExpression('p') . ' AS stock_actual,
                u.codigo AS unidad_codigo, u.nombre AS unidad_nombre,
                (SELECT MAX(m.fecha) FROM inventario_movimientos m
                 WHERE m.tenant_id = p.tenant_id AND m.producto_id = p.id) AS ultimo_movimiento
         FROM productos p
         INNER JOIN mh_unidades_medida u ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY p.descripcion, p.codigo LIMIT 1001'
    );
    $statement->execute($params);
    $rows = $statement->fetchAll();
    $truncated = count($rows) > 1000;
    if ($truncated) {
        $rows = array_slice($rows, 0, 1000);
    }
    $statusFilter = strtoupper((string) ($filters['estado'] ?? ''));
    $items = [];
    $totalUnits = 0.0;
    $negative = 0;
    $empty = 0;
    foreach ($rows as $row) {
        $stock = round((float) $row['stock_actual'], 4);
        $row['stock_actual'] = $stock;
        $row['stock_base'] = round((float) $row['stock_base'], 4);
        $row['estado'] = $stock < 0 ? 'NEGATIVO' : ($stock == 0.0 ? 'AGOTADO' : 'DISPONIBLE');
        $totalUnits += $stock;
        if ($row['estado'] === 'NEGATIVO') {
            $negative++;
        } elseif ($row['estado'] === 'AGOTADO') {
            $empty++;
        }
        if ($statusFilter !== '' && $row['estado'] !== $statusFilter) {
            continue;
        }
        $items[] = $row;
    }
    return [
        'items' => $items,
        'total_unidades' => round($totalUnits, 4),
        'negativos' => $negative,
        'agotados' => $empty,
        'truncated' => $truncated,
    ];
}

/**
 * @return array{producto:array,saldo_inicial:float,rows:array,total_entradas:float,total_salidas:float,saldo_final:float}
 */
function inventarioKardex(int $tenantId, int $productId, string $from, string $to): array
{
    $product = inventarioProduct($tenantId, $productId);
    if ($product === null) {
        throw new RuntimeException('El producto no existe para esta empresa.');
    }
    if ((int) $product['control_stock'] !== 1) {
        throw new RuntimeException('El producto no tiene control de existencias.');
    }
    $opening = db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN m.tipo IN ('ENTRADA', 'AJUSTE_POSITIVO') THEN m.cantidad ELSE -m.cantidad END), 0)
         FROM inventario_movimientos m
         WHERE m.tenant_id = :tenant_id AND m.producto_id = :producto_id AND m.fecha < :desde"
    );
    $opening->execute(['tenant_id' => $tenantId, 'producto_id' => $productId, 'desde' => $from]);
    $base = (int) round((float) $product['stock'] * 10000);
    $balance = $base + (int) round((float) $opening->fetchColumn() * 10000);
    $openingBalance = $balance / 10000;

    $statement = db()->prepare(
        'SELECT m.id, m.fecha, m.tipo, m.cantidad, m.motivo, d.numero_control, d.tipo_dte,
                us.nombre AS usuario
         FROM inventario_movimientos m
         LEFT JOIN documentos d ON d.tenant_id = m.tenant_id AND d.id = m.documento_id
         LEFT JOIN users us ON us.id = m.created_by
         WHERE m.tenant_id = :tenant_id AND m.producto_id = :producto_id
           AND m.fecha BETWEEN :desde AND :hasta
         ORDER BY m.fecha, m.id'
    );
    $statement->execute([
        'tenant_id' => $tenantId, 'producto_id' => $productId, 'desde' => $from, 'hasta' => $to,
    ]);
    $rows = [];
    $entries = 0;
    $exits = 0;
    foreach ($statement->fetchAll() as $row) {
        $quantity = (int) round((float) $row['cantidad'] * 10000);
        $signed = inventarioTipoSigno((string) $row['tipo']) * $quantity;
        if ($signed > 0) {
            $entries += $quantity;
        } else {
            $exits += $quantity;
        }
        $balance += $signed;
        $row['cantidad'] = $quantity / 10000;
        $row['cantidad_firmada'] = $signed / 10000;
        $row['tipo_label'] = inventarioTipos()[$row['tipo']] ?? $row['tipo'];
        $row['saldo'] = $balance / 10000;
        $rows[] = $row;
    }
    return [
        'producto' => $product,
        'saldo_inicial' => $openingBalance,
        'rows' => $rows,
        'total_entradas' => $entries / 10000,
        'total_salidas' => $exits / 10000,
        'saldo_final' => $balance / 10000,
    ];
}

function inventarioResumen(int $tenantId): array
{
    $config = inventarioConfig($tenantId);
    $stock = inventarioExistencias($tenantId);
    $timezone = inventarioTimezone($tenantId);
    $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $monthStart = $today->modify('first day of this month')->format('Y-m-d');
    $monthEnd = $today->format('Y-m-d');
    $movements = inventarioMovements($tenantId, [
        'fecha_desde' => $monthStart, 'fecha_hasta' => $monthEnd,
    ], 1, 5);
    $monthCount = db()->prepare(
        'SELECT COUNT(*) FROM inventario_movimientos WHERE tenant_id = :tenant_id AND fecha BETWEEN :desde AND :hasta'
    );
    $monthCount->execute(['tenant_id' => $tenantId, 'desde' => $monthStart, 'hasta' => $monthEnd]);
    return [
        'fecha_inicio' => $config['fecha_inicio'],
        'periodo' => ['desde' => $monthStart, 'hasta' => $monthEnd],
        'productos_controlados' => count($stock['items']),
        'unidades' => $stock['total_unidades'],
        'negativos' => $stock['negativos'],
        'agotados' => $stock['agotados'],
        'movimientos_mes' => (int) $monthCount->fetchColumn(),
        'pendientes' => inventarioPendingDocuments($tenantId),
        'recientes' => $movements['items'],
    ];
}

/**
 * Documentos procesados o invalidados desde el inicio del inventario que aún
 * no tienen su salida o su reversión. Permite avisar y reparar con el botón
 * de reconciliación.
 */
function inventarioPendingDocuments(int $tenantId): int
{
    $config = inventarioConfig($tenantId);
    $statement = db()->prepare(
        "SELECT COUNT(*) AS total FROM (
            SELECT DISTINCT d.id
            FROM documentos d
            INNER JOIN documento_items i ON i.tenant_id = d.tenant_id AND i.documento_id = d.id
            INNER JOIN productos p ON p.tenant_id = i.tenant_id AND p.id = i.producto_id
            LEFT JOIN inventario_movimientos m
                ON m.tenant_id = d.tenant_id AND m.documento_id = d.id
               AND m.producto_id = i.producto_id AND m.tipo = 'SALIDA'
            WHERE d.tenant_id = :tenant_sale
              AND d.estado IN ('PROCESADA', 'INVALIDADA')
              AND d.tipo_dte IN ('01', '03', '06', '11', 'IN')
              AND d.procesado_at IS NOT NULL
              AND DATE(d.procesado_at) >= :inicio_sale
              AND i.producto_id IS NOT NULL
              AND p.control_stock = 1
              AND m.id IS NULL
            UNION
            SELECT DISTINCT d.id
            FROM documentos d
            INNER JOIN inventario_movimientos m
                ON m.tenant_id = d.tenant_id AND m.documento_id = d.id AND m.tipo = 'SALIDA'
            LEFT JOIN inventario_movimientos r
                ON r.tenant_id = d.tenant_id AND r.documento_id = d.id
               AND r.producto_id = m.producto_id AND r.tipo = 'ENTRADA'
            WHERE d.tenant_id = :tenant_rev
              AND d.estado = 'INVALIDADA'
              AND d.procesado_at IS NOT NULL
              AND DATE(d.procesado_at) >= :inicio_rev
              AND r.id IS NULL
        ) pendientes"
    );
    $statement->execute([
        'tenant_sale' => $tenantId,
        'inicio_sale' => $config['fecha_inicio'],
        'tenant_rev' => $tenantId,
        'inicio_rev' => $config['fecha_inicio'],
    ]);
    return (int) $statement->fetchColumn();
}

/* -------------------------------------------------------------------------
 * Movimientos automáticos de ventas
 * ---------------------------------------------------------------------- */

function inventarioDocumentRow(PDO $pdo, int $tenantId, int $documentId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, tipo_dte, estado, numero_control, fecha_emision, procesado_at, invalidado_at, created_by
         FROM documentos WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $documentId]);
    $document = $statement->fetch();
    return $document === false ? null : $document;
}

function inventarioDocumentHasOutflow(string $documentType): bool
{
    return in_array($documentType, ['01', '03', '06', '11', 'IN'], true);
}

/**
 * Descuenta del inventario las líneas de un DTE procesado. Solo aplica a
 * productos con control de existencias y a los tipos de venta.
 * Es idempotente por documento, producto y tipo.
 */
function inventarioRegistrarSalidaDocumento(int $tenantId, int $documentId, ?int $userId = null): int
{
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        inventarioEnsureTenant($tenantId);
        $document = inventarioDocumentRow($pdo, $tenantId, $documentId);
        if ($document === null) {
            throw new RuntimeException('El documento no existe para esta empresa.');
        }
        if (!in_array($document['estado'], ['PROCESADA', 'INVALIDADA'], true)
            || !inventarioDocumentHasOutflow((string) $document['tipo_dte'])) {
            if ($owns) {
                $pdo->commit();
            }
            return 0;
        }
        $statement = $pdo->prepare(
            'SELECT i.producto_id, SUM(i.cantidad) AS cantidad
             FROM documento_items i
             INNER JOIN productos p ON p.tenant_id = i.tenant_id AND p.id = i.producto_id
             WHERE i.tenant_id = :tenant_id AND i.documento_id = :documento_id
               AND i.producto_id IS NOT NULL AND p.control_stock = 1
             GROUP BY i.producto_id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId]);
        $items = $statement->fetchAll();
        $reference = (string) ($document['numero_control'] ?: ('#' . $documentId));
        $created = 0;
        foreach ($items as $item) {
            $quantity = inventarioQuantityFromDocument($item['cantidad']);
            if ($quantity <= 0) {
                continue;
            }
            $check = $pdo->prepare(
                "SELECT id FROM inventario_movimientos
                 WHERE tenant_id = :tenant_id AND documento_id = :documento_id
                   AND producto_id = :producto_id AND tipo = 'SALIDA' LIMIT 1"
            );
            $check->execute([
                'tenant_id' => $tenantId, 'documento_id' => $documentId, 'producto_id' => (int) $item['producto_id'],
            ]);
            if ($check->fetchColumn() !== false) {
                continue;
            }
            inventarioInsertMovement($pdo, $tenantId, $userId ?? (int) $document['created_by'], [
                'producto_id' => (int) $item['producto_id'],
                'fecha' => (string) $document['fecha_emision'],
                'tipo' => 'SALIDA',
                'cantidad' => $quantity,
                'motivo' => 'Venta DTE ' . $reference,
                'documento_id' => $documentId,
            ]);
            $created++;
        }
        if ($owns) {
            $pdo->commit();
        }
        return $created;
    } catch (Throwable $exception) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function inventarioInvalidationDate(array $document, int $tenantId): string
{
    $original = inventarioDate($document['fecha_emision'] ?? '') ?? inventarioToday($tenantId);
    if (empty($document['invalidado_at'])) {
        return inventarioToday($tenantId);
    }
    try {
        $date = (new DateTimeImmutable((string) $document['invalidado_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(inventarioTimezone($tenantId)))
            ->format('Y-m-d');
        return $date >= $original ? $date : $original;
    } catch (Throwable) {
        return $original;
    }
}

/**
 * Devuelve al inventario las salidas de un DTE invalidado. Es idempotente.
 */
function inventarioRegistrarReversionDocumento(int $tenantId, int $documentId, ?int $userId = null): int
{
    $pdo = db();
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }
    try {
        inventarioEnsureTenant($tenantId);
        $document = inventarioDocumentRow($pdo, $tenantId, $documentId);
        if ($document === null || $document['estado'] !== 'INVALIDADA') {
            if ($owns) {
                $pdo->commit();
            }
            return 0;
        }
        $statement = $pdo->prepare(
            "SELECT producto_id, cantidad FROM inventario_movimientos
             WHERE tenant_id = :tenant_id AND documento_id = :documento_id AND tipo = 'SALIDA'"
        );
        $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId]);
        $outflows = $statement->fetchAll();
        $reference = (string) ($document['numero_control'] ?: ('#' . $documentId));
        $date = inventarioInvalidationDate($document, $tenantId);
        $created = 0;
        foreach ($outflows as $outflow) {
            $check = $pdo->prepare(
                "SELECT id FROM inventario_movimientos
                 WHERE tenant_id = :tenant_id AND documento_id = :documento_id
                   AND producto_id = :producto_id AND tipo = 'ENTRADA' LIMIT 1"
            );
            $check->execute([
                'tenant_id' => $tenantId, 'documento_id' => $documentId, 'producto_id' => (int) $outflow['producto_id'],
            ]);
            if ($check->fetchColumn() !== false) {
                continue;
            }
            inventarioInsertMovement($pdo, $tenantId, $userId ?? (int) $document['created_by'], [
                'producto_id' => (int) $outflow['producto_id'],
                'fecha' => $date,
                'tipo' => 'ENTRADA',
                'cantidad' => (int) round((float) $outflow['cantidad'] * 10000),
                'motivo' => 'Reversión por invalidación DTE ' . $reference,
                'documento_id' => $documentId,
            ]);
            $created++;
        }
        if ($owns) {
            $pdo->commit();
        }
        return $created;
    } catch (Throwable $exception) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Reintenta los movimientos automáticos de los DTE procesados desde la fecha
 * de inicio del inventario. No reconstruye ventas anteriores a esa fecha.
 *
 * @return array{documentos:int,movimientos:int}
 */
function inventarioSincronizar(int $tenantId, ?int $userId): array
{
    $pdo = db();
    $config = inventarioConfig($tenantId);
    $pdo->beginTransaction();
    try {
        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM inventario_movimientos WHERE tenant_id = ' . $tenantId
        )->fetchColumn();
        $statement = $pdo->prepare(
            "SELECT id, estado FROM documentos
             WHERE tenant_id = :tenant_id AND estado IN ('PROCESADA', 'INVALIDADA')
               AND procesado_at IS NOT NULL AND DATE(procesado_at) >= :inicio
             ORDER BY id"
        );
        $statement->execute(['tenant_id' => $tenantId, 'inicio' => $config['fecha_inicio']]);
        $documents = $statement->fetchAll();
        foreach ($documents as $document) {
            inventarioRegistrarSalidaDocumento($tenantId, (int) $document['id'], $userId);
            if ($document['estado'] === 'INVALIDADA') {
                inventarioRegistrarReversionDocumento($tenantId, (int) $document['id'], $userId);
            }
        }
        $after = (int) $pdo->query(
            'SELECT COUNT(*) FROM inventario_movimientos WHERE tenant_id = ' . $tenantId
        )->fetchColumn();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    return ['documentos' => count($documents), 'movimientos' => $after - $before];
}

/* -------------------------------------------------------------------------
 * Exportación CSV
 * ---------------------------------------------------------------------- */

function inventarioCsvSafe(mixed $value): string
{
    $text = (string) $value;
    return preg_match('/^\s*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
}

/**
 * @return array{filename:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
 */
function inventarioCsv(int $tenantId, string $report, array $filters): array
{
    $quantity = static fn(float $value): string => number_format($value, 4, '.', '');
    if ($report === 'kardex') {
        $productId = filter_var($filters['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($productId === false || $productId === null) {
            throw new RuntimeException('Seleccione el producto del kardex.');
        }
        $range = inventarioRange($filters, inventarioTimezone($tenantId));
        $book = inventarioKardex($tenantId, (int) $productId, $range['desde'], $range['hasta']);
        $rows = [];
        foreach ($book['rows'] as $row) {
            $rows[] = [$row['fecha'], $row['tipo_label'], $quantity((float) $row['cantidad_firmada']),
                $row['motivo'], $row['numero_control'] ?: '', $quantity((float) $row['saldo'])];
        }
        return [
            'filename' => 'kardex_' . $book['producto']['codigo'] . '_' . $range['desde'] . '_' . $range['hasta'],
            'headers' => ['Fecha', 'Tipo', 'Cantidad', 'Motivo', 'Documento', 'Saldo'],
            'rows' => $rows,
        ];
    }
    if ($report === 'movimientos') {
        $list = inventarioMovements($tenantId, $filters, 1, 500);
        $rows = [];
        foreach ($list['items'] as $row) {
            $rows[] = [$row['fecha'], $row['producto_codigo'], $row['producto_descripcion'], $row['tipo_label'],
                $quantity((float) $row['cantidad_firmada']), $row['motivo'], $row['numero_control'] ?: '',
                $row['usuario'] ?: 'Sistema'];
        }
        return [
            'filename' => 'movimientos_inventario',
            'headers' => ['Fecha', 'Código', 'Producto', 'Tipo', 'Cantidad', 'Motivo', 'Documento', 'Usuario'],
            'rows' => $rows,
        ];
    }
    $stock = inventarioExistencias($tenantId, $filters);
    $rows = [];
    foreach ($stock['items'] as $row) {
        $rows[] = [$row['codigo'], $row['descripcion'], $row['unidad_codigo'], $quantity((float) $row['stock_actual']),
            $row['estado'], $row['ultimo_movimiento'] ?: ''];
    }
    return [
        'filename' => 'existencias_inventario',
        'headers' => ['Código', 'Producto', 'Unidad', 'Existencia', 'Estado', 'Último movimiento'],
        'rows' => $rows,
    ];
}
