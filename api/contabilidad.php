<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/contabilidad.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}
if (!in_array($user['rol'], ['ADMIN', 'CONTADOR'], true)) {
    jsonResponse(['success' => false, 'message' => 'Su rol no tiene acceso al módulo de contabilidad.'], 403);
}

$tenantId = (int) $user['tenant_id'];
$isAdmin = $user['rol'] === 'ADMIN';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function contabilidadRequestId(array $source, string $message = 'El asiento solicitado no es válido.'): int
{
    $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => $message], 400);
    }
    return (int) $id;
}

function contabilidadRequestAccount(array $source): int
{
    $id = filter_var($source['cuenta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'Seleccione una cuenta contable válida.'], 400);
    }
    return (int) $id;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'resumen';
    try {
        if ($action === 'resumen') {
            jsonResponse(['success' => true] + contabilidadResumen($tenantId));
        }
        if ($action === 'asientos') {
            $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
            $perPage = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 25;
            jsonResponse(['success' => true] + contabilidadEntries($tenantId, $_GET, (int) $page, (int) $perPage));
        }
        if ($action === 'asiento') {
            $entry = contabilidadEntry($tenantId, contabilidadRequestId($_GET));
            if ($entry === null) {
                jsonResponse(['success' => false, 'message' => 'El asiento contable no existe para esta empresa.'], 404);
            }
            jsonResponse(['success' => true, 'asiento' => $entry]);
        }
        if ($action === 'cuentas') {
            jsonResponse([
                'success' => true,
                'items' => contabilidadAccounts($tenantId),
                'tipos' => contabilidadAccountTypes(),
                'naturalezas' => contabilidadNatures(),
                'puede_gestionar' => $isAdmin,
            ]);
        }
        if ($action === 'config') {
            jsonResponse([
                'success' => true,
                'config' => contabilidadConfig($tenantId),
                'mapping' => contabilidadMapping(),
                'cuentas' => contabilidadAccounts($tenantId),
                'puede_gestionar' => $isAdmin,
            ]);
        }
        if ($action === 'diario') {
            $range = contabilidadRange($_GET, contabilidadTimezone($tenantId));
            jsonResponse(['success' => true, 'range' => $range] + contabilidadDiario($tenantId, $range['desde'], $range['hasta']));
        }
        if ($action === 'mayor') {
            $range = contabilidadRange($_GET, contabilidadTimezone($tenantId));
            jsonResponse([
                'success' => true,
                'range' => $range,
            ] + contabilidadMayor($tenantId, contabilidadRequestAccount($_GET), $range['desde'], $range['hasta']));
        }
        if ($action === 'balanza') {
            $range = contabilidadRange($_GET, contabilidadTimezone($tenantId));
            jsonResponse(['success' => true, 'range' => $range] + contabilidadBalanza($tenantId, $range['desde'], $range['hasta']));
        }
        if ($action === 'resultados') {
            $range = contabilidadRange($_GET, contabilidadTimezone($tenantId));
            jsonResponse(['success' => true, 'range' => $range] + contabilidadEstadoResultados($tenantId, $range['desde'], $range['hasta']));
        }
        if ($action === 'balance') {
            $timezone = contabilidadTimezone($tenantId);
            $asOf = contabilidadDate($_GET['fecha_hasta'] ?? '') ?? (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
            jsonResponse(['success' => true] + contabilidadBalanceGeneral($tenantId, $asOf));
        }
        if ($action === 'export') {
            $report = (string) ($_GET['report'] ?? 'diario');
            if (!in_array($report, ['diario', 'mayor', 'balanza', 'resultados', 'balance', 'asientos'], true)) {
                jsonResponse(['success' => false, 'message' => 'El reporte solicitado no es válido.'], 400);
            }
            $csv = contabilidadCsv($tenantId, $report, $_GET);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $csv['filename'] . '.csv"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo "\xEF\xBB\xBF";
            $output = fopen('php://output', 'wb');
            fputcsv($output, $csv['headers'], ';');
            foreach ($csv['rows'] as $row) {
                fputcsv($output, array_map('contabilidadCsvSafe', $row), ';');
            }
            fclose($output);
            exit;
        }
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    } catch (ContabilidadValidationException $exception) {
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
    if (in_array($action, ['cuenta-save', 'cuenta-toggle', 'config-save'], true) && !$isAdmin) {
        jsonResponse(['success' => false, 'message' => 'Solo un administrador puede modificar el catálogo o la configuración contable.'], 403);
    }
    if ($action === 'asiento-create') {
        $result = contabilidadCreateManual($user, $_POST);
        jsonResponse(['success' => true] + $result);
    }
    if ($action === 'asiento-anular') {
        $message = contabilidadAnularEntry(
            $user,
            contabilidadRequestId($_POST),
            is_string($_POST['motivo'] ?? null) ? (string) $_POST['motivo'] : ''
        );
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'cuenta-save') {
        $result = contabilidadSaveAccount($user, $_POST);
        jsonResponse(['success' => true] + $result);
    }
    if ($action === 'cuenta-toggle') {
        $message = contabilidadToggleAccount(
            $user,
            contabilidadRequestId($_POST, 'La cuenta solicitada no es válida.'),
            ((string) ($_POST['activa'] ?? '0')) === '1'
        );
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'config-save') {
        $message = contabilidadSaveConfig($user, $_POST);
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'sincronizar') {
        $result = contabilidadSincronizar($tenantId, (int) $user['id']);
        jsonResponse([
            'success' => true,
            'message' => $result['asientos'] > 0
                ? 'Se generaron ' . $result['asientos'] . ' asientos contables.'
                : 'Todos los documentos ya estaban contabilizados.',
        ] + $result);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (ContabilidadValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
