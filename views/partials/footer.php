<script src="<?= e(url('assets/vendor/jquery/jquery.min.js')) ?>"></script>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(assetUrl('assets/js/app.js')) ?>"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
<script src="<?= e(assetUrl($script)) ?>"></script>
<?php endforeach; ?>
</body>
</html>
