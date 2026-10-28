<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/perfil.php';

$profile = perfilCurrent();
if ($profile === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'data';
    if ($action !== 'data') {
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    }
    jsonResponse(['success' => true, 'perfil' => $profile]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

verifyCsrf();
$action = $_REQUEST['action'] ?? '';

try {
    if ($action === 'password') {
        $message = perfilChangePassword($profile, $_POST);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        jsonResponse(['success' => true, 'message' => $message]);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (ConfiguracionValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
