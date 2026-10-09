<?php

/**
 * «Лента Instagram»: последние публикации аккаунта ведомства.
 *
 * Посты приходят из сохранённой копии (`InstagramFeed`), а не из Instagram:
 * публичная страница в сеть не ходит. Кадры лежат в медиатеке, поэтому
 * уменьшенные копии и ленивая загрузка — те же, что у любой картинки сайта.
 *
 * Пустая лента не выводится вовсе: рамка с одним заголовком читается как
 * поломка, а что лента не подключена, редактору говорит подсказка в форме.
 *
 * @var array $data
 * @var int $blockId
 */
$posts = is_array($data['posts'] ?? null) ? $data['posts'] : [];
$columns = (int) $data['columns'];
$templateCss = '#block-' . (int) $blockId . ' .ig-feed{--ig-cols:' . $columns . ';}';
?>
<?php if ($posts !== []): ?>
    <div class="ig-feed ig-feed--<?= htmlspecialchars((string) $data['layout'], ENT_QUOTES) ?>">
        <?= \App\Core\SectionHead::render([
            'title' => (string) ($data['title'] ?? ''),
            'all_text' => (string) $data['all_text'],
            'all_url' => (string) ($data['profile_url'] ?? ''),
            'class' => 'ig-feed__head',
        ]) ?>
        <?php require __DIR__ . '/partials/instagram_grid.php'; ?>
    </div>
<?php endif; ?>
