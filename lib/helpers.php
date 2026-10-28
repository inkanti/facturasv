<?php

declare(strict_types=1);

function appConfig(?string $path = null): mixed
{
    $config = $GLOBALS['facturasv_config'] ?? [];

    if ($path === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    $base = (string) appConfig('app.url');

    if ($base === '' && PHP_SAPI !== 'cli') {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $base = preg_replace('#/(?:views|api)/[^/]+$#', '', $script) ?? '';
        if (basename($script) === 'index.php') {
            $base = rtrim(dirname($script), '/.');
        }
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** URL de un asset con versión por fecha de modificación para evitar caché vieja. */
function assetUrl(string $path): string
{
    $relative = ltrim($path, '/');
    $absolute = dirname(__DIR__) . '/' . $relative;
    $version = is_file($absolute) ? (string) filemtime($absolute) : '';
    return url($relative) . ($version !== '' ? '?v=' . $version : '');
}

/**
 * Construye una condición de búsqueda "inteligente": cada palabra debe
 * aparecer en al menos una de las columnas, en cualquier orden. La collation
 * utf8mb4_unicode_ci ya ignora mayúsculas y acentos.
 *
 * @param array<int,string> $columns Expresiones SQL de columnas o REPLACE(...)
 * @param array<string,mixed> $params Se completan los parámetros nombrados
 */
function smartSearchCondition(array $columns, string $search, string $prefix, array &$params, int $maxTerms = 6): ?string
{
    $search = trim($search);
    if ($search === '' || $columns === []) {
        return null;
    }
    $search = mb_substr($search, 0, 100);
    $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = array_slice($terms, 0, $maxTerms);
    if ($terms === []) {
        return null;
    }
    $groups = [];
    foreach ($terms as $termIndex => $term) {
        $value = '%' . strtr($term, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
        $likes = [];
        foreach ($columns as $columnIndex => $column) {
            $param = $prefix . $termIndex . '_' . $columnIndex;
            $likes[] = $column . ' LIKE :' . $param;
            $params[$param] = $value;
        }
        $groups[] = '(' . implode(' OR ', $likes) . ')';
    }
    return '(' . implode(' AND ', $groups) . ')';
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $provided = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored = $_SESSION['csrf_token'] ?? '';

    if (!is_string($provided) || !is_string($stored) || $stored === '' || !hash_equals($stored, $provided)) {
        jsonResponse([
            'success' => false,
            'message' => 'La sesión de seguridad expiró. Recargue la página e inténtelo nuevamente.',
        ], 403);
    }
}

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function requestIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === 443);
}

function validNit(string $nit): bool
{
    return preg_match('/^[0-9]{4}-[0-9]{6}-[0-9]{3}-[0-9]$/', $nit) === 1;
}

function postString(string $key, int $maxLength = 255): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);
    return mb_substr($value, 0, $maxLength);
}
