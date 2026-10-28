<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/perfil.php';

/**
 * Pruebas del módulo 12: cambio de la propia contraseña.
 * Restaura la contraseña del administrador demo y elimina la auditoría temporal.
 */

$pdo = db();
$checks = 0;
$admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
$originalHash = $admin['password_hash'];
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$profile = [
    'id' => (int) $admin['id'],
    'tenant_id' => 1,
    'superadmin' => false,
];

function checkModule12(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FALLO M12: ' . $message);
    $checks++;
    echo "OK: {$message}\n";
}

try {
    try {
        perfilChangePassword($profile, [
            'current_password' => 'Incorrecta123!', 'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
        ]);
        throw new RuntimeException('Se aceptó una contraseña actual incorrecta.');
    } catch (ConfiguracionValidationException $exception) {
        checkModule12(isset($exception->errors['current_password']), 'se rechaza una contraseña actual incorrecta');
    }

    try {
        perfilChangePassword($profile, [
            'current_password' => 'Demo1234!', 'password' => 'corta', 'password_confirmation' => 'corta',
        ]);
        throw new RuntimeException('Se aceptó una contraseña débil.');
    } catch (ConfiguracionValidationException $exception) {
        checkModule12(isset($exception->errors['password']), 'se rechaza una contraseña nueva débil');
    }

    $message = perfilChangePassword($profile, [
        'current_password' => 'Demo1234!', 'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
    ]);
    checkModule12(str_contains($message, 'actualizada'), 'el usuario cambia su propia contraseña');
    $hash = (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . (int) $admin['id'])->fetchColumn();
    checkModule12(password_verify('Nueva12345!', $hash), 'la nueva contraseña queda almacenada con hash');

    try {
        perfilChangePassword($profile, [
            'current_password' => 'Nueva12345!', 'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
        ]);
        throw new RuntimeException('Se aceptó la misma contraseña.');
    } catch (ConfiguracionValidationException $exception) {
        checkModule12(isset($exception->errors['password']), 'se rechaza reutilizar la misma contraseña');
    }

    $audit = (int) $pdo->query(
        'SELECT COUNT(*) FROM configuracion_cambios WHERE id > ' . $auditStart
        . " AND tenant_id = 1 AND detalle LIKE '%cambió su propia contraseña%'"
    )->fetchColumn();
    checkModule12($audit >= 1, 'el cambio de contraseña queda auditado en la empresa');

    echo "RESULTADO: {$checks} comprobaciones correctas.\n";
} finally {
    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
        ->execute(['hash' => $originalHash, 'id' => (int) $admin['id']]);
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
