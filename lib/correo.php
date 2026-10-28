<?php

declare(strict_types=1);

require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/dte-pdf.php';

/**
 * Envío del DTE por correo electrónico (módulo 7).
 *
 * El mensaje se arma como MIME multipart con el PDF y el JSON del documento.
 * Transportes disponibles:
 *  - smtp:     conexión directa al servidor SMTP (sin librerías externas).
 *  - mail:     función mail() de PHP.
 *  - simulado: prepara y registra el correo sin enviarlo (desarrollo).
 *
 * Cada intento queda en documento_correos, con el resultado y el usuario.
 */

function mailSettings(array $override = []): array
{
    $defaults = [
        'transport' => 'simulado', 'host' => '', 'port' => 587, 'user' => '', 'pass' => '',
        'encryption' => 'tls', 'from_email' => 'no-responder@facturasv.local',
        'from_name' => 'FacturaSV', 'timeout' => 15, 'reply_to' => '',
    ];
    $configured = appConfig('mail');
    $settings = array_merge($defaults, is_array($configured) ? $configured : [], $override);
    $settings['transport'] = in_array($settings['transport'], ['smtp', 'mail', 'simulado'], true)
        ? $settings['transport'] : 'simulado';
    $settings['encryption'] = in_array($settings['encryption'], ['tls', 'ssl', 'none'], true)
        ? $settings['encryption'] : 'tls';
    $settings['port'] = (int) $settings['port'];
    $settings['timeout'] = max(3, (int) $settings['timeout']);
    return $settings;
}

function correoDireccion(string $email): ?string
{
    $email = trim($email);
    if ($email === '' || preg_match('/[\r\n]/', $email) === 1) return null;
    return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
}

/** Descarta dominios de ejemplo que un servidor SMTP real no puede entregar. */
function correoDireccionEntregable(string $email): ?string
{
    $email = correoDireccion($email);
    if ($email === null) return null;
    $domain = mb_strtolower(substr($email, strrpos($email, '@') + 1));
    foreach (['.local', '.localhost', '.test', '.invalid', '.example'] as $suffix) {
        if (str_ends_with($domain, $suffix)) return null;
    }
    return $email;
}

function correoNombre(string $name): string
{
    $name = trim(preg_replace('/[\r\n]+/', ' ', $name) ?? '');
    return mb_substr($name, 0, 120);
}

function correoAsuntoCodificado(string $subject): string
{
    $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
    return '=?UTF-8?B?' . base64_encode(mb_substr($subject, 0, 200)) . '?=';
}

function correoArchivoSeguro(string $name, string $fallback): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? '';
    return $name === '' ? $fallback : $name;
}

/** Arma las cabeceras y el cuerpo MIME. Devuelve subject ya codificado. */
function correoConstruirMime(array $message): array
{
    $fromEmail = correoDireccion((string) ($message['from_email'] ?? ''));
    if ($fromEmail === null) throw new RuntimeException('La dirección del remitente no es válida.');
    $fromName = correoNombre((string) ($message['from_name'] ?? ''));
    $recipients = [];
    foreach ($message['to'] ?? [] as $recipient) {
        $email = correoDireccion((string) ($recipient['email'] ?? ''));
        if ($email === null) throw new RuntimeException('La dirección del destinatario no es válida.');
        $recipients[] = $recipient;
    }
    if ($recipients === []) throw new RuntimeException('El correo no tiene destinatarios.');

    $boundary = '=_FacturaSV_' . bin2hex(random_bytes(12));
    $alternative = '=_FacturaSV_alt_' . bin2hex(random_bytes(8));
    $domain = substr($fromEmail, strpos($fromEmail, '@') + 1) ?: 'facturasv.local';

    $formatAddress = static function (array $recipient): string {
        $name = correoNombre((string) ($recipient['name'] ?? ''));
        return $name === '' ? $recipient['email'] : correoAsuntoCodificado($name) . ' <' . $recipient['email'] . '>';
    };
    $header = static function (string $name, string $value): string {
        return $name . ': ' . trim(preg_replace('/[\r\n]+/', ' ', $value) ?? '');
    };

    $headers = [
        'Date: ' . date('r'),
        $header('From', $fromName === '' ? $fromEmail : correoAsuntoCodificado($fromName) . ' <' . $fromEmail . '>'),
        $header('To', implode(', ', array_map($formatAddress, $recipients))),
    ];
    if (!empty($message['cc']) && is_array($message['cc'])) {
        $cc = [];
        foreach ($message['cc'] as $recipient) {
            $email = correoDireccion((string) ($recipient['email'] ?? ''));
            if ($email !== null) $cc[] = $email;
        }
        if ($cc !== []) $headers[] = $header('Cc', implode(', ', $cc));
    }
    if (correoDireccion((string) ($message['reply_to'] ?? '')) !== null) {
        $headers[] = $header('Reply-To', (string) $message['reply_to']);
    }
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>';
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

    $chunk = static function (string $text): string {
        return rtrim(chunk_split(base64_encode($text), 76, "\r\n"));
    };

    $body = '--' . $boundary . "\r\n"
        . 'Content-Type: multipart/alternative; boundary="' . $alternative . '"' . "\r\n\r\n"
        . '--' . $alternative . "\r\n"
        . 'Content-Type: text/plain; charset="UTF-8"' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
        . $chunk((string) ($message['text'] ?? '')) . "\r\n"
        . '--' . $alternative . "\r\n"
        . 'Content-Type: text/html; charset="UTF-8"' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
        . $chunk((string) ($message['html'] ?? '')) . "\r\n"
        . '--' . $alternative . '--' . "\r\n";

    foreach (($message['attachments'] ?? []) as $attachment) {
        $name = correoArchivoSeguro((string) ($attachment['name'] ?? ''), 'adjunto');
        $type = preg_match('#^[a-z]+/[a-z0-9.+-]+$#i', (string) ($attachment['type'] ?? '')) === 1
            ? $attachment['type'] : 'application/octet-stream';
        $body .= '--' . $boundary . "\r\n"
            . 'Content-Type: ' . $type . '; name="' . $name . '"' . "\r\n"
            . 'Content-Transfer-Encoding: base64' . "\r\n"
            . 'Content-Disposition: attachment; filename="' . $name . '"' . "\r\n\r\n"
            . $chunk((string) ($attachment['data'] ?? '')) . "\r\n";
    }
    $body .= '--' . $boundary . "--\r\n";

    return [
        'subject' => correoAsuntoCodificado((string) ($message['subject'] ?? '')),
        'to' => implode(', ', array_map(static fn(array $r): string => (string) $r['email'], $recipients)),
        'headers' => $headers,
        'body' => $body,
    ];
}

/** Cliente SMTP mínimo: EHLO, STARTTLS/SSL, AUTH LOGIN, MAIL, RCPT, DATA. */
function correoEnviarSmtp(array $message, array $settings): void
{
    $host = trim((string) $settings['host']);
    if ($host === '') throw new RuntimeException('Falta configurar el servidor SMTP (MAIL_HOST).');
    $port = (int) $settings['port'];
    $timeout = (int) $settings['timeout'];
    $encryption = (string) $settings['encryption'];

    $context = stream_context_create(['ssl' => ['SNI_enabled' => true, 'peer_name' => $host]]);
    $remote = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errorNumber, $errorText, $timeout, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        throw new RuntimeException('No fue posible conectar con el servidor de correo (' . $host . ':' . $port . ').');
    }
    stream_set_timeout($socket, $timeout);
    $mime = correoConstruirMime($message);

    $read = static function () use ($socket, $timeout): string {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (preg_match('/^[0-9]{3} /', $line) === 1) break;
        }
        if ($response === '') throw new RuntimeException('El servidor de correo cerró la conexión inesperadamente.');
        return $response;
    };
    $expect = static function (string $response, array $codes, string $context): void {
        $code = (int) substr($response, 0, 3);
        if (!in_array($code, $codes, true)) {
            $detail = mb_substr(trim(preg_replace('/\s+/', ' ', $response) ?? ''), 0, 200);
            throw new RuntimeException('El servidor de correo rechazó ' . $context . ' (código ' . $code . '). ' . $detail);
        }
    };
    $command = static function (string $line, array $codes, string $context) use ($socket, &$read, &$expect): string {
        fwrite($socket, $line . "\r\n");
        $response = $read();
        $expect($response, $codes, $context);
        return $response;
    };

    $expect($read(), [220], 'la conexión');
    $hostname = gethostname() ?: 'localhost';
    $command('EHLO ' . $hostname, [250], 'EHLO');

    if ($encryption === 'tls') {
        $command('STARTTLS', [220], 'STARTTLS');
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('No fue posible iniciar TLS con el servidor de correo.');
        }
        $command('EHLO ' . $hostname, [250], 'EHLO después de TLS');
    }

    $user = (string) $settings['user'];
    if ($user !== '') {
        $command('AUTH LOGIN', [334], 'AUTH LOGIN');
        $command(base64_encode($user), [334], 'el usuario de correo');
        $command(base64_encode((string) $settings['pass']), [235], 'la contraseña de correo');
    }

    $from = (string) $message['from_email'];
    $command('MAIL FROM:<' . $from . '>', [250], 'el remitente');
    foreach (($message['to'] ?? []) as $recipient) {
        $command('RCPT TO:<' . $recipient['email'] . '>', [250, 251], 'el destinatario');
    }
    foreach (($message['cc'] ?? []) as $recipient) {
        $email = correoDireccion((string) ($recipient['email'] ?? ''));
        if ($email !== null) $command('RCPT TO:<' . $email . '>', [250, 251], 'la copia');
    }
    $command('DATA', [354], 'el inicio del mensaje');

    $data = 'Subject: ' . $mime['subject'] . "\r\n" . implode("\r\n", $mime['headers'])
        . "\r\n\r\n" . $mime['body'];
    $data = preg_replace('/(?m)^\./', '..', $data) ?? $data;
    fwrite($socket, rtrim($data, "\r\n") . "\r\n.\r\n");
    $expect($read(), [250], 'el mensaje');

    fwrite($socket, "QUIT\r\n");
    @fclose($socket);
}

/** Despacha un mensaje ya construido. Devuelve ENVIADO o SIMULADO. */
function correoDespacharMensaje(array $message, array $settings): string
{
    if ($settings['transport'] === 'smtp') {
        correoEnviarSmtp($message, $settings);
        return 'ENVIADO';
    }
    if ($settings['transport'] === 'mail') {
        $mime = correoConstruirMime($message);
        $headers = implode("\r\n", $mime['headers']);
        if (!@mail($mime['to'], $mime['subject'], $mime['body'], $headers)) {
            throw new RuntimeException('La función mail() no pudo entregar el mensaje.');
        }
        return 'ENVIADO';
    }
    return 'SIMULADO';
}

function correoRegistrar(int $tenantId, int $documentId, ?int $userId, string $destinatario, ?string $copia, string $asunto, string $transporte, string $estado, ?string $error, ?string $adjuntos): void
{
    $statement = db()->prepare(
        'INSERT INTO documento_correos
            (tenant_id, documento_id, user_id, destinatario, copia, asunto, transporte, estado, error, adjuntos)
         VALUES
            (:tenant_id, :documento_id, :user_id, :destinatario, :copia, :asunto, :transporte, :estado, :error, :adjuntos)'
    );
    $statement->execute([
        'tenant_id' => $tenantId, 'documento_id' => $documentId, 'user_id' => $userId,
        'destinatario' => mb_substr($destinatario, 0, 254), 'copia' => $copia === null ? null : mb_substr($copia, 0, 254),
        'asunto' => mb_substr($asunto, 0, 255), 'transporte' => mb_substr($transporte, 0, 20),
        'estado' => $estado, 'error' => $error === null ? null : mb_substr($error, 0, 500),
        'adjuntos' => $adjuntos === null ? null : mb_substr($adjuntos, 0, 255),
    ]);
}

function correosDocumento(int $tenantId, int $documentId, int $limit = 8): array
{
    $limit = max(1, min(50, $limit));
    $statement = db()->prepare(
        'SELECT c.id, c.destinatario, c.copia, c.asunto, c.transporte, c.estado, c.error, c.adjuntos,
                c.created_at, u.nombre AS usuario
         FROM documento_correos c LEFT JOIN users u ON u.id = c.user_id
         WHERE c.tenant_id = :tenant_id AND c.documento_id = :documento_id
         ORDER BY c.id DESC LIMIT ' . $limit
    );
    $statement->execute(['tenant_id' => $tenantId, 'documento_id' => $documentId]);
    return $statement->fetchAll();
}

/**
 * Genera el PDF/JSON y envía el DTE al correo del cliente.
 *
 * @return array{estado:string,message:string,destinatario?:string,asunto?:string}
 */
function enviarCorreoDocumento(int $tenantId, int $documentId, ?int $userId = null, array $options = []): array
{
    $pdo = db();
    $document = documentRecord($pdo, $tenantId, $documentId);
    if ($document === null) throw new RuntimeException('El documento no existe.');
    if (empty($document['numero_control']) || empty($document['codigo_generacion'])) {
        throw new RuntimeException('Solo puede enviarse un documento emitido.');
    }
    if (!in_array($document['estado'], ['PROCESADA', 'INVALIDADA'], true)) {
        throw new RuntimeException('El documento debe estar procesado para enviarse por correo.');
    }
    $statement = $pdo->prepare('SELECT email, nombre FROM clientes WHERE tenant_id = :tenant_id AND id = :id');
    $statement->execute(['tenant_id' => $tenantId, 'id' => $document['cliente_id']]);
    $client = $statement->fetch();
    if ($client === false) throw new RuntimeException('El cliente del documento ya no existe.');

    $settings = mailSettings(is_array($options['mail'] ?? null) ? $options['mail'] : []);
    $recipient = correoDireccion((string) ($client['email'] ?? ''));
    $copia = correoDireccion((string) ($options['copia'] ?? ''));
    $asuntoBase = documentTypeDefinitions()[$document['tipo_dte']]['nombre'] ?? 'Documento';
    $asunto = ($document['estado'] === 'INVALIDADA' ? 'Documento invalidado · ' : '') . $asuntoBase
        . ' ' . $document['numero_control'] . ' · ' . $document['cliente_nombre'];

    if ($recipient === null) {
        correoRegistrar($tenantId, $documentId, $userId, 'sin correo', $copia, $asunto, $settings['transport'], 'OMITIDO', 'El cliente no tiene un correo electrónico válido registrado.', null);
        return ['estado' => 'OMITIDO', 'message' => 'El cliente no tiene un correo electrónico válido registrado.'];
    }

    $tenant = tenantDocumentContext($pdo, $tenantId);
    $pdf = buildDocumentPdf($tenantId, $documentId);
    $json = (string) ($document['dte_json'] ?? '');
    $attachments = [[
        'name' => correoArchivoSeguro((string) $document['numero_control'], 'DTE') . '.pdf',
        'type' => 'application/pdf', 'data' => $pdf,
    ]];
    $attachedNames = $attachments[0]['name'];
    if ($json !== '') {
        $attachments[] = [
            'name' => correoArchivoSeguro((string) $document['numero_control'], 'DTE') . '.json',
            'type' => 'application/json', 'data' => $json,
        ];
        $attachedNames .= ', ' . $attachments[1]['name'];
    }

    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $money = static fn(float $value): string => '$ ' . number_format($value, 2, '.', ',');
    $isInvalid = $document['estado'] === 'INVALIDADA';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;color:#1f2937;font-size:14px;line-height:1.5">'
        . '<h2 style="color:#1e3a8a;margin:0 0 12px">' . $escape((string) $tenant['nombre_comercial']) . '</h2>'
        . '<p>Estimado(a) ' . $escape((string) $client['nombre']) . ',</p>'
        . '<p>' . ($isInvalid ? 'Le informamos que el siguiente documento fue <strong>invalidado</strong>. Adjuntamos su representación gráfica y el JSON para su control.' : 'Adjuntamos su ' . $escape(mb_strtolower($asuntoBase)) . ' en formato PDF y el JSON del documento tributario electrónico.') . '</p>'
        . '<table cellpadding="6" cellspacing="0" style="border-collapse:collapse;margin:14px 0;font-size:13px">'
        . '<tr><td style="background:#eef0f4;font-weight:bold">Número de control</td><td>' . $escape((string) $document['numero_control']) . '</td></tr>'
        . '<tr><td style="background:#eef0f4;font-weight:bold">Código de generación</td><td>' . $escape((string) $document['codigo_generacion']) . '</td></tr>'
        . '<tr><td style="background:#eef0f4;font-weight:bold">Fecha de emisión</td><td>' . $escape((string) $document['fecha_emision']) . '</td></tr>'
        . '<tr><td style="background:#eef0f4;font-weight:bold">Sello de recepción</td><td>' . $escape((string) ($document['sello_recepcion'] ?? 'No recibido')) . '</td></tr>'
        . '<tr><td style="background:#eef0f4;font-weight:bold">Total a pagar</td><td><strong>' . $money((float) $document['total_pagar']) . '</strong></td></tr>'
        . '</table>'
        . '<p style="font-size:12px;color:#6b7280">Este mensaje fue generado automáticamente por FacturaSV. El documento adjunto corresponde a una representación gráfica del DTE emitido en modo SIMULACIÓN y no tiene validez fiscal.</p>'
        . '</div>';
    $text = ($isInvalid ? "Documento invalidado\n\n" : '')
        . 'Estimado(a) ' . $client['nombre'] . ",\n\n"
        . 'Adjuntamos su ' . mb_strtolower($asuntoBase) . " en PDF y el JSON del DTE.\n\n"
        . 'Número de control: ' . $document['numero_control'] . "\n"
        . 'Código de generación: ' . $document['codigo_generacion'] . "\n"
        . 'Fecha de emisión: ' . $document['fecha_emision'] . "\n"
        . 'Sello de recepción: ' . ($document['sello_recepcion'] ?? 'No recibido') . "\n"
        . 'Total a pagar: ' . $money((float) $document['total_pagar']) . "\n\n"
        . "FacturaSV · representación gráfica en modo SIMULACIÓN, sin validez fiscal.\n";

    $replyTo = correoDireccion((string) ($settings['reply_to'] ?? ''))
        ?? correoDireccionEntregable((string) ($tenant['email'] ?? ''))
        ?? '';
    $message = [
        'from_email' => $settings['from_email'],
        'from_name' => $settings['from_name'],
        'to' => [['email' => $recipient, 'name' => (string) $client['nombre']]],
        'cc' => $copia === null ? [] : [['email' => $copia, 'name' => '']],
        'reply_to' => $replyTo,
        'subject' => $asunto,
        'html' => $html,
        'text' => $text,
        'attachments' => $attachments,
    ];

    $estado = 'ENVIADO';
    $error = null;
    try {
        $estado = correoDespacharMensaje($message, $settings);
    } catch (Throwable $exception) {
        $estado = 'ERROR';
        $error = $exception->getMessage();
    }

    correoRegistrar($tenantId, $documentId, $userId, $recipient, $copia, $asunto, $settings['transport'], $estado, $error, $attachedNames);

    if ($estado === 'ERROR') {
        return ['estado' => 'ERROR', 'message' => 'No fue posible enviar el correo: ' . $error, 'destinatario' => $recipient, 'asunto' => $asunto];
    }
    $label = $estado === 'SIMULADO'
        ? 'Correo preparado en modo simulación para ' . $recipient . ' (no se envió).'
        : 'Documento enviado a ' . $recipient . '.';
    return ['estado' => $estado, 'message' => $label, 'destinatario' => $recipient, 'asunto' => $asunto];
}
