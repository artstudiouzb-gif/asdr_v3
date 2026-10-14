<?php
/** @var array $data */
$title = $data['title'] ?? '';
$items = $data['items'] ?? [];
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$variant = (string) $data['variant'];
// Мини-иконки у строк подбираются по содержимому: трубка к номеру, конверт к
// почте, часы к режиму работы.
$lineIcons = !empty($data['line_icons']);

// Фоллбек иконки по заголовку карточки
$getFallbackIcon = static function (string $cardTitle): ?string {
    $t = mb_strtolower(trim($cardTitle));
    if (str_contains($t, 'адрес') || str_contains($t, 'manzil') || str_contains($t, 'address') || str_contains($t, 'где')) {
        return 'map-pin';
    }
    if (str_contains($t, 'телефон') || str_contains($t, 'telefon') || str_contains($t, 'phone') || str_contains($t, 'связь') || str_contains($t, 'call') || str_contains($t, 'номер')) {
        return 'phone';
    }
    if (str_contains($t, 'mail') || str_contains($t, 'почта') || str_contains($t, 'e-mail') || str_contains($t, 'pochta') || str_contains($t, 'написать')) {
        return 'mail';
    }
    if (str_contains($t, 'часы') || str_contains($t, 'время') || str_contains($t, 'график') || str_contains($t, 'vaqt') || str_contains($t, 'hour') || str_contains($t, 'приём') || str_contains($t, 'режим')) {
        return 'clock';
    }
    return null;
};

// Размер иконки и подложка под ней. Всё уходит в scoped CSS блока: инлайн-стили
// в блоках стережёт тест, а размер значка тема форсит через
// --feature-card-icon-size (у неё правило со !important), плитка же прибита
// к 44×44 — перебить это можно только селектором с id блока.
$iconSize = (int) $data['icon_size'];
$iconBg = (string) $data['icon_bg'];
$scope = '#block-' . (int) $blockId . ' .contact-card';
$templateCss = $scope . '{--feature-card-icon-size:' . $iconSize . 'px}';
if ($iconBg === 'off') {
    // Без подложки плитка исчезает совсем — остаётся только значок.
    $templateCss .= $scope . ' .feature-card__icon,' . $scope . ':hover .feature-card__icon'
        . '{width:auto;height:auto;background:none;border-radius:0}';
} else {
    $templateCss .= $scope . ' .feature-card__icon{width:' . max(42, $iconSize + 22) . 'px;'
        . 'height:' . max(42, $iconSize + 22) . 'px}';
}
?>
<?php if ($variant === 'icons'): ?>
<?php
// «Только иконки» — ряд ссылок без текста: соцсети и мессенджеры. Заголовок
// карточки не печатается, но уходит в aria-label и подсказку: иконка без
// имени для диктора — это «ссылка» и ничего больше.
$iconsAlign = (string) $data['icons_align'];
// Размер значка — тот же «Размер иконки» блока; плитка вокруг считается от него.
$templateCss = '#block-' . (int) $blockId . ' .contact-icons{--contact-icon-size:' . $iconSize . 'px}';
?>
<div class="block-contact-cards block-contact-cards--icons block-contact-cards--icon-bg-<?= $iconBg ?> block-contact-cards--icons-<?= htmlspecialchars($iconsAlign, ENT_QUOTES) ?>">
    <?= \App\Core\SectionHead::render(['title' => $title, 'title_class' => 'block-contact-cards__title']) ?>
    <?php if (empty($items)): ?>
        <p class="block-contact-cards__empty"><?= htmlspecialchars(t('Контактные карточки ещё не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <ul class="contact-icons">
            <?php foreach ($items as $item): ?>
                <?php
                $iconSvg = !empty($item['icon_svg']) ? (string) $item['icon_svg'] : $getFallbackIcon((string) ($item['title'] ?? ''));
                $iconImage = trim((string) ($item['icon_image'] ?? ''));
                if ($iconImage !== '' && !\App\Core\UrlGuard::isSafeMedia($iconImage)) {
                    $iconImage = '';
                }
                if ($iconImage === '' && ($iconSvg === null || $iconSvg === '')) {
                    continue;
                }
                $name = trim((string) ($item['title'] ?? '')) ?: trim((string) ($item['link_text'] ?? ''));
                $linkUrl = trim((string) ($item['link_url'] ?? ''));
                $linkUrl = $linkUrl !== '' && \App\Core\UrlGuard::isSafeLink($linkUrl) ? $linkUrl : '';
                $glyph = $iconImage !== ''
                    ? '<img class="contact-icons__img" src="' . htmlspecialchars($iconImage, ENT_QUOTES) . '" alt="" width="' . $iconSize . '" height="' . $iconSize . '">'
                    : \App\Core\Icon::render((string) $iconSvg, $iconSize, 'contact-icons__svg', 1.8);
                $nameAttr = $name !== '' ? ' aria-label="' . htmlspecialchars($name, ENT_QUOTES) . '" title="' . htmlspecialchars($name, ENT_QUOTES) . '"' : '';
                ?>
                <li class="contact-icons__item">
                    <?php if ($linkUrl !== ''): ?>
                        <a class="contact-icons__link" href="<?= htmlspecialchars($linkUrl, ENT_QUOTES) ?>"<?= $nameAttr ?>><span class="contact-icons__icon" aria-hidden="true"><?= $glyph ?></span></a>
                    <?php else: ?>
                        <span class="contact-icons__link contact-icons__link--static"<?= $nameAttr !== '' ? ' role="img"' . $nameAttr : '' ?>><span class="contact-icons__icon" aria-hidden="true"><?= $glyph ?></span></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php return; endif; ?>
<div class="block-contact-cards block-contact-cards--<?= htmlspecialchars($variant, ENT_QUOTES) ?> block-contact-cards--icon-bg-<?= $iconBg ?> block-contact-cards--panel-<?= htmlspecialchars((string) $data['panel'], ENT_QUOTES) ?>">
    <?= \App\Core\SectionHead::render(['title' => $title, 'title_class' => 'block-contact-cards__title']) ?>
    <?php if (empty($items)): ?>
        <p class="block-contact-cards__empty"><?= htmlspecialchars(t('Контактные карточки ещё не добавлены.'), ENT_QUOTES) ?></p>
    <?php else: ?>
        <div class="contact-cards">
            <?php foreach ($items as $index => $item): ?>
                <?php
                $iconSvg = !empty($item['icon_svg']) ? (string) $item['icon_svg'] : $getFallbackIcon((string) ($item['title'] ?? ''));
                // Своя картинка важнее ключа Tabler — как у кнопок обложки.
                $iconImage = trim((string) ($item['icon_image'] ?? ''));
                if ($iconImage !== '' && !\App\Core\UrlGuard::isSafeMedia($iconImage)) {
                    $iconImage = '';
                }
                ?>
                <article class="feature-card contact-card">
                    <?php // Вариант «в одну строку»: иконка и заголовок рядом,
                          // номер уходит вправо — карточка получается ниже. ?>
                    <div class="feature-card__top">
                        <?php if ($iconImage !== ''): ?>
                            <span class="feature-card__icon contact-card__icon" aria-hidden="true"><img class="contact-card__icon-img" src="<?= htmlspecialchars($iconImage, ENT_QUOTES) ?>" alt="" width="<?= $iconSize ?>" height="<?= $iconSize ?>"></span>
                        <?php elseif ($iconSvg !== null && $iconSvg !== ''): ?>
                            <span class="feature-card__icon contact-card__icon" aria-hidden="true"><?= \App\Core\Icon::render($iconSvg, $iconSize, 'contact-card__icon-svg', 1.8) ?></span>
                        <?php else: ?>
                            <span class="feature-card__spacer"></span>
                        <?php endif; ?>
                        <?php if ($variant === 'inline' && !empty($item['title'])): ?>
                            <h3 class="feature-card__title contact-card__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h3>
                        <?php endif; ?>
                        <span class="feature-card__num" aria-hidden="true"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                    </div>
                    <?php if ($variant !== 'inline' && !empty($item['title'])): ?>
                        <h3 class="feature-card__title contact-card__title"><?= htmlspecialchars((string) $item['title'], ENT_QUOTES) ?></h3>
                    <?php endif; ?>
                    <?php foreach (preg_split('/\r\n|\n|\r/', (string) ($item['lines'] ?? '')) ?: [] as $line): ?>
                        <?php $line = trim($line); if ($line === '') { continue; } ?>
                        <?php // Номера и адреса почты становятся ссылками: с телефона по ним звонят и пишут в одно касание. ?>
                        <?php $lineIcon = $lineIcons ? \App\Core\ContactLink::iconFor($line) : null; ?>
                        <p class="feature-card__text contact-card__line<?= $lineIcon !== null ? ' contact-card__line--icon' : '' ?>">
                            <?php if ($lineIcon !== null): ?>
                                <span class="contact-card__line-icon" aria-hidden="true"><?= \App\Core\Icon::render($lineIcon, 15, '', 1.8) ?></span>
                            <?php endif; ?>
                            <span class="contact-card__line-text"><?= \App\Core\ContactLink::linkify($line) ?></span>
                        </p>
                    <?php endforeach; ?>
                    <?php $linkUrl = trim((string) ($item['link_url'] ?? '')); ?>
                    <?php if ($linkUrl !== '' && \App\Core\UrlGuard::isSafeLink($linkUrl) && !empty($item['link_text'])): ?>
                        <a class="contact-card__link" href="<?= htmlspecialchars($linkUrl, ENT_QUOTES) ?>"><?= htmlspecialchars((string) $item['link_text'], ENT_QUOTES) ?> →</a>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
