<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/productos.php';
require_once dirname(__DIR__) . '/lib/documentos.php';
require_once dirname(__DIR__) . '/lib/dte-pdf.php';

$user = currentUser();
if ($user === null) jsonResponse(['success' => false, 'message' => 'Debe iniciar sesión para continuar.'], 401);
$tenantId = (int) $user['tenant_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function requireDocumentWrite(array $user): void
{
    if (!in_array($user['rol'], ['ADMIN', 'VENDEDOR'], true)) {
        jsonResponse(['success' => false, 'message' => 'Su rol solo permite consultar documentos.'], 403);
    }
}

function requestedDocumentId(array $source): int
{
    $id = filter_var($source['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) jsonResponse(['success' => false, 'message' => 'El documento solicitado no es válido.'], 400);
    return $id;
}

if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';
    try {
        if ($action === 'options') jsonResponse(['success' => true] + documentFormOptions($tenantId));
        if ($action === 'get') jsonResponse(['success' => true] + documentDetail($tenantId, requestedDocumentId($_GET)));
        if ($action === 'download') {
            $detail = documentDetail($tenantId, requestedDocumentId($_GET));
            if (($detail['document']['tipo_dte'] ?? '') === 'IN') {
                jsonResponse(['success' => false, 'message' => 'El comprobante interno no genera JSON DTE.'], 409);
            }
            if ($detail['document']['dte'] === null) jsonResponse(['success' => false, 'message' => 'El borrador aún no tiene JSON DTE. Emítalo primero.'], 409);
            $filename = ($detail['document']['numero_control'] ?: 'DTE-' . $detail['document']['id']) . '.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
            header('Cache-Control: no-store');
            echo json_encode($detail['document']['dte'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            exit;
        }
        if ($action === 'pdf') {
            $id = requestedDocumentId($_GET);
            $pdf = buildDocumentPdf($tenantId, $id);
            $document = documentRecord(db(), $tenantId, $id);
            $filename = str_replace('"', '', ($document['numero_control'] ?? ('DTE-' . $id)) . '.pdf');
            $disposition = ($_GET['download'] ?? '') === '1' ? 'attachment' : 'inline';
            header('Content-Type: application/pdf');
            header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
            header('Cache-Control: no-store');
            header('Content-Length: ' . strlen($pdf));
            echo $pdf;
            exit;
        }
        if ($action !== 'list') jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $query = documentQueryParts($tenantId, $_GET);
        $statement = db()->prepare('SELECT COUNT(*) AS total FROM documentos d WHERE ' . $query['where']);
        $statement->execute($query['params']);
        $total = (int) $statement->fetch()['total'];
        $statement = db()->prepare(
            'SELECT d.id, d.tipo_dte, d.numero_control, d.codigo_generacion, d.fecha_emision,
                    d.estado, d.total_pagar, d.sello_recepcion, d.created_at,
                    d.tipo_operacion, d.tipo_contingencia, d.contingencia_evento_id,
                    c.nombre AS cliente_nombre, c.numero_documento AS cliente_documento
             FROM documentos d INNER JOIN clientes c ON c.tenant_id = d.tenant_id AND c.id = d.cliente_id
             WHERE ' . $query['where'] . " ORDER BY d.fecha_emision DESC, d.id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $statement->execute($query['params']);
        jsonResponse([
            'success' => true, 'items' => $statement->fetchAll(),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => max(1, (int) ceil($total / $perPage))],
        ]);
    } catch (RuntimeException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 404);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    jsonResponse(['success' => false, 'message' => 'Método no permitido.'], 405);
}
requireDocumentWrite($user);
verifyCsrf();
$action = $_POST['action'] ?? 'save';
try {
    if ($action === 'save') {
        $id = saveDocument($tenantId, (int) $user['id'], $_POST);
        jsonResponse(['success' => true, 'message' => 'Borrador guardado correctamente.', 'id' => $id]);
    }
    $id = requestedDocumentId($_POST);
    if ($action === 'emit') {
        $contingency = [];
        if (($_POST['contingencia'] ?? '') === '1') {
            $contingency = [
                'enabled' => true,
                'tipo_contingencia' => $_POST['tipo_contingencia'] ?? null,
                'motivo' => $_POST['motivo_contin'] ?? '',
            ];
        }
        $detail = emitDocument($tenantId, $user, $id, $contingency);
        $isInternal = ($detail['document']['tipo_dte'] ?? '') === 'IN';
        $message = $isInternal
            ? 'Comprobante interno emitido. No se transmite al MH ni tiene validez fiscal.'
            : ($contingency === []
                ? 'Documento emitido y transmitido en modo simulación.'
                : 'Documento emitido en contingencia. Declare el evento de contingencia para transmitirlo al MH.');
        $correo = $isInternal ? null : ($detail['correos'][0] ?? null);
        if (is_array($correo)) {
            $message .= match ($correo['estado']) {
                'ENVIADO' => ' Se envió por correo a ' . $correo['destinatario'] . '.',
                'SIMULADO' => ' El correo quedó preparado en modo simulación.',
                'ERROR' => ' No se pudo enviar por correo; puede reintentarlo desde el detalle.',
                'OMITIDO' => ' El cliente no tiene correo electrónico registrado.',
                default => '',
            };
        }
        jsonResponse(['success' => true, 'message' => $message, 'id' => $id, 'estado' => $detail['document']['estado'], 'correo' => $correo]);
    }
    if ($action === 'delete') {
        deleteDraftDocument($tenantId, $id);
        jsonResponse(['success' => true, 'message' => 'Borrador eliminado correctamente.']);
    }
    if ($action === 'email') {
        require_once dirname(__DIR__) . '/lib/correo.php';
        $document = documentRecord(db(), $tenantId, $id);
        if ($document !== null && ($document['tipo_dte'] ?? '') === 'IN') {
            jsonResponse(['success' => false, 'message' => 'El modo interno no envía correo fiscal; imprima el comprobante o su ticket.'], 409);
        }
        $result = enviarCorreoDocumento($tenantId, $id, (int) $user['id']);
        $success = !in_array($result['estado'], ['ERROR', 'OMITIDO'], true);
        $status = $result['estado'] === 'OMITIDO' ? 422 : ($result['estado'] === 'ERROR' ? 502 : 200);
        jsonResponse(['success' => $success, 'message' => $result['message'], 'correo' => $result], $status);
    }
    jsonResponse(['success' => false, 'message' => 'Acción no válida.'], 400);
} catch (DocumentValidationException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->errors], 422);
} catch (RuntimeException $exception) {
    jsonResponse(['success' => false, 'message' => $exception->getMessage()], 409);
}
