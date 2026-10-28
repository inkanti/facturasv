<?php

declare(strict_types=1);

require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/empresa.php';
require_once __DIR__ . '/correo.php';

/**
 * Configuración de la empresa (módulo 8).
 *
 * Cubre datos fiscales, emisor del DTE, numeración, usuarios y correo.
 * Solo los usuarios ADMIN pueden modificarla; cada cambio se registra en
 * configuracion_cambios.
 */

final class ConfiguracionValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }
}

function configuracionTimezoneOptions(): array
{
    return [
        'America/El_Salvador' => 'El Salvador (GMT-06:00)',
        'America/Guatemala' => 'Guatemala (GMT-06:00)',
        'America/Tegucigalpa' => 'Honduras (GMT-06:00)',
        'America/Costa_Rica' => 'Costa Rica (GMT-06:00)',
        'America/Panama' => 'Panamá (GMT-05:00)',
        'America/Mexico_City' => 'Ciudad de México (GMT-06:00)',
        'America/Bogota' => 'Bogotá (GMT-05:00)',
        'America/Lima' => 'Lima (GMT-05:00)',
        'America/Santiago' => 'Santiago (GMT-04:00)',
        'America/Argentina/Buenos_Aires' => 'Buenos Aires (GMT-03:00)',
        'America/New_York' => 'Nueva York (GMT-05:00)',
        'America/Los_Angeles' => 'Los Ángeles (GMT-08:00)',
        'UTC' => 'UTC',
    ];
}

function configuracionRoleLabels(): array
{
    return ['ADMIN' => 'Administrador', 'VENDEDOR' => 'Vendedor', 'CONTADOR' => 'Contador'];
}

function configuracionRegisterChange(int $tenantId, ?int $userId, string $section, string $detail): void
{
    $statement = db()->prepare(
        'INSERT INTO configuracion_cambios (tenant_id, user_id, seccion, detalle)
         VALUES (:tenant_id, :user_id, :seccion, :detalle)'
    );
    $statement->execute([
        'tenant_id' => $tenantId, 'user_id' => $userId,
        'seccion' => mb_substr($section, 0, 30), 'detalle' => mb_substr($detail, 0, 1000),
    ]);
}

/** Crea las series que falten para la empresa actual. */
function configuracionEnsureSequences(int $tenantId, string $establishment): void
{
    $statement = db()->prepare(
        "INSERT IGNORE INTO document_sequences (tenant_id, tipo_dte, establecimiento, prefijo, numero_inicial, numero_actual)
         VALUES (:tenant_id, :tipo_dte, :establecimiento, :prefijo, 1, 0)"
    );
    foreach (array_keys(documentTypeDefinitions()) as $type) {
        $statement->execute([
            'tenant_id' => $tenantId,
            'tipo_dte' => $type,
            'establecimiento' => $establishment,
            'prefijo' => 'DTE',
        ]);
    }
    // La serie interna solo existe para empresas en modo interno; si la empresa
    // cambia de modo, se crea de forma perezosa al emitir el primer comprobante.
    if (tenantBillingMode(db(), $tenantId) === 'INTERNO') {
        $statement->execute([
            'tenant_id' => $tenantId,
            'tipo_dte' => 'IN',
            'establecimiento' => $establishment,
            'prefijo' => 'INT',
        ]);
    }
}

function configuracionLogoUrl(?string $path): string
{
    if (!is_string($path) || $path === '' || tenantLogoAbsolutePath($path) === null) return '';
    return url($path);
}

/** Convierte una marca de tiempo UTC al formato local de la empresa. */
function configuracionFechaLocal(?string $value, string $timezone): string
{
    if (!is_string($value) || trim($value) === '') return '';
    try {
        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone($timezone))
            ->format('d/m/Y H:i');
    } catch (Throwable) {
        return $value;
    }
}

function configuracionMailStatus(): array
{
    $settings = mailSettings();
    $configured = $settings['transport'] === 'mail'
        || ($settings['transport'] === 'smtp'
            && trim((string) $settings['host']) !== ''
            && trim((string) $settings['user']) !== ''
            && (string) $settings['pass'] !== '');
    return [
        'transport' => $settings['transport'],
        'host' => $settings['host'],
        'port' => (int) $settings['port'],
        'encryption' => $settings['encryption'],
        'from_email' => $settings['from_email'],
        'from_name' => $settings['from_name'],
        'user' => (string) $settings['user'],
        'configured' => $configured,
        'simulated' => $settings['transport'] === 'simulado',
    ];
}

function configuracionData(int $tenantId, int $currentUserId): array
{
    $pdo = db();
    $tenantStatement = $pdo->prepare(
        'SELECT id, nit, razon_social, nombre_comercial, codigo_actividad, direccion, telefono, email,
                numero_registro_mh, logo_path
         FROM tenants WHERE id = :id'
    );
    $tenantStatement->execute(['id' => $tenantId]);
    $tenant = $tenantStatement->fetch();
    if ($tenant === false) throw new RuntimeException('La empresa no existe.');

    $configStatement = $pdo->prepare(
        'SELECT mh_mode, modo_facturacion, timezone, moneda, distrito_emisor, departamento_emisor, municipio_emisor, establecimiento
         FROM tenant_config WHERE tenant_id = :tenant_id'
    );
    $configStatement->execute(['tenant_id' => $tenantId]);
    $config = $configStatement->fetch();
    if ($config === false) throw new RuntimeException('La empresa no tiene configuración.');

    configuracionEnsureSequences($tenantId, (string) $config['establecimiento']);
    $sequenceStatement = $pdo->prepare(
        'SELECT id, tipo_dte, establecimiento, prefijo, numero_inicial, numero_actual, activo
         FROM document_sequences
         WHERE tenant_id = :tenant_id AND establecimiento = :establecimiento
         ORDER BY tipo_dte'
    );
    $sequenceStatement->execute([
        'tenant_id' => $tenantId, 'establecimiento' => (string) $config['establecimiento'],
    ]);
    $definitions = documentAllTypeDefinitions();
    $sequences = [];
    foreach ($sequenceStatement->fetchAll() as $sequence) {
        $definition = $definitions[$sequence['tipo_dte']] ?? ['nombre' => $sequence['tipo_dte'], 'disponible' => false];
        $sequences[] = $sequence + [
            'nombre' => $definition['nombre'], 'disponible' => (bool) $definition['disponible'],
            'proximo' => (int) $sequence['numero_actual'] + 1,
        ];
    }

    $userStatement = $pdo->prepare(
        'SELECT id, nombre, usuario, email, rol, activo, last_login_at, created_at
         FROM users WHERE tenant_id = :tenant_id ORDER BY rol, nombre, id'
    );
    $userStatement->execute(['tenant_id' => $tenantId]);
    $users = [];
    foreach ($userStatement->fetchAll() as $user) {
        $user['es_actual'] = (int) $user['id'] === $currentUserId;
        $user['ultimo_acceso'] = configuracionFechaLocal($user['last_login_at'], (string) $config['timezone']);
        $users[] = $user;
    }

    $departmentStatement = $pdo->prepare(
        'SELECT id, codigo, nombre FROM mh_departamentos WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY codigo'
    );
    $departmentStatement->execute(['tenant_id' => $tenantId]);
    $municipalityStatement = $pdo->prepare(
        'SELECT id, departamento_id, codigo, nombre FROM mh_municipios
         WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY codigo'
    );
    $municipalityStatement->execute(['tenant_id' => $tenantId]);

    $auditStatement = $pdo->prepare(
        'SELECT c.id, c.seccion, c.detalle, c.created_at, u.nombre AS usuario
         FROM configuracion_cambios c LEFT JOIN users u ON u.id = c.user_id
         WHERE c.tenant_id = :tenant_id ORDER BY c.id DESC LIMIT 20'
    );
    $auditStatement->execute(['tenant_id' => $tenantId]);
    $audit = [];
    foreach ($auditStatement->fetchAll() as $change) {
        $change['fecha'] = configuracionFechaLocal($change['created_at'], (string) $config['timezone']);
        $audit[] = $change;
    }

    return [
        'tenant' => $tenant + ['logo_url' => configuracionLogoUrl($tenant['logo_path'] ?? null)],
        'config' => $config,
        'sequences' => $sequences,
        'users' => $users,
        'departamentos' => $departmentStatement->fetchAll(),
        'municipios' => $municipalityStatement->fetchAll(),
        'timezones' => configuracionTimezoneOptions(),
        'roles' => configuracionRoleLabels(),
        'mail' => configuracionMailStatus(),
        'cambios' => $audit,
    ];
}

function configuracionText(array $input, string $key, int $max): string
{
    $value = $input[$key] ?? '';
    return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
}

function configuracionPasswordErrors(string $password, string $confirmation): array
{
    $errors = [];
    if (strlen($password) < 10
        || preg_match('/[a-z]/', $password) !== 1
        || preg_match('/[A-Z]/', $password) !== 1
        || preg_match('/[0-9]/', $password) !== 1) {
        $errors['password'] = 'Use al menos 10 caracteres, con mayúsculas, minúsculas y números.';
    }
    if ($password !== $confirmation) {
        $errors['password_confirmation'] = 'Las contraseñas no coinciden.';
    }
    return $errors;
}

function configuracionUpdateCompany(int $tenantId, int $userId, array $input): string
{
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
    $errors = [];
    foreach ($data as $field => $value) {
        if ($value === '') $errors[$field] = 'Este campo es obligatorio.';
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
    if ($data['nit'] !== '') {
        $statement = db()->prepare('SELECT id FROM tenants WHERE nit = :nit AND id <> :id LIMIT 1');
        $statement->execute(['nit' => $data['nit'], 'id' => $tenantId]);
        if ($statement->fetch() !== false) $errors['nit'] = 'El NIT ya está registrado por otra empresa.';
    }
    if ($errors !== []) throw new ConfiguracionValidationException('Revise los datos de la empresa.', $errors);

    $statement = db()->prepare(
        'UPDATE tenants SET nit = :nit, razon_social = :razon_social, nombre_comercial = :nombre_comercial,
            codigo_actividad = :codigo_actividad, direccion = :direccion, telefono = :telefono,
            email = :email, numero_registro_mh = :numero_registro_mh
         WHERE id = :id'
    );
    $statement->execute($data + ['id' => $tenantId]);
    configuracionRegisterChange($tenantId, $userId, 'empresa', 'Datos fiscales y de contacto actualizados.');
    return 'Datos de la empresa actualizados correctamente.';
}

function configuracionUpdateEmitter(int $tenantId, int $userId, array $input): string
{
    $pdo = db();
    $data = [
        'establecimiento' => mb_strtoupper(configuracionText($input, 'establecimiento', 8)),
        'distrito_emisor' => configuracionText($input, 'distrito_emisor', 2),
        'departamento_emisor' => configuracionText($input, 'departamento_emisor', 2),
        'municipio_emisor' => configuracionText($input, 'municipio_emisor', 2),
        'timezone' => configuracionText($input, 'timezone', 64),
        'mh_mode' => strtoupper(configuracionText($input, 'mh_mode', 12)),
    ];
    $errors = [];
    if (preg_match('/^[A-Z0-9]{8}$/', $data['establecimiento']) !== 1) {
        $errors['establecimiento'] = 'Use 8 caracteres: establecimiento (4) y punto de venta (4), por ejemplo M001P001.';
    }
    if (preg_match('/^[0-9]{2}$/', $data['distrito_emisor']) !== 1) {
        $errors['distrito_emisor'] = 'Ingrese el código de distrito de dos dígitos.';
    }
    $department = $pdo->prepare(
        'SELECT id FROM mh_departamentos WHERE tenant_id = :tenant_id AND codigo = :codigo AND activo = 1 LIMIT 1'
    );
    $department->execute(['tenant_id' => $tenantId, 'codigo' => $data['departamento_emisor']]);
    $departmentId = $department->fetchColumn();
    if ($departmentId === false) $errors['departamento_emisor'] = 'Seleccione un departamento activo del catálogo.';
    if ($departmentId !== false) {
        $municipality = $pdo->prepare(
            'SELECT id FROM mh_municipios
             WHERE tenant_id = :tenant_id AND departamento_id = :departamento AND codigo = :codigo AND activo = 1 LIMIT 1'
        );
        $municipality->execute([
            'tenant_id' => $tenantId, 'departamento' => $departmentId, 'codigo' => $data['municipio_emisor'],
        ]);
        if ($municipality->fetchColumn() === false) {
            $errors['municipio_emisor'] = 'Seleccione un municipio activo del departamento elegido.';
        }
    }
    if (!array_key_exists($data['timezone'], configuracionTimezoneOptions())) {
        $errors['timezone'] = 'Seleccione una zona horaria válida.';
    }
    if ($data['mh_mode'] !== 'SIMULACION') {
        $errors['mh_mode'] = 'La conexión real al Ministerio de Hacienda aún no está disponible; el entorno debe permanecer en SIMULACIÓN.';
    }
    if ($errors !== []) throw new ConfiguracionValidationException('Revise los datos del emisor.', $errors);

    $statement = $pdo->prepare(
        'UPDATE tenant_config SET establecimiento = :establecimiento, distrito_emisor = :distrito_emisor,
            departamento_emisor = :departamento_emisor, municipio_emisor = :municipio_emisor,
            timezone = :timezone, mh_mode = :mh_mode
         WHERE tenant_id = :tenant_id'
    );
    $statement->execute($data + ['tenant_id' => $tenantId]);
    configuracionRegisterChange(
        $tenantId, $userId, 'emisor',
        'Emisor: establecimiento ' . $data['establecimiento'] . ', distrito ' . $data['distrito_emisor']
        . ', departamento ' . $data['departamento_emisor'] . ', municipio ' . $data['municipio_emisor']
        . ', zona horaria ' . $data['timezone'] . '.'
    );
    return 'Configuración del emisor actualizada. Se aplicará a los próximos documentos.';
}

function configuracionUpdateSequence(int $tenantId, int $userId, array $input): string
{
    $pdo = db();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) throw new RuntimeException('La serie solicitada no es válida.');
    $statement = $pdo->prepare(
        'SELECT * FROM document_sequences WHERE tenant_id = :tenant_id AND id = :id'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
    $sequence = $statement->fetch();
    if ($sequence === false) throw new RuntimeException('La serie no existe para esta empresa.');

    $prefix = configuracionText($input, 'prefijo', 20);
    $initial = filter_var($input['numero_inicial'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999999999999]]);
    $current = filter_var($input['numero_actual'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 999999999999999]]);
    $active = ($input['activo'] ?? '1') === '1' ? 1 : 0;
    $errors = [];
    if (preg_match('/^[A-Za-z0-9_-]*$/', $prefix) !== 1) {
        $errors['prefijo'] = 'Use solo letras, números, guion o guion bajo (máximo 20).';
    }
    if ($initial === false || $initial === null) $errors['numero_inicial'] = 'Indique un número inicial mayor que cero.';
    if ($current === false || $current === null) $errors['numero_actual'] = 'Indique un número actual válido.';
    if ($initial !== false && $initial !== null && $current !== false && $current !== null && $current < $initial - 1) {
        $errors['numero_actual'] = 'El número actual no puede ser menor que el inicial menos uno.';
    }

    $issuedStatement = $pdo->prepare(
        'SELECT numero_control FROM documentos
         WHERE tenant_id = :tenant_id AND tipo_dte = :tipo_dte AND numero_control IS NOT NULL'
    );
    $issuedStatement->execute(['tenant_id' => $tenantId, 'tipo_dte' => $sequence['tipo_dte']]);
    $lastIssued = 0;
    foreach ($issuedStatement->fetchAll(PDO::FETCH_COLUMN) as $control) {
        $parts = explode('-', (string) $control);
        $lastIssued = max($lastIssued, (int) end($parts));
    }
    if ($current !== false && $current !== null && $current < $lastIssued) {
        $errors['numero_actual'] = 'El último número emitido es ' . $lastIssued . '; el número actual no puede ser menor.';
    }
    if ($errors !== []) throw new ConfiguracionValidationException('Revise la numeración.', $errors);

    $update = $pdo->prepare(
        'UPDATE document_sequences SET prefijo = :prefijo, numero_inicial = :numero_inicial,
            numero_actual = :numero_actual, activo = :activo
         WHERE tenant_id = :tenant_id AND id = :id'
    );
    $update->execute([
        'prefijo' => $prefix, 'numero_inicial' => $initial, 'numero_actual' => $current,
        'activo' => $active, 'tenant_id' => $tenantId, 'id' => $id,
    ]);
    configuracionRegisterChange(
        $tenantId, $userId, 'numeracion',
        'Serie ' . $sequence['tipo_dte'] . ': prefijo "' . $prefix . '", inicial ' . $initial
        . ', actual ' . $current . ', próximo ' . ($current + 1) . ($active ? '.' : ' (inactiva).')
    );
    return 'Serie actualizada. El próximo número de control usará ' . ($current + 1) . '.';
}

function configuracionCreateUser(int $tenantId, int $userId, array $input): string
{
    $data = [
        'nombre' => configuracionText($input, 'nombre', 120),
        'usuario' => configuracionText($input, 'usuario', 50),
        'email' => mb_strtolower(configuracionText($input, 'email', 150)),
        'rol' => strtoupper(configuracionText($input, 'rol', 10)),
        'activo' => ($input['activo'] ?? '1') === '1' ? 1 : 0,
    ];
    $password = is_string($input['password'] ?? null) ? (string) $input['password'] : '';
    $confirmation = is_string($input['password_confirmation'] ?? null) ? (string) $input['password_confirmation'] : '';
    $errors = [];
    foreach ($data as $field => $value) {
        if ($value === '') $errors[$field] = 'Este campo es obligatorio.';
    }
    if ($data['usuario'] !== '' && preg_match('/^[A-Za-z0-9._-]{3,50}$/', $data['usuario']) !== 1) {
        $errors['usuario'] = 'Use de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.';
    }
    if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Ingrese un correo electrónico válido.';
    }
    if (!array_key_exists($data['rol'], configuracionRoleLabels())) {
        $errors['rol'] = 'Seleccione un rol válido.';
    }
    if ($data['usuario'] !== '') {
        $statement = db()->prepare('SELECT id FROM users WHERE usuario = :usuario LIMIT 1');
        $statement->execute(['usuario' => $data['usuario']]);
        if ($statement->fetch() !== false) $errors['usuario'] = 'El nombre de usuario ya está en uso.';
    }
    if ($data['email'] !== '') {
        $statement = db()->prepare('SELECT id FROM users WHERE tenant_id = :tenant_id AND email = :email LIMIT 1');
        $statement->execute(['tenant_id' => $tenantId, 'email' => $data['email']]);
        if ($statement->fetch() !== false) $errors['email'] = 'Ya existe un usuario con ese correo en la empresa.';
    }
    $errors += configuracionPasswordErrors($password, $confirmation);
    if ($errors !== []) throw new ConfiguracionValidationException('Revise los datos del usuario.', $errors);

    $statement = db()->prepare(
        'INSERT INTO users (tenant_id, nombre, usuario, email, password_hash, rol, activo)
         VALUES (:tenant_id, :nombre, :usuario, :email, :password_hash, :rol, :activo)'
    );
    $statement->execute($data + ['tenant_id' => $tenantId, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
    $newId = (int) db()->lastInsertId();
    configuracionRegisterChange(
        $tenantId, $userId, 'usuarios',
        'Usuario creado: ' . $data['usuario'] . ' (' . configuracionRoleLabels()[$data['rol']] . ')' . ($data['activo'] ? '.' : ', inactivo.')
    );
    return 'Usuario creado correctamente (#' . $newId . ').';
}

function configuracionUpdateUser(int $tenantId, int $userId, array $input): string
{
    $pdo = db();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) throw new RuntimeException('El usuario solicitado no es válido.');
    $statement = $pdo->prepare('SELECT * FROM users WHERE tenant_id = :tenant_id AND id = :id');
    $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
    $target = $statement->fetch();
    if ($target === false) throw new RuntimeException('El usuario no existe para esta empresa.');

    $data = [
        'nombre' => configuracionText($input, 'nombre', 120),
        'email' => mb_strtolower(configuracionText($input, 'email', 150)),
        'rol' => strtoupper(configuracionText($input, 'rol', 10)),
        'activo' => ($input['activo'] ?? '1') === '1' ? 1 : 0,
    ];
    $errors = [];
    foreach ($data as $field => $value) {
        if ($value === '') $errors[$field] = 'Este campo es obligatorio.';
    }
    if ($data['email'] !== '' && filter_var($data['email'], FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Ingrese un correo electrónico válido.';
    }
    if (!array_key_exists($data['rol'], configuracionRoleLabels())) $errors['rol'] = 'Seleccione un rol válido.';
    if ($data['email'] !== '') {
        $duplicate = $pdo->prepare('SELECT id FROM users WHERE tenant_id = :tenant_id AND email = :email AND id <> :id LIMIT 1');
        $duplicate->execute(['tenant_id' => $tenantId, 'email' => $data['email'], 'id' => $id]);
        if ($duplicate->fetch() !== false) $errors['email'] = 'Ya existe otro usuario con ese correo.';
    }
    if ($id === $userId && ($data['rol'] !== 'ADMIN' || $data['activo'] === 0)) {
        $errors['rol'] = 'No puede quitarse el rol de administrador ni desactivar su propia cuenta.';
    }
    if ($target['rol'] === 'ADMIN' && (int) $target['activo'] === 1
        && ($data['rol'] !== 'ADMIN' || $data['activo'] === 0)) {
        $admins = $pdo->prepare(
            "SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND rol = 'ADMIN' AND activo = 1 AND id <> :id"
        );
        $admins->execute(['tenant_id' => $tenantId, 'id' => $id]);
        if ((int) $admins->fetchColumn() === 0) {
            $errors['rol'] = 'Debe existir al menos un administrador activo.';
        }
    }
    if ($errors !== []) throw new ConfiguracionValidationException('Revise los datos del usuario.', $errors);

    $update = $pdo->prepare(
        'UPDATE users SET nombre = :nombre, email = :email, rol = :rol, activo = :activo
         WHERE tenant_id = :tenant_id AND id = :id'
    );
    $update->execute($data + ['tenant_id' => $tenantId, 'id' => $id]);
    configuracionRegisterChange(
        $tenantId, $userId, 'usuarios',
        'Usuario actualizado: ' . $target['usuario'] . ' (' . configuracionRoleLabels()[$data['rol']]
        . ($data['activo'] ? ', activo' : ', inactivo') . ').'
    );
    return 'Usuario actualizado correctamente.';
}

function configuracionResetPassword(int $tenantId, int $userId, array $input): string
{
    $pdo = db();
    $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) throw new RuntimeException('El usuario solicitado no es válido.');
    $statement = $pdo->prepare('SELECT id, usuario FROM users WHERE tenant_id = :tenant_id AND id = :id');
    $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
    $target = $statement->fetch();
    if ($target === false) throw new RuntimeException('El usuario no existe para esta empresa.');

    $password = is_string($input['password'] ?? null) ? (string) $input['password'] : '';
    $confirmation = is_string($input['password_confirmation'] ?? null) ? (string) $input['password_confirmation'] : '';
    $errors = configuracionPasswordErrors($password, $confirmation);
    if ($errors !== []) throw new ConfiguracionValidationException('Revise la contraseña.', $errors);

    $update = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE tenant_id = :tenant_id AND id = :id');
    $update->execute(['hash' => password_hash($password, PASSWORD_DEFAULT), 'tenant_id' => $tenantId, 'id' => $id]);
    configuracionRegisterChange($tenantId, $userId, 'usuarios', 'Contraseña restablecida: ' . $target['usuario'] . '.');
    return 'Contraseña actualizada. El usuario puede iniciar sesión con la nueva clave.';
}

function configuracionRemoveLogo(int $tenantId, int $userId): string
{
    $pdo = db();
    $statement = $pdo->prepare('SELECT logo_path FROM tenants WHERE id = :id');
    $statement->execute(['id' => $tenantId]);
    $path = $statement->fetchColumn();
    if (!is_string($path) || $path === '') throw new RuntimeException('La empresa no tiene logo.');
    $absolute = tenantLogoAbsolutePath($path);
    $pdo->prepare('UPDATE tenants SET logo_path = NULL WHERE id = :id')->execute(['id' => $tenantId]);
    if ($absolute !== null) @unlink($absolute);
    configuracionRegisterChange($tenantId, $userId, 'empresa', 'Logo eliminado.');
    return 'Logo eliminado. El PDF volverá a mostrar el recuadro de LOGO.';
}

function configuracionTestEmail(int $tenantId, int $userId, string $recipient): array
{
    $email = correoDireccion($recipient);
    if ($email === null) {
        throw new ConfiguracionValidationException('Revise el destinatario.', ['destinatario' => 'Ingrese un correo electrónico válido.']);
    }
    $settings = mailSettings();
    $tenant = db()->prepare('SELECT razon_social, nombre_comercial FROM tenants WHERE id = :id');
    $tenant->execute(['id' => $tenantId]);
    $company = $tenant->fetch() ?: ['razon_social' => 'FacturaSV', 'nombre_comercial' => 'FacturaSV'];
    $message = [
        'from_email' => $settings['from_email'], 'from_name' => $settings['from_name'],
        'to' => [['email' => $email, 'name' => 'Prueba']],
        'reply_to' => correoDireccion((string) $settings['reply_to']) ?? '',
        'subject' => 'Prueba de correo · ' . $company['nombre_comercial'],
        'html' => '<p>Este es un correo de prueba de <strong>' . htmlspecialchars((string) $company['razon_social'], ENT_QUOTES, 'UTF-8')
            . '</strong> enviado desde FacturaSV.</p><p>Si lo recibe, la configuración de correo funciona.</p>',
        'text' => 'Correo de prueba de ' . $company['razon_social'] . ' enviado desde FacturaSV. La configuración de correo funciona.',
        'attachments' => [],
    ];
    $status = 'ERROR';
    $error = null;
    try {
        $status = correoDespacharMensaje($message, $settings);
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
    $label = $status === 'SIMULADO' ? 'simulado' : strtolower($status);
    configuracionRegisterChange(
        $tenantId, $userId, 'correo',
        'Prueba de correo a ' . $email . ': ' . $label . ($error !== null ? ' · ' . $error : '') . '.'
    );
    if ($status === 'ERROR') {
        return ['estado' => 'ERROR', 'message' => 'No fue posible enviar la prueba: ' . $error];
    }
    return [
        'estado' => $status,
        'message' => $status === 'SIMULADO'
            ? 'Correo preparado en modo simulación (no se envió).'
            : 'Correo de prueba enviado a ' . $email . '.',
    ];
}
