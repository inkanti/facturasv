<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrfToken()) ?>">
    <meta name="app-url" content="<?= e(rtrim((string) appConfig('app.url'), '/')) ?>">
    <meta name="theme-color" content="#0b255e">
    <title><?= e($pageTitle ?? 'FacturaSV') ?> | FacturaSV</title>
    <link rel="icon" type="image/jpeg" href="<?= e(url('assets/img/bissweb-mark.jpg')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(assetUrl('assets/css/app.css')) ?>">
    <?php foreach (($pageStyles ?? []) as $style): ?>
    <link rel="stylesheet" href="<?= e(assetUrl($style)) ?>">
    <?php endforeach; ?>
</head>
<body class="<?= e($bodyClass ?? '') ?>">
