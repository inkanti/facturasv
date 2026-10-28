<?php

declare(strict_types=1);

$sessionPath = null;
foreach ([sys_get_temp_dir(), 'C:\\Windows\\Temp', '/tmp'] as $candidate) {
    if (is_dir($candidate) && is_writable($candidate)) {
        $sessionPath = $candidate;
        break;
    }
}
if ($sessionPath !== null) {
    ini_set('session.save_path', $sessionPath);
}

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/contabilidad.php';
require_once dirname(__DIR__) . '/lib/documentos.php';

// La prueba de integración no debe usar el SMTP real configurado.
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);

/**
 * Pruebas del módulo 13: contabilidad.
 * Genera los asientos de los DTE existentes, prueba asientos manuales,
 * anulación, catálogo, configuración y estados financieros.
 * Elimina al final los datos temporales de la prueba.
 */

$pdo = db();
$checks = 0;
$tenantId = 1;
$admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
$user = ['id' => (int) $admin['id'], 'tenant_id' => $tenantId, 'rol' => 'ADMIN'];
$auditStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM configuracion_cambios')->fetchColumn();
$entryStart = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM contabilidad_asientos')->fetchColumn();
$createdEntries = [];
$emittedDocumentId = null;
$originalDocSequences = [];

function checkModule13(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('FALLO M13: ' . $message);
    }
    $checks++;
    echo "OK: {$message}\n";
}

function module13User(): array
{
    global $user;
    return $user;
}

function module13Accounts(int $tenantId): array
{
    $accounts = [];
    foreach (contabilidadAccounts($tenantId) as $account) {
        $accounts[(string) $account['codigo']] = $account;
    }
    return $accounts;
}

function module13Date(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');
}

try {
    contabilidadEnsureTenant($tenantId);
    $accounts = module13Accounts($tenantId);
    checkModule13(count($accounts) >= 20, 'el catálogo base tiene al menos 20 cuentas');
    foreach (['1101', '1102', '2102', '4101', '5102', '6101'] as $code) {
        checkModule13(isset($accounts[$code]), 'el catálogo incluye la cuenta ' . $code);
    }
    $config = contabilidadConfig($tenantId);
    foreach (array_keys(contabilidadMapping()) as $field) {
        checkModule13((int) $config[$field] > 0, 'la configuración automática tiene ' . $field);
    }
    checkModule13((int) $config['cuenta_caja_id'] === (int) $accounts['1101']['id'], 'la caja apunta a la cuenta 1101');
    checkModule13((int) $config['cuenta_iva_debito_id'] === (int) $accounts['2102']['id'], 'el IVA débito apunta a la cuenta 2102');

    $sync = contabilidadSincronizar($tenantId, (int) $user['id']);
    checkModule13($sync['pendientes'] === 0, 'todos los DTE procesados e invalidados quedan contabilizados');
    $secondSync = contabilidadSincronizar($tenantId, (int) $user['id']);
    checkModule13($secondSync['asientos'] === 0, 'la sincronización es idempotente: no duplica asientos');

    $processed = (int) $pdo->query(
        "SELECT COUNT(*) FROM documentos d
         WHERE d.tenant_id = 1 AND d.estado = 'PROCESADA'
           AND EXISTS (
               SELECT 1 FROM contabilidad_asientos a
               WHERE a.tenant_id = d.tenant_id AND a.documento_id = d.id
                 AND a.tipo <> 'INVALIDACION'
           )"
    )->fetchColumn();
    $processedTotal = (int) $pdo->query(
        "SELECT COUNT(*) FROM documentos WHERE tenant_id = 1 AND estado = 'PROCESADA'"
    )->fetchColumn();
    checkModule13($processed === $processedTotal, 'cada documento procesado tiene su asiento de venta');

    // Integración real: emitir un DTE debe crear el asiento desde el hook de transmisión.
    $today = module13Date();
    $originalDocSequences = $pdo->query(
        'SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1'
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $emittedDocumentId = saveDocument($tenantId, (int) $user['id'], [
        'tipo_dte' => '01', 'cliente_id' => 1, 'fecha_emision' => $today,
        'condicion_operacion_id' => 1, 'forma_pago_id' => 1, 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Prueba de integración contable del módulo 13',
        'items' => [[
            'producto_id' => '1', 'cantidad' => '1', 'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ]);
    $emittedDetail = emitDocument($tenantId, $admin, $emittedDocumentId);
    checkModule13($emittedDetail['document']['estado'] === 'PROCESADA', 'la emisión de un DTE de prueba se procesa');
    $autoEntryId = (int) $pdo->query(
        'SELECT id FROM contabilidad_asientos WHERE tenant_id = 1 AND documento_id = ' . $emittedDocumentId
        . " AND tipo = 'VENTA'"
    )->fetchColumn();
    checkModule13($autoEntryId > 0, 'la emisión crea automáticamente el asiento de venta');
    $autoEntry = contabilidadEntry($tenantId, $autoEntryId);
    checkModule13(
        $autoEntry !== null && abs($autoEntry['total_debe'] - 113.0) < 0.01 && count($autoEntry['partidas']) === 3,
        'el asiento automático de la Factura tiene tres partidas y 113.00'
    );
    $invalidatedEmission = invalidateDocument($tenantId, $admin, $emittedDocumentId, [
        'tipo_anulacion' => '1', 'motivo' => 'Prueba de invalidación contable del módulo 13',
        'tipo_documento_responsable' => '13', 'numero_documento_responsable' => '12345678-9',
    ]);
    checkModule13($invalidatedEmission['document']['estado'] === 'INVALIDADA', 'la invalidación del DTE de prueba se procesa');
    $reversalEntryId = (int) $pdo->query(
        'SELECT id FROM contabilidad_asientos WHERE tenant_id = 1 AND documento_id = ' . $emittedDocumentId
        . " AND tipo = 'INVALIDACION'"
    )->fetchColumn();
    checkModule13($reversalEntryId > 0, 'la invalidación crea automáticamente el contra-asiento');
    $documentNet = (float) $pdo->query(
        'SELECT COALESCE(SUM(p.debe - p.haber), 0) FROM contabilidad_partidas p
         INNER JOIN contabilidad_asientos a ON a.tenant_id = p.tenant_id AND a.id = p.asiento_id
         WHERE a.tenant_id = 1 AND a.documento_id = ' . $emittedDocumentId
    )->fetchColumn();
    checkModule13(abs($documentNet) < 0.004, 'el DTE invalidado queda en cero en la contabilidad');
    $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')
        ->execute(['id' => $emittedDocumentId]);
    $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')
        ->execute(['id' => $emittedDocumentId]);
    checkModule13(
        (int) $pdo->query('SELECT COUNT(*) FROM contabilidad_asientos WHERE documento_id = ' . $emittedDocumentId)->fetchColumn() === 0,
        'al eliminar el documento de prueba se elimina su asiento en cascada'
    );
    $emittedDocumentId = null;
    $restoreDocSequence = $pdo->prepare(
        'UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type'
    );
    foreach ($originalDocSequences as $type => $value) {
        $restoreDocSequence->execute(['value' => (int) $value, 'type' => (string) $type]);
    }

    $unbalanced = $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT a.id FROM contabilidad_asientos a
            INNER JOIN contabilidad_partidas p ON p.tenant_id = a.tenant_id AND p.asiento_id = a.id
            WHERE a.tenant_id = 1
            GROUP BY a.id, a.total_debe, a.total_haber
            HAVING ABS(SUM(p.debe) - SUM(p.haber)) > 0.004
                OR ABS(SUM(p.debe) - a.total_debe) > 0.004
                OR ABS(SUM(p.haber) - a.total_haber) > 0.004
        ) x'
    )->fetchColumn();
    checkModule13((int) $unbalanced === 0, 'todos los asientos automáticos están balanceados');

    $invalidated = $pdo->query(
        "SELECT d.id,
                COALESCE(SUM(p.debe - p.haber), 0) AS neto,
                SUM(a.tipo = 'INVALIDACION') AS reversos
         FROM documentos d
         LEFT JOIN contabilidad_asientos a ON a.tenant_id = d.tenant_id AND a.documento_id = d.id
         LEFT JOIN contabilidad_partidas p ON p.tenant_id = a.tenant_id AND p.asiento_id = a.id
         WHERE d.tenant_id = 1 AND d.estado = 'INVALIDADA'
         GROUP BY d.id"
    )->fetchAll();
    checkModule13(count($invalidated) >= 1, 'existen documentos invalidados de prueba');
    foreach ($invalidated as $row) {
        checkModule13((int) $row['reversos'] >= 1, 'el documento invalidado ' . $row['id'] . ' tiene contra-asiento');
        checkModule13(abs((float) $row['neto']) < 0.004, 'el documento invalidado ' . $row['id'] . ' queda en cero');
    }

    $monthStart = substr($today, 0, 8) . '01';
    $before = contabilidadBalanza($tenantId, $monthStart, $today);
    $balanceBefore = contabilidadBalanceGeneral($tenantId, $today);

    $manual = contabilidadCreateManual(module13User(), [
        'fecha' => $today,
        'concepto' => 'Asiento temporal de prueba del módulo 13',
        'lineas' => [
            ['cuenta_id' => $accounts['1101']['id'], 'descripcion' => 'Cargo temporal', 'debe' => '100.00', 'haber' => ''],
            ['cuenta_id' => $accounts['4101']['id'], 'descripcion' => 'Abono temporal', 'debe' => '', 'haber' => '100.00'],
        ],
    ]);
    checkModule13($manual['id'] > $entryStart, 'se crea un asiento manual balanceado');
    $createdEntries[] = (int) $manual['id'];
    $entry = contabilidadEntry($tenantId, $manual['id']);
    checkModule13($entry !== null && abs($entry['total_debe'] - 100.0) < 0.001, 'el asiento manual guarda el total del debe');
    checkModule13(count($entry['partidas']) === 2, 'el asiento manual guarda sus dos partidas');

    $afterCreate = contabilidadBalanza($tenantId, $monthStart, $today);
    checkModule13(abs(($afterCreate['total_debe'] - $before['total_debe']) - 100.0) < 0.01, 'el asiento manual suma al debe de la balanza');

    $anulado = contabilidadAnularEntry(module13User(), $manual['id'], 'Anulación de prueba del módulo 13');
    checkModule13(str_contains($anulado, 'anulado'), 'un asiento manual se puede anular');
    $afterVoid = contabilidadBalanza($tenantId, $monthStart, $today);
    checkModule13(abs($afterVoid['total_debe'] - $before['total_debe']) < 0.01, 'el asiento anulado no altera la balanza');

    try {
        contabilidadCreateManual(module13User(), [
            'fecha' => $today,
            'concepto' => 'Asiento desbalanceado de prueba',
            'lineas' => [
                ['cuenta_id' => $accounts['1101']['id'], 'debe' => '100.00', 'haber' => ''],
                ['cuenta_id' => $accounts['4101']['id'], 'debe' => '', 'haber' => '90.00'],
            ],
        ]);
        throw new RuntimeException('Se aceptó un asiento desbalanceado.');
    } catch (ContabilidadValidationException $exception) {
        checkModule13(isset($exception->errors['lineas']), 'se rechaza un asiento desbalanceado');
    }

    try {
        contabilidadCreateManual(module13User(), [
            'fecha' => $today,
            'concepto' => 'Asiento con línea inválida',
            'lineas' => [
                ['cuenta_id' => $accounts['1101']['id'], 'debe' => '100.00', 'haber' => '100.00'],
                ['cuenta_id' => $accounts['4101']['id'], 'debe' => '', 'haber' => ''],
            ],
        ]);
        throw new RuntimeException('Se aceptó una línea con debe y haber.');
    } catch (ContabilidadValidationException $exception) {
        checkModule13(isset($exception->errors['lineas']), 'se rechaza una línea con debe y haber a la vez');
    }

    $tempCode = '9999';
    $pdo->prepare('DELETE FROM contabilidad_cuentas WHERE tenant_id = :tenant_id AND codigo = :codigo')
        ->execute(['tenant_id' => $tenantId, 'codigo' => $tempCode]);
    $created = contabilidadSaveAccount(module13User(), [
        'codigo' => $tempCode, 'nombre' => 'Cuenta temporal de prueba', 'tipo' => 'ACTIVO',
        'naturaleza' => 'DEUDORA', 'permite_movimiento' => '1', 'activa' => '1',
    ]);
    checkModule13($created['id'] > 0, 'el administrador crea una cuenta contable');
    try {
        contabilidadSaveAccount(module13User(), [
            'codigo' => $tempCode, 'nombre' => 'Cuenta duplicada', 'tipo' => 'ACTIVO',
            'naturaleza' => 'DEUDORA', 'permite_movimiento' => '1', 'activa' => '1',
        ]);
        throw new RuntimeException('Se aceptó un código de cuenta duplicado.');
    } catch (ContabilidadValidationException $exception) {
        checkModule13(isset($exception->errors['codigo']), 'se rechaza un código de cuenta duplicado');
    }
    $toggled = contabilidadToggleAccount(module13User(), (int) $created['id'], false);
    checkModule13(str_contains($toggled, 'desactivada'), 'se desactiva una cuenta sin movimientos');
    $toggled = contabilidadToggleAccount(module13User(), (int) $created['id'], true);
    checkModule13(str_contains($toggled, 'activada'), 'se reactiva una cuenta sin movimientos');
    $pdo->prepare('DELETE FROM contabilidad_cuentas WHERE tenant_id = :tenant_id AND id = :id')
        ->execute(['tenant_id' => $tenantId, 'id' => (int) $created['id']]);

    $withMovements = (int) $pdo->query(
        'SELECT cuenta_id FROM contabilidad_partidas WHERE tenant_id = 1 ORDER BY id LIMIT 1'
    )->fetchColumn();
    try {
        contabilidadSaveAccount(module13User(), [
            'id' => $withMovements, 'codigo' => '', 'nombre' => 'Cuenta con movimientos',
            'tipo' => 'ACTIVO', 'naturaleza' => 'DEUDORA',
            'permite_movimiento' => '1', 'activa' => '0',
        ]);
        throw new RuntimeException('Se desactivó una cuenta con movimientos.');
    } catch (ContabilidadValidationException $exception) {
        checkModule13(isset($exception->errors['activa']), 'no se desactiva una cuenta con movimientos');
    }

    $invalidConfig = $config;
    $invalidConfig['cuenta_caja_id'] = $accounts['4101']['id'];
    try {
        contabilidadSaveConfig(module13User(), $invalidConfig);
        throw new RuntimeException('Se aceptó una cuenta de ingreso como caja.');
    } catch (ContabilidadValidationException $exception) {
        checkModule13(isset($exception->errors['cuenta_caja_id']), 'la configuración valida el tipo de cuenta');
    }
    $sameConfig = contabilidadSaveConfig(module13User(), $config);
    checkModule13(str_contains($sameConfig, 'no tiene cambios'), 'guardar la misma configuración no genera cambios');

    $diary = contabilidadDiario($tenantId, $monthStart, $today);
    $trial = contabilidadBalanza($tenantId, $monthStart, $today);
    checkModule13(abs($diary['total_debe'] - $trial['total_debe']) < 0.01, 'el libro diario cuadra con la balanza en el debe');
    checkModule13(abs($diary['total_haber'] - $trial['total_haber']) < 0.01, 'el libro diario cuadra con la balanza en el haber');

    $ledger = contabilidadMayor($tenantId, (int) $accounts['1101']['id'], $monthStart, $today);
    checkModule13(
        abs(($ledger['saldo_inicial'] + $ledger['total_debe'] - $ledger['total_haber']) - $ledger['saldo_final']) < 0.01,
        'el libro mayor calcula el saldo final correctamente'
    );

    $result = contabilidadEstadoResultados($tenantId, $monthStart, $today);
    checkModule13(
        abs($result['utilidad_bruta'] - ($result['total_ingresos'] - $result['total_costos'])) < 0.01,
        'el estado de resultados calcula la utilidad bruta'
    );
    checkModule13(
        abs($result['utilidad_neta'] - ($result['utilidad_bruta'] - $result['total_gastos'])) < 0.01,
        'el estado de resultados calcula la utilidad neta'
    );
    $balance = contabilidadBalanceGeneral($tenantId, $today);
    checkModule13(abs($balance['diferencia']) < 0.01, 'el balance general está cuadrado');
    checkModule13(
        abs($balance['total_activo'] - $balance['total_pasivo_patrimonio']) < 0.01,
        'activo igual a pasivo más patrimonio'
    );
    checkModule13(
        abs($balanceBefore['diferencia']) < 0.01,
        'el balance general estaba cuadrado antes de la prueba'
    );

    $csv = contabilidadCsv($tenantId, 'balanza', ['fecha_desde' => $monthStart, 'fecha_hasta' => $today]);
    checkModule13(count($csv['headers']) === 8 && count($csv['rows']) >= 1, 'la balanza se puede exportar a CSV');
    $csvDiary = contabilidadCsv($tenantId, 'diario', ['fecha_desde' => $monthStart, 'fecha_hasta' => $today]);
    checkModule13(count($csvDiary['headers']) === 9, 'el libro diario se puede exportar a CSV');

    echo "RESULTADO: {$checks} comprobaciones correctas.\n";
} finally {
    if ($emittedDocumentId !== null) {
        $pdo->prepare('DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id = :id')
            ->execute(['id' => $emittedDocumentId]);
        $pdo->prepare('DELETE FROM documentos WHERE tenant_id = 1 AND id = :id')
            ->execute(['id' => $emittedDocumentId]);
    }
    if ($originalDocSequences !== []) {
        $restoreDocSequence = $pdo->prepare(
            'UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type'
        );
        foreach ($originalDocSequences as $type => $value) {
            $restoreDocSequence->execute(['value' => (int) $value, 'type' => (string) $type]);
        }
    }
    if ($createdEntries !== []) {
        $pdo->exec('DELETE FROM contabilidad_asientos WHERE id IN (' . implode(',', $createdEntries) . ')');
    }
    $pdo->exec("DELETE FROM contabilidad_cuentas WHERE tenant_id = 1 AND codigo = '9999'");
    $pdo->exec(
        'UPDATE contabilidad_secuencias SET numero_actual = (
            SELECT COALESCE(MAX(numero), 0) FROM contabilidad_asientos WHERE tenant_id = 1
        ) WHERE tenant_id = 1'
    );
    $pdo->exec('DELETE FROM configuracion_cambios WHERE id > ' . $auditStart);
    echo "Datos temporales eliminados.\n";
}
