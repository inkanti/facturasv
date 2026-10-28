<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/inventario.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}

$tenantId = (int) $user['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requireProductWrite(array $user): void
{
    if (!in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true)) {
        jsonResponse([
            'success' => false,
            'message' => 'Su rol solo permite consultar productos y servicios.',
        ], 403);
    }
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'options') {
        jsonResponse(['success' => true] + productOptions($tenantId));
    }

    if ($action === 'get') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            jsonResponse(['success' => false, 'message' => 'El producto solicitado no es válido.'], 400);
        }
        $statement = db()->prepare(
            'SELECT p.id, p.codigo, p.codigo_barras, p.descripcion, p.tipo, p.unidad_medida_id, p.precio_unitario,
                    p.tratamiento_iva, p.control_stock, p.stock AS stock_base,
                    ' . inventarioStockExpression('p') . ' AS stock, p.activo,
                    u.codigo AS unidad_codigo, u.nombre AS unidad_nombre
             FROM productos p
             INNER JOIN mh_unidades_medida u
                ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
             WHERE p.tenant_id = :tenant_id AND p.id = :id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $product = $statement->fetch();
        if ($product === false) {
            jsonResponse(['success' => false, 'message' => 'El producto o servicio no existe.'], 404);
        }
        jsonResponse(['success' => true, 'product' => $product]);
    }

    if ($action === 'barcode') {
        $barcode = mb_strtoupper(trim((string) ($_GET['codigo'] ?? '')));
        if ($barcode === '') {
            jsonResponse(['success' => false, 'message' => 'Ingrese el código de barras a buscar.'], 400);
        }
        $statement = db()->prepare(
            'SELECT p.id, p.codigo, p.codigo_barras, p.descripcion, p.tipo, p.precio_unitario,
                    p.tratamiento_iva, p.control_stock, p.stock AS stock_base,
                    ' . inventarioStockExpression('p') . ' AS stock, p.activo,
                    u.codigo AS unidad_codigo, u.nombre AS unidad_nombre
             FROM productos p
             INNER JOIN mh_unidades_medida u
                ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
             WHERE p.tenant_id = :tenant_id AND p.codigo_barras = :codigo AND p.activo = 1
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'codigo' => $barcode]);
        $product = $statement->fetch();
        if ($product === false) {
            jsonResponse(['success' => false, 'message' => 'No hay un producto activo con ese código de barras.'], 404);
        }
        jsonResponse(['success' => true, 'product' => $product]);
    }

    if ($action !== 'list') {
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    }

    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $perPage = 15;
    $offset = ($page - 1) * $perPage;
    $query = productQueryParts($tenantId, $_GET);

    $statement = db()->prepare('SELECT COUNT(*) AS total FROM productos p WHERE ' . $query['where']);
    $statement->execute($query['params']);
    $total = (int) $statement->fetch()['total'];

    $statement = db()->prepare(
        'SELECT p.id, p.codigo, p.codigo_barras, p.descripcion, p.tipo, p.precio_unitario,
                p.tratamiento_iva, p.control_stock, p.stock AS stock_base,
                ' . inventarioStockExpression('p') . ' AS stock, p.activo,
                u.codigo AS unidad_codigo, u.nombre AS unidad_nombre
         FROM productos p
         INNER JOIN mh_unidades_medida u
            ON u.tenant_id = p.tenant_id AND u.id = p.unidad_medida_id
         WHERE ' . $query['where']
        . " ORDER BY p.descripcion, p.codigo, p.id LIMIT {$perPage} OFFSET {$offset}"
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
requireProductWrite($user);
verifyCsrf();

if ($action === 'delete') {
    if ($user['rol'] !== 'ADMIN') {
        jsonResponse(['success' => false, 'message' => 'Solo un administrador puede eliminar productos y servicios.'], 403);
    }
    $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'El producto solicitado no es válido.'], 400);
    }

    try {
        $statement = db()->prepare('DELETE FROM productos WHERE tenant_id = :tenant_id AND id = :id');
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'El producto o servicio no existe.'], 404);
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            jsonResponse([
                'success' => false,
                'message' => 'No puede eliminarlo porque está relacionado con documentos o movimientos de inventario. Puede desactivarlo.',
            ], 409);
        }
        throw $exception;
    }

    jsonResponse(['success' => true, 'message' => 'Producto o servicio eliminado correctamente.']);
}

if ($action !== 'save') {
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
}

$requestedId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$existingProduct = null;
if ($requestedId !== false && $requestedId !== null) {
    $statement = db()->prepare(
        'SELECT id, control_stock, stock FROM productos WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
    );
    $statement->execute(['tenant_id' => $tenantId, 'id' => (int) $requestedId]);
    $existingProduct = $statement->fetch();
    if ($existingProduct === false) {
        jsonResponse(['success' => false, 'message' => 'El producto o servicio no existe.'], 404);
    }
    // El stock y el control de existencias solo se definen al crear el producto.
    // Los cambios posteriores se registran como movimientos de inventario.
    $_POST['control_stock'] = (string) (int) $existingProduct['control_stock'];
    $_POST['stock'] = $existingProduct['stock'] === null ? '' : (string) $existingProduct['stock'];
}

[$data, $errors] = validateProductData(db(), $tenantId, normalizeProductData($_POST));
if ($errors !== []) {
    jsonResponse(['success' => false, 'message' => 'Revise los campos indicados.', 'errors' => $errors], 422);
}
if ($existingProduct !== null) {
    $data['control_stock'] = (int) $existingProduct['control_stock'];
    $data['stock'] = $existingProduct['stock'];
}

$id = filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$params = [
    'tenant_id' => $tenantId,
    'codigo' => $data['codigo'],
    'codigo_barras' => $data['codigo_barras'] === '' ? null : $data['codigo_barras'],
    'descripcion' => $data['descripcion'],
    'tipo' => $data['tipo'],
    'unidad_medida_id' => $data['unidad_medida_id'],
    'precio_unitario' => $data['precio_unitario'],
    'tratamiento_iva' => $data['tratamiento_iva'],
    'control_stock' => $data['control_stock'],
    'stock' => $data['stock'],
    'activo' => $data['activo'],
];

try {
    if ($id !== false && $id !== null) {
        $params['id'] = $id;
        $statement = db()->prepare(
            'UPDATE productos SET
                codigo = :codigo, codigo_barras = :codigo_barras, descripcion = :descripcion, tipo = :tipo,
                unidad_medida_id = :unidad_medida_id, precio_unitario = :precio_unitario,
                tratamiento_iva = :tratamiento_iva, control_stock = :control_stock,
                stock = :stock, activo = :activo
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $statement->execute($params);
        if ($statement->rowCount() === 0) {
            $check = db()->prepare('SELECT id FROM productos WHERE tenant_id = :tenant_id AND id = :id');
            $check->execute(['tenant_id' => $tenantId, 'id' => $id]);
            if ($check->fetch() === false) {
                jsonResponse(['success' => false, 'message' => 'El producto o servicio no existe.'], 404);
            }
        }
        $message = 'Producto o servicio actualizado correctamente.';
    } else {
        $statement = db()->prepare(
            'INSERT INTO productos (
                tenant_id, codigo, codigo_barras, descripcion, tipo, unidad_medida_id,
                precio_unitario, tratamiento_iva, control_stock, stock, activo
            ) VALUES (
                :tenant_id, :codigo, :codigo_barras, :descripcion, :tipo, :unidad_medida_id,
                :precio_unitario, :tratamiento_iva, :control_stock, :stock, :activo
            )'
        );
        $statement->execute($params);
        $message = 'Producto o servicio creado correctamente.';
    }
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        $isBarcode = str_contains($exception->getMessage(), 'uq_productos_tenant_codigo_barras')
            || str_contains($exception->getMessage(), 'codigo_barras');
        jsonResponse([
            'success' => false,
            'message' => $isBarcode
                ? 'Ese código de barras ya está asignado a otro producto.'
                : 'Ya existe un producto o servicio con ese código en esta empresa.',
            'errors' => $isBarcode
                ? ['codigo_barras' => 'El código de barras ya está asignado a otro producto.']
                : ['codigo' => 'El código ya está registrado para esta empresa.'],
        ], 409);
    }
    throw $exception;
}

jsonResponse(['success' => true, 'message' => $message]);
