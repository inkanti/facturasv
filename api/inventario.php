<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/inventario.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}
if (!in_array($user['rol'], ['ADMIN', 'VENDEDOR', 'CONTADOR'], true)) {
    jsonResponse(['success' => false, 'message' => 'Su rol no tiene acceso al inventario.'], 403);
}

$tenantId = (int) $user['tenant_id'];
$canWrite = in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function inventarioRequestProduct(array $source): int
{
    $id = filter_var($source['producto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'Seleccione un producto con control de existencias.'], 400);
    }
    return (int) $id;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'resumen';
    try {
        if ($action === 'resumen') {
            jsonResponse(['success' => true, 'tipos' => inventarioTipos()] + inventarioResumen($tenantId));
        }
        if ($action === 'existencias') {
            jsonResponse(['success' => true] + inventarioExistencias($tenantId, $_GET));
        }
        if ($action === 'movimientos') {
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $perPage = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 25;
            jsonResponse([
                'success' => true,
                'tipos' => inventarioTipos(),
            ] + inventarioMovements($tenantId, $_GET, (int) $page, (int) $perPage));
        }
        if ($action === 'kardex') {
            $range = inventarioRange($_GET, inventarioTimezone($tenantId));
            jsonResponse([
                'success' => true,
                'range' => $range,
            ] + inventarioKardex($tenantId, inventarioRequestProduct($_GET), $range['desde'], $range['hasta']));
        }
        if ($action === 'productos') {
            jsonResponse([
                'success' => true,
                'items' => inventarioProductOptions($tenantId),
                'tipos' => inventarioTipos(),
                'puede_escribir' => $canWrite,
            ]);
        }
        if ($action === 'export') {
            $report = (string) ($_GET['report'] ?? 'existencias');
            if (!in_array($report, ['existencias', 'movimientos', 'kardex'], true)) {
                jsonResponse(['success' => false, 'message' => 'El reporte solicitado no es válido.'], 400);
            }
            $csv = inventarioCsv($tenantId, $report, $_GET);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $csv['filename'] . '.csv"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            fputcsv($output, $csv['headers'], ';');
            foreach ($csv['rows'] as $row) {
                fputcsv($output, array_map('inventarioCsvSafe', $row), ';');
            }
            fclose($output);
            exit;
        }
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    } catch (InventarioValidationException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
    } catch (RuntimeException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

verifyCsrf();
$action = (string) ($_POST['action'] ?? '');

try {
    if ($action === 'movimiento-create') {
        if (!$canWrite) {
            jsonResponse(['success' => false, 'message' => 'Su rol solo permite consultar el inventario.'], 403);
        }
        $result = inventarioCreateMovement($user, $_POST);
        jsonResponse(['success' => true] + $result);
    }
    if ($action === 'sincronizar') {
        if ($user['rol'] !== 'ADMIN') {
            jsonResponse(['success' => false, 'message' => 'Solo un administrador puede reconciliar las ventas.'], 403);
        }
        $result = inventarioSincronizar($tenantId, (int) $user['id']);
        jsonResponse([
            'success' => true,
            'message' => $result['movimientos'] > 0
                ? 'Se generaron ' . $result['movimientos'] . ' movimientos de inventario.'
                : 'El inventario ya estaba al día.',
        ] + $result);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (InventarioValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
