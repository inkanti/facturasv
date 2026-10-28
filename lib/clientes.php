<?php

declare(strict_types=1);

function clientContributorTypes(): array
{
    return [
        'GRAN_CONTRIBUYENTE' => 'Gran contribuyente',
        'SUJETO_EXCLUIDO' => 'Sujeto excluido',
        'OTRO' => 'Otro',
    ];
}

function normalizeClientData(array $input): array
{
    $text = static function (string $key, int $max) use ($input): string {
        $value = $input[$key] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    };

    return [
        'id' => $input['id'] ?? null,
        'tipo_documento' => mb_strtoupper($text('tipo_documento', 7)),
        'numero_documento' => $text('numero_documento', 17),
        'nombre' => $text('nombre', 150),
        'nombre_comercial' => $text('nombre_comercial', 150),
        'actividad_economica' => $text('actividad_economica', 150),
        'direccion' => $text('direccion', 255),
        'departamento_id' => $input['departamento_id'] ?? null,
        'municipio_id' => $input['municipio_id'] ?? null,
        'telefono' => $text('telefono', 12),
        'email' => mb_strtolower($text('email', 150)),
        'tipo_contribuyente' => mb_strtoupper($text('tipo_contribuyente', 30)),
        'activo' => !isset($input['activo']) || $input['activo'] === '1' || $input['activo'] === 1 ? 1 : 0,
    ];
}

function validateClientData(PDO $pdo, int $tenantId, array $data): array
{
    $errors = [];

    if (!in_array($data['tipo_documento'], ['NIT', 'DUI', 'NINGUNO'], true)) {
        $errors['tipo_documento'] = 'Seleccione NIT, DUI o Sin documento.';
    }
    if ($data['tipo_documento'] === 'NIT' && !validNit($data['numero_documento'])) {
        $errors['numero_documento'] = 'El NIT debe usar el formato 0000-000000-000-0.';
    }
    if ($data['tipo_documento'] === 'DUI' && !preg_match('/^[0-9]{8}-[0-9]$/', $data['numero_documento'])) {
        $errors['numero_documento'] = 'El DUI debe usar el formato 00000000-0.';
    }
    if ($data['tipo_documento'] === 'NINGUNO' && $data['numero_documento'] !== '') {
        $errors['numero_documento'] = 'Un cliente sin documento no debe llevar número; elija NIT o DUI si lo tiene.';
    }
    if (mb_strlen($data['nombre']) < 2) {
        $errors['nombre'] = 'Ingrese el nombre o razón social.';
    }
    if (mb_strlen($data['direccion']) < 3) {
        $errors['direccion'] = 'Ingrese el complemento de la dirección.';
    }
    if ($data['telefono'] !== '' && !preg_match('/^[0-9]{4}-[0-9]{4}$/', $data['telefono'])) {
        $errors['telefono'] = 'Use el formato 0000-0000.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Ingrese un correo electrónico válido.';
    }
    if (!array_key_exists($data['tipo_contribuyente'], clientContributorTypes())) {
        $errors['tipo_contribuyente'] = 'Seleccione un tipo de contribuyente válido.';
    }

    $departmentId = filter_var($data['departamento_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $municipalityId = filter_var($data['municipio_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($departmentId === false || $departmentId === null) {
        $errors['departamento_id'] = 'Seleccione un departamento.';
    }
    if ($municipalityId === false || $municipalityId === null) {
        $errors['municipio_id'] = 'Seleccione un municipio.';
    }

    if (!isset($errors['departamento_id']) && !isset($errors['municipio_id'])) {
        $statement = $pdo->prepare(
            'SELECT m.id, m.departamento_id
             FROM mh_municipios m
             INNER JOIN mh_departamentos d
                ON d.tenant_id = m.tenant_id AND d.id = m.departamento_id
             WHERE m.tenant_id = :tenant_id AND m.id = :municipio_id
               AND m.departamento_id = :departamento_id
               AND m.activo = 1 AND d.activo = 1'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'municipio_id' => $municipalityId,
            'departamento_id' => $departmentId,
        ]);
        if ($statement->fetch() === false) {
            $errors['municipio_id'] = 'El municipio no pertenece al departamento seleccionado o está inactivo.';
        }
    }

    $data['departamento_id'] = $departmentId;
    $data['municipio_id'] = $municipalityId;
    if ($data['tipo_documento'] === 'NINGUNO') {
        $data['numero_documento'] = null;
    }
    foreach (['nombre_comercial', 'actividad_economica', 'telefono', 'email'] as $field) {
        $data[$field] = $data[$field] === '' ? null : $data[$field];
    }

    return [$data, $errors];
}

function clientOptions(int $tenantId): array
{
    $statement = db()->prepare(
        'SELECT id, codigo, nombre FROM mh_departamentos
         WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY orden, nombre'
    );
    $statement->execute(['tenant_id' => $tenantId]);

    $statement2 = db()->prepare(
        'SELECT id, departamento_id, codigo, nombre FROM mh_municipios
         WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY orden, nombre'
    );
    $statement2->execute(['tenant_id' => $tenantId]);

    return [
        'departamentos' => $statement->fetchAll(),
        'municipios' => $statement2->fetchAll(),
        'tipos_contribuyente' => clientContributorTypes(),
    ];
}

function clientQueryParts(int $tenantId, array $filters): array
{
    $from = 'FROM clientes c
             INNER JOIN mh_departamentos d
                ON d.tenant_id = c.tenant_id AND d.id = c.departamento_id
             INNER JOIN mh_municipios m
                ON m.tenant_id = c.tenant_id AND m.id = c.municipio_id';
    $where = ['c.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100);

    $searchCondition = smartSearchCondition([
        'c.numero_documento',
        "REPLACE(c.numero_documento, '-', '')",
        'c.nombre',
        'c.nombre_comercial',
        'c.email',
        'c.actividad_economica',
        'c.telefono',
        "REPLACE(c.telefono, '-', '')",
    ], $search, 'client_search_', $params);
    if ($searchCondition !== null) {
        $where[] = $searchCondition;
    }

    $status = $filters['status'] ?? '';
    if ($status === '0' || $status === '1') {
        $where[] = 'c.activo = :status';
        $params['status'] = (int) $status;
    }

    $contributor = $filters['tipo_contribuyente'] ?? '';
    if (array_key_exists($contributor, clientContributorTypes())) {
        $where[] = 'c.tipo_contribuyente = :contributor';
        $params['contributor'] = $contributor;
    }

    return [
        'from' => $from,
        'where' => implode(' AND ', $where),
        'params' => $params,
    ];
}

function clientCsvRow(array $client): array
{
    return [
        $client['tipo_documento'],
        $client['numero_documento'],
        $client['nombre'],
        $client['nombre_comercial'] ?? '',
        $client['actividad_economica'] ?? '',
        $client['direccion'],
        $client['departamento_codigo'],
        $client['municipio_codigo'],
        $client['telefono'] ?? '',
        $client['email'] ?? '',
        $client['tipo_contribuyente'],
        (string) $client['activo'],
    ];
}

function clientCsvHeaders(): array
{
    return [
        'tipo_documento', 'numero_documento', 'nombre', 'nombre_comercial',
        'actividad_economica', 'direccion', 'departamento_codigo', 'municipio_codigo',
        'telefono', 'email', 'tipo_contribuyente', 'activo',
    ];
}

function csvSafeCell(mixed $value): string
{
    $value = (string) $value;
    if (preg_match('/^[\s]*[=+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}
