<?php

declare(strict_types=1);

/**
 * Servidor SMTP mínimo para pruebas locales.
 * Uso: php tests/smtp-servidor.php 127.0.0.1:2525 mensaje.eml [conversacion.log]
 *
 * Acepta una sola conexión, guarda el mensaje recibido y termina. No usa TLS.
 */

$address = (string) ($argv[1] ?? '127.0.0.1:2525');
$output = (string) ($argv[2] ?? '');
$log = (string) ($argv[3] ?? '');
if ($output === '') {
    fwrite(STDERR, "Falta la ruta del archivo de salida.\n");
    exit(2);
}

$logLine = static function (string $line) use ($log): void {
    if ($log === '') return;
    file_put_contents($log, $line . "\n", FILE_APPEND);
};

$server = @stream_socket_server('tcp://' . $address, $errorNumber, $errorText);
if ($server === false) {
    $logLine('ERROR ' . $errorNumber . ' ' . $errorText);
    exit(1);
}
$logLine('READY');
$client = @stream_socket_accept($server, 30);
if ($client === false) {
    $logLine('ERROR sin conexión');
    exit(1);
}
stream_set_timeout($client, 20);

$write = static function (string $line) use ($client, $logLine): void {
    fwrite($client, $line . "\r\n");
    $logLine('S: ' . $line);
};
$write('220 smtp-prueba ESMTP listo');

$authStep = 0;
$message = null;
$inData = false;
while (($line = fgets($client, 8192)) !== false) {
    $line = rtrim($line, "\r\n");
    $logLine('C: ' . $line);

    if ($inData) {
        if ($line === '.') {
            file_put_contents($output, $message);
            $write('250 2.0.0 Mensaje aceptado');
            $inData = false;
            $message = null;
            continue;
        }
        $message .= ($message === null ? '' : "\r\n") . preg_replace('/^\.\./', '.', $line);
        continue;
    }

    $command = strtoupper(substr($line, 0, 4));
    if ($authStep === 1) {
        $authStep = 2;
        $write('334 UGFzc3dvcmQ6');
        continue;
    }
    if ($authStep === 2) {
        $authStep = 0;
        $write('235 2.7.0 Autenticación aceptada');
        continue;
    }
    if ($command === 'EHLO') {
        $write('250-smtp-prueba');
        $write('250-AUTH LOGIN PLAIN');
        $write('250 8BITMIME');
    } elseif ($command === 'HELO') {
        $write('250 smtp-prueba');
    } elseif ($command === 'AUTH') {
        $authStep = 1;
        $write('334 VXNlcm5hbWU6');
    } elseif ($command === 'MAIL' || $command === 'RCPT') {
        $write('250 2.1.0 Aceptado');
    } elseif ($command === 'DATA') {
        $inData = true;
        $message = '';
        $write('354 Envíe el mensaje y termine con punto');
    } elseif ($command === 'QUIT') {
        $write('221 2.0.0 Adiós');
        break;
    } elseif ($command === 'RSET' || $command === 'NOOP') {
        $write('250 2.0.0 OK');
    } else {
        $write('502 5.5.2 Comando no implementado');
    }
}

fclose($client);
fclose($server);
exit(0);
