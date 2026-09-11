<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\BlockData\BlockFieldSchema;
use App\Core\BlockData\BlockPresentationNormalizer;
use App\Models\FormDef;

final class BlockRenderer
{
    /**
     * Совместимый фасад: источник истины находится в BlockTypeRegistry.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return BlockTypeRegistry::defaults();
    }

    /**
     * Ближайшая граница расписания среди отрисованных блоков — заполняется в
     * render(), сбрасывается в renderPage() и отдаётся вызывающему как
     * expires_at, чтобы кэш страницы не пережил свою же дату показа.
     */
    private static ?int $nextBoundary = null;

    /**
     * Ближайшая граница расписания на странице. Её сообщают и блоки целиком,
     * и отдельные слайды обложки: кэш страницы обязан пережить свою же дату
     * показа, иначе слайд «до 30 июля» останется висеть и 31-го.
     */
    public static function noteBoundary(?int $boundary): void
    {
        if ($boundary !== null && (self::$nextBoundary === null || $boundary < self::$nextBoundary)) {
            self::$nextBoundary = $boundary;
        }
    }

    /**
     * Режим предпросмотра в админке. На сайте незаполненный блок просто не
     * выводится (иначе на странице зияет пустая секция с отступами), а
     * редактору вместо него показывается заметка: блок добавлен, но пуст —
     * иначе «ничего не появилось» читается как поломка.
     */
    private static bool $previewMode = false;

    /**
     * Заголовок первого уровня на странице должен быть один: экранный диктор
     * по нему понимает, о чём страница. Обложка и профиль персоны претендуют
     * на h1 — первому из них он и достаётся, второму остаётся h2.
     */
    private static bool $h1Used = false;

    /**
     * Типы блоков, отрисованных внутри контейнера («Колонки», «Вкладки»).
     *
     * renderPage() перечисляет ассеты по блокам верхнего уровня, а вложенный
     * блок там представлен только своим контейнером — то есть его CSS и скрипт
     * не подключались вовсе. Отказ тихий: разметка на месте, поэтому блок не
     * пропадает, а рисуется без собственных правил — «Коллаж» в колонке шёл
     * одним столбцом, потому что `display:grid` ему задаёт как раз свой файл.
     * Эвристики по готовому HTML (обложка, фотокарусель) это не закрывают: они
     * заведены под конкретные признаки, а типов со своим ассетом полтора
     * десятка.
     *
     * @var array<string, bool>
     */
    private static array $nestedAssets = [];

    /**
     * Разделы страницы для якорной навигации: собираются до рендера, потому
     * что блок оглавления обычно стоит первым и должен знать о том, что будет
     * ниже. Источник — заголовок блока: он же выводится на странице секцией.
     *
     * @var list<array{id: string, label: string}>
     */
    private static array $pageSections = [];

    /** @return list<array{id: string, label: string}> */
    public static function pageSections(): array
    {
        return self::$pageSections;
    }

    /**
     * Типы, чей заголовок может быть заголовком страницы. CTA сюда не входит:
     * это рекламная врезка и всегда использует h2.
     *
     * @var list<string>
     */
    private const H1_BLOCKS = ['hero'];

    /**
     * Сообщает рендеру, что h1 на странице уже занят (например, шапкой самой
     * страницы), чтобы блоки не добавляли второй.
     */
    public static function markH1Used(): void
    {
        self::$h1Used = true;
    }

    public static function setPreviewMode(bool $on): void
    {
        self::$previewMode = $on;
    }

    /**
     * Видимой считаем секцию, где есть текст или медиа. Только текст проверять
     * нельзя: галерея из одних фотографий текста не содержит.
     */
    public static function isVisuallyEmpty(string $html): bool
    {
        $html = trim($html);
        if ($html === '') {
            return true;
        }

        // Текст, доступный пользователю или экранному диктору.
        $withoutCode = preg_replace(
            '#<(script|style|template)\b[^>]*>.*?</\1>#is',
            '',
            $html
        ) ?? $html;

        // Заготовки состояний («Ничего не найдено», пустые слоты фильтров)
        // помечены атрибутом hidden и до действия пользователя не отображаются.
        // Браузер их не рисует, значит для расчёта пустоты это не содержимое:
        // иначе пустой блок FAQ считался бы заполненным из-за скрытой подписи
        // и оставлял на странице секцию с отступами.
        $withoutCode = preg_replace(
            '#<([a-z][a-z0-9]*)\b[^>]*?\shidden(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]*))?[^>]*>.*?</\1>#is',
            '',
            $withoutCode
        ) ?? $withoutCode;

        $text = html_entity_decode(strip_tags($withoutCode), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (trim((string) preg_replace('/\x{00A0}|\s+/u', ' ', $text)) !== '') {
            return false;
        }

        // Самодостаточные визуальные и интерактивные элементы могут не иметь
        // текста, но всё равно являются содержимым блока.
        // `hr` в этом списке не для красоты: разделитель — это блок, у которого
        // нет и не должно быть ни текста, ни картинки, а на страницу он попасть
        // обязан.
        if (preg_match(
            '#<(img|picture|video|audio|iframe|svg|canvas|form|input|textarea|select|button|hr)\b#i',
            $withoutCode
        ) === 1) {
            return false;
        }

        // Фоновое изображение тоже считается визуальным содержимым.
        return preg_match('/background(?:-image)?\s*:\s*[^;]*url\s*\(/i', $withoutCode) !== 1;
    }

    public static function defaultsFor(string $type): array
    {
        return BlockTypeRegistry::defaultsFor($type);
    }

    /**
     * @param array<string, mixed> $block
     * @return array{html: string, css: string, hidden?: bool, preload_image?: string|null}
     */
    public static function render(array $block): array
    {
        $rawType = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $block['type'])) ?? '';
        // Переименованный тип читается по-новому даже до миграции: код и база
        // на сервере обновляются разными путями, и в окне между ними блок
        // иначе вышел бы комментарием «Неизвестный тип блока».
        $type = BlockTypeRegistry::canonicalType($rawType);
        $blockId = (int) $block['id'];
        $data = json_decode((string) ($block['data'] ?? '{}'), true);
        if (!is_array($data)) {
            $data = [];
        }
        $data = BlockTypeRegistry::canonicalData($rawType, $data);

        // Смердживание с дефолтами поддерживает частично заполненные формы.
        $data = array_merge(self::defaultsFor($type), $data);
        // Типы со схемой полей получают проверенные значения и на выводе:
        // data блока приезжает и из старых записей, и из загруженного файла
        // шаблона страницы, где сверены только ключи.
        if (BlockFieldSchema::has($type)) {
            $data = BlockFieldSchema::apply($type, $data);
        }

        // Условия показа (расписание). Границу запоминаем до проверки: блок,
        // который ещё не начался, тоже обязан разморозить кэш к своему старту.
        self::noteBoundary(BlockVisibility::boundary($data));
        if (!BlockVisibility::isVisible($data)) {
            return ['html' => '', 'css' => '', 'hidden' => true];
        }

        // Уровень заголовка блока: первому претенденту на странице — h1,
        // следующим — h2 (двух h1 на странице быть не должно).
        if (in_array($type, self::H1_BLOCKS, true)) {
            $data['_heading_tag'] = self::$h1Used ? 'h2' : 'h1';
            self::$h1Used = true;
        }

        $data = self::enrichData($type, $data);

        // Блок «columns» (группа 4.1): рендерим вложенные блоки, сгруппированные
        // по колонкам. Дочерние блоки — обычные блоки со своими scoped-стилями.
        $childrenCss = '';
        $templateCss = '';
        if ($type === 'columns') {
            [$html, $childrenCss] = self::renderColumns($block, $data);
        } elseif ($type === 'tabs') {
            [$html, $childrenCss] = self::renderTabs($block, $data);
        } else {
            $templateFile = BlockTypeRegistry::templateFile($type);
            if ($templateFile !== null && is_file($templateFile)) {
                [$html, $templateCss] = self::renderTemplate($templateFile, $data, $blockId);
            } else {
                $html = '<!-- Неизвестный тип блока: ' . htmlspecialchars($type, ENT_QUOTES) . ' -->';
            }
        }

        $scopedCss = '';
        if (!empty($block['custom_css'])) {
            $scopedCss = CssScoper::scope((string) $block['custom_css'], '#block-' . $blockId);
        }
        if ($childrenCss !== '') {
            $scopedCss = $scopedCss !== '' ? $scopedCss . "\n" . $childrenCss : $childrenCss;
        }
        if ($templateCss !== '') {
            $scopedCss = $scopedCss !== '' ? $scopedCss . "\n" . $templateCss : $templateCss;
        }

        // Дизайн-система: пресет отступов и опция анимации появления.
        // Ключи _spacing/_reveal могут отсутствовать (старые/битые данные) —
        // берём безопасные значения по умолчанию.
        $spacing = (string) ($data['_spacing'] ?? 'premium');
        if (!in_array($spacing, ['none', 'small', 'premium', 'max'], true)) {
            $spacing = 'premium';
        }
        // Анимация появления (группа 4.2). Обратная совместимость: старое
        // булево _reveal=true → {enabled:true, type:'fade'}.
        $revealRaw = $data['_reveal'] ?? null;
        if (is_array($revealRaw)) {
            $revealOn = !empty($revealRaw['enabled']);
            $revealType = (string) ($revealRaw['type'] ?? 'fade');
        } else {
            $revealOn = !empty($revealRaw);
            $revealType = 'fade';
        }
        if (!in_array($revealType, ['fade', 'slide-up', 'slide-left', 'slide-right', 'zoom-in', 'stagger'], true)) {
            $revealType = 'fade';
        }
        // Фон секции, полноширинная подложка и независимые отступы сверху/снизу.
        $bg = (string) ($data['_bg'] ?? 'none');
        if (!in_array($bg, ['none', 'light', 'tint', 'navy'], true)) {
            $bg = 'none';
        }
        $surface = (string) ($data['_surface'] ?? 'flat');
        if (!in_array($surface, ['flat', 'card'], true)) {
            $surface = 'flat';
        }
        $fullwidth = !empty($data['_fullwidth']);
        $padMap = ['none' => '0', 'small' => 'var(--space-small)', 'medium' => 'var(--space-premium)', 'large' => 'var(--space-max)'];
        $extraClass = '';
        // Своя заливка (цвет, градиент, фото, узор) отменяет пресет темы: два
        // фона на одной секции дают кашу.
        $background = BlockBackground::build($data, $blockId);
        if ($background['class'] !== '') {
            $extraClass .= $background['class'];
            if ($background['css'] !== '') {
                $scopedCss = $scopedCss !== '' ? $scopedCss . "\n" . $background['css'] : $background['css'];
            }
        } elseif ($bg !== 'none') {
            $extraClass .= ' cms-block--bg cms-block--bg-' . $bg;
        }
        // Цвет текста секции и вложенных карточек. Считается для любого фона,
        // в том числе для пресета navy и для секции вовсе без фона: редактор
        // может задать цвет текста и там.
        $sectionColors = SectionColors::build($data, $blockId);
        if ($sectionColors !== '') {
            $scopedCss = $scopedCss !== '' ? $scopedCss . "\n" . $sectionColors : $sectionColors;
        }
        if ($fullwidth) {
            $extraClass .= ' cms-block--fullwidth';
        }
        if ($surface === 'card') {
            $extraClass .= ' cms-block--surface-card';
        }
        $minHeight = (string) ($data['_min_height'] ?? '');
        if (in_array($minHeight, ['small', 'medium', 'large', 'screen'], true)) {
            $extraClass .= ' cms-block--minh-' . $minHeight;
        }
        // Ограничение по устройству — только CSS: кэш страницы общий, серверное
        // ветвление по User-Agent сделало бы его непригодным.
        $extraClass .= BlockVisibility::deviceClass($data);
        $styleVars = '';
        $padTop = (string) ($data['_pad_top'] ?? 'default');
        $padBottom = (string) ($data['_pad_bottom'] ?? 'default');
        if (isset($padMap[$padTop])) {
            $extraClass .= ' cms-block--pad-top-custom';
            $styleVars .= '--block-pad-top:' . $padMap[$padTop] . ';';
        }
        if (isset($padMap[$padBottom])) {
            $extraClass .= ' cms-block--pad-bottom-custom';
            $styleVars .= '--block-pad-bottom:' . $padMap[$padBottom] . ';';
        }
        // Фоновая надпись секции: крупное слово за содержимым. Прозрачность
        // своя у каждой секции, поэтому переменной в scoped CSS — инлайн-стили
        // в блоках запрещены.
        $watermark = trim((string) ($data['_watermark'] ?? ''));
        if ($watermark !== '') {
            $extraClass .= ' cms-block--has-watermark';
            $styleVars .= '--block-watermark-opacity:'
                . round(((int) ($data['_watermark_opacity'] ?? 12)) / 100, 3) . ';'
                . '--block-watermark-size:' . ((int) ($data['_watermark_size'] ?? 22)) . 'vw;';
        }

        if ($styleVars !== '') {
            $sectionCss = '#block-' . $blockId . '{' . $styleVars . '}';
            $scopedCss = $scopedCss !== '' ? $scopedCss . "\n" . $sectionCss : $sectionCss;
        }

        $sectionAttributes = [
            'id="block-' . $blockId . '"',
            'class="cms-block cms-block--' . htmlspecialchars($type, ENT_QUOTES)
                . ' cms-block--space-' . htmlspecialchars($spacing, ENT_QUOTES)
                . $extraClass . '"',
            'data-block-type="' . htmlspecialchars($type, ENT_QUOTES) . '"',
        ];
        if ($revealOn) {
            if ($revealType === 'stagger') {
                $sectionAttributes[] = 'data-reveal-items';
            } else {
                $sectionAttributes[] = 'data-reveal';
                $sectionAttributes[] = 'data-reveal-type="' . htmlspecialchars($revealType, ENT_QUOTES) . '"';
            }
        }

        $anchor = BlockPresentationNormalizer::normalizeAnchor((string) ($data['_anchor'] ?? ''));
        $anchorHtml = $anchor !== ''
            ? '<span id="' . htmlspecialchars($anchor, ENT_QUOTES) . '" class="cms-block__anchor" aria-hidden="true"></span>' . "\n"
            : '';

        $wrapped = "<section\n    "
            . implode("\n    ", $sectionAttributes)
            . "\n>\n"
            . $anchorHtml
            . self::watermark($data, $watermark)
            . trim($html)
            . "\n</section>";

        $preloadImage = null;
        if ($type === 'hero') {
            if (!empty($data['_hero_slides'])) {
                // Кандидат в LCP — первый кадр обложки: у слайда это может быть
                // и постер видео, поэтому спрашиваем не поле, а помощник.
                $first = \App\Core\Hero\HeroSlideData::fallbackImage(
                    (array) (($data['_hero_slides'][0]['data'] ?? []))
                );
                $preloadImage = $first !== '' ? $first : null;
            } else {
                $heroImage = trim((string) ($data['image'] ?? ''));
                $heroBgType = (string) ($data['bg_type'] ?? 'none');
                if (in_array($heroBgType, ['image', 'youtube'], true) && $heroImage !== '') {
                    $preloadImage = $heroImage;
                }
            }
        }

        return ['html' => $wrapped, 'css' => $scopedCss, 'preload_image' => $preloadImage];
    }

    /**
     * Фоновая надпись секции.
     *
     * Живёт по тем же правилам, что и у обложки: это декорация из текста,
     * поэтому диктор её не читает (`aria-hidden`), а мышь сквозь неё проходит
     * (`pointer-events` в CSS) — иначе слово шириной в секцию накрыло бы
     * ссылки и кнопки внутри.
     *
     * @param array<string, mixed> $data
     */
    private static function watermark(array $data, string $text): string
    {
        if ($text === '') {
            return '';
        }

        $x = (string) ($data['_watermark_x'] ?? 'center');
        $y = (string) ($data['_watermark_y'] ?? 'middle');

        return '<span class="cms-block__watermark cms-block__watermark--x-'
            . htmlspecialchars($x, ENT_QUOTES) . ' cms-block__watermark--y-'
            . htmlspecialchars($y, ENT_QUOTES) . '" aria-hidden="true">'
            . htmlspecialchars($text, ENT_QUOTES) . "</span>\n";
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array{html: string, css: string, assets: array<int, string>, preload_images: array<int, string>, expires_at: int|null}
     */
    public static function renderPage(array $blocks): array
    {
        $htmlParts = [];
        $cssParts = [];
        $assets = [];
        $preloadImages = [];
        self::$nextBoundary = null;
        self::$h1Used = false;
        self::$nestedAssets = [];
        // FAQPage на страницу допускается ровно один — флаг живёт там же, где
        // счётчик h1, и сбрасывается вместе с ним.
        \App\Core\SchemaOrg::resetPageState();
        self::$pageSections = self::collectSections($blocks);

        foreach ($blocks as $block) {
            $rendered = self::render($block);
            if (!empty($rendered['hidden'])) {
                continue;
            }
            // Незаполненный блок: на сайте пропускаем, в предпросмотре
            // показываем заметку с типом блока — редактор должен понимать,
            // что блок есть, но его нужно наполнить.
            if (self::isVisuallyEmpty($rendered['html'])) {
                if (!self::$previewMode) {
                    continue;
                }
                $htmlParts[] = self::emptyNotice($block);
                continue;
            }
            $htmlParts[] = $rendered['html'];
            if ($rendered['css'] !== '') {
                $cssParts[] = "/* block #{$block['id']} ({$block['type']}) */\n" . $rendered['css'];
            }
            $type = BlockTypeRegistry::canonicalType(
                preg_replace('/[^a-z0-9_]/', '', strtolower((string) $block['type'])) ?? ''
            );
            $assets[$type] = true;
            // Обложка со слайдами использует общий скрипт слайдера. Смотрим на
            // готовую разметку, а не на тип блока: обычной обложке этот скрипт
            // не нужен, и грузить его всем подряд незачем.
            if (str_contains($rendered['html'], 'data-hero-slider')) {
                $assets['slider'] = true;
            }
            // Обложка как тип контента — свой скрипт и своя часть темы. Ключ не
            // совпадает с типом блока намеренно: блок «Обложка» со старыми
            // собственными настройками их не использует, и грузить их такой
            // странице незачем.
            if (str_contains($rendered['html'], 'data-hero-transition')) {
                $assets['hero_slides'] = true;
            }
            // Виджет-фотокарусель внутри блока. Сам шаблон виджета просит
            // скрипт у AssetCollector, но при попадании в кэш он не
            // выполняется — из HTML же ключ виден и на кэше тоже.
            if (str_contains($rendered['html'], 'widget--photo_slider')) {
                $assets['slider'] = true;
            }
            if (!empty($rendered['preload_image']) && $preloadImages === []) {
                // Одного LCP-кандидата достаточно: дополнительные high-priority
                // preload конкурировали бы с CSS и шрифтами первого экрана.
                $preloadImages[] = (string) $rendered['preload_image'];
            }
        }

        // Ассеты вложенных блоков: сам контейнер о типах своих детей не
        // сообщает, а без них блок внутри колонки остаётся без своего CSS.
        $assets += self::$nestedAssets;

        return [
            'html' => implode("\n", $htmlParts),
            'css' => implode("\n\n", $cssParts),
            'assets' => array_keys($assets),
            'preload_images' => $preloadImages,
            'expires_at' => self::$nextBoundary,
        ];
    }

    /**
     * Рендер блока «columns»: дочерние блоки группируются по колонкам и
     * рендерятся рекурсивно обычным render() (переиспользование). Вложение
     * columns-в-columns запрещено (такие дети пропускаются).
     *
     * @param array<string,mixed> $block
     * @param array<string,mixed> $data
     * @return array{0:string,1:string} [html, css дочерних блоков]
     */
    private static function renderColumns(array $block, array $data): array
    {
        // Значения уже проверены схемой полей (BlockFieldSchema), поэтому
        // читаются как есть.
        $count = (int) $data['columns'];
        $gap = (string) $data['gap'];
        $valign = (string) $data['valign'];
        $mobileOrder = (string) $data['mobile_order'];

        $byColumn = self::containerChildren($block, $count);

        $cssParts = [];
        $colsHtml = '';
        for ($i = 0; $i < $count; $i++) {
            [$inner, $innerCss] = self::renderContainerCell($byColumn[$i]);
            $cssParts = array_merge($cssParts, $innerCss);
            $colsHtml .= "    <div class=\"cms-columns__col\">\n"
                . trim($inner)
                . "\n    </div>\n";
        }

        // Выравнивание по высоте и порядок на телефоне — классы: инлайн-стили
        // в блоках запрещены тестами.
        $modifiers = $valign !== 'stretch' ? ' cms-columns--valign-' . $valign : '';
        $modifiers .= $mobileOrder === 'reverse' ? ' cms-columns--mobile-reverse' : '';

        // Заголовок и описание подписывают всю группу колонок сразу. Разметку
        // собирает общая шапка секции (`SectionHead`) — та же, что у полутора
        // десятков блоков: своя копия разъехалась бы с ней при первой правке,
        // а вместе с ней потерялись бы разметка заголовка (*слово* и |) и
        // структура `__copy`. Выравнивание — класс, а не инлайн-стиль.
        $align = (string) $data['title_align'];
        $head = SectionHead::render([
            'title' => (string) $data['title'],
            'description' => (string) $data['description'],
            'level' => (string) $data['title_level'],
            'class' => $align === 'left' ? '' : 'section-head--align-' . $align,
        ]);

        $html = sprintf(
            "%s<div class=\"cms-columns cms-columns--%d cms-columns--gap-%s%s\">\n%s</div>",
            $head === '' ? '' : $head . "\n",
            $count,
            htmlspecialchars($gap, ENT_QUOTES),
            $modifiers,
            $colsHtml
        );

        // Пропорции колонок — scoped CSS, а не инлайн-стиль (инлайн в блоках
        // запрещён тестами и требовал бы послабления в CSP).
        $template = ColumnRatio::template((string) ($data['ratio'] ?? ''), $count);
        if ($template !== '' && !empty($block['id'])) {
            // Только с 721px: ниже общая тема кладёт колонки в одну, и правило
            // с id-селектором перебивало бы её по специфичности.
            $cssParts[] = '@media (min-width: 721px){#block-' . (int) $block['id']
                . ' .cms-columns{grid-template-columns:' . $template . '}}';
        }

        return [$html, implode("\n", $cssParts)];
    }

    /**
     * Дочерние блоки контейнера, разложенные по ячейкам: у «Колонок» ячейка —
     * колонка, у «Вкладок» — вкладка (в обоих случаях это `column_index`).
     *
     * Ячейка вне диапазона схлопывается в первую: число колонок или вкладок
     * могли уменьшить уже после того, как в дальнюю что-то положили, и молча
     * терять этот блок нельзя — редактор увидел бы пропажу без объяснения.
     *
     * @param array<string,mixed> $block
     * @return array<int, list<array<string,mixed>>>
     */
    private static function containerChildren(array $block, int $count): array
    {
        // Дочерние блоки доступны только при наличии реального id (в рендере из БД).
        $children = [];
        if (!empty($block['id']) && class_exists(\App\Models\Block::class)) {
            $children = \App\Models\Block::childrenOf((int) $block['id'], true);
        }

        $count = max(1, $count);
        $byIndex = array_fill(0, $count, []);
        foreach ($children as $child) {
            // Защита от контейнера в контейнере (колонки в колонках, вкладки
            // во вкладках): такие дети сюда попасть не должны, но данные могли
            // приехать из старого дампа.
            if (BlockTypeRegistry::isContainer((string) $child['type'])) {
                continue;
            }
            $index = (int) ($child['column_index'] ?? 0);
            if ($index < 0 || $index >= $count) {
                $index = 0;
            }
            $byIndex[$index][] = $child;
        }

        return $byIndex;
    }

    /**
     * Содержимое одной ячейки контейнера: вложенные блоки рендерятся обычным
     * render() — со своими вариантами, отступами и scoped-стилями.
     *
     * @param list<array<string,mixed>> $children
     * @return array{0:string,1:list<string>} [html, css вложенных блоков]
     */
    private static function renderContainerCell(array $children): array
    {
        $html = '';
        $cssParts = [];
        foreach ($children as $child) {
            $rendered = self::render($child);
            if (!empty($rendered['hidden'])) {
                continue;
            }
            // Тип ребёнка запоминаем здесь, а не в render(): скрытый блок
            // разметки не даёт, и грузить его файл незачем.
            $childType = BlockTypeRegistry::canonicalType(
                preg_replace('/[^a-z0-9_]/', '', strtolower((string) $child['type'])) ?? ''
            );
            if ($childType !== '') {
                self::$nestedAssets[$childType] = true;
            }
            $html .= $rendered['html'];
            if ($rendered['css'] !== '') {
                $cssParts[] = $rendered['css'];
            }
        }

        return [$html, $cssParts];
    }

    /**
     * Рендер блока «Вкладки»: подписи хранит сам блок, содержимое каждой
     * вкладки — вложенные блоки любого типа (`column_index` = номер вкладки).
     *
     * Разметка приходит рабочей и без JavaScript: вкладки — обычные ссылки на
     * заголовки разделов, все панели видны. Скрипт `blocks/tabs.js` уже поверх
     * этого прячет неактивные панели и расставляет роли ARIA. Обещать роль
     * `tab` в статике нельзя — без скрипта переключения не будет.
     *
     * @param array<string,mixed> $block
     * @param array<string,mixed> $data
     * @return array{0:string,1:string} [html, css дочерних блоков]
     */
    private static function renderTabs(array $block, array $data): array
    {
        $blockId = (int) ($block['id'] ?? 0);

        // Значения проверены схемой полей (BlockFieldSchema).
        $variant = (string) $data['variant'];
        $align = (string) $data['align'];
        $autoplay = (int) $data['autoplay'];

        $items = [];
        foreach ((array) ($data['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $items[] = [
                'title' => $title,
                'icon' => Icon::cleanName($item['icon'] ?? ''),
                'text' => trim((string) ($item['text'] ?? '')),
            ];
        }
        // Вкладок больше десятка не бывает осмысленно, а полоса из них
        // разъезжается на любой ширине.
        $items = array_slice($items, 0, 10);
        if ($items === []) {
            return ['', ''];
        }

        $byTab = self::containerChildren($block, count($items));

        $listHtml = '';
        $panelsHtml = '';
        $cssParts = [];
        foreach ($items as $index => $item) {
            $panelId = 'block-' . $blockId . '-tab-' . ($index + 1);
            $title = htmlspecialchars($item['title'], ENT_QUOTES);
            $icon = $item['icon'] !== ''
                ? '<span class="cms-tabs__tab-icon" aria-hidden="true">' . Icon::render($item['icon'], 18) . '</span>'
                : '';

            // Полоса отсчёта до следующей вкладки. Рисуется только при
            // включённом автопереключении и заполняется скриптом: без него
            // отсчитывать нечего.
            $progress = $autoplay > 0
                ? '<span class="cms-tabs__tab-progress" data-tabs-progress aria-hidden="true"></span>'
                : '';

            $listHtml .= '        <a class="cms-tabs__tab' . ($index === 0 ? ' is-active' : '')
                . '" href="#' . $panelId . '" data-tabs-tab="' . $index . '">'
                . $icon . '<span class="cms-tabs__tab-text">' . $title . '</span>' . $progress . '</a>' . "\n";

            [$inner, $innerCss] = self::renderContainerCell($byTab[$index]);
            $cssParts = array_merge($cssParts, $innerCss);

            // Пояснение к вкладке — одна строка о том, чему она посвящена.
            // Стоит над содержимым, а не в самой вкладке: вторая строка в
            // полосе вкладок ломает ряд.
            $note = $item['text'] !== ''
                ? '            <p class="cms-tabs__panel-note">' . htmlspecialchars($item['text'], ENT_QUOTES) . '</p>' . "\n"
                : '';

            $panelsHtml .= '        <div class="cms-tabs__panel" id="' . $panelId
                . '" data-tabs-panel="' . $index . '">' . "\n"
                // Заголовок панели нужен версии без JavaScript: там панели идут
                // подряд, и без него не видно, где кончается одна вкладка.
                . '            <h3 class="cms-tabs__panel-title">' . $title . '</h3>' . "\n"
                . $note
                . trim($inner) . "\n"
                . '        </div>' . "\n";
        }

        $head = SectionHead::render([
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'description_html' => true,
        ]);

        // Автопереключение — атрибутом: без JavaScript панели и так видны все
        // подряд, переключать нечего.
        $autoplayAttr = $autoplay > 0 && count($items) > 1
            ? ' data-tabs-autoplay="' . $autoplay . '"'
            : '';

        $html = '<div class="cms-tabs cms-tabs--' . $variant . ' cms-tabs--align-' . $align
            . '" data-tabs data-tab-count="' . count($items) . '"' . $autoplayAttr . '>' . "\n"
            . ($head !== '' ? '    ' . $head . "\n" : '')
            // Список и панели лежат в общей обёртке: у варианта «список слева»
            // они становятся сеткой, а шапка секции обязана остаться над ними.
            . '    <div class="cms-tabs__body">' . "\n"
            . '    <div class="cms-tabs__list" data-tabs-list>' . "\n" . $listHtml . '    </div>' . "\n"
            . '    <div class="cms-tabs__panels">' . "\n" . $panelsHtml . '    </div>' . "\n"
            . '    </div>' . "\n"
            . '</div>';

        return [$html, implode("\n", $cssParts)];
    }

    /**
     * Заметка о незаполненном блоке для предпросмотра в админке.
     *
     * @param array<string,mixed> $block
     */
    private static function emptyNotice(array $block): string
    {
        $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $block['type'])) ?? '';
        $label = self::TYPE_LABELS[$type] ?? $type;
        $title = trim((string) ($block['title'] ?? ''));

        return sprintf(
            "<section\n"
            . "    id=\"block-%d\"\n"
            . "    class=\"cms-block cms-block--empty-notice\"\n"
            . "    data-block-type=\"%s\"\n"
            . ">\n"
            . "    <div class=\"cms-empty-notice\">\n"
            . "        <strong>Блок «%s»%s пока пуст</strong>\n"
            . "        <span>Заполните поля блока — на сайте он появится. Сейчас посетители его не видят.</span>\n"
            . "        <a class=\"cms-empty-notice__edit\" href=\"/admin/blocks/%d/edit\">Заполнить</a>\n"
            . "    </div>\n"
            . "</section>",
            (int) $block['id'],
            htmlspecialchars($type, ENT_QUOTES),
            htmlspecialchars($label, ENT_QUOTES),
            $title !== '' ? ' (' . htmlspecialchars($title, ENT_QUOTES) . ')' : '',
            (int) $block['id']
        );
    }

    /** Совместимый фасад: названия хранятся в BlockTypeRegistry. */
    public const TYPE_LABELS = BlockTypeRegistry::TYPE_LABELS;

    /**
     * Адрес ссылки «Все новости» у блоков новостей. Если блок ограничен
     * рубрикой, ссылка ведёт в ту же рубрику: иначе посетитель, кликнув «Все»
     * под подборкой одной рубрики, попадал бы в общую ленту и терял контекст.
     */
    /**
     * Названия рубрик для набора новостей одним запросом — блоки выводят
     * несколько карточек, и запрос на каждую был бы N+1.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private static function newsCategoryNames(array $rows, string $lang): array
    {
        return \App\Models\NewsCategory::namesForIds(
            array_map(static fn (array $row): int => (int) ($row['category_id'] ?? 0), $rows),
            $lang
        );
    }

    private static function newsAllUrl(string $lang, int $categoryId): string
    {
        $url = Locale::url('news', $lang);
        if ($categoryId <= 0) {
            return $url;
        }

        $category = \App\Models\NewsCategory::find($categoryId);
        if ($category === null || (int) $category['is_active'] !== 1) {
            return $url;
        }

        return $url . '?category=' . rawurlencode((string) $category['slug']);
    }

    private static function enrichData(string $type, array $data): array
    {
        return match ($type) {
            'form' => self::enrichForm($data),
            'hero' => self::enrichHero($data),
            'bio_education' => self::enrichBioEducation($data),
            'team_list' => self::enrichTeamList($data),
            'projects_list' => self::enrichProjectsList($data),
            'news_latest' => self::enrichNewsLatest($data),
            'news_feature' => self::enrichNewsFeature($data),
            'news_docs' => self::enrichNewsDocs($data),
            'cards_grid' => self::enrichCardsGrid($data),
            'media_gallery' => self::enrichMediaGallery($data),
            default => $data,
        };
    }

    private static function enrichForm(array $data): array
    {
        if (!empty($data['form_id'])) {
            $form = FormDef::findById((int) $data['form_id']);
            if ($form !== null) {
                $data['form'] = $form;
            }
        }
        return $data;
    }

    private static function enrichHero(array $data): array
    {
        if ((int) ($data['hero_id'] ?? 0) > 0) {
            $heroId = (int) $data['hero_id'];
            $hero = \App\Models\Hero::find($heroId);
            if ($hero !== null) {
                self::noteBoundary(\App\Models\Hero::boundary($hero));
                self::noteBoundary(\App\Models\HeroSlide::boundary($heroId));
            }
            if ($hero !== null && \App\Models\Hero::isVisible($hero)) {
                $slides = \App\Models\HeroSlide::forDisplay($heroId, Locale::current());
                if ($slides !== []) {
                    $data['_hero'] = $hero;
                    $data['_hero_slides'] = $slides;
                    $data['_hero_settings'] = \App\Models\Hero::settings($hero);
                }
            }
        }
        return $data;
    }

    private static function enrichBioEducation(array $data): array
    {
        $lang = Locale::current();
        $data['_widgets_before_html'] = WidgetRenderer::renderSelection((array) ($data['widgets_before'] ?? []), $lang);
        $data['_widgets_after_html'] = WidgetRenderer::renderSelection((array) ($data['widgets_after'] ?? []), $lang);
        return $data;
    }

    private static function enrichTeamList(array $data): array
    {
        $items = \App\Models\TeamMember::published(Locale::current());
        $department = trim((string) ($data['department'] ?? ''));
        if ($department !== '') {
            $items = array_values(array_filter(
                $items,
                static fn (array $row): bool => \App\Models\TeamMember::departmentSlug($row) === $department
            ));
        }

        $limit = (int) ($data['limit'] ?? 0);
        $data['members'] = $limit > 0 ? array_slice($items, 0, $limit) : $items;
        $data['groups'] = !empty($data['group_by_department'])
            ? \App\Models\TeamMember::groupByDepartment($data['members'])
            : [];
        return $data;
    }

    private static function enrichProjectsList(array $data): array
    {
        $lang = Locale::current();
        $items = \App\Models\Project::published($lang);
        $limit = (int) ($data['limit'] ?? 0);
        $data['projects'] = $limit > 0 ? array_slice($items, 0, $limit) : $items;
        if (trim((string) ($data['all_url'] ?? '')) === '') {
            $data['all_url'] = Locale::url('projects', $lang);
        }
        return $data;
    }

    private static function enrichNewsLatest(array $data): array
    {
        $limit = (int) ($data['limit'] ?? 3);
        if ($limit <= 0) {
            $limit = 3;
        }
        $lang = Locale::current();
        $category = (int) ($data['category'] ?? 0);
        $rows = \App\Models\News::published($limit, 0, $lang, $category > 0 ? $category : null);
        $categoryNames = self::newsCategoryNames($rows, $lang);
        $items = [];
        foreach ($rows as $row) {
            $rowCategory = (int) ($row['category_id'] ?? 0);
            $items[] = [
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'published_at' => (string) ($row['published_at'] ?? ''),
                'excerpt' => (string) ($row['excerpt'] ?? ''),
                'category' => $rowCategory > 0 ? (string) ($categoryNames[$rowCategory] ?? '') : '',
                'cover' => \App\Models\News::getCoverImage($row),
                'url' => Locale::url('news/' . $row['slug'], $lang),
            ];
        }
        $data['news'] = $items;
        if (trim((string) ($data['all_url'] ?? '')) === '') {
            $data['all_url'] = self::newsAllUrl($lang, $category);
        }
        return $data;
    }

    private static function enrichNewsFeature(array $data): array
    {
        $limit = (int) ($data['limit'] ?? 6);
        if ($limit <= 0) {
            $limit = 6;
        }
        // Мозаика набирается ритмом: шесть карточек и восемь ячеек сетки,
        // поэтому число задано ритмом, а не литералом — иначе ряд оборвался бы
        // на середине композиции (App\Core\NewsFeedRhythm).
        if ((string) ($data['variant'] ?? 'cards') === 'mosaic') {
            $limit = \App\Core\NewsFeedRhythm::BLOCK_SIZE;
        }
        $lang = Locale::current();
        $category = (int) ($data['category'] ?? 0);
        $rows = \App\Models\News::published($limit, 0, $lang, $category > 0 ? $category : null);
        $categoryNames = self::newsCategoryNames($rows, $lang);
        $items = [];
        foreach ($rows as $row) {
            $rowCategory = (int) ($row['category_id'] ?? 0);
            $items[] = [
                'title' => (string) $row['title'],
                'slug' => (string) $row['slug'],
                'published_at' => (string) ($row['published_at'] ?? ''),
                'excerpt' => (string) ($row['excerpt'] ?? ''),
                'badge' => trim((string) ($row['badge'] ?? '')),
                'badge_color' => (string) ($row['badge_color'] ?? ''),
                'category' => $rowCategory > 0 ? (string) ($categoryNames[$rowCategory] ?? '') : '',
                'cover' => \App\Models\News::getCoverImage($row),
                'url' => Locale::url('news/' . $row['slug'], $lang),
            ];
        }
        $data['news'] = $items;
        if (($data['all_url'] ?? '') === '') {
            $data['all_url'] = self::newsAllUrl($lang, $category);
        }
        return $data;
    }

    private static function enrichNewsDocs(array $data): array
    {
        $limit = (int) ($data['limit'] ?? 3);
        if ($limit <= 0) {
            $limit = 3;
        }
        $lang = Locale::current();
        $category = (int) ($data['category'] ?? 0);
        $items = [];
        foreach (\App\Models\News::published($limit, 0, $lang, $category > 0 ? $category : null) as $row) {
            $items[] = [
                'title' => (string) $row['title'],
                'published_at' => (string) ($row['published_at'] ?? ''),
                'cover' => \App\Models\News::getCoverImage($row),
                'url' => Locale::url('news/' . $row['slug'], $lang),
            ];
        }
        $data['news'] = $items;
        if (($data['news_all_url'] ?? '') === '') {
            $data['news_all_url'] = self::newsAllUrl($lang, $category);
        }
        return $data;
    }

    private static function enrichCardsGrid(array $data): array
    {
        if (($data['variant'] ?? 'icon') === 'image' && ($data['source'] ?? 'manual') === 'projects') {
            $lang = Locale::current();
            $limit = (int) ($data['limit'] ?? 6);
            $items = [];
            foreach (\App\Models\Project::forHome($limit, $lang) as $p) {
                $items[] = [
                    'image' => (string) ($p['cover_image'] ?? ''),
                    'title' => (string) $p['title'],
                    'text' => '',
                    'url' => Locale::url('projects/' . $p['slug'], $lang),
                ];
            }
            $data['items'] = $items;
            if (($data['all_url'] ?? '') === '') {
                $data['all_url'] = Locale::url('projects', $lang);
            }
        }
        return $data;
    }

    private static function enrichMediaGallery(array $data): array
    {
        $mediaSource = (string) ($data['source'] ?? 'manual');
        if (in_array($mediaSource, ['albums', 'videos', 'media'], true)) {
            $lang = Locale::current();
            $limit = (int) ($data['limit'] ?? 8);
            $paginate = !empty($data['paginate']);

            // Постраничный вывод и витрина главной — разные задачи. Без полосы
            // страниц блок показывает отмеченные «на главной» записи (forHome),
            // с полосой — весь опубликованный список подряд: иначе читатель
            // упирался бы в первые записи и не мог дойти до остальных.
            //
            // У каждой вкладки своя полоса страниц. Общая на оба списка
            // показывала неправду: списки разной длины, и на «Фото» стояли
            // страницы видео — переход по ним не менял ни одной карточки.
            // Номер из адреса принадлежит открытой вкладке, вторая
            // показывает своё начало, чтобы переключение не открывало её
            // пустой серединой.
            $videoTotal = 0;
            $photoTotal = 0;
            if ($paginate && ($mediaSource === 'videos' || $mediaSource === 'media')) {
                $videoTotal = \App\Models\Video::publishedTotal();
            }
            if ($paginate && ($mediaSource === 'albums' || $mediaSource === 'media')) {
                $photoTotal = \App\Models\PhotoAlbum::publishedTotal();
            }

            // Пустой список вкладкой не становится: адрес с её именем не
            // должен уводить на раздел, которого посетитель не видит.
            $kinds = [];
            if ($videoTotal > 0) {
                $kinds[] = 'video';
            }
            if ($photoTotal > 0) {
                $kinds[] = 'photo';
            }
            $activeTab = $paginate ? BlockPager::currentTab($kinds) : '';

            $videoPager = $videoTotal > 0
                ? BlockPager::slice($videoTotal, $limit, $activeTab === 'video' ? null : 1)
                : null;
            $albumPager = $photoTotal > 0
                ? BlockPager::slice($photoTotal, $limit, $activeTab === 'photo' ? null : 1)
                : null;

            $items = [];
            if ($mediaSource === 'videos' || $mediaSource === 'media') {
                $videos = !$paginate
                    ? \App\Models\Video::forHome($limit, $lang)
                    : ($videoPager === null
                        ? []
                        : \App\Models\Video::publishedSlice(
                            $videoPager['per_page'],
                            $videoPager['offset'],
                            $lang
                        ));
                foreach ($videos as $v) {
                    $items[] = [
                        'kind' => 'video',
                        'image' => (string) ($v['cover_url'] ?? ''),
                        'title' => (string) $v['title'],
                        'meta' => (string) ($v['duration'] ?? ''),
                        'url' => (string) ($v['video_url'] ?? ''),
                    ];
                }
            }
            if ($mediaSource === 'albums' || $mediaSource === 'media') {
                $albums = !$paginate
                    ? \App\Models\PhotoAlbum::forHome($limit, $lang)
                    : ($albumPager === null
                        ? []
                        : \App\Models\PhotoAlbum::publishedSlice(
                            $albumPager['per_page'],
                            $albumPager['offset'],
                            $lang
                        ));
                foreach ($albums as $a) {
                    $items[] = [
                        'kind' => 'photo',
                        'image' => \App\Models\PhotoAlbum::coverFor($a),
                        'title' => (string) $a['title'],
                        'meta' => '',
                        'url' => Locale::url('albums/' . $a['slug'], $lang),
                    ];
                }
            }
            $data['items'] = $items;
            $pagers = [];
            if ($videoPager !== null) {
                $pagers['video'] = $videoPager;
            }
            if ($albumPager !== null) {
                $pagers['photo'] = $albumPager;
            }
            if ($pagers !== []) {
                $data['_pagers'] = $pagers;
                $data['_media_tab'] = $activeTab;
            }
            if ($mediaSource === 'albums' && ($data['all_url'] ?? '') === '') {
                $data['all_url'] = Locale::url('albums', $lang);
            }
        }
        return $data;
    }

    /**
     * Разделы страницы для автоматического оглавления. Берём заголовок блока:
     * он выводится на странице как заголовок секции, а якорем служит id самой
     * секции — отдельных якорей заводить не нужно.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<array{id: string, label: string}>
     */
    private static function collectSections(array $blocks): array
    {
        $sections = [];
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? '');
            // Обложка — заголовок всей страницы, а не её раздел; сама
            // навигация в свой список тоже не попадает.
            if (in_array($type, ['hero', 'anchor_nav'], true)) {
                continue;
            }
            $data = $block['data'] ?? [];
            if (is_string($data)) {
                $data = json_decode($data, true);
            }
            if (!is_array($data) || !BlockVisibility::isVisible($data)) {
                continue;
            }
            // Пункт оглавления — текст ссылки, а не заголовок секции:
            // звёздочки выделения снимаем, красить в оглавлении нечего.
            $label = trim(TitleMarkup::plain((string) ($data['title'] ?? '')));
            if ($label === '') {
                continue;
            }
            $sections[] = ['id' => 'block-' . (int) ($block['id'] ?? 0), 'label' => $label];
        }

        return $sections;
    }

    /** @return array{0:string,1:string} */
    private static function renderTemplate(string $file, array $data, int $blockId): array
    {
        $render = static function (string $__file, array $data, int $blockId): string {
            $templateCss = '';
            extract(['data' => $data, 'blockId' => $blockId], EXTR_SKIP);
            require $__file;

            return $templateCss;
        };

        ob_start();
        $templateCss = $render($file, $data, $blockId);

        return [(string) ob_get_clean(), $templateCss];
    }
}
