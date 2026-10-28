<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/catalogos.php';
require_once dirname(__DIR__) . '/lib/empresa.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_REQUEST['action'] ?? '';

if ($method === 'GET') {
    if ($action !== 'session') {
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    }

    $superadmin = currentSuperadmin();
    if ($superadmin !== null) {
        jsonResponse([
            'success' => true,
            'superadmin' => true,
            'user' => [
                'nombre' => $superadmin['nombre'],
                'usuario' => $superadmin['usuario'],
                'rol' => $superadmin['rol'],
                'tenant' => null,
                'nit' => null,
                'mh_mode' => null,
            ],
        ]);
    }

    $user = currentUser();
    if ($user === null) {
        jsonResponse(['success' => false, 'message' => 'No hay una sesión activa.'], 401);
    }

    jsonResponse([
        'success' => true,
        'superadmin' => false,
        'user' => [
            'nombre' => $user['nombre'],
            'usuario' => $user['usuario'],
            'rol' => $user['rol'],
            'tenant' => $user['nombre_comercial'],
            'nit' => $user['tenant_nit'],
            'mh_mode' => $user['mh_mode'],
        ],
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

verifyCsrf();

if ($action === 'login') {
    $usuario = postString('usuario', 150);
    $password = postString('password', 72);
    $remember = (($_POST['remember'] ?? '') === '1');

    if ($usuario === '' || $password === '') {
        jsonResponse([
            'success' => false,
            'message' => 'Ingrese su usuario o correo y su contraseña.',
        ], 422);
    }

    if (isLoginThrottled($usuario)) {
        jsonResponse([
            'success' => false,
            'message' => 'Demasiados intentos fallidos. Espere 15 minutos antes de intentarlo nuevamente.',
        ], 429);
    }

    $superadmin = findSuperadminForLogin($usuario);
    $user = findUserForLogin($usuario);
    if ($superadmin !== null && $user !== null) {
        // Un correo compartido entre una cuenta global y una de empresa es
        // ambiguo; se rechaza en lugar de elegir una cuenta arbitrariamente.
        recordLoginAttempt($usuario, false);
        jsonResponse([
            'success' => false,
            'message' => 'Las credenciales no son válidas o la cuenta está inactiva.',
        ], 401);
    }
    $identity = $superadmin ?? $user;
    $hash = $identity['password_hash'] ?? '$2y$10$jNYXK68yXE1woACvsVYjbuUTC9R9JNSrvgBj6xND6Ct55dYHkroTe';
    $validPassword = password_verify($password, $hash);

    if ($identity === null || !$validPassword) {
        recordLoginAttempt($usuario, false);
        jsonResponse([
            'success' => false,
            'message' => 'Las credenciales no son válidas o la cuenta está inactiva.',
        ], 401);
    }

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        $statement = db()->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $statement->execute([
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $identity['id'],
        ]);
    }

    markSuccessfulLogin((int) $identity['id']);
    recordLoginAttempt($usuario, true);

    if ($superadmin !== null) {
        establishSuperadminSession($superadmin, $remember);
        jsonResponse([
            'success' => true,
            'message' => 'Acceso de superadministrador correcto.',
            'superadmin' => true,
            'redirect' => url('views/superadmin.php'),
        ]);
    }

    establishSession($user, $remember);
    jsonResponse([
        'success' => true,
        'message' => 'Inicio de sesión correcto.',
        'superadmin' => false,
        'redirect' => url('views/dashboard.php'),
    ]);
}

if ($action === 'register') {
    jsonResponse([
        'success' => false,
        'message' => 'El registro público está deshabilitado. Las empresas las crea el superadministrador.',
    ], 403);
}

if ($action === 'logout') {
    destroySession();
    jsonResponse([
        'success' => true,
        'message' => 'Sesión cerrada correctamente.',
        'redirect' => url('views/login.php'),
    ]);
}

jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
