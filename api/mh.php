<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';

$user = currentUser();
if ($user === null) jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}
verifyCsrf();
$tenantId = (int) $user['tenant_id'];
$action = $_POST['action'] ?? '';
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) jsonResponse(['success' => false, 'message' => 'El documento solicitado no es válido.'], 400);

try {
    if ($action === 'consult') {
        $response = consultDocument($tenantId, $user, $id);
        jsonResponse(['success' => true, 'message' => 'Consulta registrada correctamente.', 'response' => $response]);
    }
    if ($action === 'transmit') {
        if (!in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true)) jsonResponse(['success' => false, 'message' => 'Su rol no permite transmitir documentos.'], 403);
        $response = transmitDocument($tenantId, $user, $id);
        jsonResponse(['success' => true, 'message' => 'Transmisión simulada completada.', 'response' => $response]);
    }
    if ($action === 'invalidate') {
        if ($user['rol'] !== 'ADMIN') jsonResponse(['success' => false, 'message' => 'Solo un administrador puede invalidar documentos.'], 403);
        $detail = invalidateDocument($tenantId, $user, $id, $_POST);
        jsonResponse(['success' => true, 'message' => 'Documento invalidado correctamente en modo simulación.', 'document' => $detail['document']]);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (DocumentValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
