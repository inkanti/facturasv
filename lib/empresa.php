<?php

declare(strict_types=1);

/**
 * Gestión del logo de la empresa (emisor).
 * El logo se captura al registrar la empresa y se usa en la
 * representación gráfica PDF del DTE (módulo 6).
 */

function logoStorageDir(): string
{
    return dirname(__DIR__) . '/uploads/logos';
}

function tenantLogoAbsolutePath(?string $relativePath): ?string
{
    if (!is_string($relativePath) || $relativePath === '') {
        return null;
    }
    $relativePath = str_replace('\\', '/', $relativePath);
    if (str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath) === 1
        || str_contains($relativePath, '..')) {
        return null;
    }
    $absolute = dirname(__DIR__) . '/' . $relativePath;
    return is_file($absolute) ? $absolute : null;
}

/**
 * Valida el archivo subido, lo normaliza a JPEG con fondo blanco
 * y lo guarda en uploads/logos/. Devuelve la ruta relativa almacenada.
 *
 * @param array $file Entrada de $_FILES con la estructura estándar de PHP.
 * @throws RuntimeException Si el archivo no es un logo válido.
 */
function storeTenantLogo(PDO $pdo, int $tenantId, array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No fue posible subir el logo. Inténtelo nuevamente.');
    }
    if (($file['size'] ?? 0) > 1024 * 1024) {
        throw new RuntimeException('El logo no puede superar 1 MB.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
        throw new RuntimeException('El archivo del logo no es válido.');
    }

    $info = @getimagesize($tmp);
    if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
        throw new RuntimeException('El logo debe ser una imagen PNG o JPG.');
    }
    [$width, $height] = $info;
    if ($width < 40 || $height < 40 || $width > 2000 || $height > 2000) {
        throw new RuntimeException('El logo debe medir entre 40×40 y 2000×2000 píxeles.');
    }
    if (!extension_loaded('gd')) {
        throw new RuntimeException('El servidor no puede procesar imágenes (extensión GD no disponible).');
    }

    $source = $info[2] === IMAGETYPE_PNG
        ? @imagecreatefrompng($tmp)
        : @imagecreatefromjpeg($tmp);
    if ($source === false) {
        throw new RuntimeException('No fue posible leer la imagen del logo.');
    }

    // Fondo blanco para que los PNG con transparencia se vean bien en el PDF.
    $canvas = imagecreatetruecolor($width, $height);
    if ($canvas === false) {
        imagedestroy($source);
        throw new RuntimeException('No fue posible procesar la imagen del logo.');
    }
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
    imagedestroy($source);

    $directory = logoStorageDir();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        imagedestroy($canvas);
        throw new RuntimeException('No fue posible crear la carpeta de logos.');
    }

    $relative = 'uploads/logos/tenant_' . $tenantId . '_' . bin2hex(random_bytes(4)) . '.jpg';
    $target = dirname(__DIR__) . '/' . $relative;
    if (!imagejpeg($canvas, $target, 88)) {
        imagedestroy($canvas);
        throw new RuntimeException('No fue posible guardar el logo.');
    }
    imagedestroy($canvas);

    $statement = $pdo->prepare('SELECT logo_path FROM tenants WHERE id = :id');
    $statement->execute(['id' => $tenantId]);
    $previous = $statement->fetchColumn();

    $update = $pdo->prepare('UPDATE tenants SET logo_path = :path WHERE id = :id');
    $update->execute(['path' => $relative, 'id' => $tenantId]);

    $previousAbsolute = tenantLogoAbsolutePath(is_string($previous) ? $previous : null);
    if ($previousAbsolute !== null && $previousAbsolute !== $target) {
        @unlink($previousAbsolute);
    }

    return $relative;
}
