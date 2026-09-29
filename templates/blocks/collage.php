<?php

use App\Core\BlockBackground;
use App\Core\BlockData\CollageBlockNormalizer;
use App\Core\CollageBadge;
use App\Core\CollageComposition;
use App\Core\CollageEnsemble;
use App\Core\CollageLayout;
use App\Core\Icon;
use App\Core\Media;
use App\Core\TitleMarkup;
use App\Core\UrlGuard;
use App\Core\Video;

/**
 * «Коллаж»: свободная композиция из разнотипных элементов на общей сетке.
 *
 * Размещение хранится номерами ячеек, а не координатами: два элемента могут
 * занять одни и те же ячейки — так и получается наложение, а порядок в
 * репитере решает, кто сверху. Свободные X/Y не годятся, потому что их нечем
 * сложить в столбец на телефоне.
 *
 * Композиции по ролям (CollageLayout::COMPOSED) ячеек не знают: кадр с
 * вырезом, печать на его краю и стеклянная карточка поверх портрета —
 * отношения между элементами, а не места в сетке. Разметка элемента при этом
 * одна на оба режима ($renderItem): второй экземпляр разъехался бы с первым
 * при первой правке.
 *
 * Правила размещения уходят в scoped CSS: инлайн-стили в блоках запрещены
 * тестами, а значения тут у каждого блока свои.
 *
 * @var array $data
 * @var int $blockId
 */
$title = trim((string) ($data['title'] ?? ''));
// Значения проверены схемой полей (BlockFieldSchema) — читаем как есть.
$layout = (string) $data['layout'];
$columns = (int) $data['columns'];
$rows = (int) $data['rows'];
$ratio = (string) $data['ratio'];
$gap = (string) $data['gap'];
$cardStyle = (string) $data['card_style'];
$items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
$composed = CollageLayout::isComposed($layout);
// Композиция без текста держит сетку и в узкой колонке (см. collage.css).
$textless = !CollageLayout::hasText($items);

$scope = '#block-' . (int) $blockId;
$templateCss = $scope . ' .collage__canvas{--collage-cols:' . $columns . ';--collage-rows:' . $rows . ';}';

foreach ($items as $index => $item) {
    if (!$composed) {
        $templateCss .= $scope . ' .collage__item--' . $index . '{'
            . 'grid-column:' . (int) $item['col'] . '/span ' . (int) $item['col_span'] . ';'
            . 'grid-row:' . (int) $item['row'] . '/span ' . (int) $item['row_span'] . ';'
            . '}';
    }
    // Свой цвет элемента — тоже переменная: инлайн-стиль здесь запрещён.
    $vars = '';
    if (($item['bg'] ?? '') !== '') {
        $vars .= '--collage-bg:' . $item['bg'] . ';';
    }
    if (($item['fg'] ?? '') !== '') {
        $vars .= '--collage-fg:' . $item['fg'] . ';';
    }
    if ($vars !== '') {
        $templateCss .= $scope . ' .collage__item--' . $index . '{' . $vars . '}';
    }
}

// Точка фокуса уходит аргументом в Media::picture, а не собственным
// object-position: рендерер печатает её инлайновой переменной у самого <img>,
// и своё правило либо проигрывало бы ей, либо молча затирало точку фокуса,
// заданную снимку в медиатеке.
$focusMap = [
    'center' => [50, 50],
    'top' => [50, 0],
    'bottom' => [50, 100],
    'left' => [0, 50],
    'right' => [100, 50],
];

$renderItem = static function (int $index, array $item, string $extraClass = '') use ($blockId, $scope, $focusMap, &$templateCss): string {
    $type = (string) $item['type'];
    // Печать всегда круглая: у неё форма не настройка, а суть.
    $shape = $type === 'badge' ? 'circle' : (string) $item['shape'];
    // Ссылка элемента — повторяющееся поле, схема её не приводит.
    $link = (string) ($item['link'] ?? '');
    $attrs = '';
    if ($type === 'video') {
        // Ролик проверяется и на выводе: данные приезжают и из файла шаблона
        // страницы, где значения никто не сверял. YouTube приводится к одной
        // ссылке — по ней его узнаёт лайтбокс, — файл открывается им же по
        // признаку, а без скрипта обе ссылки просто ведут на ролик.
        $youtube = Video::youtubeId((string) ($item['video'] ?? ''));
        $link = $youtube !== null ? 'https://www.youtube.com/watch?v=' . $youtube : (string) ($item['video'] ?? '');
        $extension = strtolower(pathinfo((string) parse_url($link, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($youtube === null && (!UrlGuard::isSafeMedia($link) || !in_array($extension, CollageBlockNormalizer::VIDEO_FILES, true))) {
            return '';
        }
        $videoTitle = (string) ($item['video_title'] ?? '');
        // Имя ссылки — действие и предмет: «Смотреть видео: …». Одна подпись
        // на кадре не говорит диктору, что по нажатию откроется плеер.
        $attrs = ' aria-label="' . htmlspecialchars(t('Смотреть видео') . ($videoTitle !== '' ? ': ' . $videoTitle : ''), ENT_QUOTES) . '"'
            . ($youtube === null ? ' data-lightbox-video' : '');
    } elseif ($link !== '' && !UrlGuard::isSafeLink($link)) {
        $link = '';
    }
    $tag = $link !== '' ? 'a' : 'div';
    $classes = 'collage__item collage__item--' . $index
        . ' collage__item--' . $type
        . ' collage__item--shape-' . $shape
        . ($extraClass !== '' ? ' ' . $extraClass : '');

    ob_start();
    ?>
    <<?= $tag ?> class="<?= $classes ?>"<?= $link !== '' ? ' href="' . htmlspecialchars($link, ENT_QUOTES) . '"' : '' ?><?= $attrs ?>>
        <?php if ($type === 'photo'):
            $focus = $focusMap[(string) ($item['focus'] ?? 'auto')] ?? [null, null];
            echo Media::picture(
                (string) $item['image'],
                (string) ($item['alt'] ?? ''),
                $focus[0],
                $focus[1],
                'collage__photo',
                true,
                '(max-width: 720px) 100vw, 50vw'
            );
        elseif ($type === 'video'):
            $poster = (string) ($item['poster'] ?? '');
            if ($poster !== '' && UrlGuard::isSafeMedia($poster)) {
                // Кадр-обложка — декорация: смысл ссылки несёт её имя.
                echo Media::picture($poster, '', null, null, 'collage__photo', true, '(max-width: 720px) 100vw, 50vw');
            } elseif ($youtube !== null) {
                // Превью YouTube 4:3 несёт чёрные поля сверху и снизу у
                // широкого ролика; класс срезает их увеличением кадра.
                ?><img class="collage__photo collage__photo--youtube" src="https://i.ytimg.com/vi/<?= htmlspecialchars($youtube, ENT_QUOTES) ?>/hqdefault.jpg" alt="" loading="lazy" decoding="async"><?php
            } else {
                // Без обложки у файла показывается первый кадр: `#t` просит
                // браузер встать на него, а `preload="metadata"` не качает
                // ролик целиком.
                ?><video class="collage__photo" src="<?= htmlspecialchars($link, ENT_QUOTES) ?>#t=0.1" preload="metadata" muted playsinline tabindex="-1" aria-hidden="true"></video><?php
            }
            ?>
            <span class="collage__play" aria-hidden="true"></span>
            <?php if ($videoTitle !== ''): ?>
                <span class="collage__video-title" aria-hidden="true"><?= htmlspecialchars($videoTitle, ENT_QUOTES) ?></span>
            <?php endif; ?>
        <?php elseif ($type === 'stat'): ?>
            <?php if (!empty($item['icon_svg'])): ?>
                <span class="collage__stat-icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], 32) ?></span>
            <?php endif; ?>
            <?php if (($item['prefix'] ?? '') !== ''): ?>
                <span class="collage__stat-prefix"><?= htmlspecialchars((string) $item['prefix'], ENT_QUOTES) ?></span>
            <?php endif; ?>
            <?php if (($item['value'] ?? '') !== ''): ?>
                <span class="collage__stat-value"><?= htmlspecialchars((string) $item['value'], ENT_QUOTES) ?></span>
            <?php endif; ?>
            <?php if (($item['label'] ?? '') !== ''): ?>
                <span class="collage__stat-label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES) ?></span>
            <?php endif; ?>
        <?php elseif ($type === 'quote'): ?>
            <?php
            // Знак кавычки декоративен: диктор читает саму цитату,
            // а «левая двойная кавычка» посреди фразы сбивает.
            ?>
            <blockquote class="collage__quote">
                <span class="collage__quote-mark" aria-hidden="true">«</span>
                <p class="collage__quote-text"><?= htmlspecialchars((string) $item['quote_text'], ENT_QUOTES) ?></p>
                <?php if (($item['author'] ?? '') !== '' || ($item['role'] ?? '') !== ''): ?>
                    <footer class="collage__quote-by">
                        <?php if (($item['author'] ?? '') !== ''): ?>
                            <cite class="collage__quote-author"><?= htmlspecialchars((string) $item['author'], ENT_QUOTES) ?></cite>
                        <?php endif; ?>
                        <?php if (($item['role'] ?? '') !== ''): ?>
                            <span class="collage__quote-role"><?= htmlspecialchars((string) $item['role'], ENT_QUOTES) ?></span>
                        <?php endif; ?>
                    </footer>
                <?php endif; ?>
            </blockquote>
        <?php elseif ($type === 'info'):
            // Справка — пары «подпись / значение», то есть ровно список
            // определений: диктор читает их парами, а не россыпью строк.
            $infoRows = CollageBlockNormalizer::infoRows((string) ($item['info_rows'] ?? ''));
        ?>
            <?php if (($item['info_title'] ?? '') !== ''): ?>
                <p class="collage__info-title"><?= htmlspecialchars((string) $item['info_title'], ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($infoRows !== []): ?>
                <dl class="collage__info-list">
                    <?php foreach ($infoRows as [$infoLabel, $infoValue]): ?>
                        <div class="collage__info-row"><dt><?= htmlspecialchars($infoLabel, ENT_QUOTES) ?></dt><?php if ($infoValue !== ''): ?><dd><?= htmlspecialchars($infoValue, ENT_QUOTES) ?></dd><?php endif; ?></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
        <?php elseif ($type === 'badge'):
            // Надпись по кругу собирает CollageBadge: это текст на
            // траектории, а не иконка, и шаблону такую геометрию
            // носить нельзя.
            $badgeText = (string) ($item['text'] ?? '');
            $center = (string) ($item['center'] ?? 'none');
            if ($center === 'emblem') {
                // Маска знака — абсолютным адресом из scoped CSS: переменная
                // --gov-emblem объявлена относительным url, и из файла блока
                // браузер разрешил бы её не туда.
                $templateCss .= $scope . ' .collage__item--' . $index . ' .collage__badge-emblem{'
                    . '-webkit-mask-image:url("' . BlockBackground::emblemUrl() . '");'
                    . 'mask-image:url("' . BlockBackground::emblemUrl() . '");}';
            }
        ?>
            <span class="collage__badge">
                <?php if ($badgeText !== ''): ?>
                    <?= CollageBadge::ring($badgeText, 'collage-badge-' . (int) $blockId . '-' . $index) ?>
                    <span class="visually-hidden"><?= htmlspecialchars($badgeText, ENT_QUOTES) ?></span>
                <?php endif; ?>
                <?php if ($center === 'emblem'): ?>
                    <span class="collage__badge-core" aria-hidden="true"><span class="collage__badge-emblem"></span></span>
                <?php elseif ($center === 'image'): ?>
                    <?php // Логотип — картинка без alt: смысл печати несёт надпись рядом. ?>
                    <span class="collage__badge-core"><img class="collage__badge-logo" src="<?= htmlspecialchars((string) $item['center_image'], ENT_QUOTES) ?>" alt="" loading="lazy" decoding="async"></span>
                <?php elseif ($center === 'icon'): ?>
                    <span class="collage__badge-icon" aria-hidden="true"><?= Icon::render((string) $item['icon_svg'], 26) ?></span>
                <?php endif; ?>
            </span>
        <?php else:
            // Узор берётся из общего набора фонов секции, а не
            // рисуется здесь заново; свой узор — картинка-плитка.
            $size = CollageBlockNormalizer::PATTERN_SIZES[(string) ($item['pattern_size'] ?? 'medium')] ?? 28;
            $patternCss = (string) $item['pattern'] === CollageBlockNormalizer::CUSTOM_PATTERN
                ? 'background-image:url("' . htmlspecialchars((string) $item['pattern_image'], ENT_QUOTES) . '");'
                    . 'background-repeat:repeat;background-size:' . ($size * 2) . 'px auto;'
                : BlockBackground::patternCss((string) $item['pattern']);
            $templateCss .= $scope . ' .collage__item--' . $index . ' .collage__pattern{'
                . '--block-pattern-size:' . $size . 'px;' . $patternCss . '}';
        ?>
            <span class="collage__pattern" aria-hidden="true"></span>
        <?php endif; ?>
    </<?= $tag ?>>
    <?php

    return (string) ob_get_clean();
};

$blockClasses = 'block-collage'
    . ' block-collage--cards-' . $cardStyle
    . (empty($data['badge_spin']) ? ' block-collage--still' : '');
?>
<div class="<?= htmlspecialchars($blockClasses, ENT_QUOTES) ?>">
    <?php if ($title !== ''): ?><h2 class="section-head__title block-collage__title"><?= TitleMarkup::html($title) ?></h2><?php endif; ?>
    <?php if ($items !== [] && !$composed): ?>
        <div class="collage__canvas collage__canvas--ratio-<?= htmlspecialchars($ratio, ENT_QUOTES) ?> collage__canvas--gap-<?= htmlspecialchars($gap, ENT_QUOTES) ?><?= $textless ? ' collage__canvas--textless' : '' ?>">
            <?php foreach ($items as $index => $item): ?>
                <?= $renderItem($index, $item) ?>
            <?php endforeach; ?>
        </div>
    <?php elseif ($items !== [] && CollageEnsemble::isEnsemble($layout)):
        // Ансамбль: места выбирает CollageEnsemble по типам элементов, сюда
        // приходят готовые прямоугольники. Они уходят переменными (--ga,
        // --cols, --rows), а не свойствами: scoped CSS весит по id, и узкий
        // экран не отменил бы свойство без флага приоритета.
        $plan = CollageEnsemble::plan($layout, $items);
        $cascade = $layout === 'cascade';
        if (!$cascade && $plan['columns'] !== '') {
            $templateCss .= $scope . ' .collage-comp{--cols:' . $plan['columns'] . ';--rows:' . $plan['rows'] . ';}';
        }
        $tileHtml = static function (array $tile) use ($items, $renderItem, $cascade, $scope, &$templateCss): string {
            $index = (int) $tile['index'];
            $templateCss .= $scope . ' .collage__item--' . $index . '{'
                . ($cascade ? '--ci:' . $index . ';' : '--ga:' . $tile['area'] . ';') . '}';
            $class = 'collage-comp__tile'
                . ($tile['shape'] !== '' ? ' collage-comp__tile--' . $tile['shape'] : '')
                . ($tile['shape'] === 'lead' ? ' collage-comp__lead' : '')
                . ($tile['ratio'] !== '' ? ' collage-comp__tile--r-' . $tile['ratio'] : '')
                . ($tile['full'] ? ' collage-comp__tile--full' : '')
                . ($tile['by_badge'] !== '' ? ' collage-comp__tile--by-badge-' . $tile['by_badge'] : '');

            return $renderItem($index, $items[$index], $class);
        };
        $badge = $plan['badge'];
        $badgeHtml = '';
        if ($badge !== null) {
            if (!$cascade) {
                $templateCss .= $scope . ' .collage__item--' . (int) $badge['index'] . '{--ga:' . $badge['area'] . ';}';
            }
            $badgeHtml = $renderItem((int) $badge['index'], $items[(int) $badge['index']], 'collage-comp__badge collage-comp__badge--' . $badge['at']);
        }
        $cascadeCols = $cascade ? max(1, min(3, count($plan['tiles']))) : 0;
        $ensembleClasses = 'collage-comp collage-comp--' . $layout
            . ($cascade ? ' collage-comp--cascade-' . $cascadeCols : ' collage-comp--grid')
            . ' collage__canvas--gap-' . $gap
            . ($textless ? ' collage-comp--textless' : '')
            . ($badge !== null ? ' collage-comp--has-badge collage-comp--badge-' . $badge['at'] : '');
    ?>
        <?php if ($plan['complete']): ?>
            <div class="<?= htmlspecialchars($ensembleClasses, ENT_QUOTES) ?>">
                <?php if ($cascade): ?>
                    <?php for ($column = 0; $column < $cascadeCols; $column++): ?>
                        <div class="collage-cascade__col">
                            <?php // Печать занимает смещение второй колонки: так оно перестаёт быть пустотой. ?>
                            <?= $column === min(1, $cascadeCols - 1) ? $badgeHtml : '' ?>
                            <?php foreach ($plan['tiles'] as $tile): ?>
                                <?= (int) $tile['column'] === $column ? $tileHtml($tile) : '' ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endfor; ?>
                <?php else: ?>
                    <?php foreach ($plan['tiles'] as $tile): ?>
                        <?= $tileHtml($tile) ?>
                    <?php endforeach; ?>
                    <?= $badgeHtml ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($items !== []):
        $roles = CollageComposition::roles($layout, $items);
        $slot = static fn (string $name): array => $roles['slots'][$name] ?? [];
        $one = static function (string $name, string $class) use ($slot, $items, $renderItem): string {
            $out = '';
            foreach ($slot($name) as $index) {
                $out .= $renderItem($index, $items[$index], $class);
            }

            return $out;
        };
        $compClasses = 'collage-comp collage-comp--' . $layout
            . ' collage__canvas--gap-' . $gap
            . ' collage-comp--ratio-' . $ratio
            . (in_array($layout, ['callout', 'notch'], true) ? ' collage-comp--cut-' . (string) $data['cut_corner'] : '');
        // Композиция без кадра не строится: вырез вырезать не из чего.
        $hasPhoto = CollageComposition::complete($layout, $roles);
    ?>
        <?php if ($hasPhoto): ?>
            <div class="<?= htmlspecialchars($compClasses, ENT_QUOTES) ?>">
                <?php if (in_array($layout, ['callout', 'notch'], true)): ?>
                    <?= $one('pattern', 'collage-comp__pattern') ?>
                    <?= $one('photo', 'collage-comp__photo collage-comp__cut') ?>
                    <?= $one('card', 'collage-comp__card') ?>
                    <?= $one('badge', 'collage-comp__badge') ?>
                <?php elseif ($layout === 'pair'): ?>
                    <?php // Кадр с вырезом под второй: полоса между ними — фон секции, а не обводка. ?>
                    <?= $one('photo', 'collage-comp__photo collage-comp__cut') ?>
                    <?= $one('pattern', 'collage-comp__pattern') ?>
                    <?= $one('second', 'collage-comp__photo collage-comp__second') ?>
                    <?= $one('card', 'collage-comp__card') ?>
                    <?= $one('badge', 'collage-comp__badge') ?>
                <?php elseif ($layout === 'diagonal'): ?>
                    <?php // Вырез под карточку заходит на оба кадра, поэтому маска у обоих. ?>
                    <?= $one('photo', 'collage-comp__photo collage-comp__cut collage-comp__before') ?>
                    <?= $one('second', 'collage-comp__photo collage-comp__cut collage-comp__after') ?>
                    <?= $one('card', 'collage-comp__card') ?>
                    <?= $one('badge', 'collage-comp__badge') ?>
                <?php elseif ($layout === 'checker'): ?>
                    <?= $one('tiles', 'collage-comp__tile') ?>
                    <?= $one('badge', 'collage-comp__badge') ?>
                <?php else: ?>
                    <div class="collage-comp__pic">
                        <?= $one('photo', 'collage-comp__photo') ?>
                        <?php if ($slot('stats') !== []): ?>
                            <div class="collage-comp__glass"><?= $one('stats', 'collage-comp__stat') ?></div>
                        <?php endif; ?>
                        <?= $one('badge', 'collage-comp__badge') ?>
                    </div>
                    <div class="collage-comp__side">
                        <?= $one('pattern', 'collage-comp__pattern') ?>
                        <?= $one('quote', 'collage-comp__quote') ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
