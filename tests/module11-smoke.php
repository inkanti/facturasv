<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/configuracion.php';
require_once dirname(__DIR__) . '/lib/superadmin.php';

/**
 * Pruebas del módulo 11: superadministrador, empresas y usuarios centralizados.
 * Restaura la base eliminando la empresa temporal.
 */

$pdo = db();
$assertions = 0;
$tenantId = null;
$superadmin = $pdo->query("SELECT * FROM users WHERE rol = 'SUPERADMIN' AND tenant_id IS NULL LIMIT 1")->fetch();
$suffix = (string) getmypid();
$adminUser = 'm11_admin_' . $suffix;
$vendorUser = 'm11_vendor_' . $suffix;
$nit = '0614-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) . '-777-7';

function checkModule11(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function module11UserInput(string $name, string $user, string $role, string $password): array
{
    return [
        'nombre' => $name, 'usuario' => $user, 'email' => $user . '@test.local',
        'rol' => $role, 'activo' => '1',
        'password' => $password, 'password_confirmation' => $password,
    ];
}

try {
    checkModule11((int) $pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn() >= 13, 'la base de datos está en la versión 13 o superior');
    checkModule11(is_array($superadmin), 'existe el superadministrador global');
    checkModule11($superadmin['tenant_id'] === null && $superadmin['rol'] === 'SUPERADMIN', 'el superadministrador no pertenece a una empresa');
    checkModule11(password_verify('SuperDemo1234!', $superadmin['password_hash']), 'la contraseña inicial del superadministrador es válida');

    $tenantId = superadminCreateTenant([
        'nit' => $nit,
        'razon_social' => 'Empresa temporal M11 SA de CV',
        'nombre_comercial' => 'Temporal M11',
        'codigo_actividad' => '00000',
        'direccion' => 'San Salvador, El Salvador',
        'telefono' => '2222-9999',
        'email' => 'empresa.m11@test.local',
        'numero_registro_mh' => '9999999',
        'admin_nombre' => 'Administrador temporal M11',
        'admin_usuario' => $adminUser,
        'admin_email' => 'admin.m11@test.local',
        'admin_password' => 'Admin12345!',
        'admin_password_confirmation' => 'Admin12345!',
    ], (int) $superadmin['id']);
    checkModule11($tenantId > 0, 'el superadministrador crea una empresa');
    checkModule11((int) $pdo->query('SELECT COUNT(*) FROM users WHERE tenant_id = ' . $tenantId . " AND rol = 'ADMIN'")->fetchColumn() === 1, 'la empresa nace con un administrador');
    checkModule11((int) $pdo->query('SELECT COUNT(*) FROM document_sequences WHERE tenant_id = ' . $tenantId)->fetchColumn() === 6, 'la empresa nace con sus seis series');
    checkModule11((int) $pdo->query('SELECT COUNT(*) FROM mh_tipos_documento WHERE tenant_id = ' . $tenantId)->fetchColumn() === 6, 'la empresa nace con sus catálogos');
    checkModule11(count(contabilidadAccounts($tenantId)) >= 20, 'la empresa nace con su catálogo contable');
    checkModule11((int) $pdo->query('SELECT COUNT(*) FROM contabilidad_config WHERE tenant_id = ' . $tenantId)->fetchColumn() === 1, 'la empresa nace con su configuración contable');
    checkModule11((int) $pdo->query('SELECT COUNT(*) FROM inventario_config WHERE tenant_id = ' . $tenantId)->fetchColumn() === 1, 'la empresa nace con su configuración de inventario');

    configuracionCreateUser($tenantId, (int) $superadmin['id'], module11UserInput('Vendedor temporal M11', $vendorUser, 'VENDEDOR', 'Venta12345!'));
    checkModule11((int) $pdo->query('SELECT COUNT(*) FROM users WHERE tenant_id = ' . $tenantId)->fetchColumn() === 2, 'la empresa puede tener varios usuarios');
    $vendorId = (int) $pdo->query('SELECT id FROM users WHERE usuario = ' . $pdo->quote($vendorUser))->fetchColumn();
    checkModule11($vendorId > 0, 'el superadministrador asigna un vendedor');

    $login = findUserForLogin($adminUser);
    checkModule11(is_array($login), 'el administrador de la empresa puede iniciar sesión');

    superadminToggleUser($tenantId, $vendorId, (int) $superadmin['id'], false);
    checkModule11((int) $pdo->query('SELECT activo FROM users WHERE id = ' . $vendorId)->fetchColumn() === 0, 'el superadministrador desactiva un usuario');
    checkModule11(findUserForLogin($vendorUser) === null, 'un usuario desactivado no puede iniciar sesión');
    superadminToggleUser($tenantId, $vendorId, (int) $superadmin['id'], true);
    checkModule11(is_array(findUserForLogin($vendorUser)), 'un usuario reactivado puede iniciar sesión');

    superadminToggleTenant($tenantId, (int) $superadmin['id'], false);
    checkModule11((int) $pdo->query('SELECT activo FROM tenants WHERE id = ' . $tenantId)->fetchColumn() === 0, 'el superadministrador desactiva la empresa');
    checkModule11(findUserForLogin($adminUser) === null, 'los usuarios de una empresa desactivada no pueden iniciar sesión');
    superadminToggleTenant($tenantId, (int) $superadmin['id'], true);
    checkModule11(is_array(findUserForLogin($adminUser)), 'la empresa reactivada permite iniciar sesión de nuevo');

    $companies = superadminCompanies();
    checkModule11(count(array_filter($companies, static fn(array $row): bool => (int) $row['id'] === $tenantId)) === 1, 'la empresa aparece en el listado del superadministrador');

    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    if ($tenantId !== null) {
        $pdo->prepare('DELETE FROM contabilidad_asientos WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM contabilidad_config WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM contabilidad_cuentas WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM contabilidad_secuencias WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM inventario_movimientos WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM inventario_config WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM configuracion_cambios WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM document_sequences WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM users WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        $pdo->prepare('DELETE FROM tenant_config WHERE tenant_id = :tenant_id')->execute(['tenant_id' => $tenantId]);
        foreach (['mh_municipios', 'mh_departamentos', 'mh_formas_pago', 'mh_condiciones_operacion', 'mh_unidades_medida', 'mh_tipos_item', 'mh_tipos_establecimiento', 'mh_tipos_documento'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE tenant_id = :tenant_id")->execute(['tenant_id' => $tenantId]);
        }
        $pdo->prepare('DELETE FROM tenants WHERE id = :id')->execute(['id' => $tenantId]);
    }
    echo "Datos temporales eliminados.\n";
}
