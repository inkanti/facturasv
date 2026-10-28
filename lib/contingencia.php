<?php

declare(strict_types=1);

require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/dte-builder.php';

/**
 * Módulo 9: contingencia de DTE.
 *
 * Un DTE emitido en contingencia queda en estado EMITIDA, con
 * tipo_operacion = 2, sin sello y sin transmisión. El evento del esquema MH v4
 * agrupa los DTE del periodo y, cuando se procesa, el sistema transmite cada
 * DTE pendiente y los pasa a PROCESADA.
 */

function contingencyTypes(): array
{
    return [
        1 => 'No disponibilidad de sistema del MH',
        2 => 'No disponibilidad de sistema del emisor',
        3 => 'Falla en el suministro de servicio de Internet del emisor',
        4 => 'Falla en el suministro de servicio de energía eléctrica del emisor',
        5 => 'Otro (describa el motivo)',
    ];
}

function contingencyTypeName(int $type): string
{
    return contingencyTypes()[$type] ?? 'Tipo desconocido';
}

function contingencyEstablishmentTypes(PDO $pdo, int $tenantId): array
{
    $statement = $pdo->prepare(
        'SELECT codigo, nombre FROM mh_tipos_establecimiento
         WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY orden, id'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    $options = [];
    foreach ($statement->fetchAll() as $row) {
        $options[(string) $row['codigo']] = (string) $row['nombre'];
    }
    return $options;
}

function contingencyDateValue(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $value = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : null;
}

function contingencyTimeValue(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $value = trim($value);
    if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $value, $matches) !== 1) {
        return null;
    }
    return $matches[1] . ':' . $matches[2] . ':' . ($matches[3] ?? '00');
}

/**
 * Valida y normaliza solo el periodo del evento.
 *
 * @return array{f_inicio:string,f_fin:string,h_inicio:string,h_fin:string,start:string,end:string}
 */
function contingencyPeriodFromInput(array $input, string $timezone): array
{
    $errors = [];
    $fInicio = contingencyDateValue($input['f_inicio'] ?? null);
    $fFin = contingencyDateValue($input['f_fin'] ?? null);
    $hInicio = contingencyTimeValue($input['h_inicio'] ?? null);
    $hFinRaw = is_string($input['h_fin'] ?? null) ? trim($input['h_fin']) : '';
    $hFin = contingencyTimeValue($input['h_fin'] ?? null);
    // El formulario envía HH:MM sin segundos. Para el fin del periodo se
    // interpreta como el último segundo de ese minuto, de modo que un DTE
    // emitido en ese mismo minuto no quede fuera del evento.
    if ($hFin !== null && preg_match('/^[0-2][0-9]:[0-5][0-9]$/', $hFinRaw) === 1) {
        $hFin = substr($hFin, 0, 5) . ':59';
    }

    if ($fInicio === null) $errors['f_inicio'] = 'Ingrese una fecha de inicio válida.';
    if ($fFin === null) $errors['f_fin'] = 'Ingrese una fecha de fin válida.';
    if ($hInicio === null) $errors['h_inicio'] = 'Ingrese una hora de inicio válida.';
    if ($hFin === null) $errors['h_fin'] = 'Ingrese una hora de fin válida.';

    if ($errors === []) {
        $start = new DateTimeImmutable($fInicio . ' ' . $hInicio, new DateTimeZone($timezone));
        $end = new DateTimeImmutable($fFin . ' ' . $hFin, new DateTimeZone($timezone));
        if ($start >= $end) {
            $errors['h_fin'] = 'El fin de la contingencia debe ser posterior al inicio.';
        }
        $today = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');
        if ($fFin > $today) {
            $errors['f_fin'] = 'La fecha de fin no puede ser futura.';
        }
    }

    if ($errors !== []) {
        throw new DocumentValidationException('Revise el periodo de contingencia.', $errors);
    }

    return [
        'f_inicio' => $fInicio,
        'f_fin' => $fFin,
        'h_inicio' => $hInicio,
        'h_fin' => $hFin,
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $end->format('Y-m-d H:i:s'),
    ];
}

/**
 * Valida todos los datos del evento (periodo, motivo, responsable y establecimiento).
 */
function contingencyValidateInput(PDO $pdo, int $tenantId, array $input): array
{
    $tenant = tenantDocumentContext($pdo, $tenantId);
    $period = contingencyPeriodFromInput($input, $tenant['timezone']);
    $errors = [];

    $typeValue = filter_var($input['tipo_contingencia'] ?? null, FILTER_VALIDATE_INT);
    $type = is_int($typeValue) ? $typeValue : null;
    if ($type === null || $type < 1 || $type > 5) {
        $errors['tipo_contingencia'] = 'Seleccione un tipo de contingencia válido.';
    }

    $motivo = is_string($input['motivo_contingencia'] ?? null) ? trim($input['motivo_contingencia']) : '';
    if (mb_strlen($motivo) > 500) {
        $errors['motivo_contingencia'] = 'El motivo no puede superar los 500 caracteres.';
    } elseif ($type === 5 && $motivo === '') {
        $errors['motivo_contingencia'] = 'Explique el motivo cuando selecciona el tipo 5.';
    }

    $nombre = is_string($input['nombre_responsable'] ?? null) ? trim($input['nombre_responsable']) : '';
    if ($nombre === '' || mb_strlen($nombre) > 100) {
        $errors['nombre_responsable'] = 'Ingrese el nombre del responsable (máximo 100 caracteres).';
    }

    $tipoDoc = is_string($input['tipo_doc_responsable'] ?? null) ? trim($input['tipo_doc_responsable']) : '';
    if (!in_array($tipoDoc, ['13', '36'], true)) {
        $errors['tipo_doc_responsable'] = 'Seleccione DUI o NIT del responsable.';
    }

    $numeroDoc = preg_replace('/\D/', '', (string) ($input['numero_doc_responsable'] ?? '')) ?? '';
    if ($tipoDoc === '13' && strlen($numeroDoc) !== 9) {
        $errors['numero_doc_responsable'] = 'El DUI del responsable debe tener 9 dígitos.';
    } elseif ($tipoDoc === '36' && strlen($numeroDoc) !== 14) {
        $errors['numero_doc_responsable'] = 'El NIT del responsable debe tener 14 dígitos.';
    }

    $tipoEstablecimiento = is_string($input['tipo_establecimiento'] ?? null) ? trim($input['tipo_establecimiento']) : '';
    $establishments = contingencyEstablishmentTypes($pdo, $tenantId);
    if (!array_key_exists($tipoEstablecimiento, $establishments)) {
        $errors['tipo_establecimiento'] = 'Seleccione un tipo de establecimiento válido.';
    }

    if ($errors !== []) {
        throw new DocumentValidationException('Revise los datos del evento de contingencia.', $errors);
    }

    return $period + [
        'tipo_contingencia' => $type,
        'motivo_contingencia' => $motivo === '' ? null : $motivo,
        'nombre_responsable' => $nombre,
        'tipo_doc_responsable' => $tipoDoc,
        'numero_doc_responsable' => $numeroDoc,
        'tipo_establecimiento' => $tipoEstablecimiento,
    ];
}

/**
 * DTE emitidos en contingencia, pendientes de declarar, dentro del periodo.
 */
function contingencyPendingDocuments(PDO $pdo, int $tenantId, array $period, ?int $tipoContingencia = null): array
{
    $sql = "SELECT d.id, d.tipo_dte, d.numero_control, d.codigo_generacion, d.fecha_emision, d.hora_emision,
                d.total_pagar, d.estado, d.tipo_contingencia, d.motivo_contingencia,
                c.nombre AS cliente_nombre, c.numero_documento AS cliente_documento
         FROM documentos d
         INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
         WHERE d.tenant_id = :tenant_id
           AND d.tipo_operacion = 2
           AND d.estado = 'EMITIDA'
           AND d.contingencia_evento_id IS NULL
           AND d.codigo_generacion IS NOT NULL
           AND d.codigo_generacion <> ''
           AND TIMESTAMP(d.fecha_emision, COALESCE(d.hora_emision, '00:00:00')) BETWEEN :desde AND :hasta";
    $params = [
        'tenant_id' => $tenantId,
        'desde' => $period['start'],
        'hasta' => $period['end'],
    ];
    if ($tipoContingencia !== null) {
        $sql .= ' AND d.tipo_contingencia = :tipo_contingencia';
        $params['tipo_contingencia'] = $tipoContingencia;
    }
    $sql .= ' ORDER BY d.fecha_emision, d.hora_emision, d.id';
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function contingencyEventRecord(PDO $pdo, int $tenantId, int $eventId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM eventos_contingencia WHERE tenant_id = :tenant_id AND id = :id');
    $statement->execute(['tenant_id' => $tenantId, 'id' => $eventId]);
    $event = $statement->fetch();
    return $event === false ? null : $event;
}

function contingencyEventList(PDO $pdo, int $tenantId): array
{
    $statement = $pdo->prepare(
        'SELECT e.id, e.codigo_generacion, e.estado, e.f_transmision, e.h_transmision,
                e.f_inicio, e.f_fin, e.h_inicio, e.h_fin, e.tipo_contingencia,
                e.motivo_contingencia, e.sello_recepcion, e.error, e.created_at,
                COUNT(ed.id) AS documentos
         FROM eventos_contingencia e
         LEFT JOIN evento_contingencia_documentos ed
            ON ed.tenant_id = e.tenant_id AND ed.evento_id = e.id
         WHERE e.tenant_id = :tenant_id
         GROUP BY e.id, e.codigo_generacion, e.estado, e.f_transmision, e.h_transmision,
                  e.f_inicio, e.f_fin, e.h_inicio, e.h_fin, e.tipo_contingencia,
                  e.motivo_contingencia, e.sello_recepcion, e.error, e.created_at
         ORDER BY e.id DESC LIMIT 100'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    return $statement->fetchAll();
}

function contingencyEventDetail(PDO $pdo, int $tenantId, int $eventId): array
{
    $event = contingencyEventRecord($pdo, $tenantId, $eventId);
    if ($event === null) {
        throw new RuntimeException('El evento de contingencia no existe.');
    }
    $event['evento'] = $event['evento_json'] ? json_decode((string) $event['evento_json'], true) : null;
    $event['response'] = $event['response_json'] ? json_decode((string) $event['response_json'], true) : null;
    unset($event['evento_json'], $event['response_json']);

    $statement = $pdo->prepare(
        'SELECT d.id, d.tipo_dte, d.numero_control, d.codigo_generacion, d.fecha_emision, d.hora_emision,
                d.estado, d.sello_recepcion, d.total_pagar, ed.no_item,
                c.nombre AS cliente_nombre, c.numero_documento AS cliente_documento
         FROM evento_contingencia_documentos ed
         INNER JOIN documentos d ON d.tenant_id = ed.tenant_id AND d.id = ed.documento_id
         INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
         WHERE ed.tenant_id = :tenant_id AND ed.evento_id = :evento_id
         ORDER BY ed.no_item'
    );
    $statement->execute(['tenant_id' => $tenantId, 'evento_id' => $eventId]);
    $documents = $statement->fetchAll();

    $statement = $pdo->prepare(
        'SELECT l.id, l.accion, l.request_json, l.response_json, l.estado, l.created_at, u.nombre AS usuario
         FROM mh_transmissions_log l
         LEFT JOIN users u ON u.id = l.user_id
         WHERE l.tenant_id = :tenant_id AND l.evento_contingencia_id = :evento_id
         ORDER BY l.id DESC'
    );
    $statement->execute(['tenant_id' => $tenantId, 'evento_id' => $eventId]);
    $logs = array_map(static function (array $log): array {
        $log['request'] = $log['request_json'] ? json_decode((string) $log['request_json'], true) : null;
        $log['response'] = $log['response_json'] ? json_decode((string) $log['response_json'], true) : null;
        unset($log['request_json'], $log['response_json']);
        return $log;
    }, $statement->fetchAll());

    return ['event' => $event, 'documentos' => $documents, 'logs' => $logs];
}

/**
 * Crea el evento, lo guarda como BORRADOR y lo transmite en simulación.
 */
function contingencyCreateEvent(int $tenantId, array $user, array $input): array
{
    $pdo = db();
    $data = contingencyValidateInput($pdo, $tenantId, $input);
    $tenant = tenantDocumentContext($pdo, $tenantId);
    $documents = contingencyPendingDocuments($pdo, $tenantId, $data, (int) $data['tipo_contingencia']);
    if ($documents === []) {
        throw new DocumentValidationException(
            'No hay documentos emitidos en contingencia de ese tipo dentro del periodo seleccionado.',
            ['documentos' => 'Primero emita uno o más DTE en contingencia de ese tipo y ajuste el periodo.']
        );
    }
    if (count($documents) > 1000) {
        throw new DocumentValidationException(
            'El evento admite como máximo 1000 documentos.',
            ['documentos' => 'Divida la contingencia en varios eventos.']
        );
    }

    $now = new DateTimeImmutable('now', new DateTimeZone($tenant['timezone']));
    $header = [
        'codigo_generacion' => generateDteUuid(),
        'f_transmision' => $now->format('Y-m-d'),
        'h_transmision' => $now->format('H:i:s'),
        'f_inicio' => $data['f_inicio'],
        'f_fin' => $data['f_fin'],
        'h_inicio' => $data['h_inicio'],
        'h_fin' => $data['h_fin'],
        'tipo_contingencia' => $data['tipo_contingencia'],
        'motivo_contingencia' => $data['motivo_contingencia'],
    ];
    $responsible = [
        'nombre' => $data['nombre_responsable'],
        'tipo_documento' => $data['tipo_doc_responsable'],
        'numero_documento' => $data['numero_doc_responsable'],
        'tipo_establecimiento' => $data['tipo_establecimiento'],
    ];
    $event = buildContingencyEvent($header, $documents, $tenant, $responsible);
    $eventJson = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare(
            'INSERT INTO eventos_contingencia
                (tenant_id, codigo_generacion, f_transmision, h_transmision, f_inicio, f_fin, h_inicio, h_fin,
                 tipo_contingencia, motivo_contingencia, nombre_responsable, tipo_doc_responsable,
                 numero_doc_responsable, tipo_establecimiento, evento_json, created_by)
             VALUES
                (:tenant_id, :codigo_generacion, :f_transmision, :h_transmision, :f_inicio, :f_fin, :h_inicio, :h_fin,
                 :tipo_contingencia, :motivo_contingencia, :nombre_responsable, :tipo_doc_responsable,
                 :numero_doc_responsable, :tipo_establecimiento, :evento_json, :created_by)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'codigo_generacion' => $header['codigo_generacion'],
            'f_transmision' => $header['f_transmision'],
            'h_transmision' => $header['h_transmision'],
            'f_inicio' => $data['f_inicio'],
            'f_fin' => $data['f_fin'],
            'h_inicio' => $data['h_inicio'],
            'h_fin' => $data['h_fin'],
            'tipo_contingencia' => $data['tipo_contingencia'],
            'motivo_contingencia' => $data['motivo_contingencia'],
            'nombre_responsable' => $data['nombre_responsable'],
            'tipo_doc_responsable' => $data['tipo_doc_responsable'],
            'numero_doc_responsable' => $data['numero_doc_responsable'],
            'tipo_establecimiento' => $data['tipo_establecimiento'],
            'evento_json' => $eventJson,
            'created_by' => (int) $user['id'],
        ]);
        $eventId = (int) $pdo->lastInsertId();

        $link = $pdo->prepare(
            'INSERT INTO evento_contingencia_documentos (tenant_id, evento_id, documento_id, no_item)
             VALUES (:tenant_id, :evento_id, :documento_id, :no_item)'
        );
        $update = $pdo->prepare(
            "UPDATE documentos SET contingencia_evento_id = :evento_id
             WHERE tenant_id = :tenant_id AND id = :documento_id
               AND tipo_operacion = 2 AND estado = 'EMITIDA' AND contingencia_evento_id IS NULL"
        );
        foreach ($documents as $index => $document) {
            $link->execute([
                'tenant_id' => $tenantId,
                'evento_id' => $eventId,
                'documento_id' => (int) $document['id'],
                'no_item' => $index + 1,
            ]);
            $update->execute([
                'evento_id' => $eventId,
                'tenant_id' => $tenantId,
                'documento_id' => (int) $document['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Un documento cambió de estado mientras se preparaba el evento. Vuelva a intentarlo.');
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }

    return contingencyTransmitEvent($tenantId, $user, $eventId);
}

/**
 * Transmite el evento (simulación) y, si queda PROCESADO, transmite sus DTE.
 */
function contingencyTransmitEvent(int $tenantId, array $user, int $eventId): array
{
    $pdo = db();
    $event = contingencyEventRecord($pdo, $tenantId, $eventId);
    if ($event === null) {
        throw new RuntimeException('El evento de contingencia no existe.');
    }
    if ($event['estado'] === 'PROCESADO') {
        $summary = contingencyTransmitDocuments($tenantId, $user, $eventId);
        $detail = contingencyEventDetail($pdo, $tenantId, $eventId);
        $detail['transmision'] = $summary;
        return $detail;
    }
    if (empty($event['evento_json'])) {
        throw new RuntimeException('El evento no tiene un JSON para transmitir.');
    }
    $payload = json_decode((string) $event['evento_json'], true);
    if (!is_array($payload)) {
        throw new RuntimeException('El JSON del evento de contingencia no es válido.');
    }

    $tenant = tenantDocumentContext($pdo, $tenantId);
    $connector = MHConnectorFactory::make($tenant['mh_mode']);
    $response = null;
    try {
        $response = $connector->transmitirEventoContingencia($payload, ['tenant_id' => $tenantId]);
        mhLog(
            $pdo, $tenantId, null, (int) $user['id'], 'CONTINGENCIA',
            $payload, $response, (string) ($response['estado'] ?? 'ERROR'), $eventId
        );
        if (($response['estado'] ?? '') !== 'PROCESADO') {
            throw new RuntimeException('El evento de contingencia no fue procesado por el MH.');
        }
        $seal = $response['selloRecetion'] ?? $response['selloRecepcion'] ?? null;
        if (!is_string($seal) || !preg_match('/^[A-Z0-9]{40}$/', $seal)) {
            throw new RuntimeException('La respuesta simulada del evento no contiene un sello válido.');
        }
        $statement = $pdo->prepare(
            "UPDATE eventos_contingencia
             SET estado = 'PROCESADO', sello_recepcion = :sello, response_json = :response_json,
                 error = NULL, procesado_at = UTC_TIMESTAMP()
             WHERE tenant_id = :tenant_id AND id = :id"
        );
        $statement->execute([
            'sello' => $seal,
            'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'tenant_id' => $tenantId,
            'id' => $eventId,
        ]);
    } catch (Throwable $exception) {
        if ($response === null) {
            mhLog(
                $pdo, $tenantId, null, (int) $user['id'], 'CONTINGENCIA',
                $payload, ['error' => $exception->getMessage()], 'ERROR', $eventId
            );
        }
        $statement = $pdo->prepare(
            "UPDATE eventos_contingencia
             SET estado = 'RECHAZADO', response_json = :response_json, error = :error
             WHERE tenant_id = :tenant_id AND id = :id"
        );
        $statement->execute([
            'response_json' => json_encode(
                $response === null ? ['error' => $exception->getMessage()] : $response,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            ),
            'error' => mb_substr($exception->getMessage(), 0, 500),
            'tenant_id' => $tenantId,
            'id' => $eventId,
        ]);
        throw $exception;
    }

    $summary = contingencyTransmitDocuments($tenantId, $user, $eventId);
    $detail = contingencyEventDetail($pdo, $tenantId, $eventId);
    $detail['transmision'] = $summary;
    return $detail;
}

/**
 * Transmite los DTE del evento que sigan pendientes.
 *
 * @return array{total:int,procesados:int,errores:array<int,array{documento_id:int,message:string}>}
 */
function contingencyTransmitDocuments(int $tenantId, array $user, int $eventId): array
{
    $pdo = db();
    $statement = $pdo->prepare(
        "SELECT d.id
         FROM evento_contingencia_documentos ed
         INNER JOIN documentos d ON d.tenant_id = ed.tenant_id AND d.id = ed.documento_id
         WHERE ed.tenant_id = :tenant_id AND ed.evento_id = :evento_id
           AND d.estado IN ('EMITIDA', 'RECHAZADA')
         ORDER BY ed.no_item"
    );
    $statement->execute(['tenant_id' => $tenantId, 'evento_id' => $eventId]);
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $statement->fetchAll());

    $summary = ['total' => count($ids), 'procesados' => 0, 'errores' => []];
    foreach ($ids as $documentId) {
        try {
            transmitDocument($tenantId, $user, $documentId);
            $summary['procesados']++;
        } catch (Throwable $exception) {
            $summary['errores'][] = ['documento_id' => $documentId, 'message' => $exception->getMessage()];
        }
    }
    return $summary;
}

/**
 * Reintenta un evento rechazado o los DTE pendientes de un evento ya procesado.
 */
function contingencyRetryEvent(int $tenantId, array $user, int $eventId): array
{
    $pdo = db();
    $event = contingencyEventRecord($pdo, $tenantId, $eventId);
    if ($event === null) {
        throw new RuntimeException('El evento de contingencia no existe.');
    }
    if ($event['estado'] === 'PROCESADO') {
        $summary = contingencyTransmitDocuments($tenantId, $user, $eventId);
        $detail = contingencyEventDetail($pdo, $tenantId, $eventId);
        $detail['transmision'] = $summary;
        return $detail;
    }
    return contingencyTransmitEvent($tenantId, $user, $eventId);
}
