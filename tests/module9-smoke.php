<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/contingencia.php';

/**
 * Pruebas del módulo 9: emisión en contingencia, evento MH v4 y transmisión
 * diferida de los DTE. Restaura correlativos y elimina los datos temporales.
 */

$pdo = db();
$GLOBALS['facturasv_config']['mail'] = array_merge(
    is_array($GLOBALS['facturasv_config']['mail'] ?? null) ? $GLOBALS['facturasv_config']['mail'] : [],
    ['transport' => 'simulado', 'host' => '', 'user' => '', 'pass' => '']
);
$tenantId = 1;
$assertions = 0;
$documentIds = [];
$eventIds = [];
$originalSequences = $pdo->query(
    'SELECT tipo_dte, numero_actual FROM document_sequences WHERE tenant_id = 1'
)->fetchAll(PDO::FETCH_KEY_PAIR);
$today = (new DateTimeImmutable('now', new DateTimeZone('America/El_Salvador')))->format('Y-m-d');

function checkModule9(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function expectModule9Error(callable $callback, string $field, string $message): void
{
    $caught = false;
    try {
        $callback();
    } catch (DocumentValidationException $exception) {
        $caught = isset($exception->errors[$field]);
    }
    checkModule9($caught, $message);
}

function module9Input(string $today): array
{
    return [
        'tipo_dte' => '01', 'cliente_id' => '1', 'fecha_emision' => $today,
        'condicion_operacion_id' => '1', 'forma_pago_id' => '1', 'dias_credito' => '',
        'emisor_distrito' => '14', 'receptor_distrito' => '01', 'aplicar_iva_retenido' => '0',
        'observaciones' => 'Prueba automatizada del módulo 9',
        'items' => [[
            'producto_id' => '1', 'cantidad' => '1',
            'precio_unitario' => '113.00000000', 'descuento' => '0',
        ]],
    ];
}

function module9EventInput(string $today): array
{
    return [
        'f_inicio' => $today, 'h_inicio' => '00:00',
        'f_fin' => $today, 'h_fin' => '23:59',
        'tipo_contingencia' => '2',
        'motivo_contingencia' => 'Prueba de evento de contingencia',
        'nombre_responsable' => 'Administrador Demo',
        'tipo_doc_responsable' => '36',
        'numero_doc_responsable' => '0614-000000-000-0',
        'tipo_establecimiento' => '02',
    ];
}

try {
    checkModule9((int) $pdo->query('SELECT MAX(version) FROM schema_versions')->fetchColumn() >= 11, 'la base de datos está en la versión 11 o superior');
    checkModule9((int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'facturasv2' AND table_name IN ('eventos_contingencia','evento_contingencia_documentos')")->fetchColumn() === 2, 'existen las tablas de contingencia');
    checkModule9(count(contingencyTypes()) === 5, 'el catálogo de tipos de contingencia tiene cinco valores');

    $admin = $pdo->query("SELECT * FROM users WHERE tenant_id = 1 AND rol = 'ADMIN' LIMIT 1")->fetch();
    checkModule9(is_array($admin), 'existe el administrador de pruebas');

    $documentId = saveDocument($tenantId, (int) $admin['id'], module9Input($today));
    $documentIds[] = $documentId;
    $draft = documentDetail($tenantId, $documentId);
    checkModule9($draft['document']['estado'] === 'BORRADOR' && (int) $draft['document']['tipo_operacion'] === 1, 'el borrador inicia con operación normal');

    $emitted = emitDocument($tenantId, $admin, $documentId, [
        'enabled' => true,
        'tipo_contingencia' => 2,
        'motivo' => 'Falla simulada de Internet',
    ]);
    checkModule9($emitted['document']['estado'] === 'EMITIDA', 'el DTE en contingencia queda emitido sin transmitir');
    checkModule9((int) $emitted['document']['tipo_operacion'] === 2 && (int) $emitted['document']['tipo_contingencia'] === 2, 'el DTE guarda tipo de operación y contingencia');
    checkModule9($emitted['document']['motivo_contingencia'] === 'Falla simulada de Internet', 'el DTE guarda el motivo de contingencia');
    checkModule9($emitted['document']['sello_recepcion'] === null, 'el DTE en contingencia no tiene sello de recepción');
    checkModule9($emitted['document']['dte']['identificacion']['tipoOperacion'] === 2 && $emitted['document']['dte']['identificacion']['tipoContingencia'] === 2, 'el JSON del DTE marca la transmisión por contingencia');
    checkModule9($emitted['document']['dte']['identificacion']['motivoContin'] === 'Falla simulada de Internet', 'el JSON del DTE incluye el motivo de contingencia');
    checkModule9(($emitted['logs'] ?? []) === [], 'la emisión en contingencia no registra transmisión');

    $blocked = false;
    try {
        transmitDocument($tenantId, $admin, $documentId);
    } catch (RuntimeException $exception) {
        $blocked = str_contains($exception->getMessage(), 'contingencia');
    }
    checkModule9($blocked, 'un DTE en contingencia no se puede transmitir sin declarar el evento');

    $period = contingencyPeriodFromInput([
        'f_inicio' => $today, 'h_inicio' => '00:00', 'f_fin' => $today, 'h_fin' => '23:59',
    ], 'America/El_Salvador');
    $pending = contingencyPendingDocuments($pdo, $tenantId, $period);
    checkModule9(count(array_filter($pending, static fn(array $row): bool => (int) $row['id'] === $documentId)) === 1, 'el documento aparece como pendiente de contingencia');

    expectModule9Error(
        static fn() => contingencyValidateInput($pdo, 1, ['tipo_contingencia' => '5', 'motivo_contingencia' => ''] + module9EventInput($today)),
        'motivo_contingencia', 'el tipo 5 exige explicar el motivo'
    );
    expectModule9Error(
        static fn() => contingencyValidateInput($pdo, 1, ['f_inicio' => $today, 'f_fin' => $today, 'h_inicio' => '23:00', 'h_fin' => '01:00'] + module9EventInput($today)),
        'h_fin', 'el evento rechaza un periodo invertido'
    );
    expectModule9Error(
        static fn() => contingencyValidateInput($pdo, 1, ['numero_doc_responsable' => '123'] + module9EventInput($today)),
        'numero_doc_responsable', 'el evento valida el documento del responsable'
    );

    $created = contingencyCreateEvent($tenantId, $admin, module9EventInput($today));
    $event = $created['event'];
    $eventIds[] = (int) $event['id'];
    checkModule9($event['estado'] === 'PROCESADO', 'el evento de contingencia queda procesado');
    checkModule9(strlen((string) $event['sello_recepcion']) === 40, 'el evento recibe un sello de 40 caracteres');
    checkModule9(isset($event['evento']['identificacion']['version']) && (int) $event['evento']['identificacion']['version'] === 4, 'el evento guardado usa el esquema v4');
    checkModule9(count($event['evento']['detalleDTE']) === 1 && $event['evento']['detalleDTE'][0]['codigoGeneracion'] === $emitted['document']['codigo_generacion'], 'el evento incluye el DTE pendiente');
    checkModule9(($created['transmision']['total'] ?? 0) === 1 && ($created['transmision']['procesados'] ?? 0) === 1, 'el evento transmite el DTE pendiente');

    $processed = documentDetail($tenantId, $documentId);
    checkModule9($processed['document']['estado'] === 'PROCESADA', 'el DTE pasa a procesado después del evento');
    checkModule9(strlen((string) $processed['document']['sello_recepcion']) === 40, 'el DTE recibe su propio sello de recepción');
    checkModule9((int) $processed['document']['contingencia_evento_id'] === (int) $event['id'], 'el DTE queda vinculado al evento');
    checkModule9(count($processed['logs']) === 2, 'la transmisión diferida registra firma y transmisión');

    $logs = $pdo->query('SELECT accion, estado FROM mh_transmissions_log WHERE tenant_id = 1 AND evento_contingencia_id = ' . (int) $event['id'] . ' ORDER BY id')->fetchAll();
    $actions = array_column($logs, 'accion');
    checkModule9(in_array('CONTINGENCIA', $actions, true), 'el log del evento registra la acción CONTINGENCIA');

    $list = contingencyEventList($pdo, $tenantId);
    checkModule9(count(array_filter($list, static fn(array $row): bool => (int) $row['id'] === (int) $event['id'])) === 1, 'el evento aparece en el listado');
    $detail = contingencyEventDetail($pdo, $tenantId, (int) $event['id']);
    checkModule9(count($detail['documentos']) === 1 && ($detail['documentos'][0]['estado'] ?? '') === 'PROCESADA', 'el detalle del evento muestra el DTE procesado');
    checkModule9(contingencyPendingDocuments($pdo, $tenantId, $period) === [], 'un DTE ya declarado deja de estar pendiente');

    $duplicate = false;
    try {
        contingencyCreateEvent($tenantId, $admin, module9EventInput($today));
    } catch (DocumentValidationException $exception) {
        $duplicate = isset($exception->errors['documentos']);
    }
    checkModule9($duplicate, 'no se puede crear un segundo evento sin documentos pendientes');

    echo "RESULTADO: {$assertions} comprobaciones correctas.\n";
} finally {
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $pdo->prepare("DELETE FROM documento_correos WHERE tenant_id = 1 AND documento_id IN ({$placeholders})")->execute($documentIds);
    }
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $pdo->prepare("DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND documento_id IN ({$placeholders})")->execute($documentIds);
    }
    if ($eventIds !== []) {
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $pdo->prepare("DELETE FROM mh_transmissions_log WHERE tenant_id = 1 AND evento_contingencia_id IN ({$placeholders})")->execute($eventIds);
        $pdo->prepare("DELETE FROM evento_contingencia_documentos WHERE tenant_id = 1 AND evento_id IN ({$placeholders})")->execute($eventIds);
        $pdo->prepare("DELETE FROM documentos WHERE tenant_id = 1 AND contingencia_evento_id IN ({$placeholders})")->execute($eventIds);
        $pdo->prepare("DELETE FROM eventos_contingencia WHERE tenant_id = 1 AND id IN ({$placeholders})")->execute($eventIds);
    }
    if ($documentIds !== []) {
        $placeholders = implode(',', array_fill(0, count($documentIds), '?'));
        $pdo->prepare("DELETE FROM documentos WHERE tenant_id = 1 AND id IN ({$placeholders})")->execute($documentIds);
    }
    if ($originalSequences !== []) {
        $restore = $pdo->prepare('UPDATE document_sequences SET numero_actual = :value WHERE tenant_id = 1 AND tipo_dte = :type');
        foreach ($originalSequences as $type => $value) {
            $restore->execute(['value' => (int) $value, 'type' => (string) $type]);
        }
    }
    echo "Datos temporales eliminados.\n";
}
