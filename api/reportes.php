<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/reportes.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}
$tenantId = (int) $user['tenant_id'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

$action = $_GET['action'] ?? 'data';
try {
    if ($action === 'dashboard') {
        jsonResponse(['success' => true] + dashboardMetrics($tenantId));
    }
    if ($action === 'data') {
        $range = reportRange($_GET, reportTimezone($tenantId));
        jsonResponse([
            'success' => true,
            'range' => $range,
            'summary' => reportSummary($tenantId, $range['desde'], $range['hasta']),
            'by_client' => reportByClient($tenantId, $range['desde'], $range['hasta'], 100),
            'by_product' => reportByProduct($tenantId, $range['desde'], $range['hasta'], 100),
            'by_day' => reportByDay($tenantId, $range['desde'], $range['hasta']),
            'iva' => reportMonthlyIva($tenantId, $range['desde'], $range['hasta']),
        ]);
    }
    if ($action === 'export') {
        $report = (string) ($_GET['report'] ?? 'resumen');
        if (!in_array($report, ['resumen', 'clientes', 'productos', 'iva', 'diario'], true)) {
            jsonResponse(['success' => false, 'message' => 'El reporte solicitado no es válido.'], 400);
        }
        $range = reportRange($_GET, reportTimezone($tenantId));
        $csv = reportCsvData($tenantId, $report, $range['desde'], $range['hasta']);
        $filename = $csv['filename'] . '_' . $range['desde'] . '_' . $range['hasta'] . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'wb');
        fputcsv($output, $csv['headers'], ';');
        foreach ($csv['rows'] as $row) {
            fputcsv($output, array_map('reportCsvSafe', $row), ';');
        }
        fclose($output);
        exit;
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
}
