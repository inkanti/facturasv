<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/configuracion.php';
require_once dirname(__DIR__) . '/lib/superadmin.php';

$superadmin = currentSuperadmin();
if ($superadmin === null) {
    jsonResponse(['success' => false, 'message' => 'Acceso reservado al superadministrador.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function superadminTenantId(array $source): int
{
    $id = filter_var($source['tenant_id'] ?? $source['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'La empresa solicitada no es válida.'], 400);
    }
    return $id;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'companies';
    try {
        if ($action === 'companies') {
            jsonResponse([
                'success' => true,
                'items' => superadminCompanies(),
                'superadmin' => [
                    'nombre' => $superadmin['nombre'],
                    'usuario' => $superadmin['usuario'],
                ],
            ]);
        }
        if ($action === 'users') {
            $tenantId = superadminTenantId($_GET);
            $tenant = superadminTenant($tenantId);
            if ($tenant === null) {
                jsonResponse(['success' => false, 'message' => 'La empresa no existe.'], 404);
            }
            jsonResponse([
                'success' => true,
                'tenant' => $tenant,
                'users' => superadminTenantUsers($tenantId),
                'roles' => configuracionRoleLabels(),
            ]);
        }
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    } catch (RuntimeException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 404);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

verifyCsrf();
$action = $_POST['action'] ?? '';

try {
    if ($action === 'company-create') {
        $tenantId = superadminCreateTenant($_POST, (int) $superadmin['id']);
        jsonResponse([
            'success' => true,
            'message' => 'Empresa y administrador creados correctamente.',
            'tenant_id' => $tenantId,
        ], 201);
    }

    if ($action === 'company-toggle') {
        $tenantId = superadminTenantId($_POST);
        $active = ($_POST['activo'] ?? '1') === '1';
        $message = superadminToggleTenant($tenantId, (int) $superadmin['id'], $active);
        jsonResponse(['success' => true, 'message' => $message, 'activo' => $active]);
    }

    if ($action === 'company-billing-mode') {
        $tenantId = superadminTenantId($_POST);
        $message = superadminSetBillingMode(
            $tenantId,
            (int) $superadmin['id'],
            is_string($_POST['modo_facturacion'] ?? null) ? $_POST['modo_facturacion'] : ''
        );
        jsonResponse([
            'success' => true,
            'message' => $message,
            'modo_facturacion' => strtoupper(trim((string) ($_POST['modo_facturacion'] ?? ''))),
        ]);
    }

    $tenantId = superadminTenantId($_POST);

    if ($action === 'user-create') {
        $message = configuracionCreateUser($tenantId, (int) $superadmin['id'], $_POST);
        jsonResponse(['success' => true, 'message' => $message]);
    }

    if ($action === 'user-toggle') {
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false || $userId === null) {
            jsonResponse(['success' => false, 'message' => 'El usuario solicitado no es válido.'], 400);
        }
        $active = ($_POST['activo'] ?? '1') === '1';
        $message = superadminToggleUser($tenantId, (int) $userId, (int) $superadmin['id'], $active);
        jsonResponse(['success' => true, 'message' => $message, 'activo' => $active]);
    }

    if ($action === 'user-reset-password') {
        $message = configuracionResetPassword($tenantId, (int) $superadmin['id'], $_POST);
        jsonResponse(['success' => true, 'message' => $message]);
    }

    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (ConfiguracionValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        jsonResponse(['success' => false, 'message' => 'El NIT, usuario o correo ya están registrados.'], 409);
    }
    error_log('FacturaSV: error de superadministración. ' . $exception->getMessage());
    jsonResponse(['success' => false, 'message' => 'No fue posible completar la operación.'], 500);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
