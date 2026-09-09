</main>
<script src="/assets/js/a11y.js" defer></script>
<?php // Скрипты портала вынесены в файл: CSP не разрешает inline-код. ?>
<script src="<?= htmlspecialchars(\App\Core\Asset::url('/assets/js/repo.js'), ENT_QUOTES) ?>" defer></script>
</body>
</html>
