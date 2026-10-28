<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/configuracion.php';
require_once dirname(__DIR__) . '/lib/empresa.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}
if ($user['rol'] !== 'ADMIN') {
    jsonResponse(['success' => false, 'message' => 'Solo el administrador puede cambiar la configuración.'], 403);
}
$tenantId = (int) $user['tenant_id'];
$userId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'data';
    try {
        if ($action === 'data') {
            jsonResponse(['success' => true] + configuracionData($tenantId, $userId));
        }
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    } catch (RuntimeException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

verifyCsrf();
$action = $_POST['action'] ?? '';

if (in_array($action, ['user-create', 'user-update', 'user-password'], true)) {
    jsonResponse([
        'success' => false,
        'message' => 'La gestión de usuarios la realiza el superadministrador desde el panel global.',
    ], 403);
}

try {
    if ($action === 'empresa') {
        $message = configuracionUpdateCompany($tenantId, $userId, $_POST);
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'emisor') {
        $message = configuracionUpdateEmitter($tenantId, $userId, $_POST);
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'sequence') {
        $message = configuracionUpdateSequence($tenantId, $userId, $_POST);
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'logo') {
        $file = $_FILES['logo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            jsonResponse(['success' => false, 'message' => 'Seleccione una imagen PNG o JPG.', 'errors' => ['logo' => 'Seleccione una imagen.']], 422);
        }
        $relative = storeTenantLogo(db(), $tenantId, $file);
        configuracionRegisterChange($tenantId, $userId, 'empresa', 'Logo actualizado: ' . $relative . '.');
        jsonResponse(['success' => true, 'message' => 'Logo actualizado correctamente.', 'logo_url' => configuracionLogoUrl($relative)]);
    }
    if ($action === 'logo-remove') {
        $message = configuracionRemoveLogo($tenantId, $userId);
        jsonResponse(['success' => true, 'message' => $message]);
    }
    if ($action === 'mail-test') {
        $result = configuracionTestEmail($tenantId, $userId, postString('destinatario', 150));
        $ok = $result['estado'] !== 'ERROR';
        jsonResponse([
            'success' => $ok, 'message' => $result['message'], 'correo' => $result,
        ], $ok ? 200 : 502);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (ConfiguracionValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
