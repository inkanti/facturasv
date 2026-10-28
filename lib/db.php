<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = appConfig('db');
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $config['host'],
        $config['port'],
        $config['name'],
        $config['charset']
    );

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $exception) {
        error_log('FacturaSV: error de conexión a la base de datos. ' . $exception->getMessage());

        if (str_contains(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), '/api/')) {
            jsonResponse(['success' => false, 'message' => 'No fue posible conectar con la base de datos.'], 503);
        }

        http_response_code(503);
        exit('No fue posible conectar con la base de datos. Revise la configuración e inténtelo nuevamente.');
    }

    return $pdo;
}
