<?php

declare(strict_types=1);

/**
 * Configuración mediante variables de entorno.
 * Los valores locales permiten probar en XAMPP; en producción defina DB_PASS y APP_URL.
 */
$env = static function (string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
};

return (static function (array $config): array {
    // Configuración local opcional del correo: config/correo.local.php.
    // Permite probar el envío real sin definir variables de entorno en Apache.
    // La carpeta config/ está bloqueada desde el navegador por .htaccess.
    $localFile = __DIR__ . '/correo.local.php';
    if (is_file($localFile)) {
        $local = require $localFile;
        if (is_array($local)) {
            $config['mail'] = array_merge($config['mail'], array_intersect_key($local, $config['mail']));
        }
    }
    return $config;
})([
    'app' => [
        'name' => 'FacturaSV',
        'env' => $env('APP_ENV', 'development'),
        'url' => rtrim($env('APP_URL', ''), '/'),
        'session_name' => 'facturasv_session',
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '3306'),
        'name' => $env('DB_NAME', 'facturasv2'),
        'user' => $env('DB_USER', 'root'),
        'pass' => $env('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],
    /*
     * Envío de DTE por correo electrónico (módulo 7).
     * MAIL_TRANSPORT: smtp | mail | simulado.
     * Con "simulado" el sistema prepara y registra el correo sin enviarlo,
     * útil para desarrollo. En producción configure smtp o mail.
     */
    'mail' => [
        'transport' => mb_strtolower($env('MAIL_TRANSPORT', 'simulado')),
        'host' => $env('MAIL_HOST', ''),
        'port' => (int) $env('MAIL_PORT', '587'),
        'user' => $env('MAIL_USER', ''),
        'pass' => $env('MAIL_PASS', ''),
        'encryption' => mb_strtolower($env('MAIL_ENCRYPTION', 'tls')),
        'from_email' => $env('MAIL_FROM', 'no-responder@facturasv.local'),
        'from_name' => $env('MAIL_FROM_NAME', 'FacturaSV'),
        'timeout' => max(3, (int) $env('MAIL_TIMEOUT', '15')),
        'reply_to' => $env('MAIL_REPLY_TO', ''),
    ],
]);
