<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/catalogos.php';

$user = currentUser();
if ($user === null) {
    jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
}

$tenantId = (int) $user['tenant_id'];
$definitions = catalogDefinitions();
$resource = $_REQUEST['resource'] ?? 'tipos-documento';

if (!is_string($resource) || !isset($definitions[$resource])) {
    jsonResponse(['success' => false, 'message' => 'El catálogo solicitado no es válido.'], 400);
}

$definition = $definitions[$resource];
$table = $definition['table'];
$isMunicipality = isset($definition['municipality']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requireCatalogAdmin(array $user): void
{
    if ($user['rol'] !== 'ADMIN') {
        jsonResponse([
            'success' => false,
            'message' => 'Solo un administrador puede modificar los catálogos.',
        ], 403);
    }
}

function catalogDepartments(int $tenantId): array
{
    $statement = db()->prepare(
        'SELECT id, codigo, nombre FROM mh_departamentos
         WHERE tenant_id = :tenant_id AND activo = 1 ORDER BY orden, nombre'
    );
    $statement->execute(['tenant_id' => $tenantId]);
    return $statement->fetchAll();
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'get') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            jsonResponse(['success' => false, 'message' => 'El registro solicitado no es válido.'], 400);
        }

        $sql = $isMunicipality
            ? "SELECT m.id, m.codigo, m.nombre, m.orden, m.activo, m.departamento_id,
                      d.nombre AS departamento_nombre, d.codigo AS departamento_codigo
               FROM {$table} m
               INNER JOIN mh_departamentos d ON d.tenant_id = m.tenant_id AND d.id = m.departamento_id
               WHERE m.tenant_id = :tenant_id AND m.id = :id"
            : "SELECT id, codigo, nombre, orden, activo FROM {$table}
               WHERE tenant_id = :tenant_id AND id = :id";
        $statement = db()->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $item = $statement->fetch();

        if ($item === false) {
            jsonResponse(['success' => false, 'message' => 'El registro no existe.'], 404);
        }

        jsonResponse(['success' => true, 'item' => $item]);
    }

    if ($action !== 'list') {
        jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
    }

    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
    $search = trim((string) ($_GET['search'] ?? ''));
    $search = mb_substr($search, 0, 100);
    $status = $_GET['status'] ?? '';
    $departmentId = filter_var($_GET['departamento_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $perPage = 15;
    $offset = ($page - 1) * $perPage;

    $from = $isMunicipality
        ? "FROM {$table} m INNER JOIN mh_departamentos d ON d.tenant_id = m.tenant_id AND d.id = m.departamento_id"
        : "FROM {$table} m";
    $where = ['m.tenant_id = :tenant_id'];
    $params = ['tenant_id' => $tenantId];

    if ($search !== '') {
        $searchColumns = ['m.codigo', 'm.nombre'];
        if ($isMunicipality) {
            $searchColumns[] = 'd.nombre';
        }
        $searchCondition = smartSearchCondition($searchColumns, $search, 'catalog_search_', $params);
        if ($searchCondition !== null) {
            $where[] = $searchCondition;
        }
    }

    if ($status === '0' || $status === '1') {
        $where[] = 'm.activo = :status';
        $params['status'] = (int) $status;
    }
    if ($isMunicipality && $departmentId !== false && $departmentId !== null) {
        $where[] = 'm.departamento_id = :department_id';
        $params['department_id'] = $departmentId;
    }

    $whereSql = implode(' AND ', $where);
    $statement = db()->prepare("SELECT COUNT(*) AS total {$from} WHERE {$whereSql}");
    $statement->execute($params);
    $total = (int) $statement->fetch()['total'];

    $select = $isMunicipality
        ? 'm.id, m.codigo, m.nombre, m.orden, m.activo, m.departamento_id,
           d.nombre AS departamento_nombre, d.codigo AS departamento_codigo'
        : 'm.id, m.codigo, m.nombre, m.orden, m.activo';
    $statement = db()->prepare(
        "SELECT {$select} {$from} WHERE {$whereSql}
         ORDER BY m.orden, m.nombre, m.id LIMIT {$perPage} OFFSET {$offset}"
    );
    $statement->execute($params);

    $response = [
        'success' => true,
        'catalog' => [
            'resource' => $resource,
            'label' => $definition['label'],
            'singular' => $definition['singular'],
            'code_length' => $definition['code_length'],
            'municipality' => $isMunicipality,
        ],
        'items' => $statement->fetchAll(),
        'pagination' => [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ],
    ];

    if ($isMunicipality) {
        $response['departamentos'] = catalogDepartments($tenantId);
    }

    jsonResponse($response);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}

requireCatalogAdmin($user);
verifyCsrf();
$action = $_POST['action'] ?? 'save';
$id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

if ($action === 'delete') {
    if ($id === false || $id === null) {
        jsonResponse(['success' => false, 'message' => 'El registro solicitado no es válido.'], 400);
    }

    if ($resource === 'departamentos') {
        $statement = db()->prepare(
            'SELECT COUNT(*) AS total FROM mh_municipios WHERE tenant_id = :tenant_id AND departamento_id = :id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        if ((int) $statement->fetch()['total'] > 0) {
            jsonResponse([
                'success' => false,
                'message' => 'No puede eliminar un departamento que tiene municipios relacionados. Puede desactivarlo.',
            ], 409);
        }
    }

    try {
        $statement = db()->prepare("DELETE FROM {$table} WHERE tenant_id = :tenant_id AND id = :id");
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);

        if ($statement->rowCount() === 0) {
            jsonResponse(['success' => false, 'message' => 'El registro no existe.'], 404);
        }
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            jsonResponse([
                'success' => false,
                'message' => 'No puede eliminar el registro porque está relacionado con otros datos. Puede desactivarlo.',
            ], 409);
        }
        throw $exception;
    }

    jsonResponse(['success' => true, 'message' => $definition['singular'] . ' eliminado correctamente.']);
}

if ($action !== 'save') {
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
}

$code = postString('codigo', 3);
$name = postString('nombre', 120);
$order = filter_var($_POST['orden'] ?? 0, FILTER_VALIDATE_INT);
$active = isset($_POST['activo']) && $_POST['activo'] === '1' ? 1 : 0;
$departmentId = filter_var($_POST['departamento_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$errors = [];

if (!preg_match('/^[0-9]{' . $definition['code_length'] . '}$/', $code)) {
    $errors['codigo'] = 'Debe contener exactamente ' . $definition['code_length'] . ' dígito(s).';
}
if (mb_strlen($name) < 2) {
    $errors['nombre'] = 'Ingrese un nombre de al menos 2 caracteres.';
}
if ($order === false || $order < 0 || $order > 65535) {
    $errors['orden'] = 'Ingrese un orden entre 0 y 65535.';
}
if ($isMunicipality) {
    if ($departmentId === false || $departmentId === null) {
        $errors['departamento_id'] = 'Seleccione un departamento.';
    } else {
        $statement = db()->prepare(
            'SELECT id FROM mh_departamentos WHERE tenant_id = :tenant_id AND id = :id'
        );
        $statement->execute(['tenant_id' => $tenantId, 'id' => $departmentId]);
        if ($statement->fetch() === false) {
            $errors['departamento_id'] = 'El departamento seleccionado no es válido.';
        }
    }
}

if ($errors !== []) {
    jsonResponse([
        'success' => false,
        'message' => 'Revise los campos indicados.',
        'errors' => $errors,
    ], 422);
}

$params = [
    'tenant_id' => $tenantId,
    'codigo' => $code,
    'nombre' => $name,
    'orden' => $order,
    'activo' => $active,
];
if ($isMunicipality) {
    $params['departamento_id'] = $departmentId;
}

try {
    if ($id !== false && $id !== null) {
        $params['id'] = $id;
        $fields = 'codigo = :codigo, nombre = :nombre, orden = :orden, activo = :activo';
        if ($isMunicipality) {
            $fields .= ', departamento_id = :departamento_id';
        }
        $statement = db()->prepare(
            "UPDATE {$table} SET {$fields} WHERE tenant_id = :tenant_id AND id = :id"
        );
        $statement->execute($params);

        if ($statement->rowCount() === 0) {
            $check = db()->prepare("SELECT id FROM {$table} WHERE tenant_id = :tenant_id AND id = :id");
            $check->execute(['tenant_id' => $tenantId, 'id' => $id]);
            if ($check->fetch() === false) {
                jsonResponse(['success' => false, 'message' => 'El registro no existe.'], 404);
            }
        }
        $message = $definition['singular'] . ' actualizado correctamente.';
    } else {
        $fields = 'tenant_id, codigo, nombre, orden, activo';
        $values = ':tenant_id, :codigo, :nombre, :orden, :activo';
        if ($isMunicipality) {
            $fields .= ', departamento_id';
            $values .= ', :departamento_id';
        }
        $statement = db()->prepare("INSERT INTO {$table} ({$fields}) VALUES ({$values})");
        $statement->execute($params);
        $message = $definition['singular'] . ' creado correctamente.';
    }
} catch (PDOException $exception) {
    if ($exception->getCode() === '23000') {
        jsonResponse([
            'success' => false,
            'message' => 'Ya existe un registro con ese código en el ámbito seleccionado.',
            'errors' => ['codigo' => 'El código ya está registrado.'],
        ], 409);
    }
    throw $exception;
}

jsonResponse(['success' => true, 'message' => $message]);
