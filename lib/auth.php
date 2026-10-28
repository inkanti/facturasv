<?php

declare(strict_types=1);

function currentUser(): ?array
{
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !ctype_digit((string) $userId)) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT u.id, u.tenant_id, u.nombre, u.usuario, u.email, u.rol,
                u.last_login_at, t.nit AS tenant_nit, t.razon_social,
                t.nombre_comercial, t.codigo_actividad, t.direccion,
                t.telefono AS tenant_telefono, t.email AS tenant_email,
                t.numero_registro_mh, c.mh_mode, c.modo_facturacion, c.timezone, c.moneda
         FROM users u
         INNER JOIN tenants t ON t.id = u.tenant_id
         INNER JOIN tenant_config c ON c.tenant_id = t.id
         WHERE u.id = :id AND u.activo = 1 AND t.activo = 1
         LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $user = $statement->fetch();

    return $user === false ? null : $user;
}

function currentSuperadmin(): ?array
{
    $superadminId = $_SESSION['superadmin_id'] ?? null;
    if (!is_int($superadminId) && !ctype_digit((string) $superadminId)) {
        return null;
    }

    $statement = db()->prepare(
        "SELECT id, nombre, usuario, email, rol, last_login_at
         FROM users
         WHERE id = :id AND rol = 'SUPERADMIN' AND tenant_id IS NULL AND activo = 1
         LIMIT 1"
    );
    $statement->execute(['id' => (int) $superadminId]);
    $superadmin = $statement->fetch();

    return $superadmin === false ? null : $superadmin;
}

function requireAuth(): array
{
    if (currentSuperadmin() !== null) {
        redirect('views/superadmin.php');
    }

    $user = currentUser();
    if ($user === null) {
        unset($_SESSION['user_id']);
        redirect('views/login.php');
    }

    return $user;
}

function requireSuperadmin(): array
{
    $superadmin = currentSuperadmin();
    if ($superadmin === null) {
        if (currentUser() !== null) {
            http_response_code(403);
            exit('Acceso reservado al superadministrador.');
        }
        redirect('views/login.php');
    }

    return $superadmin;
}

function requireSuperadminApi(): array
{
    $superadmin = currentSuperadmin();
    if ($superadmin === null) {
        jsonResponse(['success' => false, 'message' => 'Acceso reservado al superadministrador.'], 401);
    }
    return $superadmin;
}

function requireRole(array $user, string ...$roles): void
{
    if (!in_array($user['rol'], $roles, true)) {
        http_response_code(403);
        exit('Acceso denegado: su rol no tiene permiso para realizar esta acción.');
    }
}

function persistLoginCookies(bool $remember): void
{
    $lifetime = $remember ? 2592000 : 0;
    $expires = $remember ? time() + $lifetime : 0;
    $options = [
        'expires' => $expires,
        'path' => '/',
        'secure' => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if ($remember) {
        $_SESSION['remember_me'] = true;
    } else {
        unset($_SESSION['remember_me']);
    }
    // El ID de sesión se renueva al iniciar sesión; se vuelve a enviar la cookie
    // con la vigencia elegida por el usuario.
    setcookie(session_name(), session_id(), $options);
    setcookie('facturasv_remember', $remember ? '1' : '0', [
        'expires' => $remember ? time() + $lifetime : time() - 42000,
        'path' => '/',
        'secure' => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function establishSession(array $user, bool $remember = false): void
{
    session_regenerate_id(true);
    unset($_SESSION['superadmin_id']);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    persistLoginCookies($remember);
}

function establishSuperadminSession(array $superadmin, bool $remember = false): void
{
    session_regenerate_id(true);
    unset($_SESSION['user_id']);
    $_SESSION['superadmin_id'] = (int) $superadmin['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    persistLoginCookies($remember);
}

function destroySession(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $options = [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ];
        setcookie(session_name(), '', $options);
        setcookie('facturasv_remember', '', $options);
    }

    session_destroy();
}

function loginIdentifierHash(string $usuario): string
{
    return hash('sha256', mb_strtolower(trim($usuario)));
}

function isLoginThrottled(string $usuario): bool
{
    $pdo = db();
    $identifierHash = loginIdentifierHash($usuario);
    $ipHash = hash('sha256', clientIp());

    $statement = $pdo->prepare(
        "SELECT
            SUM(identifier_hash = :identifier_sum) AS intentos_cuenta,
            SUM(ip_hash = :ip_sum) AS intentos_ip
         FROM auth_attempts
         WHERE exitoso = 0 AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
           AND (identifier_hash = :identifier_where OR ip_hash = :ip_where)"
    );
    $statement->execute([
        'identifier_sum' => $identifierHash,
        'ip_sum' => $ipHash,
        'identifier_where' => $identifierHash,
        'ip_where' => $ipHash,
    ]);
    $attempts = $statement->fetch();

    return (int) ($attempts['intentos_cuenta'] ?? 0) >= 5
        || (int) ($attempts['intentos_ip'] ?? 0) >= 20;
}

function recordLoginAttempt(string $usuario, bool $successful): void
{
    $pdo = db();
    $identifierHash = loginIdentifierHash($usuario);

    if ($successful) {
        $statement = $pdo->prepare('DELETE FROM auth_attempts WHERE identifier_hash = :identifier_hash');
        $statement->execute(['identifier_hash' => $identifierHash]);
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO auth_attempts (identifier_hash, ip_hash, exitoso) VALUES (:identifier_hash, :ip_hash, 0)'
    );
    $statement->execute([
        'identifier_hash' => $identifierHash,
        'ip_hash' => hash('sha256', clientIp()),
    ]);

    if (random_int(1, 100) === 1) {
        $pdo->exec('DELETE FROM auth_attempts WHERE attempted_at < (UTC_TIMESTAMP() - INTERVAL 30 DAY)');
    }
}

function findUserForLogin(string $usuario): ?array
{
    $identifier = trim($usuario);
    $statement = db()->prepare(
        'SELECT u.id, u.password_hash
         FROM users u
         INNER JOIN tenants t ON t.id = u.tenant_id
         WHERE (u.usuario = :usuario OR LOWER(u.email) = :email)
           AND u.activo = 1 AND t.activo = 1
         LIMIT 2'
    );
    $statement->execute([
        'usuario' => $identifier,
        'email' => mb_strtolower($identifier),
    ]);
    $matches = $statement->fetchAll();
    return count($matches) === 1 ? $matches[0] : null;
}

function findSuperadminForLogin(string $usuario): ?array
{
    $identifier = trim($usuario);
    $statement = db()->prepare(
        "SELECT id, password_hash
         FROM users
         WHERE (usuario = :usuario OR LOWER(email) = :email)
           AND rol = 'SUPERADMIN' AND tenant_id IS NULL AND activo = 1
         LIMIT 2"
    );
    $statement->execute([
        'usuario' => $identifier,
        'email' => mb_strtolower($identifier),
    ]);
    $matches = $statement->fetchAll();
    return count($matches) === 1 ? $matches[0] : null;
}

function markSuccessfulLogin(int $userId): void
{
    $statement = db()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id');
    $statement->execute(['id' => $userId]);
}
