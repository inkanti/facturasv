<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/bootstrap.php';
require_once dirname(__DIR__) . '/lib/correo.php';

/**
 * Prueba el cliente SMTP real contra un servidor local de un solo uso.
 * No envía correo a Internet: el servidor de prueba está en 127.0.0.1.
 */

$assertions = 0;
$process = null;
$files = [];
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

function checkSmtp(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) throw new RuntimeException('FALLO SMTP: ' . $message);
    $assertions++;
    echo "OK: {$message}\n";
}

function waitForFile(string $path, int $milliseconds = 8000): bool
{
    $limit = microtime(true) + ($milliseconds / 1000);
    while (microtime(true) < $limit) {
        clearstatcache(true, $path);
        if (is_file($path) && filesize($path) > 0) return true;
        usleep(100000);
    }
    return false;
}

try {
    $started = false;
    $port = 0;
    for ($attempt = 0; $attempt < 5 && !$started; $attempt++) {
        $port = random_int(20000, 45000);
        $suffix = getmypid() . '_' . $attempt;
        $output = dirname(__DIR__) . '/__smtp_mensaje_' . $suffix . '.eml';
        $log = dirname(__DIR__) . '/__smtp_log_' . $suffix . '.txt';
        $files = [$output, $log];
        @unlink($output);
        @unlink($log);
        $command = [PHP_BINARY, __DIR__ . '/smtp-servidor.php', '127.0.0.1:' . $port, $output, $log];
        $process = proc_open($command, [
            0 => ['file', $nullDevice, 'r'],
            1 => ['file', $nullDevice, 'w'],
            2 => ['file', $nullDevice, 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            $process = null;
            continue;
        }
        $limit = microtime(true) + 5;
        while (microtime(true) < $limit) {
            clearstatcache(true, $log);
            if (is_file($log) && str_contains((string) file_get_contents($log), 'READY')) { $started = true; break; }
            if (is_file($log) && str_contains((string) file_get_contents($log), 'ERROR')) break;
            usleep(100000);
        }
        if (!$started) {
            proc_terminate($process);
            proc_close($process);
            $process = null;
        }
    }
    checkSmtp($started, 'el servidor SMTP de prueba inicia en 127.0.0.1:' . $port);

    $settings = mailSettings([
        'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => $port, 'user' => 'usuario-prueba',
        'pass' => 'clave-prueba', 'encryption' => 'none', 'timeout' => 10,
        'from_email' => 'no-responder@facturasv.local', 'from_name' => 'FacturaSV',
    ]);
    $message = [
        'from_email' => 'no-responder@facturasv.local', 'from_name' => 'FacturaSV',
        'to' => [['email' => 'cliente@example.com', 'name' => 'Cliente de Prueba']],
        'subject' => 'Factura DTE-01-M001P001-000000000000001 · Empresa Demo',
        'html' => '<p>Adjuntamos su factura.</p>', 'text' => 'Adjuntamos su factura.',
        'attachments' => [[
            'name' => 'DTE-01-M001P001-000000000000001.pdf', 'type' => 'application/pdf',
            'data' => "%PDF-1.4\ncontenido de prueba\n%%EOF",
        ]],
    ];
    correoEnviarSmtp($message, $settings);
    checkSmtp(true, 'el cliente SMTP completa EHLO, AUTH LOGIN, MAIL, RCPT y DATA');

    checkSmtp(waitForFile($files[0]), 'el servidor recibe el mensaje completo');
    $received = (string) file_get_contents($files[0]);
    checkSmtp(str_contains($received, 'Subject: =?UTF-8?B?'), 'el mensaje recibido conserva el asunto codificado');
    checkSmtp(str_contains($received, 'From: =?UTF-8?B?'), 'el mensaje recibido incluye el remitente');
    checkSmtp(str_contains($received, 'To:' ) && str_contains($received, 'cliente@example.com'), 'el mensaje recibido incluye el destinatario');
    checkSmtp(str_contains($received, 'Content-Type: multipart/mixed'), 'el mensaje recibido es multipart/mixed');
    checkSmtp(str_contains($received, 'filename="DTE-01-M001P001-000000000000001.pdf"'), 'el mensaje recibido incluye el PDF adjunto');
    checkSmtp(str_contains($received, 'Content-Type: text/html; charset="UTF-8"'), 'el mensaje recibido incluye el cuerpo HTML');
    $conversation = (string) file_get_contents($files[1]);
    checkSmtp(str_contains($conversation, 'AUTH LOGIN') && str_contains($conversation, '235'), 'el servidor confirma la autenticación');
    checkSmtp(str_contains($conversation, 'C: MAIL FROM:<no-responder@facturasv.local>'), 'el sobre usa el remitente configurado');

    echo "RESULTADO: {$assertions} comprobaciones SMTP correctas.\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    foreach ($files as $file) {
        if (is_file($file)) @unlink($file);
    }
    echo "Servidor y archivos temporales eliminados.\n";
}
