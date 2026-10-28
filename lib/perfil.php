<?php

declare(strict_types=1);

require_once __DIR__ . '/configuracion.php';

/**
 * Módulo 12: perfil de la cuenta.
 *
 * Cada usuario solo puede consultar su propia cuenta y cambiar su propia
 * contraseña. No puede modificar el nombre, usuario, correo, rol ni empresa.
 */

function perfilCurrent(): ?array
{
    $superadmin = currentSuperadmin();
    if ($superadmin !== null) {
        return [
            'id' => (int) $superadmin['id'],
            'tenant_id' => null,
            'superadmin' => true,
            'nombre' => $superadmin['nombre'],
            'usuario' => $superadmin['usuario'],
            'email' => $superadmin['email'],
            'rol' => $superadmin['rol'],
            'last_login_at' => $superadmin['last_login_at'],
            'empresa' => null,
            'empresa_nit' => null,
        ];
    }

    $user = currentUser();
    if ($user === null) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'tenant_id' => (int) $user['tenant_id'],
        'superadmin' => false,
        'nombre' => $user['nombre'],
        'usuario' => $user['usuario'],
        'email' => $user['email'],
        'rol' => $user['rol'],
        'last_login_at' => $user['last_login_at'],
        'empresa' => $user['nombre_comercial'],
        'empresa_nit' => $user['tenant_nit'],
    ];
}

function perfilChangePassword(array $profile, array $input): string
{
    $current = is_string($input['current_password'] ?? null) ? (string) $input['current_password'] : '';
    $password = is_string($input['password'] ?? null) ? (string) $input['password'] : '';
    $confirmation = is_string($input['password_confirmation'] ?? null) ? (string) $input['password_confirmation'] : '';

    $errors = [];
    if ($current === '') {
        $errors['current_password'] = 'Ingrese su contraseña actual.';
    }
    $errors += configuracionPasswordErrors($password, $confirmation);
    if ($password !== '' && $password === $current) {
        $errors['password'] = 'La nueva contraseña debe ser diferente a la actual.';
    }

    $pdo = db();
    if ($profile['superadmin']) {
        $statement = $pdo->prepare(
            "SELECT password_hash FROM users
             WHERE id = :id AND rol = 'SUPERADMIN' AND tenant_id IS NULL AND activo = 1"
        );
        $statement->execute(['id' => (int) $profile['id']]);
    } else {
        $statement = $pdo->prepare(
            'SELECT password_hash FROM users
             WHERE id = :id AND tenant_id = :tenant_id AND activo = 1'
        );
        $statement->execute(['id' => (int) $profile['id'], 'tenant_id' => (int) $profile['tenant_id']]);
    }
    $account = $statement->fetch();
    if ($account === false) {
        throw new RuntimeException('La cuenta no está disponible.');
    }
    if ($current !== '' && !password_verify($current, $account['password_hash'])) {
        $errors['current_password'] = 'La contraseña actual no es correcta.';
    }
    if ($errors !== []) {
        throw new ConfiguracionValidationException('Revise los datos de la contraseña.', $errors);
    }

    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
        $update->execute([
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => (int) $profile['id'],
        ]);
        if (!$profile['superadmin']) {
            configuracionRegisterChange(
                (int) $profile['tenant_id'],
                (int) $profile['id'],
                'usuarios',
                'El usuario cambió su propia contraseña.'
            );
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    return 'Contraseña actualizada correctamente. Use la nueva clave en su próximo inicio de sesión.';
}
