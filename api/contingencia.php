<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/contingencia.php';

$user = currentUser();
if ($user === null) jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
$tenantId = (int) $user['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function contingencyRequestId(array $source): int
{
    $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'El evento de contingencia solicitado no es válido.'], 400);
    }
    return $id;
}

function contingencyTransmissionMessage(array $detail): string
{
    $summary = $detail['transmision'] ?? ['total' => 0, 'procesados' => 0, 'errores' => []];
    $message = 'Evento de contingencia procesado correctamente.';
    if (($summary['total'] ?? 0) > 0) {
        $message .= ' Se transmitieron ' . (int) $summary['procesados'] . ' de ' . (int) $summary['total'] . ' documentos.';
    } else {
        $message .= ' No quedaban documentos pendientes de transmitir.';
    }
    if (($summary['errores'] ?? []) !== []) {
        $message .= ' Algunos documentos no se pudieron transmitir; revise el detalle para reintentarlos.';
    }
    return $message;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    try {
        if ($action === 'list') {
            jsonResponse(['success' => true, 'items' => contingencyEventList(db(), $tenantId)]);
        }
        if ($action === 'pending') {
            $tenant = tenantDocumentContext(db(), $tenantId);
            $period = contingencyPeriodFromInput($_GET, $tenant['timezone']);
            $typeValue = filter_var($_GET['tipo_contingencia'] ?? null, FILTER_VALIDATE_INT);
            $type = is_int($typeValue) && $typeValue >= 1 && $typeValue <= 5 ? $typeValue : null;
            $documents = contingencyPendingDocuments(db(), $tenantId, $period, $type);
            jsonResponse([
                'success' => true,
                'items' => $documents,
                'total' => count($documents),
                'period' => $period,
            ]);
        }
        if ($action === 'get') {
            $detail = contingencyEventDetail(db(), $tenantId, contingencyRequestId($_GET));
            jsonResponse(['success' => true] + $detail);
        }
        if ($action === 'download') {
            $event = contingencyEventRecord(db(), $tenantId, contingencyRequestId($_GET));
            if ($event === null) {
                jsonResponse(['success' => false, 'message' => 'El evento de contingencia no existe.'], 404);
            }
            if (empty($event['evento_json'])) {
                jsonResponse(['success' => false, 'message' => 'El evento aún no tiene JSON.'], 409);
            }
            $decoded = json_decode((string) $event['evento_json'], true);
            $filename = 'Evento-Contingencia-' . preg_replace('/[^A-Z0-9-]/', '', (string) $event['codigo_generacion']) . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-store');
            echo json_encode(
                $decoded,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            );
            exit;
        }
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    } catch (DocumentValidationException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
    } catch (RuntimeException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 404);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}
if ($user['rol'] !== 'ADMIN') {
    jsonResponse(['success' => false, 'message' => 'Solo un administrador puede declarar eventos de contingencia.'], 403);
}
verifyCsrf();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'create') {
        $detail = contingencyCreateEvent($tenantId, $user, $_POST);
        jsonResponse([
            'success' => true,
            'message' => contingencyTransmissionMessage($detail),
            'event' => $detail['event'],
            'documentos' => $detail['documentos'],
            'transmision' => $detail['transmision'],
            'logs' => $detail['logs'],
        ]);
    }
    if ($action === 'retry') {
        $detail = contingencyRetryEvent($tenantId, $user, contingencyRequestId($_POST));
        jsonResponse([
            'success' => true,
            'message' => contingencyTransmissionMessage($detail),
            'event' => $detail['event'],
            'documentos' => $detail['documentos'],
            'transmision' => $detail['transmision'],
            'logs' => $detail['logs'],
        ]);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (DocumentValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
