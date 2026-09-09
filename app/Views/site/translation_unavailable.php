<?php

use App\Core\Locale;

/** @var array<int, array{code:string,name:string,url:string}> $alternatives */

$metaTitle = t('Перевод пока недоступен');
$metaDescription = t('Этот материал ещё не переведён на выбранный язык.');
$robotsNoindex = true;
require __DIR__ . '/_header.php';
?>
<main class="translation-notice" id="main-content">
    <div class="translation-notice__card">
        <span class="translation-notice__icon" aria-hidden="true">文</span>
        <p class="translation-notice__eyebrow"><?= htmlspecialchars(t('Доступен на другом языке'), ENT_QUOTES) ?></p>
        <h1 class="translation-notice__title"><?= htmlspecialchars(t('Перевод пока недоступен'), ENT_QUOTES) ?></h1>
        <p class="translation-notice__text"><?= htmlspecialchars(t('Этот материал ещё не переведён на выбранный язык. Вы можете открыть его на другом доступном языке.'), ENT_QUOTES) ?></p>

        <?php if ($alternatives !== []): ?>
            <div class="translation-notice__actions" aria-label="<?= htmlspecialchars(t('Доступные языки материала'), ENT_QUOTES) ?>">
                <?php foreach ($alternatives as $alternative): ?>
                    <a class="translation-notice__button" href="<?= htmlspecialchars($alternative['url'], ENT_QUOTES) ?>" hreflang="<?= htmlspecialchars($alternative['code'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars(t('Посмотреть на языке'), ENT_QUOTES) ?>: <?= htmlspecialchars($alternative['name'], ENT_QUOTES) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <a class="translation-notice__home" href="<?= htmlspecialchars(Locale::url('/'), ENT_QUOTES) ?>"><?= htmlspecialchars(t('Вернуться на главную'), ENT_QUOTES) ?></a>
    </div>
</main>
<?php require __DIR__ . '/_footer.php'; ?>