<?php

declare(strict_types=1);

require_once __DIR__ . '/documentos.php';

/**
 * Dashboard y reportes de ventas (módulo 7).
 *
 * Los totales de venta consideran únicamente documentos en estado PROCESADA:
 * los borradores, emitidos sin procesar, rechazados e invalidados no forman
 * parte de las ventas, pero se informan por separado.
 */

function reportTimezone(int $tenantId): string
{
    $statement = db()->prepare('SELECT timezone FROM tenant_config WHERE tenant_id = :tenant_id');
    $statement->execute(['tenant_id' => $tenantId]);
    $timezone = $statement->fetchColumn();
    if (!is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
        return 'America/El_Salvador';
    }
    return $timezone;
}

function reportDate(string $value): ?string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
    return $date !== false && $date->format('Y-m-d') === trim($value) ? $date->format('Y-m-d') : null;
}

/**
 * Las notas de crédito (05) restan de las ventas; el resto de DTE suma.
 * Así los reportes no inflan las ventas cuando se emiten notas.
 */
function reportSignExpression(string $alias = ''): string
{
    $prefix = $alias === '' ? '' : $alias . '.';
    return "CASE {$prefix}tipo_dte WHEN '05' THEN -1 ELSE 1 END";
}

/**
 * Normaliza el rango solicitado. Por omisión devuelve el mes en curso.
 *
 * @return array{desde:string,hasta:string,mes:string}
 */
function reportRange(array $input, string $timezone): array
{
    $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $from = reportDate((string) ($input['fecha_desde'] ?? '')) ?? $today->modify('first day of this month')->format('Y-m-d');
    $to = reportDate((string) ($input['fecha_hasta'] ?? '')) ?? $today->format('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];
    $limit = (new DateTimeImmutable($to))->modify('-3 years')->format('Y-m-d');
    if ($from < $limit) $from = $limit;
    return ['desde' => $from, 'hasta' => $to, 'mes' => substr($to, 0, 7)];
}

function reportSummary(int $tenantId, string $from, string $to): array
{
    $pdo = db();
    $sign = reportSignExpression();
    $statement = $pdo->prepare(
        "SELECT COUNT(*) AS documentos,
                COALESCE(SUM(total_pagar * {$sign}), 0) AS total,
                COALESCE(SUM(total_gravada * {$sign}), 0) AS gravada,
                COALESCE(SUM(total_exenta * {$sign}), 0) AS exenta,
                COALESCE(SUM(total_no_suj * {$sign}), 0) AS no_suj,
                COALESCE(SUM(total_iva * {$sign}), 0) AS iva,
                COALESCE(SUM(total_descuento * {$sign}), 0) AS descuentos,
                COALESCE(SUM(iva_rete1 * {$sign}), 0) AS iva_retenido
         FROM documentos
         WHERE tenant_id = :tenant_id AND estado = 'PROCESADA' AND fecha_emision BETWEEN :desde AND :hasta"
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    $summary = $statement->fetch() ?: [];

    $invalidated = $pdo->prepare(
        "SELECT COUNT(*) AS documentos, COALESCE(SUM(total_pagar), 0) AS total FROM documentos
         WHERE tenant_id = :tenant_id AND estado = 'INVALIDADA' AND fecha_emision BETWEEN :desde AND :hasta"
    );
    $invalidated->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    $invalid = $invalidated->fetch() ?: [];
    $invalidatedMonth = $pdo->prepare(
        "SELECT COUNT(*) FROM documentos WHERE tenant_id = :tenant_id AND estado IN ('BORRADOR','EMITIDA','RECHAZADA')"
    );
    $invalidatedMonth->execute(['tenant_id' => $tenantId]);

    return [
        'documentos' => (int) ($summary['documentos'] ?? 0),
        'total' => (float) ($summary['total'] ?? 0),
        'gravada' => (float) ($summary['gravada'] ?? 0),
        'exenta' => (float) ($summary['exenta'] ?? 0),
        'no_suj' => (float) ($summary['no_suj'] ?? 0),
        'iva' => (float) ($summary['iva'] ?? 0),
        'descuentos' => (float) ($summary['descuentos'] ?? 0),
        'iva_retenido' => (float) ($summary['iva_retenido'] ?? 0),
        'invalidados' => (int) ($invalid['documentos'] ?? 0),
        'invalidados_total' => (float) ($invalid['total'] ?? 0),
        'pendientes' => (int) $invalidatedMonth->fetchColumn(),
    ];
}

function reportByClient(int $tenantId, string $from, string $to, int $limit = 100): array
{
    $sign = reportSignExpression('d');
    $statement = db()->prepare(
        "SELECT c.id, c.nombre, c.numero_documento, COUNT(d.id) AS documentos,
                COALESCE(SUM(d.total_gravada * {$sign}), 0) AS gravada,
                COALESCE(SUM(d.total_iva * {$sign}), 0) AS iva,
                COALESCE(SUM(d.total_pagar * {$sign}), 0) AS total
         FROM documentos d
         INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
         WHERE d.tenant_id = :tenant_id AND d.estado = 'PROCESADA' AND d.fecha_emision BETWEEN :desde AND :hasta
         GROUP BY c.id, c.nombre, c.numero_documento
         ORDER BY total DESC, c.nombre LIMIT " . max(1, min(500, $limit))
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    return $statement->fetchAll();
}

function reportByProduct(int $tenantId, string $from, string $to, int $limit = 100): array
{
    $sign = reportSignExpression('d');
    $statement = db()->prepare(
        "SELECT i.codigo, i.descripcion, COALESCE(SUM(i.cantidad * {$sign}), 0) AS cantidad,
                COALESCE(SUM((i.venta_gravada + i.venta_exenta + i.venta_no_suj + COALESCE(i.compra, 0)) * {$sign}), 0) AS base,
                COALESCE(SUM(i.iva_item * {$sign}), 0) AS iva,
                COALESCE(SUM((i.venta_gravada + i.venta_exenta + i.venta_no_suj + COALESCE(i.compra, 0) + i.iva_item) * {$sign}), 0) AS total,
                COUNT(DISTINCT d.id) AS documentos
         FROM documento_items i
         INNER JOIN documentos d ON d.tenant_id = i.tenant_id AND d.id = i.documento_id
         WHERE d.tenant_id = :tenant_id AND d.estado = 'PROCESADA' AND d.fecha_emision BETWEEN :desde AND :hasta
         GROUP BY i.codigo, i.descripcion
         ORDER BY total DESC LIMIT " . max(1, min(500, $limit))
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    return $statement->fetchAll();
}

function reportByDay(int $tenantId, string $from, string $to): array
{
    $sign = reportSignExpression();
    $statement = db()->prepare(
        "SELECT fecha_emision AS fecha, COUNT(*) AS documentos,
                COALESCE(SUM(total_pagar * {$sign}), 0) AS total,
                COALESCE(SUM(total_iva * {$sign}), 0) AS iva
         FROM documentos
         WHERE tenant_id = :tenant_id AND estado = 'PROCESADA' AND fecha_emision BETWEEN :desde AND :hasta
         GROUP BY fecha_emision ORDER BY fecha_emision"
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    return $statement->fetchAll();
}

function reportMonthlyIva(int $tenantId, string $from, string $to): array
{
    $sign = reportSignExpression();
    $statement = db()->prepare(
        "SELECT DATE_FORMAT(fecha_emision, '%Y-%m') AS periodo, COUNT(*) AS documentos,
                COALESCE(SUM(total_gravada * {$sign}), 0) AS gravada,
                COALESCE(SUM(total_iva * {$sign}), 0) AS iva,
                COALESCE(SUM(total_exenta * {$sign}), 0) AS exenta,
                COALESCE(SUM(total_no_suj * {$sign}), 0) AS no_suj,
                COALESCE(SUM(total_pagar * {$sign}), 0) AS total
         FROM documentos
         WHERE tenant_id = :tenant_id AND estado = 'PROCESADA' AND fecha_emision BETWEEN :desde AND :hasta
         GROUP BY periodo ORDER BY periodo"
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    return $statement->fetchAll();
}

/** Serie de los últimos seis meses, con los meses sin ventas en cero. */
function reportMonthlySeries(int $tenantId, string $timezone, int $months = 6): array
{
    $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $start = $today->modify('first day of this month')->modify('-' . ($months - 1) . ' months');
    $sign = reportSignExpression();
    $statement = db()->prepare(
        "SELECT DATE_FORMAT(fecha_emision, '%Y-%m') AS periodo, COUNT(*) AS documentos,
                COALESCE(SUM(total_pagar * {$sign}), 0) AS total
         FROM documentos
         WHERE tenant_id = :tenant_id AND estado = 'PROCESADA' AND fecha_emision >= :desde
         GROUP BY periodo"
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $start->format('Y-m-d')]);
    $found = [];
    foreach ($statement->fetchAll() as $row) $found[$row['periodo']] = $row;
    $monthsEs = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun',
        '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
    $series = [];
    for ($index = 0; $index < $months; $index++) {
        $month = $start->modify('+' . $index . ' months');
        $key = $month->format('Y-m');
        $series[] = [
            'periodo' => $key,
            'etiqueta' => $monthsEs[$month->format('m')] . ' ' . $month->format('y'),
            'documentos' => (int) ($found[$key]['documentos'] ?? 0),
            'total' => (float) ($found[$key]['total'] ?? 0),
        ];
    }
    return $series;
}

function dashboardMetrics(int $tenantId): array
{
    $timezone = reportTimezone($tenantId);
    $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $monthStart = $today->modify('first day of this month')->format('Y-m-d');
    $monthEnd = $today->format('Y-m-d');
    $previousStart = $today->modify('first day of previous month')->format('Y-m-d');
    $previousEnd = $today->modify('last day of previous month')->format('Y-m-d');

    $current = reportSummary($tenantId, $monthStart, $monthEnd);
    $previous = reportSummary($tenantId, $previousStart, $previousEnd);

    $recent = db()->prepare(
        "SELECT d.id, d.tipo_dte, d.numero_control, d.fecha_emision, d.estado, d.total_pagar,
                c.nombre AS cliente_nombre
         FROM documentos d INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
         WHERE d.tenant_id = :tenant_id ORDER BY d.fecha_emision DESC, d.id DESC LIMIT 5"
    );
    $recent->execute(['tenant_id' => $tenantId]);

    return [
        'timezone' => $timezone,
        'periodo' => ['desde' => $monthStart, 'hasta' => $monthEnd],
        'ingresos' => $current['total'],
        'ingresos_anterior' => $previous['total'],
        'documentos' => $current['documentos'],
        'documentos_anterior' => $previous['documentos'],
        'iva' => $current['iva'],
        'iva_anterior' => $previous['iva'],
        'pendientes' => $current['pendientes'],
        'invalidados' => $current['invalidados'],
        'clientes' => count(reportByClient($tenantId, $monthStart, $monthEnd, 5)),
        'top_clientes' => reportByClient($tenantId, $monthStart, $monthEnd, 5),
        'serie' => reportMonthlySeries($tenantId, $timezone, 6),
        'recientes' => $recent->fetchAll(),
    ];
}

function reportCsvSafe(mixed $value): string
{
    $value = (string) $value;
    return preg_match('/^[\s]*[=+\-@]/', $value) === 1 ? "'" . $value : $value;
}

/**
 * Datos de exportación CSV para cada reporte.
 *
 * @return array{filename:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
 */
function reportCsvData(int $tenantId, string $report, string $from, string $to): array
{
    $money = static fn(float $value): string => number_format($value, 2, '.', '');
    switch ($report) {
        case 'clientes':
            $rows = [];
            foreach (reportByClient($tenantId, $from, $to, 500) as $row) {
                $rows[] = [$row['nombre'], $row['numero_documento'] ?? 'Sin documento', $row['documentos'],
                    $money((float) $row['gravada']), $money((float) $row['iva']), $money((float) $row['total'])];
            }
            return ['filename' => 'ventas_por_cliente', 'headers' => [
                'Cliente', 'Documento', 'Documentos', 'Ventas gravadas', 'IVA', 'Total'], 'rows' => $rows];
        case 'productos':
            $rows = [];
            foreach (reportByProduct($tenantId, $from, $to, 500) as $row) {
                $rows[] = [$row['codigo'], $row['descripcion'], $money((float) $row['cantidad']),
                    $money((float) $row['base']), $money((float) $row['iva']), $money((float) $row['total'])];
            }
            return ['filename' => 'ventas_por_producto', 'headers' => [
                'Código', 'Descripción', 'Cantidad', 'Ventas sin IVA', 'IVA', 'Total'], 'rows' => $rows];
        case 'iva':
            $rows = [];
            foreach (reportMonthlyIva($tenantId, $from, $to) as $row) {
                $rows[] = [$row['periodo'], $row['documentos'], $money((float) $row['gravada']),
                    $money((float) $row['iva']), $money((float) $row['exenta']),
                    $money((float) $row['no_suj']), $money((float) $row['total'])];
            }
            return ['filename' => 'resumen_iva', 'headers' => [
                'Periodo', 'Documentos', 'Ventas gravadas', 'IVA débito fiscal',
                'Ventas exentas', 'Ventas no sujetas', 'Total'], 'rows' => $rows];
        case 'diario':
            $rows = [];
            foreach (reportByDay($tenantId, $from, $to) as $row) {
                $rows[] = [$row['fecha'], $row['documentos'], $money((float) $row['total']), $money((float) $row['iva'])];
            }
            return ['filename' => 'ventas_por_dia', 'headers' => [
                'Fecha', 'Documentos', 'Ventas', 'IVA'], 'rows' => $rows];
        case 'resumen':
        default:
            $summary = reportSummary($tenantId, $from, $to);
            $rows = [
                ['Documentos procesados', $summary['documentos']],
                ['Ventas gravadas', $money($summary['gravada'])],
                ['Ventas exentas', $money($summary['exenta'])],
                ['Ventas no sujetas', $money($summary['no_suj'])],
                ['Descuentos', $money($summary['descuentos'])],
                ['IVA débito fiscal', $money($summary['iva'])],
                ['IVA retenido (1 %)', $money($summary['iva_retenido'])],
                ['Total vendido', $money($summary['total'])],
                ['Documentos invalidados', $summary['invalidados']],
                ['Total invalidado', $money($summary['invalidados_total'])],
                ['Documentos pendientes', $summary['pendientes']],
            ];
            return ['filename' => 'resumen_ventas', 'headers' => ['Concepto', 'Valor'], 'rows' => $rows];
    }
}
