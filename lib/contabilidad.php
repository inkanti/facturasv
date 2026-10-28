<?php

declare(strict_types=1);

/**
 * Módulo 13: contabilidad.
 *
 * Catálogo de cuentas por empresa, configuración de cuentas automáticas,
 * asientos con partida doble, libros, balanza y estados financieros.
 *
 * Los asientos automáticos se generan a partir de los DTE en estado PROCESADA
 * y se revierten cuando el DTE se invalida. Los documentos anteriores a la
 * instalación del módulo se pueden contabilizar con contabilidadSincronizar().
 */

final class ContabilidadValidationException extends RuntimeException
{
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

function contabilidadAccountTypes(): array
{
    return [
        'ACTIVO' => 'Activo',
        'PASIVO' => 'Pasivo',
        'PATRIMONIO' => 'Patrimonio',
        'INGRESO' => 'Ingreso',
        'COSTO' => 'Costo',
        'GASTO' => 'Gasto',
    ];
}

function contabilidadNatures(): array
{
    return ['DEUDORA' => 'Deudora', 'ACREEDORA' => 'Acreedora'];
}

function contabilidadEntryTypes(): array
{
    return [
        'MANUAL' => 'Manual',
        'VENTA' => 'Venta',
        'NOTA_CREDITO' => 'Nota de crédito',
        'NOTA_DEBITO' => 'Nota de débito',
        'EXPORTACION' => 'Exportación',
        'SUJETO_EXCLUIDO' => 'Sujeto excluido',
        'INVALIDACION' => 'Invalidación',
        'AJUSTE' => 'Ajuste',
    ];
}

function contabilidadDocumentTypeLabels(): array
{
    return [
        '01' => 'Factura',
        '03' => 'Comprobante de Crédito Fiscal',
        '05' => 'Nota de Crédito',
        '06' => 'Nota de Débito',
        '11' => 'Factura de Exportación',
        '14' => 'Factura de Sujeto Excluido',
        'IN' => 'Comprobante interno',
    ];
}

/**
 * Cuentas automáticas y el tipo contable que debe tener cada una.
 *
 * @return array<string,array{label:string,codigo:string,tipos:array<int,string>}>
 */
function contabilidadMapping(): array
{
    return [
        'cuenta_caja_id' => ['label' => 'Caja y bancos (contado)', 'codigo' => '1101', 'tipos' => ['ACTIVO']],
        'cuenta_cxc_id' => ['label' => 'Cuentas por cobrar clientes', 'codigo' => '1102', 'tipos' => ['ACTIVO']],
        'cuenta_ventas_gravadas_id' => ['label' => 'Ingresos por ventas gravadas', 'codigo' => '4101', 'tipos' => ['INGRESO']],
        'cuenta_ventas_exentas_id' => ['label' => 'Ingresos por ventas exentas', 'codigo' => '4102', 'tipos' => ['INGRESO']],
        'cuenta_ventas_no_sujetas_id' => ['label' => 'Ingresos por ventas no sujetas', 'codigo' => '4103', 'tipos' => ['INGRESO']],
        'cuenta_exportaciones_id' => ['label' => 'Ingresos por exportaciones', 'codigo' => '4105', 'tipos' => ['INGRESO']],
        'cuenta_devoluciones_id' => ['label' => 'Devoluciones y descuentos sobre ventas', 'codigo' => '4104', 'tipos' => ['INGRESO']],
        'cuenta_iva_debito_id' => ['label' => 'IVA débito fiscal', 'codigo' => '2102', 'tipos' => ['PASIVO']],
        'cuenta_iva_retenido_por_cobrar_id' => ['label' => 'IVA retenido por cobrar (1 %)', 'codigo' => '1104', 'tipos' => ['ACTIVO']],
        'cuenta_compras_id' => ['label' => 'Compras de bienes y servicios', 'codigo' => '5102', 'tipos' => ['COSTO', 'GASTO']],
        'cuenta_cxp_id' => ['label' => 'Cuentas por pagar proveedores', 'codigo' => '2101', 'tipos' => ['PASIVO']],
        'cuenta_retencion_renta_por_pagar_id' => ['label' => 'Retención de renta por pagar', 'codigo' => '2103', 'tipos' => ['PASIVO']],
    ];
}

/**
 * Catálogo base inspirado en las cuentas de uso habitual para una empresa
 * salvadoreña. Es solo un punto de partida: el administrador puede agregar,
 * renombrar y desactivar cuentas.
 *
 * @return array<int,array{0:string,1:string,2:string,3:string}>
 */
function contabilidadSeedAccounts(): array
{
    return [
        ['1101', 'Efectivo y equivalentes de efectivo', 'ACTIVO', 'DEUDORA'],
        ['1102', 'Cuentas por cobrar clientes', 'ACTIVO', 'DEUDORA'],
        ['1103', 'IVA crédito fiscal', 'ACTIVO', 'DEUDORA'],
        ['1104', 'IVA retenido por cobrar', 'ACTIVO', 'DEUDORA'],
        ['1105', 'Retención de renta por cobrar', 'ACTIVO', 'DEUDORA'],
        ['1106', 'Inventario de mercaderías', 'ACTIVO', 'DEUDORA'],
        ['1201', 'Propiedad, planta y equipo', 'ACTIVO', 'DEUDORA'],
        ['1202', 'Depreciación acumulada', 'ACTIVO', 'ACREEDORA'],
        ['2101', 'Cuentas por pagar proveedores', 'PASIVO', 'ACREEDORA'],
        ['2102', 'IVA débito fiscal', 'PASIVO', 'ACREEDORA'],
        ['2103', 'Retención de renta por pagar', 'PASIVO', 'ACREEDORA'],
        ['2104', 'IVA retenido por pagar', 'PASIVO', 'ACREEDORA'],
        ['2105', 'Remuneraciones por pagar', 'PASIVO', 'ACREEDORA'],
        ['2201', 'Préstamos bancarios', 'PASIVO', 'ACREEDORA'],
        ['3101', 'Capital social', 'PATRIMONIO', 'ACREEDORA'],
        ['3102', 'Resultados acumulados', 'PATRIMONIO', 'ACREEDORA'],
        ['3103', 'Resultado del ejercicio', 'PATRIMONIO', 'ACREEDORA'],
        ['4101', 'Ingresos por ventas gravadas', 'INGRESO', 'ACREEDORA'],
        ['4102', 'Ingresos por ventas exentas', 'INGRESO', 'ACREEDORA'],
        ['4103', 'Ingresos por ventas no sujetas', 'INGRESO', 'ACREEDORA'],
        ['4104', 'Devoluciones y descuentos sobre ventas', 'INGRESO', 'DEUDORA'],
        ['4105', 'Ingresos por exportaciones', 'INGRESO', 'ACREEDORA'],
        ['5101', 'Costo de ventas', 'COSTO', 'DEUDORA'],
        ['5102', 'Compras de bienes y servicios', 'COSTO', 'DEUDORA'],
        ['6101', 'Gastos de venta', 'GASTO', 'DEUDORA'],
        ['6102', 'Gastos de administración', 'GASTO', 'DEUDORA'],
        ['6103', 'Gastos financieros', 'GASTO', 'DEUDORA'],
        ['6104', 'Otros gastos', 'GASTO', 'DEUDORA'],
    ];
}

/**
 * Crea el catálogo, la configuración y la secuencia de una empresa si faltan.
 * Es idempotente: se puede llamar en cada carga del módulo o al crear empresas.
 */
function contabilidadEnsureTenant(int $tenantId): void
{
    $pdo = db();
    $insert = $pdo->prepare(
        'INSERT IGNORE INTO contabilidad_cuentas
            (tenant_id, codigo, nombre, tipo, naturaleza, permite_movimiento, activa)
         VALUES (:tenant_id, :codigo, :nombre, :tipo, :naturaleza, 1, 1)'
    );
    foreach (contabilidadSeedAccounts() as $account) {
        $insert->execute([
            'tenant_id' => $tenantId,
            'codigo' => $account[0],
            'nombre' => $account[1],
            'tipo' => $account[2],
            'naturaleza' => $account[3],
        ]);
    }

    $exists = $pdo->prepare('SELECT COUNT(*) FROM contabilidad_config WHERE tenant_id = :tenant_id');
    $exists->execute(['tenant_id' => $tenantId]);
    if ((int) $exists->fetchColumn() === 0) {
        $accounts = $pdo->prepare('SELECT codigo, id FROM contabilidad_cuentas WHERE tenant_id = :tenant_id');
        $accounts->execute(['tenant_id' => $tenantId]);
        $byCode = [];
        foreach ($accounts->fetchAll() as $row) {
            $byCode[(string) $row['codigo']] = (int) $row['id'];
        }
        $columns = ['tenant_id'];
        $values = [':tenant_id'];
        $params = ['tenant_id' => $tenantId];
        foreach (contabilidadMapping() as $field => $definition) {
            if (!isset($byCode[$definition['codigo']])) {
                throw new RuntimeException('No se pudo inicializar el catálogo contable de la empresa.');
            }
            $columns[] = $field;
            $values[] = ':' . $field;
            $params[$field] = $byCode[$definition['codigo']];
        }
        $pdo->prepare(
            'INSERT IGNORE INTO contabilidad_config (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')'
        )->execute($params);
    }

    $pdo->prepare(
        'INSERT IGNORE INTO contabilidad_secuencias (tenant_id, numero_actual) VALUES (:tenant_id, 0)'
    )->execute(['tenant_id' => $tenantId]);
}

function contabilidadAccounts(int $tenantId, bool $onlyActive = false): array
{
    $statement = db()->prepare(
        'SELECT id, codigo, nombre, tipo, naturaleza, permite_movimiento, activa
         FROM contabilidad_cuentas WHERE tenant_id = :tenant_id'
        . ($onlyActive ? ' AND activa = 1' : '')
        . ' ORDER BY codigo'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    return $statement->fetchAll();
}

function contabilidadAccount(int $tenantId, int $accountId): ?array
{
    $statement = db()->prepare(
        'SELECT id, codigo, nombre, tipo, naturaleza, permite_movimiento, activa
         FROM contabilidad_cuentas WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $accountId]);
    $account = $statement->fetch();
    return $account === false ? null : $account;
}

function contabilidadAccountHasMovements(int $tenantId, int $accountId): bool
{
    $statement = db()->prepare(
        'SELECT COUNT(*) FROM contabilidad_partidas WHERE tenant_id = :tenant_id AND cuenta_id = :cuenta_id'
    );
    $statement->execute(['tenant_id' => $tenantId, 'cuenta_id' => $accountId]);
    return (int) $statement->fetchColumn() > 0;
}

function contabilidadAccountInConfig(int $tenantId, int $accountId): bool
{
    $config = contabilidadConfig($tenantId);
    foreach (array_keys(contabilidadMapping()) as $field) {
        if ((int) ($config[$field] ?? 0) === $accountId) {
            return true;
        }
    }
    return false;
}

function contabilidadAudit(int $tenantId, ?int $userId, string $detail): void
{
    $statement = db()->prepare(
        'INSERT INTO configuracion_cambios (tenant_id, user_id, seccion, detalle)
         VALUES (:tenant_id, :user_id, :seccion, :detalle)'
    );
    $statement->execute([
        'tenant_id' => $tenantId,
        'user_id' => $userId,
        'seccion' => 'contabilidad',
        'detalle' => mb_substr($detail, 0, 1000),
    ]);
}

/**
 * Crea o actualiza una cuenta. El código solo se define al crear.
 *
 * @return array{message:string,id:int}
 */
function contabilidadSaveAccount(array $user, array $input): array
{
    $tenantId = (int) $user['tenant_id'];
    $pdo = db();
    contabilidadEnsureTenant($tenantId);

    $idValue = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $id = ($idValue === false || $idValue === null) ? null : (int) $idValue;
    $existing = $id === null ? null : contabilidadAccount($tenantId, $id);
    if ($id !== null && $existing === null) {
        throw new RuntimeException('La cuenta contable no existe para esta empresa.');
    }

    $codigo = trim((string) ($input['codigo'] ?? ($existing['codigo'] ?? '')));
    $nombre = trim((string) ($input['nombre'] ?? ''));
    $tipo = strtoupper(trim((string) ($input['tipo'] ?? '')));
    $naturaleza = strtoupper(trim((string) ($input['naturaleza'] ?? '')));
    $permiteMovimiento = ((string) ($input['permite_movimiento'] ?? '1')) === '1' ? 1 : 0;
    $activa = ((string) ($input['activa'] ?? '1')) === '1' ? 1 : 0;
    $errors = [];

    if ($existing === null) {
        if (preg_match('/^[0-9]{1,12}$/', $codigo) !== 1) {
            $errors['codigo'] = 'Use de 1 a 12 dígitos, por ejemplo 1101.';
        }
        if ($nombre === '' || mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            $errors['nombre'] = 'El nombre debe tener entre 3 y 150 caracteres.';
        }
        if (!array_key_exists($tipo, contabilidadAccountTypes())) {
            $errors['tipo'] = 'Seleccione un tipo de cuenta válido.';
        }
        if (!array_key_exists($naturaleza, contabilidadNatures())) {
            $errors['naturaleza'] = 'Seleccione la naturaleza de la cuenta.';
        }
        if ($errors === []) {
            $duplicate = $pdo->prepare(
                'SELECT id FROM contabilidad_cuentas WHERE tenant_id = :tenant_id AND codigo = :codigo LIMIT 1'
            );
            $duplicate->execute(['tenant_id' => $tenantId, 'codigo' => $codigo]);
            if ($duplicate->fetch() !== false) {
                $errors['codigo'] = 'Ya existe una cuenta con ese código en la empresa.';
            }
        }
        if ($errors !== []) {
            throw new ContabilidadValidationException('Revise los datos de la cuenta.', $errors);
        }

        $statement = $pdo->prepare(
            'INSERT INTO contabilidad_cuentas
                (tenant_id, codigo, nombre, tipo, naturaleza, permite_movimiento, activa)
             VALUES (:tenant_id, :codigo, :nombre, :tipo, :naturaleza, :permite_movimiento, :activa)'
        );
        $statement->execute([
            'tenant_id' => $tenantId, 'codigo' => $codigo, 'nombre' => $nombre,
            'tipo' => $tipo, 'naturaleza' => $naturaleza,
            'permite_movimiento' => $permiteMovimiento, 'activa' => $activa,
        ]);
        $id = (int) $pdo->lastInsertId();
        contabilidadAudit($tenantId, (int) $user['id'], 'Cuenta contable creada: ' . $codigo . ' ' . $nombre);
        return ['message' => 'Cuenta contable creada correctamente.', 'id' => $id];
    }

    $hasMovements = contabilidadAccountHasMovements($tenantId, (int) $existing['id']);
    if ($nombre === '' || mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
        $errors['nombre'] = 'El nombre debe tener entre 3 y 150 caracteres.';
    }
    if (!array_key_exists($tipo, contabilidadAccountTypes())) {
        $errors['tipo'] = 'Seleccione un tipo de cuenta válido.';
    }
    if (!array_key_exists($naturaleza, contabilidadNatures())) {
        $errors['naturaleza'] = 'Seleccione la naturaleza de la cuenta.';
    }
    if ($hasMovements && ($tipo !== $existing['tipo'] || $naturaleza !== $existing['naturaleza'])) {
        $errors['tipo'] = 'No puede cambiar el tipo ni la naturaleza de una cuenta con movimientos.';
    }
    if ($hasMovements && $permiteMovimiento === 0) {
        $errors['permite_movimiento'] = 'No puede quitar el movimiento a una cuenta que ya tiene partidas.';
    }
    if ($activa === 0 && (int) $existing['activa'] === 1) {
        if ($hasMovements) {
            $errors['activa'] = 'No puede desactivar una cuenta con movimientos contables.';
        } elseif (contabilidadAccountInConfig($tenantId, (int) $existing['id'])) {
            $errors['activa'] = 'No puede desactivar una cuenta usada en la configuración automática.';
        }
    }
    if ($errors !== []) {
        throw new ContabilidadValidationException('Revise los datos de la cuenta.', $errors);
    }

    $pdo->prepare(
        'UPDATE contabilidad_cuentas SET nombre = :nombre, tipo = :tipo, naturaleza = :naturaleza,
            permite_movimiento = :permite_movimiento, activa = :activa
         WHERE tenant_id = :tenant_id AND id = :id'
    )->execute([
        'nombre' => $nombre, 'tipo' => $tipo, 'naturaleza' => $naturaleza,
        'permite_movimiento' => $permiteMovimiento, 'activa' => $activa,
        'tenant_id' => $tenantId, 'id' => (int) $existing['id'],
    ]);
    contabilidadAudit($tenantId, (int) $user['id'], 'Cuenta contable actualizada: ' . $existing['codigo'] . ' ' . $nombre);
    return ['message' => 'Cuenta contable actualizada correctamente.', 'id' => (int) $existing['id']];
}

function contabilidadToggleAccount(array $user, int $accountId, bool $active): string
{
    $tenantId = (int) $user['tenant_id'];
    $account = contabilidadAccount($tenantId, $accountId);
    if ($account === null) {
        throw new RuntimeException('La cuenta contable no existe para esta empresa.');
    }
    contabilidadSaveAccount($user, [
        'id' => $accountId,
        'codigo' => $account['codigo'],
        'nombre' => $account['nombre'],
        'tipo' => $account['tipo'],
        'naturaleza' => $account['naturaleza'],
        'permite_movimiento' => (string) $account['permite_movimiento'],
        'activa' => $active ? '1' : '0',
    ]);
    return $active ? 'Cuenta contable activada.' : 'Cuenta contable desactivada.';}

function contabilidadConfig(int $tenantId): array
{
    contabilidadEnsureTenant($tenantId);
    $statement = db()->prepare('SELECT * FROM contabilidad_config WHERE tenant_id = :tenant_id LIMIT 1');
    $statement->execute(['tenant_id' => $tenantId]);
    $config = $statement->fetch();
    if ($config === false) {
        throw new RuntimeException('La empresa no tiene configuración contable.');
    }
    return $config;
}

function contabilidadSaveConfig(array $user, array $input): string
{
    $tenantId = (int) $user['tenant_id'];
    $config = contabilidadConfig($tenantId);
    $errors = [];
    $changes = [];

    foreach (contabilidadMapping() as $field => $definition) {
        $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false || $value === null) {
            $errors[$field] = 'Seleccione una cuenta para ' . $definition['label'] . '.';
            continue;
        }
        $account = contabilidadAccount($tenantId, (int) $value);
        if ($account === null) {
            $errors[$field] = 'La cuenta seleccionada no existe.';
            continue;
        }
        if ((int) $account['activa'] !== 1 || (int) $account['permite_movimiento'] !== 1) {
            $errors[$field] = 'La cuenta debe estar activa y permitir movimientos.';
            continue;
        }
        if (!in_array($account['tipo'], $definition['tipos'], true)) {
            $errors[$field] = 'La cuenta debe ser de tipo ' . implode(' o ', $definition['tipos']) . '.';
            continue;
        }
        if ((int) $config[$field] !== (int) $account['id']) {
            $changes[] = $definition['label'] . ': ' . $account['codigo'] . ' ' . $account['nombre'];
        }
    }
    if ($errors !== []) {
        throw new ContabilidadValidationException('Revise la configuración contable.', $errors);
    }
    if ($changes === []) {
        return 'La configuración contable no tiene cambios.';
    }

    $columns = [];
    $params = ['tenant_id' => $tenantId];
    foreach (array_keys(contabilidadMapping()) as $field) {
        $columns[] = $field . ' = :' . $field;
        $params[$field] = (int) $input[$field];
    }
    db()->prepare('UPDATE contabilidad_config SET ' . implode(', ', $columns) . ' WHERE tenant_id = :tenant_id')
        ->execute($params);
    contabilidadAudit($tenantId, (int) $user['id'], 'Configuración contable actualizada. ' . implode('; ', $changes));
    return 'Configuración contable actualizada correctamente.';
}

/* -------------------------------------------------------------------------
 * Asientos
 * ---------------------------------------------------------------------- */

function contabilidadCents(mixed $value): int
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], '', $value);
    }
    if (!is_numeric($value)) {
        return 0;
    }
    return (int) round(((float) $value) * 100);
}

function contabilidadMoney(mixed $value): float
{
    return contabilidadCents($value) / 100;
}

/**
 * Devuelve los centavos de un importe escrito por el usuario, o null si el
 * formato no es válido. Una cadena vacía equivale a cero.
 */
function contabilidadParseAmount(mixed $value): ?int
{
    $text = trim((string) $value);
    if ($text === '') {
        return 0;
    }
    if (preg_match('/^[0-9]{1,13}(\.[0-9]{1,2})?$/', $text) !== 1) {
        return null;
    }
    return (int) round(((float) $text) * 100);
}

function contabilidadDate(mixed $value): ?string
{
    $text = trim((string) $value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text);
    return $date !== false && $date->format('Y-m-d') === $text ? $text : null;
}

function contabilidadTimezone(int $tenantId): string
{
    $statement = db()->prepare('SELECT timezone FROM tenant_config WHERE tenant_id = :tenant_id');
    $statement->execute(['tenant_id' => $tenantId]);
    $timezone = $statement->fetchColumn();
    return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
        ? $timezone
        : 'America/El_Salvador';
}

/**
 * Normaliza el rango de fechas. Por omisión, el mes en curso.
 *
 * @return array{desde:string,hasta:string,mes:string}
 */
function contabilidadRange(array $input, ?string $timezone = null): array
{
    $timezone = $timezone ?? 'America/El_Salvador';
    $today = new DateTimeImmutable('now', new DateTimeZone($timezone));
    $from = contabilidadDate($input['fecha_desde'] ?? '') ?? $today->modify('first day of this month')->format('Y-m-d');
    $to = contabilidadDate($input['fecha_hasta'] ?? '') ?? $today->format('Y-m-d');
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    $limit = (new DateTimeImmutable($to))->modify('-3 years')->format('Y-m-d');
    if ($from < $limit) {
        $from = $limit;
    }
    return ['desde' => $from, 'hasta' => $to, 'mes' => substr($to, 0, 7)];
}

function contabilidadNextNumber(PDO $pdo, int $tenantId): int
{
    $pdo->prepare(
        'INSERT INTO contabilidad_secuencias (tenant_id, numero_actual) VALUES (:tenant_id, 0)
         ON DUPLICATE KEY UPDATE numero_actual = numero_actual'
    )->execute(['tenant_id' => $tenantId]);
    $statement = $pdo->prepare('SELECT numero_actual FROM contabilidad_secuencias WHERE tenant_id = :tenant_id FOR UPDATE');
    $statement->execute(['tenant_id' => $tenantId]);
    $current = (int) $statement->fetchColumn();
    $next = $current + 1;
    $pdo->prepare('UPDATE contabilidad_secuencias SET numero_actual = :numero WHERE tenant_id = :tenant_id')
        ->execute(['numero' => $next, 'tenant_id' => $tenantId]);
    return $next;
}

/**
 * Inserta un asiento y sus partidas. El llamador controla la transacción.
 *
 * @param array{fecha:string,concepto:string,tipo:string,documento_id?:?int} $data
 * @param array<int,array{cuenta_id:mixed,descripcion?:string,debe:mixed,haber:mixed}> $lines
 */
function contabilidadInsertEntry(PDO $pdo, int $tenantId, int $userId, array $data, array $lines): int
{
    if (count($lines) > 100) {
        throw new ContabilidadValidationException('El asiento tiene demasiadas líneas.', [
            'lineas' => 'Un asiento admite como máximo 100 líneas.',
        ]);
    }
    $normalized = [];
    $totalDebe = 0;
    $totalHaber = 0;
    $checkedAccounts = [];

    foreach (array_values($lines) as $index => $line) {
        $accountId = (int) ($line['cuenta_id'] ?? 0);
        $debe = contabilidadParseAmount($line['debe'] ?? 0);
        $haber = contabilidadParseAmount($line['haber'] ?? 0);
        if ($accountId <= 0) {
            throw new ContabilidadValidationException('La línea ' . ($index + 1) . ' no tiene una cuenta válida.', ['lineas' => 'Seleccione una cuenta en cada línea.']);
        }
        if ($debe === null || $haber === null) {
            throw new ContabilidadValidationException('Revise los importes del asiento.', ['lineas' => 'Use importes con hasta dos decimales.']);
        }
        if ($debe < 0 || $haber < 0 || ($debe > 0 && $haber > 0) || ($debe === 0 && $haber === 0)) {
            throw new ContabilidadValidationException('Revise los importes del asiento.', [
                'lineas' => 'Cada línea debe tener un importe en el debe o en el haber, no ambos.',
            ]);
        }
        if (!isset($checkedAccounts[$accountId])) {
            $account = contabilidadAccount($tenantId, $accountId);
            if ($account === null) {
                throw new ContabilidadValidationException('Una de las cuentas del asiento no existe.', ['lineas' => 'Seleccione cuentas de la empresa.']);
            }
            if ((int) $account['activa'] !== 1 || (int) $account['permite_movimiento'] !== 1) {
                throw new ContabilidadValidationException('Una de las cuentas está inactiva o no permite movimientos.', [
                    'lineas' => 'La cuenta ' . $account['codigo'] . ' no admite movimientos.',
                ]);
            }
            $checkedAccounts[$accountId] = true;
        }
        $description = trim((string) ($line['descripcion'] ?? ''));
        if ($description === '') {
            $description = (string) $data['concepto'];
        }
        $normalized[] = [
            'cuenta_id' => $accountId,
            'descripcion' => mb_substr($description, 0, 200),
            'debe' => $debe,
            'haber' => $haber,
        ];
        $totalDebe += $debe;
        $totalHaber += $haber;
    }

    if (count($normalized) < 2) {
        throw new ContabilidadValidationException('El asiento necesita al menos dos líneas.', [
            'lineas' => 'Agregue al menos una cuenta al debe y otra al haber.',
        ]);
    }
    if ($totalDebe <= 0 || $totalDebe !== $totalHaber) {
        throw new ContabilidadValidationException('El asiento no está balanceado.', [
            'lineas' => 'El total del debe debe ser igual al total del haber y mayor que cero.',
        ]);
    }

    $date = contabilidadDate($data['fecha'] ?? '');
    if ($date === null) {
        throw new ContabilidadValidationException('La fecha del asiento no es válida.', ['fecha' => 'Seleccione una fecha válida.']);
    }
    $concept = trim((string) ($data['concepto'] ?? ''));
    if (mb_strlen($concept) < 3 || mb_strlen($concept) > 300) {
        throw new ContabilidadValidationException('Revise el concepto del asiento.', [
            'concepto' => 'El concepto debe tener entre 3 y 300 caracteres.',
        ]);
    }

    $number = contabilidadNextNumber($pdo, $tenantId);
    $pdo->prepare(
        'INSERT INTO contabilidad_asientos
            (tenant_id, numero, fecha, concepto, tipo, estado, documento_id, total_debe, total_haber, created_by)
         VALUES
            (:tenant_id, :numero, :fecha, :concepto, :tipo, \'CONTABILIZADO\', :documento_id, :total_debe, :total_haber, :created_by)'
    )->execute([
        'tenant_id' => $tenantId,
        'numero' => $number,
        'fecha' => $date,
        'concepto' => $concept,
        'tipo' => $data['tipo'] ?? 'MANUAL',
        'documento_id' => $data['documento_id'] ?? null,
        'total_debe' => number_format($totalDebe / 100, 2, '.', ''),
        'total_haber' => number_format($totalHaber / 100, 2, '.', ''),
        'created_by' => $userId,
    ]);
    $entryId = (int) $pdo->lastInsertId();
    $insertLine = $pdo->prepare(
        'INSERT INTO contabilidad_partidas
            (tenant_id, asiento_id, linea, cuenta_id, descripcion, debe, haber)
         VALUES (:tenant_id, :asiento_id, :linea, :cuenta_id, :descripcion, :debe, :haber)'
    );
    foreach ($normalized as $index => $line) {
        $insertLine->execute([
            'tenant_id' => $tenantId,
            'asiento_id' => $entryId,
            'linea' => $index + 1,
            'cuenta_id' => $line['cuenta_id'],
            'descripcion' => $line['descripcion'],
            'debe' => number_format($line['debe'] / 100, 2, '.', ''),
            'haber' => number_format($line['haber'] / 100, 2, '.', ''),
        ]);
    }
    return $entryId;
}

/**
 * Crea un asiento manual con partida doble.
 *
 * @return array{id:int,numero:int,message:string}
 */
function contabilidadCreateManual(array $user, array $input): array
{
    $tenantId = (int) $user['tenant_id'];
    $pdo = db();
    contabilidadEnsureTenant($tenantId);

    $date = contabilidadDate($input['fecha'] ?? '');
    $errors = [];
    if ($date === null) {
        $errors['fecha'] = 'Seleccione una fecha válida.';
    } else {
        $today = (new DateTimeImmutable('now', new DateTimeZone(contabilidadTimezone($tenantId))))->format('Y-m-d');
        if ($date > $today) {
            $errors['fecha'] = 'La fecha del asiento no puede ser futura.';
        }
    }
    $concept = trim((string) ($input['concepto'] ?? ''));
    if (mb_strlen($concept) < 3 || mb_strlen($concept) > 300) {
        $errors['concepto'] = 'El concepto debe tener entre 3 y 300 caracteres.';
    }
    if ($errors !== []) {
        throw new ContabilidadValidationException('Revise los datos del asiento.', $errors);
    }

    $rawLines = $input['lineas'] ?? [];
    if (!is_array($rawLines)) {
        throw new ContabilidadValidationException('Agregue las líneas del asiento.', ['lineas' => 'El asiento no tiene líneas.']);
    }
    if (count($rawLines) > 50) {
        throw new ContabilidadValidationException('El asiento tiene demasiadas líneas.', [
            'lineas' => 'Un asiento manual admite como máximo 50 líneas.',
        ]);
    }
    $lines = [];
    foreach (array_values($rawLines) as $line) {
        if (!is_array($line)) {
            continue;
        }
        $accountId = (int) ($line['cuenta_id'] ?? 0);
        $debeText = trim((string) ($line['debe'] ?? ''));
        $haberText = trim((string) ($line['haber'] ?? ''));
        if ($accountId === 0 && $debeText === '' && $haberText === '') {
            continue;
        }
        $lines[] = [
            'cuenta_id' => $accountId,
            'descripcion' => trim((string) ($line['descripcion'] ?? '')),
            'debe' => $debeText,
            'haber' => $haberText,
        ];
    }
    if (count($lines) < 2) {
        throw new ContabilidadValidationException('El asiento necesita al menos dos líneas.', [
            'lineas' => 'Agregue al menos una cuenta al debe y otra al haber.',
        ]);
    }

    $pdo->beginTransaction();
    try {
        $entryId = contabilidadInsertEntry($pdo, $tenantId, (int) $user['id'], [
            'fecha' => $date,
            'concepto' => $concept,
            'tipo' => 'MANUAL',
            'documento_id' => null,
        ], $lines);
        $number = (int) $pdo->query(
            'SELECT numero FROM contabilidad_asientos WHERE id = ' . $entryId
        )->fetchColumn();
        contabilidadAudit($tenantId, (int) $user['id'], 'Asiento manual #' . $number . ': ' . $concept);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    return ['id' => $entryId, 'numero' => $number, 'message' => 'Asiento contable registrado correctamente.'];
}

function contabilidadAnularEntry(array $user, int $entryId, string $reason): string
{
    $tenantId = (int) $user['tenant_id'];
    $reason = trim($reason);
    if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) {
        throw new ContabilidadValidationException('Explique el motivo de la anulación.', [
            'motivo' => 'El motivo debe tener entre 5 y 300 caracteres.',
        ]);
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'SELECT id, numero, tipo, estado FROM contabilidad_asientos
             WHERE tenant_id = :tenant_id AND id = :id FOR UPDATE'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $entryId]);
        $entry = $statement->fetch();
        if ($entry === false) {
            throw new RuntimeException('El asiento contable no existe para esta empresa.');
        }
        if ($entry['estado'] !== 'CONTABILIZADO') {
            throw new RuntimeException('El asiento ya está anulado.');
        }
        if (!in_array($entry['tipo'], ['MANUAL', 'AJUSTE'], true)) {
            throw new RuntimeException('Solo se anulan asientos manuales o de ajuste. Para revertir un DTE, invalide el documento.');
        }
        $pdo->prepare(
            "UPDATE contabilidad_asientos
             SET estado = 'ANULADO', motivo_anulacion = :motivo,
                 anulado_at = UTC_TIMESTAMP(), anulado_por = :user_id
             WHERE tenant_id = :tenant_id AND id = :id AND estado = 'CONTABILIZADO'"
        )->execute([
            'motivo' => $reason,
            'user_id' => (int) $user['id'],
            'tenant_id' => $tenantId,
            'id' => $entryId,
        ]);
        contabilidadAudit($tenantId, (int) $user['id'], 'Asiento #' . $entry['numero'] . ' anulado: ' . $reason);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    return 'Asiento anulado correctamente.';
}

/* -------------------------------------------------------------------------
 * Asientos automáticos desde DTE
 * ---------------------------------------------------------------------- */

function contabilidadDocumentEntryType(string $documentType): string
{
    return match ($documentType) {
        '01', '03', 'IN' => 'VENTA',
        '05' => 'NOTA_CREDITO',
        '06' => 'NOTA_DEBITO',
        '11' => 'EXPORTACION',
        '14' => 'SUJETO_EXCLUIDO',
        default => throw new RuntimeException('El tipo de DTE ' . $documentType . ' no tiene asiento contable automático.'),
    };
}

function contabilidadDocumentRow(PDO $pdo, int $tenantId, int $documentId): ?array
{
    $statement = $pdo->prepare(
        'SELECT d.*, c.nombre AS cliente_nombre, co.codigo AS condicion_codigo
         FROM documentos d
         INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
         INNER JOIN mh_condiciones_operacion co ON co.tenant_id = d.tenant_id AND co.id = d.condicion_operacion_id
         WHERE d.tenant_id = :tenant_id AND d.id = :id LIMIT 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $documentId]);
    $document = $statement->fetch();
    return $document === false ? null : $document;
}

/**
 * Construye las partidas del asiento de un DTE.
 *
 * @return array<int,array{cuenta_id:int,descripcion:string,debe:float,haber:float}>
 */
function contabilidadDocumentLines(array $document, array $config, string $entryType): array
{
    $type = (string) $document['tipo_dte'];
    $reference = (string) ($document['numero_control'] ?: ('#' . $document['id']));
    $cash = (int) ($document['condicion_codigo'] ?? 1) === 1;
    $lines = [];

    $add = static function (string $configKey, int $debe, int $haber, string $description) use (&$lines, $config, $reference): void {
        if ($debe === 0 && $haber === 0) {
            return;
        }
        $lines[] = [
            'cuenta_id' => (int) $config[$configKey],
            'descripcion' => mb_substr($description . ' · ' . $reference, 0, 200),
            'debe' => $debe / 100,
            'haber' => $haber / 100,
        ];
    };

    $total = contabilidadCents($document['total_pagar']);
    $gravada = contabilidadCents($document['total_gravada']);
    $exenta = contabilidadCents($document['total_exenta']);
    $noSujeta = contabilidadCents($document['total_no_suj']);
    $iva = contabilidadCents($document['total_iva']);
    $ivaRete = contabilidadCents($document['iva_rete1']);
    $reteRenta = contabilidadCents($document['rete_renta']);

    if (in_array($entryType, ['VENTA', 'NOTA_DEBITO'], true)) {
        $add($cash ? 'cuenta_caja_id' : 'cuenta_cxc_id', $total, 0, 'Cobro de la venta');
        $add('cuenta_iva_retenido_por_cobrar_id', $ivaRete, 0, 'IVA retenido por cobrar');
        $add('cuenta_ventas_gravadas_id', 0, $gravada, 'Venta gravada');
        $add('cuenta_ventas_exentas_id', 0, $exenta, 'Venta exenta');
        $add('cuenta_ventas_no_sujetas_id', 0, $noSujeta, 'Venta no sujeta');
        $add('cuenta_iva_debito_id', 0, $iva, 'IVA débito fiscal');
    } elseif ($entryType === 'NOTA_CREDITO') {
        $add('cuenta_devoluciones_id', $gravada + $exenta + $noSujeta, 0, 'Devolución o descuento sobre ventas');
        $add('cuenta_iva_debito_id', $iva, 0, 'Reverso de IVA débito fiscal');
        $add('cuenta_iva_retenido_por_cobrar_id', 0, $ivaRete, 'Reverso de IVA retenido por cobrar');
        $add($cash ? 'cuenta_caja_id' : 'cuenta_cxc_id', 0, $total, 'Crédito al cliente');
    } elseif ($entryType === 'EXPORTACION') {
        $add($cash ? 'cuenta_caja_id' : 'cuenta_cxc_id', $total, 0, 'Cobro de la exportación');
        $add('cuenta_exportaciones_id', 0, $total, 'Ingreso por exportación (incluye flete y seguro)');
    } elseif ($entryType === 'SUJETO_EXCLUIDO') {
        $purchase = $total + $reteRenta;
        $add('cuenta_compras_id', $purchase, 0, 'Compra de bienes o servicios');
        $add($cash ? 'cuenta_caja_id' : 'cuenta_cxp_id', 0, $total, 'Pago o cuenta por pagar');
        $add('cuenta_retencion_renta_por_pagar_id', 0, $reteRenta, 'Retención de renta por pagar');
    } else {
        throw new RuntimeException('El asiento automático del tipo ' . $type . ' no está implementado.');
    }
    return $lines;
}

function contabilidadEntryConcept(array $document, string $entryType): string
{
    $label = contabilidadDocumentTypeLabels()[(string) $document['tipo_dte']] ?? ('DTE ' . $document['tipo_dte']);
    $reference = (string) ($document['numero_control'] ?: ('#' . $document['id']));
    $client = trim((string) ($document['cliente_nombre'] ?? ''));
    $concept = $label . ' ' . $reference . ($client !== '' ? ' · ' . $client : '');
    if ($entryType === 'INVALIDACION') {
        $concept = 'Invalidación ' . $concept;
    }
    return mb_substr($concept, 0, 300);
}

/**
 * Genera el asiento de un DTE procesado. Es idempotente.
 * Devuelve el id del asiento existente o creado, o null si el DTE no aplica.
 */
function contabilidadRegisterDocument(int $tenantId, int $documentId, ?int $userId = null): ?int
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        contabilidadEnsureTenant($tenantId);
        $document = contabilidadDocumentRow($pdo, $tenantId, $documentId);
        if ($document === null) {
            throw new RuntimeException('El documento no existe para esta empresa.');
        }
        if (!in_array($document['estado'], ['PROCESADA', 'INVALIDADA'], true)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }
        $entryType = contabilidadDocumentEntryType((string) $document['tipo_dte']);
        $existing = $pdo->prepare(
            'SELECT id FROM contabilidad_asientos
             WHERE tenant_id = :tenant_id AND documento_id = :documento_id AND tipo = :tipo LIMIT 1'
        );
        $existing->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId, 'tipo' => $entryType]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return (int) $existingId;
        }

        $config = contabilidadConfig($tenantId);
        $lines = contabilidadDocumentLines($document, $config, $entryType);
        $entryId = contabilidadInsertEntry($pdo, $tenantId, $userId ?? (int) $document['created_by'], [
            'fecha' => (string) $document['fecha_emision'],
            'concepto' => contabilidadEntryConcept($document, $entryType),
            'tipo' => $entryType,
            'documento_id' => $documentId,
        ], $lines);
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $entryId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function contabilidadInvalidationDate(array $document, int $tenantId): string
{
    $fallback = contabilidadDate($document['fecha_emision'] ?? '') ?? (new DateTimeImmutable('now'))->format('Y-m-d');
    if (empty($document['invalidado_at'])) {
        return (new DateTimeImmutable('now', new DateTimeZone(contabilidadTimezone($tenantId))))->format('Y-m-d');
    }
    try {
        $date = (new DateTimeImmutable((string) $document['invalidado_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone(contabilidadTimezone($tenantId)))
            ->format('Y-m-d');
        return $date >= $fallback ? $date : $fallback;
    } catch (Throwable) {
        return $fallback;
    }
}

/**
 * Genera el contra-asiento de invalidación. Si el DTE no tenía asiento original
 * (por ejemplo, se procesó antes de instalar el módulo), primero lo crea.
 */
function contabilidadRegisterInvalidation(int $tenantId, int $documentId, ?int $userId = null): ?int
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        contabilidadEnsureTenant($tenantId);
        $document = contabilidadDocumentRow($pdo, $tenantId, $documentId);
        if ($document === null || $document['estado'] !== 'INVALIDADA') {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }
        $originalType = contabilidadDocumentEntryType((string) $document['tipo_dte']);
        contabilidadRegisterDocument($tenantId, $documentId, $userId);

        $existing = $pdo->prepare(
            "SELECT id FROM contabilidad_asientos
             WHERE tenant_id = :tenant_id AND documento_id = :documento_id AND tipo = 'INVALIDACION' LIMIT 1"
        );
        $existing->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return (int) $existingId;
        }

        $statement = $pdo->prepare(
            'SELECT id, numero FROM contabilidad_asientos
             WHERE tenant_id = :tenant_id AND documento_id = :documento_id AND tipo = :tipo LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId, 'tipo' => $originalType]);
        $original = $statement->fetch();
        if ($original === false) {
            throw new RuntimeException('No se encontró el asiento original del documento a invalidar.');
        }
        $statement = $pdo->prepare(
            'SELECT cuenta_id, descripcion, debe, haber FROM contabilidad_partidas
             WHERE tenant_id = :tenant_id AND asiento_id = :asiento_id ORDER BY linea'
        );
        $statement->execute(['tenant_id' => $tenantId, 'asiento_id' => (int) $original['id']]);
        $lines = [];
        foreach ($statement->fetchAll() as $line) {
            $lines[] = [
                'cuenta_id' => (int) $line['cuenta_id'],
                'descripcion' => mb_substr('Reversión: ' . $line['descripcion'], 0, 200),
                'debe' => (float) $line['haber'],
                'haber' => (float) $line['debe'],
            ];
        }
        if ($lines === []) {
            throw new RuntimeException('El asiento original no tiene partidas.');
        }
        $concept = contabilidadEntryConcept($document, 'INVALIDACION');
        $reason = trim((string) ($document['motivo_invalidacion'] ?? ''));
        if ($reason !== '') {
            $concept .= ' · ' . $reason;
        }
        $entryId = contabilidadInsertEntry($pdo, $tenantId, $userId ?? (int) $document['created_by'], [
            'fecha' => contabilidadInvalidationDate($document, $tenantId),
            'concepto' => mb_substr($concept, 0, 300),
            'tipo' => 'INVALIDACION',
            'documento_id' => $documentId,
        ], $lines);
        contabilidadAudit($tenantId, $userId, 'Asiento de invalidación generado para el documento #' . $documentId . '.');
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $entryId;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function contabilidadPendingDocuments(int $tenantId): int
{
    $statement = db()->prepare(
        "SELECT COUNT(*) FROM documentos d
         WHERE d.tenant_id = :tenant_id AND (
             (d.estado = 'PROCESADA' AND NOT EXISTS (
                 SELECT 1 FROM contabilidad_asientos a
                 WHERE a.tenant_id = d.tenant_id AND a.documento_id = d.id
                   AND a.tipo <> 'INVALIDACION' AND a.estado = 'CONTABILIZADO'
             ))
             OR (d.estado = 'INVALIDADA' AND (
                 NOT EXISTS (
                     SELECT 1 FROM contabilidad_asientos a
                     WHERE a.tenant_id = d.tenant_id AND a.documento_id = d.id
                       AND a.tipo <> 'INVALIDACION' AND a.estado = 'CONTABILIZADO'
                 )
                 OR NOT EXISTS (
                     SELECT 1 FROM contabilidad_asientos a
                     WHERE a.tenant_id = d.tenant_id AND a.documento_id = d.id
                       AND a.tipo = 'INVALIDACION' AND a.estado = 'CONTABILIZADO'
                 )
             ))
         )"
    );
    $statement->execute(['tenant_id' => $tenantId]);
    return (int) $statement->fetchColumn();
}

/**
 * Contabiliza los DTE procesados y las invalidaciones que aún no tienen asiento.
 *
 * @return array{documentos:int,asientos:int,pendientes:int}
 */
function contabilidadSincronizar(int $tenantId, ?int $userId): array
{
    $pdo = db();
    contabilidadEnsureTenant($tenantId);
    $pdo->beginTransaction();
    try {
        $before = (int) $pdo->query(
            'SELECT COUNT(*) FROM contabilidad_asientos WHERE tenant_id = ' . $tenantId
        )->fetchColumn();
        $statement = $pdo->prepare(
            "SELECT id, estado FROM documentos
             WHERE tenant_id = :tenant_id AND estado IN ('PROCESADA', 'INVALIDADA') ORDER BY id"
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $documents = $statement->fetchAll();
        foreach ($documents as $document) {
            contabilidadRegisterDocument($tenantId, (int) $document['id'], $userId);
            if ($document['estado'] === 'INVALIDADA') {
                contabilidadRegisterInvalidation($tenantId, (int) $document['id'], $userId);
            }
        }
        $after = (int) $pdo->query(
            'SELECT COUNT(*) FROM contabilidad_asientos WHERE tenant_id = ' . $tenantId
        )->fetchColumn();
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    return [
        'documentos' => count($documents),
        'asientos' => $after - $before,
        'pendientes' => contabilidadPendingDocuments($tenantId),
    ];
}

/* -------------------------------------------------------------------------
 * Consultas, libros y estados financieros
 * ---------------------------------------------------------------------- */

function contabilidadEntries(int $tenantId, array $filters, int $page = 1, int $perPage = 25): array
{
    $where = ['a.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    $from = contabilidadDate($filters['fecha_desde'] ?? '');
    $to = contabilidadDate($filters['fecha_hasta'] ?? '');
    if ($from !== null) {
        $where[] = 'a.fecha >= :fecha_desde';
        $params['fecha_desde'] = $from;
    }
    if ($to !== null) {
        $where[] = 'a.fecha <= :fecha_hasta';
        $params['fecha_hasta'] = $to;
    }
    $type = (string) ($filters['tipo'] ?? '');
    if (array_key_exists($type, contabilidadEntryTypes())) {
        $where[] = 'a.tipo = :tipo';
        $params['tipo'] = $type;
    }
    $status = (string) ($filters['estado'] ?? '');
    if (in_array($status, ['CONTABILIZADO', 'ANULADO'], true)) {
        $where[] = 'a.estado = :estado';
        $params['estado'] = $status;
    }
    $query = trim((string) ($filters['q'] ?? ''));
    if ($query !== '') {
        $where[] = '(a.concepto LIKE :q OR CAST(a.numero AS CHAR) LIKE :q OR d.numero_control LIKE :q)';
        $params['q'] = '%' . mb_substr($query, 0, 80) . '%';
    }
    $whereSql = implode(' AND ', $where);
    $join = 'LEFT JOIN documentos d ON d.tenant_id = a.tenant_id AND d.id = a.documento_id';

    $count = db()->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN a.estado = 'CONTABILIZADO' THEN a.total_debe ELSE 0 END), 0) AS debe,
                COALESCE(SUM(CASE WHEN a.estado = 'CONTABILIZADO' THEN a.total_haber ELSE 0 END), 0) AS haber
         FROM contabilidad_asientos a " . $join . ' WHERE ' . $whereSql
    );
    $count->execute($params);
    $totals = $count->fetch() ?: ['total' => 0, 'debe' => 0, 'haber' => 0];

    $page = max(1, $page);
    $perPage = max(1, min(500, $perPage));
    $offset = ($page - 1) * $perPage;
    $statement = db()->prepare(
        'SELECT a.id, a.numero, a.fecha, a.concepto, a.tipo, a.estado, a.documento_id,
                a.total_debe, a.total_haber, a.motivo_anulacion, a.created_at,
                d.numero_control, d.tipo_dte, d.estado AS documento_estado
         FROM contabilidad_asientos a ' . $join . '
         WHERE ' . $whereSql . '
         ORDER BY a.fecha DESC, a.numero DESC
         LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $statement->execute($params);
    $items = $statement->fetchAll();
    foreach ($items as &$item) {
        $item['total_debe'] = contabilidadMoney($item['total_debe']);
        $item['total_haber'] = contabilidadMoney($item['total_haber']);
    }
    unset($item);

    $total = (int) ($totals['total'] ?? 0);
    return [
        'items' => $items,
        'total_debe' => contabilidadMoney($totals['debe'] ?? 0),
        'total_haber' => contabilidadMoney($totals['haber'] ?? 0),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ],
    ];
}

function contabilidadEntry(int $tenantId, int $entryId): ?array
{
    $statement = db()->prepare(
        'SELECT a.*, d.numero_control, d.tipo_dte, d.estado AS documento_estado,
                u.nombre AS usuario_nombre, au.nombre AS anulado_por_nombre
         FROM contabilidad_asientos a
         LEFT JOIN documentos d ON d.tenant_id = a.tenant_id AND d.id = a.documento_id
         LEFT JOIN users u ON u.id = a.created_by
         LEFT JOIN users au ON au.id = a.anulado_por
         WHERE a.tenant_id = :tenant_id AND a.id = :id LIMIT 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => $entryId]);
    $entry = $statement->fetch();
    if ($entry === false) {
        return null;
    }
    $statement = db()->prepare(
        'SELECT p.linea, p.cuenta_id, p.descripcion, p.debe, p.haber,
                c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre
         FROM contabilidad_partidas p
         INNER JOIN contabilidad_cuentas c ON c.tenant_id = p.tenant_id AND c.id = p.cuenta_id
         WHERE p.tenant_id = :tenant_id AND p.asiento_id = :asiento_id ORDER BY p.linea'
    );
    $statement->execute(['tenant_id' => $tenantId, 'asiento_id' => $entryId]);
    $lines = $statement->fetchAll();
    foreach ($lines as &$line) {
        $line['debe'] = contabilidadMoney($line['debe']);
        $line['haber'] = contabilidadMoney($line['haber']);
    }
    unset($line);
    $entry['total_debe'] = contabilidadMoney($entry['total_debe']);
    $entry['total_haber'] = contabilidadMoney($entry['total_haber']);
    $entry['partidas'] = $lines;
    return $entry;
}

function contabilidadDiario(int $tenantId, string $from, string $to): array
{
    $statement = db()->prepare(
        "SELECT a.id, a.numero, a.fecha, a.concepto, a.tipo, a.estado, a.documento_id,
                a.total_debe, a.total_haber, d.numero_control, d.tipo_dte
         FROM contabilidad_asientos a
         LEFT JOIN documentos d ON d.tenant_id = a.tenant_id AND d.id = a.documento_id
         WHERE a.tenant_id = :tenant_id AND a.estado = 'CONTABILIZADO'
           AND a.fecha BETWEEN :desde AND :hasta
         ORDER BY a.fecha, a.numero LIMIT 1001"
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    $entries = $statement->fetchAll();
    $truncated = count($entries) > 1000;
    if ($truncated) {
        $entries = array_slice($entries, 0, 1000);
    }
    $linesByEntry = [];
    if ($entries !== []) {
        $ids = array_map(static fn(array $entry): int => (int) $entry['id'], $entries);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = db()->prepare(
            'SELECT p.asiento_id, p.linea, p.descripcion, p.debe, p.haber,
                    c.codigo AS cuenta_codigo, c.nombre AS cuenta_nombre
             FROM contabilidad_partidas p
             INNER JOIN contabilidad_cuentas c ON c.tenant_id = p.tenant_id AND c.id = p.cuenta_id
             WHERE p.tenant_id = ? AND p.asiento_id IN (' . $placeholders . ')
             ORDER BY p.asiento_id, p.linea'
        );
        $statement->execute(array_merge([$tenantId], $ids));
        foreach ($statement->fetchAll() as $line) {
            $linesByEntry[(int) $line['asiento_id']][] = $line;
        }
    }
    $totalDebe = 0.0;
    $totalHaber = 0.0;
    foreach ($entries as &$entry) {
        $entry['total_debe'] = contabilidadMoney($entry['total_debe']);
        $entry['total_haber'] = contabilidadMoney($entry['total_haber']);
        $totalDebe += $entry['total_debe'];
        $totalHaber += $entry['total_haber'];
        $entry['partidas'] = array_map(static function (array $line): array {
            $line['debe'] = contabilidadMoney($line['debe']);
            $line['haber'] = contabilidadMoney($line['haber']);
            return $line;
        }, $linesByEntry[(int) $entry['id']] ?? []);
    }
    unset($entry);

    return [
        'entries' => $entries,
        'total_debe' => round($totalDebe, 2),
        'total_haber' => round($totalHaber, 2),
        'truncated' => $truncated,
    ];
}

function contabilidadMayor(int $tenantId, int $accountId, string $from, string $to): array
{
    $account = contabilidadAccount($tenantId, $accountId);
    if ($account === null) {
        throw new RuntimeException('La cuenta contable no existe para esta empresa.');
    }
    $initial = db()->prepare(
        "SELECT COALESCE(SUM(p.debe - p.haber), 0) AS saldo
         FROM contabilidad_partidas p
         INNER JOIN contabilidad_asientos a ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id
         WHERE p.tenant_id = :tenant_id AND p.cuenta_id = :cuenta_id
           AND a.estado = 'CONTABILIZADO' AND a.fecha < :desde"
    );
    $initial->execute(['tenant_id' => $tenantId, 'cuenta_id' => $accountId, 'desde' => $from]);
    $initialBalance = contabilidadMoney($initial->fetchColumn());
    $saldo = $initialBalance;

    $statement = db()->prepare(
        "SELECT a.id, a.numero, a.fecha, a.concepto, a.tipo, p.descripcion, p.debe, p.haber
         FROM contabilidad_partidas p
         INNER JOIN contabilidad_asientos a ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id
         WHERE p.tenant_id = :tenant_id AND p.cuenta_id = :cuenta_id
           AND a.estado = 'CONTABILIZADO' AND a.fecha BETWEEN :desde AND :hasta
         ORDER BY a.fecha, a.numero, p.linea LIMIT 2001"
    );
    $statement->execute(['tenant_id' => $tenantId, 'cuenta_id' => $accountId, 'desde' => $from, 'hasta' => $to]);
    $rows = $statement->fetchAll();
    $truncated = count($rows) > 2000;
    if ($truncated) {
        $rows = array_slice($rows, 0, 2000);
    }
    $totalDebe = 0.0;
    $totalHaber = 0.0;
    foreach ($rows as &$row) {
        $debe = contabilidadMoney($row['debe']);
        $haber = contabilidadMoney($row['haber']);
        $row['debe'] = $debe;
        $row['haber'] = $haber;
        $totalDebe += $debe;
        $totalHaber += $haber;
        $saldo = round($saldo + $debe - $haber, 2);
        $row['saldo'] = $saldo;
    }
    unset($row);

    return [
        'cuenta' => $account,
        'saldo_inicial' => $initialBalance,
        'rows' => $rows,
        'total_debe' => round($totalDebe, 2),
        'total_haber' => round($totalHaber, 2),
        'saldo_final' => round($saldo, 2),
        'truncated' => $truncated,
    ];
}

function contabilidadBalanza(int $tenantId, string $from, string $to): array
{
    $statement = db()->prepare(
        'SELECT c.id, c.codigo, c.nombre, c.tipo, c.naturaleza,
                COALESCE(SUM(CASE WHEN a.fecha < :desde THEN p.debe - p.haber ELSE 0 END), 0) AS saldo_inicial,
                COALESCE(SUM(CASE WHEN a.fecha BETWEEN :desde2 AND :hasta THEN p.debe ELSE 0 END), 0) AS debe,
                COALESCE(SUM(CASE WHEN a.fecha BETWEEN :desde3 AND :hasta2 THEN p.haber ELSE 0 END), 0) AS haber
         FROM contabilidad_cuentas c
         LEFT JOIN contabilidad_partidas p ON p.tenant_id = c.tenant_id AND p.cuenta_id = c.id
         LEFT JOIN contabilidad_asientos a
             ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id AND a.estado = \'CONTABILIZADO\'
         WHERE c.tenant_id = :tenant_id
         GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza
         HAVING saldo_inicial <> 0 OR debe <> 0 OR haber <> 0
         ORDER BY c.codigo'
    );
    $statement->execute([
        'tenant_id' => $tenantId, 'desde' => $from, 'desde2' => $from, 'desde3' => $from,
        'hasta' => $to, 'hasta2' => $to,
    ]);
    $rows = $statement->fetchAll();
    $totalDebe = 0.0;
    $totalHaber = 0.0;
    $totalDeudor = 0.0;
    $totalAcreedor = 0.0;
    foreach ($rows as &$row) {
        $initial = contabilidadMoney($row['saldo_inicial']);
        $debe = contabilidadMoney($row['debe']);
        $haber = contabilidadMoney($row['haber']);
        $final = round($initial + $debe - $haber, 2);
        $row['saldo_inicial'] = $initial;
        $row['debe'] = $debe;
        $row['haber'] = $haber;
        $row['saldo_final'] = $final;
        $row['saldo_deudor'] = $final > 0 ? $final : 0.0;
        $row['saldo_acreedor'] = $final < 0 ? -$final : 0.0;
        $totalDebe += $debe;
        $totalHaber += $haber;
        $totalDeudor += $row['saldo_deudor'];
        $totalAcreedor += $row['saldo_acreedor'];
    }
    unset($row);
    return [
        'rows' => $rows,
        'total_debe' => round($totalDebe, 2),
        'total_haber' => round($totalHaber, 2),
        'total_deudor' => round($totalDeudor, 2),
        'total_acreedor' => round($totalAcreedor, 2),
    ];
}

function contabilidadEstadoResultados(int $tenantId, string $from, string $to): array
{
    $statement = db()->prepare(
        "SELECT c.id, c.codigo, c.nombre, c.tipo,
                COALESCE(SUM(CASE WHEN c.tipo = 'INGRESO' THEN p.haber - p.debe ELSE p.debe - p.haber END), 0) AS saldo
         FROM contabilidad_partidas p
         INNER JOIN contabilidad_asientos a ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id
         INNER JOIN contabilidad_cuentas c ON c.tenant_id = p.tenant_id AND c.id = p.cuenta_id
         WHERE p.tenant_id = :tenant_id AND a.estado = 'CONTABILIZADO'
           AND a.fecha BETWEEN :desde AND :hasta
           AND c.tipo IN ('INGRESO', 'COSTO', 'GASTO')
         GROUP BY c.id, c.codigo, c.nombre, c.tipo
         HAVING saldo <> 0
         ORDER BY c.codigo"
    );
    $statement->execute(['tenant_id' => $tenantId, 'desde' => $from, 'hasta' => $to]);
    $groups = ['INGRESO' => [], 'COSTO' => [], 'GASTO' => []];
    $totals = ['INGRESO' => 0.0, 'COSTO' => 0.0, 'GASTO' => 0.0];
    foreach ($statement->fetchAll() as $row) {
        $amount = contabilidadMoney($row['saldo']);
        $row['saldo'] = $amount;
        $groups[$row['tipo']][] = $row;
        $totals[$row['tipo']] += $amount;
    }
    $totals = array_map(static fn(float $value): float => round($value, 2), $totals);
    $bruta = round($totals['INGRESO'] - $totals['COSTO'], 2);
    $neta = round($bruta - $totals['GASTO'], 2);
    return [
        'ingresos' => $groups['INGRESO'],
        'costos' => $groups['COSTO'],
        'gastos' => $groups['GASTO'],
        'total_ingresos' => $totals['INGRESO'],
        'total_costos' => $totals['COSTO'],
        'total_gastos' => $totals['GASTO'],
        'utilidad_bruta' => $bruta,
        'utilidad_neta' => $neta,
    ];
}

function contabilidadBalanceGeneral(int $tenantId, string $asOf): array
{
    $statement = db()->prepare(
        "SELECT c.id, c.codigo, c.nombre, c.tipo, c.naturaleza,
                COALESCE(SUM(CASE WHEN c.tipo = 'ACTIVO' THEN p.debe - p.haber ELSE p.haber - p.debe END), 0) AS saldo
         FROM contabilidad_partidas p
         INNER JOIN contabilidad_asientos a ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id
         INNER JOIN contabilidad_cuentas c ON c.tenant_id = p.tenant_id AND c.id = p.cuenta_id
         WHERE p.tenant_id = :tenant_id AND a.estado = 'CONTABILIZADO'
           AND a.fecha <= :asof AND c.tipo IN ('ACTIVO', 'PASIVO', 'PATRIMONIO')
         GROUP BY c.id, c.codigo, c.nombre, c.tipo, c.naturaleza
         HAVING saldo <> 0
         ORDER BY c.codigo"
    );
    $statement->execute(['tenant_id' => $tenantId, 'asof' => $asOf]);
    $groups = ['ACTIVO' => [], 'PASIVO' => [], 'PATRIMONIO' => []];
    $totals = ['ACTIVO' => 0.0, 'PASIVO' => 0.0, 'PATRIMONIO' => 0.0];
    foreach ($statement->fetchAll() as $row) {
        $amount = contabilidadMoney($row['saldo']);
        $row['saldo'] = $amount;
        $groups[$row['tipo']][] = $row;
        $totals[$row['tipo']] += $amount;
    }

    $result = db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN c.tipo = 'INGRESO' THEN p.haber - p.debe ELSE p.debe - p.haber END), 0)
         FROM contabilidad_partidas p
         INNER JOIN contabilidad_asientos a ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id
         INNER JOIN contabilidad_cuentas c ON c.tenant_id = p.tenant_id AND c.id = p.cuenta_id
         WHERE p.tenant_id = :tenant_id AND a.estado = 'CONTABILIZADO'
           AND a.fecha <= :asof AND c.tipo IN ('INGRESO', 'COSTO', 'GASTO')"
    );
    $result->execute(['tenant_id' => $tenantId, 'asof' => $asOf]);
    $resultado = contabilidadMoney($result->fetchColumn());
    $patrimonio = round($totals['PATRIMONIO'] + $resultado, 2);
    return [
        'asof' => $asOf,
        'activo' => $groups['ACTIVO'],
        'pasivo' => $groups['PASIVO'],
        'patrimonio' => $groups['PATRIMONIO'],
        'total_activo' => round($totals['ACTIVO'], 2),
        'total_pasivo' => round($totals['PASIVO'], 2),
        'total_patrimonio' => round($totals['PATRIMONIO'], 2),
        'resultado_ejercicio' => $resultado,
        'total_patrimonio_con_resultado' => $patrimonio,
        'total_pasivo_patrimonio' => round($totals['PASIVO'] + $patrimonio, 2),
        'diferencia' => round($totals['ACTIVO'] - ($totals['PASIVO'] + $patrimonio), 2),
    ];
}

function contabilidadResumen(int $tenantId): array
{
    $timezone = contabilidadTimezone($tenantId);
    $range = contabilidadRange([], $timezone);
    $entries = contabilidadEntries($tenantId, [
        'fecha_desde' => $range['desde'], 'fecha_hasta' => $range['hasta'],
    ], 1, 5);
    $result = contabilidadEstadoResultados($tenantId, $range['desde'], $range['hasta']);
    $accounts = db()->prepare(
        'SELECT COUNT(*) AS total, COALESCE(SUM(activa = 1), 0) AS activas FROM contabilidad_cuentas WHERE tenant_id = :tenant_id'
    );
    $accounts->execute(['tenant_id' => $tenantId]);
    $counts = $accounts->fetch() ?: ['total' => 0, 'activas' => 0];
    return [
        'periodo' => $range,
        'timezone' => $timezone,
        'asientos' => (int) ($entries['pagination']['total'] ?? 0),
        'debe' => $entries['total_debe'] ?? 0.0,
        'haber' => $entries['total_haber'] ?? 0.0,
        'resultado' => $result['utilidad_neta'],
        'cuentas' => (int) ($counts['total'] ?? 0),
        'cuentas_activas' => (int) ($counts['activas'] ?? 0),
        'pendientes' => contabilidadPendingDocuments($tenantId),
        'recientes' => $entries['items'] ?? [],
    ];
}

/* -------------------------------------------------------------------------
 * Exportación CSV
 * ---------------------------------------------------------------------- */

function contabilidadCsvSafe(mixed $value): string
{
    $text = (string) $value;
    return preg_match('/^\s*[=+\-@]/', $text) === 1 ? "'" . $text : $text;
}

/**
 * @return array{filename:string,headers:array<int,string>,rows:array<int,array<int,mixed>>}
 */
function contabilidadCsv(int $tenantId, string $report, array $filters): array
{
    $timezone = contabilidadTimezone($tenantId);
    $range = contabilidadRange($filters, $timezone);
    $money = static fn(float $value): string => number_format($value, 2, '.', '');
    if ($report === 'mayor') {
        $accountId = filter_var($filters['cuenta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($accountId === false || $accountId === null) {
            throw new RuntimeException('Seleccione la cuenta del libro mayor.');
        }
        $book = contabilidadMayor($tenantId, (int) $accountId, $range['desde'], $range['hasta']);
        $rows = [];
        foreach ($book['rows'] as $row) {
            $rows[] = [$row['fecha'], $row['numero'], $row['concepto'], $row['descripcion'],
                $money((float) $row['debe']), $money((float) $row['haber']), $money((float) $row['saldo'])];
        }
        return [
            'filename' => 'libro_mayor_' . $book['cuenta']['codigo'] . '_' . $range['desde'] . '_' . $range['hasta'],
            'headers' => ['Fecha', 'Asiento', 'Concepto', 'Detalle', 'Debe', 'Haber', 'Saldo'],
            'rows' => $rows,
        ];
    }
    if ($report === 'balanza') {
        $balance = contabilidadBalanza($tenantId, $range['desde'], $range['hasta']);
        $rows = [];
        foreach ($balance['rows'] as $row) {
            $rows[] = [$row['codigo'], $row['nombre'], $row['tipo'],
                $money((float) $row['saldo_inicial']), $money((float) $row['debe']),
                $money((float) $row['haber']), $money((float) $row['saldo_deudor']),
                $money((float) $row['saldo_acreedor'])];
        }
        return [
            'filename' => 'balanza_comprobacion_' . $range['desde'] . '_' . $range['hasta'],
            'headers' => ['Código', 'Cuenta', 'Tipo', 'Saldo inicial', 'Debe', 'Haber', 'Saldo deudor', 'Saldo acreedor'],
            'rows' => $rows,
        ];
    }
    if ($report === 'resultados') {
        $state = contabilidadEstadoResultados($tenantId, $range['desde'], $range['hasta']);
        $rows = [];
        foreach (['ingresos' => 'Ingreso', 'costos' => 'Costo', 'gastos' => 'Gasto'] as $key => $label) {
            foreach ($state[$key] as $row) {
                $rows[] = [$label, $row['codigo'], $row['nombre'], $money((float) $row['saldo'])];
            }
        }
        $rows[] = ['Utilidad bruta', '', '', $money((float) $state['utilidad_bruta'])];
        $rows[] = ['Utilidad neta', '', '', $money((float) $state['utilidad_neta'])];
        return [
            'filename' => 'estado_resultados_' . $range['desde'] . '_' . $range['hasta'],
            'headers' => ['Grupo', 'Código', 'Cuenta', 'Saldo'],
            'rows' => $rows,
        ];
    }
    if ($report === 'balance') {
        $asOf = contabilidadDate($filters['fecha_hasta'] ?? '') ?? (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
        $balance = contabilidadBalanceGeneral($tenantId, $asOf);
        $rows = [];
        foreach (['activo' => 'Activo', 'pasivo' => 'Pasivo', 'patrimonio' => 'Patrimonio'] as $key => $label) {
            foreach ($balance[$key] as $row) {
                $rows[] = [$label, $row['codigo'], $row['nombre'], $money((float) $row['saldo'])];
            }
        }
        $rows[] = ['Resultado del ejercicio', '', '', $money((float) $balance['resultado_ejercicio'])];
        $rows[] = ['Total activo', '', '', $money((float) $balance['total_activo'])];
        $rows[] = ['Total pasivo + patrimonio', '', '', $money((float) $balance['total_pasivo_patrimonio'])];
        return [
            'filename' => 'balance_general_' . $asOf,
            'headers' => ['Grupo', 'Código', 'Cuenta', 'Saldo'],
            'rows' => $rows,
        ];
    }
    if ($report === 'asientos') {
        $list = contabilidadEntries($tenantId, $filters, 1, 500);
        $rows = [];
        foreach ($list['items'] as $row) {
            $rows[] = [$row['numero'], $row['fecha'], $row['concepto'],
                contabilidadEntryTypes()[$row['tipo']] ?? $row['tipo'], $row['estado'],
                $money((float) $row['total_debe']), $money((float) $row['total_haber']),
                $row['numero_control'] ?: ''];
        }
        return [
            'filename' => 'asientos_contables_' . $range['desde'] . '_' . $range['hasta'],
            'headers' => ['Asiento', 'Fecha', 'Concepto', 'Tipo', 'Estado', 'Debe', 'Haber', 'Documento'],
            'rows' => $rows,
        ];
    }
    $diary = contabilidadDiario($tenantId, $range['desde'], $range['hasta']);
    $rows = [];
    foreach ($diary['entries'] as $entry) {
        foreach ($entry['partidas'] as $line) {
            $rows[] = [$entry['fecha'], $entry['numero'], $entry['concepto'],
                contabilidadEntryTypes()[$entry['tipo']] ?? $entry['tipo'],
                $line['cuenta_codigo'], $line['cuenta_nombre'], $line['descripcion'],
                $money((float) $line['debe']), $money((float) $line['haber'])];
        }
    }
    return [
        'filename' => 'libro_diario_' . $range['desde'] . '_' . $range['hasta'],
        'headers' => ['Fecha', 'Asiento', 'Concepto', 'Tipo', 'Código', 'Cuenta', 'Detalle', 'Debe', 'Haber'],
        'rows' => $rows,
    ];
}
