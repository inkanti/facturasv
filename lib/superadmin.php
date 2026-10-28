<?php

declare(strict_types=1);

require_once __DIR__ . '/configuracion.php';
require_once __DIR__ . '/catalogos.php';
require_once __DIR__ . '/contabilidad.php';
require_once __DIR__ . '/inventario.php';

/**
 * Módulo 11: superadministración.
 *
 * El superadministrador es un usuario global (tenant_id NULL, rol SUPERADMIN).
 * Solo él crea/desactiva empresas y asigna usuarios a cualquier empresa.
 */

function superadminCompanies(): array
{
    $statement = db()->query(
        "SELECT t.id, t.nit, t.razon_social, t.nombre_comercial, t.email, t.telefono,
                t.activo, t.created_at, c.modo_facturacion, c.mh_mode,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id) AS usuarios,
                (SELECT COUNT(*) FROM documentos d WHERE d.tenant_id = t.id) AS documentos
         FROM tenants t
         INNER JOIN tenant_config c ON c.tenant_id = t.id
         ORDER BY t.id DESC"
    );
    return $statement->fetchAll();
}

function superadminTenant(int $tenantId): ?array
{
    $statement = db()->prepare('SELECT * FROM tenants WHERE id = :id');
    $statement->execute(['id' => $tenantId]);
    $tenant = $statement->fetch();
    return $tenant === false ? null : $tenant;
}

function superadminTenantUsers(int $tenantId): array
{
    $statement = db()->prepare(
        'SELECT id, nombre, usuario, email, rol, activo, last_login_at, created_at
         FROM users WHERE tenant_id = :tenant_id ORDER BY id'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    return $statement->fetchAll();
}

/**
 * Crea una empresa y su primer usuario ADMIN en una sola transacción.
 */
function superadminCreateTenant(array $input, int $superadminId): int
{
    $pdo = db();
    $data = [
        'nit' => configuracionText($input, 'nit', 17),
        'razon_social' => configuracionText($input, 'razon_social', 150),
        'nombre_comercial' => configuracionText($input, 'nombre_comercial', 150),
        'codigo_actividad' => configuracionText($input, 'codigo_actividad', 10),
        'direccion' => configuracionText($input, 'direccion', 255),
        'telefono' => configuracionText($input, 'telefono', 30),
        'email' => mb_strtolower(configuracionText($input, 'email', 150)),
        'numero_registro_mh' => configuracionText($input, 'numero_registro_mh', 30),
    ];
    $admin = [
        'nombre' => configuracionText($input, 'admin_nombre', 120),
        'usuario' => configuracionText($input, 'admin_usuario', 50),
        'email' => mb_strtolower(configuracionText($input, 'admin_email', 150)),
    ];
    $password = is_string($input['admin_password'] ?? null) ? (string) $input['admin_password'] : '';
    $confirmation = is_string($input['admin_password_confirmation'] ?? null) ? (string) $input['admin_password_confirmation'] : '';
    $billingMode = strtoupper(configuracionText($input, 'modo_facturacion', 12));
    if ($billingMode === '') {
        $billingMode = 'SIMULACION';
    }

    $errors = [];
    foreach ($data as $field => $value) {
        if ($value === '') $errors[$field] = 'Este campo es obligatorio.';
    }
    foreach ($admin as $field => $value) {
        if ($value === '') $errors['admin_' . $field] = 'Este campo es obligatorio.';
    }
    if ($data['nit'] !== '' && !validNit($data['nit'])) {
        $errors['nit'] = 'Use el formato 0000-000000-000-0.';
    }
    if ($data['codigo_actividad'] !== '' && preg_match('/^[0-9]{3,10}$/', $data['codigo_actividad']) !== 1) {
        $errors['codigo_actividad'] = 'Debe contener entre 3 y 10 dígitos.';
    }
    if ($data['telefono'] !== '' && preg_match('/^[0-9]{4}-[0-9]{4}$/', $data['telefono']) !== 1) {
        $errors['telefono'] = 'Use el formato 0000-0000.';
    }
    if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Ingrese un correo electrónico válido.';
    }
    if ($admin['email'] !== '' && filter_var($admin['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['admin_email'] = 'Ingrese un correo electrónico válido.';
    }
    if ($admin['usuario'] !== '' && preg_match('/^[A-Za-z0-9._-]{3,50}$/', $admin['usuario']) !== 1) {
        $errors['admin_usuario'] = 'Use de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.';
    }
    if (!in_array($billingMode, ['INTERNO', 'SIMULACION'], true)) {
        $errors['modo_facturacion'] = 'Solo puede crear la empresa en modo interno o simulación.';
    }
    if ($data['nit'] !== '') {
        $statement = $pdo->prepare('SELECT id FROM tenants WHERE nit = :nit LIMIT 1');
        $statement->execute(['nit' => $data['nit']]);
        if ($statement->fetch() !== false) $errors['nit'] = 'El NIT ya está registrado por otra empresa.';
    }
    if ($admin['usuario'] !== '') {
        $statement = $pdo->prepare('SELECT id FROM users WHERE usuario = :usuario LIMIT 1');
        $statement->execute(['usuario' => $admin['usuario']]);
        if ($statement->fetch() !== false) $errors['admin_usuario'] = 'El nombre de usuario ya está en uso.';
    }
    $errors += configuracionPasswordErrors($password, $confirmation);
    if ($errors !== []) {
        throw new ConfiguracionValidationException('Revise los datos de la empresa y su administrador.', $errors);
    }

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO tenants (nit, razon_social, nombre_comercial, codigo_actividad,
                direccion, telefono, email, numero_registro_mh)
             VALUES (:nit, :razon_social, :nombre_comercial, :codigo_actividad,
                :direccion, :telefono, :email, :numero_registro_mh)'
        );
        $statement->execute($data);
        $tenantId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO tenant_config (tenant_id, mh_mode, modo_facturacion, timezone, moneda, establecimiento)
             VALUES (:tenant_id, 'SIMULACION', :modo_facturacion, 'America/El_Salvador', 'USD', 'M001P001')"
        )->execute(['tenant_id' => $tenantId, 'modo_facturacion' => $billingMode]);

        $pdo->prepare(
            "INSERT INTO users (tenant_id, nombre, usuario, email, password_hash, rol, activo)
             VALUES (:tenant_id, :nombre, :usuario, :email, :password_hash, 'ADMIN', 1)"
        )->execute([
            'tenant_id' => $tenantId,
            'nombre' => $admin['nombre'],
            'usuario' => $admin['usuario'],
            'email' => $admin['email'],
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        seedTenantCatalogs($pdo, $tenantId);
        contabilidadEnsureTenant($tenantId);
        inventarioEnsureTenant($tenantId);
        configuracionEnsureSequences($tenantId, 'M001P001');
        configuracionRegisterChange($tenantId, $superadminId, 'empresa', 'Empresa creada por el superadministrador en modo ' . $billingMode . '.');
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    return $tenantId;
}

function superadminToggleTenant(int $tenantId, int $superadminId, bool $active): string
{
    $tenant = superadminTenant($tenantId);
    if ($tenant === null) throw new RuntimeException('La empresa no existe.');
    db()->prepare('UPDATE tenants SET activo = :activo WHERE id = :id')
        ->execute(['activo' => $active ? 1 : 0, 'id' => $tenantId]);
    configuracionRegisterChange(
        $tenantId,
        $superadminId,
        'empresa',
        $active ? 'Empresa reactivada por el superadministrador.' : 'Empresa desactivada por el superadministrador.'
    );
    return $active
        ? 'Empresa reactivada. Sus usuarios ya pueden iniciar sesión.'
        : 'Empresa desactivada. Los datos se conservan y sus usuarios no pueden iniciar sesión.';
}

function superadminToggleUser(int $tenantId, int $userId, int $superadminId, bool $active): string
{
    $statement = db()->prepare(
        'SELECT id, nombre, email, rol, activo FROM users WHERE tenant_id = :tenant_id AND id = :id'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $userId]);
    $target = $statement->fetch();
    if ($target === false) throw new RuntimeException('El usuario no existe para esta empresa.');

    return configuracionUpdateUser($tenantId, $superadminId, [
        'id' => $userId,
        'nombre' => $target['nombre'],
        'email' => $target['email'],
        'rol' => $target['rol'],
        'activo' => $active ? '1' : '0',
    ]);
}

/**
 * Cambia el modo de facturación de una empresa. Solo el superadministrador
 * puede hacerlo; PRODUCCION sigue bloqueado hasta la integración real con MH.
 * El historial no se convierte: los comprobantes internos ya emitidos
 * conservan su serie INT y no se transforman en DTE.
 */
function superadminSetBillingMode(int $tenantId, int $superadminId, string $mode): string
{
    $mode = strtoupper(trim($mode));
    $tenant = superadminTenant($tenantId);
    if ($tenant === null) throw new RuntimeException('La empresa no existe.');
    if (!in_array($mode, ['INTERNO', 'SIMULACION', 'PRODUCCION'], true)) {
        throw new ConfiguracionValidationException('Revise el modo de facturación.', [
            'modo_facturacion' => 'Seleccione un modo válido.',
        ]);
    }
    if ($mode === 'PRODUCCION') {
        throw new ConfiguracionValidationException('Revise el modo de facturación.', [
            'modo_facturacion' => 'La producción aún no está disponible: la conexión real con Hacienda no está implementada.',
        ]);
    }

    $statement = db()->prepare(
        'SELECT mh_mode, modo_facturacion FROM tenant_config WHERE tenant_id = :tenant_id'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    $current = $statement->fetch();
    if ($current === false) throw new RuntimeException('La empresa no tiene configuración.');
    if ($current['modo_facturacion'] === $mode) {
        return 'La empresa ya está en modo ' . ($mode === 'INTERNO' ? 'interno' : 'simulación') . '.';
    }

    db()->prepare('UPDATE tenant_config SET modo_facturacion = :modo WHERE tenant_id = :tenant_id')
        ->execute(['modo' => $mode, 'tenant_id' => $tenantId]);
    configuracionRegisterChange(
        $tenantId,
        $superadminId,
        'modo_facturacion',
        'Modo de facturación: ' . $current['modo_facturacion'] . ' → ' . $mode . '.'
    );

    return $mode === 'INTERNO'
        ? 'Empresa en modo interno. Emitirá comprobantes no fiscales sin sello, sin JSON DTE y sin transmisión al MH; el historial anterior se conserva.'
        : 'Empresa en modo simulación. Volverá a emitir DTE de prueba con sello simulado; los comprobantes internos anteriores se conservan.';
}
