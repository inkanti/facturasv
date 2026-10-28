<?php

declare(strict_types=1);

function productTaxTreatments(): array
{
    return [
        'GRAVADO' => 'Gravado (13 %)',
        'EXENTO' => 'Exento',
        'NO_SUJETO' => 'No sujeto',
    ];
}

function normalizeProductData(array $input): array
{
    $text = static function (string $key, int $max) use ($input): string {
        $value = $input[$key] ?? '';
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    };

    return [
        'id' => $input['id'] ?? null,
        'codigo' => mb_strtoupper($text('codigo', 30)),
        'codigo_barras' => mb_strtoupper($text('codigo_barras', 64)),
        'descripcion' => $text('descripcion', 255),
        'tipo' => mb_strtoupper($text('tipo', 10)),
        'unidad_medida_id' => $input['unidad_medida_id'] ?? null,
        'precio_unitario' => str_replace(',', '.', $text('precio_unitario', 30)),
        'tratamiento_iva' => mb_strtoupper($text('tratamiento_iva', 20)),
        'control_stock' => isset($input['control_stock']) && ($input['control_stock'] === '1' || $input['control_stock'] === 1) ? 1 : 0,
        'stock' => str_replace(',', '.', $text('stock', 30)),
        'activo' => isset($input['activo']) && ($input['activo'] === '1' || $input['activo'] === 1) ? 1 : 0,
    ];
}

function validateProductData(PDO $pdo, int $tenantId, array $data): array
{
    $errors = [];

    if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,29}$/', $data['codigo'])) {
        $errors['codigo'] = 'Use hasta 30 caracteres: letras, números, punto, guion o guion bajo.';
    }
    if ($data['codigo_barras'] !== '') {
        if (preg_match('/^[\x21-\x7E]{4,64}$/', $data['codigo_barras']) !== 1) {
            $errors['codigo_barras'] = 'Use de 4 a 64 caracteres sin espacios (letras, números y símbolos del código).';
        } else {
            $currentId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $sql = 'SELECT id FROM productos
                    WHERE tenant_id = :tenant_id AND codigo_barras = :codigo_barras'
                . ($currentId !== false && $currentId !== null ? ' AND id <> :id' : '')
                . ' LIMIT 1';
            $statement = $pdo->prepare($sql);
            $params = ['tenant_id' => $tenantId, 'codigo_barras' => $data['codigo_barras']];
            if ($currentId !== false && $currentId !== null) {
                $params['id'] = $currentId;
            }
            $statement->execute($params);
            if ($statement->fetch() !== false) {
                $errors['codigo_barras'] = 'El código de barras ya está asignado a otro producto.';
            }
        }
    }
    if (mb_strlen($data['descripcion']) < 2) {
        $errors['descripcion'] = 'Ingrese la descripción del producto o servicio.';
    }
    if (!in_array($data['tipo'], ['BIEN', 'SERVICIO'], true)) {
        $errors['tipo'] = 'Seleccione bien o servicio.';
    }
    if (!array_key_exists($data['tratamiento_iva'], productTaxTreatments())) {
        $errors['tratamiento_iva'] = 'Seleccione un tratamiento de IVA válido.';
    }

    $unitId = filter_var($data['unidad_medida_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($unitId === false || $unitId === null) {
        $errors['unidad_medida_id'] = 'Seleccione una unidad de medida.';
    } else {
        $statement = $pdo->prepare(
            'SELECT id FROM mh_unidades_medida
             WHERE tenant_id = :tenant_id AND id = :id AND activo = 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $unitId]);
        if ($statement->fetch() === false) {
            $errors['unidad_medida_id'] = 'La unidad de medida no existe para esta empresa o está inactiva.';
        }
    }

    if (!preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/', $data['precio_unitario'])
        || (float) $data['precio_unitario'] > 999999999.999999) {
        $errors['precio_unitario'] = 'Ingrese un precio entre 0 y 999999999.999999, con hasta 6 decimales.';
    } else {
        $data['precio_unitario'] = number_format((float) $data['precio_unitario'], 6, '.', '');
    }

    if ($data['control_stock'] === 1) {
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,4})?$/', $data['stock'])
            || (float) $data['stock'] > 99999999999.9999) {
            $errors['stock'] = 'Ingrese existencias entre 0 y 99999999999.9999, con hasta 4 decimales.';
        } else {
            $data['stock'] = number_format((float) $data['stock'], 4, '.', '');
        }
    } else {
        $data['stock'] = null;
    }

    $data['unidad_medida_id'] = $unitId;
    return [$data, $errors];
}

function productOptions(int $tenantId): array
{
    $statement = db()->prepare(
        'SELECT id, codigo, nombre FROM mh_unidades_medida
         WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY orden, nombre'
    );
    $statement->execute(['tenant_id' => $tenantId]);

    return [
        'unidades_medida' => $statement->fetchAll(),
        'tratamientos_iva' => productTaxTreatments(),
    ];
}

function productQueryParts(int $tenantId, array $filters): array
{
    $where = ['p.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];
    $search = mb_substr(trim((string) ($filters['search'] ?? '')), 0, 100);

    $searchCondition = smartSearchCondition(
        ['p.codigo', 'p.codigo_barras', 'p.descripcion'],
        $search,
        'product_search_',
        $params
    );
    if ($searchCondition !== null) {
        $where[] = $searchCondition;
    }
    if (in_array($filters['tipo'] ?? '', ['BIEN', 'SERVICIO'], true)) {
        $where[] = 'p.tipo = :tipo';
        $params['tipo'] = $filters['tipo'];
    }
    if (array_key_exists($filters['tratamiento_iva'] ?? '', productTaxTreatments())) {
        $where[] = 'p.tratamiento_iva = :tratamiento_iva';
        $params['tratamiento_iva'] = $filters['tratamiento_iva'];
    }
    if (($filters['status'] ?? '') === '0' || ($filters['status'] ?? '') === '1') {
        $where[] = 'p.activo = :status';
        $params['status'] = (int) $filters['status'];
    }

    return ['where' => implode(' AND ', $where), 'params' => $params];
}
