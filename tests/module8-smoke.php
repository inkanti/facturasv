<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/configuracion.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/productos.php';

/**
 * Pruebas del módulo 8: empresa, emisor, numeración, usuarios y correo.
 * Restaura todos los valores al finalizar.
 */

$pdo = db();
// La prueba no debe usar el SMTP real: fuerza el transporte simulado.
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);
$tenantId = 1;
$assertions = 0;
$documentIds = [];
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$tenantBefore = null;
$configBefore = null;
$sequenceBefore = null;
$tempUserId = null;

function checkModule8(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function expectConfigError(callable $callback, string $field, string $message): void
{
    $caught = false;
    try { $callback(); }
    catch (ConfiguracionValidationException $exception) { $caught = isset($exception->errors[$field]); }
    checkModule8($caught, $message);
}

try {
    checkModule8((int) $pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn() >= 10, 'la base de datos está en la versión 10 o superior');
    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    checkModule8(is_array($admin), 'existe el administrador de pruebas');

    $tenantBefore = $pdo->query('SELECT * FROM tenants WHERE id = 1')->fetch();
    $configBefore = $pdo->query('SELECT * FROM tenant_config WHERE tenant_id = 1')->fetch();
    $sequenceBefore = $pdo->query("SELECT * FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = '05' LIMIT 1")->fetch();
    $sequence01Row = $pdo->query("SELECT id, numero_actual FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = '01' LIMIT 1")->fetch();
    checkModule8($sequenceBefore !== false, 'existe la serie del tipo 05');
    $sequence01 = $sequence01Row !== false ? (int) $sequence01Row['numero_actual'] : null;

    $data = configuracionData($tenantId, (int) $admin['id']);
    checkModule8($data['tenant']['nit'] === '0614-000000-000-0', 'la configuración devuelve los datos de la empresa');
    checkModule8(count($data['sequences']) >= 6, 'la configuración devuelve las series');
    checkModule8($data['config']['departamento_emisor'] === '06' && $data['config']['municipio_emisor'] === '04', 'el emisor tiene departamento y municipio');
    checkModule8(count($data['departamentos']) === 14 && count($data['municipios']) === 44, 'los catálogos de ubicación están completos');
    checkModule8(array_key_exists('America/El_Salvador', $data['timezones']), 'las zonas horarias están disponibles');
    checkModule8($data['mail']['simulated'] === true, 'el correo está en modo simulado para la prueba');

    // Empresa: validaciones y actualización.
    $companyInput = [
        'nit' => $tenantBefore['nit'], 'razon_social' => $tenantBefore['razon_social'],
        'nombre_comercial' => $tenantBefore['nombre_comercial'],
        'codigo_actividad' => $tenantBefore['codigo_actividad'], 'direccion' => $tenantBefore['direccion'],
        'telefono' => '2222-3333', 'email' => $tenantBefore['email'],
        'numero_registro_mh' => $tenantBefore['numero_registro_mh'],
    ];
    expectConfigError(
        static fn() => configuracionUpdateCompany(1, (int) $admin['id'], ['nit' => '0614-1', 'telefono' => 'x'] + $companyInput),
        'nit', 'la empresa rechaza un NIT inválido'
    );
    expectConfigError(
        static fn() => configuracionUpdateCompany(1, (int) $admin['id'], ['telefono' => '123'] + $companyInput),
        'telefono', 'la empresa rechaza un teléfono inválido'
    );
    configuracionUpdateCompany($tenantId, (int) $admin['id'], $companyInput);
    checkModule8($pdo->query('SELECT telefono FROM tenants WHERE id = 1')->fetchColumn() === '2222-3333', 'la empresa actualiza sus datos');

    // Emisor: validaciones y cambio de ubicación.
    $emitterInput = [
        'establecimiento' => $configBefore['establecimiento'], 'distrito_emisor' => $configBefore['distrito_emisor'],
        'departamento_emisor' => '06', 'municipio_emisor' => '04', 'timezone' => 'America/El_Salvador',
        'mh_mode' => 'SIMULACION',
    ];
    expectConfigError(
        static fn() => configuracionUpdateEmitter(1, (int) $admin['id'], ['establecimiento' => 'X'] + $emitterInput),
        'establecimiento', 'el emisor rechaza un establecimiento inválido'
    );
    expectConfigError(
        static fn() => configuracionUpdateEmitter(1, (int) $admin['id'], ['mh_mode' => 'PRODUCCION'] + $emitterInput),
        'mh_mode', 'el emisor rechaza el entorno de producción'
    );
    expectConfigError(
        static fn() => configuracionUpdateEmitter(1, (int) $admin['id'], ['municipio_emisor' => '04', 'departamento_emisor' => '01'] + $emitterInput),
        'municipio_emisor', 'el emisor rechaza un municipio de otro departamento'
    );
    configuracionUpdateEmitter($tenantId, (int) $admin['id'], ['departamento_emisor' => '06', 'municipio_emisor' => '01'] + $emitterInput);
    checkModule8($pdo->query('SELECT municipio_emisor FROM tenant_config WHERE tenant_id = 1')->fetchColumn() === '01', 'el emisor actualiza su municipio');

    // El JSON del DTE usa la ubicación configurada.
    $client = $pdo->query('SELECT id FROM clientes WHERE tenant_id = 1 AND activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    $sequence01 = $pdo->query("SELECT numero_actual FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = '01'")->fetchColumn();
    $documentId = saveDocument($tenantId, (int) $admin['id'], [
        'tipo_dte' => '01', 'cliente_id' => (string) $client, 'fecha_emision' => '2026-10-26',
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => $configBefore['distrito_emisor'], 'receptor_distrito' => '01',
        'aplicar_iva_retenido' => '0', 'observaciones' => 'Prueba módulo 8',
        'items' => [['producto_id' => '1', 'cantidad' => '1', 'precio_unitario' => '113.00000000', 'descuento' => '0']],
    ]);
    $documentIds[] = $documentId;
    $emitted = emitDocument($tenantId, $admin, $documentId);
    checkModule8(
        $emitted['document']['dte']['emisor']['direccion']['departamento'] === '06'
        && $emitted['document']['dte']['emisor']['direccion']['municipio'] === '01',
        'el DTE usa el departamento y municipio configurados'
    );

    // Numeración: validaciones y actualización de una serie sin documentos.
    expectConfigError(
        static fn() => configuracionUpdateSequence(1, (int) $admin['id'], ['id' => $sequence01Row['id'], 'prefijo' => 'DTE', 'numero_inicial' => '1', 'numero_actual' => '0', 'activo' => '1']),
        'numero_actual', 'la serie del tipo 01 no baja del último emitido'
    );
    configuracionUpdateSequence($tenantId, (int) $admin['id'], [
        'id' => $sequenceBefore['id'], 'prefijo' => 'PRB', 'numero_inicial' => '5', 'numero_actual' => '9', 'activo' => '1',
    ]);
    $updated = $pdo->query('SELECT prefijo, numero_inicial, numero_actual FROM document_sequences WHERE tenant_id = 1 AND tipo_dte = \'05\'')->fetch();
    checkModule8($updated['prefijo'] === 'PRB' && (int) $updated['numero_actual'] === 9, 'la serie actualiza prefijo y número actual');

    // Una serie inactiva no permite reservar números.
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE document_sequences SET activo = 0 WHERE id = :id')->execute(['id' => $sequence01Row['id']]);
        $blocked = false;
        try {
            reserveDocumentNumber($pdo, $tenantId, '01', (string) $configBefore['establecimiento']);
        } catch (RuntimeException $exception) {
            $blocked = str_contains($exception->getMessage(), 'inactiva');
        }
        checkModule8($blocked, 'una serie inactiva bloquea la emisión');
    } finally {
        $pdo->rollBack();
    }

    // Usuarios: crear, validar, actualizar, contraseña y protección del administrador.
    $username = 'm8_user_' . getmypid();
    $userInput = [
        'nombre' => 'Usuario temporal M8', 'usuario' => $username, 'email' => $username . '@test.local',
        'rol' => 'VENDEDOR', 'activo' => '1', 'password' => 'Prueba12345!', 'password_confirmation' => 'Prueba12345!',
    ];
    expectConfigError(
        static fn() => configuracionCreateUser(1, (int) $admin['id'], ['password' => 'corta'] + $userInput),
        'password', 'se rechaza una contraseña débil'
    );
    configuracionCreateUser($tenantId, (int) $admin['id'], $userInput);
    $tempUserId = (int) $pdo->query('SELECT id FROM users WHERE usuario = ' . $pdo->quote($username))->fetchColumn();
    checkModule8($tempUserId > 0, 'se crea un usuario temporal');
    expectConfigError(
        static fn() => configuracionCreateUser(1, (int) $admin['id'], $userInput),
        'usuario', 'no se permite repetir el nombre de usuario'
    );
    configuracionUpdateUser($tenantId, (int) $admin['id'], [
        'id' => $tempUserId, 'nombre' => 'Usuario temporal M8', 'email' => $username . '@test.local',
        'rol' => 'CONTADOR', 'activo' => '1',
    ]);
    checkModule8($pdo->query('SELECT rol FROM users WHERE id = ' . $tempUserId)->fetchColumn() === 'CONTADOR', 'se actualiza el rol del usuario');
    configuracionResetPassword($tenantId, (int) $admin['id'], [
        'id' => $tempUserId, 'password' => 'Nueva12345!', 'password_confirmation' => 'Nueva12345!',
    ]);
    $hash = $pdo->query('SELECT password_hash FROM users WHERE id = ' . $tempUserId)->fetchColumn();
    checkModule8(password_verify('Nueva12345!', $hash), 'la contraseña restablecida es válida');
    expectConfigError(
        static fn() => configuracionUpdateUser(1, (int) $admin['id'], ['id' => (int) $admin['id'], 'nombre' => 'Administrador Demo', 'email' => 'admin@facturasv.local', 'rol' => 'VENDEDOR', 'activo' => '1']),
        'rol', 'el administrador no puede quitarse su propio rol'
    );
    configuracionUpdateUser($tenantId, (int) $admin['id'], [
        'id' => $tempUserId, 'nombre' => 'Usuario temporal M8', 'email' => $username . '@test.local',
        'rol' => 'CONTADOR', 'activo' => '0',
    ]);
    checkModule8((int) $pdo->query('SELECT activo FROM users WHERE id = ' . $tempUserId)->fetchColumn() === 0, 'se desactiva el usuario temporal');

    // Correo: validación y envío simulado.
    expectConfigError(
        static fn() => configuracionTestEmail(1, (int) $admin['id'], 'no-es-correo'),
        'destinatario', 'la prueba de correo rechaza un destinatario inválido'
    );
    $mailTest = configuracionTestEmail($tenantId, (int) $admin['id'], 'prueba@example.com');
    checkModule8($mailTest['estado'] === 'SIMULADO', 'la prueba de correo funciona en modo simulado');

    // Auditoría e historial.
    $audit = $pdo->query('SELECT COUNT(*) FROM configuracion_cambios WHERE id > ' . $auditStart)->fetchColumn();
    checkModule8((int) $audit >= 6, 'cada cambio queda en la auditoría');
    $dataAfter = configuracionData($tenantId, (int) $admin['id']);
    checkModule8($dataAfter['cambios'] !== [], 'el historial de cambios se muestra en la configuración');
    checkModule8(count($dataAfter['users']) >= 1, 'la configuración devuelve los usuarios');

    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $pdo->prepare("DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id IN ({$placeholders})")->execute($documentIds);
        $pdo->prepare("DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id IN ({$placeholders})")->execute($documentIds);
        $pdo->prepare("DELETE FROM documentos WHERE tenant_id = 1 AND id IN ({$placeholders})")->execute($documentIds);
    }
    if ($tempUserId !== null) $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $tempUserId]);
    if (is_array($tenantBefore)) {
        $pdo->prepare(
            'UPDATE tenants SET nit = :nit, razon_social = :razon_social, nombre_comercial = :nombre_comercial,
                codigo_actividad = :codigo_actividad, direccion = :direccion, telefono = :telefono,
                email = :email, numero_registro_mh = :numero_registro_mh WHERE id = 1'
        )->execute([
            'nit' => $tenantBefore['nit'], 'razon_social' => $tenantBefore['razon_social'],
            'nombre_comercial' => $tenantBefore['nombre_comercial'], 'codigo_actividad' => $tenantBefore['codigo_actividad'],
            'direccion' => $tenantBefore['direccion'], 'telefono' => $tenantBefore['telefono'],
            'email' => $tenantBefore['email'], 'numero_registro_mh' => $tenantBefore['numero_registro_mh'],
        ]);
    }
    if (is_array($configBefore)) {
        $pdo->prepare(
            'UPDATE tenant_config SET mh_mode = :mh_mode, timezone = :timezone, moneda = :moneda,
                distrito_emisor = :distrito_emisor, departamento_emisor = :departamento_emisor,
                municipio_emisor = :municipio_emisor, establecimiento = :establecimiento
             WHERE tenant_id = 1'
        )->execute([
            'mh_mode' => $configBefore['mh_mode'], 'timezone' => $configBefore['timezone'],
            'moneda' => $configBefore['moneda'], 'distrito_emisor' => $configBefore['distrito_emisor'],
            'departamento_emisor' => $configBefore['departamento_emisor'],
            'municipio_emisor' => $configBefore['municipio_emisor'],
            'establecimiento' => $configBefore['establecimiento'],
        ]);
    }
    if (is_array($sequenceBefore)) {
        $pdo->prepare(
            'UPDATE document_sequences SET prefijo = :prefijo, numero_inicial = :numero_inicial,
                numero_actual = :numero_actual, activo = :activo WHERE id = :id'
        )->execute([
            'prefijo' => $sequenceBefore['prefijo'], 'numero_inicial' => $sequenceBefore['numero_inicial'],
            'numero_actual' => $sequenceBefore['numero_actual'], 'activo' => $sequenceBefore['activo'],
            'id' => $sequenceBefore['id'],
        ]);
    }
    if ($sequence01 !== null) {
        $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type')
            ->execute(['value' => (int) $sequence01, 'type' => '01']);
    }
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
