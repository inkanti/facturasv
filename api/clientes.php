<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/clientes.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}

$tenantId = (int) $user['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requireClientWrite(array $user): void
{
    if (!in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true)) {
        jsonResponse([
            'success' => false,
            'message' => 'Su rol solo permite consultar y exportar clientes.',
        ], 403);
    }
}

function clientCsvDownload(string $filename, array $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, clientCsvHeaders(), ';');
    foreach ($rows as $row) {
        fputcsv($output, array_map('csvSafeCell', clientCsvRow($row)), ';');
    }
    fclose($output);
    exit;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'options') {
        jsonResponse(['success' => true] + clientOptions($tenantId));
    }

    if ($action === 'template') {
        clientCsvDownload('plantilla_clientes.csv', [[
            'tipo_documento' => 'NIT',
            'numero_documento' => '0614-333333-333-3',
            'nombre' => 'Cliente de ejemplo SA de CV',
            'nombre_comercial' => 'Cliente de ejemplo',
            'actividad_economica' => 'Comercio al por menor',
            'direccion' => 'Colonia Centro, San Salvador',
            'departamento_codigo' => '06',
            'municipio_codigo' => '04',
            'telefono' => '2222-2222',
            'email' => 'cliente@example.com',
            'tipo_contribuyente' => 'OTRO',
            'activo' => 1,
        ]]);
    }

    if ($action === 'export') {
        $query = clientQueryParts($tenantId, $_GET);
        $statement = db()->prepare(
            'SELECT c.*, d.codigo AS departamento_codigo, m.codigo AS municipio_codigo '
            . $query['from'] . ' WHERE ' . $query['where'] . ' ORDER BY c.nombre, c.id'
        );
        $statement->execute($query['params']);
        $rows = [];
        while ($row = $statement->fetch()) {
            $rows[] = $row;
        }
        clientCsvDownload('clientes_' . gmdate('Ymd_His') . '.csv', $rows);
    }

    if ($action === 'get') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            jsonResponse(['success' => false, 'message' => 'El cliente solicitado no es válido.'], 400);
        }
        $statement = db()->prepare(
            'SELECT c.*, d.nombre AS departamento_nombre, m.nombre AS municipio_nombre
             FROM clientes c
             INNER JOIN mh_departamentos d ON d.tenant_id = c.tenant_id AND d.id = c.departamento_id
             INNER JOIN mh_municipios m ON m.tenant_id = c.tenant_id AND m.id = c.municipio_id
             WHERE c.tenant_id = :tenant_id AND c.id = :id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $client = $statement->fetch();
        if ($client === false) {
            jsonResponse(['success' => false, 'message' => 'El cliente no existe.'], 404);
        }
        jsonResponse(['success' => true, 'client' => $client]);
    }

    if ($action !== 'list') {
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    }

    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $perPage = 15;
    $offset = ($page - 1) * $perPage;
    $query = clientQueryParts($tenantId, $_GET);

    $statement = db()->prepare('SELECT COUNT(*) AS total ' . $query['from'] . ' WHERE ' . $query['where']);
    $statement->execute($query['params']);
    $total = (int) $statement->fetch()['total'];

    $statement = db()->prepare(
        'SELECT c.id, c.tipo_documento, c.numero_documento, c.nombre, c.nombre_comercial,
                c.telefono, c.email, c.tipo_contribuyente, c.activo,
                d.nombre AS departamento_nombre, m.nombre AS municipio_nombre '
        . $query['from'] . ' WHERE ' . $query['where']
        . " ORDER BY c.nombre, c.id LIMIT {$perPage} OFFSET {$offset}"
    );
    $statement->execute($query['params']);

    jsonResponse([
        'success' => true,
        'items' => $statement->fetchAll(),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ],
    ]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

$action = $_POST['action'] ?? 'save';
requireClientWrite($user);
verifyCsrf();

if ($action === 'delete') {
    if ($user['rol'] !== 'ADMIN') {
        jsonResponse(['success' => false, 'message' => 'Solo un administrador puede eliminar clientes.'], 403);
    }
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'El cliente solicitado no es válido.'], 400);
    }

    try {
        $statement = db()->prepare('DELETE FROM clientes WHERE tenant_id = :tenant_id AND id = :id');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'El cliente no existe.'], 404);
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            jsonResponse([
                'success' => false,
                'message' => 'No puede eliminar el cliente porque está relacionado con documentos. Puede desactivarlo.',
            ], 409);
        }
        throw $exception;
    }

    jsonResponse(['success' => true, 'message' => 'Cliente eliminado correctamente.']);
}

if ($action === 'import') {
    $file = $_FILES['archivo'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'message' => 'Seleccione un archivo CSV para importar.'], 422);
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        jsonResponse(['success' => false, 'message' => 'El archivo no puede superar 2 MB.'], 422);
    }
    if (mb_strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION)) !== 'csv' || !is_uploaded_file($file['tmp_name'])) {
        jsonResponse(['success' => false, 'message' => 'El archivo debe tener extensión .csv.'], 422);
    }

    $handle = fopen($file['tmp_name'], 'rb');
    if ($handle === false) {
        jsonResponse(['success' => false, 'message' => 'No fue posible leer el archivo.'], 422);
    }

    $firstLine = fgets($handle);
    if ($firstLine === false) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'El archivo está vacío.'], 422);
    }
    $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
    rewind($handle);
    $headers = fgetcsv($handle, 0, $delimiter);
    if ($headers === false) {
        fclose($handle);
        jsonResponse(['success' => false, 'message' => 'No fue posible leer los encabezados.'], 422);
    }
    $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
    $headers = array_map(static fn($value) => mb_strtolower(trim((string) $value)), $headers);
    $expectedHeaders = clientCsvHeaders();
    $missing = array_diff($expectedHeaders, $headers);
    if ($missing !== []) {
        fclose($handle);
        jsonResponse([
            'success' => false,
            'message' => 'Faltan columnas obligatorias: ' . implode(', ', $missing) . '. Descargue la plantilla.',
        ], 422);
    }

    $options = clientOptions($tenantId);
    $departments = [];
    foreach ($options['departamentos'] as $department) {
        $departments[$department['codigo']] = (int) $department['id'];
    }
    $municipalities = [];
    foreach ($options['municipios'] as $municipality) {
        $municipalities[$municipality['departamento_id']][$municipality['codigo']] = (int) $municipality['id'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    $insert = $pdo->prepare(
        'INSERT INTO clientes (
            tenant_id, tipo_documento, numero_documento, nombre, nombre_comercial,
            actividad_economica, direccion, departamento_id, municipio_id,
            telefono, email, tipo_contribuyente, activo
        ) VALUES (
            :tenant_id, :tipo_documento, :numero_documento, :nombre, :nombre_comercial,
            :actividad_economica, :direccion, :departamento_id, :municipio_id,
            :telefono, :email, :tipo_contribuyente, :activo
        )'
    );
    $exists = $pdo->prepare(
        'SELECT id FROM clientes WHERE tenant_id = :tenant_id
           AND tipo_documento = :tipo_documento AND numero_documento = :numero_documento'
    );
    $errors = [];
    $seen = [];
    $imported = 0;
    $line = 1;

    try {
        while (($values = fgetcsv($handle, 0, $delimiter)) !== false) {
            $line++;
            if (count($values) === 1 && trim((string) $values[0]) === '') {
                continue;
            }
            if ($line > 1001) {
                $errors[] = ['fila' => $line, 'mensajes' => ['El archivo supera el máximo de 1000 clientes.']];
                break;
            }
            if (count($values) !== count($headers)) {
                $errors[] = ['fila' => $line, 'mensajes' => ['La cantidad de columnas no coincide con los encabezados.']];
                continue;
            }

            $raw = array_combine($headers, $values);
            $departmentCode = trim((string) $raw['departamento_codigo']);
            $municipalityCode = trim((string) $raw['municipio_codigo']);
            $departmentId = $departments[$departmentCode] ?? null;
            $raw['departamento_id'] = $departmentId;
            $raw['municipio_id'] = $departmentId !== null
                ? ($municipalities[$departmentId][$municipalityCode] ?? null)
                : null;
            $raw['tipo_contribuyente'] = trim((string) $raw['tipo_contribuyente']) !== ''
                ? $raw['tipo_contribuyente'] : 'OTRO';
            $activeText = mb_strtolower(trim((string) $raw['activo']));
            $raw['activo'] = in_array($activeText, ['', '1', 'si', 'sí'], true) ? '1' : '0';
            if (!in_array($activeText, ['', '0', '1', 'si', 'sí', 'no'], true)) {
                $errors[] = ['fila' => $line, 'mensajes' => ['La columna activo debe ser 1, 0, SI o NO.']];
                continue;
            }

            [$data, $validationErrors] = validateClientData($pdo, $tenantId, normalizeClientData($raw));
            if ($departmentId === null) {
                $validationErrors['departamento_id'] = 'El código de departamento no existe o está inactivo.';
            } elseif ($data['municipio_id'] === null) {
                $validationErrors['municipio_id'] = 'El código de municipio no existe para ese departamento o está inactivo.';
            }

            if ($data['numero_documento'] !== null) {
                $documentKey = $data['tipo_documento'] . '|' . $data['numero_documento'];
                if (isset($seen[$documentKey])) {
                    $validationErrors['numero_documento'] = 'El documento se repite en la fila ' . $seen[$documentKey] . ' del archivo.';
                }
                $seen[$documentKey] = $line;
            }

            if ($validationErrors === [] && $data['numero_documento'] !== null) {
                $exists->execute([
                    'tenant_id' => $tenantId,
                    'tipo_documento' => $data['tipo_documento'],
                    'numero_documento' => $data['numero_documento'],
                ]);
                if ($exists->fetch() !== false) {
                    $validationErrors['numero_documento'] = 'El documento ya está registrado para esta empresa.';
                }
            }

            if ($validationErrors !== []) {
                $errors[] = ['fila' => $line, 'mensajes' => array_values($validationErrors)];
                continue;
            }

            $insert->execute([
                'tenant_id' => $tenantId,
                'tipo_documento' => $data['tipo_documento'],
                'numero_documento' => $data['numero_documento'],
                'nombre' => $data['nombre'],
                'nombre_comercial' => $data['nombre_comercial'],
                'actividad_economica' => $data['actividad_economica'],
                'direccion' => $data['direccion'],
                'departamento_id' => $data['departamento_id'],
                'municipio_id' => $data['municipio_id'],
                'telefono' => $data['telefono'],
                'email' => $data['email'],
                'tipo_contribuyente' => $data['tipo_contribuyente'],
                'activo' => $data['activo'],
            ]);
            $imported++;
        }
        fclose($handle);

        if ($errors !== []) {
            $pdo->rollBack();
            jsonResponse([
                'success' => false,
                'message' => 'La importación no se aplicó. Corrija las filas indicadas e inténtelo nuevamente.',
                'importados' => 0,
                'errores' => array_slice($errors, 0, 50),
                'errores_omitidos' => max(0, count($errors) - 50),
            ], 422);
        }
        if ($imported === 0) {
            $pdo->rollBack();
            jsonResponse(['success' => false, 'message' => 'El archivo no contiene clientes para importar.'], 422);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    jsonResponse([
        'success' => true,
        'message' => 'Se importaron ' . $imported . ' clientes correctamente.',
        'importados' => $imported,
    ]);
}

if ($action !== 'save') {
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
}

$data = normalizeClientData($_POST);
[$data, $errors] = validateClientData(db(), $tenantId, $data);
if ($errors !== []) {
    jsonResponse(['success' => false, 'message' => 'Revise los campos indicados.', 'errors' => $errors], 422);
}

$id = filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$params = [
    'tenant_id' => $tenantId,
    'tipo_documento' => $data['tipo_documento'],
    'numero_documento' => $data['numero_documento'],
    'nombre' => $data['nombre'],
    'nombre_comercial' => $data['nombre_comercial'],
    'actividad_economica' => $data['actividad_economica'],
    'direccion' => $data['direccion'],
    'departamento_id' => $data['departamento_id'],
    'municipio_id' => $data['municipio_id'],
    'telefono' => $data['telefono'],
    'email' => $data['email'],
    'tipo_contribuyente' => $data['tipo_contribuyente'],
    'activo' => $data['activo'],
];

try {
    if ($id !== false && $id !== null) {
        $params['id'] = $id;
        $statement = db()->prepare(
            'UPDATE clientes SET
                tipo_documento = :tipo_documento, numero_documento = :numero_documento,
                nombre = :nombre, nombre_comercial = :nombre_comercial,
                actividad_economica = :actividad_economica, direccion = :direccion,
                departamento_id = :departamento_id, municipio_id = :municipio_id,
                telefono = :telefono, email = :email,
                tipo_contribuyente = :tipo_contribuyente, activo = :activo
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $statement->execute($params);
        if ($statement->rowCount() === 0) {
            $check = db()->prepare('SELECT id FROM clientes WHERE tenant_id = :tenant_id AND id = :id');
            $check->execute(['tenant_id' => $tenantId, 'id' => $id]);
            if ($check->fetch() === false) {
                jsonResponse(['success' => false, 'message' => 'El cliente no existe.'], 404);
            }
        }
        $message = 'Cliente actualizado correctamente.';
    } else {
        $statement = db()->prepare(
            'INSERT INTO clientes (
                tenant_id, tipo_documento, numero_documento, nombre, nombre_comercial,
                actividad_economica, direccion, departamento_id, municipio_id,
                telefono, email, tipo_contribuyente, activo
            ) VALUES (
                :tenant_id, :tipo_documento, :numero_documento, :nombre, :nombre_comercial,
                :actividad_economica, :direccion, :departamento_id, :municipio_id,
                :telefono, :email, :tipo_contribuyente, :activo
            )'
        );
        $statement->execute($params);
        $message = 'Cliente creado correctamente.';
    }
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        jsonResponse([
            'success' => false,
            'message' => 'Ya existe un cliente con ese tipo y número de documento.',
            'errors' => ['numero_documento' => 'El documento ya está registrado para esta empresa.'],
        ], 409);
    }
    throw $exception;
}

jsonResponse(['success' => true, 'message' => $message]);
